<?php

namespace C2GS\Tests;

final class SmokeTest extends TestCase {

	public function test_autoload_and_constants_available(): void {
		$this->assertSame( 86400, DAY_IN_SECONDS );
		$this->assertTrue( class_exists( \Google\Client::class ) );
	}
}
