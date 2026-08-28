<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Evolution\Upcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Record\StoredEventReader;
use Storm\Chronicler\Tests\Fixture\FakeCipherKeyStore;
use Storm\Chronicler\Tests\Fixture\PersonalUpcastEvent;
use Storm\Message\Attribute\Personal;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\CipheringMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;

/**
 * The whole read chain of crypto-shredding, in the order `StoredEventReader` fixes: upcast FIRST,
 * on the ciphered payload, then decrypt at deserialize. The property under test: an upcaster sees
 * personal fields as opaque envelopes, so it migrates the shape around them and can never leak
 * cleartext, and the fold receives cleartext or the declared fallback, whatever the row's age and
 * the key's fate.
 */
final class CipheredUpcastReadTest extends TestCase
{
    private FakeCipherKeyStore $keys;

    private StoredEventReader $reader;

    /** The v1-to-v2 upcaster, holding what it SAW in the personal key: the opacity witness. */
    private Upcaster $upcaster;

    #[Override]
    protected function setUp(): void
    {
        $this->keys = new FakeCipherKeyStore;

        $this->upcaster = new class() implements Upcaster
        {
            /** What this upcaster saw in the personal key, captured to prove opacity. */
            public ?string $seen = null;

            public function supports(string $alias, int $fromVersion): bool
            {
                return $alias === 'test.personal_upcast' && $fromVersion === 1;
            }

            public function upcast(array $payload): array
            {
                $this->seen = (string) $payload['full_name'];

                return [...$payload, 'channel' => 'web'];
            }
        };

        $this->reader = new StoredEventReader(
            new CipheringMessageSerializer(new DefaultMessageSerializer, $this->keys, $this->map()),
            new MappedEventTypeMapper([PersonalUpcastEvent::class]),
            new UpcasterChain([$this->upcaster]),
        );
    }

    #[Test]
    public function an_old_ciphered_row_upcasts_opaquely_and_folds_the_cleartext(): void
    {
        $event = $this->reader->toMessage($this->storedV1Row())->message();

        $this->assertInstanceOf(PersonalUpcastEvent::class, $event);
        $this->assertSame('Ada Lovelace', $event->fullName);
        $this->assertSame('web', $event->channel); // the upcaster ran, on the ciphered payload

        // the opacity proof: what the upcaster saw was the envelope, never the cleartext
        $seen = $this->upcaster->seen; // @phpstan-ignore property.notFound (the anonymous witness's own property)
        $this->assertIsString($seen);
        $this->assertMatchesRegularExpression('/^v\d+:/', $seen);
        $this->assertStringNotContainsString('Ada', $seen);
    }

    #[Test]
    public function a_destroyed_key_still_upcasts_and_folds_the_fallback(): void
    {
        $row = $this->storedV1Row();
        $this->keys->destroy('cus-1');

        $event = $this->reader->toMessage($row)->message();

        $this->assertInstanceOf(PersonalUpcastEvent::class, $event);
        $this->assertSame('⌫', $event->fullName); // the fallback, never the ciphertext
        $this->assertSame('web', $event->channel); // shape migration is independent of the key's fate
    }

    /**
     * A stored v1 row of the marked event: ciphered at write time under the CURRENT declaration,
     * then aged, `channel` removed and `event_version` pinned at 1, exactly what an old row looks
     * like to today's reader.
     *
     * @return array<string, mixed>
     */
    private function storedV1Row(): array
    {
        $data = new CipheringMessageSerializer(new DefaultMessageSerializer, $this->keys, $this->map())
            ->serialize(
                new Message(PersonalUpcastEvent::fromPayload(['customer_id' => 'cus-1', 'full_name' => 'Ada Lovelace', 'channel' => 'web']))
                    ->withHeader(Header::MessageType, PersonalUpcastEvent::class)
                    ->withHeader(Header::MessageId, 'evt-1'),
            );

        unset($data['content']['channel']);
        unset($data['header'][Header::MessageType->value]); // stored rows carry the alias in `type`, not the FQCN header

        return [
            'type' => 'test.personal_upcast',
            'event_version' => 1,
            'header' => json_encode($data['header'], JSON_THROW_ON_ERROR), // rows carry JSON text, as the jsonb columns do
            'content' => json_encode($data['content'], JSON_THROW_ON_ERROR),
            'stream' => 'customer-cus-1',
        ];
    }

    /**
     * @return array<class-string, array{subject: string, keys: list<string>, fallbacks: array<string, scalar|null>}>
     */
    private function map(): array
    {
        $attribute = new ReflectionClass(PersonalUpcastEvent::class)->getAttributes(Personal::class)[0]->newInstance();

        return [PersonalUpcastEvent::class => ['subject' => $attribute->subject, 'keys' => $attribute->keys, 'fallbacks' => $attribute->fallback]];
    }
}
