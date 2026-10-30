import { createContext, useContext, useState, useEffect, useCallback } from '@wordpress/element';
import { get } from './api';

const LookupContext = createContext( { clients: [], users: [], settings: null, refresh: () => {} } );

export function LookupProvider( { children } ) {
	const [ state, setState ] = useState( { clients: [], users: [], settings: null } );

	const refresh = useCallback( () => {
		return Promise.all( [ get( '/clients' ), get( '/users' ), get( '/settings' ) ] ).then( ( [ clients, users, settings ] ) =>
			setState( { clients, users, settings } )
		);
	}, [] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	return <LookupContext.Provider value={ { ...state, refresh } }>{ children }</LookupContext.Provider>;
}

export const useLookups = () => useContext( LookupContext );

/** Bumped whenever data changes somewhere so other views can reload. */
const ChangeContext = createContext( { version: 0, bump: () => {} } );

export function ChangeProvider( { children } ) {
	const [ version, setVersion ] = useState( 0 );
	const bump = useCallback( () => setVersion( ( v ) => v + 1 ), [] );
	return <ChangeContext.Provider value={ { version, bump } }>{ children }</ChangeContext.Provider>;
}

export const useChanges = () => useContext( ChangeContext );
