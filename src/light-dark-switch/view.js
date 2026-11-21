/**
 * Windrose Livery, light and dark switch on the page (a script module, Interactivity API). The visitor's click sets
 * the scheme on <html> and keeps it in this browser only (localStorage, no cookie, nothing sent). The head script
 * (`Schemes::script()`, inline, before the page shows) applies a kept choice; this module keeps the buttons'
 * `aria-pressed` in step with what the page shows.
 *
 * Server data (`Schemes::render()`): config `own`, `twin` (the style's own scheme and its twin), `follow` (the site
 * follows the browser preference), `storage`, `attribute`; state `dark` (whether the page shows dark).
 */
import { getConfig, store } from '@wordpress/interactivity';

const NAMESPACE = 'windrose-livery/light-dark-switch';

/**
 * Whether the browser prefers a scheme.
 *
 * @param {string} scheme `dark` or `light`.
 * @return {boolean} Whether it does.
 */
function prefers( scheme ) {
	return window.matchMedia( `(prefers-color-scheme: ${ scheme })` ).matches;
}

/**
 * The scheme the page shows: the visitor's choice, else the twin when the site follows the browser and the browser
 * prefers it, else the style's own.
 *
 * @return {string} `dark` or `light`.
 */
function shown() {
	const { own, twin, follow, attribute } = getConfig( NAMESPACE );
	const chosen = document.documentElement.getAttribute( attribute );
	if ( 'dark' === chosen || 'light' === chosen ) {
		return chosen;
	}
	if ( follow && prefers( twin ) ) {
		return twin;
	}
	return own;
}

/**
 * Puts the buttons in step with the scheme the page shows.
 */
function sync() {
	state.dark = 'dark' === shown();
}

const { state } = store( NAMESPACE, {
	actions: {
		toggle() {
			const { storage, attribute } = getConfig( NAMESPACE );
			const next = 'dark' === shown() ? 'light' : 'dark';
			document.documentElement.setAttribute( attribute, next );
			try {
				window.localStorage.setItem( storage, next );
			} catch {}
			state.dark = 'dark' === next;
		},
	},
	callbacks: {
		// The head script may have applied a kept choice, or the browser may prefer the twin.
		sync,
	},
} );

window
	.matchMedia( '(prefers-color-scheme: dark)' )
	.addEventListener( 'change', sync );
