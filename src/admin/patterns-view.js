/**
 * Livery's screen, Browse patterns (0.6): the patterns of a site with a stored style applied, for this preview only.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import PatternBrowser from '../mermaid/patterns';
import { data, ROOT } from './parts';

/**
 * A style as an option of the browser.
 *
 * @param {Object} item  Variation summary.
 * @param {string} label Label.
 * @return {Object} Option.
 */
const option = ( item, label ) => ( {
	value: `${ item.scope ?? 0 }:${ item.id }`,
	label,
	id: item.id,
	scope: item.scope ?? 0,
	theme: item.theme,
	scheme: item.scheme ?? '',
} );

/**
 * The view.
 *
 * @param {Object}     props
 * @param {Object[]}   props.items   The library's variations.
 * @param {Object[]}   props.sites   Sites.
 * @param {Object}     props.initial { id, scope } of the style to start with.
 * @param {() => void} props.onClose Back to the list.
 */
export default function PatternsView( { items, sites, initial, onClose } ) {
	// On the network screen, the sites' own styles too (their card opens this view as well).
	const [ own, setOwn ] = useState( data.network ? null : [] );
	useEffect( () => {
		if ( data.network ) {
			apiFetch( { path: `${ ROOT }/site-styles` } )
				.then( setOwn )
				.catch( () => setOwn( [] ) );
		}
	}, [] );
	const [ style, setStyle ] = useState(
		`${ initial.scope ?? 0 }:${ initial.id }`
	);
	const [ width, setWidth ] = useState( 'desktop' );
	const [ showTwins, setShowTwins ] = useState( false );

	const styles = [
		...items.map( ( v ) => option( v, v.title ) ),
		...( own ?? [] ).map( ( v ) =>
			option(
				v,
				sprintf(
					/* translators: 1: style title, 2: site name. */
					__( '%1$s (own style of %2$s)', 'windrose-livery' ),
					v.title,
					v.siteName
				)
			)
		),
	];
	const current = styles.find( ( s ) => s.value === style );

	return (
		<Card className="windrose-livery__card windrose-livery__patterns-view">
			<CardHeader>
				<h2 className="windrose-livery__card-title">
					{ current
						? sprintf(
								/* translators: %s: style title. */
								__( 'Patterns in %s', 'windrose-livery' ),
								current.label
							)
						: __( 'Patterns', 'windrose-livery' ) }
				</h2>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Back to the list', 'windrose-livery' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'WordPress draws the site’s patterns with the style applied, for this preview only: nothing is saved and the site keeps its own styles. In a site’s Site Editor, Styles shows the same when you pick a style without saving, then open Patterns.',
						'windrose-livery'
					) }
				</p>
				<div className="windrose-livery-mermaid__preview-bar">
					{ current?.scheme && (
						<button
							type="button"
							className="windrose-livery-mermaid__chip"
							aria-pressed={ showTwins }
							onClick={ () => setShowTwins( ! showTwins ) }
						>
							{ __( 'Dark/light switch', 'windrose-livery' ) }
						</button>
					) }
					<span className="windrose-livery-mermaid__preview-widths">
						{ [
							[ 'desktop', __( 'Wide', 'windrose-livery' ) ],
							[ 'phone', __( 'Phone', 'windrose-livery' ) ],
						].map( ( [ id, label ] ) => (
							<button
								key={ id }
								type="button"
								className="windrose-livery-mermaid__chip"
								aria-pressed={ width === id }
								onClick={ () => setWidth( id ) }
							>
								{ label }
							</button>
						) ) }
					</span>
				</div>
				{ null === own || ! sites.length ? (
					<Spinner />
				) : (
					<PatternBrowser
						sites={ sites }
						styles={ styles }
						style={ current ? style : styles[ 0 ]?.value }
						onStyle={ ( value ) => {
							setStyle( value );
							setShowTwins( false );
						} }
						nonce={ data.mermaid?.patterns?.nonce ?? '' }
						width={ width }
						scheme={
							current?.scheme && showTwins ? current.scheme : ''
						}
						layout="side"
					/>
				) }
			</CardBody>
		</Card>
	);
}
