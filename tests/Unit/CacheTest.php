<?php
namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Cache;
use Mai\Cache\Tests\Support\ArrayStore;
use Mai\Cache\Tests\TestCase;

final class CacheTest extends TestCase {
	private function allowCaching(): void {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	public function test_remember_returns_callback_value_and_caches_it(): void {
		$this->allowCaching();
		$cache = new Cache( 'mai', new ArrayStore() );
		$calls = 0;
		$cb    = function () use ( &$calls ) { $calls++; return 'value'; };

		$this->assertSame( 'value', $cache->remember( 'k', $cb, 60 ) ); // miss → runs callback
		$this->assertSame( 'value', $cache->remember( 'k', $cb, 60 ) ); // hit → no callback
		$this->assertSame( 1, $calls );
	}

	public function test_remember_does_not_cache_wp_error(): void {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		Functions\when( 'is_wp_error' )->justReturn( true );
		$store = new ArrayStore();
		$cache = new Cache( 'mai', $store );

		$cache->remember( 'k', fn() => 'err', 60 );
		$this->assertFalse( $cache->get( 'k' ) ); // WP_Error value was not cached
	}

	public function test_object_mode_is_noop_when_store_unavailable(): void {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		$store            = new ArrayStore();
		$store->available = false;
		$cache            = new Cache( 'mai', $store );

		$this->assertFalse( $cache->set( 'k', 'v', 60 ) ); // can_cache false → no write
		$this->assertFalse( $cache->get( 'k' ) );          // can_cache false → miss
		$this->assertSame( [], $store->data );
	}

	public function test_factories_pick_the_right_store(): void {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$this->assertTrue( Cache::for( 'x' )->can_cache() );      // transient store: always available
		$this->assertFalse( Cache::object( 'x' )->can_cache() );  // object store: unavailable here
	}

	public function test_has_persistent_object_cache_wraps_wp_function(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$this->assertTrue( Cache::has_persistent_object_cache() );
	}

	public function test_pull_returns_value_then_deletes_it(): void {
		$this->allowCaching();
		$store = new ArrayStore();
		$cache = new Cache( 'mai', $store );

		$cache->set( 'token', 'once', 60 );
		$this->assertSame( 'once', $cache->pull( 'token' ) ); // returns the value
		$this->assertFalse( $cache->get( 'token' ) );          // and it is gone
	}

	public function test_pull_returns_default_on_miss(): void {
		$this->allowCaching();
		$cache = new Cache( 'mai', new ArrayStore() );

		$this->assertSame( 'fallback', $cache->pull( 'missing', 'fallback' ) );
	}
	/**
	 * The bug 0.4.0 fixes. The store's own miss sentinel is false, so before
	 * envelopes a stored false looked like a miss and remember() re-ran its
	 * callback on every request -- for a condition that answers true/false,
	 * that is every visitor on one side of the answer, forever.
	 */
	public function test_a_cached_false_is_a_hit(): void {
		$this->allowCaching();
		$cache = new Cache( 'mai', new ArrayStore() );
		$calls = 0;
		$cb    = function () use ( &$calls ) { $calls++; return false; };

		$this->assertFalse( $cache->remember( 'flag', $cb, 60 ) );
		$this->assertFalse( $cache->remember( 'flag', $cb, 60 ) );
		$this->assertSame( 1, $calls ); // second call was served from cache
	}

	public function test_has_tells_a_stored_false_from_a_miss(): void {
		$this->allowCaching();
		$cache = new Cache( 'mai', new ArrayStore() );

		$this->assertFalse( $cache->has( 'flag' ) );
		$cache->set( 'flag', false, 60 );
		$this->assertTrue( $cache->has( 'flag' ) );
		$this->assertFalse( $cache->get( 'flag' ) ); // the public contract is unchanged
	}

	public function test_pull_returns_a_stored_false_rather_than_the_default(): void {
		$this->allowCaching();
		$cache = new Cache( 'mai', new ArrayStore() );

		$cache->set( 'flag', false, 60 );
		$this->assertFalse( $cache->pull( 'flag', 'default' ) );
		$this->assertFalse( $cache->has( 'flag' ) ); // and it is gone
	}

	public function test_a_raw_pre_envelope_value_reads_as_a_miss(): void {
		$this->allowCaching();
		$store = new ArrayStore();
		$cache = new Cache( 'mai', $store );

		// What a pre-0.4.0 build would have written: the bare value.
		$store->data[ $cache->key( 'legacy' ) ] = 'bare';

		$this->assertFalse( $cache->has( 'legacy' ) );
		$this->assertFalse( $cache->get( 'legacy' ) );
		$this->assertSame( 'fresh', $cache->remember( 'legacy', fn() => 'fresh', 60 ) );
		$this->assertSame( 'fresh', $cache->get( 'legacy' ) ); // rewritten wrapped
	}

	public function test_has_is_false_when_caching_is_disabled(): void {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		$store            = new ArrayStore();
		$store->available = false;
		$cache            = new Cache( 'mai', $store );

		$cache->set( 'k', 'v', 60 );
		$this->assertFalse( $cache->has( 'k' ) );
	}
}
