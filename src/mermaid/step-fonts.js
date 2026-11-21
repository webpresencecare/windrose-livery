/**
 * Style Mermaid: the Start step (ready styles) and the Fonts step (pairs, heading and text fonts, labels font, fonts
 * from Google Fonts or Bunny Fonts, fonts for other scripts from installed font packs or those services).
 */
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Notice,
	RangeControl,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { addCss, call } from './api';
import { categoryNames, languageNames, scriptNames } from './labels';

const SERIF = /serif|ruhl|amiri|naskh|tiro|lora|garamond/i;

/**
 * Font info for a choice (bundled or fetched).
 *
 * @param {Object}   ref   Choice.
 * @param {Object[]} fonts Bundled fonts.
 * @return {Object} { name, generic, covers }.
 */
export function fontInfo( ref, fonts ) {
	if ( 'remote' === ref?.source || 'library' === ref?.source ) {
		return {
			name: ref.name,
			generic: ref.generic || 'sans-serif',
			covers: ref.covers ?? [],
		};
	}
	const font = fonts.find( ( f ) => f.slug === ref?.slug );
	return font
		? { name: font.name, generic: font.generic, covers: font.covers }
		: { name: ref?.slug ?? '', generic: 'sans-serif', covers: [] };
}

const sameRef = ( a, b ) => {
	if ( a?.source !== b?.source ) {
		return false;
	}
	if ( 'remote' === a?.source ) {
		return a.key === b?.key;
	}
	return 'library' === a?.source ? a.id === b?.id : a?.slug === b?.slug;
};

/**
 * Choices for the fonts installed in the Font Library (the server names a family like a bundled one "<slug>-library").
 *
 * @param {Object[]} library Font Library families.
 * @param {Object[]} fonts   Bundled fonts.
 * @return {Object[]} Choices.
 */
export const libraryRefs = ( library, fonts ) =>
	( library ?? [] ).map( ( f ) => ( {
		source: 'library',
		id: f.id,
		slug: fonts.some( ( b ) => b.slug === f.slug )
			? `${ f.slug }-library`
			: f.slug,
		name: f.name,
		generic: f.generic,
		covers: [],
	} ) );

/**
 * Swatches of a palette.
 *
 * @param {Object}   props
 * @param {Object[]} props.palette Colors.
 */
export function MiniSwatches( { palette } ) {
	return (
		<span className="windrose-livery-mermaid__swatches">
			{ ( palette ?? [] ).slice( 0, 8 ).map( ( c ) => (
				<span
					key={ c.slug }
					className="windrose-livery-mermaid__swatch"
					style={ { background: c.color } }
					title={ `${ c.name }: ${ c.color }` }
				/>
			) ) }
			<span className="screen-reader-text">
				{ ( palette ?? [] )
					.map( ( c ) => `${ c.name } ${ c.color }` )
					.join( ', ' ) }
			</span>
		</span>
	);
}

/**
 * Start: the ready styles.
 *
 * @param {Object}                   props
 * @param {Object}                   props.catalog Catalog.
 * @param {Object}                   props.state   State.
 * @param {(value: unknown) => void} props.onPick  Picks a ready style.
 */
