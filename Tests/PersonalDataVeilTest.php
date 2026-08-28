<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Tests\Fixture\FakeCipherKeyStore;
use Storm\Chronicler\Tests\Fixture\PersonalUpcastEvent;
use Storm\Clock\PointInTime;
use Storm\Message\Attribute\Personal;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Serializer\CipheringMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;

/**
 * The no-decrypt rule of the inspection surfaces: personal keys render AS STORED, the envelope or a
 * pre-marking row's cleartext, never the rebuilt event's decrypted values; business keys keep the
 * hydrated current view.
 */
final class PersonalDataVeilTest extends TestCase
{
    private FakeCipherKeyStore $keys;

    private CipheringMessageSerializer $serializer;

    #[Override]
    protected function setUp(): void
    {
        $this->keys = new FakeCipherKeyStore;
        $this->serializer = new CipheringMessageSerializer(new DefaultMessageSerializer, $this->keys, $this->map());
    }

    #[Test]
    public function veils_a_marked_event_with_the_stored_envelope_never_the_cleartext(): void
    {
        $data = $this->cipheredData();
        $record = $this->record($data);

        $veiled = new PersonalDataVeil($this->map())->veil($record);

        $this->assertSame($data['content']['full_name'], $veiled['full_name'], 'the personal key shows the envelope, byte-identical to the store');
        $this->assertMatchesRegularExpression('/^v\d+:/', (string) $veiled['full_name']);
        $this->assertStringNotContainsString('Ada', json_encode($veiled, JSON_THROW_ON_ERROR));
        $this->assertSame('web', $veiled['channel'], 'business keys keep the hydrated view');
        $this->assertSame('cus-1', $veiled['customer_id'], 'the subject is clear by design');
    }

    #[Test]
    public function an_unmarked_class_passes_through_as_the_hydrated_payload(): void
    {
        $record = $this->record($this->cipheredData());

        // empty map = the unmarked-app default: pure pass-through of toPayload()
        $veiled = new PersonalDataVeil()->veil($record);

        $this->assertSame('Ada Lovelace', $veiled['full_name']);
    }

    #[Test]
    public function a_pre_marking_cleartext_row_shows_its_stored_cleartext(): void
    {
        // read-without-decrypting shows what the store HOLDS: for a row written before the class
        // was marked, that is honest cleartext; protecting it is the forget's job, not the veil's
        $stored = ['customer_id' => 'cus-1', 'full_name' => 'Ada Lovelace', 'channel' => 'web'];
        $message = Message::fromStored(
            PersonalUpcastEvent::fromPayload($stored),
            [Header::MessageType->value => PersonalUpcastEvent::class, Header::MessageId->value => 'evt-1'],
        );

        $veiled = new PersonalDataVeil($this->map())->veil(new EventRecord($message, SequencePosition::fromInt(1), PointInTime::from('2026-07-29T10:00:00.000000+00:00'), $stored));

        $this->assertSame('Ada Lovelace', $veiled['full_name']);
    }

    #[Test]
    public function a_personal_key_the_stored_row_does_not_carry_renders_the_fallback_not_the_hydrated_value(): void
    {
        // an upcaster-born personal key has no stored form; the hydrated value behind it may be
        // decrypted cleartext, so the veil must not fall through to it
        $data = $this->cipheredData();
        $record = $this->record($data, storedContent: ['customer_id' => 'cus-1', 'channel' => 'web']);

        $veiled = new PersonalDataVeil($this->map())->veil($record);

        $this->assertSame('⌫', $veiled['full_name']);
    }

    /**
     * The serialized `{header, content}` of a marked event, personal keys ciphered as at rest.
     *
     * @return array{header: array<string, mixed>, content: array<string, mixed>}
     */
    private function cipheredData(): array
    {
        return $this->serializer->serialize(
            new Message(PersonalUpcastEvent::fromPayload(['customer_id' => 'cus-1', 'full_name' => 'Ada Lovelace', 'channel' => 'web']))
                ->withHeader(Header::MessageType, PersonalUpcastEvent::class)
                ->withHeader(Header::MessageId, 'evt-1'),
        );
    }

    /**
     * An EventRecord as the read path builds it: the message rebuilt through the DECORATED
     * serializer, so cleartext in the object, and the stored content as written, so envelopes.
     *
     * @param  array{header: array<string, mixed>, content: array<string, mixed>}  $data
     * @param  array<string, mixed>|null  $storedContent
     */
    private function record(array $data, ?array $storedContent = null): EventRecord
    {
        return new EventRecord(
            $this->serializer->deserialize($data),
            SequencePosition::fromInt(1),
            PointInTime::from('2026-07-29T10:00:00.000000+00:00'),
            $storedContent ?? $data['content'],
        );
    }

    #[Test]
    public function redacts_a_live_event_to_the_declared_fallback_with_no_row_behind_it(): void
    {
        // The live-surface case: an event bus handler holds the rebuilt event and nothing else, so
        // there is no stored form to overlay. The fallback is the whole answer, and it is what makes
        // this the more redacting of the two renderings.
        $event = PersonalUpcastEvent::fromPayload(['customer_id' => 'cus-1', 'full_name' => 'Ada Lovelace', 'channel' => 'web']);

        $redacted = new PersonalDataVeil($this->map())->redact($event);

        $this->assertNotSame('Ada Lovelace', $redacted['full_name']);
        $this->assertStringNotContainsString('Ada', json_encode($redacted, JSON_THROW_ON_ERROR));
        $this->assertSame('web', $redacted['channel'], 'business keys keep the hydrated view');
        $this->assertSame('cus-1', $redacted['customer_id'], 'the subject is clear by design');
    }

    #[Test]
    public function redacting_an_unmarked_class_passes_the_payload_through(): void
    {
        $event = PersonalUpcastEvent::fromPayload(['customer_id' => 'cus-1', 'full_name' => 'Ada Lovelace', 'channel' => 'web']);

        $this->assertSame($event->toPayload(), new PersonalDataVeil()->redact($event), 'an empty map redacts nothing');
    }

    #[Test]
    public function declares_a_key_across_every_marked_class(): void
    {
        // the class-blind question an inspection surface asks before persisting or comparing an
        // operator value against a key; an empty map declares nothing
        $veil = new PersonalDataVeil($this->map());

        $this->assertTrue($veil->isDeclaredKey('full_name'));
        $this->assertFalse($veil->isDeclaredKey('amount'));
        $this->assertFalse(new PersonalDataVeil()->isDeclaredKey('full_name'));
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
