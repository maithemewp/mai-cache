<?php
namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Cache;
use Mai\Cache\Tests\Support\BlogStore;
use Mai\Cache\Tests\TestCase;

/**
 * Tokens read on one site must not be used after switch_to_blog() to another. Each site keeps
 * its own tokens in its own storage, so a rotation made while switched has to land under the
 * target site's tokens, or that site never sees it.
 */
final class SwitchedBlogTest extends TestCase {
	private BlogStore $store;

	protected function setUp(): void {
		parent::setUp();

		$this->store = new BlogStore();

		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->alias( fn() => $this->store->blog_id );
	}

	private function grid(): Cache {
		return ( new Cache( 'mai', $this->store ) )->group( 'grid' );
	}

	/** Starts a new request on a site: nothing memoized carries over. */
	private function request( int $blog_id ): void {
		Cache::reset_runtime();
		$this->store->blog_id = $blog_id;
	}

	public function test_a_bump_while_switched_reaches_the_target_site(): void {
		$this->request( 5 );
		$before = $this->grid()->version( [ 'post' ] );

		// Site 1 reads its own tokens, then switches to site 5 and changes a post there.
		$this->request( 1 );
		$this->grid()->version( [ 'post' ] );
		$this->store->blog_id = 5;
		$this->grid()->bump( 'post' );
		$this->store->blog_id = 1;

		$this->request( 5 );
		$this->assertNotSame( $before, $this->grid()->version( [ 'post' ] ) );
	}

	public function test_a_flush_while_switched_reaches_the_target_site(): void {
		$this->request( 5 );
		$this->grid()->set( 'k', 'v', 60 );

		$this->request( 1 );
		$this->grid()->get( 'k' );
		$this->store->blog_id = 5;
		$this->grid()->flush();
		$this->store->blog_id = 1;

		$this->request( 5 );
		$this->assertFalse( $this->grid()->get( 'k' ) );
	}

	/** A flush on the other site must not change the codes this site remembers. */
	public function test_switching_back_keeps_the_first_sites_keys(): void {
		$this->request( 1 );
		$key = $this->grid()->key( 'k' );

		$this->store->blog_id = 5;
		$this->grid()->flush();
		$this->store->blog_id = 1;

		$this->assertSame( $key, $this->grid()->key( 'k' ) );
	}
}
