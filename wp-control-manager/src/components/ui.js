import { useState, useEffect, createContext, useContext, useCallback } from '@wordpress/element';
import Icon from './Icon';
import { initials } from '../utils';

export function Button( { variant = 'secondary', size, icon, busy, disabled, children, className = '', ...props } ) {
	return (
		<button
			type="button"
			className={ `wpc-btn wpc-btn--${ variant } ${ size ? `wpc-btn--${ size }` : '' } ${ className }` }
			disabled={ busy || disabled }
			{ ...props }
		>
			{ busy ? <Spinner small /> : icon && <Icon name={ icon } size={ 14 } /> }
			{ children && <span>{ children }</span> }
		</button>
	);
}

export function Spinner( { small } ) {
	return <span className={ `wpc-spinner ${ small ? 'wpc-spinner--sm' : '' }` } aria-label="Loading" />;
}

export function Loading() {
	return (
		<div className="wpc-loading">
			<Spinner />
		</div>
	);
}

export function ErrorBox( { error, onRetry } ) {
	return (
		<div className="wpc-error">
			<Icon name="alert" />
			<span>{ ( error && error.message ) || 'Failed to load.' }</span>
			{ onRetry && (
				<Button size="sm" onClick={ onRetry }>
					Retry
				</Button>
			) }
		</div>
	);
}

export function Empty( { icon = 'checkCircle', title, children } ) {
	return (
		<div className="wpc-empty">
			<Icon name={ icon } size={ 28 } />
			<strong>{ title }</strong>
			{ children && <p>{ children }</p> }
		</div>
	);
}

export function Card( { title, actions, children, className = '', flush } ) {
	return (
		<section className={ `wpc-card ${ flush ? 'wpc-card--flush' : '' } ${ className }` }>
			{ ( title || actions ) && (
				<header className="wpc-card__head">
					{ title && <h3>{ title }</h3> }
					{ actions && <div className="wpc-card__actions">{ actions }</div> }
				</header>
			) }
			{ children }
		</section>
	);
}

export function Avatar( { name, system } ) {
	return <span className={ `wpc-avatar ${ system ? 'wpc-avatar--system' : '' }` }>{ system ? 'S' : initials( name ) }</span>;
}

export function Person( { name, system, muted } ) {
	return (
		<span className={ `wpc-person ${ muted ? 'is-muted' : '' }` }>
			<Avatar name={ name } system={ system } />
			<span>{ name }</span>
		</span>
	);
}

const STATUS_LABELS = {
	online: 'Online',
	offline: 'Offline',
	pending: 'Not paired',
	success: 'Success',
	failed: 'Failed',
	running: 'Running',
	unknown: 'Unknown',
	open: 'Open',
	resolved: 'Resolved',
	critical: 'Critical',
	moderate: 'Moderate',
	low: 'Low',
};

const STATUS_TONE = {
	online: 'green',
	success: 'green',
	resolved: 'green',
	offline: 'red',
	failed: 'red',
	critical: 'red',
	open: 'red',
	moderate: 'amber',
	running: 'blue',
	low: 'blue',
	pending: 'gray',
	unknown: 'gray',
};

export function Status( { value, label } ) {
	const tone = STATUS_TONE[ value ] || 'gray';
	return (
		<span className={ `wpc-status wpc-status--${ tone }` }>
			<i />
			{ label || STATUS_LABELS[ value ] || value }
		</span>
	);
}

export function Badge( { tone = 'gray', children } ) {
	return <span className={ `wpc-badge wpc-badge--${ tone }` }>{ children }</span>;
}

export function PageHeader( { title, subtitle, actions } ) {
	return (
		<div className="wpc-page-head">
			<div>
				<h1>{ title }</h1>
				{ subtitle && <p>{ subtitle }</p> }
			</div>
			{ actions && <div className="wpc-page-head__actions">{ actions }</div> }
		</div>
	);
}

export function Tabs( { tabs, value, onChange } ) {
	return (
		<nav className="wpc-tabs" role="tablist">
			{ tabs.map( ( t ) => (
				<button
					type="button"
					role="tab"
					key={ t.id }
					aria-selected={ value === t.id }
					className={ value === t.id ? 'is-active' : '' }
					onClick={ () => onChange( t.id ) }
				>
					{ t.label }
					{ t.count ? <span className="wpc-tabs__count">{ t.count }</span> : null }
				</button>
			) ) }
		</nav>
	);
}

export function Pills( { options, value, onChange } ) {
	return (
		<div className="wpc-pills">
			{ options.map( ( o ) => (
				<button
					type="button"
					key={ o.id }
					className={ value === o.id ? 'is-active' : '' }
					onClick={ () => onChange( o.id ) }
				>
					{ o.label }
				</button>
			) ) }
		</div>
	);
}

