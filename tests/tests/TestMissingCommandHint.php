<?php

namespace WP_CLI\Tests\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WP_CLI\Tests\Context\Support;
use WP_CLI\Tests\TestCase;

class TestMissingCommandHint extends TestCase {

	/**
	 * @return string
	 */
	private function hint( string $stderr ) {
		$context = new class() {
			use Support;

			/**
			 * @var \WP_CLI\ProcessRun|null
			 */
			public $result = null;

			public function hint( string $stderr ): string {
				return $this->missing_command_hint( $stderr );
			}
		};

		return $context->hint( $stderr );
	}

	/**
	 * @dataProvider data_missing_commands
	 */
	#[DataProvider( 'data_missing_commands' )] // phpcs:ignore PHPCompatibility.Attributes.NewAttributes.PHPUnitAttributeFound
	public function test_missing_commands_are_named( string $stderr, string $command ): void {
		$this->assertStringContainsString( "The command `{$command}` was not found. If the scenario needs it, tag it with @require-bin-{$command}", $this->hint( $stderr ) );
	}

	/**
	 * @return array<array{string, string}>
	 */
	public static function data_missing_commands(): array {
		return [
			[ "sh: line 1: ex: command not found\n", 'ex' ],
			[ "sh: 1: ex: not found\n", 'ex' ],
			[ "bash: svn: command not found\n", 'svn' ],
			[ "'zipinfo' is not recognized as an internal or external command,\n", 'zipinfo' ],
		];
	}

	public function test_other_errors_get_no_hint(): void {
		$this->assertSame( '', $this->hint( "Error: not found\n" ) );
		$this->assertSame( '', $this->hint( "Error: Plugin: not found\n" ) );
		$this->assertSame( '', $this->hint( '' ) );
	}
}
