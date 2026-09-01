<?php
/**
 * Extracts PHP snippets from Behat .feature files into line-padded standalone PHP files,
 * and syncs PHPCBF fixes back into original .feature files.
 */

namespace WP_CLI\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/feature-php-blocks.php';

/**
 * Pattern matching the file names created during extraction.
 */
const EXTRACTED_FILE_PATTERN = '/^(.*\.feature)_L(\d+)_E(\d+)_(HASPHP|NOPHP)\.php$/';

/**
 * Turn a PHP block into the source of a standalone PHP file.
 *
 * The block is padded with one empty line per preceding line of the feature
 * file, so that the line numbers PHP_CodeSniffer reports are the line numbers
 * of the feature file. A block that does not bring its own opening tag is
 * given one on the line of the docstring delimiter, which is the line right
 * before the first line of code and therefore free.
 *
 * Unlike the analysis in `phpstan-feature-files.php`, an opening tag that the
 * block brings along stays where it is. A fix is written back into the feature
 * file, so the checked copy has to line up with the block it came from.
 *
 * @param array{start: int, lines: array<int, string>, has_php_tag: bool} $block Block to render.
 * @return string Source of the standalone PHP file.
 */
function render_fixable_block( array $block ) {
	$indent_length = strlen( (string) get_common_indent( $block['lines'] ) );

	$out_lines = [];
	for ( $i = 0; $i < $block['start'] + 1; $i++ ) {
		$out_lines[ $i ] = "\n";
	}

	if ( ! $block['has_php_tag'] ) {
		$out_lines[ $block['start'] ] = "<?php\n";
	}

	foreach ( $block['lines'] as $line_idx => $code_line ) {
		if ( '' === trim( $code_line ) ) {
			$out_lines[ $line_idx ] = "\n";
		} else {
			$out_lines[ $line_idx ] = substr( $code_line, $indent_length );
		}
	}

	return implode( '', $out_lines );
}

/**
 * Extract PHP blocks from a source directory of feature files to a target directory.
 *
 * @param string $source_dir Source directory containing .feature files.
 * @param string $target_dir Target directory to output extracted .php files.
 * @return bool Whether extraction completed successfully.
 */
function extract_feature_php( $source_dir, $target_dir ) {
	$source_dir = rtrim( str_replace( '\\', '/', $source_dir ), '/' );
	$target_dir = rtrim( str_replace( '\\', '/', $target_dir ), '/' );

	if ( ! is_dir( $source_dir ) ) {
		fwrite( STDERR, sprintf( 'Source directory "%s" does not exist.', $source_dir ) . PHP_EOL );
		return false;
	}

	if ( ! is_valid_target_dir( $target_dir, $source_dir ) ) {
		fwrite( STDERR, sprintf( 'Refusing to use "%s" as target directory.', $target_dir ) . PHP_EOL );
		return false;
	}

	remove_extracted_files( $target_dir, EXTRACTED_FILE_PATTERN );

	$success = true;

	foreach ( find_feature_files( $source_dir ) as $filepath ) {
		$relative = substr( $filepath, strlen( $source_dir ) + 1 );
		$lines    = file( $filepath );

		if ( false === $lines ) {
			fwrite( STDERR, sprintf( 'Could not read "%s".', $filepath ) . PHP_EOL );
			$success = false;
			continue;
		}

		$blocks = collect_blocks( $lines );

		if ( null === $blocks ) {
			fwrite( STDERR, sprintf( 'Unterminated docstring in "%s".', $filepath ) . PHP_EOL );
			$success = false;
			continue;
		}

		foreach ( $blocks as $block ) {
			// A docstring that merely opens with `<?php` is not necessarily a
			// PHP file, and a block is fixed in place here, so the step is what
			// decides. See is_php_file_step().
			if ( ! $block['from_step'] ) {
				continue;
			}

			$php_flag    = $block['has_php_tag'] ? 'HASPHP' : 'NOPHP';
			$target_file = $target_dir . '/' . $relative . '_L' . ( $block['start'] + 1 ) . '_E' . ( $block['end'] + 1 ) . '_' . $php_flag . '.php';

			$target_subdir = dirname( $target_file );
			if ( ! is_dir( $target_subdir ) && ! mkdir( $target_subdir, 0777, true ) && ! is_dir( $target_subdir ) ) {
				fwrite( STDERR, sprintf( 'Could not create directory "%s".', $target_subdir ) . PHP_EOL );
				$success = false;
				continue;
			}

			if ( false === file_put_contents( $target_file, render_fixable_block( $block ) ) ) {
				fwrite( STDERR, sprintf( 'Could not write "%s".', $target_file ) . PHP_EOL );
				$success = false;
			}
		}
	}

	return $success;
}

