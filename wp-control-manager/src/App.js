import { useState } from '@wordpress/element';
import { useRoute, navigate } from './utils';
import { LookupProvider, ChangeProvider } from './context';
import { ToastProvider, Button, Avatar } from './components/ui';
import Icon from './components/Icon';
import SiteDrawer from './components/SiteDrawer';
import SiteFormModal from './components/SiteFormModal';
import Overview from './pages/Overview';
import Sites from './pages/Sites';
import Clients from './pages/Clients';
import Maintenance from './pages/Maintenance';
import Activity from './pages/Activity';
import Reports from './pages/Reports';
import Settings from './pages/Settings';

const NAV = [
	{ path: '/overview', label: 'Overview', icon: 'overview', Page: Overview },
	{ path: '/sites', label: 'Websites', icon: 'globe', Page: Sites },
	{ path: '/clients', label: 'Clients', icon: 'users', Page: Clients },
	{ path: '/maintenance', label: 'Maintenance', icon: 'wrench', Page: Maintenance },
	{ path: '/activity', label: 'Activity', icon: 'activity', Page: Activity },
	{ path: '/reports', label: 'Reports', icon: 'report', Page: Reports },
	{ path: '/settings', label: 'Settings', icon: 'settings', Page: Settings },
];

export default function App() {
	const { path, params } = useRoute();
	const [ adding, setAdding ] = useState( false );
	const current = NAV.find( ( n ) => n.path === path ) || NAV[ 0 ];
	const { Page } = current;
	const user = ( window.wpControl && window.wpControl.user ) || { name: '' };

	return (
		<ToastProvider>
			<ChangeProvider>
				<LookupProvider>
					<div className="wpc-app">
						<aside className="wpc-sidebar">
							<div className="wpc-brand">
								<Icon name="logo" size={ 22 } />
								<span>WP Control</span>
							</div>
							<nav>
								{ NAV.map( ( n ) => (
									<a
										key={ n.path }
										href={ `#${ n.path }` }
										className={ n === current ? 'is-active' : '' }
										onClick={ ( e ) => {
											e.preventDefault();
											navigate( n.path );
										} }
									>
										<Icon name={ n.icon } />
										{ n.label }
									</a>
								) ) }
							</nav>
							<div className="wpc-sidebar__foot">v{ window.wpControl && window.wpControl.version }</div>
						</aside>
						<div className="wpc-main">
							<header className="wpc-topbar">
								<Button variant="primary" size="sm" icon="plus" onClick={ () => setAdding( true ) }>
									Add website
								</Button>
								<span className="wpc-topbar__user">
									<Avatar name={ user.name } />
									{ user.name }
								</span>
							</header>
							<main className="wpc-content">
								<Page params={ params } />
							</main>
						</div>
						{ params.site && <SiteDrawer key={ params.site } siteId={ Number( params.site ) } tab={ params.tab || 'overview' } /> }
						{ adding && <SiteFormModal onClose={ () => setAdding( false ) } /> }
					</div>
				</LookupProvider>
			</ChangeProvider>
		</ToastProvider>
	);
}