export function StartStep( { catalog, state, onPick } ) {
	const fontName = ( slug ) =>
		catalog.fonts.find( ( f ) => f.slug === slug )?.name ?? slug;
	return (
		<div>
			<p>
				{ __(
					'Start from a ready style, then change its fonts, colors, typography, layout and buttons. The preview shows each change.',
					'windrose-livery'
				) }
			</p>
			<ul className="windrose-livery-mermaid__cards">
				{ catalog.styles.map( ( style ) => {
					const heading = fontName( style.fonts.heading );
					const text = fontName( style.fonts.text );
					const current = state.base === style.slug;
					return (
						<li key={ style.slug }>
							<button
								type="button"
								className="windrose-livery-mermaid__card"
								aria-pressed={ current }
								onClick={ () => onPick( style ) }
							>
								<span
									className="windrose-livery-mermaid__card-title"
									style={ {
										fontFamily: `"${ heading }", sans-serif`,
									} }
								>
									{ style.title }
								</span>
								<MiniSwatches
									palette={ style.settings?.color?.palette }
								/>
								<span className="windrose-livery-mermaid__muted">
									{ heading === text
										? heading
										: sprintf(
												/* translators: 1: heading font, 2: text font. */
												__(
													'%1$s and %2$s',
													'windrose-livery'
												),
												heading,
												text
											) }
								</span>
								<span className="windrose-livery-mermaid__card-text">
									{ style.description }
								</span>
							</button>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

/**
 * A list of fonts to choose from, each name shown in its font.
 *
 * @param {Object}                   props
 * @param {string}                   props.label    Label.
 * @param {Object}                   props.value    Chosen font.
 * @param {(value: unknown) => void} props.onChange Called with the new choice.
 * @param {Object[]}                 props.fonts    Bundled fonts.
 * @param {Object[]}                 props.remote   Fetched fonts.
 * @param {Object[]}                 props.library  Font Library fonts (choices).
 * @param {string}                   props.name     Radio group name.
 */
function FontPicker( {
	label,
	value,
	onChange,
	fonts,
	remote,
	library = [],
	name,
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const cats = categoryNames();
	const all = [
		...fonts.map( ( f ) => ( {
			ref: { source: 'bundled', slug: f.slug },
			name: f.name,
			generic: f.generic,
			category: f.category,
		} ) ),
		...library.map( ( r ) => ( {
			ref: r,
			name: r.name,
			generic: r.generic,
			category: 'library',
		} ) ),
		...remote.map( ( r ) => ( {
			ref: r,
			name: r.name,
			generic: r.generic,
			category: 'remote',
		} ) ),
	];
	const shown = all.filter(
		( f ) =>
			( ! category || f.category === category ) &&
			f.name.toLowerCase().includes( search.trim().toLowerCase() )
	);
	return (
		<fieldset className="windrose-livery-mermaid__fieldset">
			<legend className="windrose-livery-mermaid__legend">
				{ label }
			</legend>
			<div className="windrose-livery-mermaid__row">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search fonts', 'windrose-livery' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Kind', 'windrose-livery' ) }
					hideLabelFromVision
					value={ category }
					options={ [
						{
							value: '',
							label: __( 'All kinds', 'windrose-livery' ),
						},
						...Object.entries( cats )
							.filter(
								( [ id ] ) =>
									( 'remote' !== id || remote.length ) &&
									( 'library' !== id || library.length )
							)
							.map( ( [ id, text ] ) => ( {
								value: id,
								label: text,
							} ) ),
					] }
					onChange={ setCategory }
				/>
			</div>
			<div className="windrose-livery-mermaid__fontlist">
				{ shown.map( ( f ) => (
					<label
						key={ f.ref.key ?? f.ref.id ?? f.ref.slug }
						className="windrose-livery-mermaid__font"
						htmlFor={ `${ name }-${
							f.ref.key ?? f.ref.id ?? f.ref.slug
						}` }
					>
						<input
							id={ `${ name }-${
								f.ref.key ?? f.ref.id ?? f.ref.slug
							}` }
							type="radio"
							name={ name }
							checked={ sameRef( f.ref, value ) }
							onChange={ () => onChange( f.ref ) }
						/>
						<span
							style={ {
								fontFamily: `"${ f.name }", ${ f.generic }`,
							} }
						>
							{ f.name }
						</span>
						{ 'remote' === f.category && (
							<span className="windrose-livery-mermaid__muted">
								{ ' ' }
								{ 'google' === f.ref.provider
									? 'Google Fonts'
									: 'Bunny Fonts' }
							</span>
						) }
						{ 'library' === f.category && (
							<span className="windrose-livery-mermaid__muted">
								{ ' ' }
								{ __( 'Font Library', 'windrose-livery' ) }
							</span>
						) }
					</label>
				) ) }
				{ ! shown.length && (
					<p className="windrose-livery-mermaid__muted">
						{ __( 'No font found.', 'windrose-livery' ) }
					</p>
				) }
			</div>
		</fieldset>
	);
}

/**
 * Gets a font from Google Fonts or Bunny Fonts through this website.
 *
 * @param {Object} config  App config.
 * @param {Object} request { provider, family, weights, italic, script, generic }.
 * @return {Promise<Object>} Font choice.
 */
export async function fetchFont( config, request ) {
	const answer = await call( config, '/mermaid/font', {
		method: 'POST',
		data: {
			provider: request.provider,
			family: request.family,
			weights: request.weights ?? [ 400, 700 ],
			italic: !! request.italic,
			script: request.script ?? '',
		},
	} );
	addCss( `windrose-livery-mermaid-font-${ answer.key }`, answer.css );
	return {
		source: 'remote',
		key: answer.key,
		slug: answer.slug,
		name: answer.name,
		provider: answer.provider,
		covers: answer.covers,
		generic:
			request.generic ||
			( SERIF.test( answer.name ) && ! /sans/i.test( answer.name )
				? 'serif'
				: 'sans-serif' ),
	};
}

/**
 * What happened to a font just fetched.
 *
 * @param {string} use  heading, text, both or none.
 * @param {string} name Font name.
 * @param {string} from Google Fonts or Bunny Fonts.
 * @return {string} Message.
 */
function usedMessage( use, name, from ) {
	switch ( use ) {
		case 'heading':
			return sprintf(
				/* translators: 1: font name, 2: Google Fonts or Bunny Fonts. */
				__(
					'%1$s (from %2$s) is now the heading font.',
					'windrose-livery'
				),
				name,
				from
			);
		case 'text':
			return sprintf(
				/* translators: 1: font name, 2: Google Fonts or Bunny Fonts. */
				__(
					'%1$s (from %2$s) is now the text font.',
					'windrose-livery'
				),
				name,
				from
			);
		case 'both':
			return sprintf(
				/* translators: 1: font name, 2: Google Fonts or Bunny Fonts. */
				__(
					'%1$s (from %2$s) is now the heading and text font.',
					'windrose-livery'
				),
				name,
				from
			);
		default:
			return sprintf(
				/* translators: 1: font name, 2: Google Fonts or Bunny Fonts. */
				__(
					'%1$s (from %2$s) is ready: choose it in the lists above, under the kind “From Google Fonts or Bunny Fonts”.',
					'windrose-livery'
				),
				name,
				from
			);
	}
}

/**
 * The form to get a font from Google Fonts or Bunny Fonts.
 *
 * @param {Object}                   props
 * @param {Object}                   props.config  App config.
 * @param {(value: unknown) => void} props.onFont  Called with the fetched font.
 * @param {string}                   props.initial Family to fill in.
 */
function RemoteFontForm( { config, onFont, initial = '' } ) {
	const [ provider, setProvider ] = useState( 'bunny' );
	const [ family, setFamily ] = useState( initial );
	const [ weights, setWeights ] = useState( [ 400, 700 ] );
	const [ italic, setItalic ] = useState( true );
	const [ generic, setGeneric ] = useState( '' );
	const [ use, setUse ] = useState( 'heading' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( '' );
	const toggleWeight = ( w, on ) =>
		setWeights( ( list ) =>
			on
				? [ ...new Set( [ ...list, w ] ) ].sort()
				: list.filter( ( x ) => x !== w )
		);
	const submit = async () => {
		setBusy( true );
		setError( '' );
		setDone( '' );
		try {
			const font = await fetchFont( config, {
				provider,
				family,
				weights,
				italic,
				generic,
			} );
			onFont( font, use );
			setFamily( '' );
			const from =
				'google' === font.provider ? 'Google Fonts' : 'Bunny Fonts';
			setDone( usedMessage( use, font.name, from ) );
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};
	return (
		<details className="windrose-livery-mermaid__details">
			<summary>
				{ __(
					'Get a font from Google Fonts or Bunny Fonts',
					'windrose-livery'
				) }
			</summary>
			<p className="windrose-livery-mermaid__muted">
				{ __(
					'This website downloads the font once and serves it itself: visitors’ browsers never contact Google or Bunny. Find names on https://fonts.google.com or https://fonts.bunny.net.',
					'windrose-livery'
				) }
			</p>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'From', 'windrose-livery' ) }
				value={ provider }
				options={ [
					{ value: 'bunny', label: 'Bunny Fonts' },
					{ value: 'google', label: 'Google Fonts' },
				] }
				onChange={ setProvider }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Font name or address', 'windrose-livery' ) }
				help={ __(
					'For example “Merriweather”, or the address of the font’s page, such as https://fonts.bunny.net/family/merriweather or https://fonts.google.com/specimen/Merriweather (an address chooses its service).',
					'windrose-livery'
				) }
				value={ family }
				onChange={ setFamily }
			/>
			<fieldset className="windrose-livery-mermaid__weights">
				<legend>{ __( 'Weights', 'windrose-livery' ) }</legend>
				{ [ 300, 400, 500, 600, 700, 800 ].map( ( w ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ w }
						label={ String( w ) }
						checked={ weights.includes( w ) }
						onChange={ ( on ) => toggleWeight( w, on ) }
					/>
				) ) }
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Italic too', 'windrose-livery' ) }
					checked={ italic }
					onChange={ setItalic }
				/>
			</fieldset>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Kind of font', 'windrose-livery' ) }
				value={ generic }
				options={ [
					{ value: '', label: __( 'Guess', 'windrose-livery' ) },
					{
						value: 'sans-serif',
						label: __( 'Sans serif', 'windrose-livery' ),
					},
					{ value: 'serif', label: __( 'Serif', 'windrose-livery' ) },
					{
						value: 'monospace',
						label: __( 'Monospace', 'windrose-livery' ),
					},
				] }
				onChange={ setGeneric }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Use it for', 'windrose-livery' ) }
				value={ use }
				options={ [
					{
						value: 'heading',
						label: __( 'Headings', 'windrose-livery' ),
					},
					{ value: 'text', label: __( 'Text', 'windrose-livery' ) },
					{
						value: 'both',
						label: __( 'Headings and text', 'windrose-livery' ),
					},
					{
						value: 'none',
						label: __(
							'Only add it to the lists',
							'windrose-livery'
						),
					},
				] }
				onChange={ setUse }
			/>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={ submit }
				disabled={ busy || ! family.trim() || ! weights.length }
				accessibleWhenDisabled
				isBusy={ busy }
			>
				{ __( 'Get the font', 'windrose-livery' ) }
			</Button>
			{ error && (
				<p className="windrose-livery-mermaid__error" role="alert">
					{ error }
				</p>
			) }
			<p className="windrose-livery-mermaid__done" role="status">
				{ done }
			</p>
		</details>
	);
}

/**
 * The ref of a font in an installed pack, or null.
 *
 * @param {Object} pack Pack (catalog.packs entry).
 * @param {string} name Font name.
 * @return {Object|null} Font choice.
 */
function packRef( pack, name ) {
	const family = pack?.installed
		? pack.families.find( ( f ) => f.name === name )
		: null;
	return family
		? {
				source: 'pack',
				pack: pack.script,
				slug: family.slug,
				name: family.name,
				generic: family.generic,
			}
		: null;
}

/**
 * The size a font gets next to Latin text by default (measured for the packs), in percent.
 *
 * @param {Object[]} packs Packs.
 * @param {string}   name  Font name.
 * @return {number} Percent.
 */
export function defaultSize( packs, name ) {
	for ( const pack of packs ?? [] ) {
		const family = pack.families.find( ( f ) => f.name === name );
		if ( family ) {
			return parseInt( family.sizeAdjust, 10 ) || 100;
		}
	}
	return 100;
}

/**
 * Fonts for one script: a checkbox, then a pair, fonts for its languages and the size next to Latin text.
 *
 * @param {Object}                   props
 * @param {Object}                   props.config    App config.
 * @param {Object}                   props.script    Script.
 * @param {Object}                   props.pack      Its font pack, if any.
 * @param {Object}                   props.chosen    The chosen pair, if any.
 * @param {string}                   props.provider  Provider (when there is no installed pack).
 * @param {(value: unknown) => void} props.onChange  Called with the new pair (or null).
 * @param {(value: unknown) => void} props.onInstall Installs the pack (managers), resolves when done.
 */
function ScriptFonts( {
	config,
	script,
	pack,
	chosen,
	provider,
	onChange,
	onInstall,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const names = scriptNames();
	const languages = languageNames();
	const installed = !! pack?.installed;
	const usable = installed || config.remote;
	const get = ( family ) =>
		packRef( pack, family ) ??
		fetchFont( config, {
			provider,
			family,
			weights: [ 400, 500, 600, 700 ],
			script: script.slug,
		} );
	const run = async ( fn ) => {
		setBusy( true );
		setError( '' );
		try {
			await fn();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};
	const choose = ( index ) =>
		run( async () => {
			const pair = script.pairs[ index ];
			const heading = await get( pair.heading );
			const text =
				pair.text === pair.heading ? heading : await get( pair.text );
			onChange( {
				pair: index,
				heading,
				text,
				size: defaultSize( [ pack ], pair.text ),
				languages: chosen?.languages ?? {},
			} );
		} );
	const chooseLanguage = ( tag, family ) =>
		run( async () => {
			const next = { ...( chosen.languages ?? {} ) };
			if ( family ) {
				next[ tag ] = await get( family );
			} else {
				delete next[ tag ];
			}
			onChange( { ...chosen, languages: next } );
		} );
	const label = names[ script.slug ] ?? script.slug;
	return (
		<div className="windrose-livery-mermaid__script">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ !! chosen || busy }
				disabled={ busy || ( ! usable && ! chosen ) }
				help={
					! usable && ! chosen
						? __(
								'Not available here: its font pack is not installed.',
								'windrose-livery'
							)
						: undefined
				}
				onChange={ ( on ) => ( on ? choose( 0 ) : onChange( null ) ) }
			/>
			{ ! installed && config.manager && pack && (
				<Button
					__next40pxDefaultSize
					className="windrose-livery-mermaid__install"
					variant="secondary"
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ () =>
						run( async () => {
							await onInstall( script.slug );
						} )
					}
				>
					{ sprintf(
						/* translators: 1: script name, 2: size. */
						__(
							'Install the font pack for %1$s (%2$s)',
							'windrose-livery'
						),
						label,
						packSize( pack.bytes )
					) }
				</Button>
			) }
			{ chosen && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Heading and text fonts', 'windrose-livery' ) }
					value={ String( chosen.pair ?? 0 ) }
					disabled={ busy }
					options={ script.pairs.map( ( p, i ) => ( {
						value: String( i ),
						label:
							p.heading === p.text
								? p.heading
								: sprintf(
										/* translators: 1: heading font, 2: text font. */
										__(
											'%1$s and %2$s',
											'windrose-livery'
										),
										p.heading,
										p.text
									),
					} ) ) }
					onChange={ ( v ) => choose( Number( v ) ) }
				/>
			) }
			{ chosen && (
				<p
					className="windrose-livery-mermaid__script-sample"
					dir={ script.dir }
					lang={ script.lang }
					style={ {
						fontFamily: `"${ chosen.heading.name }", sans-serif`,
					} }
				>
					{ script.sample }
				</p>
			) }
			{ chosen && (
				<RangeControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Size next to Latin text', 'windrose-livery' ) }
					help={ __(
						'In percent: some scripts look small next to Latin letters of the same size. Applies wherever these fonts are used.',
						'windrose-livery'
					) }
					min={ 80 }
					max={ 150 }
					step={ 5 }
					value={ chosen.size ?? 100 }
					onChange={ ( v ) =>
						onChange( { ...chosen, size: Number( v ) || 100 } )
					}
				/>
			) }
			{ chosen &&
				( script.languages ?? [] ).map( ( language ) => (
					<SelectControl
						key={ language.tag }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %s: language name. */
							__( 'Font for %s', 'windrose-livery' ),
							languages[ language.tag ] ?? language.tag
						) }
						help={ __(
							'Used only on pages (and parts of pages) in that language.',
							'windrose-livery'
						) }
						value={ chosen.languages?.[ language.tag ]?.name ?? '' }
						disabled={ busy }
						options={ [
							{
								value: '',
								label: __(
									'The pair above',
									'windrose-livery'
								),
							},
							...language.fonts.map( ( f ) => ( {
								value: f,
								label: f,
							} ) ),
						] }
						onChange={ ( v ) => chooseLanguage( language.tag, v ) }
					/>
				) ) }
			{ busy && <Spinner /> }
			{ error && (
				<p className="windrose-livery-mermaid__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}

/**
 * A size in MB or KB.
 *
 * @param {number} bytes Bytes.
 * @return {string} Text.
 */
function packSize( bytes ) {
	return bytes >= 1048576
		? sprintf(
				/* translators: %s: size in megabytes. */
				__( '%s MB', 'windrose-livery' ),
				( bytes / 1048576 ).toFixed( 1 )
			)
		: sprintf(
				/* translators: %s: size in kilobytes. */
				__( '%s KB', 'windrose-livery' ),
				Math.max( 1, Math.round( bytes / 1024 ) )
			);
}

/**
 * The Fonts step.
 *
 * @param {Object}                   props
 * @param {Object}                   props.config      App config.
 * @param {Object}                   props.catalog     Catalog.
 * @param {Object}                   props.state       State.
 * @param {(value: unknown) => void} props.setFonts    Changes the font choices.
 * @param {Object[]}                 props.remote      Fetched fonts.
 * @param {(value: unknown) => void} props.addRemote   Adds a fetched font.
 * @param {(value: unknown) => void} props.installPack Installs a script's font pack (managers).
 */
export function FontsStep( {
	config,
	catalog,
	state,
	setFonts,
	remote,
	addRemote,
	installPack,
} ) {
	const [ provider, setProvider ] = useState( 'bunny' );
	const packs = catalog.packs ?? [];
	const fonts = catalog.fonts;
	const library = useMemo(
		() => libraryRefs( catalog.library, catalog.fonts ),
		[ catalog ]
	);
	const pairs = useMemo( () => {
		const seen = new Set();
		return catalog.styles
			.map( ( s ) => s.fonts )
			.filter( ( f ) => {
				const key = `${ f.heading }|${ f.text }`;
				if ( seen.has( key ) ) {
					return false;
				}
				seen.add( key );
				return true;
			} );
	}, [ catalog ] );
	const name = ( slug ) =>
		fonts.find( ( f ) => f.slug === slug )?.name ?? slug;
	const heading = fontInfo( state.fonts.heading, fonts );
	const text = fontInfo( state.fonts.text, fonts );
	const lacksCyrillic =
		! heading.covers.includes( 'cyrillic' ) ||
		! text.covers.includes( 'cyrillic' );
	const names = scriptNames();
	const missing = state.missing?.heading || state.missing?.text;

	return (
		<div>
			{ missing && (
				<Notice status="info" isDismissible={ false }>
					{ config.remote
						? sprintf(
								/* translators: 1: font name, 2: font used instead. */
								__(
									'This style was made with %1$s, which is not in this plugin: %2$s is used instead. You can get %1$s from Google Fonts or Bunny Fonts below.',
									'windrose-livery'
								),
								missing.name,
								name( missing.fallback )
							)
						: sprintf(
								/* translators: 1: font name, 2: font used instead. */
								__(
									'This style was made with %1$s, which is not in this plugin: %2$s is used instead.',
									'windrose-livery'
								),
								missing.name,
								name( missing.fallback )
							) }
					{ ! config.remote &&
						'admin' === config.mode &&
						' ' +
							sprintf(
								/* translators: %s: font name. */
								__(
									'Install %s in the Site Editor’s Font Library, then pick it here.',
									'windrose-livery'
								),
								missing.name
							) }
				</Notice>
			) }
			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Pairs from the ready styles', 'windrose-livery' ) }
			</h3>
			<div className="windrose-livery-mermaid__chips">
				{ pairs.map( ( p ) => (
					<button
						type="button"
						key={ `${ p.heading }|${ p.text }` }
						className="windrose-livery-mermaid__chip"
						aria-pressed={
							state.fonts.heading?.slug === p.heading &&
							state.fonts.text?.slug === p.text
						}
						onClick={ () =>
							setFonts( {
								heading: { source: 'bundled', slug: p.heading },
								text: { source: 'bundled', slug: p.text },
							} )
						}
					>
						<span
							style={ { fontFamily: `"${ name( p.heading ) }"` } }
						>
							{ name( p.heading ) }
						</span>
						{ p.heading !== p.text && (
							<>
								{ ' + ' }
								<span
									style={ {
										fontFamily: `"${ name( p.text ) }"`,
									} }
								>
									{ name( p.text ) }
								</span>
							</>
						) }
					</button>
				) ) }
			</div>
			<div className="windrose-livery-mermaid__two">
				<FontPicker
					name="windrose-livery-mermaid-heading"
					label={ __( 'Heading font', 'windrose-livery' ) }
					value={ state.fonts.heading }
					onChange={ ( ref ) => setFonts( { heading: ref } ) }
					fonts={ fonts }
					remote={ remote }
					library={ library }
				/>
				<FontPicker
					name="windrose-livery-mermaid-text"
					label={ __( 'Text font', 'windrose-livery' ) }
					value={ state.fonts.text }
					onChange={ ( ref ) => setFonts( { text: ref } ) }
					fonts={ fonts }
					remote={ remote }
					library={ library }
				/>
			</div>
			{ 'admin' === config.mode && (
				<p className="windrose-livery-mermaid__muted">
					{ __(
						'More fonts: install them in the Site Editor (Styles, Typography, Fonts, Install Fonts or Upload); they then appear here under "In the Font Library".',
						'windrose-livery'
					) }
					{ config.fontsUrl && (
						<>
							{ ' ' }
							<ExternalLink href={ config.fontsUrl }>
								{ __(
									'Fonts in the Site Editor',
									'windrose-livery'
								) }
							</ExternalLink>
						</>
					) }
				</p>
			) }
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Labels font', 'windrose-livery' ) }
				help={ __(
					'Menus, buttons, dates, categories, pagination and quote sources.',
					'windrose-livery'
				) }
				value={ state.fonts.labels }
				options={ [
					{
						value: 'manrope',
						label: __( 'Manrope (as in Ipsum)', 'windrose-livery' ),
					},
					{
						value: 'heading',
						label: __( 'The heading font', 'windrose-livery' ),
					},
					{
						value: 'text',
						label: __( 'The text font', 'windrose-livery' ),
					},
				] }
				onChange={ ( labels ) => setFonts( { labels } ) }
			/>
			{ config.remote ? (
				<RemoteFontForm
					key={ missing?.name ?? 'form' }
					config={ config }
					initial={ missing?.name ?? '' }
					onFont={ ( font, use ) => {
						addRemote( font );
						if ( 'none' !== use ) {
							setFonts( {
								...( 'text' !== use ? { heading: font } : {} ),
								...( 'heading' !== use ? { text: font } : {} ),
							} );
						}
					} }
				/>
			) : null }

			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Other scripts', 'windrose-livery' ) }
			</h3>
			<p className="windrose-livery-mermaid__muted">
				{ __(
					'One style can serve every language: each letter comes from the first font of the list that has it, and a browser downloads a script’s font only when a page holds that script.',
					'windrose-livery'
				) }
			</p>
			{ config.remote &&
				catalog.scripts.some(
					( s ) =>
						! s.system &&
						! packs.find( ( p ) => p.script === s.slug )?.installed
				) && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Get script fonts without a pack from',
							'windrose-livery'
						) }
						value={ provider }
						options={ [
							{ value: 'bunny', label: 'Bunny Fonts' },
							{ value: 'google', label: 'Google Fonts' },
						] }
						onChange={ setProvider }
					/>
				) }
			{ ! config.remote && ! packs.some( ( p ) => p.installed ) && (
				<p>
					{ config.manager
						? __(
								'Install a font pack for a script below (or on Livery’s screen, Font packs).',
								'windrose-livery'
							)
						: __(
								'This site offers no fonts for other scripts here: other scripts use the visitor’s own fonts.',
								'windrose-livery'
							) }
				</p>
			) }
			{ catalog.scripts
				.filter( ( s ) => ! s.system )
				.filter( ( s ) => ! s.onlyMissing || lacksCyrillic )
				.filter(
					( s ) =>
						config.manager ||
						config.remote ||
						packs.find( ( p ) => p.script === s.slug )?.installed
				)
				.map( ( s ) => (
					<ScriptFonts
						key={ s.slug }
						config={ config }
						script={ s }
						pack={ packs.find( ( p ) => p.script === s.slug ) }
						provider={ provider }
						chosen={ state.fonts.scripts?.[ s.slug ] }
						onInstall={ installPack }
						onChange={ ( pair ) =>
							setFonts( {
								scripts: {
									...( state.fonts.scripts ?? {} ),
									[ s.slug ]: pair ?? undefined,
								},
							} )
						}
					/>
				) ) }
			<p className="windrose-livery-mermaid__muted">
				{ sprintf(
					/* translators: %s: list of scripts. */
					__(
						'%s use the visitor’s own fonts (every current computer and phone has them).',
						'windrose-livery'
					),
					catalog.scripts
						.filter( ( s ) => s.system )
						.map( ( s ) => names[ s.slug ] ?? s.slug )
						.join( ', ' )
				) }
			</p>
		</div>
	);
}
