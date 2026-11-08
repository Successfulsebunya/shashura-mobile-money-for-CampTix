<?php
/**
 * Builds the plugin zip from the working tree, leaving out everything listed in .distignore.
 *
 * Usage: php bin/build-zip.php   (or: composer build)
 * Output: build/shashura-mobile-money-for-camptix.zip
 *
 * @package CampTix_Shashura
 */

require __DIR__ . '/distignore.php';

$slug = 'shashura-mobile-money-for-camptix';
$root = dirname( __DIR__ );

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}

$patterns = shasmomo_dist_patterns( $root );
$files    = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		function ( $item ) use ( $root, $patterns ) {
			$rel = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );
			return ! shasmomo_dist_excluded( $rel, $patterns );
		}
	)
);
foreach ( $iterator as $item ) {
	if ( $item->isFile() ) {
		$files[] = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );
	}
}
sort( $files );

if ( ! is_dir( $root . '/build' ) ) {
	mkdir( $root . '/build' );
}
$zip_path = $root . '/build/' . $slug . '.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create $zip_path\n" );
	exit( 1 );
}
foreach ( $files as $rel ) {
	$zip->addFile( $root . '/' . $rel, $slug . '/' . $rel );
}
$zip->close();

echo implode( "\n", $files ), "\n\n";
printf( "Built build/%s.zip with %d files.\n", $slug, count( $files ) );
