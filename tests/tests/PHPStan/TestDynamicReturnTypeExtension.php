<?php

declare(strict_types=1);

namespace WP_CLI\Tests\Tests\PHPStan;

use PHPUnit\Framework\Attributes\DataProvider;

class TestDynamicReturnTypeExtension extends \PHPStan\Testing\TypeInferenceTestCase {

	/**
	 * @return iterable<mixed>
	 */
	public static function dataFileAsserts(): iterable {
		// Path to a file with actual asserts of expected types:
		yield from self::gatherAssertTypes( dirname( __DIR__, 2 ) . '/data/parse_url.php' );
		yield from self::gatherAssertTypes( dirname( __DIR__, 2 ) . '/data/get_flag_value.php' );
		yield from self::gatherAssertTypes( dirname( __DIR__, 2 ) . '/data/runcommand.php' );
	}

	/**
	 * @dataProvider dataFileAsserts
	 * @param array<string> ...$args
	 */
	#[DataProvider( 'dataFileAsserts' )] // phpcs:ignore PHPCompatibility.Attributes.NewAttributes.PHPUnitAttributeFound
	public function testFileAsserts( string $assertType, string $file, ...$args ): void {
		$this->assertFileAsserts( $assertType, $file, ...$args );
	}

	public static function getAdditionalConfigFiles(): array {
		return [ dirname( __DIR__, 3 ) . '/extension.neon' ];
	}
}
