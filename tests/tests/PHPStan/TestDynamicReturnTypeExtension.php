<?php

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

class TestDynamicReturnTypeExtension extends \PHPStan\Testing\TypeInferenceTestCase {

	/**
	 * @return iterable<mixed>
	 */
	public function dataFileAsserts(): iterable {
		// Path to a file with actual asserts of expected types:
		yield from self::gatherAssertTypes( dirname( __DIR__, 2 ) . '/data/parse_url.php' );
	}

	/**
	 * @dataProvider dataFileAsserts
	 * @param array<string> ...$args
	 */
	public function testFileAsserts( string $assertType, string $file, ...$args ): void {
		$this->assertFileAsserts( $assertType, $file, ...$args );
	}

	public static function getAdditionalConfigFiles(): array {
		return [ dirname( __DIR__, 3 ) . '/extension.neon' ];
	}
}
