<?php
/**
 * Flag scenarios that run external tools without declaring them.
 *
 * A scenario that runs a tool that is not always installed has to carry the matching
 * `@require-bin-<name>` tag, so that environments without the tool report the scenario
 * as not run instead of failing it halfway through.
 *
 * This only reports, so that existing feature files can be tagged over time.
 *
 * Usage: php check-required-tools.php [<features folder or file>...]
 */

/**
 * Tools that are not part of every environment the tests run in.
 *
 * Common ones like git, curl or the database client are assumed to be present.
 */
const WP_CLI_TESTS_OPTIONAL_TOOLS = [ 'convert', 'ex', 'gs', 'identify', 'jq', 'nano', 'phpunit', 'sqlite3', 'svn', 'tar', 'unzip', 'vi', 'vim', 'zip', 'zipinfo' ];

/**
 * Get the tools that a command line runs.
 *
 * @param string $command_line
 * @return array<string>
 */
function wp_cli_tests_tools_in_command( $command_line ) {
	$tools = [];

	foreach ( preg_split( '/\|\||&&|;|\||\$\(/', $command_line ) as $part ) {
		// Commands can be set as editors through environment variables.
		if ( preg_match_all( '/\b(?:EDITOR|VISUAL)=["\']?([A-Za-z0-9_.+-]+)/', $part, $matches ) ) {
			$tools = array_merge( $tools, $matches[1] );
		}

		$part = (string) preg_replace( '/^\s*(?:[A-Z_][A-Z0-9_]*=(?:"[^"]*"|\'[^\']*\'|\S+)\s+)*/', '', $part );
		if ( preg_match( '/^([A-Za-z0-9_.+-]+)/', $part, $matches ) ) {
			$tools[] = $matches[1];
		}
	}

	return array_values( array_intersect( array_unique( $tools ), WP_CLI_TESTS_OPTIONAL_TOOLS ) );
}

/**
 * Check one feature file.
 *
 * @param string $file
 * @return array<string> Problems found, one per scenario and tool.
 */
function wp_cli_tests_check_feature( $file ) {
	$problems     = [];
	$feature_tags = [];
	$pending_tags = [];
	$background   = [];
	$current      = null;
	$doc_string   = null;
	$scenarios    = [];

	foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $index => $line ) {
		$line = trim( (string) $line );

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
			$pending_tags = [];
		} elseif ( preg_match( '/^Background:/', $line ) ) {
			$current = 'background';
		} elseif ( preg_match( '/^(Scenario|Scenario Outline|Scenario Template|Example):/', $line ) ) {
			$scenarios[]  = [
				'line'  => $index + 1,
				'tags'  => array_merge( $feature_tags, $pending_tags ),
				'tools' => [],
			];
			$current      = count( $scenarios ) - 1;
			$pending_tags = [];
		} elseif ( preg_match( '/^(?:Given|When|Then|And|But) I (?:run|try|launch in the background) `([^`]+)`/', $line, $matches ) ) {
			$tools = wp_cli_tests_tools_in_command( $matches[1] );
			if ( 'background' === $current ) {
				$background = array_merge( $background, $tools );
			} elseif ( null !== $current ) {
				$scenarios[ $current ]['tools'] = array_merge( $scenarios[ $current ]['tools'], $tools );
			}
		}
	}

	foreach ( $scenarios as $scenario ) {
		foreach ( array_unique( array_merge( $background, $scenario['tools'] ) ) as $tool ) {
			if ( ! in_array( "@require-bin-{$tool}", $scenario['tags'], true ) ) {
				$problems[] = "{$file}:{$scenario['line']} runs `{$tool}` without the @require-bin-{$tool} tag.";
			}
		}
	}

	return $problems;
}

$paths = array_slice( $argv, 1 );
if ( ! $paths ) {
	$paths = [ 'features' ];
}

$files = [];
foreach ( $paths as $path ) {
	if ( is_dir( $path ) ) {
		$files = array_merge( $files, (array) glob( rtrim( $path, '/\\' ) . '/*.feature' ) );
	} elseif ( is_file( $path ) ) {
		$files[] = $path;
	}
}

$problems = [];
foreach ( $files as $file ) {
	$problems = array_merge( $problems, wp_cli_tests_check_feature( (string) $file ) );
}

foreach ( $problems as $problem ) {
	echo "Warning: {$problem}\n";
}
