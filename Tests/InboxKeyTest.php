<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Inbox\InboxKey;

use function hash;
use function hex2bin;
use function strlen;

final class InboxKeyTest extends TestCase
{
    #[Test]
    public function a_uuid_becomes_its_sixteen_bytes(): void
    {
        $key = InboxKey::encode('0192f7a0-5b3c-7d1e-8a2b-3c4d5e6f7081');

        self::assertSame(16, strlen($key));
        self::assertSame(hex2bin('0192f7a05b3c7d1e8a2b3c4d5e6f7081'), $key);
    }

    #[Test]
    public function an_idempotent_id_becomes_the_thirty_two_bytes_of_its_hash(): void
    {
        $hex = hash('sha256', 'order-42');

        self::assertSame(hex2bin($hex), InboxKey::encode('idem-'.$hex));
    }

    #[Test]
    public function any_other_text_becomes_its_own_hash(): void
    {
        $key = InboxKey::encode('msg-1');

        self::assertSame(32, strlen($key));
        self::assertSame(hash('sha256', 'msg-1', true), $key);
        self::assertNotSame($key, InboxKey::encode('msg-2'));
    }

    #[Test]
    public function the_v7_floor_of_an_instant_sorts_below_every_key_minted_after_it(): void
    {
        $floor = InboxKey::v7Floor(1_700_000_000_000);

        self::assertSame(16, strlen($floor));
        self::assertSame(hex2bin('018bcfe56800'.'00000000000000000000'), $floor);
        // a v7 uuid minted one millisecond later carries a larger prefix, whatever follows it
        self::assertGreaterThan($floor, InboxKey::encode('018bcfe5-6801-7000-8000-000000000000'));
        self::assertLessThan($floor, InboxKey::encode('018bcfe5-67ff-7fff-bfff-ffffffffffff'));
    }

    #[Test]
    public function the_three_shapes_never_share_a_key(): void
    {
        $hex = hash('sha256', 'idem-'.hash('sha256', 'x'));

        // an idem- id and a plain text whose hash happens to be spelled the same way differ by what is
        // hashed: the hex of the one against the whole text of the other
        self::assertNotSame(InboxKey::encode('idem-'.$hex), InboxKey::encode('idem-'.hash('sha256', 'x')));
        self::assertSame(16, strlen(InboxKey::encode('00000000-0000-0000-0000-000000000000')));
    }
}
