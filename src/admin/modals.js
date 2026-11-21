/**
 * Windrose Livery screen: the dialogs (import, save a site's styles, apply, delete).
 */
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { data, fits, Preview, ROOT } from './parts';

/**
 * Buttons row of a dialog.
 *
 * @param {Object} props
 * @param {Object} props.children Buttons.
 */
export function Buttons( { children } ) {
	return <div className="windrose-livery__buttons">{ children }</div>;
}

/**
 * The "replace" choice shown when a variation with the same title exists for the theme.
 *
 * @param {Object}                     props
 * @param {Object}                     props.replaces Existing variation: title, version.
 * @param {boolean}                    props.checked  Ticked.
 * @param {(checked: boolean) => void} props.onChange Change handler.
 */
export function ReplaceChoice( { replaces, checked, onChange } ) {
	return (
		<CheckboxControl
			__nextHasNoMarginBottom
			checked={ checked }
			onChange={ onChange }
			label={ sprintf(
				/* translators: 1: variation title, 2: new version number. */
				__(
					'Replace %1$s (it becomes version %2$d)',
					'windrose-livery'
				),
				replaces.title,
				replaces.version + 1
			) }
			help={ __(
				'Sites that use it keep the current version until you apply the new one.',
				'windrose-livery'
			) }
		/>
	);
}

/**
 * Import a variation file or package: check first (dry run), then add.
 *
 * @param {Object}                   props
 * @param {Object[]}                 props.themes  Installed block themes.
 * @param {() => void}               props.onClose Closes.
 * @param {(answer: Object) => void} props.onDone  Receives the saved variation.
 */
