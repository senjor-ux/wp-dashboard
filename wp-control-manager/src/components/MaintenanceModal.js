import { useState, useEffect } from '@wordpress/element';
import { get, post, put } from '../api';
import { useLookups, useChanges } from '../context';
import { toLocalInput, fromLocalInput, formatDate, errorMessage } from '../utils';
import { Modal, Field, Button, Select, Loading, useToast } from './ui';

const EVENT_LABEL = { updated: 'Updated', installed: 'Installed', removed: 'Removed', downgraded: 'Downgraded' };

export function EventChange( { event } ) {
	if ( event.event_type === 'installed' ) {
		return <span className="wpc-ver">→ { event.new_version }</span>;
	}
	if ( event.event_type === 'removed' ) {
		return <span className="wpc-ver">{ event.old_version } → removed</span>;
	}
	return (
		<span className="wpc-ver">
			{ event.old_version } → <b>{ event.new_version }</b>
		</span>
	);
}

export default function MaintenanceModal( { record, siteId, preselectEvents = [], onClose, onSaved } ) {
	const { users, settings } = useLookups();
	const { bump } = useChanges();
	const toast = useToast();
	const me = window.wpControl && window.wpControl.user;
	const categories = ( settings && settings.maintenance_categories ) || [ 'Updates', 'Other' ];

	const [ sites, setSites ] = useState( null );
	const [ events, setEvents ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ form, setForm ] = useState( () => ( {
		site_id: String( ( record && record.site_id ) || siteId || '' ),
		performed_at: toLocalInput( record && record.performed_at ),
		performer_user_id: record
			? ( record.performer_user_id && String( record.performer_user_id ) ) || 'other'
			: String( ( me && me.id ) || '' ),
		performer_name: record && ! record.performer_user_id ? record.performer_name : '',
		category: ( record && record.category ) || ( preselectEvents.length ? 'Updates' : categories[ 0 ] ),
		description: ( record && record.description ) || '',
		duration_minutes: record && record.duration_minutes !== null ? String( record.duration_minutes ) : '',
		notes: ( record && record.notes ) || '',
		event_ids: ( record && record.event_ids ) || preselectEvents,
	} ) );

	const set = ( key ) => ( v ) => setForm( ( f ) => ( { ...f, [ key ]: v } ) );

	useEffect( () => {
		get( '/sites', { per_page: 500, orderby: 'name' } ).then( ( r ) => setSites( r.items ) );
	}, [] );

	// Unlinked detected changes for the chosen site (+ the ones this record already claims).
	useEffect( () => {
		if ( ! form.site_id ) {
			setEvents( [] );
			return;
		}
		setEvents( null );
		get( `/sites/${ form.site_id }/events`, {
			unlinked: 1,
			include_maintenance: record ? record.id : '',
			per_page: 100,
		} ).then( ( r ) => setEvents( r.items ) );
	}, [ form.site_id, record ] );

	const toggleEvent = ( id ) =>
		set( 'event_ids' )( form.event_ids.includes( id ) ? form.event_ids.filter( ( x ) => x !== id ) : [ ...form.event_ids, id ] );

	const changeSite = ( v ) => setForm( ( f ) => ( { ...f, site_id: v, event_ids: [] } ) );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( '' );
		const data = {
			...form,
			site_id: Number( form.site_id ),
			performer_user_id: form.performer_user_id === 'other' ? 0 : Number( form.performer_user_id ),
			performer_name: form.performer_user_id === 'other' ? form.performer_name : '',
			performed_at: fromLocalInput( form.performed_at ),
			duration_minutes: form.duration_minutes === '' ? null : Number( form.duration_minutes ),
		};
		( record ? put( `/maintenance/${ record.id }`, data ) : post( '/maintenance', data ) )
			.then( ( saved ) => {
				bump();
				toast( record ? 'Maintenance record updated' : 'Maintenance recorded' );
				if ( onSaved ) {
					onSaved( saved );
				}
				onClose();
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	const performerOptions = [
		...users.map( ( u ) => ( { value: String( u.id ), label: u.name } ) ),
		{ value: 'other', label: 'Someone else…' },
	];
	return (
		<Modal
			wide
			title={ record ? 'Edit maintenance record' : 'Log maintenance' }
			onClose={ onClose }
			footer={
				<>
					<Button onClick={ onClose }>Cancel</Button>
					<Button variant="primary" type="submit" form="wpc-maint-form" busy={ busy }>
						Save record
					</Button>
				</>
			}
		>
			<form id="wpc-maint-form" className="wpc-form wpc-form--grid" onSubmit={ submit }>
				{ error && <div className="wpc-error wpc-span-2">{ error }</div> }
				<Field label="Website" required>
					{ sites ? (
						<Select
							required
							value={ form.site_id }
							onChange={ changeSite }
							options={ [ { value: '', label: 'Choose a website…' }, ...sites.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ] }
						/>
					) : (
						<Loading />
					) }
				</Field>
				<Field label="Performed" required>
					<input type="datetime-local" required value={ form.performed_at } onChange={ ( e ) => set( 'performed_at' )( e.target.value ) } />
				</Field>
				<Field label="Performed by" required>
					<Select value={ form.performer_user_id } onChange={ set( 'performer_user_id' ) } options={ performerOptions } />
				</Field>
				{ form.performer_user_id === 'other' && (
					<Field label="Performer name" required>
						<input type="text" required value={ form.performer_name } onChange={ ( e ) => set( 'performer_name' )( e.target.value ) } />
					</Field>
				) }
				<Field label="Category" required>
					<Select value={ form.category } onChange={ set( 'category' ) } options={ categories.map( ( c ) => ( { value: c, label: c } ) ) } />
				</Field>
				<Field label="Duration (minutes)">
					<input type="number" min="0" step="5" value={ form.duration_minutes } onChange={ ( e ) => set( 'duration_minutes' )( e.target.value ) } />
				</Field>
				<div className="wpc-span-2">
					<Field label="Description" required>
						<textarea rows="3" required value={ form.description } onChange={ ( e ) => set( 'description' )( e.target.value ) } />
					</Field>
				</div>
				<div className="wpc-span-2">
					<Field label="Internal notes">
						<textarea rows="2" value={ form.notes } onChange={ ( e ) => set( 'notes' )( e.target.value ) } />
					</Field>
				</div>

				<div className="wpc-span-2">
					<div className="wpc-field__label">Link detected changes</div>
					<p className="wpc-field__hint">
						Version changes the agent detected that aren’t documented yet. Linking them records who performed the work.
					</p>
					{ ! form.site_id && <p className="wpc-muted">Choose a website first.</p> }
					{ form.site_id && ! events && <Loading /> }
					{ events && form.site_id && ! events.length && <p className="wpc-muted">No undocumented changes for this site.</p> }
					{ events && events.length > 0 && (
						<div className="wpc-checklist">
							{ events.map( ( ev ) => (
								<label key={ ev.id } className="wpc-checklist__row">
									<input type="checkbox" checked={ form.event_ids.includes( ev.id ) } onChange={ () => toggleEvent( ev.id ) } />
									<span className="wpc-checklist__name">
										{ ev.component_name } <span className="wpc-muted">{ ev.component_type }</span>
									</span>
									<span>{ EVENT_LABEL[ ev.event_type ] }</span>
									<EventChange event={ ev } />
									<span className="wpc-muted">{ formatDate( ev.detected_at ) }</span>
								</label>
							) ) }
						</div>
					) }
				</div>
			</form>
		</Modal>
	);
}
