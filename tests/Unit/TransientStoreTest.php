<?php
namespace Mai\Cache\Tests\Unit;

use Brain\Monkey\Functions;
use Mai\Cache\Tests\TestCase;
use Mai\Cache\TransientStore;
use PHPUnit\Framework\Attributes\DataProvider;

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
	 * @param array<int|false> $results Return values for successive query() calls.
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

			public function query( string $sql ): int|bool {
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
		$this->assertSame( [ '\\_transient\\_mai\\_s1\\_abc\\_%', '\\_transient\\_timeout\\_mai\\_s1\\_abc\\_%' ], $args );
	}

	public function test_delete_prefix_pattern_matches_only_that_prefix(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$wpdb = $this->fakeWpdb( [ 0 ] );

		( new TransientStore() )->delete_prefix( 'mai_s1_abc_' );

		[ $value_like, $timeout_like ] = $wpdb->prepared[0][1];

		// The two rows of a transient under the prefix match.
		$this->assertTrue( $this->likeMatches( $value_like, '_transient_mai_s1_abc_thing' ) );
		$this->assertTrue( $this->likeMatches( $timeout_like, '_transient_timeout_mai_s1_abc_thing' ) );

		// A LIKE wildcard left unescaped anywhere would match these. "_" matches any one character.
		$this->assertFalse( $this->likeMatches( $value_like, 'xtransientxmai_s1_abc_thing' ) );
		$this->assertFalse( $this->likeMatches( $value_like, '_transient_maixs1xabc_thing' ) );
		$this->assertFalse( $this->likeMatches( $value_like, '_transient_mai_s1_abcXthing' ) );

		// Another token, the token row, and the other prefix's rows do not match.
		$this->assertFalse( $this->likeMatches( $value_like, '_transient_mai_s1_abd_thing' ) );
		$this->assertFalse( $this->likeMatches( $value_like, '_transient_mai__token' ) );
		$this->assertFalse( $this->likeMatches( $value_like, '_transient_timeout_mai_s1_abc_thing' ) );
		$this->assertFalse( $this->likeMatches( $timeout_like, '_transient_mai_s1_abc_thing' ) );
	}

	#[DataProvider( 'unsafe_prefixes' )]
	public function test_delete_prefix_does_nothing_for_an_empty_prefix_or_one_without_a_trailing_underscore( string $prefix ): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_delete' )->never();

		$wpdb = $this->fakeWpdb( [ 5 ] );

		$this->assertSame( 0, ( new TransientStore() )->delete_prefix( $prefix ) );
		$this->assertSame( [], $wpdb->queries );
		$this->assertSame( [], $wpdb->prepared );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function unsafe_prefixes(): array {
		return [
			'empty'                        => [ '' ],
			'no trailing underscore'       => [ 'mai_s1_abc' ],
			'a bare prefix'                => [ 'mai' ],
			'underscore only at the start' => [ '_mai' ],
		];
	}

	public function test_delete_prefix_stops_when_the_query_fails(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_delete' )->once()->with( 'alloptions', 'options' );

		// A failed $wpdb->query() returns false. It must end the loop, not repeat it.
		$wpdb = $this->fakeWpdb( [ false, 5 ] );

		$this->assertSame( 0, ( new TransientStore() )->delete_prefix( 'mai_s1_abc_' ) );
		$this->assertCount( 1, $wpdb->queries );
	}

	/**
	 * Whether a SQL LIKE pattern matches a value. A backslash makes the next character
	 * literal, "_" is any one character and "%" is any run of characters.
	 */
	private function likeMatches( string $pattern, string $value ): bool {
		$regex = '';

		for ( $i = 0, $length = strlen( $pattern ); $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}

		return 1 === preg_match( '/^' . $regex . '$/s', $value );
	}
}
