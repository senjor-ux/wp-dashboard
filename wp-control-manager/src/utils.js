import { useState, useEffect, useCallback, useRef } from '@wordpress/element';

export function timeAgo( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const diff = ( Date.now() - new Date( iso ).getTime() ) / 1000;
	if ( diff < 0 ) {
		return 'just now';
	}
	if ( diff < 60 ) {
		return 'just now';
	}
	const units = [
		[ 60 * 60 * 24 * 365, 'y' ],
		[ 60 * 60 * 24 * 30, 'mo' ],
		[ 60 * 60 * 24, 'd' ],
		[ 60 * 60, 'h' ],
		[ 60, 'min' ],
	];
	for ( const [ secs, label ] of units ) {
		if ( diff >= secs ) {
			return `${ Math.floor( diff / secs ) }${ label === 'min' ? ' min' : label } ago`;
		}
	}
	return 'just now';
}

export function formatDate( iso, withTime = true ) {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	const opts = { day: '2-digit', month: 'short', year: 'numeric' };
	if ( withTime ) {
		opts.hour = '2-digit';
		opts.minute = '2-digit';
	}
	return d.toLocaleString( undefined, opts );
}

/** ISO -> value for <input type="datetime-local"> in the browser's timezone. */
export function toLocalInput( iso ) {
	const d = iso ? new Date( iso ) : new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
}

export function fromLocalInput( value ) {
	return value ? new Date( value ).toISOString() : '';
}

export function formatBytes( bytes ) {
	if ( bytes === null || bytes === undefined ) {
		return '—';
	}
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let i = 0;
	let n = bytes;
	while ( n >= 1024 && i < units.length - 1 ) {
		n /= 1024;
		i++;
	}
	return `${ n.toFixed( i > 1 ? 1 : 0 ) } ${ units[ i ] }`;
}

export function formatDuration( minutes ) {
	if ( ! minutes && minutes !== 0 ) {
		return '—';
	}
	const h = Math.floor( minutes / 60 );
	const m = minutes % 60;
	return h ? `${ h }h${ m ? ` ${ m }m` : '' }` : `${ m }m`;
}

export function hostOf( url ) {
	try {
		return new URL( url ).host;
	} catch ( e ) {
		return url;
	}
}

export function initials( name = '' ) {
	return (
		name
			.split( /\s+/ )
			.filter( Boolean )
			.slice( 0, 2 )
			.map( ( p ) => p[ 0 ].toUpperCase() )
			.join( '' ) || '?'
	);
}

// Hash router: #/path?key=value
function parseHash() {
	const raw = window.location.hash.replace( /^#/, '' ) || '/overview';
	const [ path, qs = '' ] = raw.split( '?' );
	return { path: path || '/overview', params: Object.fromEntries( new URLSearchParams( qs ) ) };
}

export function buildHash( path, params = {} ) {
	const qs = new URLSearchParams(
		Object.entries( params ).filter( ( [ , v ] ) => v !== undefined && v !== null && v !== '' )
	).toString();
	return `#${ path }${ qs ? `?${ qs }` : '' }`;
}

export function useRoute() {
	const [ route, setRoute ] = useState( parseHash );
	useEffect( () => {
		const onChange = () => setRoute( parseHash() );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );
	return route;
}

export function navigate( path, params ) {
	window.location.hash = buildHash( path, params );
}

/** Merge params into the current route (null removes a key). */
export function setParams( changes ) {
	const { path, params } = parseHash();
	navigate( path, { ...params, ...changes } );
}

/**
 * Load data with a fetcher; re-runs when deps change. Returns { data, error, loading, reload }.
 * @param {Function} fetcher
 * @param {Array}    deps
 */
export function useLoad( fetcher, deps = [] ) {
	const [ state, setState ] = useState( { data: null, error: null, loading: true } );
	const seq = useRef( 0 );

	const run = useCallback( () => {
		const id = ++seq.current;
		setState( ( s ) => ( { ...s, loading: true, error: null } ) );
		return fetcher()
			.then( ( data ) => {
				if ( id === seq.current ) {
					setState( { data, error: null, loading: false } );
				}
			} )
			.catch( ( error ) => {
				if ( id === seq.current ) {
					setState( ( s ) => ( { ...s, error, loading: false } ) );
				}
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, deps );

	useEffect( () => {
		run();
	}, [ run ] );

	return { ...state, reload: run };
}

export function useDebounced( value, delay = 300 ) {
	const [ v, setV ] = useState( value );
	useEffect( () => {
		const t = setTimeout( () => setV( value ), delay );
		return () => clearTimeout( t );
	}, [ value, delay ] );
	return v;
}

export const errorMessage = ( e ) => ( e && ( e.message || e.code ) ) || 'Something went wrong.';
