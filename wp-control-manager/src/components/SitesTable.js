import { useState } from '@wordpress/element';
import { get } from '../api';
import { useLookups, useChanges } from '../context';
import { useLoad, useDebounced, timeAgo, hostOf, setParams } from '../utils';
import { Status, SearchInput, Select, Pagination, Loading, ErrorBox, Empty, Card } from './ui';
import Icon from './Icon';

const STATUS_FILTERS = [
	{ value: '', label: 'All status' },
	{ value: 'online', label: 'Online' },
	{ value: 'offline', label: 'Offline' },
	{ value: 'pending', label: 'Not paired' },
	{ value: 'updates', label: 'Has updates' },
	{ value: 'critical', label: 'Critical issues' },
	{ value: 'backup_stale', label: 'Stale backups' },
];

function BackupCell( { site } ) {
	if ( site.status === 'pending' ) {
		return <span className="wpc-muted">—</span>;
	}
	if ( ! site.last_backup_at ) {
		return <Status value="unknown" label="Unknown" />;
	}
	return <Status value={ site.backup_stale ? 'moderate' : 'success' } label={ timeAgo( site.last_backup_at ) } />;
}

function UpdatesCell( { site } ) {
	if ( site.status === 'pending' ) {
		return <span className="wpc-muted">—</span>;
	}
	if ( ! site.pending_updates ) {
		return <span className="wpc-muted">Up to date</span>;
	}
	return <Status value="moderate" label={ String( site.pending_updates ) } />;
}

export default function SitesTable( { title = 'Websites', perPage = 10, compact = false, actions = null, initialStatus = '', initialClient = '' } ) {
	const { clients } = useLookups();
	const { version } = useChanges();
	const [ search, setSearch ] = useState( '' );
	const [ clientId, setClientId ] = useState( initialClient );
	const [ status, setStatus ] = useState( initialStatus );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { orderby: 'name', order: 'asc' } );
	const q = useDebounced( search );

	const { data, error, loading, reload } = useLoad(
		() => get( '/sites', { search: q, client_id: clientId, status, page, per_page: perPage, ...sort } ),
		[ q, clientId, status, page, perPage, sort, version ]
	);

	const filter = ( setter ) => ( v ) => {
		setter( v );
		setPage( 1 );
	};

	const sortBy = ( key ) =>
		setSort( ( s ) => ( { orderby: key, order: s.orderby === key && s.order === 'asc' ? 'desc' : 'asc' } ) );

	const Th = ( { k, children } ) => (
		<th>
			<button type="button" className={ `wpc-sort ${ sort.orderby === k ? `is-${ sort.order }` : '' }` } onClick={ () => sortBy( k ) }>
				{ children }
			</button>
		</th>
	);

	return (
		<Card
			flush
			title={ title }
			actions={
				<>
					<SearchInput value={ search } onChange={ filter( setSearch ) } placeholder="Search sites…" />
					<Select
						value={ clientId }
						onChange={ filter( setClientId ) }
						options={ [ { value: '', label: 'All clients' }, ...clients.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ] }
					/>
					<Select value={ status } onChange={ filter( setStatus ) } options={ STATUS_FILTERS } />
					{ actions }
				</>
			}
		>
			{ error && <ErrorBox error={ error } onRetry={ reload } /> }
			{ ! data && loading && <Loading /> }
			{ data && ! data.items.length && (
				<Empty icon="globe" title="No websites found">
					{ q || clientId || status ? 'Try different filters.' : 'Add your first website to start monitoring.' }
				</Empty>
			) }
			{ data && data.items.length > 0 && (
				<div className={ `wpc-table-wrap ${ loading ? 'is-loading' : '' }` }>
					<table className="wpc-table wpc-table--clickable">
						<thead>
							<tr>
								<Th k="name">Website</Th>
								<Th k="client_name">Client</Th>
								<Th k="status">Status</Th>
								<Th k="pending_updates">Updates</Th>
								<Th k="last_backup_at">Backup</Th>
								<Th k="php_version">PHP</Th>
								<Th k="last_sync_at">Last sync</Th>
								{ ! compact && <Th k="last_maintenance_at">Last maintenance</Th> }
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( s ) => (
								<tr key={ s.id } onClick={ () => setParams( { site: s.id, tab: 'overview' } ) }>
									<td>
										<div className="wpc-site-cell">
											<strong>{ s.name }</strong>
											<a href={ s.url } target="_blank" rel="noreferrer" onClick={ ( e ) => e.stopPropagation() } title={ s.url }>
												{ hostOf( s.url ) } <Icon name="external" size={ 11 } />
											</a>
										</div>
									</td>
									<td>{ s.client_name || <span className="wpc-muted">—</span> }</td>
									<td>
										<Status value={ s.status } />
										{ s.critical_issues > 0 && (
											<span className="wpc-crit" title={ `${ s.critical_issues } critical issue(s)` }>
												<Icon name="alert" size={ 13 } />
											</span>
										) }
									</td>
									<td>
										<UpdatesCell site={ s } />
									</td>
									<td>
										<BackupCell site={ s } />
									</td>
									<td>{ s.php_version || <span className="wpc-muted">—</span> }</td>
									<td className="wpc-nowrap">{ timeAgo( s.last_sync_at ) }</td>
									{ ! compact && (
										<td className="wpc-nowrap">
											{ s.last_maintenance_at ? (
												<>
													{ timeAgo( s.last_maintenance_at ) }
													<span className="wpc-muted"> · { s.last_maintenance_by }</span>
												</>
											) : (
												<span className="wpc-muted">—</span>
											) }
										</td>
									) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ data && (
				<Pagination page={ page } pages={ data.pages } total={ data.total } perPage={ perPage } onChange={ setPage } />
			) }
		</Card>
	);
}
