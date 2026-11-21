/**
 * Light and dark in the Site Editor (Options ⋮): "Add a dark palette" (or light) and "Preview dark" (or light).
 *
 * The twins are colours of the site's own palette (Styles, Colors, Palette, Custom), so core edits, saves, exports
 * and applies them like any colour (includes/class-schemes.php). Adding them only changes the styles being edited:
 * core's Save button saves them. The preview points the canvas's colour variables at the twins while it is on.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { PluginMoreMenuItem } from '@wordpress/editor';
import { Button, Modal, Notice } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { color as colorIcon, seen } from '@wordpress/icons';

import { findTwins, isTwin, makeTwins } from '../mermaid/twins.js';
import { ContrastList } from '../mermaid/step-colors.js';
import { Buttons } from '../admin/modals';

const PREVIEW_ID = 'windrose-livery-preview-scheme';

/**
 * A preset list of either shape (a plain list, or grouped by origin) as { theme, custom, default } lists.
 *
 * @param {unknown} value Preset value.
 * @return {Object} Lists by origin.
 */
function byOrigin( value ) {
	if ( Array.isArray( value ) ) {
		return { theme: value };
	}
	return value && 'object' === typeof value ? value : {};
}

/**
 * Deep merge of plain objects (later wins); lists are replaced.
 *
 * @param {Object} a First.
 * @param {Object} b Second.
 * @return {Object} Merged.
 */
function merge( a, b ) {
	if ( ! b || 'object' !== typeof b || Array.isArray( b ) ) {
		return b ?? a;
	}
	const out = { ...( a && 'object' === typeof a ? a : {} ) };
	Object.entries( b ).forEach( ( [ key, value ] ) => {
		out[ key ] = merge( out[ key ], value );
	} );
	return out;
}

/**
 * The styles being edited: their record, and the palette and styles in effect (theme base, then the site's).
 *
 * @return {Object} { id, record, palette, styles }.
 */
function useCurrentStyles() {
	return useSelect( ( select ) => {
		const store = select( coreStore );
		const id = store.__experimentalGetCurrentGlobalStylesId();
		const record = id
			? store.getEditedEntityRecord( 'root', 'globalStyles', id )
			: null;
		const base = store.__experimentalGetCurrentThemeBaseGlobalStyles();
		const user = byOrigin( record?.settings?.color?.palette );
		const theme = byOrigin( base?.settings?.color?.palette );
		const palette = [
			...( user.theme ?? theme.theme ?? [] ),
			...( user.custom ?? [] ),
		];
		return {
			id,
			record,
			palette,
			styles: merge( base?.styles ?? {}, record?.styles ?? {} ),
		};
	}, [] );
}

/**
 * The dialog: the calculated twins and their contrast, then "Add the colours".
 *
 * @param {Object}     props
 * @param {Object}     props.current Current styles.
 * @param {() => void} props.onClose Closes.
 */
function AddDialog( { current, onClose } ) {
	const { editEntityRecord } = useDispatch( coreStore );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const made = useMemo(
		() =>
			makeTwins(
				{ color: { palette: current.palette } },
				current.styles
			),
		[ current ]
	);
	const already = current.palette.some( isTwin );
	const add = () => {
		const settings = current.record?.settings ?? {};
		const palette = byOrigin( settings.color?.palette );
		editEntityRecord( 'root', 'globalStyles', current.id, {
			settings: {
				...settings,
				color: {
					...( settings.color ?? {} ),
					palette: {
						...palette,
						custom: [
							...( palette.custom ?? [] ).filter(
								( c ) => ! isTwin( c )
							),
							...made.twins,
						],
					},
				},
			},
		} );
		createSuccessNotice(
			'dark' === made.scheme
				? __(
						'Dark palette added to Styles, Colors, Palette (Custom). Save to keep it.',
						'windrose-livery'
					)
				: __(
						'Light palette added to Styles, Colors, Palette (Custom). Save to keep it.',
						'windrose-livery'
					),
			{ type: 'snackbar' }
		);
		onClose();
	};
	return (
		<div className="windrose-livery__modal">
			<p>
				{ 'dark' === made.scheme
					? __(
							'A dark twin for each colour of the palette, made from it and checked for contrast (colours that failed were moved to the nearest that passes). They are added to Styles, Colors, Palette, as "Dark: …" colours you can change there. Visitors see them with the light and dark switch, or when their browser prefers dark, as the site allows.',
							'windrose-livery'
						)
					: __(
							'A light twin for each colour of the palette, made from it and checked for contrast (colours that failed were moved to the nearest that passes). They are added to Styles, Colors, Palette, as "Light: …" colours you can change there. Visitors see them with the light and dark switch, or when their browser prefers light, as the site allows.',
							'windrose-livery'
						) }
			</p>
			{ already && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The palette has twins already: they are replaced.',
						'windrose-livery'
					) }
				</Notice>
			) }
			<ul className="windrose-livery__twins">
				{ made.twins.map( ( c ) => (
					<li key={ c.slug }>
						<span
							className="windrose-livery__twin-swatch"
							style={ { background: c.color } }
							aria-hidden="true"
						/>
						<span>{ c.name }</span>
						<code>{ c.color }</code>
					</li>
				) ) }
			</ul>
			<h3>
				{ 'dark' === made.scheme
					? __( 'Contrast in dark', 'windrose-livery' )
					: __( 'Contrast in light', 'windrose-livery' ) }
			</h3>
			<ContrastList checks={ made.checks } />
			{ ! made.passes && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Some pairs still do not reach WCAG 2.2 AA (they use a colour typed by hand, or the background). You can add the colours and change them in the palette.',
						'windrose-livery'
					) }
				</Notice>
			) }
			<Buttons>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
				>
					{ __( 'Cancel', 'windrose-livery' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ add }
					disabled={ ! current.id }
					accessibleWhenDisabled
				>
					{ __( 'Add the colours', 'windrose-livery' ) }
				</Button>
			</Buttons>
		</div>
	);
}

