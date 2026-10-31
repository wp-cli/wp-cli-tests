<?php
/**
 * Generate a list of tags to skip during the test run.
 *
 * Require a minimum version of WordPress:
 *
 *   @require-wp-4.0
 *   Scenario: Core translation CRUD
 *
 * Then use in bash script:
 *
 *   BEHAT_TAGS=$(php behat-tags.php)
 *   vendor/bin/behat --format progress $BEHAT_TAGS
 */

function version_tags(
	$prefix,
	$current,
	$operator = '<',
	$features_folder = 'features'
) {
	if ( ! $current ) {
		return array();
	}

	$existing_tags = array();
	$feature_files = glob( $features_folder . DIRECTORY_SEPARATOR . '*.feature' );
	if ( ! empty( $feature_files ) ) {
		foreach ( $feature_files as $feature_file ) {
			$contents = (string) file_get_contents( $feature_file );
			if ( preg_match_all( '/@' . $prefix . '-[0-9\.]+/', $contents, $matches ) ) {
				$existing_tags = array_merge( $existing_tags, $matches[0] );
			}
		}
		$existing_tags = array_unique( $existing_tags );
	}

	$skip_tags = array();

	foreach ( $existing_tags as $tag ) {
		$compare = str_replace( "@{$prefix}-", '', $tag );
		if ( version_compare( $current, $compare, $operator ) ) {
			$skip_tags[] = $tag;
		}
	}

	return $skip_tags;
}

function get_db_type_and_version() {
	// Detect which client binary is available.
	$client_binary = null;
	$output        = array();
	exec( 'command -v mysql 2>/dev/null', $output, $mysql_exit_code );
	if ( 0 === $mysql_exit_code ) {
		$client_binary = 'mysql';
	} else {
		$output = array();
		exec( 'command -v mariadb 2>/dev/null', $output, $mariadb_exit_code );
		if ( 0 === $mariadb_exit_code ) {
			$client_binary = 'mariadb';
		}
	}

	if ( null === $client_binary ) {
		// No client binary found, return defaults.
		return array(
			'type'    => 'mysql',
			'version' => '',
		);
	}

	// Build connection parameters from environment variables.
	$host = getenv( 'WP_CLI_TEST_DBHOST' ) ?: 'localhost';
	$user = getenv( 'WP_CLI_TEST_DBROOTUSER' ) ?: 'root';
	$pass = getenv( 'WP_CLI_TEST_DBROOTPASS' );

	// Build the command to get the server version.
	$host_parts = explode( ':', $host );
	$host_arg   = '-h' . escapeshellarg( $host_parts[0] );

	$port_arg = '';
	if ( isset( $host_parts[1] ) ) {
		// Check if it's a port number or socket path.
		if ( is_numeric( $host_parts[1] ) ) {
			$port_arg = ' --port=' . escapeshellarg( $host_parts[1] ) . ' --protocol=tcp';
		} else {
			$port_arg = ' --socket=' . escapeshellarg( $host_parts[1] ) . ' --protocol=socket';
		}
	}

	$pass_arg = false !== $pass && '' !== $pass ? '-p' . escapeshellarg( $pass ) : '';

	$cmd = sprintf(
		'%s %s %s -u%s %s -e "SELECT VERSION()" --skip-column-names 2>/dev/null',
		escapeshellcmd( $client_binary ),
		$host_arg,
		$port_arg,
		escapeshellarg( $user ),
		$pass_arg
	);

	$output      = array();
	$return_code = 0;
	exec( $cmd, $output, $return_code );
	$version_string = isset( $output[0] ) ? $output[0] : '';

	// If the connection failed, fall back to client binary version.
	if ( 0 !== $return_code || empty( $version_string ) ) {
		$client_version_cmd = sprintf( '%s --version 2>/dev/null', escapeshellcmd( $client_binary ) );
		$version_string     = exec( $client_version_cmd );
	}

	// Detect database type from server version string.
	$db_type = 'mysql';
	if ( false !== stripos( $version_string, 'mariadb' ) ) {
		$db_type = 'mariadb';
	}

	preg_match( '@[0-9]+\.[0-9]+\.[0-9]+@', $version_string, $version );
	$db_version = isset( $version[0] ) ? $version[0] : '';

	return array(
		'type'    => $db_type,
		'version' => $db_version,
	);
}

