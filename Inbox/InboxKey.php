<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

use function hash;
use function hex2bin;
use function pack;
use function preg_match;
use function str_repeat;
use function strlen;
use function substr;

/**
 * The binary form a message id takes in `es_inbox`, so a proof of consumption costs 16 or 32 bytes
 * of key rather than the 36 to 69 characters of its text.
 *
 * Three input shapes use two binary lengths:
 *
 * - An rfc4122 uuid, the id every enriched message carries, becomes its 16 bytes
 * - An `idem-` id, a sha256 in hex that the API derives from an `Idempotency-Key`, becomes the 32
 *   bytes of that hash
 * - Any other text becomes the 32 bytes of its own sha256
 *
 * The two 32-byte shapes share a key domain: text whose sha256 equals an `idem-` digest produces
 * the same inbox key. This encoding does not separate those input domains. Deduplication of
 * arbitrary text relies on sha256 collision resistance.
 */
final readonly class InboxKey
{
    private const string IDEMPOTENT_PREFIX = 'idem-';

    // No `new InboxKey(...)` call site exists anywhere in src/ or tests/: the class is a static-only
    // encoder, read through v7Floor() and encode(). This constructor exists solely to block
    // instantiation, so it is unreachable except through Reflection, which would assert nothing
    // about behavior.
    // @codeCoverageIgnoreStart
    private function __construct() {}

    // @codeCoverageIgnoreEnd

    /**
     * The smallest v7 key of a given instant: its 48 bits of unix milliseconds, then zeros. Every v7
     * key minted before that instant sorts below it, so a retention bound is a range on the primary
     * index rather than a predicate on every row.
     *
     * @throws InvalidArgumentException when the instant is outside unsigned 48-bit milliseconds
     */
    public static function v7Floor(int $unixMilliseconds): string
    {
        if ($unixMilliseconds < 0 || $unixMilliseconds >= 281_474_976_710_656) {
            throw new InvalidArgumentException('An inbox v7 floor requires unsigned 48-bit Unix milliseconds.');
        }

        // the eight packed bytes leave six past the offset, so a longer length reads the same six: an
        // equivalent mutant, left unignored on purpose, the offset and the padding here being killed
        return substr(pack('J', $unixMilliseconds), 2, 6).str_repeat("\0", 10);
    }

    public static function encode(string $messageId): string
    {
        if (strlen($messageId) === 36 && Uuid::isValid($messageId)) {
            return Uuid::fromString($messageId)->toBinary();
        }

        if (preg_match('/\Aidem-[0-9a-f]{64}\z/', $messageId) === 1) {
            return (string) hex2bin(substr($messageId, strlen(self::IDEMPOTENT_PREFIX)));
        }

        return hash('sha256', $messageId, true);
    }
}
