/**
 * Style Mermaid: the Blocks step (0.5). One panel per entry of blocks.js: "Keep the preset …" (the look of the style
 * chosen in the Start step), a few named choices with core's toggle groups, a small picture of the current choice (the
 * guide) and the contrast checks that entry touches. Opening a panel scrolls the preview to that block (only the
 * preview's own page moves). Anything finer is the Site Editor's.
 */
import {
	CheckboxControl,
	Panel,
	PanelBody,
	// No stable component yet (WordPress 7.2); used the way the Site Editor uses it.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	BLOCK_ORDER,
	BLOCK_STYLES,
	BUTTON_COLORS,
	BUTTON_SHAPES,
	CORNER_RADII,
	SEPARATOR_COLORS,
} from './blocks.js';
import { paletteMap } from './recipe.js';
import { ContrastList } from './step-colors';

/**
 * Names and choices shown for each entry (translatable).
 *
 * @return {Object} Id => { title, help, keep, controls: [ { key, label, options: [ [ value, label ] ] } ], checks }.
 */
const entries = () => ( {
	button: {
		title: __( 'Buttons', 'windrose-livery' ),
		help: __( 'Changes buttons and the search button.', 'windrose-livery' ),
		keep: __( 'Keep the preset buttons', 'windrose-livery' ),
		controls: [
			{
				key: 'shape',
				label: __( 'Shape', 'windrose-livery' ),
				options: [
					[ 'square', __( 'Square', 'windrose-livery' ) ],
					[ 'rounded', __( 'Rounded', 'windrose-livery' ) ],
					[ 'pill', __( 'Pill', 'windrose-livery' ) ],
				],
			},
			{
				key: 'fill',
				label: __( 'Fill', 'windrose-livery' ),
				options: [
					[ 'filled', __( 'Filled', 'windrose-livery' ) ],
					[ 'outlined', __( 'Outlined', 'windrose-livery' ) ],
				],
			},
			{
				key: 'color',
				label: __( 'Color', 'windrose-livery' ),
				options: [
					[ 'text', __( 'Contrast', 'windrose-livery' ) ],
					[ 'accent', __( 'Accent', 'windrose-livery' ) ],
					[ 'strong', __( 'Strong Contrast', 'windrose-livery' ) ],
				],
			},
			{
				key: 'hover',
				label: __( 'Hover color', 'windrose-livery' ),
				options: [
					[ 'text', __( 'Contrast', 'windrose-livery' ) ],
					[ 'accent', __( 'Accent', 'windrose-livery' ) ],
					[ 'strong', __( 'Strong Contrast', 'windrose-livery' ) ],
				],
			},
			{
				key: 'size',
				label: __( 'Size', 'windrose-livery' ),
				options: [
					[ 'small', __( 'Small', 'windrose-livery' ) ],
					[ 'medium', __( 'Medium', 'windrose-livery' ) ],
					[ 'large', __( 'Large', 'windrose-livery' ) ],
				],
			},
		],
		checks: [ 'button', 'button-hover' ],
	},
	corners: {
		title: __( 'Corners', 'windrose-livery' ),
		help: __(
			'One choice for images, featured images, covers, quotes, pullquotes and code.',
			'windrose-livery'
		),
		keep: __( 'Keep the preset corners', 'windrose-livery' ),
		controls: [
			{
				key: 'radius',
				label: __( 'Corners', 'windrose-livery' ),
				options: [
					[ 'square', __( 'Square', 'windrose-livery' ) ],
					[ 'soft', __( 'Soft', 'windrose-livery' ) ],
					[ 'round', __( 'Round', 'windrose-livery' ) ],
				],
			},
		],
		checks: [],
	},
	images: {
		title: __( 'Images', 'windrose-livery' ),
		help: __( 'Changes images and featured images.', 'windrose-livery' ),
		keep: __( 'Keep the preset images', 'windrose-livery' ),
		controls: [
			{
				key: 'frame',
				label: __( 'Frame', 'windrose-livery' ),
				options: [
					[ 'none', __( 'None', 'windrose-livery' ) ],
					[ 'line', __( 'Line', 'windrose-livery' ) ],
					[ 'shadow', __( 'Shadow', 'windrose-livery' ) ],
				],
			},
		],
		checks: [ 'caption' ],
	},
	quotes: {
		title: __( 'Quotes', 'windrose-livery' ),
		help: __(
			'Changes quotes and pullquotes. Lines: one above and one below, so the same on right-to-left pages.',
			'windrose-livery'
		),
		keep: __( 'Keep the preset quotes', 'windrose-livery' ),
		controls: [
			{
				key: 'look',
				label: __( 'Look', 'windrose-livery' ),
				options: [
					[ 'soft', __( 'Surface', 'windrose-livery' ) ],
					[ 'lines', __( 'Lines', 'windrose-livery' ) ],
					[ 'plain', __( 'Plain', 'windrose-livery' ) ],
				],
			},
		],
		checks: [ 'text', 'text-soft' ],
	},
	separator: {
		title: __( 'Separator', 'windrose-livery' ),
		help: __( 'Changes the separator line.', 'windrose-livery' ),
		keep: __( 'Keep the preset separator', 'windrose-livery' ),
		controls: [
			{
				key: 'color',
				label: __( 'Color', 'windrose-livery' ),
				options: [
					[ 'lines', __( 'Border', 'windrose-livery' ) ],
					[ 'accent', __( 'Accent', 'windrose-livery' ) ],
					[ 'text', __( 'Contrast', 'windrose-livery' ) ],
				],
			},
			{
				key: 'thickness',
				label: __( 'Thickness', 'windrose-livery' ),
				options: [
					[ 'thin', __( 'Thin', 'windrose-livery' ) ],
					[ 'thick', __( 'Thick', 'windrose-livery' ) ],
				],
			},
		],
		checks: [],
	},
	menu: {
		title: __( 'Menu', 'windrose-livery' ),
		help: __(
			'Changes the navigation menu. Its font is the labels font (Fonts step).',
			'windrose-livery'
		),
		keep: __( 'Keep the preset menu', 'windrose-livery' ),
		controls: [
			{
				key: 'case',
				label: __( 'Letters', 'windrose-livery' ),
				options: [
					[ 'as-written', __( 'As written', 'windrose-livery' ) ],
					[ 'capitals', __( 'Capitals', 'windrose-livery' ) ],
				],
			},
			{
				key: 'weight',
				label: __( 'Weight', 'windrose-livery' ),
				options: [
					[ 'regular', __( 'Regular', 'windrose-livery' ) ],
					[ 'bold', __( 'Bold', 'windrose-livery' ) ],
				],
			},
			{
				key: 'gap',
				label: __( 'Space between items', 'windrose-livery' ),
				options: [
					[ 'small', __( 'Small', 'windrose-livery' ) ],
					[ 'medium', __( 'Medium', 'windrose-livery' ) ],
					[ 'large', __( 'Large', 'windrose-livery' ) ],
				],
			},
		],
		checks: [ 'link', 'link-hover' ],
	},
	code: {
		title: __( 'Code', 'windrose-livery' ),
		help: __( 'Changes code blocks.', 'windrose-livery' ),
		keep: __( 'Keep the preset code', 'windrose-livery' ),
		controls: [
			{
				key: 'look',
				label: __( 'Look', 'windrose-livery' ),
				options: [
					[ 'line', __( 'Line', 'windrose-livery' ) ],
					[ 'soft', __( 'Surface', 'windrose-livery' ) ],
				],
			},
		],
		checks: [ 'text', 'text-soft' ],
	},
} );

