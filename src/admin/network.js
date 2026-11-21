/**
 * Livery's network screen: the switch for site administrators, every site's own styles with "Copy to the shared
 * library", and the move of fonts from versions before 0.4 into the Font Library.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Modal,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { data, FontList, ROOT, Swatches } from './parts';
import { Buttons } from './modals';

/**
 * The switch "Site administrators may use Livery on their own site".
 *
 * @param {Object}                   props
 * @param {(value: unknown) => void} props.notify Shows a notice.
 */
export function SiteAdminsCard( { notify } ) {
	const [ on, setOn ] = useState( !! data.siteAdmins );
	const [ busy, setBusy ] = useState( false );
	const save = async ( value ) => {
		setBusy( true );
		try {
			const answer = await apiFetch( {
				path: `${ ROOT }/network`,
				method: 'POST',
				data: { site_admins: value },
			} );
			setOn( answer.siteAdmins );
			notify(
				'success',
				answer.siteAdmins
					? __(
							'Site administrators now find Appearance, Style variations on their own site.',
							'windrose-livery'
						)
					: __(
							'Site administrators no longer see Livery’s screen. Their sites’ own styles stay and keep working.',
							'windrose-livery'
						)
			);
		} catch ( e ) {
			notify( 'error', e.message );
		}
		setBusy( false );
	};
	return (
		<Card className="windrose-livery__card">
			<CardHeader>
				<h2 className="windrose-livery__card-title">
					{ __( 'Site administrators', 'windrose-livery' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Site administrators may use Livery on their own site',
						'windrose-livery'
					) }
					help={ __(
						'Off by default. When on, each site’s administrators get Appearance, Style variations: Style Mermaid, import, export and delete of their site’s own styles, "Save styles as a variation" in their Site Editor, and their site’s switches. Their styles stay on their site, with their fonts in their site’s Font Library; you can copy one into the shared library below. The shared library and font packs stay yours.',
						'windrose-livery'
					) }
					checked={ on }
					disabled={ busy }
					onChange={ save }
				/>
			</CardBody>
		</Card>
	);
}

/**
 * The dialog of "Copy to the shared library": check, then copy (or replace).
 *
 * @param {Object}                   props
 * @param {Object}                   props.item    The site's variation.
 * @param {(value: unknown) => void} props.onClose Closes.
 * @param {(value: unknown) => void} props.onDone  Called with the copy.
 */
