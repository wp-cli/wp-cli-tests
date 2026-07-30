<?php

/**
 * Test data for WPCliGetConfigDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use WP_CLI;
use function PHPStan\Testing\assertType;

// No arguments
assertType( 'array{path: string|null, ssh: string|null, ssh-args: array<int, string>, http: string|null, url: string|null, user: string|null, skip-plugins: array<int, string>|true, skip-themes: array<int, string>|true, skip-packages: bool, require: array<int, string>, exec: array<int, string>, context: string, debug: string|true, prompt: string|false, quiet: bool, apache_modules: array<int, string>, assume-https: bool, color: bool|string, disabled_commands: array<int, string>, locale: string, allow-root: bool, alias: string}', WP_CLI::get_config() );
assertType( 'array{path: string|null, ssh: string|null, ssh-args: array<int, string>, http: string|null, url: string|null, user: string|null, skip-plugins: array<int, string>|true, skip-themes: array<int, string>|true, skip-packages: bool, require: array<int, string>, exec: array<int, string>, context: string, debug: string|true, prompt: string|false, quiet: bool, apache_modules: array<int, string>, assume-https: bool, color: bool|string, disabled_commands: array<int, string>, locale: string, allow-root: bool, alias: string}', WP_CLI::get_config( null ) );

// Specific keys
assertType( 'string|null', WP_CLI::get_config( 'path' ) );
assertType( 'array<int, string>', WP_CLI::get_config( 'ssh-args' ) );
assertType( 'bool', WP_CLI::get_config( 'skip-packages' ) );
assertType( 'array<int, string>|true', WP_CLI::get_config( 'skip-plugins' ) );
assertType( 'string|false', WP_CLI::get_config( 'prompt' ) );
assertType( 'bool', WP_CLI::get_config( 'quiet' ) );
assertType( 'bool', WP_CLI::get_config( 'assume-https' ) );

// Nullable and non-constant keys
/** @var string|null $nullable_key */
$nullable_key = null;
assertType( 'array<\'alias\'|\'allow-root\'|\'apache_modules\'|\'assume-https\'|\'color\'|\'context\'|\'debug\'|\'disabled_commands\'|\'exec\'|\'http\'|\'locale\'|\'path\'|\'prompt\'|\'quiet\'|\'require\'|\'skip-packages\'|\'skip-plugins\'|\'skip-themes\'|\'ssh\'|\'ssh-args\'|\'url\'|\'user\'|int, array<int, string>|bool|string|null>|bool|string|null', WP_CLI::get_config( $nullable_key ) );

/** @var string $string_key */
$string_key = 'path';
assertType( 'array<int, string>|bool|string|null', WP_CLI::get_config( $string_key ) );

// Invalid key
assertType( 'null', WP_CLI::get_config( 'invalid_key' ) );