const px = ( value ) => parseFloat( value ) || 0;

/**
 * A small picture of an entry's current choice, in the style's colors (decoration: the controls say it in words).
 *
 * @param {Object} props
 * @param {string} props.id      Entry id.
 * @param {Object} props.value   Choices.
 * @param {Object} props.palette Slug => color.
 */
function Diagram( { id, value, palette } ) {
	const c = ( slug, fallback = '#888' ) => palette[ slug ] ?? fallback;
	const page = c( 'base', '#fff' );
	const text = c( 'contrast', '#000' );
	let body = null;
	if ( 'button' === id ) {
		const main = c( BUTTON_COLORS[ value.color ] ?? 'contrast' );
		const r = Math.min( px( BUTTON_SHAPES[ value.shape ] ), 16 );
		const grow = { small: -6, medium: 0, large: 6 }[ value.size ] ?? 0;
		const hover = c( BUTTON_COLORS[ value.hover ] ?? 'accent-3' );
		const outlined = 'outlined' === value.fill;
		// The button, then the same button on hover.
		const w = 44 + grow * 2;
		const h = 20 + grow;
		const y = 24 - h / 2;
		body = (
			<>
				<rect
					x={ 8 }
					y={ y }
					width={ w }
					height={ h }
					rx={ Math.min( r, h / 2 ) }
					fill={ outlined ? 'none' : main }
					stroke={ main }
					strokeWidth="2"
				/>
				<rect
					x={ 112 - w }
					y={ y }
					width={ w }
					height={ h }
					rx={ Math.min( r, h / 2 ) }
					fill={ hover }
					stroke={ hover }
					strokeWidth="2"
				/>
			</>
		);
	} else if ( 'corners' === id || 'images' === id ) {
		const r = 'corners' === id ? px( CORNER_RADII[ value.radius ] ) : 6;
		body = (
			<>
				{ 'images' === id && 'shadow' === value.frame && (
					<rect
						x="32"
						y="10"
						width="60"
						height="32"
						rx={ r }
						fill={ text }
						opacity="0.15"
					/>
				) }
				<rect
					x="28"
					y="6"
					width="60"
					height="32"
					rx={ r }
					fill={ c( 'accent-5', '#eee' ) }
					stroke={
						'images' === id && 'line' === value.frame
							? c( 'accent-6' )
							: 'none'
					}
					strokeWidth="2"
				/>
				<path
					d="M34 34 L48 20 L58 30 L66 24 L82 34 Z"
					fill={ c( 'accent-1' ) }
				/>
			</>
		);
	} else if ( 'quotes' === id || 'code' === id ) {
		const soft = 'soft' === value.look;
		const lines = 'lines' === value.look;
		body = (
			<>
				{ soft && (
					<rect
						x="16"
						y="6"
						width="88"
						height="36"
						rx="4"
						fill={ c( 'accent-5', '#eee' ) }
					/>
				) }
				{ 'code' === id && 'line' === value.look && (
					<rect
						x="16"
						y="6"
						width="88"
						height="36"
						rx="4"
						fill="none"
						stroke={ c( 'accent-6' ) }
						strokeWidth="1.5"
					/>
				) }
				{ lines && (
					<path
						d="M16 6 H104 M16 42 H104"
						stroke={ c( 'accent-6' ) }
						strokeWidth="1.5"
					/>
				) }
				<path
					d="M26 18 H94 M26 26 H86 M26 34 H70"
					stroke={ text }
					strokeWidth="2"
				/>
			</>
		);
	} else if ( 'separator' === id ) {
		body = (
			<path
				d="M12 24 H108"
				stroke={ c( SEPARATOR_COLORS[ value.color ] ?? 'accent-6' ) }
				strokeWidth={ 'thick' === value.thickness ? 4 : 1.5 }
			/>
		);
	} else if ( 'menu' === id ) {
		const gap = { small: 6, medium: 10, large: 14 }[ value.gap ] ?? 10;
		const w = 'capitals' === value.case ? 26 : 22;
		body = [ 0, 1, 2 ].map( ( i ) => (
			<path
				key={ i }
				d={ `M${ 10 + i * ( w + gap ) } 24 h${ w }` }
				stroke={ text }
				strokeWidth={ 'bold' === value.weight ? 4 : 2 }
			/>
		) );
	}
	return (
		<svg
			className="windrose-livery-mermaid__diagram"
			viewBox="0 0 120 48"
			width="120"
			height="48"
			aria-hidden="true"
			focusable="false"
		>
			<rect width="120" height="48" rx="4" fill={ page } />
			{ body }
		</svg>
	);
}

