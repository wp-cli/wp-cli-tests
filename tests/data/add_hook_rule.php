<?php

/**
 * Test data for WPCliAddHookCallbackRule.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use WP_CLI;

// Valid: before_wp_load with 0 required args.
WP_CLI::add_hook(
	'before_wp_load',
	static function () {
		// valid
	}
);

// Valid: before_wp_load with optional arg.
WP_CLI::add_hook(
	'before_wp_load',
	static function ( $optional = null ) {
		// valid
	}
);

// Invalid: before_wp_load requires 1 arg, but do_hook passes 0.
WP_CLI::add_hook(
	'before_wp_load',
	static function ( $required_arg ) {
		// invalid
	}
);

// Valid: before_invoke:cmd with 1 required arg.
WP_CLI::add_hook(
	'before_invoke:user list',
	static function ( $cmd ) {
		// valid
	}
);

// Invalid: before_invoke:cmd requires 2 args, but do_hook passes 1.
WP_CLI::add_hook(
	'before_invoke:user list',
	static function ( $cmd, $extra_arg ) {
		// invalid
	}
);

// Invalid: callback is not callable.
WP_CLI::add_hook( 'before_wp_load', 'non_existent_function_12345' );
