<?php

/**
 * Test data for WPCliGetConfigDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use WP_CLI;
use function PHPStan\Testing\assertType;

// No arguments
assertType( 'array{path: string|null, ssh: string|null, ssh-args: array<string>, http: string|null, url: string|null, user: string|null, skip-plugins: array<string>|true, skip-themes: array<string>|true, skip-packages: bool, require: array<string>, exec: array<string>, context: string, debug: bool|string, prompt: bool|string, quiet: bool, apache_modules: array<string>, assume-https: bool, color: bool|string, disabled_commands: array<string>, locale: string, allow-root: bool, alias: string}', WP_CLI::get_config() );
assertType( 'array{path: string|null, ssh: string|null, ssh-args: array<string>, http: string|null, url: string|null, user: string|null, skip-plugins: array<string>|true, skip-themes: array<string>|true, skip-packages: bool, require: array<string>, exec: array<string>, context: string, debug: bool|string, prompt: bool|string, quiet: bool, apache_modules: array<string>, assume-https: bool, color: bool|string, disabled_commands: array<string>, locale: string, allow-root: bool, alias: string}', WP_CLI::get_config( null ) );

// Specific keys
assertType( 'string|null', WP_CLI::get_config( 'path' ) );
assertType( 'array<string>', WP_CLI::get_config( 'ssh-args' ) );
assertType( 'bool', WP_CLI::get_config( 'skip-packages' ) );
assertType( 'array<string>|true', WP_CLI::get_config( 'skip-plugins' ) );
assertType( 'bool|string', WP_CLI::get_config( 'prompt' ) );
assertType( 'bool', WP_CLI::get_config( 'quiet' ) );
assertType( 'bool', WP_CLI::get_config( 'assume-https' ) );

// Nullable and non-constant keys
/** @var string|null $nullable_key */
$nullable_key = null;
assertType( 'array<array<string>|bool|string|null>|bool|string|null', WP_CLI::get_config( $nullable_key ) );

/** @var string $string_key */
$string_key = 'path';
assertType( 'array<string>|bool|string|null', WP_CLI::get_config( $string_key ) );

// Invalid key
assertType( 'null', WP_CLI::get_config( 'invalid_key' ) );
