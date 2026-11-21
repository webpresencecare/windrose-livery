/**
 * The pattern browser (0.6): a site's registered patterns with a style applied, rendered by WordPress itself on that
 * site (POST <home>/?windrose_livery_patterns=1, class Patterns), shown in a sandboxed frame without scripts.
 * Used by Style Mermaid's preview (the style being made, or a stored one) and by Livery's screen (Browse patterns).
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import {
	Button,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export const RECIPE = 'recipe';
const IPSUM = 'ipsum';

/**
 * Whether a style can be shown on a site: Style Mermaid's on Ipsum and its child themes; a stored one on the theme it
 * was made for and its child themes, and a site's own style on that site only. Only sites at this address (the screen
 * sends its login with the request).
 *
 * @param {Object} site  Site row: id, url, stylesheet, template.
 * @param {Object} style Style option: { value, theme, scope }.
 * @return {boolean} Whether it fits.
 */
export function siteFits( site, style ) {
	let here = false;
	try {
		here = new window.URL( site.url ).host === window.location.host;
	} catch {
		here = false;
	}
	if ( ! here || ! style ) {
		return false;
	}
	if ( RECIPE === style.value ) {
		return IPSUM === site.template || IPSUM === site.stylesheet;
	}
	if ( style.scope > 0 && style.scope !== site.id ) {
		return false;
	}
	return style.theme === site.stylesheet || style.theme === site.template;
}

/**
 * The browser: site, style and category choices, the frame, and the list of patterns to jump to.
 *
 * @param {Object}                  props
 * @param {Object[]}                props.sites   Site rows (id, name, url, render, rest, stylesheet, template).
 * @param {Object[]}                props.styles  Style options: { value, label, theme, scope, id }.
 * @param {string}                  props.style   Chosen style option value.
 * @param {(value: string) => void} props.onStyle Called with a style option value.
 * @param {string}                  props.recipe  Style Mermaid's recipe (JSON), for the RECIPE option.
 * @param {string}                  props.nonce   Nonce for administrators ('' for visitors).
 * @param {string}                  props.width   'desktop' or 'phone'.
 * @param {string}                  props.scheme  The other palette to show ('dark', 'light') or ''.
 * @param {string}                  props.layout  'compact' (a select to jump) or 'side' (a list beside the frame).
 */
