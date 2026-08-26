<?php
/**
 * Extracts PHP snippets from Behat .feature files into line-padded standalone PHP
 * files that PHPStan can analyse, and maps the resulting errors back onto the
 * original .feature files.
 *
 * Unlike PHP_CodeSniffer, PHPStan resolves symbols across all analysed files at
 * once and aborts the entire run when a single file fails to parse. The blocks
 * are therefore checked for parse errors up front and spread over batches that
 * do not declare the same class or function twice.
 */

namespace WP_CLI\Tests;

use FilesystemIterator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pattern matching the file names created during extraction.
 */
const EXTRACTED_FILE_PATTERN = '/^(.*\.feature)_L(\d+)_E(\d+)\.php$/';

/**
 * Name of the manifest describing an extraction.
 */
const MANIFEST_FILE = 'manifest.json';

/**
 * Bring a path into the form used to compare it against another path.
 *
 * @param string $path Path to normalize.
 * @return string Normalized path.
 */
function normalize_path( $path ) {
	$path = rtrim( str_replace( '\\', '/', $path ), '/' );

	// Windows paths are not case sensitive.
	return DIRECTORY_SEPARATOR === '\\' ? strtolower( $path ) : $path;
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

	// A Windows drive root, such as `C:`, `C:\`, or `C:/`.
	if ( preg_match( '/^[a-z]:[\\\\\/]?$/i', $target_dir ) ) {
		return false;
	}

	$target_real = realpath( $target_dir );

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

	// The target directory contains the feature files themselves.
	if ( 0 === strpos( $source_real . DIRECTORY_SEPARATOR, $target_real . DIRECTORY_SEPARATOR ) ) {
		return false;
	}

	return true;
}

/**
 * Remove files of a previous extraction from the target directory.
 *
 * Only files created by this script and the directories that held them are
 * removed, so that an unrelated file in the target directory is never lost.
 *
 * @param string $target_dir Target directory containing extracted .php files.
 * @return void
 */
