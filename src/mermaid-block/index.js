/**
 * The block windrose/style-mermaid in the editor: a placeholder (the app runs on the published page).
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { ExternalLink, Notice, Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from '../../blocks/style-mermaid/block.json';

const data = window.windroseLiveryMermaidBlock || {};

/**
 * Editor view.
 */
function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				icon="art"
				label={ __( 'Style Mermaid', 'windrose-livery' ) }
				instructions={ __(
					'Visitors make a style variation for the Ipsum theme here (fonts, colors, typography, layout, buttons) and download it for Windrose Livery. It shows on the published page.',
					'windrose-livery'
				) }
			>
				{ ! data.public && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Style Mermaid is off for visitors on this site, so the block shows nothing. Whoever manages Windrose Livery turns it on in its screen (Style variations, Style Mermaid on public pages).',
							'windrose-livery'
						) }{ ' ' }
						{ data.manager && data.screen && (
							<ExternalLink href={ data.screen }>
								{ __( 'Style variations', 'windrose-livery' ) }
							</ExternalLink>
						) }
					</Notice>
				) }
			</Placeholder>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
