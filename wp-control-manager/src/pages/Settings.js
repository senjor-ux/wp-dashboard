import { useState, useEffect } from '@wordpress/element';
import { post } from '../api';
import { useLookups } from '../context';
import { errorMessage } from '../utils';
import { PageHeader, Card, Button, Field, Loading, useToast } from '../components/ui';

export default function Settings() {
	const { settings, refresh } = useLookups();
	const toast = useToast();
	const [ form, setForm ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		if ( settings ) {
			setForm( { ...settings, categories_text: settings.maintenance_categories.join( '\n' ) } );
		}
	}, [ settings ] );

	if ( ! form ) {
		return <Loading />;
	}

	const set = ( key, v ) => setForm( ( f ) => ( { ...f, [ key ]: v } ) );

	const save = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const { categories_text: text, ...rest } = form;
		post( '/settings', {
			...rest,
			maintenance_categories: text.split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean ),
		} )
			.then( refresh )
			.then( () => toast( 'Settings saved' ) )
			.catch( ( err ) => toast( errorMessage( err ), 'error' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<PageHeader title="Settings" subtitle="Monitoring thresholds, security and maintenance categories" />
			<form onSubmit={ save } className="wpc-settings">
				<Card title="Monitoring">
					<div className="wpc-form wpc-form--grid">
						<Field label="Mark site offline after (hours)" hint="When no snapshot has been received for this long.">
							<input type="number" min="1" max="168" value={ form.offline_after_hours } onChange={ ( e ) => set( 'offline_after_hours', e.target.value ) } />
						</Field>
						<Field label="Backup is stale after (days)" hint="Highlights sites whose last successful backup is older.">
							<input type="number" min="1" max="90" value={ form.backup_stale_days } onChange={ ( e ) => set( 'backup_stale_days', e.target.value ) } />
						</Field>
					</div>
				</Card>
				<Card title="Security">
					<label className="wpc-check">
						<input type="checkbox" checked={ !! form.require_https } onChange={ ( e ) => set( 'require_https', e.target.checked ) } />
						<span>
							<strong>Strict transport</strong>
							<span className="wpc-field__hint">
								Require HTTPS for all agent communication and refuse to contact private/local network addresses. Only
								disable for local development.
							</span>
						</span>
					</label>
					<p className="wpc-field__hint">
						Every agent has its own secret (encrypted at rest). Requests are HMAC-SHA256 signed with a timestamp and
						single-use nonce, so captured requests cannot be replayed.
					</p>
				</Card>
				<Card title="Maintenance categories">
					<Field label="One category per line">
						<textarea rows="7" value={ form.categories_text } onChange={ ( e ) => set( 'categories_text', e.target.value ) } />
					</Field>
				</Card>
				<Card title="Data">
					<label className="wpc-check">
						<input type="checkbox" checked={ !! form.delete_data_on_uninstall } onChange={ ( e ) => set( 'delete_data_on_uninstall', e.target.checked ) } />
						<span>
							<strong>Delete all WP Control data when the plugin is uninstalled</strong>
							<span className="wpc-field__hint">Clients, sites, history and maintenance records are permanently removed.</span>
						</span>
					</label>
				</Card>
				<div className="wpc-row-actions wpc-row-actions--end">
					<Button variant="primary" type="submit" busy={ busy }>
						Save settings
					</Button>
				</div>
			</form>
		</>
	);
}