function remove_extracted_files( $target_dir ) {
	if ( ! is_dir( $target_dir ) ) {
		return;
	}

	$manifest = $target_dir . '/' . MANIFEST_FILE;
	if ( is_file( $manifest ) ) {
		unlink( $manifest );
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
 * Determine whether a step creates a PHP file.
 *
 * The docstring following such a step holds the contents of a PHP file, while
 * docstrings following other steps -- an expectation about the contents of a
 * file, for example -- are not necessarily PHP code. A docstring that opens
 * with `<?php` counts as PHP either way, see collect_blocks().
 *
 * @param string $line Line preceding a docstring.
 * @return bool Whether the line is a step creating a PHP file.
 */
function is_php_file_step( $line ) {
	return 1 === preg_match( '/^\s*(?:Given|When|Then|And|But|\*)\s+an?\s+[\w\/.-]+\.php\s+(?:cache\s+)?file:\s*$/i', $line );
}

/**
 * Collect the PHP blocks contained in a single feature file.
 *
 * @param string[] $lines Lines of the feature file.
 * @return array<int, array{start: int, end: int, lines: array<int, string>}>|null Blocks, or null on an unterminated docstring.
 */
function collect_blocks( array $lines ) {
	$blocks          = [];
	$in_docstring    = false;
	$is_php_block    = false;
	$has_content     = false;
	$start_line      = 0;
	$docstring_lines = [];

	foreach ( $lines as $index => $line ) {
		$trimmed = trim( $line );

		if ( 0 === strpos( $trimmed, '"""' ) || 0 === strpos( $trimmed, "'''" ) ) {
			if ( ! $in_docstring ) {
				$in_docstring    = true;
				$is_php_block    = $index > 0 && is_php_file_step( $lines[ $index - 1 ] );
				$has_content     = false;
				$docstring_lines = [];
				$start_line      = $index;
			} else {
				$in_docstring = false;

				if ( $is_php_block && ! empty( $docstring_lines ) ) {
					$blocks[] = [
						'start' => $start_line,
						'end'   => $index,
						'lines' => $docstring_lines,
					];
				}
			}
			continue;
		}

		if ( $in_docstring ) {
			// A block opening with `<?php` is PHP no matter which step precedes
			// it, which covers PHP files that are not named `*.php`, such as the
			// `.maintenance` file of a WordPress installation.
			if ( ! $has_content && 0 === strpos( $trimmed, '<?php' ) ) {
				$is_php_block = true;
			}

			if ( '' !== $trimmed ) {
				$has_content = true;
			}

			// Every line is kept, including the empty ones leading up to
			// an opening tag, so that line numbers keep matching.
			$docstring_lines[ $index ] = $line;
		}
	}

	return $in_docstring ? null : $blocks;
}

/**
 * Turn a PHP block into the source of a standalone PHP file.
 *
 * The opening tag goes on the first line and the block is padded with one empty
 * line per preceding line of the feature file, so that the line numbers PHPStan
 * reports are the line numbers of the feature file. Anything in front of the
 * opening tag would count as inline HTML, which makes `declare()` and
 * `namespace` statements in a block a fatal error, so a tag the block brings
 * along itself is dropped instead of being kept in place.
 *
 * @param array{start: int, end: int, lines: array<int, string>} $block Block to render.
 * @return string Source of the standalone PHP file.
 */
function render_block( array $block ) {
	$min_indent = PHP_INT_MAX;
	foreach ( $block['lines'] as $code_line ) {
		if ( '' !== trim( $code_line ) ) {
			preg_match( '/^[ \t]*/', $code_line, $matches );
			$min_indent = min( $min_indent, strlen( $matches[0] ) );
		}
	}
	if ( PHP_INT_MAX === $min_indent ) {
		$min_indent = 0;
	}

	$out_lines = [];
	for ( $i = 0; $i <= $block['start']; $i++ ) {
		$out_lines[ $i ] = "\n";
	}
	$out_lines[0] = "<?php\n";

	$tag_dropped = false;
	foreach ( $block['lines'] as $line_idx => $code_line ) {
		if ( '' === trim( $code_line ) ) {
			$out_lines[ $line_idx ] = "\n";
			continue;
		}

		$code_line = substr( $code_line, $min_indent );

		if ( ! $tag_dropped ) {
			$tag_dropped = true;

			$without_tag = preg_replace( '/^\s*<\?php\b/', '', $code_line, 1, $count );
			if ( $count > 0 ) {
				$code_line = '' === trim( (string) $without_tag ) ? "\n" : (string) $without_tag;
			}
		}

		$out_lines[ $line_idx ] = $code_line;
	}

	$source = implode( '', $out_lines );

	return "\n" === substr( $source, -1 ) ? $source : $source . "\n";
}

/**
 * Determine whether a block can be parsed as standalone PHP.
 *
 * A single unparsable file makes PHPStan abort the whole run, and feature files
 * legitimately contain snippets that are not standalone PHP: deliberate syntax
 * errors, or Behat placeholders such as `{USER_ID}` that are substituted before
 * the snippet is ever written to disk.
 *
 * @param string $source Source of the standalone PHP file.
 * @return string|null Parse error message, or null when the source parses.
 */
function get_parse_error( $source ) {
	try {
		// The tokens are of no interest here, only whether the source parses at all.
		token_get_all( $source, TOKEN_PARSE );
	} catch ( ParseError $exception ) {
		return $exception->getMessage();
	}

	return null;
}

/**
 * Collect the names of the classes and functions a block declares globally.
 *
 * PHPStan resolves a name to a single declaration, so two blocks declaring the
 * same class produce errors about members that only exist on the other block's
 * version of it. Declarations nested in a conditional are not detected, which
 * can only lead to blocks sharing a batch that would better be kept apart.
 *
 * @param string $source Source of the standalone PHP file.
 * @return string[] Lowercased names of the declared symbols.
 */
function get_declared_symbols( $source ) {
	$declarations = [ T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION ];
	if ( defined( 'T_ENUM' ) ) {
		$declarations[] = constant( 'T_ENUM' );
	}

	$tokens  = token_get_all( $source );
	$count   = count( $tokens );
	$symbols = [];

	// Brace depths at which the body of a class or function was opened, so that
	// methods and nested functions are not mistaken for global declarations.
	$body_stack   = [];
	$depth        = 0;
	$pending_body = false;
	$previous     = null;

	for ( $index = 0; $index < $count; $index++ ) {
		$token = $tokens[ $index ];

		if ( ! is_array( $token ) ) {
			if ( '{' === $token ) {
				++$depth;
				if ( $pending_body ) {
					$body_stack[] = $depth;
					$pending_body = false;
				}
			} elseif ( '}' === $token ) {
				if ( ! empty( $body_stack ) && end( $body_stack ) === $depth ) {
					array_pop( $body_stack );
				}
				--$depth;
			} elseif ( ';' === $token ) {
				$pending_body = false;
			}

			$previous = $token;
			continue;
		}

		if ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
			++$depth;
			$previous = $token;
			continue;
		}

		if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			continue;
		}

		// `use function foo;` and `use Foo;` are imports, not declarations.
		if ( in_array( $token[0], $declarations, true ) && ! ( is_array( $previous ) && T_USE === $previous[0] ) ) {
			$name = null;

			for ( $lookahead = $index + 1; $lookahead < $count; $lookahead++ ) {
				$next = $tokens[ $lookahead ];

				if ( is_array( $next ) && ( T_WHITESPACE === $next[0] || T_COMMENT === $next[0] || T_DOC_COMMENT === $next[0] ) ) {
					continue;
				}

				// A function returning by reference.
				if ( '&' === $next ) {
					continue;
				}

				if ( is_array( $next ) && T_STRING === $next[0] ) {
					$name = $next[1];
				}

				break;
			}

			if ( null !== $name ) {
				$pending_body = true;

				if ( empty( $body_stack ) ) {
					$symbols[] = strtolower( $name );
				}
			}
		}

		$previous = $token;
	}

	return array_values( array_unique( $symbols ) );
}

