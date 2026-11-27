/* Leadstart Translate — admin helpers */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Saved secrets never appear in the DOM. The toggle is for unsaved input only.
		document.querySelectorAll( '.leadst-secret-field' ).forEach( function ( field ) {
			var input = field.querySelector( '.leadst-secret-input' );
			var toggle = field.querySelector( '.leadst-secret-toggle' );
			if ( ! input || ! toggle ) { return; }
			input.addEventListener( 'input', function () {
				toggle.disabled = input.value.length === 0;
				if ( toggle.disabled ) {
					input.type = 'password';
					toggle.textContent = window.leadstAdmin?.i18n?.showNewKey || 'Show new';
					toggle.setAttribute( 'aria-pressed', 'false' );
				}
			} );
			toggle.addEventListener( 'click', function () {
				if ( ! input.value ) { return; }
				var reveal = input.type === 'password';
				input.type = reveal ? 'text' : 'password';
				toggle.textContent = reveal ? ( window.leadstAdmin?.i18n?.hideNewKey || 'Hide new' ) : ( window.leadstAdmin?.i18n?.showNewKey || 'Show new' );
				toggle.setAttribute( 'aria-pressed', reveal ? 'true' : 'false' );
			} );
		} );

		var grid = document.querySelector( '.leadst-language-grid' );

		if ( ! grid ) {
			return;
		}

		var boxes = grid.querySelectorAll( 'input[type="checkbox"]' );
		var count = document.getElementById( 'leadst-selected-count' );
		var all = document.getElementById( 'leadst-select-all' );
		var none = document.getElementById( 'leadst-select-none' );
		var search = document.getElementById( 'leadst-language-search' );
		var selectedOnly = document.getElementById( 'leadst-language-selected-only' );
		var noResults = document.getElementById( 'leadst-language-no-results' );

		var estimate = document.getElementById( 'leadst-estimate' );
		var cfg = window.leadstAdmin || null;

		if ( cfg ) {
			cfg.siteChars = Number( cfg.siteChars ) || 0;
			cfg.ratePerMillion = Number( cfg.ratePerMillion ) || 0;
			cfg.freePerMonth = Number( cfg.freePerMonth ) || 0;
		}

		function group( n ) {
			return Math.round( n ).toString().replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		}

		// Cost is the one number that decides how many languages are sensible,
		// so show it while the choice is being made rather than afterwards.
		function price( selected ) {
			if ( ! estimate || ! cfg ) {
				return;
			}

			if ( cfg.engine !== 'google_v2' ) {
				estimate.textContent = cfg.i18n.providerEstimate;
				estimate.className = 'leadst-estimate';
				return;
			}

			if ( selected === 0 ) {
				estimate.textContent = '';
				estimate.className = 'leadst-estimate';
				return;
			}

			if ( ! cfg.siteChars ) {
				estimate.textContent = cfg.i18n.noContent;
				estimate.className = 'leadst-estimate';
				return;
			}

			var chars = cfg.siteChars * selected;
			var cost = ( chars / 1000000 ) * cfg.ratePerMillion;
			var months = chars / cfg.freePerMonth;

			var line = cfg.i18n.estimate
				.replace( '%1$s', group( chars ) )
				.replace( '%2$s', cost < 10 ? cost.toFixed( 2 ) : group( cost ) );

			if ( chars <= cfg.freePerMonth ) {
				line += ' ' + cfg.i18n.withinFree;
				estimate.className = 'leadst-estimate is-free';
			} else {
				line += ' ' + cfg.i18n.months.replace( '%s', Math.ceil( months ) );
				estimate.className = cost >= 250 ? 'leadst-estimate is-steep' : 'leadst-estimate';
			}

			estimate.textContent = line;
		}

		function filterLanguages() {
			var query = search ? search.value.trim().toLocaleLowerCase() : '';
			var onlySelected = selectedOnly && selectedOnly.checked;
			var visible = 0;
			boxes.forEach( function ( box ) {
				var label = box.closest( '.leadst-language, .wptr-language' );
				if ( ! label ) { return; }
				var matches = ( ! query || label.textContent.toLocaleLowerCase().indexOf( query ) !== -1 )
					&& ( ! onlySelected || box.checked );
				label.hidden = ! matches;
				if ( matches ) { visible++; }
			} );
			if ( noResults ) { noResults.hidden = visible > 0; }
		}

		function refresh() {
			var selected = 0;
			boxes.forEach( function ( box ) {
				if ( box.checked ) {
					selected++;
				}
			} );

			if ( count ) {
				count.textContent = String( selected );
			}

			price( selected );
			filterLanguages();
		}

		function setAll( value ) {
			boxes.forEach( function ( box ) {
				box.checked = value;
			} );
			refresh();
		}

		if ( all ) {
			all.addEventListener( 'click', function () {
				var total = boxes.length;
				var message = cfg && cfg.i18n ? cfg.i18n.selectAllConfirm.replace( '%s', String( total ) ) : 'Enable all languages?';

				if ( cfg && cfg.siteChars && cfg.engine === 'google_v2' ) {
					var chars = cfg.siteChars * total;
					var cost = ( chars / 1000000 ) * cfg.ratePerMillion;
					message += '\n\n' + cfg.i18n.selectAllCost
						.replace( '%1$s', group( chars ) )
						.replace( '%2$s', group( cost ) );
				}

				if ( window.confirm( message ) ) {
					setAll( true );
				}
			} );
		}

		if ( none ) {
			none.addEventListener( 'click', function () {
				setAll( false );
			} );
		}

		if ( search ) { search.addEventListener( 'input', filterLanguages ); }
		if ( selectedOnly ) { selectedOnly.addEventListener( 'change', filterLanguages ); }

		boxes.forEach( function ( box ) {
			box.addEventListener( 'change', refresh );
		} );

		refresh();
	} );
}() );
