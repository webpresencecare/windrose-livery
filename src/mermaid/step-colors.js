/**
 * Style Mermaid: the Colors step. Browse every preset palette (ready styles, Ipsum's presets, the library's
 * variations in the admin screen, moods from an accent), filter them by accent color, then edit the colors as in the
 * Site Editor: the palette, the colors of text, links, headings and captions, gradients and the duotone filter. The
 * contrast list (WCAG 2.2) says what passes and offers a fix.
 */
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	ColorIndicator,
	ColorPicker,
	Dropdown,
	DuotonePicker,
	GradientPicker,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { accentNear, fixContrast } from './color.js';
import { checkNames, moodNames, roleHelp, roleNames } from './labels';
import { ROLES, isRole } from './roles.js';
import { moods } from './moods.js';
import { IPSUM_GRADIENTS, paletteMap } from './recipe.js';
import { isTwin, ownScheme } from './twins.js';
import { MiniSwatches } from './step-fonts';

const MAX_EXTRA = 4;
const MAX_GRADIENTS = 8;

/**
 * A color swatch that opens a color picker.
 *
 * @param {Object}                   props
 * @param {string}                   props.label    Accessible label.
 * @param {string}                   props.color    Color.
 * @param {(value: unknown) => void} props.onChange Called with the new color.
 * @param {boolean}                  props.alpha    Whether transparency may be set.
 */
export function ColorButton( { label, color, onChange, alpha = false } ) {
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					className="windrose-livery-mermaid__colorbutton"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					label={ sprintf(
						/* translators: 1: color name, 2: color value. */
						__( 'Change %1$s (%2$s)', 'windrose-livery' ),
						label,
						color
					) }
					showTooltip
				>
					<ColorIndicator colorValue={ color } />
				</Button>
			) }
			renderContent={ () => (
				<ColorPicker
					color={ color }
					onChange={ onChange }
					enableAlpha={ alpha }
					defaultValue={ color }
				/>
			) }
		/>
	);
}

/**
 * The contrast checks, each with its ratio and, when it fails, a fix.
 *
 * @param {Object}                   props
 * @param {Object[]}                 props.checks Checks.
 * @param {(value: unknown) => void} props.onFix  Called with a failing check.
 * @param {string[]}                 props.only   Check ids to show (all when empty).
 */
