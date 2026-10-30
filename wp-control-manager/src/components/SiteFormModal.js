import { useState } from '@wordpress/element';
import { post, put } from '../api';
import { useLookups, useChanges } from '../context';
import { errorMessage } from '../utils';
import { Modal, Field, Button, Select, CopyField, useToast } from './ui';

export function PairingInstructions( { token } ) {
	return (
		<div className="wpc-pairing">
			<ol>
				<li>
					Install and activate the <strong>WP Control Agent</strong> plugin on the client website.
				</li>
				<li>
					In that site’s admin go to <strong>WP Control → Connection</strong>.
				</li>
				<li>Paste the pairing token below and click <strong>Connect</strong>.</li>
			</ol>
			<CopyField value={ token } />
			<p className="wpc-field__hint">
				The token contains this site’s private signing key and is shown only once. Regenerating it disconnects any agent
				using the old one.
			</p>
		</div>
	);
}

export default function SiteFormModal( { site, onClose, onSaved } ) {
	const { clients } = useLookups();
	const { bump } = useChanges();
	const toast = useToast();
	const [ form, setForm ] = useState( {
		name: site ? site.name : '',
		url: site ? site.url : 'https://',
		client_id: site && site.client_id ? String( site.client_id ) : '',
	} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ token, setToken ] = useState( '' );

	const set = ( key ) => ( v ) => setForm( ( f ) => ( { ...f, [ key ]: v } ) );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( '' );
		const data = { ...form, client_id: form.client_id ? Number( form.client_id ) : null };
		const req = site ? put( `/sites/${ site.id }`, data ) : post( '/sites', data );
		req.then( ( res ) => {
			bump();
			if ( site ) {
				toast( 'Site updated' );
				if ( onSaved ) {
					onSaved( res );
				}
				onClose();
			} else {
				toast( 'Site added' );
				setToken( res.pairing_token );
				if ( onSaved ) {
					onSaved( res.site );
				}
			}
		} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	if ( token ) {
		return (
			<Modal title="Connect the agent" onClose={ onClose } footer={ <Button variant="primary" onClick={ onClose }>Done</Button> }>
				<PairingInstructions token={ token } />
			</Modal>
		);
	}

	return (
		<Modal
			title={ site ? 'Edit website' : 'Add website' }
			onClose={ onClose }
			footer={
				<>
					<Button onClick={ onClose }>Cancel</Button>
					<Button variant="primary" busy={ busy } type="submit" form="wpc-site-form">
						{ site ? 'Save' : 'Add website' }
					</Button>
				</>
			}
		>
			<form id="wpc-site-form" className="wpc-form" onSubmit={ submit }>
				{ error && <div className="wpc-error">{ error }</div> }
				<Field label="Website URL" required>
					<input type="url" required value={ form.url } onChange={ ( e ) => set( 'url' )( e.target.value ) } />
				</Field>
				<Field label="Display name" hint="Defaults to the domain.">
					<input type="text" value={ form.name } onChange={ ( e ) => set( 'name' )( e.target.value ) } />
				</Field>
				<Field label="Client">
					<Select
						value={ form.client_id }
						onChange={ set( 'client_id' ) }
						options={ [ { value: '', label: '— No client —' }, ...clients.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ] }
					/>
				</Field>
			</form>
		</Modal>
	);
}
