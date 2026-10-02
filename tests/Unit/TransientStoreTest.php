<?php
namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Tests\TestCase;
use Mai\Cache\TransientStore;

final class TransientStoreTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Install a $wpdb stand-in. Its query() returns the next value in $results
	 * each call and records the prepared SQL; prepare() and esc_like() only need
	 * to behave well enough to show what the store passes them.
	 *
	 * @param int[] $results Return values for successive query() calls.
	 */
	private function fakeWpdb( array $results ): object {
		return $GLOBALS['wpdb'] = new class( $results ) {
			public string $options = 'wp_options';
			public array $prepared = [];
			public array $queries  = [];

			public function __construct( private array $results ) {}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, ...$args ): string {
				$this->prepared[] = [ $query, $args ];
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return array_shift( $this->results ) ?? 0;
			}
		};
	}

	public function test_reads_via_get_transient(): void {
		Functions\expect( 'get_transient' )->once()->with( 'k' )->andReturn( 'v' );
		$this->assertSame( 'v', ( new TransientStore() )->read( 'k' ) );
	}

	public function test_writes_via_set_transient(): void {
		Functions\expect( 'set_transient' )->once()->with( 'k', 'v', 60 )->andReturn( true );
		$this->assertTrue( ( new TransientStore() )->write( 'k', 'v', 60 ) );
	}

	public function test_removes_via_delete_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'k' )->andReturn( true );
		$this->assertTrue( ( new TransientStore() )->remove( 'k' ) );
	}

	public function test_is_always_available(): void {
		$this->assertTrue( ( new TransientStore() )->available() );
	}

	public function test_delete_prefix_does_nothing_with_a_persistent_object_cache(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\expect( 'wp_cache_delete' )->never();

		// No $wpdb is installed, so touching the database would fail the test.
		$this->assertSame( 0, ( new TransientStore() )->delete_prefix( 'mai_s1_abc_' ) );
	}

	public function test_delete_prefix_repeats_until_a_pass_deletes_nothing(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_delete' )->once()->with( 'alloptions', 'options' );

		$wpdb = $this->fakeWpdb( [ 1000, 1000, 3, 0 ] );

		$this->assertSame( 2003, ( new TransientStore() )->delete_prefix( 'mai_s1_abc_' ) );
		$this->assertCount( 4, $wpdb->queries );
	}

	public function test_delete_prefix_matches_the_value_and_timeout_rows_with_the_prefix_escaped(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$wpdb = $this->fakeWpdb( [ 0 ] );

		( new TransientStore() )->delete_prefix( 'mai_s1_abc_' );

		[ $query, $args ] = $wpdb->prepared[0];

		$this->assertSame( 'DELETE FROM wp_options WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1000', $query );
		$this->assertSame( [ '_transient_mai\\_s1\\_abc\\_%', '_transient_timeout_mai\\_s1\\_abc\\_%' ], $args );
	}
}
