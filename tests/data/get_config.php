<?php

/**
 * Test data for WPCliGetConfigDynamicReturnTypeExtension.
 */

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use WP_CLI;
use function PHPStan\Testing\assertType;

// No arguments
assertType( 'array{path: string|null, ssh: string|null, ssh-args: array<int, string>, http: string|null, url: string|null, user: string|null, skip-plugins: array<int, string>|true, skip-themes: array<int, string>|true, skip-packages: bool, require: array<int, string>, exec: array<int, string>, context: string, debug: bool|string, prompt: bool|string, quiet: bool, apache_modules: array<int, string>, assume-https: bool, color: bool|string, disabled_commands: array<int, string>, locale: string, allow-root: bool, alias: string}', WP_CLI::get_config() );

// Specific keys
assertType( 'string|null', WP_CLI::get_config( 'path' ) );
assertType( 'array<int, string>', WP_CLI::get_config( 'ssh-args' ) );
assertType( 'bool', WP_CLI::get_config( 'skip-packages' ) );
assertType( 'array<int, string>|true', WP_CLI::get_config( 'skip-plugins' ) );
assertType( 'bool|string', WP_CLI::get_config( 'prompt' ) );
assertType( 'bool', WP_CLI::get_config( 'quiet' ) );
assertType( 'bool', WP_CLI::get_config( 'assume-https' ) );

// Invalid key
assertType( 'null', WP_CLI::get_config( 'invalid_key' ) );
