<?php
/** Run every standalone regression in a fresh PHP process. */
$tests = glob( dirname( __DIR__ ) . '/tests/*.php' );
sort( $tests );
foreach ( $tests as $test ) {
	if ( 'bootstrap.php' === basename( $test ) ) {
		continue;
	}
	printf( "\nRunning %s\n", basename( $test ) );
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $test ), $status );
	if ( 0 !== $status ) {
		exit( $status );
	}
}
