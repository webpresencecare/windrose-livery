/**
 * Style Mermaid: the Typography, Layout and Finish steps (the Blocks step is step-blocks.js).
 */
import { useState } from '@wordpress/element';
import {
	BoxControl,
	Button,
	CheckboxControl,
	FontSizePicker,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	// No stable component yet for these (WordPress 7.2); used the way the Site Editor uses them.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUnitControl as UnitControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { passes } from './contrast.js';
import {
	CONTENT_SIZES,
	FONT_PREFIX,
	IPSUM_FONT_SIZES,
	IPSUM_SPACING,
	SPACING_SCALES,
	WIDE_SIZES,
	getAt,
	scaledSpacing,
	spacingScale,
} from './recipe.js';
import { ContrastList } from './step-colors';

/**
 * Parts of the page whose typography can change, with the paths they write.
 *
 * @return {Object[]} Elements.
 */
const typeElements = () => [
	{
		id: 'text',
		label: __( 'Text', 'windrose-livery' ),
		paths: [ [ 'typography' ] ],
		font: true,
	},
	{
		id: 'link',
		label: __( 'Links', 'windrose-livery' ),
		paths: [ [ 'elements', 'link', 'typography' ] ],
		font: true,
	},
	{
		id: 'heading',
		label: __( 'All headings', 'windrose-livery' ),
		paths: [ [ 'elements', 'heading', 'typography' ] ],
		font: true,
	},
	...[ 1, 2, 3, 4, 5, 6 ].map( ( n ) => ( {
		id: `h${ n }`,
		label: sprintf(
			/* translators: %d: heading level. */
			__( 'Heading %d', 'windrose-livery' ),
			n
		),
		paths: [ [ 'elements', `h${ n }`, 'typography' ] ],
		font: true,
	} ) ),
	{
		id: 'caption',
		label: __( 'Captions', 'windrose-livery' ),
		paths: [ [ 'elements', 'caption', 'typography' ] ],
		font: true,
	},
	{
		id: 'button',
		label: __( 'Buttons', 'windrose-livery' ),
		paths: [
			[ 'blocks', 'core/button', 'typography' ],
			[ 'elements', 'button', 'typography' ],
		],
		font: false,
	},
];

const SIZE_PREFIX = 'var:preset|font-size|';

/**
 * The Typography step.
 *
 * @param {Object}                   props
 * @param {Object}                   props.state    State.
 * @param {(value: unknown) => void} props.setStyle Sets a styles value.
 */
export function TypographyStep( { state, setStyle } ) {
	const elements = typeElements();
	const [ current, setCurrent ] = useState( 'text' );
	const element = elements.find( ( e ) => e.id === current ) ?? elements[ 0 ];
	const read = ( key ) => {
		for ( const path of element.paths ) {
			const value = getAt( state.styles, [ ...path, key ] );
			if ( undefined !== value && '' !== value ) {
				return value;
			}
		}
		return '';
	};
	const write = ( key, value ) =>
		element.paths.forEach( ( path ) =>
			setStyle( [ ...path, key ], value )
		);
	const size = read( 'fontSize' );
	const sizeValue = size.startsWith( SIZE_PREFIX )
		? IPSUM_FONT_SIZES.find(
				( s ) => s.slug === size.slice( SIZE_PREFIX.length )
			)?.size
		: size;
	const lineHeight = read( 'lineHeight' );
	return (
		<div>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Part of the page', 'windrose-livery' ) }
				value={ current }
				options={ elements.map( ( e ) => ( {
					value: e.id,
					label: e.label,
				} ) ) }
				onChange={ setCurrent }
			/>
			<div className="windrose-livery-mermaid__stack">
				{ element.font && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Font', 'windrose-livery' ) }
						value={ read( 'fontFamily' ) }
						options={ [
							{
								value: '',
								label: __( 'Theme default', 'windrose-livery' ),
							},
							{
								value: `${ FONT_PREFIX }@heading`,
								label: __( 'Heading font', 'windrose-livery' ),
							},
							{
								value: `${ FONT_PREFIX }@text`,
								label: __( 'Text font', 'windrose-livery' ),
							},
							{
								value: `${ FONT_PREFIX }@labels`,
								label: __( 'Labels font', 'windrose-livery' ),
							},
							{
								value: `${ FONT_PREFIX }jetbrains-mono`,
								label: __(
									'Code font (JetBrains Mono)',
									'windrose-livery'
								),
							},
						] }
						onChange={ ( v ) => write( 'fontFamily', v ) }
					/>
				) }
				<FontSizePicker
					__next40pxDefaultSize
					fontSizes={ IPSUM_FONT_SIZES }
					value={ sizeValue || undefined }
					onChange={ ( value, item ) =>
						write(
							'fontSize',
							item?.slug ? SIZE_PREFIX + item.slug : value
						)
					}
					withReset
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Weight', 'windrose-livery' ) }
					value={ String( read( 'fontWeight' ) ) }
					options={ [
						{
							value: '',
							label: __( 'Theme default', 'windrose-livery' ),
						},
						...[ 300, 400, 500, 600, 700, 800, 900 ].map(
							( w ) => ( {
								value: String( w ),
								label: String( w ),
							} )
						),
					] }
					onChange={ ( v ) => write( 'fontWeight', v ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Line height', 'windrose-livery' ) }
					help={ __(
						'A number, for example 1.5. Empty: the theme’s.',
						'windrose-livery'
					) }
					value={ lineHeight }
					onChange={ ( v ) => write( 'lineHeight', v.trim() ) }
				/>
				<UnitControl
					__next40pxDefaultSize
					label={ __( 'Letter spacing', 'windrose-livery' ) }
					value={ read( 'letterSpacing' ) }
					units={ [
						{ value: 'em', label: 'em', step: 0.01 },
						{ value: 'rem', label: 'rem', step: 0.01 },
						{ value: 'px', label: 'px', step: 0.5 },
					] }
					onChange={ ( v ) => write( 'letterSpacing', v ) }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Letter case', 'windrose-livery' ) }
					value={ read( 'textTransform' ) }
					options={ [
						{
							value: '',
							label: __( 'Theme default', 'windrose-livery' ),
						},
						{
							value: 'none',
							label: __( 'As typed', 'windrose-livery' ),
						},
						{
							value: 'uppercase',
							label: __( 'Uppercase', 'windrose-livery' ),
						},
						{
							value: 'lowercase',
							label: __( 'Lowercase', 'windrose-livery' ),
						},
						{
							value: 'capitalize',
							label: __( 'Capitalize', 'windrose-livery' ),
						},
					] }
					onChange={ ( v ) => write( 'textTransform', v ) }
				/>
			</div>
		</div>
	);
}

/**
 * A width: three choices, then any value.
 *
 * @param {Object}                   props
 * @param {string}                   props.label    Label.
 * @param {string}                   props.value    Value.
 * @param {Object}                   props.choices  id => value.
 * @param {Object}                   props.names    id => label.
 * @param {(value: unknown) => void} props.onChange Called with the new value.
 */
function WidthControl( { label, value, choices, names, onChange } ) {
	const chosen =
		Object.keys( choices ).find( ( id ) => choices[ id ] === value ) ?? '';
	return (
		<div className="windrose-livery-mermaid__stack">
			<ToggleGroupControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				isBlock
				label={ label }
				value={ chosen }
				onChange={ ( id ) => id && onChange( choices[ id ] ) }
			>
				{ Object.keys( choices ).map( ( id ) => (
					<ToggleGroupControlOption
						key={ id }
						value={ id }
						label={ `${ names[ id ] } ${ choices[ id ] }` }
					/>
				) ) }
			</ToggleGroupControl>
			<UnitControl
				__next40pxDefaultSize
				label={ sprintf(
					/* translators: %s: what the width is for. */
					__( '%s, any value', 'windrose-livery' ),
					label
				) }
				value={ value }
				units={ [
					{ value: 'px', label: 'px', step: 10 },
					{ value: 'rem', label: 'rem', step: 1 },
					{ value: 'ch', label: 'ch', step: 1 },
				] }
				onChange={ ( v ) => v && onChange( v ) }
			/>
		</div>
	);
}

/**
 * The Layout step.
 *
 * @param {Object}                   props
 * @param {Object}                   props.state       State.
 * @param {(value: unknown) => void} props.setStyle    Sets a styles value.
 * @param {(value: unknown) => void} props.setSettings Sets a settings value.
 */
export function LayoutStep( { state, setStyle, setSettings } ) {
	const scale = spacingScale( state.settings );
	const spacing = state.settings?.spacing?.spacingSizes ?? IPSUM_SPACING;
	const presets = spacing.map( ( s ) => ( {
		name: s.name,
		slug: s.slug,
		size: s.size,
	} ) );
	return (
		<div className="windrose-livery-mermaid__stack">
			<WidthControl
				label={ __( 'Text width', 'windrose-livery' ) }
				value={ state.settings?.layout?.contentSize ?? '640px' }
				choices={ CONTENT_SIZES }
				names={ {
					narrow: __( 'Narrow', 'windrose-livery' ),
					medium: __( 'Medium', 'windrose-livery' ),
					wide: __( 'Wide', 'windrose-livery' ),
				} }
				onChange={ ( v ) =>
					setSettings( [ 'layout', 'contentSize' ], v )
				}
			/>
			<WidthControl
				label={ __( 'Wide width', 'windrose-livery' ) }
				value={ state.settings?.layout?.wideSize ?? '1280px' }
				choices={ WIDE_SIZES }
				names={ {
					small: __( 'Small', 'windrose-livery' ),
					medium: __( 'Medium', 'windrose-livery' ),
					large: __( 'Large', 'windrose-livery' ),
				} }
				onChange={ ( v ) => setSettings( [ 'layout', 'wideSize' ], v ) }
			/>
			<ToggleGroupControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				isBlock
				label={ __( 'Spacing scale', 'windrose-livery' ) }
				help={ __(
					'All of Ipsum’s spacing sizes, smaller or larger: patterns keep their proportions.',
					'windrose-livery'
				) }
				value={ scale }
				onChange={ ( id ) =>
					id &&
					setSettings(
						[ 'spacing', 'spacingSizes' ],
						'ipsum' === id
							? undefined
							: scaledSpacing( SPACING_SCALES[ id ] )
					)
				}
			>
				<ToggleGroupControlOption
					value="compact"
					label={ __( 'Compact', 'windrose-livery' ) }
				/>
				<ToggleGroupControlOption
					value="ipsum"
					label={ __( 'As Ipsum', 'windrose-livery' ) }
				/>
				<ToggleGroupControlOption
					value="airy"
					label={ __( 'Airy', 'windrose-livery' ) }
				/>
			</ToggleGroupControl>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Space between blocks', 'windrose-livery' ) }
				value={ getAt( state.styles, [ 'spacing', 'blockGap' ] ) ?? '' }
				options={ [
					{
						value: '',
						label: __( 'Theme default', 'windrose-livery' ),
					},
					...spacing.map( ( s ) => ( {
						value: `var:preset|spacing|${ s.slug }`,
						label: `${ s.name } (${ s.size })`,
					} ) ),
				] }
				onChange={ ( v ) => setStyle( [ 'spacing', 'blockGap' ], v ) }
			/>
			<BoxControl
				__next40pxDefaultSize
				label={ __( 'Page padding', 'windrose-livery' ) }
				values={ getAt( state.styles, [ 'spacing', 'padding' ] ) ?? {} }
				presets={ presets }
				presetKey="spacing"
				onChange={ ( v ) =>
					setStyle(
						[ 'spacing', 'padding' ],
						v && Object.keys( v ).length ? v : undefined
					)
				}
			/>
		</div>
	);
}

/**
 * The Finish step.
 *
 * @param {Object}                   props
 * @param {Object}                   props.config     App config.
 * @param {Object}                   props.state      State.
 * @param {(value: unknown) => void} props.setText    Sets title or description.
 * @param {Object[]}                 props.checks     Contrast checks.
 * @param {(value: unknown) => void} props.onFix      Fixes a check.
 * @param {(value: unknown) => void} props.onDownload Downloads.
 * @param {(value: unknown) => void} props.onSave     Adds to the library (admin screen).
 * @param {boolean}                  props.busy       Working.
 * @param {Object}                   props.message    { status, text } after an action.
 * @param {boolean}                  props.exists     A variation with this title exists (admin screen).
 * @param {?Object[]}                props.twinChecks Contrast checks of the other scheme (null without twins).
 * @param {string}                   props.twinScheme dark or light ('' without twins).
 * @param {(value: unknown) => void} props.onFixTwin  Fixes a check of the other scheme.
 */
export function FinishStep( {
	config,
	state,
	setText,
	checks,
	onFix,
	onDownload,
	onSave,
	busy,
	message,
	exists,
	twinChecks = null,
	twinScheme = '',
	onFixTwin,
} ) {
	const [ replace, setReplace ] = useState( false );
	const ok = passes( checks ) && ( ! twinChecks || passes( twinChecks ) );
	const admin = 'admin' === config.mode;
	// On a site's own screen (scope), the style goes to that site's styles and can be used there at once.
	const own = admin && config.scope > 0;
	return (
		<div className="windrose-livery-mermaid__stack">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Title', 'windrose-livery' ) }
				value={ state.title }
				placeholder={ __( 'My style', 'windrose-livery' ) }
				onChange={ ( v ) => setText( 'title', v ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Description (optional)', 'windrose-livery' ) }
				value={ state.description }
				onChange={ ( v ) => setText( 'description', v ) }
				rows={ 2 }
			/>
			<h3 className="windrose-livery-mermaid__h">
				{ __( 'Contrast', 'windrose-livery' ) }
			</h3>
			<ContrastList checks={ checks } onFix={ onFix } />
			{ twinChecks && (
				<>
					<h3 className="windrose-livery-mermaid__h">
						{ 'dark' === twinScheme
							? __( 'Contrast in dark', 'windrose-livery' )
							: __( 'Contrast in light', 'windrose-livery' ) }
					</h3>
					<ContrastList checks={ twinChecks } onFix={ onFixTwin } />
				</>
			) }
			{ ! ok && (
				<Notice
					status={ admin ? 'warning' : 'error' }
					isDismissible={ false }
				>
					{ admin
						? __(
								'Some colors do not reach WCAG 2.2 AA. You can still save, but visitors may find the text hard to read: use Fix.',
								'windrose-livery'
							)
						: __(
								'Some colors do not reach WCAG 2.2 AA yet: use Fix, then download.',
								'windrose-livery'
							) }
				</Notice>
			) }
			{ admin && exists && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'A style variation with this title exists for Ipsum: replace it (as a new version)',
						'windrose-livery'
					) }
					checked={ replace }
					onChange={ setReplace }
				/>
			) }
			<div className="windrose-livery-mermaid__actions">
				{ own && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => onSave( replace, true ) }
						disabled={
							busy || ( exists && ! replace ) || ! config.ipsum
						}
						accessibleWhenDisabled
						isBusy={ busy }
					>
						{ __( 'Add and use on this site', 'windrose-livery' ) }
					</Button>
				) }
				{ admin && (
					<Button
						__next40pxDefaultSize
						variant={ own ? 'secondary' : 'primary' }
						onClick={ () => onSave( replace, false ) }
						disabled={
							busy || ( exists && ! replace ) || ! config.ipsum
						}
						accessibleWhenDisabled
						isBusy={ busy }
					>
						{ own
							? __(
									'Add to this site’s styles',
									'windrose-livery'
								)
							: __( 'Add to the library', 'windrose-livery' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant={ admin ? 'secondary' : 'primary' }
					onClick={ onDownload }
					disabled={ busy || ( ! admin && ! ok ) }
					accessibleWhenDisabled
					isBusy={ busy }
				>
					{ __( 'Download', 'windrose-livery' ) }
				</Button>
			</div>
			<p className="windrose-livery-mermaid__muted">
				{ __(
					'The download is a zip for Windrose Livery (Import), made for the Ipsum theme and its child themes. Its fonts come with their licenses.',
					'windrose-livery'
				) }
			</p>
			{ message && (
				<Notice status={ message.status } isDismissible={ false }>
					{ message.text }
				</Notice>
			) }
		</div>
	);
}
