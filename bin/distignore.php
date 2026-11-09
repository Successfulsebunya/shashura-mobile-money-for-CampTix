<?php
/**
 * Reads .distignore and decides which files belong in the plugin zip.
 *
 * Shared by bin/build-zip.php and bin/check-zip.php so both use the same rules.
 *
 * @package CampTix_Shashura
 */

/**
 * Load the patterns from .distignore, skipping blank lines and comments.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function shasmomo_dist_patterns( $root ) {
	$file = $root . '/.distignore';
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, ".distignore not found in $root\n" );
		exit( 1 );
	}
	$patterns = array();
	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && '#' !== $line[0] ) {
			$patterns[] = $line;
		}
	}
	return $patterns;
}

/**
 * Whether a path relative to the plugin root is excluded by the patterns.
 *
 * A pattern starting with "/" matches from the root; any other pattern matches
 * a file or folder name at any depth. A match on a folder excludes everything in it.
 *
 * @param string   $rel      Relative path with forward slashes, e.g. "tests/refund.php".
 * @param string[] $patterns Patterns from .distignore.
 * @return bool
 */
function shasmomo_dist_excluded( $rel, array $patterns ) {
	$parts = explode( '/', $rel );
	foreach ( $patterns as $pattern ) {
		$anchored = '/' === $pattern[0];
		$pattern  = trim( $pattern, '/' );
		$depth    = substr_count( $pattern, '/' ) + 1;
		if ( $anchored ) {
			if ( count( $parts ) >= $depth && fnmatch( $pattern, implode( '/', array_slice( $parts, 0, $depth ) ) ) ) {
				return true;
			}
			continue;
		}
		foreach ( $parts as $part ) {
			if ( fnmatch( $pattern, $part ) ) {
				return true;
			}
		}
	}
	return false;
}