export function ImportModal( { themes, onClose, onDone } ) {
	const [ file, setFile ] = useState( null );
	const [ theme, setTheme ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ replace, setReplace ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const send = async ( dryRun ) => {
		const body = new window.FormData();
		body.append( 'file', file );
		body.append( 'dry_run', dryRun ? 'true' : 'false' );
		body.append( 'theme', dryRun ? theme : preview.theme );
		body.append( 'title', title );
		body.append( 'replace', replace ? 'true' : 'false' );
		setBusy( true );
		setError( '' );
		try {
			const answer = await apiFetch( {
				path: `${ ROOT }/import`,
				method: 'POST',
				body,
			} );
			if ( dryRun ) {
				setPreview( answer );
				setReplace( false );
			} else {
				onDone( answer );
			}
		} catch ( requestError ) {
			setError( requestError.message );
		}
		setBusy( false );
	};

	const tooLarge = file && data.maxUpload && file.size > data.maxUpload;
	return (
		<div className="windrose-livery__modal">
			{ ! preview && (
				<>
					<p>
						{ __(
							'A style variation file (.json, theme.json shape), or a zip holding one variation file, optionally fonts.json and the font files and licenses they name. Nothing is saved until you confirm.',
							'windrose-livery'
						) }
					</p>
					<div className="windrose-livery__field">
						<label htmlFor="windrose-livery-file">
							{ __( 'File', 'windrose-livery' ) }
						</label>
						<input
							id="windrose-livery-file"
							type="file"
							accept=".json,.zip,application/json,application/zip"
							onChange={ ( event ) =>
								setFile( event.target.files?.[ 0 ] || null )
							}
						/>
					</div>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Made for the theme', 'windrose-livery' ) }
						value={ theme }
						onChange={ setTheme }
						options={ [
							{
								value: '',
								label: __(
									'Guess from its color names',
									'windrose-livery'
								),
							},
							...themes,
						] }
						help={ __(
							'The variation shows on this theme and its child themes.',
							'windrose-livery'
						) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title (optional)', 'windrose-livery' ) }
						value={ title }
						onChange={ setTitle }
						help={ __(
							'Empty: the title in the file.',
							'windrose-livery'
						) }
					/>
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
			{ tooLarge && (
				<p className="windrose-livery__error" role="alert">
					{ __(
						'This file is larger than this server accepts for uploads.',
						'windrose-livery'
					) }
				</p>
			) }
			{ error && (
				<p className="windrose-livery__error" role="alert">
					{ error }
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
						disabled={ busy || ! file || tooLarge }
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

/**
 * Save a site's current styles as a variation.
 *
 * @param {Object}                   props
 * @param {Object[]}                 props.sites   Sites.
 * @param {Object[]}                 props.themes  Installed block themes.
 * @param {() => void}               props.onClose Closes.
 * @param {(answer: Object) => void} props.onDone  Receives the saved variation.
 */
export function SaveModal( { sites, themes, onClose, onDone } ) {
	const editable = sites.filter( ( s ) => s.canEdit );
	const [ siteId, setSiteId ] = useState(
		String( editable[ 0 ]?.id ?? data.siteId )
	);
	const site = editable.find( ( s ) => String( s.id ) === siteId );
	const [ title, setTitle ] = useState( '' );
	const [ theme, setTheme ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ replace, setReplace ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const themeName = ( stylesheet ) =>
		themes.find( ( t ) => t.value === stylesheet )?.label || stylesheet;
	const themeOptions = site
		? [ site.stylesheet, site.template ]
				.filter( ( v, i, all ) => v && all.indexOf( v ) === i )
				.map( ( v ) => ( { value: v, label: themeName( v ) } ) )
		: [];

	const send = async ( dryRun ) => {
		setBusy( true );
		setError( '' );
		try {
			const answer = await apiFetch( {
				path: `${ ROOT }/from-site`,
				method: 'POST',
				data: {
					site: Number( siteId ),
					title,
					theme: theme || site?.stylesheet || '',
					dry_run: dryRun,
					replace,
				},
			} );
			if ( dryRun ) {
				setPreview( answer );
				setReplace( false );
			} else {
				onDone( answer );
			}
		} catch ( requestError ) {
			setError( requestError.message );
		}
		setBusy( false );
	};

	return (
		<div className="windrose-livery__modal">
			{ ! preview && (
				<>
					<p>
						{ __(
							'The styles the site saved in its Site Editor (colors, fonts, sizes, block styles) become a stored variation. Fonts installed through its Font Library are copied into the library.',
							'windrose-livery'
						) }
					</p>
					{ data.network && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Site', 'windrose-livery' ) }
							value={ siteId }
							onChange={ ( value ) => {
								setSiteId( value );
								setTheme( '' );
							} }
							options={ editable.map( ( s ) => ( {
								value: String( s.id ),
								label: `${ s.name } (${ s.url })`,
							} ) ) }
						/>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'windrose-livery' ) }
						value={ title }
						onChange={ setTitle }
					/>
					{ themeOptions.length > 1 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Made for the theme',
								'windrose-livery'
							) }
							value={ theme || site?.stylesheet }
							onChange={ setTheme }
							options={ themeOptions }
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
						disabled={ busy || ! site || ! title.trim() }
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

/**
 * What a site's row says in the apply dialog.
 *
 * @param {Object} variation Variation.
 * @param {Object} site      Site.
 * @return {string} Status.
 */
function siteStatus( variation, site ) {
	if ( ! fits( variation, site ) ) {
		return sprintf(
			/* translators: %s: theme name. */
			__( 'Not available: the site uses %s.', 'windrose-livery' ),
			site.themeName
		);
	}
	if ( ! site.canEdit ) {
		return __(
			'You may not change this site’s styles.',
			'windrose-livery'
		);
	}
	const applied = site.applied;
	if ( applied?.id === variation.id ) {
		if ( applied.version < variation.version ) {
			return applied.changed
				? sprintf(
						/* translators: %d: version number. */
						__(
							'Uses version %d, changed since: those changes are replaced.',
							'windrose-livery'
						),
						applied.version
					)
				: sprintf(
						/* translators: %d: version number. */
						__( 'Uses version %d.', 'windrose-livery' ),
						applied.version
					);
		}
		return applied.changed
			? __(
					'Uses it, changed since: those changes are replaced.',
					'windrose-livery'
				)
			: __( 'Uses it already.', 'windrose-livery' );
	}
	return sprintf(
		/* translators: %s: theme name. */
		__( 'Uses %s.', 'windrose-livery' ),
		site.themeName
	);
}

/**
 * Apply a variation to sites.
 *
 * @param {Object}                   props
 * @param {Object}                   props.variation Variation.
 * @param {Object[]}                 props.sites     Sites.
 * @param {number[]}                 props.preset    Sites ticked at first.
 * @param {() => void}               props.onClose   Closes.
 * @param {(answer: Object) => void} props.onDone    Receives the results.
 */
export function ApplyModal( { variation, sites, preset, onClose, onDone } ) {
	const usable = useMemo(
		() => sites.filter( ( s ) => fits( variation, s ) && s.canEdit ),
		[ sites, variation ]
	);
	const [ chosen, setChosen ] = useState(
		() => new Set( preset ?? ( data.network ? [] : [ data.siteId ] ) )
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const toggle = ( id, on ) =>
		setChosen( ( prev ) => {
			const next = new Set( prev );
			if ( on ) {
				next.add( id );
			} else {
				next.delete( id );
			}
			return next;
		} );

	const apply = async () => {
		setBusy( true );
		setError( '' );
		try {
			const answer = await apiFetch( {
				path: `${ ROOT }/variations/${ variation.id }/apply`,
				method: 'POST',
				data: { sites: [ ...chosen ] },
			} );
			onDone( answer.results );
		} catch ( requestError ) {
			setError( requestError.message );
			setBusy( false );
		}
	};

	return (
		<div className="windrose-livery__modal">
			<p>
				{ __(
					'Each site’s current styles are replaced by this variation. The previous styles stay in the Site Editor, Styles, Revisions.',
					'windrose-livery'
				) }
			</p>
			{ data.network ? (
				<fieldset className="windrose-livery__sites">
					<legend>{ __( 'Sites', 'windrose-livery' ) }</legend>
					{ sites.map( ( site ) => (
						<CheckboxControl
							key={ site.id }
							__nextHasNoMarginBottom
							label={ `${ site.name } (${ site.url })` }
							help={ siteStatus( variation, site ) }
							checked={ chosen.has( site.id ) }
							disabled={ ! usable.includes( site ) }
							onChange={ ( on ) => toggle( site.id, on ) }
						/>
					) ) }
				</fieldset>
			) : (
				sites[ 0 ] && <p>{ siteStatus( variation, sites[ 0 ] ) }</p>
			) }
			{ error && (
				<p className="windrose-livery__error" role="alert">
					{ error }
				</p>
			) }
			<Buttons>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ __( 'Cancel', 'windrose-livery' ) }
				</Button>
				{ data.network && usable.length > 1 && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () =>
							setChosen( new Set( usable.map( ( s ) => s.id ) ) )
						}
						disabled={ busy }
					>
						{ __( 'Tick every available site', 'windrose-livery' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy }
					accessibleWhenDisabled
					disabled={
						busy ||
						! [ ...chosen ].some( ( id ) =>
							usable.some( ( s ) => s.id === id )
						)
					}
					onClick={ apply }
				>
					{ __( 'Apply', 'windrose-livery' ) }
				</Button>
			</Buttons>
		</div>
	);
}

/**
 * Results of applying.
 *
 * @param {Object}     props
 * @param {Object[]}   props.results Results per site.
 * @param {() => void} props.onClose Closes.
 */
export function ResultsModal( { results, onClose } ) {
	const statusText = {
		applied: __( 'Applied', 'windrose-livery' ),
		filtered: __( 'Applied in part', 'windrose-livery' ),
		skipped: __( 'Not applied', 'windrose-livery' ),
		failed: __( 'Failed', 'windrose-livery' ),
	};
	return (
		<div className="windrose-livery__modal">
			<ul className="windrose-livery__results">
				{ results.map( ( r ) => (
					<li key={ r.site }>
						<strong>
							{ r.name ||
								sprintf(
									/* translators: %d: site ID. */
									__( 'Site %d', 'windrose-livery' ),
									r.site
								) }
							{ ': ' }
							{ statusText[ r.status ] || r.status }
						</strong>
						{ r.message && <> { r.message }</> }
					</li>
				) ) }
			</ul>
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

/**
 * Confirm deletion.
 *
 * @param {Object}                   props
 * @param {Object}                   props.variation Variation.
 * @param {number}                   props.using     Sites that use it.
 * @param {() => void}               props.onClose   Closes.
 * @param {(answer: Object) => void} props.onDone    Receives the answer.
 */
export function DeleteModal( { variation, using, onClose, onDone } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const remove = async () => {
		setBusy( true );
		setError( '' );
		try {
			onDone(
				await apiFetch( {
					path: `${ ROOT }/variations/${ variation.id }`,
					method: 'DELETE',
				} )
			);
		} catch ( requestError ) {
			setError( requestError.message );
			setBusy( false );
		}
	};
	return (
		<div className="windrose-livery__modal">
			<p>
				{ sprintf(
					/* translators: %s: variation title. */
					__(
						'Delete %s? It disappears from the Site Editor. Sites that use it keep their styles (they hold a copy), and font files a site still uses stay.',
						'windrose-livery'
					),
					variation.title
				) }
			</p>
			{ using > 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Livery can no longer offer those sites a new version of it.',
						'windrose-livery'
					) }
				</Notice>
			) }
			{ error && (
				<p className="windrose-livery__error" role="alert">
					{ error }
				</p>
			) }
			<Buttons>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ __( 'Cancel', 'windrose-livery' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					isBusy={ busy }
					accessibleWhenDisabled
					disabled={ busy }
					onClick={ remove }
				>
					{ __( 'Delete', 'windrose-livery' ) }
				</Button>
			</Buttons>
		</div>
	);
}
