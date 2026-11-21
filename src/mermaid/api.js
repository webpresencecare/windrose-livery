/**
 * Style Mermaid: requests to this website (REST routes of windrose-livery/v1 and the download).
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * A REST request under the plugin's root.
 *
 * @param {Object} config  App config (root).
 * @param {string} path    Path after the root.
 * @param {Object} options apiFetch options.
 * @return {Promise<unknown>} Answer.
 */
export function call( config, path, options = {} ) {
	return apiFetch( { path: `${ config.root }${ path }`, ...options } );
}

/**
 * Downloads the style as a zip (the server builds it; nothing is stored).
 *
 * @param {Object} config App config (download address).
 * @param {Object} recipe Recipe.
 * @return {Promise<Object>} { ok: true } or { ok: false, message, checks }.
 */
export async function download( config, recipe ) {
	const body = new window.FormData();
	body.append( 'recipe', JSON.stringify( recipe ) );
	let response;
	try {
		response = await window.fetch( config.download, {
			method: 'POST',
			body,
			credentials: 'same-origin',
		} );
	} catch ( error ) {
		return { ok: false, message: error.message };
	}
	const type = response.headers.get( 'Content-Type' ) || '';
	if ( response.ok && type.includes( 'zip' ) ) {
		const blob = await response.blob();
		const name =
			( response.headers.get( 'Content-Disposition' ) || '' ).match(
				/filename="([^"]+)"/
			)?.[ 1 ] || 'style-variation.zip';
		const url = window.URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = name;
		document.body.appendChild( link );
		link.click();
		link.remove();
		window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
		return { ok: true, name };
	}
	try {
		const json = await response.json();
		return {
			ok: false,
			message:
				json?.data?.message ||
				__( 'The download failed.', 'windrose-livery' ),
			checks: json?.data?.checks,
		};
	} catch {
		return {
			ok: false,
			message: __( 'The download failed.', 'windrose-livery' ),
		};
	}
}

/**
 * Adds CSS (font faces) to the page once.
 *
 * @param {string} id  Element id.
 * @param {string} css CSS.
 */
export function addCss( id, css ) {
	if ( ! css || document.getElementById( id ) ) {
		return;
	}
	const style = document.createElement( 'style' );
	style.id = id;
	style.textContent = css;
	document.head.appendChild( style );
}
