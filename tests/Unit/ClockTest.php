<?php
declare(strict_types=1);

namespace Mai\Cache\Tests\Unit;

use Mai\Cache\Cache;
use Mai\Cache\Tests\Support\ArrayStore;
use Mai\Cache\Tests\TestCase;

final class ClockTest extends TestCase {
	public function test_now_defaults_to_time(): void {
		$this->assertEqualsWithDelta( time(), Cache::now(), 1 );
	}

	public function test_set_clock_overrides_now(): void {
		Cache::set_clock( fn() => 1000 );

		$this->assertSame( 1000, Cache::now() );
	}

	public function test_reset_runtime_restores_clock(): void {
		Cache::set_clock( fn() => 1000 );
		Cache::reset_runtime();

		$this->assertEqualsWithDelta( time(), Cache::now(), 1 );
	}

	public function test_set_clock_null_restores_time(): void {
		Cache::set_clock( fn() => 1000 );
		Cache::set_clock( null );

		$this->assertEqualsWithDelta( time(), Cache::now(), 1 );
	}

	public function test_array_store_expires(): void {
		$store = new ArrayStore();

		Cache::set_clock( fn() => 1000 );
		$store->write( 'k', 'v', 10 );

		Cache::set_clock( fn() => 1009 );
		$this->assertSame( 'v', $store->read( 'k' ) );

		// Still there in the second it expires, as a database transient is.
		Cache::set_clock( fn() => 1010 );
		$this->assertSame( 'v', $store->read( 'k' ) );

		Cache::set_clock( fn() => 1011 );
		$this->assertFalse( $store->read( 'k' ) );
	}

	public function test_array_store_zero_never_expires(): void {
		$store = new ArrayStore();

		Cache::set_clock( fn() => 1000 );
		$store->write( 'k', 'v', 0 );

		Cache::set_clock( fn() => 1000 + 10 * 365 * 86400 );
		$this->assertSame( 'v', $store->read( 'k' ) );
	}
}
