import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const el = document.getElementById( 'wpcontrol-root' );
if ( el ) {
	createRoot( el ).render( <App /> );
}
