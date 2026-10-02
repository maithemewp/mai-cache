<?php
declare(strict_types=1);

namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Cache;
use Mai\Cache\Tests\Support\ArrayStore;
use Mai\Cache\Tests\TestCase;

final class LockTest extends TestCase {
	/**
	 * Locks currently held, as the object cache would hold them. Keyed by "group|key".
	 *
	 * @var array<string,bool>
	 */
	private array $held = [];

	/**
	 * Every wp_cache_add() and wp_cache_delete() call, as "add|group|key" or "delete|group|key".
	 *
	 * @var string[]
	 */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->held  = [];
		$this->calls = [];

		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );

		// wp_cache_add() adds only when the key is free. wp_cache_delete() reports whether it removed one.
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $data, $group = '', $expire = 0 ) {
				$this->calls[] = "add|$group|$key";

				if ( isset( $this->held[ "$group|$key" ] ) ) {
					return false;
				}

				$this->held[ "$group|$key" ] = true;

				return true;
			}
		);

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				$this->calls[] = "delete|$group|$key";

				if ( ! isset( $this->held[ "$group|$key" ] ) ) {
					return false;
				}

				unset( $this->held[ "$group|$key" ] );

				return true;
			}
		);
	}

	private function cache(): Cache {
		return new Cache( 'mai', new ArrayStore() );
	}

	public function test_a_second_lock_fails_while_the_first_is_held(): void {
		$cache = $this->cache();

		$this->assertTrue( $cache->lock( 'k' ) );
		$this->assertFalse( $cache->lock( 'k' ) );
	}

	public function test_unlock_lets_a_second_lock_succeed(): void {
		$cache = $this->cache();

		$this->assertTrue( $cache->lock( 'k' ) );
		$this->assertTrue( $cache->unlock( 'k' ) );
		$this->assertTrue( $cache->lock( 'k' ) );
	}

	public function test_unlock_of_a_key_that_was_never_locked_returns_false_without_error(): void {
		$cache = $this->cache();

		$this->assertFalse( $cache->unlock( 'never' ) );
		$this->assertSame( [], $this->held );
	}

	public function test_unlock_of_one_key_leaves_another_key_locked(): void {
		$cache = $this->cache();

		$this->assertTrue( $cache->lock( 'a' ) );
		$this->assertTrue( $cache->lock( 'b' ) );
		$this->assertTrue( $cache->unlock( 'a' ) );

		$this->assertFalse( $cache->lock( 'b' ) );
		$this->assertTrue( $cache->lock( 'a' ) );
	}

	public function test_lock_and_unlock_use_the_same_key_and_group_for_a_grouped_cache(): void {
		$grid = $this->cache()->group( 'grid' );

		$this->assertTrue( $grid->lock( 'k' ) );
		$this->assertTrue( $grid->unlock( 'k' ) );

		$this->assertCount( 2, $this->calls );

		[ , $add_group, $add_key ]       = explode( '|', $this->calls[0] );
		[ , $delete_group, $delete_key ] = explode( '|', $this->calls[1] );

		$this->assertSame( $add_group, $delete_group );
		$this->assertSame( $add_key, $delete_key );
		$this->assertSame( $grid->key( 'lock_k' ), $delete_key );
		$this->assertSame( [], $this->held );
	}

	public function test_a_grouped_unlock_does_not_release_the_ungrouped_lock_of_the_same_name(): void {
		$base = $this->cache();
		$grid = $base->group( 'grid' );

		$this->assertTrue( $base->lock( 'k' ) );
		$this->assertTrue( $grid->lock( 'k' ) );
		$this->assertTrue( $grid->unlock( 'k' ) );

		$this->assertFalse( $base->lock( 'k' ) );
		$this->assertTrue( $grid->lock( 'k' ) );
	}

	public function test_unlock_works_when_caching_is_off_like_lock_does(): void {
		Functions\when( 'apply_filters' )->alias(
			fn( $tag, $value = null ) => str_ends_with( (string) $tag, '_can_cache' ) ? false : $value
		);

		$cache = $this->cache();

		$this->assertFalse( $cache->can_cache() );
		$this->assertTrue( $cache->lock( 'k' ) );
		$this->assertTrue( $cache->unlock( 'k' ) );
		$this->assertTrue( $cache->lock( 'k' ) );
	}
}