/**
 * Determine the indentation that extraction stripped from a PHP block.
 *
 * Extraction takes the indentation that all lines of a block share off each of
 * them, so putting a block back restores exactly that prefix. Deriving it from
 * the first line instead would make a block whose opening tag is indented
 * deeper than the code below it drift further to the right on every run.
 *
 * @param string[] $feature_lines   Lines of the feature file.
 * @param int      $code_start      Index of the first line of code.
 * @param int      $code_end        Index of the last line of code.
 * @param int      $docstring_start Index of the opening docstring delimiter.
 * @return string Indentation of the block.
 */
function get_block_indent( $feature_lines, $code_start, $code_end, $docstring_start ) {
	$code_lines = [];

	for ( $i = $code_start; $i <= $code_end; $i++ ) {
		if ( isset( $feature_lines[ $i ] ) ) {
			$code_lines[] = $feature_lines[ $i ];
		}
	}

	// An empty prefix is a valid answer, so only a block without any code at
	// all falls through to the guesses below.
	$indent = get_common_indent( $code_lines );

	if ( null !== $indent ) {
		return $indent;
	}

	if ( isset( $feature_lines[ $docstring_start ] ) ) {
		preg_match( '/^[ \t]*/', $feature_lines[ $docstring_start ], $m );
		return $m[0];
	}

	return '      ';
}

/**
 * Strip the padding that extraction added in front of a PHP block.
 *
 * Extraction pads the file with one empty line per preceding line of the
 * feature file, optionally followed by an added PHP opening tag. Everything
 * after that padding belongs to the block itself, including any blank lines.
 *
 * @param string[] $temp_lines  Lines of the extracted file.
 * @param int      $code_start  Index of the first line of code.
 * @param bool     $had_php_tag Whether the block already had a PHP opening tag.
 * @return string[]|null Lines of the block, or null if the padding is not intact.
 */
function strip_extraction_padding( $temp_lines, $code_start, $had_php_tag ) {
	if ( count( $temp_lines ) < $code_start ) {
		return null;
	}

	foreach ( array_slice( $temp_lines, 0, $code_start ) as $index => $line ) {
		$trimmed = trim( $line );

		if ( '' === $trimmed ) {
			continue;
		}

		// The opening tag added during extraction sits right before the code.
		if ( ! $had_php_tag && $index === $code_start - 1 && 0 === strpos( $trimmed, '<?php' ) ) {
			continue;
		}

		return null;
	}

	$code_lines = array_slice( $temp_lines, $code_start );

	return empty( $code_lines ) ? null : $code_lines;
}

/**
 * Sync fixed PHP blocks from temporary directory back into original feature files.
 *
 * @param string $source_dir Source directory containing .feature files.
 * @param string $target_dir Target directory containing fixed .php files.
 * @return bool Whether all blocks were synced successfully.
 */
