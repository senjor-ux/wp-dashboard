import { useState } from '@wordpress/element';
import { post, put, del } from '../api';
import { useLookups, useChanges } from '../context';
import { navigate, errorMessage } from '../utils';
import { PageHeader, Card, Button, Modal, Field, Empty, Loading, useToast } from '../components/ui';
import Icon from '../components/Icon';

function ClientModal( { client, onClose } ) {
	const { refresh } = useLookups();
	const { bump } = useChanges();
	const toast = useToast();
	const [ form, setForm ] = useState( {
		name: ( client && client.name ) || '',
		contact_name: ( client && client.contact_name ) || '',
		email: ( client && client.email ) || '',
		phone: ( client && client.phone ) || '',
		notes: ( client && client.notes ) || '',
	} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const bind = ( key ) => ( {
		value: form[ key ],
		onChange: ( e ) => setForm( ( f ) => ( { ...f, [ key ]: e.target.value } ) ),
	} );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		( client ? put( `/clients/${ client.id }`, form ) : post( '/clients', form ) )
			.then( () => refresh() )
			.then( () => {
				bump();
				toast( client ? 'Client updated' : 'Client added' );
				onClose();
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={ client ? 'Edit client' : 'Add client' }
			onClose={ onClose }
			footer={
				<>
					<Button onClick={ onClose }>Cancel</Button>
					<Button variant="primary" type="submit" form="wpc-client-form" busy={ busy }>
						Save
					</Button>
				</>
			}
		>
			<form id="wpc-client-form" className="wpc-form wpc-form--grid" onSubmit={ submit }>
				{ error && <div className="wpc-error wpc-span-2">{ error }</div> }
				<div className="wpc-span-2">
					<Field label="Company / client name" required>
						<input type="text" required { ...bind( 'name' ) } />
					</Field>
				</div>
				<Field label="Contact person">
					<input type="text" { ...bind( 'contact_name' ) } />
				</Field>
				<Field label="Email">
					<input type="email" { ...bind( 'email' ) } />
				</Field>
				<Field label="Phone">
					<input type="text" { ...bind( 'phone' ) } />
				</Field>
				<div className="wpc-span-2">
					<Field label="Notes">
						<textarea rows="3" { ...bind( 'notes' ) } />
					</Field>
				</div>
			</form>
		</Modal>
	);
}

export default function Clients() {
	const { clients, settings, refresh } = useLookups();
	const { bump } = useChanges();
	const toast = useToast();
	const [ editing, setEditing ] = useState( null );

	const remove = ( c ) => {
		if ( ! window.confirm( `Delete client “${ c.name }”? Its ${ c.site_count } site(s) will be kept but unassigned.` ) ) {
			return;
		}
		del( `/clients/${ c.id }` )
			.then( refresh )
			.then( () => {
				bump();
				toast( 'Client deleted' );
			} )
			.catch( ( e ) => toast( errorMessage( e ), 'error' ) );
	};

	return (
		<>
			<PageHeader
				title="Clients"
				subtitle="The people and companies you maintain websites for"
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEditing( {} ) }>
						Add client
					</Button>
				}
			/>
			<Card flush>
				{ ! settings && <Loading /> }
				{ settings && ! clients.length && (
					<Empty icon="users" title="No clients yet">
						Group websites by client to filter the dashboard and export per-client reports.
					</Empty>
				) }
				{ clients.length > 0 && (
					<div className="wpc-table-wrap">
						<table className="wpc-table">
							<thead>
								<tr>
									<th>Client</th>
									<th>Contact</th>
									<th>Email</th>
									<th>Phone</th>
									<th>Websites</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ clients.map( ( c ) => (
									<tr key={ c.id }>
										<td>
											<strong>{ c.name }</strong>
											{ c.notes && <div className="wpc-muted wpc-small wpc-truncate">{ c.notes }</div> }
										</td>
										<td>{ c.contact_name || <span className="wpc-muted">—</span> }</td>
										<td>{ c.email ? <a href={ `mailto:${ c.email }` }>{ c.email }</a> : <span className="wpc-muted">—</span> }</td>
										<td>{ c.phone || <span className="wpc-muted">—</span> }</td>
										<td>
											<button type="button" className="wpc-link" onClick={ () => navigate( '/sites', { client: c.id } ) }>
												{ c.site_count }
											</button>
										</td>
										<td className="wpc-actions-cell">
											<button type="button" className="wpc-icon-btn" onClick={ () => setEditing( c ) } aria-label="Edit">
												<Icon name="edit" size={ 14 } />
											</button>
											<button type="button" className="wpc-icon-btn" onClick={ () => remove( c ) } aria-label="Delete">
												<Icon name="trash" size={ 14 } />
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>
			{ editing && <ClientModal client={ editing.id ? editing : null } onClose={ () => setEditing( null ) } /> }
		</>
	);
}
