<?php

declare(strict_types=1);

namespace Storm\Chronicler\Record;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Contracts\Message\DomainEvent;

/**
 * The no-decrypt rule of the inspection surfaces, applied at render time: a stored event's payload
 * for DISPLAY, where a `#[Personal]` class's declared keys show what the STORE holds, the ciphered
 * envelope or the cleartext of a row written before the class was marked, never the rebuilt event's
 * decrypted values. Ciphertext on an operator's screen is honest and safe: diagnosing a stream does
 * not need the person's name, and the envelope's presence is itself the proof the field is
 * protected at rest.
 *
 * Everything else stays the HYDRATED CURRENT view the surfaces contract: aliases resolved,
 * upcasters run, business keys in today's shape. Only the declared personal keys are overlaid from
 * {@see EventRecord::$storedContent}. The one edge: a personal key the stored row does not carry,
 * born or renamed by an upcaster, has no stored form to show, and the hydrated value may be
 * decrypted cleartext, so it renders the key's declared fallback rather than leak.
 *
 * An empty map, the unmarked-app default, makes this a pass-through of `toPayload()`.
 *
 * @see \Storm\Message\Attribute\Personal
 */
final readonly class PersonalDataVeil
{
    public function __construct(
        /**
         * The compiled `storm.personal_data` map: class => {subject, keys, fallbacks}; the bundle
         * overrides the empty default with the `#[Personal]` scan product. Every declared key
         * carries a fallback: `Personal::__construct` refuses the miss at container build, which is
         * why `veil()` indexes `fallbacks` without a coalesce; a hand-built map owes the same
         * shape, a missing fallback there renders a warning plus `null` instead of the veil.
         *
         * @var array<class-string, array{subject: string, keys: list<string>, fallbacks: array<string, scalar|null>}>
         */
        private array $map = [],
    ) {}

    /**
     * Whether any marked class declares `$key` as personal, whatever the class: the question an
     * inspection surface asks BEFORE it persists or compares an operator-supplied value against
     * that key, where the per-class map would demand a type the surface does not have.
     */
    public function isDeclaredKey(string $key): bool
    {
        return array_any($this->map, fn ($declared) => in_array($key, $declared['keys'], true));
    }

    /**
     * The render-safe payload of one stored event.
     *
     * @return array<string, mixed>
     *
     * @throws NotADomainEvent when the record's envelope does not wrap a domain event
     */
    public function veil(EventRecord $record): array
    {
        $event = $record->event();
        $payload = $event->toPayload();

        $declared = $this->map[$event::class] ?? null;

        if ($declared === null) {
            return $payload;
        }

        foreach ($declared['keys'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue; // the current shape does not carry it; nothing to veil
            }

            // as stored, envelope or pre-marking cleartext, else the declared fallback: the
            // hydrated value behind it may be decrypted, and this surface never shows that
            $payload[$key] = array_key_exists($key, $record->storedContent)
                ? $record->storedContent[$key]
                : $declared['fallbacks'][$key];
        }

        return $payload;
    }

    /**
     * The render-safe payload of a LIVE event, one that arrives rebuilt with no row behind it.
     *
     * A handler on the event bus, a live trace, a webhook: the surface holds the deserialized event
     * and nothing else, so there is no stored form to overlay and `veil()` cannot serve it. Every
     * declared key therefore renders its declared fallback, which makes this strictly more redacting
     * than `veil()`: the envelope proves a field is protected, the fallback only says that it is.
     *
     * It answers the question the two surfaces share, whether the value on the way out is the one the
     * store ciphers, and it answers it without the row. A surface that HAS the row owes the sharper
     * rendering and calls `veil()` instead.
     *
     * An unmarked class passes through, as does a declared key the current shape does not carry.
     *
     * @return array<string, mixed>
     */
    public function redact(DomainEvent $event): array
    {
        $payload = $event->toPayload();
        $declared = $this->map[$event::class] ?? null;

        if ($declared === null) {
            return $payload;
        }

        foreach ($declared['keys'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $declared['fallbacks'][$key];
            }
        }

        return $payload;
    }
}
