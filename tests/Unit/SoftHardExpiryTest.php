<?php
declare(strict_types=1);

namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Cache;
use Mai\Cache\Tests\Support\ArrayStore;
use Mai\Cache\Tests\TestCase;

final class SoftHardExpiryTest extends TestCase {
	private ArrayStore $store;

	private Cache $cache;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );

		$this->store = new ArrayStore();
		$this->cache = new Cache( 'mai', $this->store );

		$this->at( 1000 );
	}

	private function at( int $time ): void {
		Cache::set_clock( fn() => $time );
	}

	public function test_fresh_before_soft(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );

		$this->at( 1099 );
		$r = $this->cache->read_swr( 'k', $v );

		$this->assertSame( 'X', $r['value'] );
		$this->assertTrue( $r['fresh'] );
		$this->assertNull( $r['stale'] );
		$this->assertSame( 1000, $r['written'] );
	}

	public function test_age_stale_after_soft(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );

		$this->at( 1100 );
		$r = $this->cache->read_swr( 'k', $v );

		$this->assertSame( 'X', $r['value'] );
		$this->assertFalse( $r['fresh'] );
		$this->assertSame( 'age', $r['stale'] );
		$this->assertSame( 1000, $r['written'] );
	}

	public function test_gone_after_hard(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );

		// At 2000 the entry still exists, as a WordPress database transient does
		// in its expiry second. It is gone one second later.
		$this->at( 2001 );

		$this->assertNull( $this->cache->read_swr( 'k', $v ) );
	}

	public function test_version_wins_over_age(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );
		$this->cache->bump( 'post' );

		// Before the soft deadline: version-stale on its own.
		$this->at( 1050 );
		$r = $this->cache->read_swr( 'k', $this->cache->version( [ 'post' ] ) );
		$this->assertSame( 'version', $r['stale'] );

		// Past the soft deadline too: both apply, version wins.
		$this->at( 1100 );
		$r = $this->cache->read_swr( 'k', $this->cache->version( [ 'post' ] ) );
		$this->assertSame( 'X', $r['value'] );
		$this->assertFalse( $r['fresh'] );
		$this->assertSame( 'version', $r['stale'] );
	}

	public function test_write_resets_both(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );

		// Rebuild at 1050: the new entry has its own written time and lifetimes.
		$this->at( 1050 );
		$this->cache->write_swr( 'k', 'Y', $v, 100, 1000 );

		// The old soft deadline (1100) has passed. The new one (1150) has not.
		$this->at( 1120 );
		$r = $this->cache->read_swr( 'k', $v );
		$this->assertSame( 'Y', $r['value'] );
		$this->assertTrue( $r['fresh'] );
		$this->assertSame( 1050, $r['written'] );

		// The new soft deadline has passed.
		$this->at( 1200 );
		$this->assertSame( 'age', $this->cache->read_swr( 'k', $v )['stale'] );

		// The old hard deadline (2000) has passed. The new one (2050) has not.
		$this->at( 2020 );
		$this->assertSame( 'Y', $this->cache->read_swr( 'k', $v )['value'] );

		// The new hard deadline has passed.
		$this->at( 2100 );
		$this->assertNull( $this->cache->read_swr( 'k', $v ) );
	}

	public function test_hard_below_soft_is_raised(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 50 );

		$this->assertSame( 1100, $this->store->expires[ $this->cache->key( 'k' ) ] );

		$this->at( 1099 );
		$r = $this->cache->read_swr( 'k', $v );
		$this->assertSame( 'X', $r['value'] );
		$this->assertTrue( $r['fresh'] );
	}

	public function test_null_hard_behaves_like_040(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100 );

		$this->assertSame( 1100, $this->store->expires[ $this->cache->key( 'k' ) ] );

		$this->at( 1099 );
		$this->assertTrue( $this->cache->read_swr( 'k', $v )['fresh'] );

		// The known one-second difference from 0.4.0. In the second the soft
		// deadline falls on, the store still holds the entry, so it reads as
		// age-stale. 0.4.0 reported nothing there. It is gone one second later.
		$this->at( 1100 );
		$r = $this->cache->read_swr( 'k', $v );
		$this->assertSame( 'X', $r['value'] );
		$this->assertFalse( $r['fresh'] );
		$this->assertSame( 'age', $r['stale'] );

		$this->at( 1101 );
		$this->assertNull( $this->cache->read_swr( 'k', $v ) );
	}

	public function test_hard_lifetime_is_the_store_expiry(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );

		$this->assertSame( 2000, $this->store->expires[ $this->cache->key( 'k' ) ] );
	}

	public function test_envelope_records_written_time_and_soft_deadline(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 100, 1000 );

		$this->assertSame(
			[ '_v' => $v, 'value' => 'X', 'w' => 1000, 's' => 1100 ],
			$this->store->data[ $this->cache->key( 'k' ) ]
		);
	}

	public function test_entry_without_s_is_never_age_stale(): void {
		$v = $this->cache->version( [ 'post' ] );

		// An entry as 2.40 and beta.4 wrote it: no written time, no soft deadline.
		$this->store->write( $this->cache->key( 'k' ), [ '_v' => $v, 'value' => 'X' ], 0 );

		$this->at( 1000 + 10 * 365 * 86400 );
		$r = $this->cache->read_swr( 'k', $v );

		$this->assertSame( 'X', $r['value'] );
		$this->assertTrue( $r['fresh'] );
		$this->assertNull( $r['stale'] );
		$this->assertNull( $r['written'] );
	}

	public function test_entry_without_s_is_still_version_stale(): void {
		$v = $this->cache->version( [ 'post' ] );

		$this->store->write( $this->cache->key( 'k' ), [ '_v' => $v, 'value' => 'X' ], 0 );
		$this->cache->bump( 'post' );

		$r = $this->cache->read_swr( 'k', $this->cache->version( [ 'post' ] ) );

		$this->assertSame( 'X', $r['value'] );
		$this->assertSame( 'version', $r['stale'] );
	}

	public function test_zero_ttl_has_no_soft_deadline(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 0 );

		$stored = $this->store->data[ $this->cache->key( 'k' ) ];
		$this->assertArrayNotHasKey( 's', $stored );
		$this->assertArrayNotHasKey( $this->cache->key( 'k' ), $this->store->expires );

		$this->at( 1000 + 10 * 365 * 86400 );
		$r = $this->cache->read_swr( 'k', $v );

		$this->assertTrue( $r['fresh'] );
		$this->assertNull( $r['stale'] );
	}

	public function test_zero_soft_with_a_hard_lifetime_is_never_age_stale(): void {
		$v = $this->cache->version( [ 'post' ] );
		$this->cache->write_swr( 'k', 'X', $v, 0, 500 );

		$this->assertArrayNotHasKey( 's', $this->store->data[ $this->cache->key( 'k' ) ] );

		$this->at( 1400 );
		$r = $this->cache->read_swr( 'k', $v );
		$this->assertTrue( $r['fresh'] );
		$this->assertSame( 1000, $r['written'] );

		$this->at( 1600 );
		$this->assertNull( $this->cache->read_swr( 'k', $v ) );
	}

	public function test_a_plain_entry_has_no_written_time(): void {
		$this->cache->set( 'k', 'plain', 3600 );

		$this->assertSame(
			[ '_v' => null, 'value' => 'plain' ],
			$this->store->data[ $this->cache->key( 'k' ) ]
		);
	}
}
