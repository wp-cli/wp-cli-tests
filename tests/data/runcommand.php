<?php

/**
 * Test data for WPCliRuncommandDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use WP_CLI;
use function PHPStan\Testing\assertType;


$value = WP_CLI::runcommand( 'plugin list --format=json', [ 'return' => true ] );
assertType( 'string', $value );

$value = WP_CLI::runcommand( 'plugin list --format=json', [ 'return' => false ] );
assertType( 'null', $value );

$value = WP_CLI::runcommand( 'plugin list --format=json', [ 'return' => 'all' ] );
assertType( 'object{stdout: string, stderr: string, return_code: int}', $value );

$value = WP_CLI::runcommand( 'plugin list --format=json', [ 'return' => 'stdout' ] );
assertType( 'string', $value );

$value = WP_CLI::runcommand( 'plugin list --format=json', [ 'return' => 'stderr' ] );
assertType( 'string', $value );

$value = WP_CLI::runcommand( 'plugin list --format=json', [ 'return' => 'return_code' ] );
assertType( 'int', $value );

$value = WP_CLI::runcommand(
	'plugin list --format=json',
	[
		'return' => true,
		'parse'  => 'json',
	]
);
assertType( 'array|null', $value );

$value = WP_CLI::runcommand(
	'plugin list --format=json',
	[
		'return' => 'stdout',
		'parse'  => 'json',
	]
);
assertType( 'array|null', $value );

$value = WP_CLI::runcommand(
	'plugin list --format=json',
	[
		'return'     => 'stdout',
		'exit_error' => true,
	]
);
assertType( 'string', $value );

$value = WP_CLI::runcommand(
	'plugin list --format=json',
	[
		'return'     => 'stdout',
		'exit_error' => false,
	]
);
assertType( 'string', $value );