/**
 * Distribute blocks over batches that do not declare the same symbol twice.
 *
 * @param array<int, array{symbols: string[]}> $blocks Blocks to distribute.
 * @return int[] Batch number for each block, keyed by the block's key.
 */
function assign_batches( array $blocks ) {
	$batches    = [];
	$assignment = [];

	foreach ( $blocks as $key => $block ) {
		$batch = 0;

		while ( isset( $batches[ $batch ] ) && array_intersect( $block['symbols'], $batches[ $batch ] ) ) {
			++$batch;
		}

		if ( ! isset( $batches[ $batch ] ) ) {
			$batches[ $batch ] = [];
		}

		$batches[ $batch ]  = array_merge( $batches[ $batch ], $block['symbols'] );
		$assignment[ $key ] = $batch;
	}

	return $assignment;
}

/**
 * Extract the PHP blocks of a source directory of feature files to a target directory.
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
	$blocks  = [];
	$skipped = [];

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
	);

	$feature_files = [];
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'feature' === $file->getExtension() ) {
			$feature_files[] = str_replace( '\\', '/', $file->getPathname() );
		}
	}

	// The order determines the batch a block ends up in, so keep it stable.
	sort( $feature_files );

	foreach ( $feature_files as $filepath ) {
		$relative = substr( $filepath, strlen( $source_dir ) + 1 );
		$lines    = file( $filepath );

		if ( false === $lines ) {
			fwrite( STDERR, sprintf( 'Could not read "%s".', $filepath ) . PHP_EOL );
			$success = false;
			continue;
		}

		$found = collect_blocks( $lines );

		if ( null === $found ) {
			fwrite( STDERR, sprintf( 'Unterminated docstring in "%s".', $filepath ) . PHP_EOL );
			$success = false;
			continue;
		}

		foreach ( $found as $block ) {
			$source = render_block( $block );
			$error  = get_parse_error( $source );

			if ( null !== $error ) {
				$skipped[] = [
					'file'   => $filepath,
					'line'   => $block['start'] + 1,
					'reason' => $error,
				];
				continue;
			}

			$blocks[] = [
				'feature' => $filepath,
				'name'    => $relative . '_L' . ( $block['start'] + 1 ) . '_E' . ( $block['end'] + 1 ) . '.php',
				'source'  => $source,
				'symbols' => get_declared_symbols( $source ),
			];
		}
	}

	$assignment = assign_batches( $blocks );
	$manifest   = [
		'source_dir' => $source_dir,
		'blocks'     => [],
		'skipped'    => $skipped,
	];

	foreach ( $blocks as $key => $block ) {
		$relative_target = 'batch' . $assignment[ $key ] . '/' . $block['name'];
		$target_file     = $target_dir . '/' . $relative_target;
		$target_subdir   = dirname( $target_file );

		if ( ! is_dir( $target_subdir ) && ! mkdir( $target_subdir, 0777, true ) && ! is_dir( $target_subdir ) ) {
			fwrite( STDERR, sprintf( 'Could not create directory "%s".', $target_subdir ) . PHP_EOL );
			$success = false;
			continue;
		}

		if ( false === file_put_contents( $target_file, $block['source'] ) ) {
			fwrite( STDERR, sprintf( 'Could not write "%s".', $target_file ) . PHP_EOL );
			$success = false;
			continue;
		}

		$manifest['blocks'][ $relative_target ] = $block['feature'];
	}

	if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0777, true ) && ! is_dir( $target_dir ) ) {
		fwrite( STDERR, sprintf( 'Could not create directory "%s".', $target_dir ) . PHP_EOL );
		return false;
	}

	$encoded = json_encode( $manifest );

	if ( false === $encoded || false === file_put_contents( $target_dir . '/' . MANIFEST_FILE, $encoded ) ) {
		fwrite( STDERR, sprintf( 'Could not write the manifest to "%s".', $target_dir ) . PHP_EOL );
		return false;
	}

	return $success;
}

/**
 * Read the manifest of an extraction.
 *
 * @param string $target_dir Target directory containing extracted .php files.
 * @return array{source_dir: string, blocks: array<string, string>, skipped: array<int, array{file: string, line: int, reason: string}>}|null Manifest, or null when it cannot be read.
 */
