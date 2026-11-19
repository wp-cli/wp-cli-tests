<?php

namespace WP_CLI\Tests\Tests;

use WP_CLI\Tests\Context\FeatureContext;
use WP_CLI\Tests\TestCase;

class TestLegacyAnnotations extends TestCase {

	public function test_annotations_match_the_attributes(): void {
		if ( PHP_VERSION_ID < 80100 || ! class_exists( 'Behat\Hook\BeforeSuite' ) ) {
			$this->markTestSkipped( 'Reading the attributes needs PHP 8.1 and a Behat that has all of them.' );
		}

		require_once dirname( __DIR__, 2 ) . '/utils/generate-legacy-annotations.php';

		foreach ( wp_cli_tests_legacy_files() as $file => $contents ) {
			$this->assertFileExists( $file );
			$this->assertSame(
				$contents,
				file_get_contents( $file ),
				basename( $file ) . ' is out of date, run `php utils/generate-legacy-annotations.php`.'
			);
		}
	}

	/**
	 * Behat reads the attributes of the method an override replaces, from 3.18 on. That only finds
	 * them as long as no interface declares the method where the attributes are read.
	 */
	public function test_overrides_keep_their_definitions(): void {
		if ( PHP_VERSION_ID < 80100 || ! class_exists( 'Behat\Hook\BeforeSuite' ) ) {
			$this->markTestSkipped( 'Reading the attributes needs PHP 8.1 and a Behat that has all of them.' );
		}

		require_once dirname( __DIR__ ) . '/data/overriding_context.php';

		foreach ( [ 'beforeScenario', 'given_an_empty_directory' ] as $name ) {
			$method = new \ReflectionMethod( 'WP_CLI\Tests\Tests\Overriding_Context', $name );

			$this->assertCount( 0, $method->getAttributes() );
			$this->assertSame( FeatureContext::class, $method->getPrototype()->getDeclaringClass()->getName() );
			$this->assertCount( 1, $method->getPrototype()->getAttributes() );
		}
	}

	/**
	 * PHP 7 reads an attribute as a comment, which ends with the line, or with a closing PHP tag.
	 */
	public function test_attributes_are_comments_to_php_7(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Context/*.php' );
		$found = 0;

		$this->assertNotEmpty( $files );

		foreach ( (array) $files as $file ) {
			foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $number => $line ) {
				$line = trim( (string) $line );

				if ( 0 !== strpos( $line, '#[' ) ) {
					continue;
				}

				++$found;
				$where = basename( $file ) . ':' . ( $number + 1 );

				$this->assertSame( ']', substr( $line, -1 ), "{$where} has an attribute spanning several lines." );
				$this->assertStringNotContainsString( '?>', $line, "{$where} has an attribute holding a closing PHP tag." );
			}
		}

		$this->assertGreaterThan( 0, $found );
	}
}
