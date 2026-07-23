<?php
/**
 * Extracts PHP snippets from Behat .feature files into line-padded standalone PHP files,
 * and syncs PHPCBF fixes back into original .feature files.
 */

namespace WP_CLI\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Extract PHP blocks from a source directory of feature files to a target directory.
 *
 * @param string $source_dir Source directory containing .feature files.
 * @param string $target_dir Target directory to output extracted .php files.
 * @return void
 */
function extract_feature_php( $source_dir, $target_dir ) {
	$source_dir = rtrim( $source_dir, '/' );
	$target_dir = rtrim( $target_dir, '/' );

	if ( ! is_dir( $source_dir ) ) {
		return;
	}

	if ( is_dir( $target_dir ) ) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $fileinfo ) {
			$todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
			$todo( $fileinfo->getRealPath() );
		}
	}

	$directory = new RecursiveDirectoryIterator( $source_dir );
	$iterator  = new RecursiveIteratorIterator( $directory );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'feature' === $file->getExtension() ) {
			$filepath = $file->getPathname();
			$relative = substr( $filepath, strlen( $source_dir ) + 1 );
			$lines    = file( $filepath );

			$in_docstring    = false;
			$is_php_block    = false;
			$docstring_lines = [];
			$start_line      = 0;

			foreach ( $lines as $index => $line ) {
				$trimmed = trim( $line );

				if ( 0 === strpos( $trimmed, '"""' ) || 0 === strpos( $trimmed, "'''" ) ) {
					if ( ! $in_docstring ) {
						$in_docstring    = true;
						$is_php_block    = false;
						$docstring_lines = [];
						$start_line      = $index;

						if ( $index > 0 && preg_match( '/\b[\w\/-]+\.php\b/i', $lines[ $index - 1 ] ) ) {
							$is_php_block = true;
						}
					} else {
						$in_docstring = false;
						if ( $is_php_block && ! empty( $docstring_lines ) ) {
							$min_indent = PHP_INT_MAX;
							foreach ( $docstring_lines as $code_line ) {
								if ( '' !== trim( $code_line ) ) {
									preg_match( '/^\s*/', $code_line, $m );
									$min_indent = min( $min_indent, strlen( $m[0] ) );
								}
							}
							if ( PHP_INT_MAX === $min_indent ) {
								$min_indent = 0;
							}

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

							if ( ! $has_php_tag ) {
								$out_lines[ $start_line + 1 ] = "<?php // added_php_tag\n";
							}

							foreach ( $docstring_lines as $line_idx => $code_line ) {
								$out_lines[ $line_idx ] = substr( $code_line, $min_indent );
							}

							$end_line    = $index;
							$php_flag    = $has_php_tag ? 'HASPHP' : 'NOPHP';
							$target_file = $target_dir . '/' . $relative . '_L' . ( $start_line + 1 ) . '_E' . ( $end_line + 1 ) . '_' . $php_flag . '.php';

							$target_subdir = dirname( $target_file );
							if ( ! is_dir( $target_subdir ) ) {
								mkdir( $target_subdir, 0777, true );
							}
							file_put_contents( $target_file, implode( '', $out_lines ) );
						}
					}
					continue;
				}

				if ( $in_docstring ) {
					$docstring_count = count( $docstring_lines );
					if ( 0 === $docstring_count && 0 === strpos( $trimmed, '<?php' ) ) {
						$is_php_block = true;
					}
					if ( $is_php_block ) {
						$docstring_lines[ $index ] = $line;
					}
				}
			}
		}
	}
}

/**
 * Sync fixed PHP blocks from temporary directory back into original feature files.
 *
 * @param string $source_dir Source directory containing .feature files.
 * @param string $target_dir Target directory containing fixed .php files.
 * @return void
 */
function update_feature_php( $source_dir, $target_dir ) {
	$source_dir = rtrim( $source_dir, '/' );
	$target_dir = rtrim( $target_dir, '/' );

	if ( ! is_dir( $target_dir ) ) {
		return;
	}

	$directory = new RecursiveDirectoryIterator( $target_dir );
	$iterator  = new RecursiveIteratorIterator( $directory );

	$files_by_feature = [];

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$temp_filepath = $file->getPathname();
			$temp_filename = $file->getFilename();

			if ( ! preg_match( '/^(.*\.feature)_L(\d+)_E(\d+)_(HASPHP|NOPHP)\.php$/', $temp_filename, $matches ) ) {
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

	foreach ( $files_by_feature as $feature_path => $blocks ) {
		if ( ! file_exists( $feature_path ) ) {
			continue;
		}

		usort(
			$blocks,
			function ( $a, $b ) {
				return $b['docstring_start'] <=> $a['docstring_start'];
			}
		);

		$feature_lines = file( $feature_path );

		foreach ( $blocks as $block ) {
			$code_start  = $block['docstring_start'] + 1;
			$code_end    = $block['docstring_end'] - 1;
			$had_php_tag = $block['had_php_tag'];
			$temp_lines  = file( $block['temp_filepath'] );

			if ( ! isset( $feature_lines[ $code_start ] ) || $code_start > $code_end ) {
				continue;
			}

			preg_match( '/^\s*/', $feature_lines[ $code_start ], $m );
			$indent = $m[0] ?? '      ';

			$code_lines = [];
			foreach ( $temp_lines as $temp_line ) {
				if ( ! $had_php_tag && false !== strpos( $temp_line, 'added_php_tag' ) ) {
					continue;
				}
				$code_lines[] = $temp_line;
			}

			while ( ! empty( $code_lines ) && '' === trim( reset( $code_lines ) ) ) {
				array_shift( $code_lines );
			}
			while ( ! empty( $code_lines ) && '' === trim( end( $code_lines ) ) ) {
				array_pop( $code_lines );
			}

			$fixed_lines = [];
			foreach ( $code_lines as $line_content ) {
				if ( '' === trim( $line_content ) ) {
					$fixed_lines[] = "\n";
				} else {
					$fixed_lines[] = $indent . ltrim( $line_content );
				}
			}

			$num_code_lines = ( $code_end - $code_start + 1 );
			array_splice( $feature_lines, $code_start, $num_code_lines, $fixed_lines );
		}

		file_put_contents( $feature_path, implode( '', $feature_lines ) );
	}
}

$wp_cli_tests_action = $argv[1] ?? 'extract';
if ( 'update' === $wp_cli_tests_action ) {
	update_feature_php( $argv[2] ?? '', $argv[3] ?? '' );
} else {
	extract_feature_php( $argv[2] ?? $argv[1] ?? '', $argv[3] ?? $argv[2] ?? '' );
}
