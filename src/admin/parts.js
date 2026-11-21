/**
 * Windrose Livery screen: small shared parts (notice line, color swatches, the preview of a variation).
 */
import { useCallback, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';

export const data = window.windroseLivery || {};
export const ROOT = data.root || '/windrose-livery/v1';

/**
 * A notice line that screen readers also hear.
 *
 * @return {Array} [ notice element, notify( status, message ) ].
 */
export function useNotice() {
	const [ notice, setNotice ] = useState( null );
	const notify = useCallback( ( status, message ) => {
		setNotice( message ? { status, message } : null );
		if ( message ) {
			speak( message, 'error' === status ? 'assertive' : 'polite' );
		}
	}, [] );
	const element = notice ? (
		<Notice
			status={ notice.status }
			onRemove={ () => setNotice( null ) }
			className="windrose-livery__notice"
		>
			{ notice.message }
		</Notice>
	) : null;
	return [ element, notify ];
}

/**
 * Whether a variation fits a site's theme (made for it, or for its parent).
 *
 * @param {Object} variation Variation summary.
 * @param {Object} site      Site row.
 * @return {boolean} Fits.
 */
export function fits( variation, site ) {
	return (
		variation.theme === site.stylesheet || variation.theme === site.template
	);
}

/**
 * Color swatches with the color names for screen readers.
 *
 * @param {Object}   props
 * @param {Object[]} props.palette Colors: slug, name, color.
 * @param {number}   props.max     Swatches shown.
 */
export function Swatches( { palette, max = 9 } ) {
	if ( ! palette?.length ) {
		return (
			<span className="windrose-livery__muted">
				{ __( 'No colors', 'windrose-livery' ) }
			</span>
		);
	}
	const label = sprintf(
		/* translators: 1: number of colors, 2: their names. */
		_n(
			'%1$d color: %2$s',
			'%1$d colors: %2$s',
			palette.length,
			'windrose-livery'
		),
		palette.length,
		palette.map( ( c ) => c.name ).join( ', ' )
	);
	return (
		<span
			className="windrose-livery__swatches"
			role="img"
			aria-label={ label }
		>
			{ palette.slice( 0, max ).map( ( c ) => (
				<span
					key={ c.slug }
					className="windrose-livery__swatch"
					style={ { background: c.color } }
					title={ `${ c.name } ${ c.color }` }
				/>
			) ) }
		</span>
	);
}

/**
 * Font family names, with their number of files.
 *
 * @param {Object}   props
 * @param {Object[]} props.fonts Fonts: name, slug, faces.
 */
export function FontList( { fonts } ) {
	if ( ! fonts?.length ) {
		return (
			<span className="windrose-livery__muted">
				{ __( 'Theme fonts', 'windrose-livery' ) }
			</span>
		);
	}
	return fonts
		.map( ( f ) =>
			f.faces
				? sprintf(
						/* translators: 1: font name, 2: number of font files. */
						_n(
							'%1$s (%2$d file)',
							'%1$s (%2$d files)',
							f.faces,
							'windrose-livery'
						),
						f.name,
						f.faces
					)
				: f.name
		)
		.join( ', ' );
}

/**
 * What a dry run found: the preview shown before anything is saved.
 *
 * @param {Object} props
 * @param {Object} props.preview Dry run answer.
 */
export function Preview( { preview } ) {
	return (
		<div className="windrose-livery__preview">
			<dl>
				<dt>{ __( 'Title', 'windrose-livery' ) }</dt>
				<dd>{ preview.title }</dd>
				<dt>{ __( 'Theme', 'windrose-livery' ) }</dt>
				<dd>
					{ sprintf(
						/* translators: %s: theme name. */
						__( '%s and its child themes', 'windrose-livery' ),
						preview.themeName
					) }
				</dd>
				<dt>{ __( 'Colors', 'windrose-livery' ) }</dt>
				<dd>
					<Swatches palette={ preview.palette } />
					{ preview.palette?.length > 0 && (
						<ul className="windrose-livery__colors">
							{ preview.palette.map( ( c ) => (
								<li key={ c.slug }>
									<span
										className="windrose-livery__swatch"
										style={ { background: c.color } }
										aria-hidden="true"
									/>{ ' ' }
									{ c.name } <code>{ c.slug }</code>{ ' ' }
									<code>{ c.color }</code>
								</li>
							) ) }
						</ul>
					) }
				</dd>
				<dt>{ __( 'Fonts', 'windrose-livery' ) }</dt>
				<dd>
					<FontList fonts={ preview.fonts } />
				</dd>
				<dt>{ __( 'Custom CSS', 'windrose-livery' ) }</dt>
				<dd>
					{ preview.css
						? __( 'Yes', 'windrose-livery' )
						: __( 'None', 'windrose-livery' ) }
				</dd>
			</dl>
			{ preview.notes?.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __( 'Some parts were left out:', 'windrose-livery' ) }
					</p>
					<ul className="windrose-livery__notes">
						{ preview.notes.map( ( note ) => (
							<li key={ note }>{ note }</li>
						) ) }
					</ul>
				</Notice>
			) }
		</div>
	);
}
