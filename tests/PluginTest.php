<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\Plugin;

final class PluginTest extends TestCase {

	public function test_boot_registers_the_form_vibes_hook(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb = null, $priority = 10, $args = 1 ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_options_page' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://site/wp-admin/' . $p );

		Plugin::instance()->boot();

		$this->assertContains( 'fv_after_entry_meta_success', $hooks );
		$this->assertContains( 'metform_after_store_form_data', $hooks );
		$this->assertContains( 'fluentform/submission_inserted', $hooks );
	}

	public function test_instance_is_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}
}
