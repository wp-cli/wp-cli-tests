<?php

/**
 * Test data for WpParseUrlFunctionDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use function WP_CLI\Utils\get_flag_value;
use function PHPStan\Testing\assertType;

$value = get_flag_value(
	[
		'foo' => 'bar',
		'baz' => 'qux',
	],
	'foo'
);
assertType( "'bar'", $value );

$value = get_flag_value(
	[
		'foo' => 'bar',
		'baz' => 'qux',
	],
	'bar'
);
assertType( 'null', $value );

$value = get_flag_value(
	[
		'foo' => 'bar',
		'baz' => 'qux',
	],
	'bar',
	123
);
assertType( '123', $value );

$value = get_flag_value(
	[
		'foo' => 'bar',
		'baz' => true,
	],
	'baz',
	123
);
assertType( 'true', $value );

$assoc_args = [
	'foo' => 'bar',
	'baz' => true,
];
$key        = 'baz';

$value = get_flag_value( $assoc_args, $key, 123 );
assertType( 'true', $value );

$value = get_flag_value( $assoc_args, $key2, 123 );
assertType( "123|'bar'|true", $value );
