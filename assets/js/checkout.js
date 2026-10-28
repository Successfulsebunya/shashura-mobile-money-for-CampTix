/* Shashura checkout pages (render_page in class-shashura-payment-method.php).
 * Runs on every screen; the waiting-page poller below only activates when
 * wp_localize_script() has supplied window.shasmomoWait (render_wait()
 * only).
 */
document.querySelectorAll( 'form' ).forEach( function ( f ) {
	f.addEventListener( 'submit', function () {
		var b = f.querySelector( 'button' );
		if ( b ) {
			b.disabled = true;
		}
	} );
} );

if ( window.shasmomoWait ) {
	( function () {
		var cfg = window.shasmomoWait;
		var statusUrl = cfg.statusUrl;
		var labels = cfg.labels;
		var started = Date.now(), slowShown = false;

		function el( id ) { return document.getElementById( id ); }

		function done( d ) {
			var dl = el( 'det' );
			[ 'name', 'phone', 'network', 'amount', 'reference' ].forEach( function ( k ) {
				if ( ! d.details || ! d.details[ k ] ) { return; }
				var dt = document.createElement( 'dt' ), dd = document.createElement( 'dd' );
				dt.textContent = labels[ k ]; dd.textContent = d.details[ k ];
				if ( k === 'amount' ) { dd.className = 'amt'; }
				dl.appendChild( dt ); dl.appendChild( dd );
			} );
			el( 'wait' ).hidden = true; el( 'done' ).hidden = false;
			setTimeout( function () { window.location.href = d.next; }, 3000 );
		}

		function tick() {
			fetch( statusUrl, { credentials: 'same-origin', cache: 'no-store' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					if ( d.state === 'paid' && d.next ) { return done( d ); }
					if ( ( d.state === 'failed' || d.state === 'closed' ) && d.next ) {
						el( 'msg' ).textContent = labels.failed;
						return setTimeout( function () { window.location.href = d.next; }, 1500 );
					}
					later();
				} )
				.catch( later );
		}

		function later() {
			var age = Date.now() - started;
			if ( age > 90000 ) { el( 'retry' ).hidden = false; }
			if ( age > 150000 && ! slowShown ) { el( 'msg' ).textContent = labels.slow; slowShown = true; }
			setTimeout( tick, age > 300000 ? 15000 : 4000 );
		}

		setTimeout( tick, 3000 );
	} )();
}
