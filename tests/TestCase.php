<?php

namespace C2GS\Tests;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs(
			[
				'__'          => null,
				'_n'          => null,
				'esc_html__'  => null,
				'esc_attr__'  => null,
				'esc_html'    => null,
				'esc_attr'    => null,
				'esc_url'     => null,
				'wp_unslash'  => null,
			]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}
}
