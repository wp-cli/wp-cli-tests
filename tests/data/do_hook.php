<?php

/**
 * Test data for WPCliDoHookDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use WP_CLI;
use function PHPStan\Testing\assertType;

// Action without arguments returns null.
$action_result = WP_CLI::do_hook( 'custom_action' );
assertType( 'null', $action_result );

// Filter with scalar argument returns type of argument.
$string_result = WP_CLI::do_hook( 'custom_string_filter', 'default_val' );
assertType( "'default_val'", $string_result );

// Filter with array argument returns exact array type.
$array_data   = [
	'a' => 1,
	'b' => 2,
];
$array_result = WP_CLI::do_hook( 'custom_array_filter', $array_data );
assertType( 'array{a: 1, b: 2}', $array_result );

/** @var array<string, int> $typed_array */
$typed_array  = [ 'count' => 5 ];
$typed_result = WP_CLI::do_hook( 'custom_typed_filter', $typed_array );
assertType( 'array<string, int>', $typed_result );

/**
 * Filter available options.
 *
 * @param array<string, bool> $options Filtered options.
 */
$docblock_result = WP_CLI::do_hook( 'custom_docblock_filter', $typed_array );
assertType( 'array<string, bool>', $docblock_result );

/**
 * Filter available formats.
 *
 * @param string[] $formats Array of format names.
 */
$formats_result = WP_CLI::do_hook( 'formatter_available_formats', [ 'table', 'json' ] );
assertType( 'array<string>', $formats_result );
