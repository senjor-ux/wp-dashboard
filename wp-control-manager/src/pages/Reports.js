import { useState } from '@wordpress/element';
import { exportUrl } from '../api';
import { useLookups } from '../context';
import { PageHeader, Card, Button, Field, Select } from '../components/ui';

const REPORTS = [
	{
		type: 'sites',
		title: 'Website health',
		text: 'One row per website: status, WordPress/PHP versions, pending updates, open issues, last backup, last sync and last maintenance.',
		dates: false,
	},
	{
		type: 'maintenance',
		title: 'Maintenance log',
		text: 'Every documented task with date, performer, category, description, duration and number of linked changes. Ideal for client invoices.',
		dates: true,
	},
	{
		type: 'events',
		title: 'Update history',
		text: 'All detected version changes (previous → new version, detection time) and who documented them, or “Unattributed”.',
		dates: true,
	},
];

export default function Reports() {
	const { clients } = useLookups();
	const [ filters, setFilters ] = useState( { client_id: '', from: '', to: '' } );
	const set = ( key ) => ( v ) => setFilters( ( f ) => ( { ...f, [ key ]: v } ) );

	return (
		<>
			<PageHeader title="Reports" subtitle="Export CSV reports for clients, invoices and audits" />
			<Card title="Filters">
				<div className="wpc-form wpc-form--grid wpc-form--3">
					<Field label="Client">
						<Select
							value={ filters.client_id }
							onChange={ set( 'client_id' ) }
							options={ [ { value: '', label: 'All clients' }, ...clients.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ] }
						/>
					</Field>
					<Field label="From">
						<input type="date" value={ filters.from } onChange={ ( e ) => set( 'from' )( e.target.value ) } />
					</Field>
					<Field label="To">
						<input type="date" value={ filters.to } onChange={ ( e ) => set( 'to' )( e.target.value ) } />
					</Field>
				</div>
			</Card>
			<div className="wpc-grid-3">
				{ REPORTS.map( ( r ) => (
					<Card key={ r.type } title={ r.title } className="wpc-report">
						<p className="wpc-muted">{ r.text }</p>
						{ ! r.dates && <p className="wpc-field__hint">Date range does not apply — reflects current state.</p> }
						<Button
							variant="primary"
							icon="download"
							onClick={ () =>
								( window.location.href = exportUrl( r.type, {
									client_id: filters.client_id,
									from: r.dates ? filters.from : '',
									to: r.dates ? filters.to : '',
								} ) )
							}
						>
							Download CSV
						</Button>
					</Card>
				) ) }
			</div>
		</>
	);
}
