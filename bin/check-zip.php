<?php
/**
 * Inspects the built plugin zip: everything the plugin needs is there,
 * and nothing listed in .distignore slipped in.
 *
 * Usage: php bin/check-zip.php [path/to/zip]
 *
 * @package CampTix_Shashura
 */

require __DIR__ . '/distignore.php';

$slug = 'shashura-mobile-money-for-camptix';
$root = dirname( __DIR__ );
$path = isset( $argv[1] ) ? $argv[1] : $root . '/build/' . $slug . '.zip';

$zip = new ZipArchive();
if ( true !== $zip->open( $path ) ) {
	fwrite( STDERR, "Could not open $path. Run php bin/build-zip.php first.\n" );
	exit( 1 );
}

$in_zip = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$in_zip[] = $zip->getNameIndex( $i );
}
$zip->close();

$errors   = array();
$patterns = shasmomo_dist_patterns( $root );

// Everything sits inside one folder named after the plugin.
foreach ( $in_zip as $name ) {
	if ( 0 !== strpos( $name, $slug . '/' ) ) {
		$errors[] = "Outside the $slug/ folder: $name";
	}
}

// Nothing excluded by .distignore made it in.
foreach ( $in_zip as $name ) {
	$rel = substr( $name, strlen( $slug ) + 1 );
	if ( '' !== $rel && shasmomo_dist_excluded( $rel, $patterns ) ) {
		$errors[] = "Should not be in the zip: $rel";
	}
}

// Development files stay out even if a line goes missing from .distignore.
$never = array( '.git', '.github', '.distignore', '.gitignore', 'tests', 'bin', 'build', 'vendor', 'README.md', 'composer.json', 'composer.lock', 'phpcs.xml.dist', 'screenshot-*.png' );
foreach ( $in_zip as $name ) {
	$rel = substr( $name, strlen( $slug ) + 1 );
	foreach ( $never as $pattern ) {
		if ( '' !== $rel && shasmomo_dist_excluded( $rel, array( '/' . $pattern ) ) ) {
			$errors[] = "Development file in the zip: $rel";
		}
	}
}

// Every file the plugin ships with is in the zip: all PHP, templates and assets.
$needed   = array( 'readme.txt', 'uninstall.php', $slug . '.php' );
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $item ) {
	$rel = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );
	if ( $item->isFile() && preg_match( '#^(includes|templates|assets)/#', $rel ) ) {
		$needed[] = $rel;
	}
}
foreach ( array_unique( $needed ) as $rel ) {
	if ( ! in_array( $slug . '/' . $rel, $in_zip, true ) ) {
		$errors[] = "Missing from the zip: $rel";
	}
}

// The main file's version matches readme.txt's stable tag.
$main   = (string) file_get_contents( $root . '/' . $slug . '.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $version );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
if ( empty( $version[1] ) || empty( $stable[1] ) || $version[1] !== $stable[1] ) {
	$errors[] = sprintf(
		'Plugin version (%s) and readme.txt stable tag (%s) differ',
		empty( $version[1] ) ? 'none' : $version[1],
		empty( $stable[1] ) ? 'none' : $stable[1]
	);
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}
printf( "Zip looks right: %d files, version %s.\n", count( $in_zip ), $version[1] );
