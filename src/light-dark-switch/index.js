/**
 * The light and dark switch block in the editor (the page side is rendered by PHP, Schemes, with the script module
 * from ./view.js). Core's Navigation block accepts it as one of its items.
 */
import { registerBlockType } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from '../../blocks/light-dark-switch/block.json';
import './editor.scss';

const NAME = metadata.name;
const data = window.windroseLiveryScheme || {};

// Core's Navigation block takes the switch as an item (the server adds it to its allowed blocks too).
addFilter(
	'blocks.registerBlockType',
	'windrose-livery/navigation-light-dark-switch',
	( settings, name ) => {
		if ( 'core/navigation' !== name ) {
			return settings;
		}
		const allowed = settings.allowedBlocks || [];
		return allowed.includes( NAME )
			? settings
			: { ...settings, allowedBlocks: [ ...allowed, NAME ] };
	}
);

const icon = (
	<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
		<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
	</svg>
);

/**
 * Editor view: the button as visitors see it with the style's own colours.
 *
 * @param {Object}                  props
 * @param {Object}                  props.attributes    Attributes.
 * @param {(value: Object) => void} props.setAttributes Sets attributes.
 */
function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'windrose-livery-switch' } );
	let warning = '';
	if ( ! data.switch ) {
		warning = __(
			'The light and dark switch is off for this site, so visitors do not see this block. Turn it on in Appearance, Style variations (or ask whoever manages Windrose Livery).',
			'windrose-livery'
		);
	} else if ( ! data.scheme ) {
		warning = __(
			'The style this site uses has no light or dark twin colours yet, so visitors do not see this block. Add them in the Site Editor: Options (⋮), Add a dark palette.',
			'windrose-livery'
		);
	}
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'windrose-livery' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show the label', 'windrose-livery' ) }
						help={ __(
							'Off: only the icon shows; screen readers still read "Dark mode".',
							'windrose-livery'
						) }
						checked={ !! attributes.showLabel }
						onChange={ ( value ) =>
							setAttributes( { showLabel: value } )
						}
					/>
				</PanelBody>
				{ warning && (
					<div className="windrose-livery-switch__editor-notice">
						<Notice status="warning" isDismissible={ false }>
							{ warning }
						</Notice>
					</div>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				<span
					className="windrose-livery-switch__button"
					aria-hidden="true"
				>
					<span className="windrose-livery-switch__icon">
						{ icon }
					</span>
					{ attributes.showLabel && (
						<span className="windrose-livery-switch__label">
							{ __( 'Dark mode', 'windrose-livery' ) }
						</span>
					) }
				</span>
			</div>
		</>
	);
}

registerBlockType( NAME, {
	...metadata,
	icon,
	edit: Edit,
	save: () => null,
} );
