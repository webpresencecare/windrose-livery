/**
 * Livery's screen: the switches of each site (on a site's own screen, of that site): Style Mermaid on public pages,
 * and light and dark.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { data, ROOT } from './parts';

/**
 * Light and dark options of one site.
 *
 * @param {Object}                   props
 * @param {Object}                   props.site Site row.
 * @param {(value: unknown) => void} props.save Saves changes.
 */
function LightDark( { site, save } ) {
	let help = '';
	if ( 'dark' === site.twins ) {
		help = __(
			'This site’s style has dark twin colours: the switch offers dark; its own light colours stay the default.',
			'windrose-livery'
		);
	} else if ( 'light' === site.twins ) {
		help = __(
			'This site’s style has light twin colours: the switch offers light; its own dark colours stay the default.',
			'windrose-livery'
		);
	} else {
		help = __(
			'The style this site uses has no light or dark twin colours yet, so these options do nothing for now. Add them in Style Mermaid (Colors, Light and dark) or in the Site Editor (Options ⋮, Add a dark palette).',
			'windrose-livery'
		);
	}
	return (
		<fieldset className="windrose-livery__fieldset">
			<legend>{ __( 'Light and dark', 'windrose-livery' ) }</legend>
			<p className="windrose-livery__muted">{ help }</p>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Show the light and dark switch',
					'windrose-livery'
				) }
				help={ __(
					'The block "Light and dark switch" shows for visitors, and is placed after the header’s menu (move or remove it in the Site Editor). A visitor’s choice stays in their browser only.',
					'windrose-livery'
				) }
				checked={ site.switch }
				onChange={ ( on ) => save( site, { switch: on } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Follow the visitor’s browser preference',
					'windrose-livery'
				) }
				help={ __(
					'Visitors whose device is set to the other scheme get it at once (their own choice with the switch still wins).',
					'windrose-livery'
				) }
				checked={ site.follow }
				onChange={ ( on ) => save( site, { follow: on } ) }
			/>
		</fieldset>
	);
}

/**
 * The switches.
 *
 * @param {Object}                   props
 * @param {(value: unknown) => void} props.notify Shows a notice.
 */
export default function MermaidSettings( { notify } ) {
	const [ sites, setSites ] = useState( null );
	useEffect( () => {
		apiFetch( { path: `${ ROOT }/mermaid/settings` } )
			.then( setSites )
			.catch( ( e ) => notify( 'error', e.message ) );
	}, [ notify ] );
	const save = async ( site, changes ) => {
		try {
			const answer = await apiFetch( {
				path: `${ ROOT }/mermaid/settings`,
				method: 'POST',
				data: { site: site.id, ...changes },
			} );
			setSites( ( list ) =>
				list.map( ( s ) =>
					s.id === site.id ? { ...s, ...answer } : s
				)
			);
		} catch ( e ) {
			notify( 'error', e.message );
		}
	};
	return (
		<Card className="windrose-livery__card">
			<CardHeader>
				<h2 className="windrose-livery__card-title">
					{ data.network
						? __( 'Each site’s switches', 'windrose-livery' )
						: __( 'This site’s switches', 'windrose-livery' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Style Mermaid on public pages: the block Style Mermaid lets visitors make a style for Ipsum and download it, with no account. Off by default. Visitors are limited to 20 downloads and 20 new fonts an hour (counted with a hash of their address, kept one hour); the site’s privacy policy guide has a paragraph about it.',
						'windrose-livery'
					) }
				</p>
				{ null === sites ? (
					<Spinner />
				) : (
					<ul className="windrose-livery__mermaid-sites">
						{ sites.map( ( site ) => (
							<li key={ site.id }>
								{ data.network && (
									<strong>
										{ site.name || site.url }{ ' ' }
										<span className="windrose-livery__muted">
											{ site.url }
										</span>
									</strong>
								) }
								<ToggleControl
									__nextHasNoMarginBottom
									label={
										data.network
											? sprintf(
													/* translators: %s: site name. */
													__(
														'Style Mermaid for visitors on %s',
														'windrose-livery'
													),
													site.name || site.url
												)
											: __(
													'Style Mermaid for visitors',
													'windrose-livery'
												)
									}
									checked={ site.public }
									onChange={ ( on ) =>
										save( site, { public: on } )
									}
								/>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Fonts from Google Fonts and Bunny Fonts for visitors',
										'windrose-livery'
									) }
									help={
										site.remoteLocked
											? __(
													'Set by the network’s administrators: the network’s server downloads these fonts.',
													'windrose-livery'
												)
											: __(
													'This website downloads a font a visitor chooses, once, and serves it itself: their browser never contacts those services.',
													'windrose-livery'
												)
									}
									checked={ site.remote }
									disabled={
										! site.public || site.remoteLocked
									}
									onChange={ ( on ) =>
										save( site, { remote: on } )
									}
								/>
								<LightDark site={ site } save={ save } />
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}
