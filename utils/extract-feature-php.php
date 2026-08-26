<?php
/**
 * Extracts PHP snippets from Behat .feature files into line-padded standalone PHP files,
 * and syncs PHPCBF fixes back into original .feature files.
 */

namespace WP_CLI\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pattern matching the file names created during extraction.
 */
const EXTRACTED_FILE_PATTERN = '/^(.*\.feature)_L(\d+)_E(\d+)_(HASPHP|NOPHP)\.php$/';

/**
 * Determine whether a path is the root of a filesystem or of a drive.
 *
 * @param string $path Path to check.
 * @return bool Whether the path is a root directory.
 */
function is_root_dir( $path ) {
	if ( '' === $path ) {
		return false;
	}

	// A Windows drive root, such as `C:`, `C:\`, or `C:/`.
	if ( preg_match( '/^[a-z]:[\\\\\/]?$/i', $path ) ) {
		return true;
	}

	return '' === rtrim( $path, '/\\' );
}

/**
 * Determine whether a directory can be used as extraction target.
 *
 * Extraction removes previously extracted files from the target directory,
 * so guard against pointing it at a directory holding actual project files.
 *
 * @param string $target_dir Target directory to output extracted .php files.
 * @param string $source_dir Source directory containing .feature files.
 * @return bool Whether the target directory can be used.
 */
function is_valid_target_dir( $target_dir, $source_dir ) {
	if ( '' === $target_dir || '.' === $target_dir || '..' === $target_dir ) {
		return false;
	}

	if ( is_root_dir( $target_dir ) ) {
		return false;
	}

	$target_real = realpath( $target_dir );

	// Also covers a path that only resolves to a root, such as `features/../..`.
	if ( false !== $target_real && is_root_dir( $target_real ) ) {
		return false;
	}

	// A directory that does not exist yet gets created during extraction.
	if ( false === $target_real ) {
		return true;
	}

	$cwd = getcwd();
	if ( false !== $cwd && realpath( $cwd ) === $target_real ) {
		return false;
	}

	$source_real = realpath( $source_dir );
	if ( false === $source_real ) {
		return true;
	}

	if ( $source_real === $target_real ) {
		return false;
	}

	// The target directory contains the feature files themselves. A root
	// directory already ends in a separator, so appending another one would
	// keep the comparison below from ever matching it.
	$target_prefix = rtrim( $target_real, '/\\' ) . DIRECTORY_SEPARATOR;
	if ( 0 === strpos( $source_real . DIRECTORY_SEPARATOR, $target_prefix ) ) {
		return false;
	}

	return true;
}

/**
 * Remove files of a previous extraction from the target directory.
 *
 * Only files created by this script are removed, so that an unrelated file in
 * the target directory is never lost. Directories are removed once they are
 * empty, which includes an empty directory that was already there.
 *
 * @param string $target_dir Target directory containing extracted .php files.
 * @return void
 */
function remove_extracted_files( $target_dir ) {
	if ( ! is_dir( $target_dir ) ) {
		return;
	}

	// The caller is expected to have rejected such a directory already, but
	// the walk below is not something to start on a whole filesystem by
	// accident.
	$target_real = realpath( $target_dir );
	if ( is_root_dir( $target_dir ) || ( false !== $target_real && is_root_dir( $target_real ) ) ) {
		return;
	}

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $fileinfo ) {
		$pathname = $fileinfo->getPathname();

		if ( $fileinfo->isDir() ) {
			$contents = new FilesystemIterator( $pathname );
			if ( ! $contents->valid() ) {
				rmdir( $pathname );
			}
		} elseif ( preg_match( EXTRACTED_FILE_PATTERN, $fileinfo->getFilename() ) ) {
			unlink( $pathname );
		}
	}
}

/**
 * Determine the indentation that all lines holding code share.
 *
 * This is what extraction takes off a block and what syncing puts back, so it
 * is determined as an actual prefix rather than as a number of characters: a
 * block mixing tabs and spaces would otherwise come back with one swapped for
 * the other. Blank lines carry no indentation of their own and are left out.
 *
 * @param string[] $lines Lines to compare.
 * @return string|null Shared indentation, or null if no line holds code.
 */
function get_common_indent( array $lines ) {
	$common = null;

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		preg_match( '/^[ \t]*/', $line, $matches );

		if ( null === $common ) {
			$common = $matches[0];
			continue;
		}

		$length = min( strlen( $common ), strlen( $matches[0] ) );
		while ( $length > 0 && substr( $common, 0, $length ) !== substr( $matches[0], 0, $length ) ) {
			--$length;
		}

		$common = substr( $common, 0, $length );

		if ( '' === $common ) {
			break;
		}
	}

	return $common;
}

