import { useState } from '@wordpress/element';
import { get, post, del } from '../api';
import { useChanges } from '../context';
import { useLoad, timeAgo, formatDate, formatBytes, formatDuration, setParams, errorMessage, hostOf } from '../utils';
import {
	Drawer,
	Tabs,
	Pills,
	Status,
	Button,
	Loading,
	ErrorBox,
	Empty,
	Person,
	Badge,
	Modal,
	useToast,
} from './ui';
import Icon from './Icon';
import SiteFormModal, { PairingInstructions } from './SiteFormModal';
import MaintenanceModal, { EventChange } from './MaintenanceModal';

const TABS = [
	{ id: 'overview', label: 'Overview' },
	{ id: 'updates', label: 'Updates' },
	{ id: 'maintenance', label: 'Maintenance' },
	{ id: 'backups', label: 'Backups' },
	{ id: 'security', label: 'Security' },
	{ id: 'activity', label: 'Activity' },
	{ id: 'notes', label: 'Notes' },
];

function InfoRow( { label, children } ) {
	return (
		<div className="wpc-info__row">
			<dt>{ label }</dt>
			<dd>{ children || <span className="wpc-muted">—</span> }</dd>
		</div>
	);
}

function OverviewTab( { site, onChanged, onDeleted } ) {
	const toast = useToast();
	const { bump } = useChanges();
	const [ token, setToken ] = useState( '' );
	const [ editing, setEditing ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const info = site.info || {};

	const regenerate = () => {
		if ( site.agent_status === 'paired' && ! window.confirm( 'Regenerating disconnects the current agent until it is re-paired with the new token. Continue?' ) ) {
			return;
		}
		setBusy( 'token' );
		post( `/sites/${ site.id }/token` )
			.then( ( r ) => {
				setToken( r.pairing_token );
				onChanged( r.site );
			} )
			.catch( ( e ) => toast( errorMessage( e ), 'error' ) )
			.finally( () => setBusy( '' ) );
	};

	const remove = () => {
		if ( ! window.confirm( `Delete ${ site.name } and all of its history (maintenance records, detected changes, backups)? This cannot be undone.` ) ) {
			return;
		}
		setBusy( 'delete' );
		del( `/sites/${ site.id }` )
			.then( () => {
				toast( 'Site deleted' );
				bump();
				onDeleted();
			} )
			.catch( ( e ) => {
				toast( errorMessage( e ), 'error' );
				setBusy( '' );
			} );
	};

	return (
		<div className="wpc-stack">
			{ site.status === 'pending' && (
				<div className="wpc-callout wpc-callout--info">
					<Icon name="key" />
					<div>
						<strong>Waiting for the agent to connect.</strong>
						<p>Generate a pairing token and paste it into the WP Control Agent plugin on { hostOf( site.url ) }.</p>
					</div>
					<Button variant="primary" size="sm" busy={ busy === 'token' } onClick={ regenerate }>
						Get pairing token
					</Button>
				</div>
			) }
			{ site.status === 'offline' && (
				<div className="wpc-callout wpc-callout--danger">
					<Icon name="alert" />
					<div>
						<strong>Site is not reporting.</strong>
						<p>{ site.last_error || `No snapshot received since ${ formatDate( site.last_seen_at ) }.` }</p>
					</div>
				</div>
			) }

			<div className="wpc-mini-stats">
				<div>
					<span>Pending updates</span>
					<strong className={ site.pending_updates ? 'is-amber' : '' }>{ site.pending_updates }</strong>
				</div>
				<div>
					<span>Open issues</span>
					<strong className={ site.critical_issues ? 'is-red' : '' }>{ site.open_issues }</strong>
				</div>
				<div>
					<span>Last backup</span>
					<strong className={ site.backup_stale ? 'is-amber' : '' }>{ timeAgo( site.last_backup_at ) }</strong>
				</div>
				<div>
					<span>Last maintenance</span>
					<strong>{ timeAgo( site.last_maintenance_at ) }</strong>
					{ site.last_maintenance_by && <small>{ site.last_maintenance_by }</small> }
				</div>
			</div>

			<section>
				<h4 className="wpc-section-title">Site information</h4>
				<dl className="wpc-info">
					<InfoRow label="Site URL">
						<a href={ site.url } target="_blank" rel="noreferrer">
							{ site.url }
						</a>
						{ site.reported_url && site.reported_url.replace( /\/$/, '' ) !== site.url && (
							<Badge tone="amber">Agent reports { site.reported_url }</Badge>
						) }
					</InfoRow>
					<InfoRow label="Client">{ site.client_name }</InfoRow>
					<InfoRow label="WordPress">{ site.wp_version }</InfoRow>
					<InfoRow label="PHP">{ site.php_version }</InfoRow>
					<InfoRow label="Database">{ info.db_version }</InfoRow>
					<InfoRow label="Active theme">{ site.active_theme }</InfoRow>
					<InfoRow label="Server">{ info.server }</InfoRow>
					<InfoRow label="Multisite">{ info.multisite ? 'Yes' : info.wp_version ? 'No' : '' }</InfoRow>
					<InfoRow label="Backup plugin">{ info.backup_providers }</InfoRow>
				</dl>
			</section>

			<section>
				<h4 className="wpc-section-title">Agent</h4>
				<dl className="wpc-info">
					<InfoRow label="Connection">
						<Status value={ site.agent_status === 'paired' ? 'online' : site.agent_status === 'revoked' ? 'offline' : 'pending' } label={ site.agent_status || 'not created' } />
					</InfoRow>
					<InfoRow label="Agent version">{ site.agent_version }</InfoRow>
					<InfoRow label="Paired">{ site.paired_at && formatDate( site.paired_at ) }</InfoRow>
					<InfoRow label="Last seen">{ site.last_seen_at && `${ formatDate( site.last_seen_at ) } (${ timeAgo( site.last_seen_at ) })` }</InfoRow>
				</dl>
				<div className="wpc-row-actions">
					<Button size="sm" icon="key" busy={ busy === 'token' } onClick={ regenerate }>
						{ site.agent_status === 'paired' ? 'Regenerate pairing token' : 'Get pairing token' }
					</Button>
					<Button size="sm" icon="edit" onClick={ () => setEditing( true ) }>
						Edit site
					</Button>
					<Button size="sm" variant="danger" icon="trash" busy={ busy === 'delete' } onClick={ remove }>
						Delete
					</Button>
				</div>
			</section>

			{ token && (
				<Modal title="Pairing token" onClose={ () => setToken( '' ) } footer={ <Button variant="primary" onClick={ () => setToken( '' ) }>Done</Button> }>
					<PairingInstructions token={ token } />
				</Modal>
			) }
			{ editing && <SiteFormModal site={ site } onClose={ () => setEditing( false ) } onSaved={ onChanged } /> }
		</div>
	);
}

function UpdatesTab( { site, onSync, syncing } ) {
	const { version } = useChanges();
	const [ type, setType ] = useState( '' );
	const [ documenting, setDocumenting ] = useState( null );
	const comps = useLoad( () => get( `/sites/${ site.id }/components`, { type } ), [ site.id, type, version ] );
	const events = useLoad( () => get( `/sites/${ site.id }/events`, { per_page: 50 } ), [ site.id, version ] );

	const items = comps.data ? comps.data.items : [];
	const withUpdates = items.filter( ( c ) => c.update_version );
	const count = ( t ) => withUpdates.filter( ( c ) => c.type === t ).length;
	const all = comps.data && ! type ? withUpdates.length : site.pending_updates;

	return (
		<div className="wpc-stack">
			<div className="wpc-summary">
				<div>
					<span className="wpc-muted">Updates available</span>
					<div className="wpc-summary__big">
						<strong className={ site.pending_updates ? 'is-amber' : 'is-green' }>{ site.pending_updates }</strong>
						{ comps.data && ! type && (
							<span className="wpc-muted">
								{ count( 'core' ) ? `core · ` : '' }
								{ count( 'plugin' ) } plugin{ count( 'plugin' ) === 1 ? '' : 's' } · { count( 'theme' ) } theme{ count( 'theme' ) === 1 ? '' : 's' }
							</span>
						) }
					</div>
				</div>
				<div className="wpc-summary__side">
					<Button variant="primary" icon="refresh" busy={ syncing } onClick={ onSync } disabled={ site.agent_status !== 'paired' }>
						Check for updates
					</Button>
					<span className="wpc-muted">Last checked: { timeAgo( comps.data && comps.data.last_checked_at ) }</span>
				</div>
			</div>

			<Pills
				value={ type }
				onChange={ setType }
				options={ [
					{ id: '', label: `All (${ all })` },
					{ id: 'plugin', label: 'Plugins' },
					{ id: 'theme', label: 'Themes' },
					{ id: 'core', label: 'Core' },
				] }
			/>

			{ comps.error && <ErrorBox error={ comps.error } onRetry={ comps.reload } /> }
			{ ! comps.data && comps.loading && <Loading /> }
			{ comps.data && ! items.length && <Empty icon="database" title="No components reported yet" /> }
			{ comps.data && items.length > 0 && (
				<div className="wpc-table-wrap">
					<table className="wpc-table">
						<thead>
							<tr>
								<th>Name</th>
								<th>Type</th>
								<th>Current</th>
								<th>New</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( c ) => (
								<tr key={ c.id }>
									<td>
										<strong>{ c.name }</strong>
									</td>
									<td className="wpc-cap">{ c.type }</td>
									<td>{ c.version }</td>
									<td>{ c.update_version ? <b className="is-amber">{ c.update_version }</b> : <span className="wpc-muted">—</span> }</td>
									<td>{ c.type === 'core' ? null : c.active ? <Badge tone="green">Active</Badge> : <Badge>Inactive</Badge> }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<section>
				<h4 className="wpc-section-title">Update history</h4>
				<p className="wpc-field__hint">
					Changes detected by comparing agent snapshots. A detected change doesn’t prove who performed it — use
					“Document” to attach a maintenance record.
				</p>
				{ ! events.data && events.loading && <Loading /> }
				{ events.data && ! events.data.items.length && <Empty icon="activity" title="No changes detected yet" /> }
				{ events.data && events.data.items.length > 0 && (
					<div className="wpc-table-wrap">
						<table className="wpc-table">
							<thead>
								<tr>
									<th>Detected</th>
									<th>Item</th>
									<th>From → To</th>
									<th>Updated by</th>
								</tr>
							</thead>
							<tbody>
								{ events.data.items.map( ( ev ) => (
									<tr key={ ev.id }>
										<td className="wpc-nowrap">{ formatDate( ev.detected_at, false ) }</td>
										<td>
											{ ev.component_name } { ev.event_type !== 'updated' && <Badge>{ ev.event_type }</Badge> }
										</td>
										<td>
											<EventChange event={ ev } />
										</td>
										<td>
											{ ev.maintenance.length ? (
												<Person name={ ev.maintenance[ 0 ].performer_name } />
											) : (
												<button type="button" className="wpc-link" onClick={ () => setDocumenting( [ ev.id ] ) }>
													Unattributed · Document
												</button>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</section>
			{ documenting && (
				<MaintenanceModal siteId={ site.id } preselectEvents={ documenting } onClose={ () => setDocumenting( null ) } />
			) }
		</div>
	);
}

export function MaintenanceList( { siteId, showSite } ) {
	const { version } = useChanges();
	const [ editing, setEditing ] = useState( null );
	const { data, error, loading, reload } = useLoad( () => get( '/maintenance', { site_id: siteId, per_page: 100 } ), [ siteId, version ] );

	return (
		<div className="wpc-stack">
			<div className="wpc-row-actions wpc-row-actions--end">
				<Button variant="primary" icon="plus" onClick={ () => setEditing( {} ) }>
					Log maintenance
				</Button>
			</div>
			{ error && <ErrorBox error={ error } onRetry={ reload } /> }
			{ ! data && loading && <Loading /> }
			{ data && ! data.items.length && (
				<Empty icon="wrench" title="No maintenance recorded">
					Log work done on this site — who performed it, when, and what changed.
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<ul className="wpc-timeline">
					{ data.items.map( ( m ) => (
						<li key={ m.id }>
							<div className="wpc-timeline__head">
								<Person name={ m.performer_name } />
								<Badge tone="blue">{ m.category }</Badge>
								{ showSite && <span className="wpc-muted">{ m.site_name }</span> }
								<span className="wpc-muted wpc-ml-auto">{ formatDate( m.performed_at ) }</span>
								<button type="button" className="wpc-icon-btn" onClick={ () => setEditing( m ) } aria-label="Edit">
									<Icon name="edit" size={ 14 } />
								</button>
							</div>
							<p>{ m.description }</p>
							<div className="wpc-timeline__meta">
								{ m.duration_minutes !== null && <span>⏱ { formatDuration( m.duration_minutes ) }</span> }
								{ m.linked_events > 0 && (
									<span>
										<Icon name="link" size={ 12 } /> { m.linked_events } linked change{ m.linked_events === 1 ? '' : 's' }
									</span>
								) }
								{ m.notes && <span title={ m.notes }>📝 { m.notes }</span> }
							</div>
						</li>
					) ) }
				</ul>
			) }
			{ editing && <MaintenanceEditor record={ editing.id ? editing : null } siteId={ siteId } onClose={ () => setEditing( null ) } /> }
		</div>
	);
}

/** Loads the full record (with event ids) before opening the edit modal. */
export function MaintenanceEditor( { record, siteId, onClose } ) {
	const full = useLoad( () => ( record ? get( `/maintenance/${ record.id }` ) : Promise.resolve( null ) ), [ record && record.id ] );
	if ( record && ! full.data ) {
		return null;
	}
	return <MaintenanceModal record={ full.data } siteId={ siteId } onClose={ onClose } />;
}

function BackupsTab( { site } ) {
	const { version } = useChanges();
	const { data, error, loading, reload } = useLoad( () => get( `/sites/${ site.id }/backups` ), [ site.id, version ] );
	if ( error ) {
		return <ErrorBox error={ error } onRetry={ reload } />;
	}
	if ( ! data && loading ) {
		return <Loading />;
	}
	const last = data.items.find( ( b ) => b.status === 'success' );

	return (
		<div className="wpc-stack">
			{ last ? (
				<div className={ `wpc-callout ${ site.backup_stale ? 'wpc-callout--warn' : 'wpc-callout--ok' }` }>
					<Icon name={ site.backup_stale ? 'alert' : 'checkCircle' } size={ 20 } />
					<div>
						<strong>{ site.backup_stale ? 'Last backup is getting old' : 'Last backup successful' }</strong>
						<p>
							{ formatDate( last.completed_at || last.started_at ) } ({ timeAgo( last.completed_at || last.started_at ) })
							{ last.storage && ` · ${ last.storage }` }
							{ last.size_bytes !== null && ` · ${ formatBytes( last.size_bytes ) }` }
						</p>
					</div>
				</div>
			) : (
				<div className="wpc-callout wpc-callout--warn">
					<Icon name="alert" size={ 20 } />
					<div>
						<strong>No successful backup reported</strong>
						<p>
							{ data.providers
								? `Detected: ${ data.providers }.`
								: 'No supported backup plugin detected (UpdraftPlus, BackWPup, or a plugin using the wpcontrol_agent_backups filter).' }
						</p>
					</div>
				</div>
			) }

			{ data.items.length > 0 && (
				<div className="wpc-table-wrap">
					<table className="wpc-table">
						<thead>
							<tr>
								<th>Date</th>
								<th>Status</th>
								<th>Size</th>
								<th>Storage</th>
								<th>Triggered by</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( b ) => (
								<tr key={ b.id }>
									<td className="wpc-nowrap">{ formatDate( b.started_at || b.completed_at ) }</td>
									<td>
										<Status value={ b.status } />
									</td>
									<td>{ formatBytes( b.size_bytes ) }</td>
									<td>{ b.storage || <span className="wpc-muted">—</span> }</td>
									<td>{ b.triggered_by || <span className="wpc-muted">—</span> }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

function SecurityTab( { site } ) {
	const { version } = useChanges();
	const [ status, setStatus ] = useState( 'open' );
	const { data, error, loading, reload } = useLoad( () => get( `/sites/${ site.id }/checks`, { status } ), [ site.id, status, version ] );
	if ( error ) {
		return <ErrorBox error={ error } onRetry={ reload } />;
	}
	if ( ! data && loading ) {
		return <Loading />;
	}
	const { counts } = data;
	const clean = ! counts.critical && ! counts.moderate && ! counts.low;

	return (
		<div className="wpc-stack">
			<div className={ `wpc-callout ${ counts.critical ? 'wpc-callout--danger' : 'wpc-callout--ok' }` }>
				<Icon name={ counts.critical ? 'alert' : 'shield' } size={ 20 } />
				<div>
					<strong>{ counts.critical ? `${ counts.critical } critical issue${ counts.critical === 1 ? '' : 's' }` : clean ? 'No issues found' : 'No critical vulnerabilities' }</strong>
					<p>Last check: { timeAgo( data.last_checked_at ) }</p>
				</div>
			</div>
			<div className="wpc-sev-row">
				<div className="wpc-sev wpc-sev--red">
					<strong>{ counts.critical }</strong> Critical
				</div>
				<div className="wpc-sev wpc-sev--amber">
					<strong>{ counts.moderate }</strong> Moderate
				</div>
				<div className="wpc-sev wpc-sev--blue">
					<strong>{ counts.low }</strong> Low
				</div>
			</div>
			<Pills value={ status } onChange={ setStatus } options={ [ { id: 'open', label: 'Open' }, { id: 'resolved', label: 'Resolved' } ] } />
			{ ! data.items.length ? (
				<Empty icon="shield" title={ status === 'open' ? 'Nothing open' : 'Nothing resolved yet' } />
			) : (
				<div className="wpc-table-wrap">
					<table className="wpc-table">
						<thead>
							<tr>
								<th>Issue</th>
								<th>Severity</th>
								<th>Component</th>
								<th>{ status === 'open' ? 'First seen' : 'Resolved' }</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( k ) => (
								<tr key={ k.id }>
									<td>
										<strong>{ k.title }</strong>
										{ k.details && <div className="wpc-muted wpc-small">{ k.details }</div> }
									</td>
									<td>
										<Status value={ k.severity } />
									</td>
									<td>{ k.component || <span className="wpc-muted">—</span> }</td>
									<td className="wpc-nowrap">{ timeAgo( status === 'open' ? k.first_seen_at : k.resolved_at ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

export const ACTION_LABELS = {
	'site.created': 'Site added',
	'site.updated': 'Site edited',
	'site.deleted': 'Site deleted',
	'site.sync_requested': 'Sync requested',
	'agent.paired': 'Agent paired',
	'agent.token_regenerated': 'Pairing token regenerated',
	'agent.revoked': 'Agent revoked',
	'maintenance.created': 'Maintenance logged',
	'maintenance.updated': 'Maintenance edited',
	'maintenance.deleted': 'Maintenance deleted',
	'note.created': 'Note added',
	'note.deleted': 'Note deleted',
	'client.created': 'Client added',
	'client.updated': 'Client edited',
	'client.deleted': 'Client deleted',
	'settings.updated': 'Settings changed',
	'export.sites': 'Sites exported',
	'export.maintenance': 'Maintenance exported',
	'export.events': 'Update history exported',
};

function ActivityTab( { site } ) {
	const { version } = useChanges();
	const { data, error, loading, reload } = useLoad( () => get( `/sites/${ site.id }/activity` ), [ site.id, version ] );
	if ( error ) {
		return <ErrorBox error={ error } onRetry={ reload } />;
	}
	if ( ! data && loading ) {
		return <Loading />;
	}

	const entries = [
		...data.audit.map( ( a ) => ( {
			key: `a${ a.id }`,
			at: a.created_at,
			actor: a.actor,
			system: a.actor === 'Agent' || a.actor === 'System',
			text: ACTION_LABELS[ a.action ] || a.action,
		} ) ),
		...data.sync_runs.map( ( r ) => ( {
			key: `r${ r.id }`,
			at: r.started_at,
			actor: r.direction === 'push' ? 'Agent' : 'Manager',
			system: true,
			text: `${ r.direction === 'push' ? 'Snapshot received' : 'Snapshot pulled' } — ${ r.message || r.status }`,
			failed: r.status === 'failed',
		} ) ),
	].sort( ( a, b ) => ( a.at < b.at ? 1 : -1 ) );

	if ( ! entries.length ) {
		return <Empty icon="activity" title="No activity yet" />;
	}
	return (
		<ul className="wpc-feed">
			{ entries.map( ( e ) => (
				<li key={ e.key } className={ e.failed ? 'is-failed' : '' }>
					<Person name={ e.actor } system={ e.system } />
					<span>{ e.text }</span>
					<span className="wpc-muted wpc-ml-auto wpc-nowrap" title={ formatDate( e.at ) }>
						{ timeAgo( e.at ) }
					</span>
				</li>
			) ) }
		</ul>
	);
}

function NotesTab( { site } ) {
	const toast = useToast();
	const [ body, setBody ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const { data, error, loading, reload } = useLoad( () => get( `/sites/${ site.id }/notes` ), [ site.id ] );

	const add = ( e ) => {
		e.preventDefault();
		setBusy( true );
		post( `/sites/${ site.id }/notes`, { body } )
			.then( () => {
				setBody( '' );
				reload();
			} )
			.catch( ( err ) => toast( errorMessage( err ), 'error' ) )
			.finally( () => setBusy( false ) );
	};
	const remove = ( id ) => {
		if ( window.confirm( 'Delete this note?' ) ) {
			del( `/notes/${ id }` ).then( reload );
		}
	};

	return (
		<div className="wpc-stack">
			<form className="wpc-note-form" onSubmit={ add }>
				<textarea rows="3" placeholder="Add a note — access details location, client preferences, quirks…" value={ body } onChange={ ( e ) => setBody( e.target.value ) } />
				<Button type="submit" variant="primary" busy={ busy } disabled={ ! body.trim() }>
					Add note
				</Button>
			</form>
			{ error && <ErrorBox error={ error } onRetry={ reload } /> }
			{ ! data && loading && <Loading /> }
			{ data && ! data.length && <Empty icon="note" title="No notes yet" /> }
			{ data &&
				data.map( ( n ) => (
					<article key={ n.id } className="wpc-note">
						<div className="wpc-timeline__head">
							<Person name={ n.author } />
							<span className="wpc-muted wpc-ml-auto">{ formatDate( n.created_at ) }</span>
							<button type="button" className="wpc-icon-btn" onClick={ () => remove( n.id ) } aria-label="Delete note">
								<Icon name="trash" size={ 14 } />
							</button>
						</div>
						<p>{ n.body }</p>
					</article>
				) ) }
		</div>
	);
}

export default function SiteDrawer( { siteId, tab = 'overview' } ) {
	const toast = useToast();
	const { version, bump } = useChanges();
	const { data: site, error, reload } = useLoad( () => get( `/sites/${ siteId }` ), [ siteId, version ] );
	const [ syncing, setSyncing ] = useState( false );

	const close = () => setParams( { site: null, tab: null } );
	const onChanged = () => bump();

	const sync = () => {
		setSyncing( true );
		post( `/sites/${ siteId }/sync` )
			.then( ( r ) => {
				toast( `Synced — ${ r.result.message }` );
				bump();
			} )
			.catch( ( e ) => {
				toast( errorMessage( e ), 'error' );
				bump();
			} )
			.finally( () => setSyncing( false ) );
	};

	return (
		<Drawer onClose={ close }>
			{ error && <ErrorBox error={ error } onRetry={ reload } /> }
			{ ! site && ! error && <Loading /> }
			{ site && (
				<>
					<header className="wpc-drawer__head">
						<div>
							<h2>
								{ site.name }
								<a href={ site.url } target="_blank" rel="noreferrer" aria-label="Open site">
									<Icon name="external" size={ 14 } />
								</a>
							</h2>
							<div className="wpc-muted">{ site.client_name || hostOf( site.url ) }</div>
							<div className="wpc-drawer__status">
								<Status value={ site.status } />
								<span className="wpc-muted">Last sync: { timeAgo( site.last_sync_at ) }</span>
							</div>
						</div>
						<div className="wpc-drawer__tools">
							<Button size="sm" icon="refresh" busy={ syncing } onClick={ sync } disabled={ site.agent_status !== 'paired' }>
								Sync now
							</Button>
							<button type="button" className="wpc-icon-btn" onClick={ close } aria-label="Close">
								<Icon name="x" size={ 18 } />
							</button>
						</div>
					</header>
					<Tabs
						tabs={ TABS.map( ( t ) => ( {
							...t,
							count: t.id === 'updates' ? site.pending_updates : t.id === 'security' ? site.open_issues : 0,
						} ) ) }
						value={ tab }
						onChange={ ( t ) => setParams( { tab: t } ) }
					/>
					<div className="wpc-drawer__body">
						{ tab === 'overview' && <OverviewTab site={ site } onChanged={ onChanged } onDeleted={ close } /> }
						{ tab === 'updates' && <UpdatesTab site={ site } onSync={ sync } syncing={ syncing } /> }
						{ tab === 'maintenance' && <MaintenanceList siteId={ site.id } /> }
						{ tab === 'backups' && <BackupsTab site={ site } /> }
						{ tab === 'security' && <SecurityTab site={ site } /> }
						{ tab === 'activity' && <ActivityTab site={ site } /> }
						{ tab === 'notes' && <NotesTab site={ site } /> }
					</div>
				</>
			) }
		</Drawer>
	);
}
