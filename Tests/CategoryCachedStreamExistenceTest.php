<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Storm\Chronicler\Directory\CategoryCachedStreamExistence;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Erasure\CacheEvictingStreamEraser;
use Storm\Chronicler\Erasure\StreamEraser;
use Storm\Clock\FrozenClock;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;

/**
 * The cache decorator's semantics, the inner port mocked and the clock frozen: only category
 * liveness is ever remembered, the exact `hasStream` probe stays live on every call, a positive
 * is served from the set without reprobing, a negative expires with its TTL by the golden rule of
 * never a durable false negative, and erasure-side eviction forces the next probe to re-confirm.
 */
final class CategoryCachedStreamExistenceTest extends TestCase
{
    #[Test]
    public function a_confirmed_category_is_served_from_the_set_without_reprobing(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->once())->method('hasCategory')->with('order')->willReturn(true);

        $cache = $this->cache($inner);

        $this->assertTrue($cache->hasCategory('order'));
        $this->assertTrue($cache->hasCategory('order')); // second answer comes from the set
    }

    #[Test]
    public function the_cache_key_is_the_normalized_category(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->once())->method('hasCategory')->with('order')->willReturn(true);

        $cache = $this->cache($inner);

        $this->assertTrue($cache->hasCategory(' ORDER '));
        $this->assertTrue($cache->hasCategory('order')); // same key once normalized
    }

    #[Test]
    #[Group('adversarial')]
    public function a_category_born_after_a_remembered_negative_is_seen_once_the_ttl_expires(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->exactly(2))->method('hasCategory')->with('order')
            ->willReturn(false, true); // absent at first probe, alive at the re-probe

        $clock = FrozenClock::fromNow();
        $cache = $this->cache($inner, $clock);

        $this->assertFalse($cache->hasCategory('order'));
        $this->assertFalse($cache->hasCategory('order')); // within the TTL: remembered, no probe

        $clock->advance(31);

        $this->assertTrue($cache->hasCategory('order')); // TTL expired: re-probed, never a durable lie
    }

    #[Test]
    public function the_default_negative_ttl_holds_a_negative_for_thirty_seconds(): void
    {
        // Built the way the bundle builds it when the deployment names no TTL, so the default is a
        // measured window and not a number nobody reads. It is the upper bound on the "category just
        // born, not visible yet" blindness, which is why the second boundary matters as much as the
        // first: too long lies, too short spends a probe per call.
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->exactly(2))->method('hasCategory')->with('order')
            ->willReturn(false, true); // absent at first probe, born by the re-probe

        $clock = FrozenClock::fromNow();
        $cache = new CategoryCachedStreamExistence($inner, $clock);

        $this->assertFalse($cache->hasCategory('order')); // the probe that establishes the negative

        $clock->advance(29);

        $this->assertFalse($cache->hasCategory('order')); // one second short of the window: remembered

        $clock->advance(1);

        $this->assertTrue($cache->hasCategory('order')); // the window is over: probed again, and it lives
    }

    #[Test]
    public function the_exact_stream_probe_stays_live_on_every_call(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->never())->method('hasCategory'); // the exact question never consults the category cache
        $inner->expects($this->exactly(2))->method('hasStream')->willReturn(true); // never cached per stream

        $cache = $this->cache($inner);

        $this->assertTrue($cache->hasStream(new StreamName('order-1')));
        $this->assertTrue($cache->hasStream(new StreamName('order-1'))); // liveness from the set, probe live
    }

    #[Test]
    public function an_existing_stream_feeds_its_category_into_the_set(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->never())->method('hasCategory'); // proven by the stream alone
        $inner->expects($this->once())->method('hasStream')->willReturn(true);

        $cache = $this->cache($inner);

        $this->assertTrue($cache->hasStream(new StreamName('order-1')));
        $this->assertTrue($cache->hasCategory('order')); // proven by the stream, no reprobe
    }

    #[Test]
    #[Group('adversarial')]
    public function the_exact_probe_stays_live_even_under_a_remembered_negative_category(): void
    {
        // the exact-stream probe must never be answered by the negative category gate: that would
        // serve a stale false until the TTL for a stream appended right after the negative was
        // cached, breaking read-your-writes, against the port's own "always live" rule.
        // The exact probe ALWAYS runs; the negative cache serves hasCategory() only.
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->once())->method('hasCategory')->with('order')->willReturn(false);
        $inner->expects($this->once())->method('hasStream')->willReturn(true);

        $clock = FrozenClock::fromNow();
        $cache = $this->cache($inner, $clock);

        $this->assertFalse($cache->hasCategory('order')); // primes the remembered negative

        // the category was just born, by a first append elsewhere: the exact question sees it NOW,
        // not one TTL later, and the confirmed stream re-proves the category for free
        $this->assertTrue($cache->hasStream(new StreamName('order-1')));
        $this->assertTrue($cache->hasCategory('order')); // fed by the confirmed stream, no reprobe
    }

    #[Test]
    public function forget_category_evicts_the_positive_so_the_next_probe_reconfirms(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->exactly(2))->method('hasCategory')->with('order')->willReturn(true);

        $cache = $this->cache($inner);

        $this->assertTrue($cache->hasCategory('order'));
        $cache->forgetCategory('order');
        $this->assertTrue($cache->hasCategory('order')); // reprobed after the eviction
    }

    #[Test]
    #[Group('adversarial')]
    public function a_flood_of_distinct_garbage_categories_never_grows_the_negative_map_beyond_its_cap(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->exactly(1001))->method('hasCategory')->willReturn(false);

        $cache = $this->cache($inner);

        for ($i = 0; $i <= 1000; $i++) {
            $this->assertFalse($cache->hasCategory('g'.$i));
        }

        // every remembered negative was still live at the 1001st insert, so the sweep reset the map
        $this->assertCount(1, new ReflectionProperty(CategoryCachedStreamExistence::class, 'absentUntil')->getValue($cache));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_sweep_drops_expired_negatives_before_resorting_to_a_reset(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->exactly(1001))->method('hasCategory')->willReturn(false);

        $clock = FrozenClock::fromNow();
        $cache = $this->cache($inner, $clock);

        for ($i = 0; $i < 500; $i++) {
            $cache->hasCategory('old'.$i);
        }

        $clock->advance(31); // the 500 negatives above are now expired

        for ($i = 0; $i <= 500; $i++) {
            $cache->hasCategory('new'.$i);
        }

        // the cap hit at the 501st fresh insert: the sweep dropped the 500 expired, kept the live ones
        $this->assertCount(501, new ReflectionProperty(CategoryCachedStreamExistence::class, 'absentUntil')->getValue($cache));
    }

    #[Test]
    public function a_qualified_stream_name_is_rejected_as_category_before_any_probe(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->never())->method('hasCategory');

        $this->expectException(InvalidStreamException::class);

        $this->cache($inner)->hasCategory('order-42');
    }

    #[Test]
    public function erasing_a_stream_evicts_its_category_liveness(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->exactly(2))->method('hasCategory')->with('order')->willReturn(true);
        $cache = $this->cache($inner);
        $this->assertTrue($cache->hasCategory('order')); // seed the set

        $innerEraser = $this->createMock(StreamEraser::class);
        $innerEraser->expects($this->once())->method('erase')->willReturn(3);

        new CacheEvictingStreamEraser($innerEraser, $cache)->erase(new StreamName('order-1'));

        $this->assertTrue($cache->hasCategory('order')); // evicted, so this answer required a reprobe
    }

    #[Test]
    public function a_missed_erase_leaves_the_cache_untouched(): void
    {
        $inner = $this->createMock(StreamExistence::class);
        $inner->expects($this->once())->method('hasCategory')->with('order')->willReturn(true);
        $cache = $this->cache($inner);
        $this->assertTrue($cache->hasCategory('order')); // seed the set

        $innerEraser = $this->createMock(StreamEraser::class);
        $innerEraser->expects($this->once())->method('erase')->willReturn(0); // unknown stream

        new CacheEvictingStreamEraser($innerEraser, $cache)->erase(new StreamName('order-1'));

        $this->assertTrue($cache->hasCategory('order')); // still served from the set, no reprobe
    }

    private function cache(StreamExistence $inner, ?FrozenClock $clock = null): CategoryCachedStreamExistence
    {
        return new CategoryCachedStreamExistence($inner, $clock ?? FrozenClock::fromNow(), 30);
    }
}
