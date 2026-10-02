<?php
namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Cache;
use Mai\Cache\Tests\Support\ArrayStore;
use Mai\Cache\Tests\Support\PrefixRecordingStore;
use Mai\Cache\Tests\TestCase;

final class FlushCleanupTest extends TestCase {
	private function allowCaching(): void {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	public function test_group_flush_deletes_old_group_prefix(): void {
		$this->allowCaching();
		$store  = new PrefixRecordingStore();
		$base   = new Cache( 'mai', $store );
		$grid   = $base->group( 'grid' );
		$header = $base->group( 'header' );

		$grid->set( 'a', 'A', 60 );
		$header->set( 'b', 'B', 60 );

		$old_key        = $grid->key( 'a' );
		$other_group    = $header->key( 'b' );
		$old_grid_token = $store->read( 'mai_grid__token' );

		$grid->flush();

		$this->assertCount( 1, $store->deleted );

		$prefix = $store->deleted[0];

		$this->assertMatchesRegularExpression( '/^mai_s1_[0-9a-f]{12}_grid_' . $old_grid_token . '_$/', $prefix );
		$this->assertStringStartsWith( $prefix, $old_key );
		$this->assertArrayHasKey( $old_key, $store->inner->data );
		$this->assertStringStartsNotWith( $prefix, $other_group );
		$this->assertStringStartsNotWith( $prefix, $grid->key( 'a' ) );
	}

	public function test_root_flush_deletes_old_root_prefix(): void {
		$this->allowCaching();
		$store = new PrefixRecordingStore();
		$base  = new Cache( 'mai', $store );
		$grid  = $base->group( 'grid' );

		$base->set( 'a', 'A', 60 );
		$grid->set( 'b', 'B', 60 );

		$old_root_key  = $base->key( 'a' );
		$old_group_key = $grid->key( 'b' );
		$old_token     = $store->read( 'mai__token' );

		$base->flush();

		$this->assertSame( [ 'mai_s1_' . $old_token . '_' ], $store->deleted );

		$prefix = $store->deleted[0];

		$this->assertStringStartsWith( $prefix, $old_root_key );
		$this->assertStringStartsWith( $prefix, $old_group_key );
		$this->assertStringStartsNotWith( $prefix, $base->key( 'a' ) );
		$this->assertStringStartsNotWith( $prefix, $grid->key( 'b' ) );
	}

	public function test_prefix_is_deleted_after_the_token_rotates(): void {
		$this->allowCaching();
		$store = new PrefixRecordingStore();
		$cache = new Cache( 'mai', $store );

		$cache->set( 'a', 'A', 60 );
		$old_token = $store->read( 'mai__token' );

		$cache->flush();

		$this->assertCount( 1, $store->data_at_delete );
		$this->assertNotSame( $old_token, $store->data_at_delete[0]['mai__token'] );
		$this->assertSame( $store->read( 'mai__token' ), $store->data_at_delete[0]['mai__token'] );
	}

	public function test_flush_still_deletes_the_prefix_when_caching_is_off(): void {
		$this->allowCaching();
		$store = new PrefixRecordingStore();
		$cache = new Cache( 'mai', $store );

		$cache->set( 'a', 'A', 60 );

		$store->inner->available = false;

		$this->assertFalse( $cache->can_cache() );
		$this->assertTrue( $cache->flush() );
		$this->assertCount( 1, $store->deleted );
	}

	public function test_flush_without_prefix_delete_still_rotates(): void {
		$this->allowCaching();
		$cache = new Cache( 'mai', new ArrayStore() );

		$cache->set( 'a', 'A', 60 );
		$this->assertSame( 'A', $cache->get( 'a' ) );

		$this->assertTrue( $cache->flush() );
		$this->assertFalse( $cache->has( 'a' ) );
	}
}
