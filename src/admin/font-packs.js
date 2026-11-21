/**
 * Livery's screen: font packs (fonts for other scripts without Google Fonts or Bunny Fonts): install from GitHub,
 * upload, remove.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormFileUpload,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { ROOT } from './parts';
import { scriptNames } from '../mermaid/labels';

/**
 * A size in MB or KB.
 *
 * @param {number} bytes Bytes.
 * @return {string} Text.
 */
export function size( bytes ) {
	return bytes >= 1048576
		? sprintf(
				/* translators: %s: size in megabytes. */
				__( '%s MB', 'windrose-livery' ),
				( bytes / 1048576 ).toFixed( 1 )
			)
		: sprintf(
				/* translators: %s: size in kilobytes. */
				__( '%s KB', 'windrose-livery' ),
				Math.max( 1, Math.round( bytes / 1024 ) )
			);
}

/**
 * The card.
 *
 * @param {Object}                   props
 * @param {(value: unknown) => void} props.notify Shows a notice.
 */
export default function FontPacks( { notify } ) {
	const [ packs, setPacks ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const names = scriptNames();
	useEffect( () => {
		apiFetch( { path: `${ ROOT }/mermaid/packs` } )
			.then( setPacks )
			.catch( ( e ) => notify( 'error', e.message ) );
	}, [ notify ] );
	const run = async ( id, request, done ) => {
		setBusy( id );
		try {
			setPacks( await apiFetch( request ) );
			notify( 'success', done );
		} catch ( e ) {
			notify( 'error', e.message );
		}
		setBusy( '' );
	};
	const upload = ( file ) => {
		const body = new window.FormData();
		body.append( 'file', file );
		run(
			'upload',
			{ path: `${ ROOT }/mermaid/packs`, method: 'POST', body },
			sprintf(
				/* translators: %s: file name. */
				__( '%s is installed.', 'windrose-livery' ),
				file.name
			)
		);
	};
	return (
		<Card className="windrose-livery__card">
			<CardHeader>
				<h2 className="windrose-livery__card-title">
					{ __( 'Font packs', 'windrose-livery' ) }
				</h2>
				<FormFileUpload
					__next40pxDefaultSize
					accept=".zip,application/zip"
					variant="secondary"
					disabled={ !! busy }
					onChange={ ( event ) => {
						const file = event.currentTarget.files?.[ 0 ];
						event.currentTarget.value = '';
						if ( file ) {
							upload( file );
						}
					} }
				>
					{ __( 'Upload a pack', 'windrose-livery' ) }
				</FormFileUpload>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Fonts for other scripts, installed once on this website and used by Style Mermaid without Google Fonts or Bunny Fonts. Install gets the pack of this Livery version from its GitHub release; or download the pack from the release yourself and upload it. Styles keep their own copies of the fonts they use, so removing a pack changes no style.',
						'windrose-livery'
					) }
				</p>
				{ null === packs && <Spinner /> }
				{ packs && (
					<table className="windrose-livery__packs widefat striped">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Script', 'windrose-livery' ) }
								</th>
								<th scope="col">
									{ __( 'Fonts', 'windrose-livery' ) }
								</th>
								<th scope="col">
									{ __( 'Size', 'windrose-livery' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'windrose-livery' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ packs.map( ( pack ) => {
								const label =
									names[ pack.script ] ?? pack.script;
								return (
									<tr key={ pack.script }>
										<th scope="row">
											{ label }
											<br />
											<span className="windrose-livery__muted">
												{ pack.installed
													? sprintf(
															/* translators: %s: Livery version the pack belongs to. */
															__(
																'Installed (version %s)',
																'windrose-livery'
															),
															pack.livery || '?'
														)
													: __(
															'Not installed',
															'windrose-livery'
														) }
											</span>
										</th>
										<td>
											{ pack.families
												.map( ( f ) => f.name )
												.join( ', ' ) }
										</td>
										<td>{ size( pack.bytes ) }</td>
										<td className="windrose-livery__pack-actions">
											{ busy === pack.script && (
												<Spinner />
											) }
											<Button
												__next40pxDefaultSize
												variant="secondary"
												disabled={ !! busy }
												accessibleWhenDisabled
												label={
													pack.installed
														? sprintf(
																/* translators: %s: script name. */
																__(
																	'Install again: the %s pack, from GitHub',
																	'windrose-livery'
																),
																label
															)
														: sprintf(
																/* translators: %s: script name. */
																__(
																	'Install the %s pack from GitHub',
																	'windrose-livery'
																),
																label
															)
												}
												showTooltip={ false }
												onClick={ () =>
													run(
														pack.script,
														{
															path: `${ ROOT }/mermaid/packs/${ pack.script }`,
															method: 'POST',
														},
														sprintf(
															/* translators: %s: script name. */
															__(
																'The %s pack is installed.',
																'windrose-livery'
															),
															label
														)
													)
												}
											>
												{ pack.installed
													? __(
															'Install again',
															'windrose-livery'
														)
													: __(
															'Install',
															'windrose-livery'
														) }
											</Button>
											{ pack.installed && (
												<Button
													__next40pxDefaultSize
													variant="tertiary"
													isDestructive
													disabled={ !! busy }
													accessibleWhenDisabled
													label={ sprintf(
														/* translators: %s: script name. */
														__(
															'Remove the %s pack',
															'windrose-livery'
														),
														label
													) }
													showTooltip={ false }
													onClick={ () =>
														run(
															pack.script,
															{
																path: `${ ROOT }/mermaid/packs/${ pack.script }`,
																method: 'DELETE',
															},
															sprintf(
																/* translators: %s: script name. */
																__(
																	'The %s pack is removed.',
																	'windrose-livery'
																),
																label
															)
														)
													}
												>
													{ __(
														'Remove',
														'windrose-livery'
													) }
												</Button>
											) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				) }
				{ 'upload' === busy && <Spinner /> }
			</CardBody>
		</Card>
	);
}
