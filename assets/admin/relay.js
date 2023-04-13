/**
 * Content Relay — relay screen.
 *
 * Drives a relay through this site's own admin REST routes (content-relay/v1/admin/*): discover → start → upload steps
 * → plan (with diffs) → apply → roll back. The signing key never reaches the browser. Everything the receiver returns
 * is rendered as text, never as HTML.
 *
 * @package ContentRelay
 */

/* global contentRelayConfig */
( function ( wp, config ) {
	'use strict';

	const root = document.getElementById( 'content-relay-app' );
	if ( ! root || ! wp || ! wp.apiFetch || ! wp.i18n || ! config ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const BASE = '/content-relay/v1/admin';

	const ACTIONS = {
		create: __( 'Create', 'content-relay' ),
		'create-new-file': __( 'Create (new file name)', 'content-relay' ),
		'replace-file': __( 'Replace file', 'content-relay' ),
		update: __( 'Update', 'content-relay' ),
		overwrite: __( 'Overwrite', 'content-relay' ),
		adopt: __( 'Adopt', 'content-relay' ),
		unchanged: __( 'Unchanged', 'content-relay' ),
		conflict: __( 'Conflict', 'content-relay' ),
		reuse: __( 'Use existing', 'content-relay' ),
		map: __( 'Map user', 'content-relay' ),
		missing: __( 'Missing', 'content-relay' ),
	};
	const CHANGE_ACTIONS = [ 'create', 'create-new-file', 'replace-file', 'update', 'overwrite', 'adopt' ];

	const state = {
		phase: 'choose', // choose → preparing → review → applying → done; stalled when an upload fails mid-way.
		busy: false,
		error: '',
		connection: config.connections.length === 1 ? config.connections[ 0 ].label : '',
		discovery: null,
		session: null,
		receiver: '',
		progress: null,
		plan: null,
		filesPending: 0,
		overwrite: false,
		onlyChanges: true,
		open: {},
		result: null,
		rollback: null,
		rollbackOverwrite: false,
		objectCache: false,
		flushCache: false,
		verification: null,
		verifying: false,
		verificationError: '',
	};

	/* ------------------------------------------------------------------ helpers */

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( name ) => {
			const value = attrs[ name ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( name === 'class' ) {
				node.className = value;
			} else if ( name === 'text' ) {
				node.textContent = String( value );
			} else if ( name.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( name.slice( 2 ), value );
			} else if ( value === true ) {
				node.setAttribute( name, '' );
			} else {
				node.setAttribute( name, String( value ) );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild( typeof child === 'object' ? child : document.createTextNode( String( child ) ) );
		} );
		return node;
	}

	function button( text, onClick, options ) {
		const opts = options || {};
		return el( 'button', { type: 'button', class: 'button' + ( opts.primary ? ' button-primary' : '' ) + ( opts.danger ? ' button-link-delete' : '' ), disabled: !! opts.disabled, onclick: onClick, text } );
	}

	function notice( type, message, extra ) {
		return el( 'div', { class: 'notice notice-' + type + ' inline content-relay-notice' }, [ el( 'p', { text: message } ), extra || null ] );
	}

	function list( items ) {
		return el( 'ul', { class: 'content-relay-list' }, items.map( ( item ) => el( 'li', { text: item } ) ) );
	}

	function link( url, text ) {
		try {
			const parsed = new URL( url );
			if ( parsed.protocol === 'https:' || parsed.protocol === 'http:' ) {
				return el( 'a', { href: parsed.href, target: '_blank', rel: 'noopener noreferrer', text: text || parsed.href } );
			}
		} catch ( e ) {
			// Not a URL: shown as text below.
		}
		return document.createTextNode( text || String( url || '' ) );
	}

	function bytes( value ) {
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let size = Number( value ) || 0;
		let unit = 0;
		while ( size >= 1024 && unit < units.length - 1 ) {
			size /= 1024;
			unit++;
		}
		return ( unit === 0 ? size : size.toFixed( size < 10 ? 1 : 0 ) ) + ' ' + units[ unit ];
	}

	function errorMessage( error ) {
		return error && error.message ? error.message : __( 'Something went wrong. Check the connection and try again.', 'content-relay' );
	}

	function setState( changes ) {
		Object.assign( state, changes );
		render();
	}

	function relayPath( suffix ) {
		return BASE + '/relays/' + state.session + ( suffix || '' );
	}

	/* ------------------------------------------------------------------ steps */

	async function discover() {
		setState( { busy: true, error: '' } );
		try {
			const response = await apiFetch( { path: BASE + '/discover?post=' + config.post.id } );
			setState( { busy: false, discovery: response.data } );
		} catch ( error ) {
			setState( { busy: false, error: errorMessage( error ) } );
		}
	}

	async function prepare() {
		setState( { busy: true, error: '', phase: 'preparing', progress: null, plan: null, result: null, rollback: null, open: {} } );
		try {
			const response = await apiFetch( { path: BASE + '/relays', method: 'POST', data: { post: config.post.id, connection: state.connection, overwrite: state.overwrite } } );
			setState( { session: response.data.session, receiver: response.data.receiver, progress: response.data.progress, objectCache: !! response.data.object_cache } );
			await uploadAll( response.data );
		} catch ( error ) {
			setState( { busy: false, error: errorMessage( error ), phase: state.session ? 'stalled' : 'choose' } );
		}
	}

	async function uploadAll( initial ) {
		let data = initial;
		while ( ! data.progress.done ) {
			const response = await apiFetch( { path: relayPath( '/upload' ), method: 'POST' } );
			data = response.data;
			setState( { progress: data.progress } );
		}
		await loadPlan();
	}

	async function resumeUpload() {
		setState( { busy: true, error: '', phase: 'preparing' } );
		try {
			await uploadAll( { progress: { done: false } } );
		} catch ( error ) {
			setState( { busy: false, error: errorMessage( error ), phase: 'stalled' } );
		}
	}

	async function loadPlan() {
		setState( { busy: true, error: '' } );
		const response = await apiFetch( { path: relayPath( '/plan?overwrite=' + ( state.overwrite ? '1' : '0' ) ) } );
		setState( { busy: false, phase: 'review', plan: response.data.plan, filesPending: response.data.files_pending || 0 } );
	}

	async function refreshPlan( changes ) {
		setState( Object.assign( { busy: true, error: '' }, changes || {} ) );
		try {
			await loadPlan();
		} catch ( error ) {
			setState( { busy: false, error: errorMessage( error ) } );
		}
	}

	async function apply() {
		const count = state.plan.items.filter( ( item ) => CHANGE_ACTIONS.indexOf( item.action ) !== -1 ).length;
		/* translators: 1: number of changes, 2: receiving site address. */
		if ( ! window.confirm( sprintf( _n( 'Relay %1$d change to %2$s now?', 'Relay %1$d changes to %2$s now?', count, 'content-relay' ), count, state.receiver ) ) ) {
			return;
		}
		setState( { busy: true, error: '', phase: 'applying' } );
		try {
			const response = await apiFetch( { path: relayPath( '/apply' ), method: 'POST', data: { plan_hash: state.plan.hash, overwrite: state.overwrite, flush_cache: state.objectCache && state.flushCache } } );
			setState( { busy: false, phase: 'done', result: response.data, verification: null, verificationError: '' } );
		} catch ( error ) {
			setState( { busy: false, phase: 'review', error: errorMessage( error ) } );
			return;
		}
		verify();
	}

	async function verify() {
		setState( { verifying: true, verificationError: '' } );
		try {
			const response = await apiFetch( { path: relayPath( '/verify' ), method: 'POST' } );
			setState( { verifying: false, verification: response.data } );
		} catch ( error ) {
			setState( { verifying: false, verificationError: errorMessage( error ) } );
		}
	}

	async function abort() {
		setState( { busy: true, error: '' } );
		try {
			await apiFetch( { path: relayPath(), method: 'DELETE' } );
			setState( { busy: false, phase: 'choose', session: null, plan: null, progress: null, overwrite: false } );
		} catch ( error ) {
			setState( { busy: false, error: errorMessage( error ) } );
		}
	}

	async function rollback() {
		if ( ! window.confirm( __( 'Roll back this relay on the receiving site? What it replaced is restored and what it created is removed.', 'content-relay' ) ) ) {
			return;
		}
		setState( { busy: true, error: '' } );
		try {
			const response = await apiFetch( { path: relayPath( '/rollback' ), method: 'POST', data: { overwrite: state.rollbackOverwrite } } );
			setState( { busy: false, rollback: response.data.rollback || {} } );
		} catch ( error ) {
			setState( { busy: false, error: errorMessage( error ) } );
		}
	}

	/* ------------------------------------------------------------------ views */

	function renderDiscovery() {
		const card = el( 'div', { class: 'content-relay-card' }, el( 'h2', { text: __( 'What will be relayed', 'content-relay' ) } ) );
		const data = state.discovery;
		if ( ! data ) {
			card.appendChild( el( 'p', { text: state.busy ? __( 'Collecting the content, its translations and media…', 'content-relay' ) : '' } ) );
			return card;
		}

		card.appendChild(
			list(
				data.roots.map( ( item ) =>
					/* translators: 1: language code, 2: title, 3: path, 4: post status. */
					sprintf( __( '%1$s: %2$s (/%3$s/, %4$s)', 'content-relay' ), ( item.language || '—' ).toUpperCase(), item.title, item.path, item.status )
				)
			)
		);

		const owned = data.objects.filter( ( item ) => item.role === 'owned' );
		card.appendChild(
			el( 'p', {
				/* translators: 1: number of objects written, 2: number of files, 3: total file size, 4: number of objects that must already exist. */
				text: sprintf( __( '%1$d objects are written on the receiver, with %2$d files (%3$s). %4$d referenced objects must already exist there.', 'content-relay' ), owned.length, data.files, bytes( data.bytes ), data.objects.length - owned.length ),
			} )
		);

		if ( data.warnings.length ) {
			card.appendChild( notice( 'warning', __( 'Warnings from this site:', 'content-relay' ), list( data.warnings ) ) );
		}

		const rows = data.objects.map( ( item ) =>
			el( 'tr', {}, [
				el( 'td', { text: item.kind } ),
				el( 'td', { text: item.role } ),
				el( 'td', { text: ( item.language || '' ).toUpperCase() } ),
				el( 'td', { text: item.label } ),
				el( 'td', { text: item.bytes ? bytes( item.bytes ) : '' } ),
				el( 'td', { text: item.relations } ),
			] )
		);
		card.appendChild(
			el( 'details', { class: 'content-relay-details' }, [
				/* translators: %d: number of objects. */
				el( 'summary', { text: sprintf( __( 'All objects (%d)', 'content-relay' ), data.objects.length ) } ),
				table( [ __( 'Kind', 'content-relay' ), __( 'Role', 'content-relay' ), __( 'Language', 'content-relay' ), __( 'Object', 'content-relay' ), __( 'Size', 'content-relay' ), __( 'Needed as', 'content-relay' ) ], rows ),
			] )
		);

		return card;
	}

	function table( headings, rows ) {
		return el( 'table', { class: 'widefat striped content-relay-table' }, [
			el( 'thead', {}, el( 'tr', {}, headings.map( ( text ) => el( 'th', { scope: 'col', text } ) ) ) ),
			el( 'tbody', {}, rows ),
		] );
	}

	function renderChoose() {
		const card = el( 'div', { class: 'content-relay-card' }, el( 'h2', { text: __( 'Send to', 'content-relay' ) } ) );
		const select = el(
			'select',
			{ id: 'content-relay-connection', onchange: ( event ) => setState( { connection: event.target.value } ) },
			[ el( 'option', { value: '', text: __( 'Choose a connection', 'content-relay' ) } ) ].concat(
				config.connections.map( ( connection ) => el( 'option', { value: connection.label, selected: connection.label === state.connection, text: connection.label + ' — ' + connection.url } ) )
			)
		);
		card.appendChild( el( 'p', {}, [ el( 'label', { for: 'content-relay-connection', class: 'screen-reader-text', text: __( 'Connection', 'content-relay' ) } ), select ] ) );
		card.appendChild( el( 'p', { class: 'description', text: __( 'The content and its media are sent to the receiving site, which plans the change. Nothing is written there until you approve the plan.', 'content-relay' ) } ) );
		card.appendChild( el( 'p', {}, button( __( 'Prepare relay', 'content-relay' ), prepare, { primary: true, disabled: state.busy || ! state.connection || ! state.discovery } ) ) );
		return card;
	}

	function renderProgress() {
		const card = el( 'div', { class: 'content-relay-card' }, el( 'h2', { text: __( 'Sending files', 'content-relay' ) } ) );
		const progress = state.progress;
		if ( ! progress ) {
			card.appendChild( el( 'p', { text: __( 'Opening a session on the receiving site…', 'content-relay' ) } ) );
			return card;
		}
		const percent = progress.bytes_total ? Math.round( ( 100 * progress.bytes_received ) / progress.bytes_total ) : 100;
		card.appendChild( el( 'progress', { class: 'content-relay-progress', max: 100, value: percent } ) );
		card.appendChild(
			el( 'p', {
				text: progress.done
					? __( 'Files received. Asking the receiving site for its plan…', 'content-relay' )
					/* translators: 1: files complete, 2: files total, 3: bytes sent, 4: bytes total. */
					: sprintf( __( '%1$d of %2$d files, %3$s of %4$s', 'content-relay' ), progress.files_complete, progress.files_total, bytes( progress.bytes_received ), bytes( progress.bytes_total ) ),
			} )
		);
		return card;
	}

	function renderStalled() {
		return el( 'div', { class: 'content-relay-card' }, [
			el( 'p', { text: __( 'The upload stopped. What the receiving site already has is kept; continuing sends only the rest.', 'content-relay' ) } ),
			el( 'p', { class: 'content-relay-buttons' }, [ button( __( 'Continue upload', 'content-relay' ), resumeUpload, { primary: true, disabled: state.busy } ), button( __( 'Cancel relay', 'content-relay' ), abort, { disabled: state.busy } ) ] ),
		] );
	}

	function renderPlan() {
		const plan = state.plan;
		const changes = plan.items.filter( ( item ) => CHANGE_ACTIONS.indexOf( item.action ) !== -1 );
		const conflicts = plan.items.filter( ( item ) => item.action === 'conflict' );
		/* translators: %s: receiving site address. */
		const card = el( 'div', { class: 'content-relay-card' }, el( 'h2', { text: sprintf( __( 'Plan from %s', 'content-relay' ), plan.receiver ) } ) );

		if ( plan.blockers.length ) {
			card.appendChild( notice( 'error', __( 'The receiving site cannot apply this relay:', 'content-relay' ), list( plan.blockers ) ) );
		}
		if ( state.filesPending ) {
			/* translators: %d: number of files. */
			card.appendChild( notice( 'error', sprintf( _n( '%d file has not reached the receiving site.', '%d files have not reached the receiving site.', state.filesPending, 'content-relay' ), state.filesPending ) ) );
		}
		if ( plan.warnings.length ) {
			card.appendChild( notice( 'warning', __( 'Warnings:', 'content-relay' ), list( plan.warnings ) ) );
		}

		if ( conflicts.length || state.overwrite ) {
			card.appendChild(
				el( 'p', { class: 'content-relay-overwrite' }, el( 'label', {}, [
					el( 'input', { type: 'checkbox', checked: state.overwrite, disabled: state.busy, onchange: ( event ) => refreshPlan( { overwrite: event.target.checked } ) } ),
					' ',
					__( 'Overwrite changes made on the receiving site since the last relay (it keeps a backup)', 'content-relay' ),
				] ) )
			);
		}

		if ( state.objectCache ) {
			card.appendChild(
				el( 'p', {}, el( 'label', {}, [
					el( 'input', { type: 'checkbox', checked: state.flushCache, disabled: state.busy, onchange: ( event ) => setState( { flushCache: event.target.checked } ) } ),
					' ',
					__( 'Flush the entire object cache on the receiving site after applying (it uses a persistent object cache)', 'content-relay' ),
				] ) )
			);
		}

		card.appendChild(
			el( 'p', {}, el( 'label', {}, [
				el( 'input', { type: 'checkbox', checked: state.onlyChanges, onchange: ( event ) => setState( { onlyChanges: event.target.checked } ) } ),
				' ',
				__( 'Show only objects this relay changes or blocks on', 'content-relay' ),
			] ) )
		);

		const visible = plan.items.filter( ( item ) => ! state.onlyChanges || CHANGE_ACTIONS.indexOf( item.action ) !== -1 || item.action === 'conflict' || item.action === 'missing' );
		const rows = [];
		visible.forEach( ( item ) => {
			const open = !! state.open[ item.key ];
			rows.push(
				el( 'tr', {}, [
					el( 'td', {}, el( 'span', { class: 'content-relay-badge is-' + item.action, text: ACTIONS[ item.action ] || item.action } ) ),
					el( 'td', { text: item.label } ),
					el( 'td', { text: item.id === null ? '—' : String( item.id ) } ),
					el( 'td', { text: item.note } ),
					el( 'td', {}, item.diff
						? button( open ? __( 'Hide changes', 'content-relay' ) : __( 'Show changes', 'content-relay' ), () => setState( { open: Object.assign( {}, state.open, { [ item.key ]: ! open } ) } ) )
						: null ),
				] )
			);
			if ( open && item.diff ) {
				rows.push( el( 'tr', { class: 'content-relay-diff-row' }, el( 'td', { colspan: 5 }, renderDiff( item.diff ) ) ) );
			}
		} );
		if ( ! rows.length ) {
			rows.push( el( 'tr', {}, el( 'td', { colspan: 5, text: __( 'No objects change.', 'content-relay' ) } ) ) );
		}
		card.appendChild( table( [ __( 'Action', 'content-relay' ), __( 'Object', 'content-relay' ), __( 'Id there', 'content-relay' ), __( 'Note', 'content-relay' ), '' ], rows ) );

		const buttons = el( 'p', { class: 'content-relay-buttons' } );
		if ( ! plan.blockers.length && ! changes.length ) {
			card.appendChild( notice( 'success', __( 'Nothing to do: the receiving site already matches this content.', 'content-relay' ) ) );
			buttons.appendChild( button( __( 'Close', 'content-relay' ), abort, { primary: true, disabled: state.busy } ) );
		} else {
			buttons.appendChild(
				button(
					/* translators: %d: number of changes. */
					sprintf( _n( 'Relay %d change', 'Relay %d changes', changes.length, 'content-relay' ), changes.length ),
					apply,
					{ primary: true, disabled: state.busy || plan.blockers.length > 0 || state.filesPending > 0 || ! changes.length }
				)
			);
			buttons.appendChild( button( __( 'Refresh plan', 'content-relay' ), () => refreshPlan(), { disabled: state.busy } ) );
			buttons.appendChild( button( __( 'Cancel relay', 'content-relay' ), abort, { disabled: state.busy } ) );
		}
		card.appendChild( buttons );
		return card;
	}

	function renderDiff( diff ) {
		const box = el( 'div', { class: 'content-relay-diff' } );
		if ( diff.missing ) {
			box.appendChild( el( 'p', { text: __( 'The object no longer exists on the receiving site.', 'content-relay' ) } ) );
			return box;
		}
		if ( diff.approximate ) {
			box.appendChild( notice( 'warning', __( 'Some references could not be resolved yet, so values are shown as the sender has them.', 'content-relay' ) ) );
		}

		if ( diff.fields.length ) {
			box.appendChild( el( 'h4', { text: __( 'Fields', 'content-relay' ) } ) );
			box.appendChild(
				table(
					[ __( 'Field', 'content-relay' ), __( 'On the receiving site now', 'content-relay' ), __( 'After the relay', 'content-relay' ) ],
					diff.fields.map( ( field ) => el( 'tr', {}, [ el( 'td', {}, el( 'code', { text: field.field } ) ), el( 'td', { class: 'is-old', text: field.old } ), el( 'td', { class: 'is-new', text: field.new } ) ] ) )
				)
			);
		}

		const meta = diff.meta;
		const metaRows = []
			.concat( meta.added.map( ( entry ) => [ '+', entry.key, '', entry.new ] ) )
			.concat( meta.changed.map( ( entry ) => [ '~', entry.key, entry.old, entry.new ] ) )
			.concat( meta.removed.map( ( entry ) => [ '−', entry.key, entry.old, '' ] ) );
		if ( metaRows.length ) {
			box.appendChild( el( 'h4', { text: __( 'Custom fields and meta', 'content-relay' ) } ) );
			box.appendChild(
				table(
					[ '', __( 'Key', 'content-relay' ), __( 'Now', 'content-relay' ), __( 'After the relay', 'content-relay' ) ],
					metaRows.map( ( row ) => el( 'tr', {}, [ el( 'td', { text: row[ 0 ] } ), el( 'td', {}, el( 'code', { text: row[ 1 ] } ) ), el( 'td', { class: 'is-old', text: row[ 2 ] } ), el( 'td', { class: 'is-new', text: row[ 3 ] } ) ] ) )
				)
			);
			if ( meta.truncated ) {
				box.appendChild( el( 'p', { class: 'description', text: __( 'Only the first 100 keys of each group are listed.', 'content-relay' ) } ) );
			}
		}

		box.appendChild( el( 'h4', { text: __( 'Content', 'content-relay' ) } ) );
		const content = diff.content;
		if ( ! content.changed ) {
			box.appendChild( el( 'p', { text: __( 'Content unchanged.', 'content-relay' ) } ) );
		} else {
			/* translators: 1: lines added, 2: lines removed. */
			box.appendChild( el( 'p', { text: sprintf( __( '%1$d lines added, %2$d lines removed.', 'content-relay' ), content.added, content.removed ) } ) );
			content.hunks.forEach( ( hunk ) => {
				const pre = el( 'pre', { class: 'content-relay-hunk' } );
				pre.appendChild( el( 'span', { class: 'is-header', text: '@@ −' + hunk.old_start + ' +' + hunk.new_start + ' @@\n' } ) );
				hunk.lines.forEach( ( line ) => {
					const kind = line[ 0 ] === '+' ? 'is-add' : line[ 0 ] === '-' ? 'is-del' : 'is-ctx';
					pre.appendChild( el( 'span', { class: kind, text: ( line[ 0 ] === '-' ? '−' : line[ 0 ] ) + ' ' + line[ 1 ] + '\n' } ) );
				} );
				box.appendChild( pre );
			} );
			if ( content.truncated ) {
				box.appendChild( el( 'p', { class: 'description', text: __( 'The diff is shortened: long lines are cut and at most 400 lines are shown.', 'content-relay' ) } ) );
			}
		}
		box.appendChild( el( 'p', { class: 'description', text: __( 'References to objects this relay creates show as id 0 until they exist.', 'content-relay' ) } ) );
		return box;
	}

	function renderResult() {
		const data = state.result;
		const result = data.result || { written: [], problems: [] };
		const card = el( 'div', { class: 'content-relay-card' } );

		if ( state.rollback ) {
			const summary = state.rollback;
			card.appendChild(
				notice(
					'success',
					/* translators: 1: restored objects, 2: removed objects, 3: removed files. */
					sprintf( __( 'Rolled back: %1$d restored, %2$d removed, %3$d files removed.', 'content-relay' ), ( summary.restored || [] ).length, ( summary.deleted || [] ).length, summary.files || 0 )
				)
			);
		} else if ( result.problems && result.problems.length ) {
			card.appendChild( notice( 'warning', __( 'Relay applied, but the receiving site found problems:', 'content-relay' ), list( result.problems ) ) );
		} else {
			/* translators: %s: receiving site address. */
			card.appendChild( notice( 'success', sprintf( __( 'Relay applied on %s.', 'content-relay' ), state.receiver ) ) );
		}

		if ( ! state.rollback && result.warnings && result.warnings.length ) {
			card.appendChild( notice( 'warning', __( 'Warnings from the receiving site:', 'content-relay' ), list( result.warnings ) ) );
		}
		if ( ! state.rollback && result.caches && result.caches.length ) {
			/* translators: %s: comma-separated cache plugin names. */
			card.appendChild( el( 'p', { class: 'description', text: sprintf( __( 'Page caches purged: %s.', 'content-relay' ), result.caches.join( ', ' ) ) } ) );
		}

		card.appendChild(
			table(
				[ __( 'Action', 'content-relay' ), __( 'Object', 'content-relay' ), __( 'Id there', 'content-relay' ), __( 'Address', 'content-relay' ) ],
				( result.written || [] ).map( ( item ) =>
					el( 'tr', {}, [
						el( 'td', {}, el( 'span', { class: 'content-relay-badge is-' + item.action, text: ACTIONS[ item.action ] || item.action } ) ),
						el( 'td', { text: item.key } ),
						el( 'td', { text: String( item.id ) } ),
						el( 'td', {}, item.url ? link( item.url ) : null ),
					] )
				)
			)
		);

		if ( ! state.rollback ) {
			card.appendChild( renderVerification() );
		}

		/* translators: %s: relay session id. */
		card.appendChild( el( 'p', { class: 'description', text: sprintf( __( 'Relay session %s', 'content-relay' ), data.session ) } ) );

		const buttons = el( 'p', { class: 'content-relay-buttons' } );
		if ( ! state.rollback ) {
			buttons.appendChild(
				el( 'label', { class: 'content-relay-overwrite' }, [
					el( 'input', { type: 'checkbox', checked: state.rollbackOverwrite, onchange: ( event ) => setState( { rollbackOverwrite: event.target.checked } ) } ),
					' ',
					__( 'Also discard edits made there after the relay', 'content-relay' ),
				] )
			);
			buttons.appendChild( button( __( 'Roll back', 'content-relay' ), rollback, { disabled: state.busy } ) );
		}
		buttons.appendChild( el( 'a', { class: 'button', href: config.historyUrl, text: __( 'History', 'content-relay' ) } ) );
		buttons.appendChild( el( 'a', { class: 'button', href: config.listUrl, text: __( 'Back to the list', 'content-relay' ) } ) );
		card.appendChild( buttons );
		return card;
	}

	function renderVerification() {
		const box = el( 'div', { class: 'content-relay-verification' }, el( 'h3', { text: __( 'Checks on the receiving site', 'content-relay' ) } ) );
		if ( state.verifying ) {
			box.appendChild( el( 'p', { text: __( 'Fetching the relayed pages from the receiving site…', 'content-relay' ) } ) );
			return box;
		}
		if ( state.verificationError ) {
			box.appendChild( notice( 'error', state.verificationError ) );
		}

		const report = state.verification;
		if ( report ) {
			box.appendChild(
				report.ok
					? notice( 'success', __( 'Every check passed.', 'content-relay' ) )
					: notice( 'error', __( 'Some checks failed. Nothing was rolled back: review the pages, then roll back if needed.', 'content-relay' ) )
			);

			const rows = [];
			report.pages.forEach( ( page ) => {
				if ( page.skipped ) {
					rows.push( el( 'tr', {}, [ el( 'td', {}, link( page.url ) ), el( 'td', { text: '—' } ), el( 'td', { text: page.note } ) ] ) );
					return;
				}
				page.checks.forEach( ( check, index ) => {
					rows.push(
						el( 'tr', {}, [
							el( 'td', {}, index === 0 ? link( page.url ) : null ),
							el( 'td', {}, el( 'span', { class: 'content-relay-badge ' + ( check.ok ? 'is-on' : 'is-off' ), text: check.ok ? __( 'Pass', 'content-relay' ) : __( 'Fail', 'content-relay' ) } ) ),
							el( 'td', { text: check.message } ),
						] )
					);
				} );
			} );
			box.appendChild( table( [ __( 'Page', 'content-relay' ), __( 'Result', 'content-relay' ), __( 'Check', 'content-relay' ) ], rows ) );
		}

		box.appendChild( el( 'p', {}, button( __( 'Check again', 'content-relay' ), verify, { disabled: state.verifying || state.busy } ) ) );
		return box;
	}

	function render() {
		root.textContent = '';
		root.appendChild( renderDiscovery() );
		if ( state.error ) {
			root.appendChild( notice( 'error', state.error ) );
		}
		if ( state.phase === 'choose' ) {
			root.appendChild( renderChoose() );
		} else if ( state.phase === 'preparing' ) {
			root.appendChild( renderProgress() );
		} else if ( state.phase === 'stalled' ) {
			root.appendChild( renderStalled() );
		} else if ( state.phase === 'review' && state.plan ) {
			root.appendChild( renderPlan() );
		} else if ( state.phase === 'applying' ) {
			root.appendChild( el( 'div', { class: 'content-relay-card' }, el( 'p', { text: __( 'Applying on the receiving site: placing media, backing up and committing…', 'content-relay' ) } ) ) );
		} else if ( state.phase === 'done' && state.result ) {
			root.appendChild( renderResult() );
		}
	}

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( state.busy ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	render();
	discover();
}( window.wp, window.contentRelayConfig ) );