export function ContrastList( { checks, onFix, only = [] } ) {
	const names = checkNames();
	const shown = only.length
		? checks.filter( ( c ) => only.includes( c.id ) )
		: checks;
	return (
		<ul className="windrose-livery-mermaid__checks">
			{ shown.map( ( c ) => {
				let status = __( 'passes', 'windrose-livery' );
				let kind = 'pass';
				if ( ! c.pass ) {
					kind = c.warning ? 'warning' : 'fail';
					status = c.warning
						? __( 'decoration only', 'windrose-livery' )
						: __( 'too low', 'windrose-livery' );
				}
				return (
					<li key={ c.id } className={ `is-${ kind }` }>
						<span
							className="windrose-livery-mermaid__check-sample"
							style={ { color: c.fg, background: c.bg } }
							aria-hidden="true"
						>
							Aa
						</span>
						<span className="windrose-livery-mermaid__check-name">
							{ names[ c.id ] ?? c.id }
						</span>
						<span>
							{ sprintf(
								/* translators: 1: contrast ratio, 2: minimum ratio, 3: passes, too low... */
								__(
									'%1$s:1 (needs %2$s:1), %3$s',
									'windrose-livery'
								),
								c.ratio.toFixed( 2 ),
								String( c.min ),
								status
							) }
						</span>
						{ ! c.pass && c.slug && onFix && (
							<Button
								size="compact"
								variant="secondary"
								onClick={ () => onFix( c ) }
							>
								{ __( 'Fix', 'windrose-livery' ) }
							</Button>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * The color a check's fix gives.
 *
 * @param {Object} check   Check.
 * @param {Object} palette Slug => color.
 * @return {string} Color.
 */
export function fixedColor( check, palette ) {
	return fixContrast(
		palette[ check.slug ] ?? check.fg,
		check.bg,
		check.min
	);
}

/**
 * The palette browser.
 *
 * @param {Object}                   props
 * @param {Object}                   props.catalog Catalog.
 * @param {Object[]}                 props.library The library's variations (admin screen).
 * @param {Object}                   props.current The current palette (slug => color).
 * @param {(value: unknown) => void} props.onPick  Called with a palette item.
 */
function PaletteBrowser( { catalog, library, current, onPick } ) {
	const [ accent, setAccent ] = useState( '' );
	const names = roleNames();
	const moodLabels = moodNames();
	const items = useMemo( () => {
		const out = [];
		catalog.styles.forEach( ( s ) => {
			if ( s.settings?.color?.palette?.length ) {
				out.push( {
					id: `style-${ s.slug }`,
					title: s.title,
					source: __( 'Ready style', 'windrose-livery' ),
					palette: s.settings.color.palette,
					duotone: s.settings.color.duotone,
					controlScheme: s.settings.custom?.controlScheme,
				} );
			}
		} );
		catalog.palettes.forEach( ( p ) =>
			out.push( {
				id: p.slug,
				title: p.title,
				source: __( 'Ipsum', 'windrose-livery' ),
				palette: p.palette,
				duotone: p.duotone,
				controlScheme: p.controlScheme,
			} )
		);
		( library ?? [] ).forEach( ( v ) => {
			const slugs = ( v.palette ?? [] ).map( ( c ) => c.slug );
			if ( ROLES.every( ( slug ) => slugs.includes( slug ) ) ) {
				out.push( {
					id: `library-${ v.id }`,
					title: v.title,
					source: __( 'Your library', 'windrose-livery' ),
					palette: v.palette,
				} );
			}
		} );
		return out;
	}, [ catalog, library ] );
	const moodAccent = accent || current[ 'accent-1' ] || '#3355ff';
	const moodItems = useMemo(
		() =>
			moods( moodAccent, names ).map( ( m ) => ( {
				id: `mood-${ m.id }`,
				title: moodLabels[ m.id ],
				source: __( 'Mood', 'windrose-livery' ),
				palette: m.palette,
				controlScheme: m.controlScheme,
				buttons: m.buttons,
			} ) ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ moodAccent ]
	);
	const shown = accent
		? items.filter( ( i ) =>
				accentNear(
					i.palette.find( ( c ) => 'accent-1' === c.slug )?.color,
					accent
				)
			)
		: items;
	const card = ( item ) => (
		<li key={ item.id }>
			<button
				type="button"
				className="windrose-livery-mermaid__palette"
				onClick={ () => onPick( item ) }
			>
				<span className="windrose-livery-mermaid__palette-title">
					{ item.title }
				</span>
				<MiniSwatches palette={ item.palette } />
				<span className="windrose-livery-mermaid__muted">
					{ item.source }
				</span>
			</button>
		</li>
	);
	return (
		<div>
			<div className="windrose-livery-mermaid__row windrose-livery-mermaid__filter">
				<span>
					{ __( 'Filter by accent color', 'windrose-livery' ) }
				</span>
				<ColorButton
					label={ __( 'the accent to filter by', 'windrose-livery' ) }
					color={ accent || current[ 'accent-1' ] || '#3355ff' }
					onChange={ setAccent }
				/>
				{ accent && (
					<Button
						variant="tertiary"
						onClick={ () => setAccent( '' ) }
					>
						{ __( 'Reset filter', 'windrose-livery' ) }
					</Button>
				) }
			</div>
			<h4 className="windrose-livery-mermaid__h4">
				{ accent
					? __( 'Moods from this accent', 'windrose-livery' )
					: __( 'Moods from the current accent', 'windrose-livery' ) }
			</h4>
			<ul className="windrose-livery-mermaid__palettes">
				{ moodItems.map( card ) }
			</ul>
			<h4 className="windrose-livery-mermaid__h4">
				{ accent
					? sprintf(
							/* translators: %d: number of palettes. */
							__(
								'Palettes with a similar accent (%d)',
								'windrose-livery'
							),
							shown.length
						)
					: __( 'All palettes', 'windrose-livery' ) }
			</h4>
			<ul className="windrose-livery-mermaid__palettes">
				{ shown.map( card ) }
			</ul>
			{ ! shown.length && (
				<p className="windrose-livery-mermaid__muted">
					{ __(
						'No palette has an accent like this one: the moods above are made from it.',
						'windrose-livery'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * Colors of parts of the page (text, links, headings, captions): a palette color or the theme's own.
 *
 * @param {Object}                   props
 * @param {Object}                   props.state State.
 * @param {(value: unknown) => void} props.setAt Sets a styles value.
 */
function ElementColors( { state, setAt } ) {
	const palette = ( state.settings?.color?.palette ?? [] ).filter(
		( c ) => ! isTwin( c )
	);
	const rows = [
		[ [ 'color', 'background' ], __( 'Background', 'windrose-livery' ) ],
		[ [ 'color', 'text' ], __( 'Text', 'windrose-livery' ) ],
		[
			[ 'elements', 'link', 'color', 'text' ],
			__( 'Links', 'windrose-livery' ),
		],
		[
			[ 'elements', 'link', ':hover', 'color', 'text' ],
			__( 'Links on hover', 'windrose-livery' ),
		],
		[
			[ 'elements', 'heading', 'color', 'text' ],
			__( 'Headings', 'windrose-livery' ),
		],
		[
			[ 'elements', 'caption', 'color', 'text' ],
			__( 'Captions', 'windrose-livery' ),
		],
	];
	const get = ( path ) =>
		path.reduce(
			( n, k ) => ( n && 'object' === typeof n ? n[ k ] : undefined ),
			state.styles
		);
	return (
		<div className="windrose-livery-mermaid__grid">
			{ rows.map( ( [ path, label ] ) => {
				const value = get( path ) ?? '';
				const known =
					'' === value ||
					palette.some(
						( c ) => value === `var:preset|color|${ c.slug }`
					);
				return (
					<SelectControl
						key={ path.join( '.' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						value={ value }
						options={ [
							{
								value: '',
								label: __( 'Theme default', 'windrose-livery' ),
							},
							...palette.map( ( c ) => ( {
								value: `var:preset|color|${ c.slug }`,
								label: c.name,
							} ) ),
							...( known ? [] : [ { value, label: value } ] ),
						] }
						onChange={ ( v ) => setAt( path, v ) }
					/>
				);
			} ) }
		</div>
	);
}

/**
 * Turns var(--wp--preset--color--x) in a gradient into the color, so the gradient picker can edit it.
 *
 * @param {string} gradient Gradient.
 * @param {Object} palette  Slug => color.
 * @return {string} Gradient.
 */
function resolveGradient( gradient, palette ) {
	return gradient.replace(
		/var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)/gi,
		( m, slug ) => palette[ slug ] ?? m
	);
}

/**
 * Gradients and the duotone filter.
 *
 * @param {Object}                   props
 * @param {Object}                   props.state       State.
 * @param {(value: unknown) => void} props.setSettings Sets a settings value.
 */
function GradientsAndDuotone( { state, setSettings } ) {
	const palette = paletteMap( state.settings );
	const own = state.settings?.color?.gradients;
	const gradients = own ?? IPSUM_GRADIENTS;
	const [ open, setOpen ] = useState( -1 );
	const save = ( list ) => setSettings( [ 'color', 'gradients' ], list );
	const duotone = state.settings?.color?.duotone?.[ 0 ];
	const colorPalette = ( state.settings?.color?.palette ?? [] )
		.filter( ( c ) => ! isTwin( c ) )
		.map( ( c ) => ( {
			name: c.name,
			slug: c.slug,
			color: c.color,
		} ) );
	return (
		<div>
			<p className="windrose-livery-mermaid__muted">
				{ own
					? __(
							'This style has its own gradients.',
							'windrose-livery'
						)
					: __(
							'Ipsum’s gradients, made from the palette: they follow your colors.',
							'windrose-livery'
						) }
			</p>
			<ul className="windrose-livery-mermaid__gradients">
				{ gradients.map( ( g, i ) => (
					<li key={ g.slug }>
						<span
							className="windrose-livery-mermaid__gradient"
							style={ {
								background: resolveGradient(
									g.gradient,
									palette
								),
							} }
							aria-hidden="true"
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Name', 'windrose-livery' ) }
							value={ g.name }
							onChange={ ( name ) =>
								save(
									gradients.map( ( x, j ) =>
										j === i ? { ...x, name } : x
									)
								)
							}
						/>
						<Button
							variant="secondary"
							size="compact"
							aria-expanded={ open === i }
							onClick={ () => setOpen( open === i ? -1 : i ) }
						>
							{ __( 'Edit', 'windrose-livery' ) }
						</Button>
						{ gradients.length > 1 && (
							<Button
								variant="tertiary"
								size="compact"
								isDestructive
								onClick={ () =>
									save(
										gradients.filter( ( x, j ) => j !== i )
									)
								}
							>
								{ __( 'Remove', 'windrose-livery' ) }
							</Button>
						) }
						{ open === i && (
							<div className="windrose-livery-mermaid__gradient-picker">
								<GradientPicker
									value={ resolveGradient(
										g.gradient,
										palette
									) }
									onChange={ ( value ) =>
										value &&
										save(
											gradients.map( ( x, j ) =>
												j === i
													? { ...x, gradient: value }
													: x
											)
										)
									}
									gradients={ [] }
									clearable={ false }
								/>
							</div>
						) }
					</li>
				) ) }
			</ul>
			{ gradients.length < MAX_GRADIENTS && (
				<Button
					variant="secondary"
					onClick={ () => {
						const n = gradients.length + 1;
						save( [
							...gradients,
							{
								name: sprintf(
									/* translators: %d: number. */
									__( 'Gradient %d', 'windrose-livery' ),
									n
								),
								slug: `gradient-${ Date.now().toString( 36 ) }`,
								gradient: `linear-gradient(135deg, ${ palette[ 'accent-1' ] ?? '#3355ff' } 0%, ${
									palette.base ?? '#ffffff'
								} 100%)`,
							},
						] );
					} }
				>
					{ __( 'Add a gradient', 'windrose-livery' ) }
				</Button>
			) }
			<h4 className="windrose-livery-mermaid__h4">
				{ __( 'Duotone filter for images', 'windrose-livery' ) }
			</h4>
			<DuotonePicker
				duotonePalette={ [] }
				colorPalette={ colorPalette }
				value={
					duotone?.colors ?? [
						palette[ 'accent-3' ] ?? '#000000',
						palette.base ?? '#ffffff',
					]
				}
				onChange={ ( colors ) =>
					colors &&
					setSettings(
						[ 'color', 'duotone' ],
						[
							{
								name:
									duotone?.name ??
									__( 'Duotone', 'windrose-livery' ),
								slug: duotone?.slug ?? 'duotone-1',
								colors,
							},
						]
					)
				}
				disableCustomDuotone={ false }
				clearable={ false }
			/>
		</div>
	);
}

/**
 * Light and dark: the twins of the palette (the other scheme), made from the palette, then editable.
 *
 * @param {Object}                   props
 * @param {Object[]}                 props.twins      Twin colours.
 * @param {?Object[]}                props.twinChecks Their contrast checks.
 * @param {string}                   props.twinScheme Their scheme.
 * @param {string}                   props.own        The palette's own scheme.
 * @param {(value: unknown) => void} props.setColor   Changes a colour.
 * @param {(value: unknown) => void} props.onAdd      Calculates them (again).
 * @param {(value: unknown) => void} props.onRemove   Removes them.
 * @param {(value: unknown) => void} props.onFix      Fixes a check.
 */
function LightAndDark( {
	twins,
	twinChecks,
	twinScheme,
	own,
	setColor,
	onAdd,
	onRemove,
	onFix,
} ) {
	const other = 'dark' === own ? 'light' : 'dark';
	return (
		<>
			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Light and dark', 'windrose-livery' ) }
			</h3>
			<p className="windrose-livery-mermaid__muted">
				{ 'dark' === other
					? __(
							'Add a dark palette: visitors can then switch to it (light and dark switch block), or get it when their browser prefers dark, if the site allows it. This palette stays the default.',
							'windrose-livery'
						)
					: __(
							'Add a light palette: visitors can then switch to it (light and dark switch block), or get it when their browser prefers light, if the site allows it. This palette stays the default.',
							'windrose-livery'
						) }
			</p>
			{ ! twins.length && (
				<Button variant="secondary" onClick={ onAdd }>
					{ 'dark' === other
						? __( 'Add a dark palette', 'windrose-livery' )
						: __( 'Add a light palette', 'windrose-livery' ) }
				</Button>
			) }
			{ twins.length > 0 && (
				<>
					<ul className="windrose-livery-mermaid__colors">
						{ twins.map( ( c ) => (
							<li key={ c.slug }>
								<ColorButton
									label={ c.name }
									color={ c.color }
									alpha
									onChange={ ( color ) =>
										setColor( c.slug, { color } )
									}
								/>
								<span>{ c.name }</span>
								<code>{ c.color }</code>
							</li>
						) ) }
					</ul>
					<div className="windrose-livery-mermaid__actions">
						<Button variant="secondary" onClick={ onAdd }>
							{ __( 'Calculate again', 'windrose-livery' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ onRemove }
						>
							{ 'dark' === twinScheme
								? __(
										'Remove the dark palette',
										'windrose-livery'
									)
								: __(
										'Remove the light palette',
										'windrose-livery'
									) }
						</Button>
					</div>
					{ twinChecks && (
						<>
							<h4 className="windrose-livery-mermaid__h">
								{ 'dark' === twinScheme
									? __(
											'Contrast in dark',
											'windrose-livery'
										)
									: __(
											'Contrast in light',
											'windrose-livery'
										) }
							</h4>
							<ContrastList
								checks={ twinChecks }
								onFix={ onFix }
							/>
						</>
					) }
				</>
			) }
		</>
	);
}

/**
 * The Colors step.
 *
 * @param {Object}                   props
 * @param {Object}                   props.catalog       Catalog.
 * @param {Object[]}                 props.library       Library variations (admin screen).
 * @param {Object}                   props.state         State.
 * @param {Object[]}                 props.checks        Contrast checks.
 * @param {(value: unknown) => void} props.pickPalette   Applies a palette item.
 * @param {(value: unknown) => void} props.setColor      Changes a palette color: (slug, changes).
 * @param {(value: unknown) => void} props.setPalette    Replaces the palette.
 * @param {(value: unknown) => void} props.setStyle      Sets a styles value.
 * @param {(value: unknown) => void} props.setSettings   Sets a settings value.
 * @param {(value: unknown) => void} props.onFix         Fixes a check.
 * @param {?Object[]}                props.twinChecks    Contrast checks of the twins (null without twins).
 * @param {string}                   props.twinScheme    The twins' scheme ('' without twins).
 * @param {(value: unknown) => void} props.onAddTwins    Calculates the twins.
 * @param {(value: unknown) => void} props.onRemoveTwins Removes the twins.
 * @param {(value: unknown) => void} props.onFixTwin     Fixes a twin's check.
 */
export function ColorsStep( {
	catalog,
	library,
	state,
	checks,
	twinChecks = null,
	twinScheme = '',
	onAddTwins,
	onRemoveTwins,
	onFixTwin,
	pickPalette,
	setColor,
	setPalette,
	setStyle,
	setSettings,
	onFix,
} ) {
	const all = state.settings?.color?.palette ?? [];
	const palette = all.filter( ( c ) => ! isTwin( c ) );
	const twins = all.filter( isTwin );
	const help = roleHelp();
	const extras = palette.filter( ( c ) => ! isRole( c.slug ) );
	return (
		<div>
			<details className="windrose-livery-mermaid__details" open>
				<summary>
					{ __( 'Choose a palette', 'windrose-livery' ) }
				</summary>
				<PaletteBrowser
					catalog={ catalog }
					library={ library }
					current={ paletteMap( state.settings ) }
					onPick={ pickPalette }
				/>
			</details>
			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Palette', 'windrose-livery' ) }
			</h3>
			<ul className="windrose-livery-mermaid__colors">
				{ palette.map( ( c ) => {
					const n = ROLES.indexOf( c.slug );
					return (
						<li key={ c.slug }>
							<ColorButton
								label={ c.name }
								color={ c.color }
								alpha={
									'accent-6' === c.slug ||
									'accent-5' === c.slug
								}
								onChange={ ( color ) =>
									setColor( c.slug, { color } )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={
									n >= 0
										? help[ n ]
										: __( 'Extra color', 'windrose-livery' )
								}
								value={ c.name }
								onChange={ ( name ) =>
									setColor( c.slug, { name } )
								}
							/>
							<code>{ c.color }</code>
							{ n < 0 && (
								<Button
									variant="tertiary"
									size="compact"
									isDestructive
									onClick={ () =>
										setPalette(
											all.filter(
												( x ) =>
													x.slug !== c.slug &&
													! x.slug.endsWith(
														`-${ c.slug }`
													)
											)
										)
									}
								>
									{ __( 'Remove', 'windrose-livery' ) }
								</Button>
							) }
						</li>
					);
				} ) }
			</ul>
			{ extras.length < MAX_EXTRA && (
				<Button
					variant="secondary"
					onClick={ () => {
						let n = 1;
						while (
							palette.some( ( c ) => c.slug === `extra-${ n }` )
						) {
							n++;
						}
						setPalette( [
							...all,
							{
								slug: `extra-${ n }`,
								name: sprintf(
									/* translators: %d: number. */
									__( 'Extra %d', 'windrose-livery' ),
									n
								),
								color: '#888888',
							},
						] );
					} }
				>
					{ __( 'Add a color', 'windrose-livery' ) }
				</Button>
			) }
			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Contrast', 'windrose-livery' ) }
			</h3>
			<ContrastList checks={ checks } onFix={ onFix } />
			<LightAndDark
				twins={ twins }
				twinChecks={ twinChecks }
				twinScheme={ twinScheme }
				own={ ownScheme( state.settings, state.styles ) }
				setColor={ setColor }
				onAdd={ onAddTwins }
				onRemove={ onRemoveTwins }
				onFix={ onFixTwin }
			/>
			<h3 className="windrose-livery-mermaid__h">
				{ __(
					'Colors of text, links, headings and captions',
					'windrose-livery'
				) }
			</h3>
			<ElementColors state={ state } setAt={ setStyle } />
			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Gradients and duotone', 'windrose-livery' ) }
			</h3>
			<GradientsAndDuotone state={ state } setSettings={ setSettings } />
		</div>
	);
}