export default function PatternBrowser( {
	sites,
	styles,
	style,
	onStyle,
	recipe = '',
	nonce = '',
	width = 'desktop',
	scheme = '',
	layout = 'compact',
} ) {
	const current = styles.find( ( s ) => s.value === style ) ?? styles[ 0 ];
	const fitting = useMemo(
		() => sites.filter( ( site ) => siteFits( site, current ) ),
		[ sites, current ]
	);
	const [ siteId, setSiteId ] = useState( fitting[ 0 ]?.id ?? 0 );
	const site =
		fitting.find( ( s ) => s.id === siteId ) ?? fitting[ 0 ] ?? null;
	const [ list, setList ] = useState( null );
	const [ category, setCategory ] = useState( '' );
	const [ sample, setSample ] = useState( false );
	const [ html, setHtml ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const frame = useRef( null );
	const timer = useRef( 0 );
	// Where the frame was scrolled, kept when only the style changes (the page reloads with each change).
	const keep = useRef( { key: '', top: 0 } );
	const admin = '' !== nonce;

	// Plain values for the effects (the option objects are made again on every render).
	const rest = site?.rest ?? '';
	const render = site?.render ?? '';
	const value = current?.value ?? '';
	const styleId = current?.id ?? 0;
	const styleScope = current?.scope ?? 0;
	const made = RECIPE === value ? recipe : '';

	// The site's patterns.
	useEffect( () => {
		if ( ! rest ) {
			return;
		}
		let live = true;
		setList( null );
		apiFetch( { url: rest } )
			.then( ( answer ) => live && setList( answer ) )
			.catch( ( e ) => live && setError( e.message ) );
		return () => {
			live = false;
		};
	}, [ rest ] );

	const key = `${ render }|${ value }|${ category }|${ sample }`;
	useEffect( () => {
		if ( ! render || ! value ) {
			return;
		}
		const body = new window.FormData();
		if ( RECIPE === value ) {
			body.append( 'recipe', made );
		} else {
			body.append( 'style', String( styleId ) );
			body.append( 'scope', String( styleScope ) );
		}
		body.append( 'category', category );
		if ( admin ) {
			body.append( '_wpnonce', nonce );
			if ( sample ) {
				body.append( 'sample', '1' );
			}
		}
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( async () => {
			try {
				keep.current = {
					key,
					top: frame.current?.contentWindow?.scrollY ?? 0,
					was: keep.current.key,
				};
			} catch {
				keep.current = { key, top: 0 };
			}
			setBusy( true );
			try {
				const response = await window.fetch( render, {
					method: 'POST',
					body,
					credentials: 'same-origin',
				} );
				const text = await response.text();
				if ( response.ok ) {
					setHtml( text );
					setError( '' );
				} else {
					let message = __(
						'The patterns could not be shown.',
						'windrose-livery'
					);
					try {
						message = JSON.parse( text )?.data?.message || message;
					} catch {}
					setError( message );
				}
			} catch ( e ) {
				setError( e.message );
			}
			setBusy( false );
		}, 400 );
		return () => window.clearTimeout( timer.current );
	}, [
		render,
		value,
		styleId,
		styleScope,
		made,
		category,
		sample,
		admin,
		nonce,
		key,
	] );

	// The other palette: the same switch as on a page (the attribute the style's rules wait for).
	const paint = useCallback( () => {
		try {
			const root = frame.current?.contentDocument?.documentElement;
			if ( root ) {
				if ( scheme ) {
					root.setAttribute( 'data-windrose-livery-scheme', scheme );
				} else {
					root.removeAttribute( 'data-windrose-livery-scheme' );
				}
			}
		} catch {}
	}, [ scheme ] );
	useEffect( paint, [ paint ] );

	const onLoad = () => {
		paint();
		try {
			if ( keep.current.key === keep.current.was ) {
				frame.current.contentWindow.scrollTo( {
					top: keep.current.top,
				} );
			}
		} catch {}
	};

	const jump = ( name ) => {
		try {
			const win = frame.current?.contentWindow;
			const target = frame.current?.contentDocument?.querySelector(
				`[data-windrose-livery-pattern="${ window.CSS.escape( name ) }"]`
			);
			if ( win && target ) {
				win.scrollTo( {
					top: target.getBoundingClientRect().top + win.scrollY,
				} );
			}
		} catch {}
	};

	const groups = list?.groups ?? [];
	const shown = category
		? ( groups.find( ( g ) => g.key === category )?.patterns ?? [] )
		: [
				...new Map(
					groups
						.flatMap( ( g ) => g.patterns )
						.map( ( p ) => [ p.name, p ] )
				).values(),
			];

	if ( ! site ) {
		return (
			<p className="windrose-livery-patterns__note">
				{ RECIPE === current?.value
					? __(
							'Patterns show on a site that uses the Ipsum theme or one of its child themes, at this address. No site here does.',
							'windrose-livery'
						)
					: __(
							'Patterns show on a site that uses the theme this style was made for (or a child theme), at this address. No site here does.',
							'windrose-livery'
						) }
			</p>
		);
	}

	return (
		<div className={ `windrose-livery-patterns is-${ layout }` }>
			<div className="windrose-livery-patterns__controls">
				{ fitting.length > 1 && (
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Site', 'windrose-livery' ) }
						value={ String( site.id ) }
						options={ fitting.map( ( s ) => ( {
							value: String( s.id ),
							label: s.name,
						} ) ) }
						onChange={ ( v ) => {
							setSiteId( Number( v ) );
							setCategory( '' );
						} }
					/>
				) }
				{ styles.length > 1 && (
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Style', 'windrose-livery' ) }
						value={ current.value }
						options={ styles.map( ( s ) => ( {
							value: s.value,
							label: s.label,
						} ) ) }
						onChange={ onStyle }
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Category', 'windrose-livery' ) }
					value={ category }
					options={ [
						{
							value: '',
							label: __( 'All patterns', 'windrose-livery' ),
						},
						...groups.map( ( g ) => ( {
							value: g.key,
							label: sprintf(
								/* translators: 1: category name, 2: number of patterns. */
								__( '%1$s (%2$d)', 'windrose-livery' ),
								g.label,
								g.patterns.length
							),
						} ) ),
					] }
					onChange={ setCategory }
				/>
				{ 'compact' === layout && shown.length > 1 && (
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Go to a pattern', 'windrose-livery' ) }
						value=""
						options={ [
							{ value: '', label: '—' },
							...shown.map( ( p ) => ( {
								value: p.name,
								label: p.title,
							} ) ),
						] }
						onChange={ ( v ) => v && jump( v ) }
					/>
				) }
				{ admin && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Sample content', 'windrose-livery' ) }
						help={ __(
							'What visitors see: sample text, menu and posts instead of this site’s.',
							'windrose-livery'
						) }
						checked={ sample }
						onChange={ setSample }
					/>
				) }
				{ busy && <Spinner /> }
			</div>
			{ list?.visitor && (
				<p className="windrose-livery-patterns__note">
					{ __(
						'The patterns of the Ipsum theme and of WordPress, with sample content: what a site shows with this style before adding its own.',
						'windrose-livery'
					) }
				</p>
			) }
			{ error && (
				<p className="windrose-livery-mermaid__error" role="alert">
					{ error }
				</p>
			) }
			<div className="windrose-livery-patterns__body">
				{ 'side' === layout && shown.length > 0 && (
					<nav
						className="windrose-livery-patterns__list"
						aria-label={ __( 'Patterns', 'windrose-livery' ) }
					>
						<ul>
							{ shown.map( ( p ) => (
								<li key={ p.name }>
									<Button
										variant="link"
										onClick={ () => jump( p.name ) }
									>
										{ p.title }
									</Button>
								</li>
							) ) }
						</ul>
					</nav>
				) }
				{ html && (
					<iframe
						ref={ frame }
						onLoad={ onLoad }
						title={ __(
							'Patterns in the chosen style',
							'windrose-livery'
						) }
						className={ `windrose-livery-mermaid__frame is-${ width }` }
						srcDoc={ html }
						sandbox="allow-same-origin"
					/>
				) }
			</div>
		</div>
	);
}
