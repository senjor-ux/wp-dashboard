import { useState } from '@wordpress/element';
import { get } from '../api';
import { useChanges } from '../context';
import { useLoad, formatDate, timeAgo, setParams } from '../utils';
import { PageHeader, Card, Select, Pagination, Loading, ErrorBox, Empty, Person } from '../components/ui';
import { ACTION_LABELS } from '../components/SiteDrawer';

const PER_PAGE = 50;

const FILTERS = [
	{ value: '', label: 'All actions' },
	{ value: 'site.', label: 'Sites' },
	{ value: 'agent.', label: 'Agents' },
	{ value: 'maintenance.', label: 'Maintenance' },
	{ value: 'client.', label: 'Clients' },
	{ value: 'note.', label: 'Notes' },
	{ value: 'export.', label: 'Exports' },
	{ value: 'settings.', label: 'Settings' },
];

function describe( entry ) {
	const d = entry.details || {};
	const bits = [];
	if ( d.name ) {
		bits.push( d.name );
	}
	if ( d.performer ) {
		bits.push( `by ${ d.performer }` );
	}
	if ( d.category ) {
		bits.push( d.category );
	}
	if ( d.error ) {
		bits.push( `failed: ${ d.error }` );
	}
	if ( d.message ) {
		bits.push( d.message );
	}
	return bits.join( ' · ' );
}

export default function Activity() {
	const { version } = useChanges();
	const [ action, setAction ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const { data, error, loading, reload } = useLoad( () => get( '/activity', { action, page, per_page: PER_PAGE } ), [ action, page, version ] );

	return (
		<>
			<PageHeader title="Activity" subtitle="Audit history of every change made through WP Control" />
			<Card
				flush
				actions={
					<Select
						value={ action }
						onChange={ ( v ) => {
							setAction( v );
							setPage( 1 );
						} }
						options={ FILTERS }
					/>
				}
			>
				{ error && <ErrorBox error={ error } onRetry={ reload } /> }
				{ ! data && loading && <Loading /> }
				{ data && ! data.items.length && <Empty icon="activity" title="No activity yet" /> }
				{ data && data.items.length > 0 && (
					<div className={ `wpc-table-wrap ${ loading ? 'is-loading' : '' }` }>
						<table className="wpc-table">
							<thead>
								<tr>
									<th>When</th>
									<th>Who</th>
									<th>Action</th>
									<th>Website</th>
									<th>Details</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( a ) => (
									<tr key={ a.id }>
										<td className="wpc-nowrap" title={ formatDate( a.created_at ) }>
											{ timeAgo( a.created_at ) }
										</td>
										<td>
											<Person name={ a.actor } system={ a.actor === 'Agent' || a.actor === 'System' } />
										</td>
										<td>{ ACTION_LABELS[ a.action ] || a.action }</td>
										<td>
											{ a.site_id && a.site_name ? (
												<button type="button" className="wpc-link" onClick={ () => setParams( { site: a.site_id, tab: 'activity' } ) }>
													{ a.site_name }
												</button>
											) : (
												<span className="wpc-muted">—</span>
											) }
										</td>
										<td className="wpc-muted">{ describe( a ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ data && <Pagination page={ page } pages={ data.pages } total={ data.total } perPage={ PER_PAGE } onChange={ setPage } /> }
			</Card>
		</>
	);
}
