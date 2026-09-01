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
 * Width of a tab stop, in spaces.
 *
 * `WordPress-Core`, which `WP_CLI_CS` builds on, indents with one tab per level
 * and has PHP_CodeSniffer read a tab as four columns. Converting the blocks at
 * the same width is what makes the two conversions below meet in the middle.
 */
const TAB_WIDTH = 4;

/**
 * Determine how many columns a run of indentation covers.
 *
 * A tab advances to the next tab stop rather than by a fixed amount, which is
 * what keeps the two conversions below each other's inverse.
 *
 * @param string $indent    Indentation to measure.
 * @param int    $tab_width Width of a tab stop, in spaces.
 * @return int Number of columns the indentation covers.
 */
function get_indent_width( $indent, $tab_width ) {
	$width = 0;

	for ( $i = 0, $length = strlen( $indent ); $i < $length; $i++ ) {
		if ( "\t" === $indent[ $i ] ) {
			$width += $tab_width - ( $width % $tab_width );
		} else {
			++$width;
		}
	}

	return $width;
}

/**
 * Indent a line with tabs.
 *
 * Whatever is left over past the last full tab stop stays a run of spaces, so
 * that a line aligned to something rather than indented keeps its alignment.
 *
 * @param string $line      Line to convert.
 * @param int    $tab_width Width of a tab stop, in spaces.
 * @return string Line indented with tabs.
 */
function indent_with_tabs( $line, $tab_width = TAB_WIDTH ) {
	if ( 1 !== preg_match( '/^[ \t]+/', $line, $matches ) ) {
		return $line;
	}

	$width = get_indent_width( $matches[0], $tab_width );

	return str_repeat( "\t", intdiv( $width, $tab_width ) )
		. str_repeat( ' ', $width % $tab_width )
		. substr( $line, strlen( $matches[0] ) );
}

/**
 * Indent a line with spaces.
 *
 * @param string $line      Line to convert.
 * @param int    $tab_width Width of a tab stop, in spaces.
 * @return string Line indented with spaces.
 */
function indent_with_spaces( $line, $tab_width = TAB_WIDTH ) {
	if ( 1 !== preg_match( '/^[ \t]+/', $line, $matches ) ) {
		return $line;
	}

	return str_repeat( ' ', get_indent_width( $matches[0], $tab_width ) )
		. substr( $line, strlen( $matches[0] ) );
}

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
 * What is left of a line once the shared indentation is off is indented with
 * tabs, which is how the standard the block is checked against wants it. Feature
 * files indent with spaces, so update_feature_php() converts it back.
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
			$out_lines[ $line_idx ] = indent_with_tabs( substr( $code_line, $indent_length ) );
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
 * The prefix is measured, not reproduced verbatim: the caller writes it back as
 * spaces, along with the rest of the indentation of the line.
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

				// The line ending is put back the way it was found, so that a
				// feature file using CRLF keeps doing so.
				$eol = "\n";
				if ( 1 === preg_match( '/\r?\n$/', $line_content, $eol_match ) ) {
					$eol          = $eol_match[0];
					$line_content = substr( $line_content, 0, -strlen( $eol ) );
				}

				// Indentation goes back to spaces, undoing what extraction did to
				// have the block checked as the tab-indented file the standard
				// expects. Trailing whitespace goes with it: the fixer leaves some
				// behind wherever it breaks a line, and the sniff that would clean
				// that up cannot be part of the run, as it also wants the padding
				// gone. See render_fixable_block() and `phpcs/feature-files.sh`.
				// The two runs are converted apart rather than as one: extraction
				// took the shared prefix off before handing the rest to the fixer, so
				// the tabs it produced count from the start of the line as the fixer
				// saw it, not from the start of the line in the feature file.
				$fixed_lines[] = rtrim( indent_with_spaces( $indent ) . indent_with_spaces( $line_content ), " \t" ) . $eol;
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
