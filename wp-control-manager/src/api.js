import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const base = ( window.wpControl && window.wpControl.restPath ) || '/wpcontrol/v1';

const clean = ( query = {} ) =>
	Object.fromEntries(
		Object.entries( query ).filter( ( [ , v ] ) => v !== '' && v !== null && v !== undefined && v !== false )
	);

export const get = ( path, query ) => apiFetch( { path: addQueryArgs( base + path, clean( query ) ) } );
export const post = ( path, data = {} ) => apiFetch( { path: base + path, method: 'POST', data } );
export const put = ( path, data = {} ) => apiFetch( { path: base + path, method: 'PUT', data } );
export const del = ( path ) => apiFetch( { path: base + path, method: 'DELETE' } );

export const exportUrl = ( type, query = {} ) => {
	const urls = ( window.wpControl && window.wpControl.exportUrls ) || {};
	return urls[ type ] ? addQueryArgs( urls[ type ], clean( query ) ) : '#';
};