/**
 * The canvas rules that show the twins.
 *
 * @param {Object} twins { scheme, map }.
 * @return {string} CSS.
 */
function previewCss( twins ) {
	let decl = `color-scheme:${ twins.scheme };--wp--custom--control-scheme:${ twins.scheme };`;
	Object.entries( twins.map ).forEach( ( [ base, twin ] ) => {
		decl += `--wp--preset--color--${ base }:var(--wp--preset--color--${ twin });`;
	} );
	// Core may define the colour variables on :root, body or the editor wrapper: outrank each.
	return `html:root,html:root body,.editor-styles-wrapper.editor-styles-wrapper{${ decl }}`;
}

/**
 * Keeps the preview rules in the canvas frame while on (the frame is replaced when the editor changes view).
 *
 * @param {boolean} on  Preview on.
 * @param {string}  css Rules.
 */
function useCanvasPreview( on, css ) {
	useEffect( () => {
		const apply = () => {
			const frame = document.querySelector(
				'iframe[name="editor-canvas"]'
			);
			const doc = frame?.contentDocument;
			if ( ! doc?.head ) {
				return;
			}
			let el = doc.getElementById( PREVIEW_ID );
			if ( ! on ) {
				el?.remove();
				return;
			}
			if ( ! el ) {
				el = doc.createElement( 'style' );
				el.id = PREVIEW_ID;
				doc.head.appendChild( el );
			}
			if ( el.textContent !== css ) {
				el.textContent = css;
			}
		};
		apply();
		if ( ! on ) {
			return undefined;
		}
		const timer = window.setInterval( apply, 500 );
		return () => {
			window.clearInterval( timer );
			apply();
		};
	}, [ on, css ] );
}

/**
 * The two menu items.
 */
export default function LightDarkItems() {
	const current = useCurrentStyles();
	const [ open, setOpen ] = useState( false );
	const [ preview, setPreview ] = useState( false );
	const twins = useMemo( () => findTwins( current.palette ), [ current ] );
	const other = useMemo(
		() =>
			makeTwins( { color: { palette: current.palette } }, current.styles )
				.scheme,
		[ current ]
	);
	const css = twins.scheme ? previewCss( twins ) : '';
	useCanvasPreview( preview && !! twins.scheme, css );
	const scheme = twins.scheme || other;
	let previewLabel = __( 'Preview light', 'windrose-livery' );
	if ( preview ) {
		previewLabel = __( 'Stop the preview', 'windrose-livery' );
	} else if ( 'dark' === twins.scheme ) {
		previewLabel = __( 'Preview dark', 'windrose-livery' );
	}
	return (
		<>
			<PluginMoreMenuItem
				icon={ colorIcon }
				onClick={ () => setOpen( true ) }
			>
				{ 'dark' === scheme
					? __( 'Add a dark palette', 'windrose-livery' )
					: __( 'Add a light palette', 'windrose-livery' ) }
			</PluginMoreMenuItem>
			{ twins.scheme && (
				<PluginMoreMenuItem
					icon={ seen }
					onClick={ () => {
						setPreview( ! preview );
						speak(
							preview
								? __(
										'The canvas shows the style’s own colours.',
										'windrose-livery'
									)
								: __(
										'The canvas shows the twin colours.',
										'windrose-livery'
									)
						);
					} }
				>
					{ previewLabel }
				</PluginMoreMenuItem>
			) }
			{ open && (
				<Modal
					title={
						'dark' === scheme
							? __( 'Add a dark palette', 'windrose-livery' )
							: __( 'Add a light palette', 'windrose-livery' )
					}
					onRequestClose={ () => setOpen( false ) }
					size="large"
				>
					<AddDialog
						current={ current }
						onClose={ () => setOpen( false ) }
					/>
				</Modal>
			) }
		</>
	);
}