function update_feature_php( $source_dir, $target_dir ) {
	$source_dir = rtrim( str_replace( '\\', '/', $source_dir ), '/' );
	$target_dir = rtrim( str_replace( '\\', '/', $target_dir ), '/' );

	if ( ! is_dir( $target_dir ) ) {
		fwrite( STDERR, sprintf( 'Target directory "%s" does not exist.', $target_dir ) . PHP_EOL );
		return false;
	}

	$directory = new RecursiveDirectoryIterator( $target_dir );
	$iterator  = new RecursiveIteratorIterator( $directory );

	$files_by_feature = [];

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$temp_filepath = str_replace( '\\', '/', $file->getPathname() );
			$temp_filename = $file->getFilename();

			if ( ! preg_match( EXTRACTED_FILE_PATTERN, $temp_filename, $matches ) ) {
				continue;
			}

			$sub_path         = substr( dirname( $temp_filepath ), strlen( $target_dir ) );
			$feature_rel_path = ( '' !== $sub_path ? $sub_path . '/' : '' ) . $matches[1];
			$feature_path     = $source_dir . '/' . ltrim( $feature_rel_path, '/' );

			$files_by_feature[ $feature_path ][] = [
				'temp_filepath'   => $temp_filepath,
				'docstring_start' => (int) $matches[2] - 1,
				'docstring_end'   => (int) $matches[3] - 1,
				'had_php_tag'     => 'HASPHP' === $matches[4],
			];
		}
	}

	$success = true;

	foreach ( $files_by_feature as $feature_path => $blocks ) {
		if ( ! file_exists( $feature_path ) ) {
			fwrite( STDERR, sprintf( 'Feature file "%s" does not exist.', $feature_path ) . PHP_EOL );
			$success = false;
			continue;
		}

		usort(
			$blocks,
			function ( $a, $b ) {
				return $b['docstring_start'] <=> $a['docstring_start'];
			}
		);

		$feature_lines = file( $feature_path );

		if ( false === $feature_lines ) {
			fwrite( STDERR, sprintf( 'Could not read "%s".', $feature_path ) . PHP_EOL );
			$success = false;
			continue;
		}

		foreach ( $blocks as $block ) {
			$docstring_start = $block['docstring_start'];
			$docstring_end   = $block['docstring_end'];
			$code_start      = $docstring_start + 1;
			$code_end        = $docstring_end - 1;
			$temp_lines      = file( $block['temp_filepath'] );

			if ( false === $temp_lines ) {
				fwrite( STDERR, sprintf( 'Could not read "%s".', $block['temp_filepath'] ) . PHP_EOL );
				$success = false;
				continue;
			}

			if (
				$code_start > $code_end
				|| ! isset( $feature_lines[ $docstring_start ] )
				|| ! isset( $feature_lines[ $docstring_end ] )
				|| ( 0 !== strpos( trim( $feature_lines[ $docstring_start ] ), '"""' ) && 0 !== strpos( trim( $feature_lines[ $docstring_start ] ), "'''" ) )
				|| ( 0 !== strpos( trim( $feature_lines[ $docstring_end ] ), '"""' ) && 0 !== strpos( trim( $feature_lines[ $docstring_end ] ), "'''" ) )
				|| 0 === $docstring_start
				|| ! isset( $feature_lines[ $docstring_start - 1 ] )
				|| ! is_php_file_step( $feature_lines[ $docstring_start - 1 ] )
			) {
				fwrite(
					STDERR,
					sprintf(
						'The block at "%s" line %d is no longer the one that was checked, dropping its fixes.',
						$feature_path,
						$docstring_start + 1
					) . PHP_EOL
				);
				$success = false;
				continue;
			}

			$code_lines = strip_extraction_padding( $temp_lines, $code_start, $block['had_php_tag'] );

			if ( null === $code_lines ) {
				fwrite(
					STDERR,
					sprintf(
						'The checked copy of the block at "%s" line %d is padded unexpectedly, dropping its fixes.',
						$feature_path,
						$docstring_start + 1
					) . PHP_EOL
				);
				$success = false;
				continue;
			}

			$indent = get_block_indent( $feature_lines, $code_start, $code_end, $block['docstring_start'] );

			$fixed_lines = [];
			foreach ( $code_lines as $line_content ) {
				if ( '' === trim( $line_content ) ) {
					$fixed_lines[] = "\n";
					continue;
				}

				$fixed_line = $indent . $line_content;
				if ( "\n" !== substr( $fixed_line, -1 ) ) {
					$fixed_line .= "\n";
				}

				$fixed_lines[] = $fixed_line;
			}

			$num_code_lines = ( $code_end - $code_start + 1 );
			array_splice( $feature_lines, $code_start, $num_code_lines, $fixed_lines );
		}

		if ( false === file_put_contents( $feature_path, implode( '', $feature_lines ) ) ) {
			fwrite( STDERR, sprintf( 'Could not write "%s".', $feature_path ) . PHP_EOL );
			$success = false;
		}
	}

	return $success;
}

$wp_cli_tests_args   = array_slice( $argv, 1 );
$wp_cli_tests_action = 'extract';

// Only treat the first argument as an action if it actually is one, so that
// a source directory does not accidentally end up being used as target.
if ( isset( $wp_cli_tests_args[0] ) && in_array( $wp_cli_tests_args[0], [ 'extract', 'update' ], true ) ) {
	$wp_cli_tests_action = array_shift( $wp_cli_tests_args );
}

$wp_cli_tests_source = $wp_cli_tests_args[0] ?? '';
$wp_cli_tests_target = $wp_cli_tests_args[1] ?? '';

if ( '' === $wp_cli_tests_source || '' === $wp_cli_tests_target ) {
	fwrite( STDERR, 'Usage: extract-feature-php.php [extract|update] <source-dir> <target-dir>' . PHP_EOL );
	exit( 1 );
}

if ( 'update' === $wp_cli_tests_action ) {
	exit( update_feature_php( $wp_cli_tests_source, $wp_cli_tests_target ) ? 0 : 1 );
}

exit( extract_feature_php( $wp_cli_tests_source, $wp_cli_tests_target ) ? 0 : 1 );
