/**
 * Style Mermaid: the preview, a sample page in a frame with the stylesheet WordPress makes for Ipsum and this style
 * (built by the server, POST /mermaid/preview), the font faces (served from this website) and core's block styles.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import PatternBrowser, { RECIPE } from './patterns';
import { __ } from '@wordpress/i18n';
import { call } from './api';

const escape = ( text ) =>
	String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );

/**
 * A picture for the sample image: a gradient in the style's soft background and accent (an SVG data address, so the
 * frame loads nothing from elsewhere).
 *
 * @param {Object} palette Slug => color.
 * @return {string} Address.
 */
function picture( palette ) {
	const safe = ( c, fallback ) =>
		/^#[0-9a-f]{3,8}$/i.test( c ?? '' ) ? c : fallback;
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 60"><defs><linearGradient id="g" x2="1" y2="1"><stop offset="0" stop-color="${ safe(
		palette[ 'accent-5' ],
		'#eeeeee'
	) }"/><stop offset="1" stop-color="${ safe(
		palette[ 'accent-1' ],
		'#3355ff'
	) }"/></linearGradient></defs><rect width="160" height="60" fill="url(#g)"/></svg>`;
	return `data:image/svg+xml,${ encodeURIComponent( svg ) }`;
}

/**
 * The sample page's body.
 *
 * @param {Object[]} samples Script samples: { lang, dir, text, label } (the text carries its language).
 * @param {Object}   palette Slug => color.
 * @return {string} HTML.
 */
function sampleHtml( samples, palette = {} ) {
	const t = ( s ) => escape( s );
	const scripts = samples
		.map(
			( s ) =>
				`<p class="mermaid-script" dir="${ s.dir }"><span class="mermaid-script-label">${ t(
					s.label
				) }</span> <span lang="${ t( s.lang ?? '' ) }">${ t(
					s.text
				) }</span></p>`
		)
		.join( '' );
	return `
<div class="wp-site-blocks">
<header class="wp-block-group alignfull has-global-padding is-layout-constrained" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);border-bottom:1px solid var(--wp--preset--color--accent-6)">
<div class="wp-block-group alignwide is-layout-flex" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1em">
<p class="wp-block-site-title"><a href="#top">${ t( __( 'Harbour Lights', 'windrose-livery' ) ) }</a></p>
<nav class="wp-block-navigation is-layout-flex wp-block-navigation-is-layout-flex" aria-label="${ t( __( 'Sample menu', 'windrose-livery' ) ) }"><ul class="wp-block-navigation__container wp-block-navigation is-layout-flex" style="list-style:none;margin:0;padding:0">
<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="#top">${ t( __( 'Home', 'windrose-livery' ) ) }</a></li>
<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="#top">${ t( __( 'About', 'windrose-livery' ) ) }</a></li>
<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="#top">${ t( __( 'Contact', 'windrose-livery' ) ) }</a></li>
</ul></nav>
</div>
</header>
<main class="wp-block-group has-global-padding is-layout-constrained" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60)">
<h1 class="wp-block-heading">${ t( __( 'A heading for your page', 'windrose-livery' ) ) }</h1>
<p>${ t( __( 'This is body text. It shows how paragraphs read with these fonts and colors,', 'windrose-livery' ) ) } <a href="#top">${ t( __( 'with a link', 'windrose-livery' ) ) }</a> ${ t( __( 'and some more words to fill the line.', 'windrose-livery' ) ) }</p>
<div class="wp-block-buttons is-layout-flex" style="display:flex;flex-wrap:wrap;gap:0.75em">
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#top">${ t( __( 'Get started', 'windrose-livery' ) ) }</a></div>
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#top">${ t( __( 'Learn more', 'windrose-livery' ) ) }</a></div>
</div>
<h2 class="wp-block-heading">${ t( __( 'A second-level heading', 'windrose-livery' ) ) }</h2>
<p class="wp-block-post-date"><time>${ t( __( 'October 27, 2026', 'windrose-livery' ) ) }</time></p>
<div class="wp-block-group has-accent-5-background-color has-background is-layout-constrained" style="padding:var(--wp--preset--spacing--40)">
<h3 class="wp-block-heading">${ t( __( 'A card', 'windrose-livery' ) ) }</h3>
<p>${ t( __( 'Text on Surface, as in cards, quotes and code.', 'windrose-livery' ) ) }</p>
</div>
<blockquote class="wp-block-quote"><p>${ t( __( 'A quotation stands out from the text around it.', 'windrose-livery' ) ) }</p><cite>${ t( __( 'Someone wise', 'windrose-livery' ) ) }</cite></blockquote>
<ul class="wp-block-list"><li>${ t( __( 'A first item', 'windrose-livery' ) ) }</li><li>${ t( __( 'A second item', 'windrose-livery' ) ) }</li><li>${ t( __( 'A third item', 'windrose-livery' ) ) }</li></ul>
<figure class="wp-block-image size-large"><img src="${ picture( palette ) }" alt="" style="aspect-ratio:16/6;object-fit:cover;width:100%"><figcaption class="wp-element-caption">${ t( __( 'A caption under a picture (here a gradient)', 'windrose-livery' ) ) }</figcaption></figure>
<hr class="wp-block-separator has-alpha-channel-opacity">
<figure class="wp-block-pullquote"><blockquote><p>${ t( __( 'A pullquote, for words worth repeating.', 'windrose-livery' ) ) }</p><cite>${ t( __( 'Someone quoted', 'windrose-livery' ) ) }</cite></blockquote></figure>
<pre class="wp-block-code"><code>${ t( 'const style = "yours";' ) }</code></pre>
<div class="wp-block-cover alignwide is-light" style="min-height:160px"><span aria-hidden="true" class="wp-block-cover__background has-accent-5-background-color has-background-dim-100 has-background-dim"></span><div class="wp-block-cover__inner-container is-layout-constrained"><p class="has-text-align-center">${ t( __( 'A cover with text over it', 'windrose-livery' ) ) }</p></div></div>
${ scripts }
</main>
</div>`;
}

/**
 * The preview frame.
 *
 * @param {Object}   props
 * @param {Object}   props.config     App config.
 * @param {Object}   props.recipe     Recipe.
 * @param {Object[]} props.samples    Script samples.
 * @param {string}   props.twinScheme The twins' scheme, dark or light ('' without twins).
 * @param {string}   props.focus      A block to scroll to (CSS selector), or ''.
 * @param {Object[]} props.sites      Sites for the patterns (Livery's screen; on a public page, this site).
 * @param {Object[]} props.library    Stored styles (Livery's screen), offered next to the one being made.
 */
export default function Preview( {
	config,
	recipe,
	samples,
	twinScheme = '',
	focus = '',
	sites = null,
	library = [],
} ) {
	// What the frame shows: the sample page, or the site's patterns (0.6).
	const [ mode, setMode ] = useState( 'page' );
	const [ patternStyle, setPatternStyle ] = useState( RECIPE );
	const patternStyles = [
		{
			value: RECIPE,
			label: __( 'The style you are making', 'windrose-livery' ),
		},
		...( 'admin' === config.mode ? library : [] ).map( ( v ) => ( {
			value: `${ v.scope ?? 0 }:${ v.id }`,
			label: v.title,
			id: v.id,
			scope: v.scope ?? 0,
			theme: v.theme,
			scheme: v.scheme,
		} ) ),
	];
	const stored = patternStyles.find( ( s ) => s.value === patternStyle );
	const scheme =
		'patterns' === mode && stored && RECIPE !== stored.value
			? ( stored.scheme ?? '' )
			: twinScheme;
	const patternSites =
		sites ?? ( config.patterns?.site ? [ config.patterns.site ] : [] );
	const [ result, setResult ] = useState( null );
	// Which colours show: the style's own, or its twins (when it has them).
	const [ showTwins, setShowTwins ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ width, setWidth ] = useState( 'desktop' );
	const timer = useRef( 0 );
	const frame = useRef( null );
	const json = JSON.stringify( recipe );

	// Scrolls the frame to the focused block (after each load too, since srcDoc reloads the page). Only the frame's
	// own page moves: scrollIntoView() would also scroll the admin page to the frame, away from the control in use.
	const scroll = useCallback( () => {
		if ( ! focus ) {
			return;
		}
		try {
			const win = frame.current?.contentWindow;
			const target =
				frame.current?.contentDocument?.querySelector( focus );
			if ( win && target ) {
				const top =
					target.getBoundingClientRect().top +
					win.scrollY -
					win.innerHeight / 3;
				win.scrollTo( { top: Math.max( 0, top ) } );
			}
		} catch {
			// The frame is still loading.
		}
	}, [ focus ] );
	useEffect( scroll, [ scroll ] );

	useEffect( () => {
		// The sample page only (the patterns ask the site themselves).
		if ( 'page' !== mode ) {
			return;
		}
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( async () => {
			setBusy( true );
			try {
				const answer = await call( config, '/mermaid/preview', {
					method: 'POST',
					data: { recipe: json },
				} );
				setResult( answer );
				setError( '' );
			} catch ( e ) {
				setError( e.message );
			}
			setBusy( false );
		}, 350 );
		return () => window.clearTimeout( timer.current );
	}, [ config, json, mode ] );

	const doc = result
		? `<!doctype html><html lang="${ escape(
				document.documentElement.lang || 'en'
			) }"${
				twinScheme && showTwins
					? ` data-windrose-livery-scheme="${ escape( twinScheme ) }"`
					: ''
			}><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${ escape(
				config.blockStyles
			) }"><style>${ result.fonts }</style><style>${
				result.css
			}</style><style>body{margin:0}.mermaid-script{margin:0.5em 0}.mermaid-script-label{font-size:0.75em;opacity:0.8;font-family:var(--wp--preset--font-family--manrope),sans-serif}</style></head><body>${ sampleHtml(
				samples,
				Object.fromEntries(
					( recipe?.settings?.color?.palette ?? [] ).map( ( c ) => [
						c.slug,
						c.color,
					] )
				)
			) }</body></html>`
		: '';

	return (
		<div className="windrose-livery-mermaid__preview">
			<div className="windrose-livery-mermaid__preview-bar">
				<span className="windrose-livery-mermaid__preview-title">
					{ __( 'Preview', 'windrose-livery' ) }
				</span>
				{ busy && 'page' === mode && <Spinner /> }
				<span
					className="windrose-livery-mermaid__preview-modes"
					role="group"
					aria-label={ __(
						'What the preview shows',
						'windrose-livery'
					) }
				>
					{ [
						[ 'page', __( 'Sample page', 'windrose-livery' ) ],
						[ 'patterns', __( 'Patterns', 'windrose-livery' ) ],
					].map( ( [ id, label ] ) => (
						<button
							key={ id }
							type="button"
							className="windrose-livery-mermaid__chip"
							aria-pressed={ mode === id }
							onClick={ () => setMode( id ) }
						>
							{ label }
						</button>
					) ) }
				</span>
				{ scheme && (
					<button
						type="button"
						className="windrose-livery-mermaid__chip"
						aria-pressed={ showTwins }
						onClick={ () => setShowTwins( ! showTwins ) }
					>
						{ __( 'Dark/light switch', 'windrose-livery' ) }
					</button>
				) }
				<span className="windrose-livery-mermaid__preview-widths">
					{ [
						[ 'desktop', __( 'Wide', 'windrose-livery' ) ],
						[ 'phone', __( 'Phone', 'windrose-livery' ) ],
					].map( ( [ id, label ] ) => (
						<button
							key={ id }
							type="button"
							className="windrose-livery-mermaid__chip"
							aria-pressed={ width === id }
							onClick={ () => setWidth( id ) }
						>
							{ label }
						</button>
					) ) }
				</span>
			</div>
			{ 'patterns' === mode && (
				<PatternBrowser
					sites={ patternSites }
					styles={ patternStyles }
					style={ patternStyle }
					onStyle={ setPatternStyle }
					recipe={ json }
					nonce={ config.patterns?.nonce ?? '' }
					width={ width }
					scheme={ scheme && showTwins ? scheme : '' }
				/>
			) }
			{ 'page' === mode && error && (
				<p className="windrose-livery-mermaid__error" role="alert">
					{ error }
				</p>
			) }
			{ 'page' === mode && doc && (
				<iframe
					ref={ frame }
					onLoad={ scroll }
					title={ __( 'Preview of the style', 'windrose-livery' ) }
					className={ `windrose-livery-mermaid__frame is-${ width }` }
					srcDoc={ doc }
					sandbox="allow-same-origin"
				/>
			) }
		</div>
	);
}
