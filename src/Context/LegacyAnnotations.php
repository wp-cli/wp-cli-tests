<?php

namespace WP_CLI\Tests\Context;

/*
 * The steps and hooks of the contexts are declared with attributes, the only form Behat 4 reads.
 * Where Behat cannot read those, it gets the same declarations as annotations, which Behat 3 also
 * reads from the interface a method implements. Never both, as every step would be defined twice.
 */
if ( PHP_VERSION_ID < 80000 ) {
	// PHP 7 has no attributes.
	require __DIR__ . '/legacy/annotations.php';
} elseif ( ! class_exists( 'Behat\Hook\BeforeSuite' ) ) {
	// Behat 3.15, the last release for PHP 8.0, has no attributes for the suite hooks yet.
	require __DIR__ . '/legacy/suite-hook-annotations.php';
} else {
	interface LegacyAnnotations {}
}
