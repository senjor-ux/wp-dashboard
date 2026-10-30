import { useState } from '@wordpress/element';
import { get, del, exportUrl } from '../api';
import { useLookups, useChanges } from '../context';
import { useLoad, useDebounced, formatDate, formatDuration, setParams, errorMessage } from '../utils';
import { PageHeader, Card, Button, SearchInput, Select, Pagination, Loading, ErrorBox, Empty, Person, Badge, useToast } from '../components/ui';
import Icon from '../components/Icon';
import { MaintenanceEditor } from '../components/SiteDrawer';

const PER_PAGE = 25;

export default function Maintenance() {
	const { clients, settings } = useLookups();
	const { version, bump } = useChanges();
	const toast = useToast();
	const [ filters, setFilters ] = useState( { search: '', client_id: '', category: '', from: '', to: '' } );
	const [ page, setPage ] = useState( 1 );
	const [ editing, setEditing ] = useState( null );
	const search = useDebounced( filters.search );
	const categories = ( settings && settings.maintenance_categories ) || [];

	const query = { ...filters, search };
	const { data, error, loading, reload } = useLoad(
		() => get( '/maintenance', { ...query, page, per_page: PER_PAGE } ),
		[ search, filters.client_id, filters.category, filters.from, filters.to, page, version ]
	);

	const set = ( key ) => ( v ) => {
		setFilters( ( f ) => ( { ...f, [ key ]: v } ) );
		setPage( 1 );
	};

	const remove = ( m ) => {
		if ( ! window.confirm( 'Delete this maintenance record? Linked changes become unattributed again. The deletion is kept in the audit log.' ) ) {
			return;
		}
		del( `/maintenance/${ m.id }` )
			.then( () => {
				toast( 'Record deleted' );
				bump();
			} )
			.catch( ( e ) => toast( errorMessage( e ), 'error' ) );
	};

	return (
		<>
			<PageHeader
				title="Maintenance log"
				subtitle="Documented work — who did what, when, and on which site"
				actions={
					<>
						<Button icon="download" onClick={ () => ( window.location.href = exportUrl( 'maintenance', { client_id: filters.client_id, from: filters.from, to: filters.to } ) ) }>
							Export CSV
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => setEditing( {} ) }>
							Log maintenance
						</Button>
					</>
				}
			/>
			<Card
				flush
				actions={
					<>
						<SearchInput value={ filters.search } onChange={ set( 'search' ) } placeholder="Search description, site…" />
						<Select
							value={ filters.client_id }
							onChange={ set( 'client_id' ) }
							options={ [ { value: '', label: 'All clients' }, ...clients.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ] }
						/>
						<Select value={ filters.category } onChange={ set( 'category' ) } options={ [ { value: '', label: 'All categories' }, ...categories.map( ( c ) => ( { value: c, label: c } ) ) ] } />
						<input type="date" className="wpc-date" value={ filters.from } onChange={ ( e ) => set( 'from' )( e.target.value ) } aria-label="From" />
						<input type="date" className="wpc-date" value={ filters.to } onChange={ ( e ) => set( 'to' )( e.target.value ) } aria-label="To" />
					</>
				}
			>
				{ error && <ErrorBox error={ error } onRetry={ reload } /> }
				{ ! data && loading && <Loading /> }
				{ data && ! data.items.length && <Empty icon="wrench" title="No maintenance records match" /> }
				{ data && data.items.length > 0 && (
					<div className={ `wpc-table-wrap ${ loading ? 'is-loading' : '' }` }>
						<table className="wpc-table">
							<thead>
								<tr>
									<th>Performed</th>
									<th>Website</th>
									<th>Performed by</th>
									<th>Category</th>
									<th>Description</th>
									<th>Duration</th>
									<th>Changes</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( m ) => (
									<tr key={ m.id }>
										<td className="wpc-nowrap">{ formatDate( m.performed_at ) }</td>
										<td>
											<button type="button" className="wpc-link" onClick={ () => setParams( { site: m.site_id, tab: 'maintenance' } ) }>
												{ m.site_name }
											</button>
											{ m.client_name && <div className="wpc-muted wpc-small">{ m.client_name }</div> }
										</td>
										<td>
											<Person name={ m.performer_name } />
										</td>
										<td>
											<Badge tone="blue">{ m.category }</Badge>
										</td>
										<td className="wpc-desc">
											{ m.description }
											{ m.notes && <div className="wpc-muted wpc-small">{ m.notes }</div> }
										</td>
										<td>{ formatDuration( m.duration_minutes ) }</td>
										<td>{ m.linked_events || <span className="wpc-muted">—</span> }</td>
										<td className="wpc-actions-cell">
											<button type="button" className="wpc-icon-btn" onClick={ () => setEditing( m ) } aria-label="Edit">
												<Icon name="edit" size={ 14 } />
											</button>
											<button type="button" className="wpc-icon-btn" onClick={ () => remove( m ) } aria-label="Delete">
												<Icon name="trash" size={ 14 } />
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ data && <Pagination page={ page } pages={ data.pages } total={ data.total } perPage={ PER_PAGE } onChange={ setPage } /> }
			</Card>
			{ editing && <MaintenanceEditor record={ editing.id ? editing : null } onClose={ () => setEditing( null ) } /> }
		</>
	);
}