/**
 * Determine whether a step creates a PHP file.
 *
 * The docstring following such a step holds the contents of a PHP file, while
 * docstrings following other steps -- an expectation about the contents of a
 * file, for example -- are not necessarily PHP code and must not be touched.
 *
 * This is the only thing that makes a docstring a PHP block here. The analysis
 * in `phpstan-feature-files.php` also takes one that opens with `<?php`, which
 * it can afford because it only ever reads. Reformatting an expectation would
 * make it stop matching the file it is checked against, so a block that is
 * fixed in place has to be one whose contents are known to be a PHP file.
 *
 * @param string $line Line preceding a docstring.
 * @return bool Whether the line is a step creating a PHP file.
 */
function is_php_file_step( $line ) {
	return 1 === preg_match( '/^\s*(?:Given|When|Then|And|But|\*)\s+an?\s+[\w\/.-]+\.php\s+(?:cache\s+)?file:\s*$/i', $line );
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

	remove_extracted_files( $target_dir );

	$success = true;

	$directory = new RecursiveDirectoryIterator( $source_dir );
	$iterator  = new RecursiveIteratorIterator( $directory );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'feature' === $file->getExtension() ) {
			$filepath = str_replace( '\\', '/', $file->getPathname() );
			$relative = substr( $filepath, strlen( $source_dir ) + 1 );
			$lines    = file( $filepath );

			if ( false === $lines ) {
				fwrite( STDERR, sprintf( 'Could not read "%s".', $filepath ) . PHP_EOL );
				$success = false;
				continue;
			}

			$in_docstring    = false;
			$is_php_block    = false;
			$start_line      = 0;
			$docstring_lines = [];

			foreach ( $lines as $index => $line ) {
				$trimmed = trim( $line );

				if ( 0 === strpos( $trimmed, '"""' ) || 0 === strpos( $trimmed, "'''" ) ) {
					if ( ! $in_docstring ) {
						$in_docstring    = true;
						$is_php_block    = false;
						$docstring_lines = [];
						$start_line      = $index;

						if ( $index > 0 && is_php_file_step( $lines[ $index - 1 ] ) ) {
							$is_php_block = true;
						}
					} else {
						$in_docstring = false;
						if ( $is_php_block && ! empty( $docstring_lines ) ) {
							$indent_length = strlen( (string) get_common_indent( $docstring_lines ) );

							$has_php_tag = false;
							foreach ( $docstring_lines as $code_line ) {
								if ( '' !== trim( $code_line ) ) {
									if ( 0 === strpos( trim( $code_line ), '<?php' ) ) {
										$has_php_tag = true;
									}
									break;
								}
							}

							$out_lines = [];
							for ( $i = 0; $i < $start_line + 1; $i++ ) {
								$out_lines[ $i ] = "\n";
							}

							// The docstring delimiter is the line right before the first line of
							// code, so an added opening tag goes there to keep line numbers intact.
							if ( ! $has_php_tag ) {
								$out_lines[ $start_line ] = "<?php\n";
							}

							foreach ( $docstring_lines as $line_idx => $code_line ) {
								if ( '' === trim( $code_line ) ) {
									$out_lines[ $line_idx ] = "\n";
								} else {
									$out_lines[ $line_idx ] = substr( $code_line, $indent_length );
								}
							}

							$end_line    = $index;
							$php_flag    = $has_php_tag ? 'HASPHP' : 'NOPHP';
							$target_file = $target_dir . '/' . $relative . '_L' . ( $start_line + 1 ) . '_E' . ( $end_line + 1 ) . '_' . $php_flag . '.php';

							$target_subdir = dirname( $target_file );
							if ( ! is_dir( $target_subdir ) && ! mkdir( $target_subdir, 0777, true ) && ! is_dir( $target_subdir ) ) {
								fwrite( STDERR, sprintf( 'Could not create directory "%s".', $target_subdir ) . PHP_EOL );
								$success = false;
								continue;
							}

							if ( false === file_put_contents( $target_file, implode( '', $out_lines ) ) ) {
								fwrite( STDERR, sprintf( 'Could not write "%s".', $target_file ) . PHP_EOL );
								$success = false;
							}
						}
					}
					continue;
				}

				if ( $in_docstring ) {
					// Every line is kept, including the empty ones leading up to
					// an opening tag, so that line numbers keep matching.
					$docstring_lines[ $index ] = $line;
				}
			}

			if ( $in_docstring ) {
				fwrite( STDERR, sprintf( 'Unterminated docstring in "%s".', $filepath ) . PHP_EOL );
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