/**
 * The Blocks step.
 *
 * @param {Object}                               props
 * @param {Object}                               props.state    State.
 * @param {(id: string, value: ?Object) => void} props.setBlock Changes an entry's choices (null: as the style has it).
 * @param {(selector: string) => void}           props.onOpen   Scrolls the preview to a block.
 * @param {Object[]}                             props.checks   Contrast checks.
 * @param {(value: unknown) => void}             props.onFix    Fixes a check.
 */
export function BlocksStep( { state, setBlock, onOpen, checks, onFix } ) {
	const all = entries();
	const palette = paletteMap( state.settings );
	return (
		<div className="windrose-livery-mermaid__stack">
			<p className="windrose-livery-mermaid__intro">
				{ __(
					'Each block below keeps its preset look, the one of the style chosen in the Start step, until you untick “Keep the preset…”. Each choice is saved in your style, so the Site Editor (Styles, Blocks) shows it and can change it further.',
					'windrose-livery'
				) }
			</p>
			<Panel className="windrose-livery-mermaid__blocks">
				{ BLOCK_ORDER.map( ( id, i ) => {
					const entry = all[ id ];
					const chosen = state.blocks?.[ id ] ?? null;
					const value = chosen ?? BLOCK_STYLES[ id ].defaults;
					const only = entry.checks;
					return (
						<PanelBody
							key={ id }
							title={ entry.title }
							initialOpen={ 0 === i }
							onToggle={ ( open ) =>
								open && onOpen?.( BLOCK_STYLES[ id ].focus )
							}
						>
							<div className="windrose-livery-mermaid__stack">
								<p className="windrose-livery-mermaid__help">
									{ entry.help }
								</p>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ entry.keep }
									checked={ ! chosen }
									onChange={ ( keep ) => {
										setBlock(
											id,
											keep
												? null
												: {
														...BLOCK_STYLES[ id ]
															.defaults,
													}
										);
										onOpen?.( BLOCK_STYLES[ id ].focus );
									} }
								/>
								{ chosen && (
									<div className="windrose-livery-mermaid__look">
										<div className="windrose-livery-mermaid__stack">
											{ entry.controls.map(
												( control ) => (
													<ToggleGroupControl
														key={ control.key }
														__next40pxDefaultSize
														__nextHasNoMarginBottom
														isBlock
														label={ control.label }
														value={
															value[ control.key ]
														}
														onChange={ ( v ) =>
															v &&
															setBlock( id, {
																...value,
																[ control.key ]:
																	v,
															} )
														}
													>
														{ control.options.map(
															( [
																v,
																label,
															] ) => (
																<ToggleGroupControlOption
																	key={ v }
																	value={ v }
																	label={
																		label
																	}
																/>
															)
														) }
													</ToggleGroupControl>
												)
											) }
										</div>
										<Diagram
											id={ id }
											value={ value }
											palette={ palette }
										/>
									</div>
								) }
								{ only.length > 0 && (
									<ContrastList
										checks={ checks }
										onFix={ onFix }
										only={ only }
									/>
								) }
							</div>
						</PanelBody>
					);
				} ) }
			</Panel>
		</div>
	);
}