function read_manifest( $target_dir ) {
	$path = rtrim( str_replace( '\\', '/', $target_dir ), '/' ) . '/' . MANIFEST_FILE;

	if ( ! is_file( $path ) ) {
		return null;
	}

	$contents = file_get_contents( $path );

	if ( false === $contents ) {
		return null;
	}

	$manifest = json_decode( $contents, true );

	if ( ! is_array( $manifest ) || ! isset( $manifest['blocks'] ) || ! is_array( $manifest['blocks'] ) ) {
		return null;
	}

	$manifest['skipped'] = isset( $manifest['skipped'] ) && is_array( $manifest['skipped'] ) ? $manifest['skipped'] : [];

	return $manifest;
}

/**
 * Turn the errors PHPStan reported for the extracted files back into errors
 * about the feature files they came from.
 *
 * @param array<string, string>       $blocks  Feature file for each extracted file, keyed by its path relative to the target directory.
 * @param string                      $target_dir Target directory containing extracted .php files.
 * @param array<int, array<mixed>>    $results PHPStan results, decoded from its JSON output.
 * @return array{errors: array<string, array<int, array{line: int, message: string, identifier: string}>>, generic: string[]} Errors per feature file, plus errors not tied to a file.
 */
function map_errors( array $blocks, $target_dir, array $results ) {
	$target_dir = rtrim( str_replace( '\\', '/', $target_dir ), '/' );

	// The path PHPStan reports and the path the extraction wrote are not
	// necessarily spelled the same: macOS resolves `/var` to `/private/var` and
	// Windows has both a short and a long form of a directory name. Both
	// spellings of every extracted file are therefore looked up.
	$lookup = [];
	foreach ( $blocks as $relative_target => $feature ) {
		$path = $target_dir . '/' . $relative_target;
		$real = realpath( $path );

		$lookup[ normalize_path( $path ) ] = $feature;

		if ( false !== $real ) {
			$lookup[ normalize_path( $real ) ] = $feature;
		}
	}

	$errors  = [];
	$generic = [];

	foreach ( $results as $result ) {
		if ( isset( $result['errors'] ) && is_array( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				$generic[] = (string) $error;
			}
		}

		if ( ! isset( $result['files'] ) || ! is_array( $result['files'] ) ) {
			continue;
		}

		foreach ( $result['files'] as $path => $file ) {
			$path    = (string) $path;
			$real    = realpath( $path );
			$feature = null;

			foreach ( [ $path, false === $real ? null : $real ] as $candidate ) {
				if ( null !== $candidate && isset( $lookup[ normalize_path( $candidate ) ] ) ) {
					$feature = $lookup[ normalize_path( $candidate ) ];
					break;
				}
			}

			if ( null === $feature ) {
				$generic[] = sprintf( 'Unexpected file "%s" in the PHPStan results.', str_replace( '\\', '/', $path ) );
				continue;
			}

			if ( ! isset( $errors[ $feature ] ) ) {
				$errors[ $feature ] = [];
			}

			if ( ! isset( $file['messages'] ) || ! is_array( $file['messages'] ) ) {
				continue;
			}

			foreach ( $file['messages'] as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$errors[ $feature ][] = [
					'line'       => isset( $message['line'] ) ? (int) $message['line'] : 0,
					'message'    => isset( $message['message'] ) ? (string) $message['message'] : '',
					'identifier' => isset( $message['identifier'] ) ? (string) $message['identifier'] : '',
				];
			}
		}
	}

	foreach ( $errors as $feature => $messages ) {
		usort(
			$messages,
			function ( $a, $b ) {
				return $a['line'] <=> $b['line'];
			}
		);
		$errors[ $feature ] = $messages;
	}

	ksort( $errors );

	return [
		'errors'  => $errors,
		'generic' => $generic,
	];
}

