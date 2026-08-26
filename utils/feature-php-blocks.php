<?php
/**
 * Reading the PHP blocks that Behat .feature files embed in docstrings.
 *
 * Shared by `extract-feature-php.php`, which checks and fixes their code style,
 * and by `phpstan-feature-files.php`, which analyses them. What the two tools do
 * with a block differs, but they have to agree on which docstrings hold one and
 * on where a block may be extracted to, so that lives here.
 *
 * The file is not part of the autoloader. Both callers are stand-alone scripts
 * that pull it in themselves.
 */

namespace WP_CLI\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
 * Find the feature files of a source directory.
 *
 * @param string $source_dir Source directory containing .feature files.
 * @return string[] Sorted paths of the feature files, with forward slashes.
 */
function find_feature_files( $source_dir ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
	);

	$feature_files = [];

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'feature' === $file->getExtension() ) {
			$feature_files[] = str_replace( '\\', '/', $file->getPathname() );
		}
	}

	// A stable order keeps the results of a run comparable to those of the next.
	sort( $feature_files );

	return $feature_files;
}

/**
 * Collect the PHP blocks contained in a single feature file.
 *
 * A docstring holds a PHP block when the step it belongs to creates a `.php`
 * file, and also when it opens with `<?php`, which covers PHP files that are
 * not named `*.php`, such as the `.maintenance` file of a WordPress install.
 *
 * Which of the two recognised a block is reported back rather than decided
 * here, as a tool writing a block back cannot afford the second rule: a
 * docstring stating an expectation about the contents of a file routinely
 * opens with `<?php`, and reformatting one would make it stop matching the
 * file it is checked against.
 *
 * @param string[] $lines Lines of the feature file.
 * @return array<int, array{start: int, end: int, lines: array<int, string>, from_step: bool, has_php_tag: bool}>|null Blocks, or null on an unterminated docstring.
 */
function collect_blocks( array $lines ) {
	$blocks          = [];
	$in_docstring    = false;
	$from_step       = false;
	$has_php_tag     = false;
	$has_content     = false;
	$start_line      = 0;
	$docstring_lines = [];

	foreach ( $lines as $index => $line ) {
		$trimmed = trim( $line );

		if ( 0 === strpos( $trimmed, '"""' ) || 0 === strpos( $trimmed, "'''" ) ) {
			if ( ! $in_docstring ) {
				$in_docstring    = true;
				$from_step       = $index > 0 && is_php_file_step( $lines[ $index - 1 ] );
				$has_php_tag     = false;
				$has_content     = false;
				$docstring_lines = [];
				$start_line      = $index;
			} else {
				$in_docstring = false;

				if ( ( $from_step || $has_php_tag ) && ! empty( $docstring_lines ) ) {
					$blocks[] = [
						'start'       => $start_line,
						'end'         => $index,
						'lines'       => $docstring_lines,
						'from_step'   => $from_step,
						'has_php_tag' => $has_php_tag,
					];
				}
			}
			continue;
		}

		if ( $in_docstring ) {
			if ( ! $has_content && 0 === strpos( $trimmed, '<?php' ) ) {
				$has_php_tag = true;
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
 * Remove files of a previous extraction from the target directory.
 *
 * Only files created by this script are removed, so that an unrelated file in
 * the target directory is never lost. Directories are removed once they are
 * empty, which includes an empty directory that was already there.
 *
 * @param string $target_dir Target directory containing extracted .php files.
 * @param string $pattern    Pattern matching the names of the extracted files.
 * @return void
 */
function remove_extracted_files( $target_dir, $pattern ) {
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
		} elseif ( preg_match( $pattern, $fileinfo->getFilename() ) ) {
			unlink( $pathname );
		}
	}
}
