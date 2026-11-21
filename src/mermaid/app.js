/**
 * Style Mermaid: the app (Livery's screen and the public block share it; config.mode says which).
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { addCss, call, download } from './api';
import { checkContrast } from './contrast.js';
import {
	findTwins,
	isTwin,
	makeTwins,
	twinChecks as getTwinChecks,
	twinSettings,
	withoutTwins,
} from './twins.js';
import { languageNames, scriptNames, stepNames } from './labels';
import Preview from './preview';
import { BLOCK_STYLES } from './blocks.js';
import { clone, fromStyle, paletteMap, setAt, toRecipe } from './recipe.js';
import { ROLES, isRole } from './roles.js';

// Local storage key read by pattern libraries (Windrose Knotboard): { title, recipe, at }.
const LIBRARY_KEY = 'windrose-livery-recipe';
import { FontsStep, StartStep } from './step-fonts';
import { ColorsStep, fixedColor } from './step-colors';
import { BlocksStep } from './step-blocks';
import { FinishStep, LayoutStep, TypographyStep } from './step-more';

/**
 * Style Mermaid.
 *
 * @param {Object}                   props
 * @param {Object}                   props.config  { mode, root, download, remote, ipsum, blockStyles }.
 * @param {Object[]}                 props.library The library's variations (admin screen).
 * @param {(value: unknown) => void} props.onSaved Called after adding to the library (admin screen).
 * @param {(value: unknown) => void} props.onClose Back to the list (admin screen).
 * @param {Object[]|null}            props.sites   Sites (admin screen) for the patterns; null on a public page.
 */