/**
 * Report the errors PHPStan found in the PHP blocks of feature files.
 *
 * @param string   $target_dir Target directory containing extracted .php files.
 * @param string[] $json_files Files holding the JSON output of a PHPStan run.
 * @return bool Whether the blocks are free of errors.
 */
function report_feature_php( $target_dir, array $json_files ) {
	$manifest = read_manifest( $target_dir );

	if ( null === $manifest ) {
		fwrite( STDERR, sprintf( 'Could not read the manifest in "%s".', $target_dir ) . PHP_EOL );
		return false;
	}

	$results = [];

	foreach ( $json_files as $json_file ) {
		$contents = is_file( $json_file ) ? file_get_contents( $json_file ) : false;
		$decoded  = false === $contents ? null : json_decode( $contents, true );

		if ( ! is_array( $decoded ) ) {
			fwrite( STDERR, sprintf( 'Could not read the PHPStan results from "%s".', $json_file ) . PHP_EOL );
			return false;
		}

		$results[] = $decoded;
	}

	$mapped = map_errors( $manifest['blocks'], $target_dir, $results );
	$total  = 0;

	foreach ( $mapped['errors'] as $feature => $messages ) {
		echo PHP_EOL . ' ' . $feature . PHP_EOL;

		foreach ( $messages as $message ) {
			++$total;

			// A message can span multiple lines, for example when it explains a deprecation.
			$text = str_replace( "\n", PHP_EOL . '         ', rtrim( str_replace( "\r\n", "\n", $message['message'] ) ) );

			printf( '  %-6d %s%s', $message['line'], $text, PHP_EOL );

			if ( '' !== $message['identifier'] ) {
				printf( '         🪪  %s%s', $message['identifier'], PHP_EOL );
			}
		}
	}

	foreach ( $mapped['generic'] as $message ) {
		++$total;
		echo PHP_EOL . ' ' . $message . PHP_EOL;
	}

	if ( ! empty( $manifest['skipped'] ) ) {
		printf(
			'%s [NOTE] Skipped %d PHP block(s) that are not standalone PHP.%s',
			PHP_EOL,
			count( $manifest['skipped'] ),
			PHP_EOL
		);

		foreach ( $manifest['skipped'] as $skipped ) {
			printf( '        %s:%d: %s%s', $skipped['file'], $skipped['line'], $skipped['reason'], PHP_EOL );
		}
	}

	if ( 0 === $total ) {
		printf( '%s [OK] No errors in the PHP blocks of the feature files.%s', PHP_EOL, PHP_EOL );
		return true;
	}

	printf( '%s [ERROR] Found %d error(s) in the PHP blocks of the feature files.%s', PHP_EOL, $total, PHP_EOL );

	return false;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ( ! function_exists( 'token_get_all' ) ) {
	fwrite( STDERR, 'The PHP tokenizer extension is required to analyse the PHP blocks in feature files.' . PHP_EOL );
	exit( 1 );
}

$wp_cli_tests_args   = array_slice( $argv, 1 );
$wp_cli_tests_action = array_shift( $wp_cli_tests_args );

if ( 'extract' === $wp_cli_tests_action && 2 === count( $wp_cli_tests_args ) ) {
	exit( extract_feature_php( $wp_cli_tests_args[0], $wp_cli_tests_args[1] ) ? 0 : 1 );
}

if ( 'report' === $wp_cli_tests_action && count( $wp_cli_tests_args ) >= 1 ) {
	exit( report_feature_php( array_shift( $wp_cli_tests_args ), $wp_cli_tests_args ) ? 0 : 1 );
}

fwrite(
	STDERR,
	'Usage: phpstan-feature-files.php extract <source-dir> <target-dir>' . PHP_EOL
	. '       phpstan-feature-files.php report <target-dir> <json-file>...' . PHP_EOL
);
exit( 1 );
