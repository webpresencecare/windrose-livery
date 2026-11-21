/**
 * Windrose Livery screen: stored style variations (Network Admin, Themes, Style variations; on a single site,
 * Appearance, Style variations).
 */
import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Modal,
	Notice,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { data, FontList, ROOT, Swatches, useNotice } from './parts';
import MermaidApp from '../mermaid/app';
import MermaidSettings from './mermaid-settings';
import PatternsView from './patterns-view';
import FontPacks from './font-packs';
import { SiteAdminsCard, SiteStylesCard } from './network';
import {
	ApplyModal,
	DeleteModal,
	ImportModal,
	ResultsModal,
	SaveModal,
} from './modals';
import './admin.scss';

// On a site's own screen every request names that site's library (the shared one otherwise).
if ( data.scope ) {
	apiFetch.use( ( options, next ) => {
		const path = options.path ?? '';
		return next(
			path.startsWith( ROOT ) && ! /[?&]scope=/.test( path )
				? {
						...options,
						path: addQueryArgs( path, { scope: data.scope } ),
					}
				: options
		);
	} );
}

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'modified', direction: 'desc' },
	titleField: 'title',
	fields: [ 'palette', 'scheme', 'fonts', 'themeName', 'version', 'using' ],
	layout: {},
};

function App() {
	const [ state, setState ] = useState( null );
	const [ sites, setSites ] = useState( [] );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ dialog, setDialog ] = useState( null );
	const [ notice, notify ] = useNotice();
	// Which view: the list, Create a style (view=create) or Browse patterns (view=patterns&style=<id>&scope=<n>).
	const readRoute = () => {
		const params = new window.URLSearchParams( window.location.search );
		return {
			view: params.get( 'view' ) ?? '',
			id: Number( params.get( 'style' ) ?? 0 ),
			scope: Number( params.get( 'scope' ) ?? 0 ),
		};
	};
	const [ route, setRoute ] = useState( readRoute );
	const go = ( next = {} ) => {
		const url = new window.URL( window.location.href );
		for ( const name of [ 'view', 'style', 'scope' ] ) {
			url.searchParams.delete( name );
		}
		if ( next.view ) {
			url.searchParams.set( 'view', next.view );
		}
		if ( next.id ) {
			url.searchParams.set( 'style', String( next.id ) );
			url.searchParams.set( 'scope', String( next.scope ?? 0 ) );
		}
		window.history.pushState( null, '', url );
		setRoute( readRoute() );
		window.scrollTo( 0, 0 );
	};
	const openCreate = ( on ) => go( on ? { view: 'create' } : {} );
	const browse = ( item ) =>
		go( { view: 'patterns', id: item.id, scope: item.scope ?? 0 } );
	useEffect( () => {
		const onPop = () => setRoute( readRoute() );
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	const load = useCallback( async () => {
		try {
			const [ variations, siteRows ] = await Promise.all( [
				apiFetch( { path: `${ ROOT }/variations` } ),
				apiFetch( { path: `${ ROOT }/sites` } ),
			] );
			setState( variations );
			setSites( siteRows );
		} catch ( error ) {
			setState( { items: [], themes: [] } );
			notify( 'error', error.message );
		}
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const usingOf = useCallback(
		( variation ) =>
			sites.filter(
				( s ) =>
					s.applied?.id === variation.id &&
					( s.applied?.scope ?? 0 ) === ( variation.scope ?? 0 )
			),
		[ sites ]
	);

	const items = useMemo(
		() =>
			( state?.items ?? [] ).map( ( item ) => ( {
				...item,
				using: usingOf( item ).length,
			} ) ),
		[ state, usingOf ]
	);

	const outdated = useMemo(
		() =>
			items
				.map( ( item ) => ( {
					item,
					sites: usingOf( item ).filter(
						( s ) => s.applied.version < item.version
					),
				} ) )
				.filter( ( row ) => row.sites.length ),
		[ items, usingOf ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'title',
				type: 'text',
				label: __( 'Title', 'windrose-livery' ),
				enableHiding: false,
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<span>
						<strong>{ item.title }</strong>
						{ item.description && (
							<span className="windrose-livery__muted">
								{ ' ' }
								{ item.description }
							</span>
						) }
					</span>
				),
			},
			{
				id: 'palette',
				label: __( 'Colors', 'windrose-livery' ),
				enableSorting: false,
				render: ( { item } ) => <Swatches palette={ item.palette } />,
			},
			{
				id: 'scheme',
				label: __( 'Light and dark', 'windrose-livery' ),
				enableSorting: false,
				render: ( { item } ) => {
					if ( ! item.scheme ) {
						return (
							<span className="windrose-livery__muted">
								{ __( 'One palette', 'windrose-livery' ) }
							</span>
						);
					}
					const name =
						'dark' === item.scheme
							? __( 'Dark palette too', 'windrose-livery' )
							: __( 'Light palette too', 'windrose-livery' );
					return (
						<span>
							{ name }
							{ false === item.contrast?.twin && (
								<span className="windrose-livery__warn">
									{ ' ' }
									{ __(
										'(contrast too low)',
										'windrose-livery'
									) }
								</span>
							) }
						</span>
					);
				},
			},
			{
				id: 'fonts',
				label: __( 'Fonts', 'windrose-livery' ),
				enableSorting: false,
				render: ( { item } ) => <FontList fonts={ item.fonts } />,
			},
			{
				id: 'themeName',
				type: 'text',
				label: __( 'Theme', 'windrose-livery' ),
				enableGlobalSearch: true,
				elements: [
					...new Set( items.map( ( item ) => item.themeName ) ),
				].map( ( name ) => ( { value: name, label: name } ) ),
				filterBy: { operators: [ 'isAny' ] },
			},
			{
				id: 'version',
				type: 'integer',
				label: __( 'Version', 'windrose-livery' ),
			},
			{
				id: 'using',
				type: 'integer',
				label: data.network
					? __( 'Sites using it', 'windrose-livery' )
					: __( 'In use', 'windrose-livery' ),
				render: ( { item } ) => {
					if ( ! data.network ) {
						return item.using
							? __( 'Yes', 'windrose-livery' )
							: __( 'No', 'windrose-livery' );
					}
					// One name a line, each isolated (<bdi>): an Arabic name must not reorder the text around it.
					return item.using ? (
						<span className="windrose-livery__site-names">
							{ usingOf( item ).map( ( s ) => (
								<span
									key={ s.id }
									className="windrose-livery__site-names"
								>
									<bdi>{ s.name }</bdi>
								</span>
							) ) }
						</span>
					) : (
						__( 'None', 'windrose-livery' )
					);
				},
			},
			{
				id: 'modified',
				type: 'datetime',
				label: __( 'Updated', 'windrose-livery' ),
			},
		],
		[ items, usingOf ]
	);

	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	const actions = [
		{
			id: 'patterns',
			label: __( 'Browse patterns', 'windrose-livery' ),
			callback: ( [ item ] ) => browse( item ),
		},
		{
			id: 'apply',
			label: data.network
				? __( 'Apply to sites', 'windrose-livery' )
				: __( 'Apply to this site', 'windrose-livery' ),
			// In the ⋮ menu only: a primary action keeps a wide button column that pushes the table sideways.
			callback: ( [ item ] ) => setDialog( { kind: 'apply', item } ),
		},
		{
			id: 'export',
			label: __( 'Export', 'windrose-livery' ),
			callback: ( [ item ] ) => {
				window.location.href = `${ data.exportUrl }&id=${ item.id }&scope=${
					item.scope ?? 0
				}`;
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'windrose-livery' ),
			isDestructive: true,
			callback: ( [ item ] ) => setDialog( { kind: 'delete', item } ),
		},
	];

	const saved = ( variation ) => {
		setDialog( null );
		notify(
			'success',
			sprintf(
				/* translators: 1: variation title, 2: version number. */
				__(
					'%1$s saved (version %2$d). It now appears in the Site Editor, Styles, Browse styles, on its theme.',
					'windrose-livery'
				),
				variation.title,
				variation.version
			)
		);
		load();
	};

	const titles = {
		import: __( 'Import a style variation', 'windrose-livery' ),
		save: data.network
			? __( 'Save a site’s styles as a variation', 'windrose-livery' )
			: __( 'Save this site’s styles as a variation', 'windrose-livery' ),
		apply: dialog?.item
			? sprintf(
					/* translators: %s: variation title. */
					__( 'Apply %s', 'windrose-livery' ),
					dialog.item.title
				)
			: '',
		results: __( 'Results', 'windrose-livery' ),
		delete: __( 'Delete style variation', 'windrose-livery' ),
	};

	if ( 'create' === route.view ) {
		return (
			<>
				{ notice }
				<MermaidApp
					config={ data.mermaid }
					library={ state?.items ?? [] }
					sites={ sites }
					onSaved={ () => load() }
					onClose={ () => openCreate( false ) }
				/>
			</>
		);
	}

	if ( 'patterns' === route.view && route.id ) {
		return (
			<>
				{ notice }
				{ null === state ? null : (
					<PatternsView
						items={ state.items ?? [] }
						sites={ sites }
						initial={ route }
						onClose={ () => go() }
					/>
				) }
			</>
		);
	}

	return (
		<>
			{ notice }
			{ outdated.map( ( { item, sites: old } ) => (
				<Notice
					key={ item.id }
					status="warning"
					isDismissible={ false }
					className="windrose-livery__notice"
					actions={ [
						{
							label: __(
								'Apply the new version',
								'windrose-livery'
							),
							onClick: () =>
								setDialog( {
									kind: 'apply',
									item,
									preset: old.map( ( s ) => s.id ),
								} ),
							variant: 'secondary',
						},
					] }
				>
					{ sprintf(
						/* translators: 1: number of sites, 2: variation title, 3: version number. */
						_n(
							'%1$d site uses an older version of %2$s (now version %3$d).',
							'%1$d sites use an older version of %2$s (now version %3$d).',
							old.length,
							'windrose-livery'
						),
						old.length,
						item.title,
						item.version
					) }
				</Notice>
			) ) }
			<Card className="windrose-livery__card">
				<CardHeader>
					<h2 className="windrose-livery__card-title">
						{ __( 'Stored variations', 'windrose-livery' ) }
					</h2>
					<div className="windrose-livery__header-buttons">
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () => setDialog( { kind: 'save' } ) }
							disabled={ ! sites.some( ( s ) => s.canEdit ) }
							accessibleWhenDisabled
						>
							{ data.network
								? __(
										'Save a site’s styles',
										'windrose-livery'
									)
								: __(
										'Save this site’s styles',
										'windrose-livery'
									) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () => setDialog( { kind: 'import' } ) }
						>
							{ __( 'Import', 'windrose-livery' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ () => openCreate( true ) }
						>
							{ __( 'Create a style', 'windrose-livery' ) }
						</Button>
					</div>
				</CardHeader>
				<CardBody>
					{ data.scope > 0 && (
						<p>
							{ sprintf(
								/* translators: %s: site name. */
								__(
									'These are %s’s own style variations: they appear in this site’s Site Editor, Styles, Browse styles, next to the theme’s own and the network’s shared ones. Fonts they need are in this site’s Font Library (Styles, Typography, Fonts).',
									'windrose-livery'
								),
								data.siteName
							) }
						</p>
					) }
					<p>
						{ data.network
							? __(
									'Style variations kept here appear on every site of the network, in the Site Editor, Styles, Browse styles, when the site uses the theme they were made for or one of its child themes. Theme updates never remove them.',
									'windrose-livery'
								)
							: __(
									'Style variations kept here appear in the Site Editor, Styles, Browse styles, when the site uses the theme they were made for or one of its child themes. Theme updates never remove them.',
									'windrose-livery'
								) }
					</p>
					<p>
						{ __(
							'Picking a variation copies it into a site’s styles: later changes to the variation reach a site only when you apply the new version.',
							'windrose-livery'
						) }{ ' ' }
						{ ! data.network && data.editor && (
							<ExternalLink href={ data.editor }>
								{ __(
									'Styles in the Site Editor',
									'windrose-livery'
								) }
							</ExternalLink>
						) }
					</p>
				</CardBody>
				<CardBody className="windrose-livery__table">
					<DataViews
						data={ shown }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ paginationInfo }
						defaultLayouts={ { table: {} } }
						getItemId={ ( item ) => String( item.id ) }
						isLoading={ state === null }
						searchLabel={ __(
							'Search style variations',
							'windrose-livery'
						) }
						empty={
							<p>
								{ __(
									'No stored style variation yet: import one, or save a site’s styles.',
									'windrose-livery'
								) }
							</p>
						}
					/>
				</CardBody>
			</Card>
			<MermaidSettings notify={ notify } />
			{ data.network && (
				<>
					<SiteAdminsCard notify={ notify } />
					<SiteStylesCard
						notify={ notify }
						onCopy={ load }
						onBrowse={ browse }
					/>
				</>
			) }
			{ data.mermaid?.network && <FontPacks notify={ notify } /> }
			{ dialog && (
				<Modal
					title={ titles[ dialog.kind ] }
					onRequestClose={ () => setDialog( null ) }
					size={ 'apply' === dialog.kind ? 'medium' : 'large' }
				>
					{ 'import' === dialog.kind && (
						<ImportModal
							themes={ state?.themes ?? [] }
							onClose={ () => setDialog( null ) }
							onDone={ saved }
						/>
					) }
					{ 'save' === dialog.kind && (
						<SaveModal
							sites={ sites }
							themes={ state?.themes ?? [] }
							onClose={ () => setDialog( null ) }
							onDone={ saved }
						/>
					) }
					{ 'apply' === dialog.kind && (
						<ApplyModal
							variation={ dialog.item }
							sites={ sites }
							preset={ dialog.preset }
							onClose={ () => setDialog( null ) }
							onDone={ ( results ) => {
								setDialog( { kind: 'results', results } );
								load();
							} }
						/>
					) }
					{ 'results' === dialog.kind && (
						<ResultsModal
							results={ dialog.results }
							onClose={ () => setDialog( null ) }
						/>
					) }
					{ 'delete' === dialog.kind && (
						<DeleteModal
							variation={ dialog.item }
							using={ usingOf( dialog.item ).length }
							onClose={ () => setDialog( null ) }
							onDone={ ( answer ) => {
								setDialog( null );
								notify(
									'success',
									sprintf(
										/* translators: 1: variation title, 2: number of files kept. */
										_n(
											'%1$s deleted. %2$d font file is kept, with its license, because a site still uses it.',
											'%1$s deleted. %2$d font files are kept, with their licenses, because sites still use them.',
											answer.fontsKept,
											'windrose-livery'
										),
										dialog.item.title,
										answer.fontsKept
									)
								);
								load();
							} }
						/>
					) }
				</Modal>
			) }
		</>
	);
}

const container = document.getElementById( 'windrose-livery-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
