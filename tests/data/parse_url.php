<?php

/**
 * Test data for WpParseUrlFunctionDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use function WP_CLI\Utils\parse_url;
use function PHPStan\Testing\assertType;

/** @var int $integer */
$integer = doFoo();

/** @var string $string */
$string = doFoo();

$value = parse_url( 'http://abc.def' );
assertType( "array{scheme: 'http', host: 'abc.def'}", $value );

$value = parse_url( 'http://def.abc', -1 );
assertType( "array{scheme: 'http', host: 'def.abc'}", $value );

$value = parse_url( 'http://def.abc', $integer );
assertType( 'array{scheme?: string, host?: string, port?: int<0, 65535>, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|int<0, 65535>|string|false|null', $value );

$value = parse_url( 'http://def.abc', PHP_URL_FRAGMENT );
assertType( 'null', $value );

$value = parse_url( 'http://def.abc#this-is-fragment', PHP_URL_FRAGMENT );
assertType( "'this-is-fragment'", $value );

$value = parse_url( 'http://def.abc#this-is-fragment', 9999 );
assertType( 'false', $value );

$value = parse_url( $string, 9999 );
assertType( 'false', $value );

$value = parse_url( $string, PHP_URL_PORT );
assertType( 'int<0, 65535>|false|null', $value );

$value = parse_url( $string );
assertType( 'array{scheme?: string, host?: string, port?: int<0, 65535>, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|false', $value );

/** @var 'http://def.abc'|'https://example.com' $union */
$union = $union;
assertType( "array{scheme: 'http', host: 'def.abc'}|array{scheme: 'https', host: 'example.com'}", parse_url( $union ) );

/** @var 'http://def.abc#fragment1'|'https://example.com#fragment2' $union */
$union = $union;
assertType( "'fragment1'|'fragment2'", parse_url( $union, PHP_URL_FRAGMENT ) );
