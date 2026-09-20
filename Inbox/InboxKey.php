<?php

declare(strict_types=1);

namespace Storm\Chronicler\Inbox;

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
 * Three shapes, each in its own length so two shapes can never collide:
 *
 * - An rfc4122 uuid, the id every enriched message carries, becomes its 16 bytes
 * - An `idem-` id, a sha256 in hex that the API derives from an `Idempotency-Key`, becomes the 32
 *   bytes of that hash
 * - Any other text becomes the 32 bytes of its own sha256
 *
 * The last shape is a hash, so two distinct texts collide with the probability of sha256, which the
 * inbox accepts where a 64-bit hash was refused: a collision here needs 2 to the 128th messages.
 */
final readonly class InboxKey
{
    private const string IDEMPOTENT_PREFIX = 'idem-';

    private function __construct() {}

    /**
     * The smallest v7 key of a given instant: its 48 bits of unix milliseconds, then zeros. Every v7
     * key minted before that instant sorts below it, so a retention bound is a range on the primary
     * index rather than a predicate on every row.
     */
    public static function v7Floor(int $unixMilliseconds): string
    {
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
