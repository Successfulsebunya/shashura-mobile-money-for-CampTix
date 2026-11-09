<?php
// Exercise the archive inspector with real zip files, including deliberately broken ones.
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}
$root = dirname( __DIR__ );
$temp = sys_get_temp_dir() . '/shasmomo-package-' . bin2hex( random_bytes( 8 ) );
mkdir( $temp );
function package_check( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo "PASS $message\n";
}
function package_command( $script, $argument = '' ) {
	$lines = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $argument ) . ' 2>&1', $lines, $status );
	return array( $status, implode( "\n", $lines ) );
}
try {
	list( $status, $output ) = package_command( $root . '/bin/build-zip.php' );
	package_check( 0 === $status, 'production archive builds: ' . $output );
	$original = $root . '/build/shashura-mobile-money-for-camptix.zip';
	$prefix   = 'shashura-mobile-money-for-camptix/';
	list( $status, $output ) = package_command( $root . '/bin/check-zip.php', $original );
	package_check( 0 === $status, 'unmodified production archive passes inspection' );
	$cases = array(
		'missing-template' => array( 'delete', $prefix . 'templates/wait.php', 'Missing from the zip' ),
		'missing-asset'    => array( 'delete', $prefix . 'assets/images/mtn-momo.png', 'Missing from the zip' ),
		'dev-file'        => array( 'add', $prefix . 'tests/private-fixture.php', 'Development file in the zip' ),
		'policy-file'     => array( 'add', $prefix . 'REFUND-RECOVERY.md', 'Development file in the zip' ),
		'outside-root'    => array( 'add', 'unexpected.php', 'Outside the' ),
		'traversal'       => array( 'add', $prefix . '../unexpected.php', 'Unsafe archive path' ),
	);
	foreach ( $cases as $label => $case ) {
		$path = $temp . '/' . $label . '.zip';
		copy( $original, $path );
		$zip = new ZipArchive();
		$zip->open( $path );
		if ( 'delete' === $case[0] ) {
			$zip->deleteName( $case[1] );
		} else {
			$zip->addFromString( $case[1], 'test fixture' );
		}
		$zip->close();
		list( $status, $output ) = package_command( $root . '/bin/check-zip.php', $path );
		package_check( 0 !== $status && false !== strpos( $output, $case[2] ), 'inspector rejects ' . $label );
	}
} finally {
	foreach ( glob( $temp . '/*' ) as $file ) {
		unlink( $file );
	}
	rmdir( $temp );
}