export default function MermaidApp( {
	config,
	library = [],
	onSaved,
	onClose,
	sites = null,
} ) {
	const [ catalog, setCatalog ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ state, setState ] = useState( null );
	const [ step, setStep ] = useState( 'start' );
	const [ remote, setRemote ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState( null );
	// The preview scrolls to this block (a CSS selector), set by the Blocks step.
	const [ focus, setFocus ] = useState( '' );
	const heading = useRef( null );

	useEffect( () => {
		call( config, '/mermaid/catalog' )
			.then( ( answer ) => {
				addCss( 'windrose-livery-mermaid-fonts', answer.css );
				( answer.packs ?? [] ).forEach( ( p ) =>
					addCss(
						`windrose-livery-mermaid-pack-${ p.script }`,
						p.css
					)
				);
				setCatalog( answer );
				setState( fromStyle( answer.styles[ 0 ] ) );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ config ] );

	const recipe = useMemo(
		() =>
			state
				? toRecipe( state, __( 'My style', 'windrose-livery' ) )
				: null,
		[ state ]
	);
	const checks = useMemo(
		() => ( recipe ? checkContrast( recipe.settings, recipe.styles ) : [] ),
		[ recipe ]
	);
	const twinCheckList = useMemo(
		() =>
			recipe ? getTwinChecks( recipe.settings, recipe.styles ) : null,
		[ recipe ]
	);
	const twins = useMemo(
		() => findTwins( recipe?.settings?.color?.palette ?? [] ),
		[ recipe ]
	);

	// The site's pattern library (Windrose Knotboard) shows its patterns in the style being made: the recipe goes to
	// this browser's local storage (never to the server, never a cookie), the library reads it on the same address,
	// and it follows every later change once the visitor has opened the library.
	const linked = useRef( false );
	const keepRecipe = useCallback( () => {
		if ( ! recipe ) {
			return;
		}
		try {
			window.localStorage.setItem(
				LIBRARY_KEY,
				JSON.stringify( {
					title:
						( state?.title ?? '' ).trim() ||
						__( 'My style', 'windrose-livery' ),
					recipe,
					at: Date.now(),
				} )
			);
		} catch {}
	}, [ recipe, state ] );
	const seePatterns = useCallback( () => {
		keepRecipe();
		linked.current = true;
		window.open(
			config.patternLibrary + '#windrose-livery-recipe',
			'_blank',
			'noopener'
		);
	}, [ keepRecipe, config ] );
	useEffect( () => {
		if ( ! linked.current ) {
			return undefined;
		}
		const timer = window.setTimeout( keepRecipe, 400 );
		return () => window.clearTimeout( timer );
	}, [ keepRecipe ] );

	const installPack = useCallback(
		async ( script ) => {
			const packs = await call( config, `/mermaid/packs/${ script }`, {
				method: 'POST',
			} );
			packs.forEach( ( p ) =>
				addCss( `windrose-livery-mermaid-pack-${ p.script }`, p.css )
			);
			setCatalog( ( c ) => ( { ...c, packs } ) );
			speak( __( 'The font pack is installed.', 'windrose-livery' ) );
		},
		[ config ]
	);

	const update = useCallback( ( fn ) => {
		setMessage( null );
		setState( ( s ) => fn( s ) );
	}, [] );
	const setStyle = useCallback(
		( path, value ) =>
			update( ( s ) => ( {
				...s,
				styles: setAt( s.styles, path, value ),
			} ) ),
		[ update ]
	);
	const setSettings = useCallback(
		( path, value ) =>
			update( ( s ) => ( {
				...s,
				settings: setAt( s.settings, path, value ),
			} ) ),
		[ update ]
	);
	const setPalette = ( palette ) =>
		setSettings( [ 'color', 'palette' ], palette );
	const setColor = ( slug, changes ) =>
		update( ( s ) => ( {
			...s,
			settings: setAt(
				s.settings,
				[ 'color', 'palette' ],
				( s.settings?.color?.palette ?? [] ).map( ( c ) =>
					c.slug === slug ? { ...c, ...changes } : c
				)
			),
		} ) );
	const setFonts = ( changes ) =>
		update( ( s ) => ( {
			...s,
			fonts: { ...s.fonts, ...changes },
			missing: changes.heading || changes.text ? {} : s.missing,
		} ) );

	const pickStyle = ( style ) => {
		update( ( s ) => ( {
			...fromStyle( style ),
			title: s.title,
			description: s.description,
			fonts: { ...fromStyle( style ).fonts, scripts: s.fonts.scripts },
		} ) );
		speak(
			sprintf(
				/* translators: %s: style name. */
				__( '%s chosen. The preview is updated.', 'windrose-livery' ),
				style.title
			)
		);
	};

	const pickPalette = ( item ) => {
		update( ( s ) => {
			// Twins belong to the colours they were made from: the palette's own twins, or none.
			const extras = ( s.settings?.color?.palette ?? [] ).filter(
				( c ) => ! isRole( c.slug ) && ! isTwin( c )
			);
			const own = item.palette.filter(
				( c ) => ! isRole( c.slug ) && ! isTwin( c )
			);
			let settings = setAt(
				s.settings,
				[ 'color', 'palette' ],
				clone( [
					...ROLES.map( ( slug ) =>
						item.palette.find( ( c ) => c.slug === slug )
					).filter( Boolean ),
					...( own.length ? own : extras ),
					...item.palette.filter( isTwin ),
				] )
			);
			if ( item.duotone?.length ) {
				settings = setAt(
					settings,
					[ 'color', 'duotone' ],
					clone( item.duotone )
				);
			}
			settings = setAt(
				settings,
				[ 'custom', 'controlScheme' ],
				item.controlScheme || undefined
			);
			const blocks =
				'accent' === item.buttons
					? {
							...s.blocks,
							button: {
								...( s.blocks?.button ??
									BLOCK_STYLES.button.defaults ),
								color: 'accent',
							},
						}
					: s.blocks;
			return { ...s, settings, blocks };
		} );
		speak(
			sprintf(
				/* translators: %s: palette name. */
				__( 'Palette %s applied.', 'windrose-livery' ),
				item.title
			)
		);
	};

	const onFix = ( check ) => {
		const palette = paletteMap( state.settings );
		const color = fixedColor( check, palette );
		const name =
			( state.settings?.color?.palette ?? [] ).find(
				( c ) => c.slug === check.slug
			)?.name ?? check.slug;
		setColor( check.slug, { color } );
		speak(
			sprintf(
				/* translators: 1: color name, 2: new color value. */
				__( '%1$s changed to %2$s.', 'windrose-livery' ),
				name,
				color
			)
		);
	};

	const addTwins = () => {
		const made = makeTwins( state.settings, recipe.styles );
		setPalette( made.palette );
		speak(
			'dark' === made.scheme
				? __(
						'Dark palette added. Its colours are checked for contrast.',
						'windrose-livery'
					)
				: __(
						'Light palette added. Its colours are checked for contrast.',
						'windrose-livery'
					)
		);
	};
	const removeTwins = () => {
		setPalette( withoutTwins( state.settings?.color?.palette ) );
		speak( __( 'The other palette is removed.', 'windrose-livery' ) );
	};
	const onFixTwin = ( check ) => {
		const twinSlug = twins.map[ check.slug ];
		if ( ! twinSlug ) {
			return;
		}
		const color = fixedColor(
			check,
			paletteMap( twinSettings( state.settings ) ?? state.settings )
		);
		setColor( twinSlug, { color } );
		speak(
			sprintf(
				/* translators: 1: color name, 2: new color value. */
				__( '%1$s changed to %2$s.', 'windrose-livery' ),
				( state.settings?.color?.palette ?? [] ).find(
					( c ) => c.slug === twinSlug
				)?.name ?? twinSlug,
				color
			)
		);
	};

	const addRemote = ( font ) =>
		setRemote( ( list ) => [
			...list.filter( ( f ) => f.key !== font.key ),
			font,
		] );

	const goTo = ( id ) => {
		setStep( id );
		setFocus( '' );
		window.requestAnimationFrame( () => heading.current?.focus() );
	};

	const onDownload = async () => {
		setBusy( true );
		const result = await download( config, recipe );
		setBusy( false );
		const text = result.ok
			? sprintf(
					/* translators: %s: file name. */
					__(
						'%s downloaded. Import it in Windrose Livery (Style variations, Import).',
						'windrose-livery'
					),
					result.name
				)
			: result.message;
		setMessage( { status: result.ok ? 'success' : 'error', text } );
		speak( text, result.ok ? 'polite' : 'assertive' );
	};

	const onSave = async ( replace, use = false ) => {
		setBusy( true );
		try {
			const saved = await call( config, '/mermaid/save', {
				method: 'POST',
				data: {
					recipe: JSON.stringify( recipe ),
					dry_run: false,
					replace,
					apply: use,
				},
			} );
			setBusy( false );
			let text = sprintf(
				/* translators: 1: variation title, 2: version. */
				__(
					'%1$s added to the library (version %2$d). Sites on Ipsum or its child themes see it in the Site Editor, Styles, Browse styles.',
					'windrose-livery'
				),
				saved.title,
				saved.version
			);
			if ( config.scope > 0 ) {
				const applied = ( saved.applied ?? [] ).find(
					( r ) => 'applied' === r.status || 'filtered' === r.status
				);
				text = applied
					? sprintf(
							/* translators: %s: variation title. */
							__(
								'%s added to this site’s styles and now used on this site. Change it further in the Site Editor, Styles.',
								'windrose-livery'
							),
							saved.title
						)
					: sprintf(
							/* translators: %s: variation title. */
							__(
								'%s added to this site’s styles. Pick it in the Site Editor, Styles, Browse styles.',
								'windrose-livery'
							),
							saved.title
						);
				// A failure, or what WordPress removed from an applied style ('filtered').
				const note = ( saved.applied ?? [] ).find(
					( r ) => 'applied' !== r.status
				);
				if ( use && note?.message ) {
					text += ' ' + note.message;
				}
			}
			setMessage( { status: 'success', text } );
			speak( text );
			onSaved?.( saved );
		} catch ( e ) {
			setBusy( false );
			setMessage( { status: 'error', text: e.message } );
			speak( e.message, 'assertive' );
		}
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! catalog || ! state ) {
		return <Spinner />;
	}

	const steps = stepNames();
	const index = steps.findIndex( ( s ) => s.id === step );
	const names = scriptNames();
	const languages = languageNames();
	const samples = catalog.scripts
		.filter( ( s ) => state.fonts.scripts?.[ s.slug ] || s.system )
		.flatMap( ( s ) => [
			{
				dir: s.dir,
				lang: s.lang,
				text: s.sample,
				label: names[ s.slug ] ?? s.slug,
			},
			...( s.languages ?? [] )
				.filter(
					( l ) =>
						state.fonts.scripts?.[ s.slug ]?.languages?.[ l.tag ]
				)
				.map( ( l ) => ( {
					dir: s.dir,
					lang: l.tag,
					text: l.sample,
					label: languages[ l.tag ] ?? l.tag,
				} ) ),
		] );
	const title = ( state.title || '' ).trim();
	const exists =
		'admin' === config.mode &&
		library.some(
			( v ) =>
				'ipsum' === v.theme &&
				v.title.toLowerCase() ===
					(
						title || __( 'My style', 'windrose-livery' )
					).toLowerCase()
		);

	return (
		<div className="windrose-livery-mermaid">
			{ ! config.ipsum && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Style Mermaid makes styles for the Ipsum theme, which is not installed here: the preview cannot work until it is.',
						'windrose-livery'
					) }
				</Notice>
			) }
			<nav
				className="windrose-livery-mermaid__steps"
				aria-label={ __( 'Steps', 'windrose-livery' ) }
			>
				<ol>
					{ steps.map( ( s, i ) => (
						<li key={ s.id }>
							<button
								type="button"
								aria-current={
									s.id === step ? 'step' : undefined
								}
								onClick={ () => goTo( s.id ) }
							>
								<span className="windrose-livery-mermaid__step-number">
									{ i + 1 }
								</span>
								{ s.label }
							</button>
						</li>
					) ) }
				</ol>
				{ onClose && (
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Back to the list', 'windrose-livery' ) }
					</Button>
				) }
			</nav>
			<div className="windrose-livery-mermaid__body">
				<section
					className="windrose-livery-mermaid__panel"
					aria-labelledby="windrose-livery-mermaid-step"
				>
					<h2
						id="windrose-livery-mermaid-step"
						ref={ heading }
						tabIndex="-1"
						className="windrose-livery-mermaid__title"
					>
						{ steps[ index ].label }
					</h2>
					{ 'start' === step && (
						<StartStep
							catalog={ catalog }
							state={ state }
							onPick={ pickStyle }
						/>
					) }
					{ 'fonts' === step && (
						<FontsStep
							config={ config }
							catalog={ catalog }
							state={ state }
							setFonts={ setFonts }
							remote={ remote }
							addRemote={ addRemote }
							installPack={ installPack }
						/>
					) }
					{ 'colors' === step && (
						<ColorsStep
							catalog={ catalog }
							library={ library }
							state={ state }
							checks={ checks }
							twinChecks={ twinCheckList }
							twinScheme={ twins.scheme }
							onAddTwins={ addTwins }
							onRemoveTwins={ removeTwins }
							onFixTwin={ onFixTwin }
							pickPalette={ pickPalette }
							setColor={ setColor }
							setPalette={ setPalette }
							setStyle={ setStyle }
							setSettings={ setSettings }
							onFix={ onFix }
						/>
					) }
					{ 'typography' === step && (
						<TypographyStep state={ state } setStyle={ setStyle } />
					) }
					{ 'layout' === step && (
						<LayoutStep
							state={ state }
							setStyle={ setStyle }
							setSettings={ setSettings }
						/>
					) }
					{ 'blocks' === step && (
						<BlocksStep
							state={ state }
							setBlock={ ( id, value ) =>
								update( ( s ) => {
									const blocks = { ...s.blocks };
									if ( value ) {
										blocks[ id ] = value;
									} else {
										delete blocks[ id ];
									}
									return { ...s, blocks };
								} )
							}
							onOpen={ setFocus }
							checks={ checks }
							onFix={ onFix }
						/>
					) }
					{ 'finish' === step && (
						<FinishStep
							config={ config }
							state={ state }
							setText={ ( key, value ) =>
								update( ( s ) => ( { ...s, [ key ]: value } ) )
							}
							checks={ checks }
							onFix={ onFix }
							onDownload={ onDownload }
							onSave={ onSave }
							busy={ busy }
							message={ message }
							exists={ exists }
							twinChecks={ twinCheckList }
							twinScheme={ twins.scheme }
							onFixTwin={ onFixTwin }
						/>
					) }
					<div className="windrose-livery-mermaid__nav">
						{ config.patternLibrary && recipe && (
							<Button
								__next40pxDefaultSize
								variant="link"
								className="windrose-livery-mermaid__library"
								onClick={ seePatterns }
							>
								{ __(
									'See the patterns in this style',
									'windrose-livery'
								) }
								<span className="windrose-livery-mermaid__new">
									{ __(
										'(opens in a new tab)',
										'windrose-livery'
									) }
								</span>
							</Button>
						) }
						{ index > 0 && (
							<Button
								__next40pxDefaultSize
								variant="secondary"
								onClick={ () => goTo( steps[ index - 1 ].id ) }
							>
								{ __( 'Previous', 'windrose-livery' ) }
							</Button>
						) }
						{ index < steps.length - 1 && (
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ () => goTo( steps[ index + 1 ].id ) }
							>
								{ __( 'Next', 'windrose-livery' ) }
							</Button>
						) }
					</div>
				</section>
				{ config.ipsum && (
					<Preview
						config={ config }
						recipe={ recipe }
						samples={ samples }
						twinScheme={ twins.scheme }
						focus={ focus }
						sites={ sites }
						library={ library }
					/>
				) }
			</div>
		</div>
	);
}