function get_db_version() {
	$db_info = get_db_type_and_version();
	return $db_info['version'];
}

/**
 * Whether any feature file uses the given tag.
 *
 * Tags that no scenario carries do not need to be filtered out, and leaving them
 * out keeps the filter to what the suite being run actually uses.
 *
 * @param string $tag
 * @param string $features_folder
 * @return bool
 */
function tag_in_use( $tag, $features_folder = 'features' ) {
	$feature_files = glob( $features_folder . DIRECTORY_SEPARATOR . '*.feature' );

	if ( empty( $feature_files ) ) {
		return false;
	}

	foreach ( $feature_files as $feature_file ) {
		if ( false !== strpos( (string) file_get_contents( $feature_file ), $tag ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the database server can be reached through a socket.
 *
 * A database server running in a container is only reachable over TCP, so the
 * scenarios that connect through a socket cannot run against one. The locations
 * checked here are the same ones those scenarios look in.
 *
 * @return bool
 */
function has_mysql_socket() {
	$socket = getenv( 'WP_CLI_TEST_DBSOCKET' );

	if ( is_string( $socket ) && '' !== $socket ) {
		return file_exists( $socket );
	}

	// Anything but a port number after the colon in the host is a socket path.
	$host = getenv( 'WP_CLI_TEST_DBHOST' );

	if ( is_string( $host ) && false !== strpos( $host, ':' ) ) {
		$after_colon = substr( $host, strrpos( $host, ':' ) + 1 );

		if ( '' !== $after_colon && ! is_numeric( $after_colon ) ) {
			return file_exists( $after_colon );
		}
	}

	foreach ( array( '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock' ) as $location ) {
		if ( file_exists( $location ) ) {
			return true;
		}
	}

	return false;
}

$features_folder = getenv( 'BEHAT_FEATURES_FOLDER' ) ?: 'features';
$wp_version      = getenv( 'WP_VERSION' );
$wp_version_reqs = array();
// Only apply @require-wp tags when WP_VERSION isn't 'latest', 'nightly' or 'trunk'.
// 'latest', 'nightly' and 'trunk' are expected to work with all features.
if ( $wp_version &&
	! in_array( $wp_version, array( 'latest', 'nightly', 'trunk' ), true ) ) {
	$wp_version_reqs = array_merge(
		version_tags( 'require-wp', $wp_version, '<', $features_folder ),
		version_tags( 'less-than-wp', $wp_version, '>=', $features_folder )
	);
} else {
	// But make sure @less-than-wp tags always exist for those special cases. (Note: @less-than-wp-latest etc won't work and shouldn't be used).
	$wp_version_reqs = array_merge(
		$wp_version_reqs,
		version_tags( 'less-than-wp', '9999', '>=', $features_folder )
	);
}

$skip_tags = array_merge(
	$wp_version_reqs,
	version_tags( 'require-php', PHP_VERSION, '<', $features_folder ),
	// Note: this was '>' prior to WP-CLI 1.5.0 but the change is unlikely to
	// cause BC issues as usually compared against major.minor only.
	version_tags( 'less-than-php', PHP_VERSION, '>=', $features_folder )
);

// Skip GitHub API tests if `GITHUB_TOKEN` not available because of rate
// limiting. See https://github.com/wp-cli/wp-cli/issues/1612
if ( ! getenv( 'GITHUB_TOKEN' ) ) {
	$skip_tags[] = '@github-api';
}
# Skip tests known to be broken.
$skip_tags[] = '@broken';

if ( 'sqlite' !== getenv( 'WP_CLI_TEST_OBJECT_CACHE' ) ) {
	$skip_tags[] = '@require-object-cache';
} else {
	$skip_tags[] = '@skip-object-cache';
}

if ( $wp_version && in_array( $wp_version, array( 'nightly', 'trunk' ), true ) ) {
	$skip_tags[] = '@broken-trunk';
}

// Some scenarios need a version of WordPress that WordPress.org knows about, such
// as the ones verifying an installation against the published checksums. Neither a
// development build nor an archive supplied through WP_CLI_TEST_CORE_ZIP qualifies.
$core_zip = getenv( 'WP_CLI_TEST_CORE_ZIP' );
if (
	tag_in_use( '@require-wp-stable', $features_folder )
	&& (
		( false !== $core_zip && '' !== $core_zip )
		|| in_array( $wp_version, array( 'nightly', 'trunk' ), true )
	)
) {
	$skip_tags[] = '@require-wp-stable';
}

if ( tag_in_use( '@require-mysql-socket', $features_folder ) && ! has_mysql_socket() ) {
	$skip_tags[] = '@require-mysql-socket';
}

$db_info    = get_db_type_and_version();
$db_version = $db_info['version'];
// Use detected database type from server, unless WP_CLI_TEST_DBTYPE is 'sqlite'.
$env_db_type = getenv( 'WP_CLI_TEST_DBTYPE' );
$db_type     = 'sqlite' === $env_db_type ? 'sqlite' : $db_info['type'];

switch ( $db_type ) {
	case 'mariadb':
		$db_version = get_db_version();
		$skip_tags  = array_merge(
			$skip_tags,
			[ '@require-mysql', '@require-sqlite', '@skip-mariadb' ],
			version_tags( 'require-mariadb', $db_version, '<', $features_folder ),
			version_tags( 'less-than-mariadb', $db_version, '>=', $features_folder )
		);
		break;
	case 'sqlite':
		$skip_tags[] = '@require-mariadb';
		$skip_tags[] = '@require-mysql';
		$skip_tags[] = '@require-mysql-or-mariadb';
		$skip_tags[] = '@skip-sqlite';
		break;
	case 'mysql':
	default:
		$db_version = get_db_version();
		$skip_tags  = array_merge(
			$skip_tags,
			[ '@require-mariadb', '@require-sqlite', '@skip-mysql' ],
			version_tags( 'require-mysql', $db_version, '<', $features_folder ),
			version_tags( 'less-than-mysql', $db_version, '>=', $features_folder )
		);
		break;
}

# Require PHP extension, eg 'imagick'.
function extension_tags( $features_folder = 'features' ) {
	$extension_tags = array();
	$feature_files  = glob( $features_folder . DIRECTORY_SEPARATOR . '*.feature' );
	if ( ! empty( $feature_files ) ) {
		foreach ( $feature_files as $feature_file ) {
			$contents = (string) file_get_contents( $feature_file );
			if ( preg_match_all( '/@require-extension-[A-Za-z_]*/', $contents, $matches ) ) {
				$extension_tags = array_merge( $extension_tags, $matches[0] );
			}
		}
		$extension_tags = array_unique( $extension_tags );
	}

	$skip_tags = array();

	$substr_start = strlen( '@require-extension-' );
	foreach ( $extension_tags as $tag ) {
		$extension = substr( $tag, $substr_start );
		if ( ! extension_loaded( $extension ) ) {
			$skip_tags[] = $tag;
		}
	}

	return $skip_tags;
}

/**
 * An array of tags for excluding tests based on the operating system.
 *
 * @param string $features_folder The folder where the feature files are located.
 * @return array
 */
function os_tags( $features_folder = 'features' ) {
	$os_tags       = array();
	$feature_files = glob( $features_folder . DIRECTORY_SEPARATOR . '*.feature' );
	if ( ! empty( $feature_files ) ) {
		foreach ( $feature_files as $feature_file ) {
			$contents = (string) file_get_contents( $feature_file );
			if ( preg_match_all( '/@(require-(windows|macos|linux)|skip-(windows|macos|linux))/', $contents, $matches ) ) {
				$os_tags = array_merge( $os_tags, $matches[0] );
			}
		}
		$os_tags = array_unique( $os_tags );
	}

	if ( empty( $os_tags ) ) {
		return array();
	}

	$skip_tags = array();

	$is_windows = 'Windows' === PHP_OS_FAMILY;
	$is_macos   = 'Darwin' === PHP_OS_FAMILY;
	$is_linux   = 'Linux' === PHP_OS_FAMILY;

	foreach ( $os_tags as $tag ) {
		switch ( $tag ) {
			case '@require-windows':
				if ( ! $is_windows ) {
					$skip_tags[] = $tag;
				}
				break;
			case '@require-macos':
				if ( ! $is_macos ) {
					$skip_tags[] = $tag;
				}
				break;
			case '@require-linux':
				if ( ! $is_linux ) {
					$skip_tags[] = $tag;
				}
				break;
			case '@skip-windows':
				if ( $is_windows ) {
					$skip_tags[] = $tag;
				}
				break;
			case '@skip-macos':
				if ( $is_macos ) {
					$skip_tags[] = $tag;
				}
				break;
			case '@skip-linux':
				if ( $is_linux ) {
					$skip_tags[] = $tag;
				}
				break;
		}
	}

	return $skip_tags;
}

/**
 * Get the scenarios of the feature files with the tags that apply to them.
 *
 * @param string $features_folder The folder where the feature files are located.
 * @return array<array{path: string, tags: array<string>}>
 */
function scenarios_with_tags( $features_folder = 'features' ) {
	$scenarios     = array();
	$feature_files = glob( $features_folder . DIRECTORY_SEPARATOR . '*.feature' );

	foreach ( $feature_files ? $feature_files : array() as $feature_file ) {
		$lines        = file( $feature_file, FILE_IGNORE_NEW_LINES );
		$feature_tags = array();
		$pending_tags = array();
		$doc_string   = null;

		foreach ( $lines ? $lines : array() as $index => $line ) {
			$line = trim( $line );

			// Doc strings can hold whole feature files, as written by scenarios for scaffolding.
			if ( null !== $doc_string ) {
				if ( 0 === strpos( $line, $doc_string ) ) {
					$doc_string = null;
				}
				continue;
			}
			if ( 0 === strpos( $line, '"""' ) || 0 === strpos( $line, '```' ) ) {
				$doc_string = substr( $line, 0, 3 );
				continue;
			}

			if ( 0 === strpos( $line, '@' ) ) {
				preg_match_all( '/@[^\s@]+/', $line, $matches );
				$pending_tags = array_merge( $pending_tags, $matches[0] );
			} elseif ( preg_match( '/^Feature:/', $line ) ) {
				$feature_tags = $pending_tags;
				$pending_tags = array();
			} elseif ( preg_match( '/^(Scenario|Scenario Outline|Scenario Template|Example):/', $line ) ) {
				$scenarios[]  = array(
					'path' => $feature_file . ':' . ( $index + 1 ),
					'tags' => array_merge( $feature_tags, $pending_tags ),
				);
				$pending_tags = array();
			} elseif ( '' !== $line && 0 !== strpos( $line, '#' ) ) {
				$pending_tags = array();
			}
		}
	}

	return $scenarios;
}

/**
 * Describe why scenarios carrying a skip tag are not run.
 *
 * @param string $tag     The tag, without the leading tilde.
 * @param array  $context What the environment provides: wp, php, db and os.
 * @return array{0: bool, 1: string} Whether the environment lacks something, and the reason.
 */
function describe_skip_tag( $tag, $context ) {
	if ( preg_match( '/^@require-extension-(.+)$/', $tag, $matches ) ) {
		return array( true, "the PHP extension \"{$matches[1]}\" is not loaded" );
	}

	if ( '@require-mysql-socket' === $tag ) {
		return array( true, 'the database server cannot be reached through a socket' );
	}

	if ( preg_match( '/^@(require|less-than)-wp-/', $tag ) || '@require-wp-stable' === $tag || '@broken-trunk' === $tag ) {
		return array( false, "WordPress {$context['wp']} is used" );
	}

	if ( preg_match( '/^@(require|less-than)-php-/', $tag ) ) {
		return array( false, "PHP {$context['php']} is used" );
	}

	if ( preg_match( '/^@(require|less-than|skip)-(mysql|mariadb|sqlite)/', $tag ) ) {
		return array( false, "the database is {$context['db']}" );
	}

	if ( preg_match( '/^@(require|skip)-(windows|macos|linux)$/', $tag ) ) {
		return array( false, "the operating system is {$context['os']}" );
	}

	if ( '@github-api' === $tag ) {
		return array( false, 'GITHUB_TOKEN is not set' );
	}

	if ( '@require-object-cache' === $tag || '@skip-object-cache' === $tag ) {
		return array( false, 'WP_CLI_TEST_OBJECT_CACHE is ' . ( getenv( 'WP_CLI_TEST_OBJECT_CACHE' ) ? 'set' : 'not set' ) );
	}

	if ( '@broken' === $tag ) {
		return array( false, 'they are known to be broken' );
	}

	return array( false, 'it is excluded' );
}

/**
 * Report the scenarios that the tag filter leaves out, and why.
 *
 * Scenarios that the environment cannot run are listed separately from the ones that are
 * not part of the configuration being tested, such as another database or WordPress version.
 *
 * @param array<string> $skip_tags
 * @param string        $features_folder
 * @param array         $context
 * @return int Number of scenarios left out because the environment lacks something.
 */
function report_filtered_scenarios( $skip_tags, $features_folder, $context ) {
	$scenarios = scenarios_with_tags( $features_folder );
	$groups    = array();

	foreach ( $scenarios as $scenario ) {
		$matching = array_values( array_intersect( $skip_tags, $scenario['tags'] ) );
		if ( $matching ) {
			$groups[ $matching[0] ][] = $scenario['path'];
		}
	}

	if ( ! $groups ) {
		return 0;
	}

	$sections = array(
		true  => array(),
		false => array(),
	);
	$missing  = 0;
	$total    = 0;

	foreach ( $groups as $tag => $paths ) {
		list( $lacks, $reason ) = describe_skip_tag( $tag, $context );
		$sections[ $lacks ][]   = array( $tag, $reason, $paths );
		$total                 += count( $paths );
		if ( $lacks ) {
			$missing += count( $paths );
		}
	}

	$titles = array(
		true  => 'Not available in this environment',
		false => 'Not part of this configuration',
	);

	$text     = sprintf( "Not running %d of %d scenarios:\n", $total, count( $scenarios ) );
	$markdown = sprintf( "### Behat: %d of %d scenarios not run\n", $total, count( $scenarios ) );

	foreach ( array( true, false ) as $lacks ) {
		if ( ! $sections[ $lacks ] ) {
			continue;
		}
		$text     .= "  {$titles[ $lacks ]}:\n";
		$markdown .= "\n**{$titles[ $lacks ]}**\n\n";
		foreach ( $sections[ $lacks ] as $section ) {
			list( $tag, $reason, $paths ) = $section;

			$text     .= sprintf( "    %s, as %s: %s\n", $tag, $reason, implode( ', ', $paths ) );
			$markdown .= sprintf(
				"- `%s`, as %s (%d): %s\n",
				$tag,
				$reason,
				count( $paths ),
				implode(
					', ',
					array_map(
						function ( $path ) {
							return "`{$path}`";
						},
						$paths
					)
				)
			);
		}
	}

	fwrite( STDERR, $text );

	// Add the report to the summary of a GitHub Actions job, once per run.
	$summary = getenv( 'GITHUB_STEP_SUMMARY' );
	if ( $summary && ! getenv( 'WP_CLI_TEST_DEBUG_BEHAT_ENV' ) ) {
		file_put_contents( $summary, $markdown . "\n", FILE_APPEND );
	}

	return $missing;
}

$skip_tags = array_merge( $skip_tags, extension_tags( $features_folder ) );
$skip_tags = array_merge( $skip_tags, os_tags( $features_folder ) );

$missing = report_filtered_scenarios(
	$skip_tags,
	$features_folder,
	array(
		'wp'  => $wp_version ? $wp_version : 'latest',
		'php' => PHP_VERSION,
		'db'  => trim( ( 'sqlite' === $db_type ? 'SQLite' : ( 'mariadb' === $db_type ? 'MariaDB' : 'MySQL' ) ) . ' ' . ( 'sqlite' === $db_type ? '' : $db_version ) ),
		'os'  => PHP_OS_FAMILY,
	)
);

// With strict requirements, everything the scenarios need has to be available.
if ( $missing && getenv( 'WP_CLI_TEST_STRICT_REQUIREMENTS' ) ) {
	fwrite( STDERR, "Stopping, as WP_CLI_TEST_STRICT_REQUIREMENTS is set and {$missing} scenarios cannot run in this environment.\n" );
	exit( 1 );
}

if ( ! empty( $skip_tags ) ) {
	echo '--tags=~' . implode( '&&~', $skip_tags );
}
