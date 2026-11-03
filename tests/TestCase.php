<?php
namespace Mai\Cache\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Cache reads the site ID for every token. Once any test mocks it, Brain Monkey leaves it
		// defined, so every test needs an answer. SwitchedBlogTest replaces this one.
		Monkey\Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		if ( method_exists( \Mai\Cache\Cache::class, 'reset_runtime' ) ) {
			\Mai\Cache\Cache::reset_runtime();
		}
		Monkey\tearDown();
		parent::tearDown();
	}
}
