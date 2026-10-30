import { useState } from '@wordpress/element';
import { exportUrl } from '../api';
import { PageHeader, Button } from '../components/ui';
import SitesTable from '../components/SitesTable';
import SiteFormModal from '../components/SiteFormModal';

export default function Sites( { params } ) {
	const [ adding, setAdding ] = useState( false );
	return (
		<>
			<PageHeader
				title="Websites"
				subtitle="Every monitored site with its health, updates and backups"
				actions={
					<>
						<Button icon="download" onClick={ () => ( window.location.href = exportUrl( 'sites' ) ) }>
							Export CSV
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => setAdding( true ) }>
							Add website
						</Button>
					</>
				}
			/>
			<SitesTable
				key={ `${ params.status || '' }|${ params.client || '' }` }
				title="All websites"
				perPage={ 25 }
				initialStatus={ params.status || '' }
				initialClient={ params.client || '' }
			/>
			{ adding && <SiteFormModal onClose={ () => setAdding( false ) } /> }
		</>
	);
}
