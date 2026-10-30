import { get } from '../api';
import { useChanges } from '../context';
import { useLoad, timeAgo, setParams, navigate } from '../utils';
import { PageHeader, Card, Person, Badge, Loading, ErrorBox, Empty } from '../components/ui';
import Icon from '../components/Icon';
import SitesTable from '../components/SitesTable';
import { EventChange } from '../components/MaintenanceModal';

function Stat( { label, value, icon, tone, children, onClick } ) {
	return (
		<button type="button" className={ `wpc-stat wpc-stat--${ tone }` } onClick={ onClick }>
			<span className="wpc-stat__label">{ label }</span>
			<span className="wpc-stat__row">
				<strong>{ value }</strong>
				{ children }
				<Icon name={ icon } size={ 22 } />
			</span>
		</button>
	);
}

export default function Overview() {
	const { version } = useChanges();
	const { data, error, reload } = useLoad( () => get( '/dashboard' ), [ version ] );

	if ( error ) {
		return <ErrorBox error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const { stats } = data;

	return (
		<>
			<PageHeader title="Overview" subtitle="Your WordPress infrastructure at a glance" />

			<div className="wpc-stats">
				<Stat label="Total websites" value={ stats.total } icon="globe" tone="neutral" onClick={ () => navigate( '/sites' ) } />
				<Stat label="Online" value={ stats.online } icon="checkCircle" tone="green" onClick={ () => navigate( '/sites', { status: 'online' } ) }>
					<span className="wpc-stat__pct">● { stats.online_percent }%</span>
				</Stat>
				<Stat label="Pending updates" value={ stats.pending_updates } icon="refresh" tone="amber" onClick={ () => navigate( '/sites', { status: 'updates' } ) }>
					<span className="wpc-stat__sub">{ stats.sites_with_updates } sites</span>
				</Stat>
				<Stat label="Critical issues" value={ stats.critical_issues } icon="alert" tone="red" onClick={ () => navigate( '/sites', { status: 'critical' } ) } />
				<Stat label="Stale backups" value={ stats.backup_stale } icon="database" tone={ stats.backup_stale ? 'amber' : 'neutral' } onClick={ () => navigate( '/sites', { status: 'backup_stale' } ) } />
			</div>

			<SitesTable perPage={ 8 } compact />

			<div className="wpc-grid-2">
				<Card title="Recent maintenance" actions={ <button type="button" className="wpc-link" onClick={ () => navigate( '/maintenance' ) }>View all</button> }>
					{ ! data.recent_maintenance.length ? (
						<Empty icon="wrench" title="Nothing logged yet" />
					) : (
						<ul className="wpc-list">
							{ data.recent_maintenance.map( ( m ) => (
								<li key={ m.id }>
									<Person name={ m.performer_name } />
									<button type="button" className="wpc-link wpc-truncate" onClick={ () => setParams( { site: m.site_id, tab: 'maintenance' } ) }>
										{ m.site_name }
									</button>
									<Badge tone="blue">{ m.category }</Badge>
									<span className="wpc-muted wpc-ml-auto wpc-nowrap">{ timeAgo( m.performed_at ) }</span>
								</li>
							) ) }
						</ul>
					) }
				</Card>
				<Card title="Recently detected changes">
					{ ! data.recent_events.length ? (
						<Empty icon="activity" title="No changes detected yet" />
					) : (
						<ul className="wpc-list">
							{ data.recent_events.map( ( e ) => (
								<li key={ e.id }>
									<button type="button" className="wpc-link wpc-truncate" onClick={ () => setParams( { site: e.site_id, tab: 'updates' } ) }>
										{ e.site_name }
									</button>
									<span className="wpc-truncate">{ e.component_name }</span>
									<EventChange event={ e } />
									{ e.maintenance.length ? (
										<Person name={ e.maintenance[ 0 ].performer_name } />
									) : (
										<Badge>Unattributed</Badge>
									) }
									<span className="wpc-muted wpc-ml-auto wpc-nowrap">{ timeAgo( e.detected_at ) }</span>
								</li>
							) ) }
						</ul>
					) }
				</Card>
			</div>
		</>
	);
}