function CopyModal( { item, onClose, onDone } ) {
	const [ preview, setPreview ] = useState( null );
	const [ replace, setReplace ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( true );
	const send = useCallback(
		( dryRun, replacing = false ) =>
			apiFetch( {
				path: `${ ROOT }/variations/${ item.id }/copy?scope=${ item.scope }`,
				method: 'POST',
				data: { dry_run: dryRun, replace: replacing },
			} ),
		[ item ]
	);
	useEffect( () => {
		send( true )
			.then( setPreview )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	}, [ send ] );
	const copy = async () => {
		setBusy( true );
		try {
			onDone( await send( false, replace ) );
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: variation title. */
				__( 'Copy %s to the shared library', 'windrose-livery' ),
				item.title
			) }
			onRequestClose={ onClose }
			size="medium"
		>
			{ busy && ! preview && <Spinner /> }
			{ preview && (
				<p>
					{ sprintf(
						/* translators: 1: site name, 2: theme name. */
						__(
							'The copy is offered on every site of the network that uses %2$s or one of its child themes. %1$s keeps its own.',
							'windrose-livery'
						),
						item.siteName,
						preview.themeName
					) }
				</p>
			) }
			{ preview?.replaces && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf(
						/* translators: 1: variation title, 2: version number. */
						__(
							'The shared library has %1$s (version %2$d) for this theme: replace it with this one (as a new version)',
							'windrose-livery'
						),
						preview.replaces.title,
						preview.replaces.version
					) }
					checked={ replace }
					onChange={ setReplace }
				/>
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
				>
					{ __( 'Cancel', 'windrose-livery' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy }
					accessibleWhenDisabled
					disabled={
						busy || ! preview || ( preview.replaces && ! replace )
					}
					onClick={ copy }
				>
					{ __( 'Copy', 'windrose-livery' ) }
				</Button>
			</Buttons>
		</Modal>
	);
}

/**
 * Every site's own styles.
 *
 * @param {Object}                   props
 * @param {(value: unknown) => void} props.notify   Shows a notice.
 * @param {(value: unknown) => void} props.onCopy   Called after a copy (the shared list changed).
 * @param {(value: unknown) => void} props.onBrowse Opens Browse patterns for a style.
 */
export function SiteStylesCard( { notify, onCopy, onBrowse } ) {
	const [ items, setItems ] = useState( null );
	const [ copying, setCopying ] = useState( null );
	const load = useCallback( () => {
		apiFetch( { path: `${ ROOT }/site-styles` } )
			.then( setItems )
			.catch( ( e ) => notify( 'error', e.message ) );
	}, [ notify ] );
	useEffect( () => {
		load();
	}, [ load ] );
	const [ deleting, setDeleting ] = useState( null );
	const remove = async ( item ) => {
		try {
			await apiFetch( {
				path: `${ ROOT }/variations/${ item.id }?scope=${ item.scope }`,
				method: 'DELETE',
			} );
			setDeleting( null );
			load();
		} catch ( e ) {
			setDeleting( null );
			notify( 'error', e.message );
		}
	};
	return (
		<Card className="windrose-livery__card">
			<CardHeader>
				<h2 className="windrose-livery__card-title">
					{ __( 'Sites’ own styles', 'windrose-livery' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Styles that site administrators keep for their own site (offered on that site only).',
						'windrose-livery'
					) }
				</p>
				{ null === items && <Spinner /> }
				{ items && ! items.length && (
					<p className="windrose-livery__muted">
						{ __(
							'No site has its own styles.',
							'windrose-livery'
						) }
					</p>
				) }
				{ items && items.length > 0 && (
					<ul className="windrose-livery__site-styles">
						{ items.map( ( item ) => (
							<li key={ `${ item.scope }-${ item.id }` }>
								<span>
									<strong>{ item.title }</strong>{ ' ' }
									<span className="windrose-livery__muted">
										{ sprintf(
											/* translators: 1: site name, 2: theme name, 3: version number. */
											__(
												'%1$s, for %2$s, version %3$d',
												'windrose-livery'
											),
											item.siteName,
											item.themeName,
											item.version
										) }
									</span>
								</span>
								<Swatches palette={ item.palette } />
								<FontList fonts={ item.fonts } />
								<span className="windrose-livery__header-buttons">
									<Button
										variant="tertiary"
										size="compact"
										onClick={ () => onBrowse( item ) }
									>
										{ __(
											'Browse patterns',
											'windrose-livery'
										) }
									</Button>
									<Button
										variant="secondary"
										size="compact"
										onClick={ () => setCopying( item ) }
									>
										{ __(
											'Copy to the shared library',
											'windrose-livery'
										) }
									</Button>
									<Button
										variant="tertiary"
										size="compact"
										isDestructive
										onClick={ () => setDeleting( item ) }
									>
										{ __( 'Delete', 'windrose-livery' ) }
									</Button>
								</span>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
			{ deleting && (
				<Modal
					title={ __( 'Delete style variation', 'windrose-livery' ) }
					onRequestClose={ () => setDeleting( null ) }
					size="medium"
				>
					<p>
						{ sprintf(
							/* translators: 1: variation title, 2: site name. */
							__(
								'Delete %1$s from %2$s’s own styles? Sites that use it keep their copy.',
								'windrose-livery'
							),
							deleting.title,
							deleting.siteName
						) }
					</p>
					<Buttons>
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setDeleting( null ) }
						>
							{ __( 'Cancel', 'windrose-livery' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							onClick={ () => remove( deleting ) }
						>
							{ __( 'Delete', 'windrose-livery' ) }
						</Button>
					</Buttons>
				</Modal>
			) }
			{ copying && (
				<CopyModal
					item={ copying }
					onClose={ () => setCopying( null ) }
					onDone={ ( copy ) => {
						setCopying( null );
						notify(
							'success',
							sprintf(
								/* translators: 1: variation title, 2: version number. */
								__(
									'%1$s is in the shared library (version %2$d).',
									'windrose-livery'
								),
								copy.title,
								copy.version
							)
						);
						onCopy();
					} }
				/>
			) }
		</Card>
	);
}
