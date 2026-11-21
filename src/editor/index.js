/**
 * Windrose Livery in the Site Editor, top Options menu (⋮):
 * - "Save styles as a variation": the site's saved styles into the shared library (network administrators; on a
 *   single site, its administrators) or the site's own styles (its administrators when the network allows it;
 *   network administrators choose), POST /from-site: check first, then add;
 * - light and dark (./light-dark.js), for anyone who may edit the site's styles.
 */
import { useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { PluginMoreMenuItem } from '@wordpress/editor';
import {
	Button,
	ExternalLink,
	Modal,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { resolveSelect, useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import { brush } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import { data, Preview, ROOT } from '../admin/parts';
import { Buttons, ReplaceChoice } from '../admin/modals';
import LightDarkItems from './light-dark';
import './editor.scss';

/**
 * The dialog: title and theme, check, preview, add.
 *
 * @param {Object}     props
 * @param {() => void} props.onClose Closes.
 */
function SaveDialog( { onClose } ) {
	const themes = data.themes || [];
	const [ title, setTitle ] = useState( '' );
	const [ theme, setTheme ] = useState( themes[ 0 ]?.value || '' );
	const [ preview, setPreview ] = useState( null );
	const [ replace, setReplace ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	// Where it goes: the shared library (0) or this site's own styles (the site's ID).
	const [ scope, setScope ] = useState( data.shared ? 0 : data.siteId );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const {
		__experimentalReceiveThemeGlobalStyleVariations: receiveVariations,
	} = useDispatch( coreStore );
	const stylesheet = useSelect(
		( select ) => select( coreStore ).getCurrentTheme()?.stylesheet,
		[]
	);

	// Unsaved style changes are not in the site's saved styles yet.
	const unsaved = useSelect(
		( select ) =>
			select( coreStore )
				.__experimentalGetDirtyEntityRecords()
				.some( ( record ) => 'globalStyles' === record.name ),
		[]
	);

	const send = async ( dryRun ) => {
		setBusy( true );
		setError( '' );
		try {
			const answer = await apiFetch( {
				path: `${ ROOT }/from-site?scope=${ scope }`,
				method: 'POST',
				data: {
					site: data.siteId,
					title,
					theme,
					dry_run: dryRun,
					replace,
				},
			} );
			if ( dryRun ) {
				setPreview( answer );
				setReplace( false );
				setBusy( false );
				return;
			}
			// Browse styles shows the list WordPress preloaded with the page: give it the new list (a fresh request,
			// as the preloaded answer would be served again).
			if ( stylesheet ) {
				// Let WordPress load its own list first, or it would load it later over the new one.
				await resolveSelect(
					coreStore
				).__experimentalGetCurrentThemeGlobalStylesVariations();
				receiveVariations(
					stylesheet,
					await apiFetch( {
						path: `/wp/v2/global-styles/themes/${ stylesheet }/variations?context=view&windrose_livery=${ Date.now() }`,
					} )
				);
			}
			createSuccessNotice(
				sprintf(
					/* translators: 1: variation title, 2: version number. */
					__(
						'%1$s saved as a style variation (version %2$d). It appears in Browse styles.',
						'windrose-livery'
					),
					answer.title,
					answer.version
				),
				{ type: 'snackbar' }
			);
			onClose();
		} catch ( requestError ) {
			setError( requestError.message );
			setBusy( false );
		}
	};

	if ( unsaved ) {
		return (
			<div className="windrose-livery__modal">
				<p>
					{ __(
						'The styles have unsaved changes. Save them first (Save button), then save them as a variation.',
						'windrose-livery'
					) }
				</p>
				<Buttons>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ onClose }
					>
						{ __( 'Done', 'windrose-livery' ) }
					</Button>
				</Buttons>
			</div>
		);
	}

	return (
		<div className="windrose-livery__modal">
			{ ! preview && (
				<>
					{ data.shared && data.own && (
						<RadioControl
							label={ __( 'Keep it', 'windrose-livery' ) }
							selected={ String( scope ) }
							options={ [
								{
									value: '0',
									label: __(
										'In the shared library (every site of the network on that theme)',
										'windrose-livery'
									),
								},
								{
									value: String( data.siteId ),
									label: __(
										'For this site only',
										'windrose-livery'
									),
								},
							] }
							onChange={ ( value ) =>
								setScope( Number( value ) )
							}
						/>
					) }
					<p>
						{ data.network && 0 === scope
							? __(
									'This site’s saved styles (colours, fonts, sizes, block styles) become a stored variation, offered on every site of the network that uses the theme you choose.',
									'windrose-livery'
								)
							: __(
									'This site’s saved styles (colours, fonts, sizes, block styles) become a stored variation.',
									'windrose-livery'
								) }
					</p>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'windrose-livery' ) }
						value={ title }
						onChange={ setTitle }
					/>
					{ themes.length > 1 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Made for the theme',
								'windrose-livery'
							) }
							value={ theme }
							onChange={ setTheme }
							options={ themes }
							help={ __(
								'Choose the parent theme to offer it on its other child themes too.',
								'windrose-livery'
							) }
						/>
					) }
				</>
			) }
			{ preview && (
				<>
					<Preview preview={ preview } />
					{ preview.replaces && (
						<ReplaceChoice
							replaces={ preview.replaces }
							checked={ replace }
							onChange={ setReplace }
						/>
					) }
				</>
			) }
			{ error && (
				<p className="windrose-livery__error" role="alert">
					{ error }
				</p>
			) }
			{ data.screen && (
				<p>
					<ExternalLink href={ data.screen }>
						{ __( 'Stored style variations', 'windrose-livery' ) }
					</ExternalLink>
				</p>
			) }
			<Buttons>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ preview ? () => setPreview( null ) : onClose }
					disabled={ busy }
				>
					{ preview
						? __( 'Back', 'windrose-livery' )
						: __( 'Cancel', 'windrose-livery' ) }
				</Button>
				{ ! preview && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						isBusy={ busy }
						accessibleWhenDisabled
						disabled={ busy || ! title.trim() }
						onClick={ () => send( true ) }
					>
						{ __( 'Check', 'windrose-livery' ) }
					</Button>
				) }
				{ preview && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						isBusy={ busy }
						accessibleWhenDisabled
						disabled={ busy || ( preview.replaces && ! replace ) }
						onClick={ () => send( false ) }
					>
						{ __( 'Add variation', 'windrose-livery' ) }
					</Button>
				) }
			</Buttons>
		</div>
	);
}

function SaveAsVariation() {
	const [ open, setOpen ] = useState( false );
	if ( ! data.shared && ! data.own ) {
		return null;
	}
	return (
		<>
			<PluginMoreMenuItem
				icon={ brush }
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Save styles as a variation', 'windrose-livery' ) }
			</PluginMoreMenuItem>
			{ open && (
				<Modal
					title={ __(
						'Save styles as a variation',
						'windrose-livery'
					) }
					onRequestClose={ () => setOpen( false ) }
					size="large"
				>
					<SaveDialog onClose={ () => setOpen( false ) } />
				</Modal>
			) }
		</>
	);
}

registerPlugin( 'windrose-livery', {
	render: () => (
		<>
			<SaveAsVariation />
			<LightDarkItems />
		</>
	),
} );
