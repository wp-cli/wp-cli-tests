<?php
define( 'WP_CLI_TESTS_ROOT', dirname( __DIR__ ) );
define( 'VENDOR_DIR',
	file_exists( WP_CLI_TESTS_ROOT . '/vendor/autoload.php' )
		? WP_CLI_TESTS_ROOT . '/vendor'
		: WP_CLI_TESTS_ROOT . '/../..'
);
define( 'WP_CLI_ROOT', VENDOR_DIR . '/wp-cli/wp-cli' );
define( 'PACKAGE_ROOT', VENDOR_DIR . '/..' );

/**
 * Compatibility with PHPUnit 6+
 */
if ( class_exists( 'PHPUnit\Runner\Version' ) ) {
	require_once __DIR__ . '/phpunit6-compat.php';
}

require_once VENDOR_DIR . '/autoload.php';
require_once WP_CLI_ROOT . '/php/utils.php';

$config_filenames = array(
	'phpunit.xml',
	'.phpunit.xml',
	'phpunit.xml.dist',
	'.phpunit.xml.dist',
);

$config_filename = false;
foreach ( $config_filenames as $filename ) {
	if ( file_exists( PACKAGE_ROOT . '/' . $filename ) ) {
		$config_filename = PACKAGE_ROOT . '/' . $filename;
	}
}

if ( $config_filename ) {
	$config  = file_get_contents( $config_filename );
	$matches = null;
	$pattern = '/bootstrap="(?P<bootstrap>.*)"/';
	$result  = preg_match( $pattern, $config, $matches );
	if ( isset( $matches['bootstrap'] ) && file_exists( $matches['bootstrap'] ) ) {
		include_once PACKAGE_ROOT . '/' . $matches['bootstrap'];
	}
}