export function Pagination( { page, pages, total, perPage, onChange } ) {
	if ( ! total ) {
		return null;
	}
	const from = ( page - 1 ) * perPage + 1;
	const to = Math.min( total, page * perPage );
	const nums = [];
	for ( let i = Math.max( 1, page - 2 ); i <= Math.min( pages, page + 2 ); i++ ) {
		nums.push( i );
	}
	return (
		<div className="wpc-pagination">
			<div className="wpc-pagination__pages">
				<button type="button" disabled={ page <= 1 } onClick={ () => onChange( page - 1 ) } aria-label="Previous page">
					<Icon name="chevronLeft" />
				</button>
				{ nums.map( ( n ) => (
					<button type="button" key={ n } className={ n === page ? 'is-active' : '' } onClick={ () => onChange( n ) }>
						{ n }
					</button>
				) ) }
				<button type="button" disabled={ page >= pages } onClick={ () => onChange( page + 1 ) } aria-label="Next page">
					<Icon name="chevronRight" />
				</button>
			</div>
			<span className="wpc-muted">
				{ from }–{ to } of { total }
			</span>
		</div>
	);
}

function useEscape( onClose ) {
	useEffect( () => {
		const onKey = ( e ) => e.key === 'Escape' && onClose();
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ onClose ] );
}

export function Modal( { title, onClose, children, footer, wide } ) {
	useEscape( onClose );
	return (
		<div className="wpc-overlay" onMouseDown={ ( e ) => e.target === e.currentTarget && onClose() }>
			<div className={ `wpc-modal ${ wide ? 'wpc-modal--wide' : '' }` } role="dialog" aria-modal="true" aria-label={ title }>
				<header className="wpc-modal__head">
					<h2>{ title }</h2>
					<button type="button" className="wpc-icon-btn" onClick={ onClose } aria-label="Close">
						<Icon name="x" />
					</button>
				</header>
				<div className="wpc-modal__body">{ children }</div>
				{ footer && <footer className="wpc-modal__foot">{ footer }</footer> }
			</div>
		</div>
	);
}

export function Drawer( { onClose, children } ) {
	useEscape( onClose );
	return (
		<div className="wpc-overlay wpc-overlay--drawer" onMouseDown={ ( e ) => e.target === e.currentTarget && onClose() }>
			<aside className="wpc-drawer" role="dialog" aria-modal="true">
				{ children }
			</aside>
		</div>
	);
}

export function Field( { label, hint, children, required } ) {
	return (
		<label className="wpc-field">
			<span className="wpc-field__label">
				{ label }
				{ required && <em> *</em> }
			</span>
			{ children }
			{ hint && <span className="wpc-field__hint">{ hint }</span> }
		</label>
	);
}

export function SearchInput( { value, onChange, placeholder = 'Search…' } ) {
	return (
		<div className="wpc-search">
			<Icon name="search" />
			<input type="search" value={ value } placeholder={ placeholder } onChange={ ( e ) => onChange( e.target.value ) } />
		</div>
	);
}

export function Select( { value, onChange, options, ...props } ) {
	return (
		<select className="wpc-select" value={ value } onChange={ ( e ) => onChange( e.target.value ) } { ...props }>
			{ options.map( ( o ) => (
				<option key={ o.value } value={ o.value }>
					{ o.label }
				</option>
			) ) }
		</select>
	);
}

export function CopyField( { value } ) {
	const [ copied, setCopied ] = useState( false );
	const copy = () => {
		const done = () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		};
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( value ).then( done );
		}
	};
	return (
		<div className="wpc-copy">
			<code>{ value }</code>
			<Button size="sm" icon={ copied ? 'check' : 'copy' } onClick={ copy }>
				{ copied ? 'Copied' : 'Copy' }
			</Button>
		</div>
	);
}

const ToastContext = createContext( () => {} );

export function ToastProvider( { children } ) {
	const [ toasts, setToasts ] = useState( [] );
	const push = useCallback( ( message, tone = 'success' ) => {
		const id = Math.random().toString( 36 ).slice( 2 );
		setToasts( ( t ) => [ ...t, { id, message, tone } ] );
		setTimeout( () => setToasts( ( t ) => t.filter( ( x ) => x.id !== id ) ), 4000 );
	}, [] );
	return (
		<ToastContext.Provider value={ push }>
			{ children }
			<div className="wpc-toasts" aria-live="polite">
				{ toasts.map( ( t ) => (
					<div key={ t.id } className={ `wpc-toast wpc-toast--${ t.tone }` }>
						<Icon name={ t.tone === 'error' ? 'alert' : 'checkCircle' } />
						{ t.message }
					</div>
				) ) }
			</div>
		</ToastContext.Provider>
	);
}

export const useToast = () => useContext( ToastContext );
