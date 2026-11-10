/**
 * WP DBase — admin app.
 *
 * Plain ES2020, no build step. Every piece of data reaches the DOM through
 * textContent/attributes (never innerHTML), and every request goes through
 * wp.apiFetch, which sends the REST nonce.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const NS = '/' + window.wpdbase.namespace;

	/* ── tiny DOM helper ───────────────────────────────────── */

	/**
	 * Create an element. Children may be nodes, strings (as text) or falsy (skipped).
	 *
	 * @param {string} tag   Tag name, optionally with classes: 'div.a.b'.
	 * @param {Object} attrs Attributes; `on*` keys become listeners; `text` sets textContent.
	 * @param {...*}   kids  Children.
	 * @return {HTMLElement} Element.
	 */
	function h( tag, attrs, ...kids ) {
		const [ name, ...classes ] = tag.split( '.' );
		const el = document.createElement( name );
		if ( classes.length ) {
			el.className = classes.join( ' ' );
		}
		for ( const [ key, value ] of Object.entries( attrs || {} ) ) {
			if ( value === null || value === undefined || value === false ) {
				continue;
			}
			if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
				el.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( key === 'text' ) {
				el.textContent = String( value );
			} else if ( key === 'class' ) {
				el.className += ( el.className ? ' ' : '' ) + value;
			} else if ( key === 'style' && typeof value === 'object' ) {
				Object.assign( el.style, value );
			} else if ( key === 'value' ) {
				el.value = value;
			} else {
				el.setAttribute( key, value === true ? '' : String( value ) );
			}
		}
		for ( const kid of kids.flat( Infinity ) ) {
			if ( kid === null || kid === undefined || kid === false ) {
				continue;
			}
			el.append( kid instanceof Node ? kid : document.createTextNode( String( kid ) ) );
		}
		return el;
	}

	const SVG_NS = 'http://www.w3.org/2000/svg';
	const ICONS = {
		db: 'M3 5c0-1.7 4-3 9-3s9 1.3 9 3-4 3-9 3-9-1.3-9-3Zm0 0v14c0 1.7 4 3 9 3s9-1.3 9-3V5M3 12c0 1.7 4 3 9 3s9-1.3 9-3',
		gauge: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM12 4a8 8 0 1 0 8 8',
		table: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18',
		term: 'm4 17 6-6-6-6M12 19h8',
		audit: 'M9 5h11M9 12h11M9 19h11M4 5h.01M4 12h.01M4 19h.01',
		gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2',
		sun: 'M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19',
		eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
		pencil: 'M4 20h4L19 9l-4-4L4 16v4ZM14 6l4 4',
		alert: 'M10.3 3.9 2.4 18a1.8 1.8 0 0 0 1.6 2.7h16a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.9 1.9 0 0 0-3.4 0ZM12 9v4M12 17h.01',
		chevron: 'm9 6 6 6-6 6',
		back: 'm14 6-6 6 6 6',
		close: 'M18 6 6 18M6 6l12 12',
		link: 'M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1',
		play: 'm6 4 13 8-13 8Z',
		key: 'M15 7a4 4 0 1 1-3.9 5H3v3h3v3h3v-3h2.1A4 4 0 0 1 15 7Z',
		search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM20 20l-4.3-4.3',
	};

	/**
	 * Inline SVG icon (decorative: aria-hidden).
	 *
	 * @param {string} name Icon name.
	 * @param {number} size Pixel size.
	 * @return {SVGElement} Icon.
	 */
	function icon( name, size = 15 ) {
		const svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'width', size );
		svg.setAttribute( 'height', size );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.6' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		const path = document.createElementNS( SVG_NS, 'path' );
		path.setAttribute( 'd', ICONS[ name ] || '' );
		svg.append( path );
		return svg;
	}

	/* ── formatting ────────────────────────────────────────── */

	const nf = new Intl.NumberFormat();

	function bytes( n ) {
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let i = 0;
		let v = Number( n ) || 0;
		while ( v >= 1024 && i < units.length - 1 ) {
			v /= 1024;
			i++;
		}
		return ( i === 0 ? v : v.toFixed( v < 10 ? 1 : 0 ) ) + ' ' + units[ i ];
	}

	function num( n ) {
		return nf.format( Number( n ) || 0 );
	}

	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return window.localStorage.getItem( 'wpdbase:' + key );
			}
			window.localStorage.setItem( 'wpdbase:' + key, value );
		} catch ( e ) {
			// Private mode or blocked storage: preferences simply aren't remembered.
		}
		return null;
	}

	/* ── state ─────────────────────────────────────────────── */

	const MODES = {
		read: {
			label: __( 'Read-only', 'wp-dbase' ),
			icon: 'eye',
			body: __( 'Nothing can change, not even from the SQL console. The safe default.', 'wp-dbase' ),
		},
		assisted: {
			label: __( 'Assisted editing', 'wp-dbase' ),
			icon: 'pencil',
			body: __( 'Edit rows inline with a review of the SQL before saving. Free SQL writes and DDL stay blocked.', 'wp-dbase' ),
		},
		expert: {
			label: __( 'Expert', 'wp-dbase' ),
			icon: 'alert',
			body: __( 'Free SQL and DDL are unlocked. Every write asks you to type the database name and is logged.', 'wp-dbase' ),
		},
	};
	const MODE_ORDER = [ 'read', 'assisted', 'expert' ];

	const state = {
		ctx: null,
		db: null,
		view: store( 'view' ) || 'overview',
		theme: store( 'theme' ) || ( window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light' ),
		tables: [],
		tableFilter: '',
		table: null,
		tab: 'data',
		page: 1,
		perPage: 50,
		orderBy: null,
		desc: false,
		grid: null,
		dirty: {},
		inspector: null,
		trail: [],
		showInternal: false,
		showSql: false,
		console: store( 'console' ) || 'SELECT ID, post_title, post_status, post_date\nFROM wp_posts\nORDER BY ID DESC\nLIMIT 20;',
		consoleResult: null,
		preflight: null,
		lastStatus: '',
	};

	const root = document.getElementById( 'wpdbase-app' );
	let mainEl;
	let statusEl;

	/* ── API ───────────────────────────────────────────────── */

	function api( path, options = {} ) {
		return apiFetch( { path: NS + path, ...options } );
	}

	function dbPath( suffix = '' ) {
		return '/databases/' + encodeURIComponent( state.db ) + suffix;
	}

	function tablePath( suffix = '' ) {
		return dbPath( '/tables/' + encodeURIComponent( state.table ) + suffix );
	}

	/* ── toasts ────────────────────────────────────────────── */

	let toastBox;

	function toast( title, body, isError ) {
		const el = h( 'div.wpdbase-toast', { role: 'status', class: isError ? 'is-error' : null }, h( 'strong', { text: title } ), body ? h( 'small', { text: body } ) : null );
		appendAll( toastBox, el );
		setTimeout( () => el.remove(), isError ? 9000 : 5000 );
	}

	function fail( error ) {
		toast( __( 'Something went wrong', 'wp-dbase' ), ( error && error.message ) || String( error ), true );
	}

	/* ── shell ─────────────────────────────────────────────── */

	function renderShell() {
		root.textContent = '';
		root.dataset.theme = state.theme;

		const mode = state.ctx.mode;
		const nav = [
			[ 'overview', 'gauge', __( 'Overview', 'wp-dbase' ) ],
			[ 'explorer', 'table', __( 'Explorer', 'wp-dbase' ) ],
			[ 'console', 'term', __( 'SQL console', 'wp-dbase' ) ],
			[ 'audit', 'audit', __( 'Audit trail', 'wp-dbase' ) ],
			[ 'settings', 'gear', __( 'Settings', 'wp-dbase' ) ],
		];

		const dbSelect = state.ctx.databases.length > 1
			? h(
				'select.wpdbase-input',
				{
					'aria-label': __( 'Database', 'wp-dbase' ),
					onChange: ( e ) => switchDatabase( e.target.value ),
				},
				state.ctx.databases.map( ( name ) => h( 'option', { value: name, selected: name === state.db, text: name } ) )
			)
			: null;

		mainEl = h( 'div.wpdbase-main' );
		statusEl = h( 'div.wpdbase-status' );
		toastBox = h( 'div.wpdbase-toasts', { 'aria-live': 'polite' } );

		appendAll(
			root,
			h(
				'div.wpdbase-top',
				null,
				h( 'div.wpdbase-brand', null, icon( 'db', 17 ), 'WP DBase' ),
				dbSelect,
				h( 'div.wpdbase-crumb', null, h( 'span', { text: state.db } ), state.table && state.view === 'explorer' ? [ icon( 'chevron', 12 ), h( 'strong', { text: state.table } ) ] : null ),
				h(
					'div.wpdbase-top-actions',
					null,
					h( 'button.wpdbase-btn', { type: 'button', onClick: openPalette, title: __( 'Command palette', 'wp-dbase' ) }, icon( 'search', 13 ), __( 'Go to…', 'wp-dbase' ), h( 'span.wpdbase-faint.mono', { text: 'Ctrl K' } ) ),
					h(
						'button.wpdbase-btn.wpdbase-mode',
						{ type: 'button', class: 'is-' + mode, onClick: openModePicker, title: __( 'Safety mode', 'wp-dbase' ) },
						icon( MODES[ mode ].icon, 13 ),
						MODES[ mode ].label
					),
					h(
						'button.wpdbase-btn',
						{
							type: 'button',
							'aria-label': __( 'Toggle dark theme', 'wp-dbase' ),
							'aria-pressed': state.theme === 'dark' ? 'true' : 'false',
							onClick: () => {
								state.theme = state.theme === 'dark' ? 'light' : 'dark';
								store( 'theme', state.theme );
								root.dataset.theme = state.theme;
							},
						},
						icon( 'sun', 14 )
					)
				)
			),
			mode === 'expert'
				? h(
					'div.wpdbase-band',
					{ role: 'alert' },
					icon( 'alert', 14 ),
					__( 'Expert mode — free SQL and DDL are unlocked. Every write is logged to the audit trail.', 'wp-dbase' ),
					h( 'button.wpdbase-btn', { type: 'button', onClick: () => setMode( 'read' ) }, __( 'Drop to read-only', 'wp-dbase' ) )
				)
				: null,
			h(
				'div.wpdbase-body',
				null,
				h(
					'nav.wpdbase-rail',
					{ 'aria-label': __( 'WP DBase sections', 'wp-dbase' ) },
					nav.map( ( [ id, ic, label ] ) =>
						h( 'button', { type: 'button', title: label, 'aria-label': label, 'aria-current': state.view === id ? 'page' : null, onClick: () => go( id ) }, icon( ic, 17 ) )
					)
				),
				mainEl
			),
			statusEl,
			toastBox
		);

		renderStatus();
		renderView();
	}

	/**
	 * Append children, skipping falsy ones (Element.append would print "null").
	 *
	 * @param {HTMLElement} parent Parent.
	 * @param {...*}        kids   Children.
	 */
	function appendAll( parent, ...kids ) {
		parent.append( ...kids.flat( Infinity ).filter( ( kid ) => kid !== null && kid !== undefined && kid !== false ) );
	}

	/**
	 * Fill the viewport below wherever wp-admin placed the app (notices push it down).
	 */
	function fitHeight() {
		const top = root.getBoundingClientRect().top + window.scrollY;
		root.style.height = Math.max( 420, window.innerHeight - top ) + 'px';
	}

	function renderStatus() {
		statusEl.textContent = '';
		appendAll(
			statusEl,
			h( 'span', null, h( 'span.wpdbase-dot' ), state.db + ' · ' + state.ctx.server ),
			h( 'span', { text: MODES[ state.ctx.mode ].label } ),
			h( 'span', { text: state.lastStatus } ),
			h( 'span.wpdbase-spacer', { text: 'WP DBase ' + state.ctx.version } )
		);
	}

	function setStatus( text ) {
		state.lastStatus = text;
		renderStatus();
	}

	function go( view ) {
		state.view = view;
		store( 'view', view );
		renderShell();
	}

	function renderView() {
		mainEl.textContent = '';
		const views = { overview: viewOverview, explorer: viewExplorer, console: viewConsole, audit: viewAudit, settings: viewSettings };
		( views[ state.view ] || viewOverview )();
	}

	async function switchDatabase( name ) {
		state.db = name;
		state.table = null;
		state.inspector = null;
		state.trail = [];
		state.dirty = {};
		store( 'db', name );
		await loadTables();
		renderShell();
	}

	async function loadTables() {
		const data = await api( dbPath( '/tables' ) );
		state.tables = data.tables;
	}

	/* ── safety mode ───────────────────────────────────────── */

	function modeAllowed( mode ) {
		return MODE_ORDER.indexOf( mode ) <= MODE_ORDER.indexOf( state.ctx.max_mode );
	}

	async function setMode( mode ) {
		try {
			const res = await api( '/safety-mode', { method: 'PUT', data: { mode } } );
			state.ctx.mode = res.mode;
			state.ctx.onboarded = true;
			toast( sprintf( /* translators: %s: safety mode name. */ __( 'Safety mode: %s', 'wp-dbase' ), MODES[ res.mode ].label ) );
			renderShell();
		} catch ( e ) {
			fail( e );
		}
	}

	function modeChoices( onPick ) {
		return MODE_ORDER.map( ( id ) =>
			h(
				'button.wpdbase-mode-choice',
				{ type: 'button', 'aria-pressed': state.ctx.mode === id ? 'true' : 'false', disabled: ! modeAllowed( id ), onClick: () => onPick( id ) },
				h( 'span.wpdbase-mode', { class: 'is-' + id }, icon( MODES[ id ].icon, 16 ) ),
				h(
					'span',
					null,
					h( 'strong', { text: MODES[ id ].label } ),
					modeAllowed( id ) ? null : h( 'span.wpdbase-badge', { style: { marginLeft: '8px' }, text: __( 'disabled on this site', 'wp-dbase' ) } ),
					h( 'div.wpdbase-muted', { text: MODES[ id ].body } )
				)
			)
		);
	}

	function openModePicker() {
		const close = overlay( ( done ) =>
			h(
				'div.wpdbase-dialog',
				{ role: 'dialog', 'aria-modal': 'true', 'aria-label': __( 'Safety mode', 'wp-dbase' ) },
				h( 'div.wpdbase-dialog-h', null, __( 'Safety mode', 'wp-dbase' ) ),
				h( 'div.wpdbase-dialog-b', null, modeChoices( ( id ) => {
					done();
					setMode( id );
				} ) )
			)
		);
		return close;
	}

	function renderOnboarding() {
		const el = h(
			'div.wpdbase-onboard',
			null,
			h(
				'div.wpdbase-onboard-inner',
				null,
				h( 'div.wpdbase-brand', { style: { fontSize: '20px' } }, icon( 'db', 22 ), 'WP DBase', h( 'span.wpdbase-faint.mono', { text: state.ctx.version } ) ),
				h( 'p', { text: __( 'WP DBase reads the database of this site and shows you objects, not raw tables — a post with its metas and terms in one panel.', 'wp-dbase' ) } ),
				h( 'p', { text: __( 'Every screen can show you the SQL it just ran. Nothing is sent anywhere.', 'wp-dbase' ) } ),
				h( 'div.wpdbase-label', { text: __( 'Pick a safety mode — you can change it any time', 'wp-dbase' ) } ),
				modeChoices( ( id ) => {
					el.remove();
					setMode( id );
				} )
			)
		);
		appendAll( root, el );
	}

	/* ── overlays: dialog, confirm, palette ────────────────── */

	function overlay( build ) {
		const previous = document.activeElement;
		const layer = h( 'div.wpdbase-overlay' );
		const close = () => {
			layer.remove();
			document.removeEventListener( 'keydown', onKey );
			if ( previous && previous.focus ) {
				previous.focus();
			}
		};
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				close();
			}
		};
		layer.addEventListener( 'mousedown', ( e ) => {
			if ( e.target === layer ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', onKey );
		appendAll( layer, build( close ) );
		appendAll( root, layer );
		const focusable = layer.querySelector( 'input, button, select, textarea' );
		if ( focusable ) {
			focusable.focus();
		}
		return close;
	}

	/**
	 * Destructive confirmation: the user must type `phrase`.
	 *
	 * @param {Object} opts { title, body, facts: [[k, v]], phrase, cta, sql }.
	 * @return {Promise<string|null>} The typed phrase, or null when cancelled.
	 */
	function confirmTyped( opts ) {
		return new Promise( ( resolve ) => {
			let settled = false;
			const finish = ( value, close ) => {
				if ( ! settled ) {
					settled = true;
					resolve( value );
				}
				close();
			};
			overlay( ( close ) => {
				const input = h( 'input.wpdbase-input', { type: 'text', autocomplete: 'off', spellcheck: 'false', 'aria-label': __( 'Confirmation', 'wp-dbase' ) } );
				const cta = h( 'button.wpdbase-btn.is-danger', { type: 'button', disabled: true, onClick: () => finish( input.value, close ) }, opts.cta );
				input.addEventListener( 'input', () => {
					cta.disabled = input.value !== opts.phrase;
				} );
				input.addEventListener( 'keydown', ( e ) => {
					if ( e.key === 'Enter' && ! cta.disabled ) {
						finish( input.value, close );
					}
				} );
				const cancel = () => finish( null, close );
				return h(
					'div.wpdbase-dialog',
					{ role: 'alertdialog', 'aria-modal': 'true', 'aria-label': opts.title },
					h( 'div.wpdbase-dialog-h', { style: { color: 'var(--wpdbase-danger)' } }, icon( 'alert', 17 ), h( 'span', { text: opts.title } ) ),
					h(
						'div.wpdbase-dialog-b',
						null,
						opts.body ? h( 'div.wpdbase-muted', { text: opts.body } ) : null,
						opts.facts ? h( 'div.wpdbase-rows', null, opts.facts.map( ( [ k, v ] ) => h( 'div', { style: { gridTemplateColumns: '150px 1fr' } }, h( 'span.wpdbase-muted', { text: k } ), h( 'span', { text: v } ) ) ) ) : null,
						opts.sql ? h( 'pre.wpdbase-code', { text: opts.sql } ) : null,
						h( 'label', null, sprintf( /* translators: %s: text to type. */ __( 'Type %s to confirm', 'wp-dbase' ), opts.phrase ) ),
						input
					),
					h( 'div.wpdbase-dialog-f', null, h( 'button.wpdbase-btn', { type: 'button', onClick: cancel }, __( 'Cancel', 'wp-dbase' ) ), cta )
				);
			} );
		} );
	}

	function openPalette() {
		overlay( ( close ) => {
			const input = h( 'input.wpdbase-palette-input', { type: 'text', placeholder: __( 'Go to table, view, or table:id to open an object…', 'wp-dbase' ), 'aria-label': __( 'Command', 'wp-dbase' ) } );
			const list = h( 'div', { role: 'listbox', style: { overflow: 'auto', maxHeight: '340px', padding: '6px 0' } } );
			let items = [];
			let index = 0;

			const views = [
				[ 'overview', __( 'Overview', 'wp-dbase' ) ],
				[ 'explorer', __( 'Explorer', 'wp-dbase' ) ],
				[ 'console', __( 'SQL console', 'wp-dbase' ) ],
				[ 'audit', __( 'Audit trail', 'wp-dbase' ) ],
				[ 'settings', __( 'Settings', 'wp-dbase' ) ],
			];

			const refresh = () => {
				const q = input.value.trim().toLowerCase();
				items = [];
				const objectMatch = q.match( /^([a-z0-9_$-]+)\s*[:#]\s*(.+)$/i );
				if ( objectMatch && state.tables.some( ( t ) => t.name === objectMatch[ 1 ] ) ) {
					items.push( { kind: __( 'object', 'wp-dbase' ), label: objectMatch[ 1 ] + ' #' + objectMatch[ 2 ], run: () => openObject( objectMatch[ 1 ], objectMatch[ 2 ], true ) } );
				}
				views.filter( ( [ , label ] ) => label.toLowerCase().includes( q ) ).forEach( ( [ id, label ] ) => items.push( { kind: __( 'view', 'wp-dbase' ), label, run: () => go( id ) } ) );
				state.tables.filter( ( t ) => t.name.toLowerCase().includes( q ) ).slice( 0, 40 ).forEach( ( t ) =>
					items.push( { kind: __( 'table', 'wp-dbase' ), label: t.name, mono: true, hint: num( t.rows ), run: () => openTable( t.name ) } )
				);
				state.ctx.databases.filter( ( d ) => d !== state.db && d.toLowerCase().includes( q ) ).slice( 0, 20 ).forEach( ( d ) =>
					items.push( { kind: __( 'database', 'wp-dbase' ), label: d, mono: true, run: () => switchDatabase( d ) } )
				);
				index = Math.min( index, Math.max( 0, items.length - 1 ) );
				list.textContent = '';
				items.forEach( ( item, i ) =>
					appendAll(
						list,
						h(
							'button.wpdbase-palette-item',
							{ type: 'button', role: 'option', 'aria-selected': i === index ? 'true' : 'false', onClick: () => {
								close();
								item.run();
							} },
							h( 'span.wpdbase-label', { style: { width: '70px' }, text: item.kind } ),
							h( 'span', { class: item.mono ? 'mono' : null, text: item.label } ),
							item.hint ? h( 'span.wpdbase-faint.mono.wpdbase-spacer', { text: item.hint } ) : null
						)
					)
				);
			};

			input.addEventListener( 'input', () => {
				index = 0;
				refresh();
			} );
			input.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'ArrowDown' ) {
					index = Math.min( items.length - 1, index + 1 );
					refresh();
					e.preventDefault();
				} else if ( e.key === 'ArrowUp' ) {
					index = Math.max( 0, index - 1 );
					refresh();
					e.preventDefault();
				} else if ( e.key === 'Enter' && items[ index ] ) {
					close();
					items[ index ].run();
				}
			} );
			refresh();

			return h(
				'div.wpdbase-dialog',
				{ role: 'dialog', 'aria-modal': 'true', 'aria-label': __( 'Command palette', 'wp-dbase' ) },
				h( 'div.wpdbase-dialog-h', null, icon( 'search', 16 ), input, h( 'span.wpdbase-badge', { text: 'Esc' } ) ),
				list
			);
		} );
	}

	/* ── overview ──────────────────────────────────────────── */

	function viewOverview() {
		const page = h( 'div.wpdbase-page' );
		appendAll( mainEl, page );
		appendAll( page, h( 'p.wpdbase-muted', { text: __( 'Loading…', 'wp-dbase' ) } ) );

		api( dbPath( '/overview' ) ).then( ( o ) => {
			page.textContent = '';
			const groups = o.groups || {};
			const checks = o.checks || {};
			const max = o.largest.length ? o.largest[ 0 ].data_bytes + o.largest[ 0 ].index_bytes : 1;

			const health = [
				[ 'autoload', __( 'Autoloaded options', 'wp-dbase' ), ( c ) => sprintf( /* translators: 1: number of options, 2: size. */ __( '%1$s options, %2$s loaded on every request.', 'wp-dbase' ), num( c.count ), bytes( c.bytes ) ), ( c ) => c.bytes > 800 * 1024 ],
				[ 'expired_transients', __( 'Expired transients', 'wp-dbase' ), ( c ) => sprintf( /* translators: %s: count. */ __( '%s expired transients still stored.', 'wp-dbase' ), num( c.count ) ), ( c ) => c.count > 0 ],
				[ 'revisions', __( 'Revisions', 'wp-dbase' ), ( c ) => sprintf( /* translators: %s: count. */ __( '%s post revisions.', 'wp-dbase' ), num( c.count ) ), ( c ) => c.count > 1000 ],
				[ 'trash', __( 'Trash', 'wp-dbase' ), ( c ) => sprintf( /* translators: %s: count. */ __( '%s items in the trash.', 'wp-dbase' ), num( c.count ) ), ( c ) => c.count > 0 ],
				[ 'orphan_postmeta', __( 'Orphan post meta', 'wp-dbase' ), ( c ) => sprintf( /* translators: %s: count. */ __( '%s meta rows whose post no longer exists.', 'wp-dbase' ), num( c.count ) ), ( c ) => c.count > 0 ],
			].filter( ( [ key ] ) => checks[ key ] );

			appendAll(
				page,
				h(
					'div.wpdbase-page-inner',
					null,
					h(
						'div',
						null,
						h( 'h2.wpdbase-h', { text: __( 'Database health', 'wp-dbase' ) } ),
						h( 'div.wpdbase-muted', { text: __( 'Sizes and row counts are estimates read from information_schema — no COUNT(*) was run on any table.', 'wp-dbase' ) } )
					),
					h(
						'div.wpdbase-grid',
						null,
						statCard( __( 'Total size', 'wp-dbase' ), bytes( o.size_bytes ), o.database ),
						statCard( __( 'Tables', 'wp-dbase' ), num( o.tables ), sprintf( /* translators: 1: core, 2: plugin, 3: custom table counts. */ __( '%1$s core · %2$s plugin · %3$s custom', 'wp-dbase' ), num( groups.core ), num( groups.plugin ), num( ( groups.custom || 0 ) + ( groups.foreign || 0 ) ) ) ),
						statCard( __( 'Engines', 'wp-dbase' ), String( Object.keys( o.engines ).length ), Object.entries( o.engines ).map( ( [ k, v ] ) => k + ' ' + v ).join( ' · ' ), Object.keys( o.engines ).length > 1 ),
						statCard( __( 'Collations', 'wp-dbase' ), String( Object.keys( o.collations ).length ), Object.entries( o.collations ).map( ( [ k, v ] ) => k + ' ' + v ).join( ' · ' ), Object.keys( o.collations ).length > 1 )
					),
					health.length
						? h(
							'div.wpdbase-grid',
							null,
							health.map( ( [ key, title, text, isWarn ] ) => {
								const c = checks[ key ];
								return h(
									'div.wpdbase-card',
									{ class: isWarn( c ) ? 'is-warn' : 'is-ok' },
									h( 'div', { style: { display: 'flex', gap: '8px' } }, h( 'strong', { text: title } ), h( 'span.mono.wpdbase-spacer', { text: num( c.count ) } ) ),
									h( 'div.wpdbase-muted', { text: text( c ) } ),
									h( 'button.wpdbase-btn.is-link', { type: 'button', onClick: () => openInConsole( c.sql ) }, __( 'Open the query in the console →', 'wp-dbase' ) )
								);
							} )
						)
						: null,
					h(
						'div.wpdbase-panel',
						null,
						h( 'div.wpdbase-panel-h', { text: __( 'Largest tables', 'wp-dbase' ) } ),
						o.largest.map( ( t ) => {
							const size = t.data_bytes + t.index_bytes;
							const group = ( state.tables.find( ( x ) => x.name === t.name ) || {} ).group;
							return h(
								'div.wpdbase-bar-row',
								null,
								h( 'button.wpdbase-btn.is-link', { type: 'button', onClick: () => openTable( t.name ), text: t.name } ),
								h( 'div.wpdbase-bar', { class: group === 'core' ? 'is-core' : null }, h( 'span', { style: { width: Math.max( 1, ( size / max ) * 100 ) + '%' } } ) ),
								h( 'span.mono', { style: { width: '80px', textAlign: 'right' }, text: bytes( size ) } ),
								h( 'span.mono.wpdbase-faint', { style: { width: '100px', textAlign: 'right' }, text: '~' + num( t.rows ) } )
							);
						} )
					)
				)
			);
		} ).catch( fail );
	}

	function statCard( label, value, note, warn ) {
		return h(
			'div.wpdbase-card',
			{ class: warn ? 'is-warn' : null },
			h( 'div.wpdbase-label', { text: label } ),
			h( 'div.wpdbase-stat', { text: value } ),
			h( 'div.wpdbase-muted', { style: { fontSize: '12px', overflowWrap: 'anywhere' }, text: note } )
		);
	}

	/* ── explorer ──────────────────────────────────────────── */

	function openTable( name ) {
		state.table = name;
		state.tab = 'data';
		state.page = 1;
		state.orderBy = null;
		state.desc = false;
		state.dirty = {};
		state.inspector = null;
		state.trail = [];
		go( 'explorer' );
	}

	function viewExplorer() {
		const GROUPS = [
			[ 'core', __( 'WordPress core', 'wp-dbase' ) ],
			[ 'plugin', __( 'Plugins', 'wp-dbase' ) ],
			[ 'custom', __( 'Custom tables', 'wp-dbase' ) ],
			[ 'foreign', __( 'Other prefixes', 'wp-dbase' ) ],
		];

		const listEl = h( 'div.wpdbase-side-list' );
		const drawList = () => {
			listEl.textContent = '';
			const q = state.tableFilter.toLowerCase();
			for ( const [ id, label ] of GROUPS ) {
				const items = state.tables.filter( ( t ) => t.group === id && t.name.toLowerCase().includes( q ) );
				if ( ! items.length ) {
					continue;
				}
				appendAll(
					listEl,
					h( 'div.wpdbase-group-h.wpdbase-label', null, h( 'span.wpdbase-swatch', { class: 'is-' + id } ), label, h( 'span.mono.wpdbase-spacer', { text: items.length } ) ),
					items.map( ( t ) =>
						h(
							'button.wpdbase-table-item',
							{ type: 'button', 'aria-current': t.name === state.table ? 'true' : null, title: t.plugin || ( t.meta ? sprintf( /* translators: %s: meta table name. */ __( 'Object View with %s', 'wp-dbase' ), t.meta ) : t.name ), onClick: () => openTable( t.name ) },
							h( 'span.name', { text: t.name } ),
							h( 'span.num', { text: '~' + num( t.rows ) } ),
							h( 'span.num', { style: { width: '54px', textAlign: 'right' }, text: bytes( t.data_bytes + t.index_bytes ) } )
						)
					)
				);
			}
		};

		const filter = h( 'input.wpdbase-input', {
			type: 'search',
			placeholder: __( 'Filter tables', 'wp-dbase' ),
			'aria-label': __( 'Filter tables', 'wp-dbase' ),
			value: state.tableFilter,
			onInput: ( e ) => {
				state.tableFilter = e.target.value;
				drawList();
			},
		} );

		drawList();

		const side = h(
			'aside.wpdbase-side',
			null,
			h( 'div.wpdbase-side-top', null, filter, h( 'div.wpdbase-faint', { style: { fontSize: '12px' } }, h( 'span.mono', { text: state.db } ), ' · ', sprintf( /* translators: %s: number of tables. */ _n( '%s table', '%s tables', state.tables.length, 'wp-dbase' ), num( state.tables.length ) ) ) ),
			listEl,
			h( 'div.wpdbase-side-foot', null, h( 'span.wpdbase-swatch.is-core', { style: { display: 'inline-block', marginRight: '5px' } } ), __( 'WordPress core — edits here can break the site.', 'wp-dbase' ) )
		);

		const center = h( 'section.wpdbase-center' );
		appendAll( mainEl, side, center );

		if ( ! state.table ) {
			appendAll( center, h( 'div.wpdbase-empty', null, __( 'Pick a table on the left, or press Ctrl K.', 'wp-dbase' ) ) );
			return;
		}

		const tabs = [
			[ 'data', __( 'Data', 'wp-dbase' ) ],
			[ 'structure', __( 'Structure', 'wp-dbase' ) ],
			[ 'indexes', __( 'Indexes', 'wp-dbase' ) ],
		];
		const info = state.tables.find( ( t ) => t.name === state.table ) || {};

		appendAll(
			center,
			h(
				'div.wpdbase-tabs',
				{ role: 'tablist' },
				tabs.map( ( [ id, label ] ) =>
					h( 'button.wpdbase-tab', { type: 'button', role: 'tab', 'aria-selected': state.tab === id ? 'true' : 'false', onClick: () => {
						state.tab = id;
						renderView();
					} }, label )
				),
				h( 'span.mono.wpdbase-faint.wpdbase-spacer', { style: { fontSize: '11.5px' }, text: [ info.engine, info.collation, '~' + num( info.rows ) + ' ' + __( 'rows', 'wp-dbase' ) ].filter( Boolean ).join( ' · ' ) } ),
				h( 'button.wpdbase-btn', { type: 'button', style: { marginLeft: '8px' }, onClick: () => openInConsole( 'SELECT *\nFROM `' + state.table + '`\nLIMIT 50;' ) }, icon( 'term', 13 ), __( 'Query', 'wp-dbase' ) )
			)
		);

		const body = h( 'div', { style: { flex: '1', display: 'flex', flexDirection: 'column', minHeight: '0' } } );
		appendAll( center, body );

		if ( state.tab === 'data' ) {
			tabData( body );
		} else {
			tabStructure( body, state.tab );
		}

		if ( state.inspector ) {
			appendAll( mainEl, renderInspector() );
		}
	}

	async function tabData( body ) {
		appendAll( body, h( 'div.wpdbase-empty', { text: __( 'Loading…', 'wp-dbase' ) } ) );
		let page;
		try {
			const qs = new URLSearchParams( { page: state.page, per_page: state.perPage, order: state.desc ? 'desc' : 'asc' } );
			if ( state.orderBy ) {
				qs.set( 'orderby', state.orderBy );
			}
			const started = performance.now();
			page = await api( tablePath( '/rows?' + qs ) );
			state.grid = page;
			setStatus( sprintf( /* translators: 1: rows, 2: milliseconds. */ __( '%1$s rows · %2$s ms', 'wp-dbase' ), num( page.rows.length ), Math.round( performance.now() - started ) ) );
		} catch ( e ) {
			body.textContent = '';
			appendAll( body, h( 'div.wpdbase-error', { text: e.message } ) );
			return;
		}

		body.textContent = '';
		const pk = page.primary_key.length === 1 ? page.primary_key[ 0 ] : null;
		const canEdit = pk && state.ctx.mode !== 'read';
		const truncated = new Set( page.truncated.map( ( [ r, c ] ) => r + ':' + c ) );
		const first = ( page.page - 1 ) * page.per_page + 1;

		appendAll(
			body,
			h(
				'div.wpdbase-toolbar',
				null,
				h( 'span.wpdbase-faint', { style: { fontSize: '12px' }, text: canEdit ? __( 'Double-click a cell to edit it.', 'wp-dbase' ) : ( pk ? __( 'Read-only mode: switch to Assisted to edit cells.', 'wp-dbase' ) : __( 'No single-column primary key: rows cannot be edited or opened.', 'wp-dbase' ) ) } ),
				h(
					'span.wpdbase-spacer.mono.wpdbase-muted',
					{ style: { fontSize: '11.5px' } },
					page.rows.length ? sprintf( /* translators: 1: first row, 2: last row, 3: estimated total. */ __( '%1$s–%2$s of ~%3$s', 'wp-dbase' ), num( first ), num( first + page.rows.length - 1 ), num( page.estimate ) ) : ''
				),
				h( 'button.wpdbase-btn', { type: 'button', 'aria-label': __( 'Previous page', 'wp-dbase' ), disabled: page.page <= 1, onClick: () => {
					state.page--;
					renderView();
				} }, '‹' ),
				h( 'button.wpdbase-btn', { type: 'button', 'aria-label': __( 'Next page', 'wp-dbase' ), disabled: page.rows.length < page.per_page, onClick: () => {
					state.page++;
					renderView();
				} }, '›' )
			)
		);

		if ( ! page.rows.length ) {
			appendAll( body, h( 'div.wpdbase-scroll', null, h( 'div.wpdbase-empty', { text: __( 'This table is empty.', 'wp-dbase' ) } ) ) );
			return;
		}

		const sortBy = ( col ) => {
			if ( state.orderBy === col ) {
				state.desc = ! state.desc;
			} else {
				state.orderBy = col;
				state.desc = false;
			}
			state.page = 1;
			renderView();
		};

		const table = h(
			'table.wpdbase-table',
			null,
			h(
				'thead',
				null,
				h(
					'tr',
					null,
					h( 'th', { style: { width: '30px' } }, h( 'span.wpdbase-sr', { text: __( 'Open', 'wp-dbase' ) } ) ),
					page.columns.map( ( col ) =>
						h(
							'th',
							{ 'aria-sort': state.orderBy === col ? ( state.desc ? 'descending' : 'ascending' ) : null },
							h( 'button', { type: 'button', onClick: () => sortBy( col ) }, page.primary_key.includes( col ) ? h( 'span.is-pk', { title: __( 'Primary key', 'wp-dbase' ) }, icon( 'key', 12 ) ) : null, col, state.orderBy === col ? ( state.desc ? ' ↓' : ' ↑' ) : '' )
						)
					)
				)
			),
			h(
				'tbody',
				null,
				page.rows.map( ( row, r ) => {
					const id = pk ? row[ pk ] : null;
					return h(
						'tr',
						null,
						h( 'td.is-open', null, pk ? h( 'button', { type: 'button', title: __( 'Open object', 'wp-dbase' ), 'aria-label': sprintf( /* translators: %s: row id. */ __( 'Open row %s', 'wp-dbase' ), id ), onClick: () => openObject( state.table, id, true ) }, icon( 'chevron', 13 ) ) : null ),
						page.columns.map( ( col ) => {
							const dirtyKey = id + '\u0000' + col;
							const isDirty = Object.prototype.hasOwnProperty.call( state.dirty, dirtyKey );
							const value = isDirty ? state.dirty[ dirtyKey ].value : row[ col ];
							const cell = h( 'td', {
								class: [ col === pk ? 'is-pk' : '', value === null ? 'is-null' : '', isDirty ? 'is-dirty' : '' ].join( ' ' ).trim() || null,
								title: truncated.has( r + ':' + col ) ? __( 'Truncated — open the object to see the full value.', 'wp-dbase' ) : null,
								text: value === null ? 'NULL' : value + ( truncated.has( r + ':' + col ) ? '…' : '' ),
								tabindex: canEdit && col !== pk ? '0' : null,
							} );
							if ( canEdit && col !== pk && ! truncated.has( r + ':' + col ) ) {
								const edit = () => editCell( cell, id, col, row[ col ], value );
								cell.addEventListener( 'dblclick', edit );
								cell.addEventListener( 'keydown', ( e ) => {
									if ( e.key === 'Enter' ) {
										edit();
									}
								} );
							}
							return cell;
						} )
					);
				} )
			)
		);

		appendAll( body, h( 'div.wpdbase-scroll', null, table ) );

		const changes = Object.keys( state.dirty ).length;
		if ( changes ) {
			appendAll(
				body,
				h(
					'div.wpdbase-pending',
					{ role: 'region', 'aria-label': __( 'Pending changes', 'wp-dbase' ) },
					h( 'strong', { text: sprintf( /* translators: %s: number of changes. */ _n( '%s pending change', '%s pending changes', changes, 'wp-dbase' ), num( changes ) ) } ),
					h( 'div.wpdbase-spacer', { style: { display: 'flex', gap: '8px' } },
						h( 'button.wpdbase-btn', { type: 'button', onClick: () => {
							state.dirty = {};
							renderView();
						} }, __( 'Discard', 'wp-dbase' ) ),
						h( 'button.wpdbase-btn.is-primary', { type: 'button', onClick: reviewChanges }, __( 'Review & save', 'wp-dbase' ) )
					)
				)
			);
		}
	}

	function editCell( cell, id, col, original, current ) {
		const input = h( 'input', { type: 'text', value: current === null ? '' : current, 'aria-label': col } );
		cell.textContent = '';
		appendAll( cell, input );
		input.focus();
		input.select();
		let done = false;
		const commit = ( save ) => {
			if ( done ) {
				return;
			}
			done = true;
			const key = id + '\u0000' + col;
			if ( save ) {
				if ( input.value === ( original === null ? '' : original ) && original !== null ) {
					delete state.dirty[ key ];
				} else {
					state.dirty[ key ] = { id, col, value: input.value };
				}
			}
			renderView();
		};
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				commit( true );
			} else if ( e.key === 'Escape' ) {
				e.stopPropagation();
				commit( false );
			}
		} );
		input.addEventListener( 'blur', () => commit( true ) );
	}

	function groupedChanges() {
		const byRow = {};
		for ( const { id, col, value } of Object.values( state.dirty ) ) {
			byRow[ id ] = byRow[ id ] || {};
			byRow[ id ][ col ] = value;
		}
		return byRow;
	}

	async function reviewChanges() {
		const byRow = groupedChanges();
		let statements = [];
		try {
			for ( const [ id, changes ] of Object.entries( byRow ) ) {
				const res = await api( tablePath( '/rows/' + encodeURIComponent( id ) ), { method: 'PATCH', data: { changes, dry_run: true } } );
				statements.push( res.sql );
			}
		} catch ( e ) {
			fail( e );
			return;
		}

		const isCore = ( state.tables.find( ( t ) => t.name === state.table ) || {} ).group === 'core';
		const typed = await confirmTyped( {
			title: __( 'Save changes', 'wp-dbase' ),
			body: isCore ? __( 'This is a WordPress core table — a wrong value can break the site.', 'wp-dbase' ) : __( 'These statements will run now and be recorded in the audit trail.', 'wp-dbase' ),
			facts: [ [ __( 'Table', 'wp-dbase' ), state.table ], [ __( 'Rows', 'wp-dbase' ), String( statements.length ) ] ],
			sql: statements.join( ';\n' ) + ';',
			phrase: state.table,
			cta: __( 'Save', 'wp-dbase' ),
		} );
		if ( typed === null ) {
			return;
		}

		let affected = 0;
		try {
			for ( const [ id, changes ] of Object.entries( byRow ) ) {
				const res = await api( tablePath( '/rows/' + encodeURIComponent( id ) ), { method: 'PATCH', data: { changes } } );
				affected += res.affected;
			}
			toast( __( 'Saved', 'wp-dbase' ), sprintf( /* translators: %s: affected rows. */ __( '%s rows affected · logged to the audit trail', 'wp-dbase' ), num( affected ) ) );
			state.dirty = {};
		} catch ( e ) {
			fail( e );
		}
		renderView();
	}

	async function tabStructure( body, tab ) {
		appendAll( body, h( 'div.wpdbase-empty', { text: __( 'Loading…', 'wp-dbase' ) } ) );
		let s;
		try {
			s = await api( tablePath( '/structure' ) );
		} catch ( e ) {
			body.textContent = '';
			appendAll( body, h( 'div.wpdbase-error', { text: e.message } ) );
			return;
		}
		body.textContent = '';
		const scroll = h( 'div.wpdbase-scroll', { style: { padding: '16px 18px', background: 'var(--wpdbase-bg)' } } );
		appendAll( body, scroll );

		if ( tab === 'structure' ) {
			const cols = '1.6fr 1.4fr 70px 1fr 70px 1fr';
			appendAll(
				scroll,
				h(
					'div.wpdbase-panel',
					null,
					h( 'div.wpdbase-panel-h', null, __( 'Columns', 'wp-dbase' ), h( 'span.mono.wpdbase-faint', { text: s.table } ) ),
					h(
						'div.wpdbase-rows',
						null,
						h( 'div.is-head', { style: { gridTemplateColumns: cols } }, [ __( 'Column', 'wp-dbase' ), __( 'Type', 'wp-dbase' ), __( 'Null', 'wp-dbase' ), __( 'Default', 'wp-dbase' ), __( 'Key', 'wp-dbase' ), __( 'Extra', 'wp-dbase' ) ].map( ( t ) => h( 'span', { text: t } ) ) ),
						s.columns.map( ( c ) =>
							h(
								'div',
								{ style: { gridTemplateColumns: cols } },
								h( 'span', { style: { fontWeight: 600 }, text: c.name } ),
								h( 'span.wpdbase-muted', { text: c.type } ),
								h( 'span.wpdbase-faint', { text: c.nullable ? 'YES' : 'NO' } ),
								h( 'span.wpdbase-faint', { text: c.default === null ? 'NULL' : c.default } ),
								h( 'span', { text: c.key } ),
								h( 'span.wpdbase-faint', { text: c.extra } )
							)
						)
					)
				),
				h(
					'div.wpdbase-panel',
					{ style: { marginTop: '16px' } },
					h( 'div.wpdbase-panel-h', { text: 'SHOW CREATE TABLE' } ),
					h( 'pre.wpdbase-code', { style: { borderRadius: '0' }, text: s.create } )
				)
			);
			return;
		}

		const cols = '1.3fr 1.6fr 80px 110px 1.4fr';
		const NOTES = {
			ok: __( 'OK', 'wp-dbase' ),
			duplicate: __( 'Duplicate of %s — can be dropped', 'wp-dbase' ),
			redundant: __( 'Redundant: %s covers it', 'wp-dbase' ),
		};
		appendAll(
			scroll,
			h(
				'div.wpdbase-panel',
				null,
				h( 'div.wpdbase-panel-h', { text: __( 'Indexes', 'wp-dbase' ) } ),
				h(
					'div.wpdbase-rows',
					null,
					h( 'div.is-head', { style: { gridTemplateColumns: cols } }, [ __( 'Name', 'wp-dbase' ), __( 'Columns', 'wp-dbase' ), __( 'Unique', 'wp-dbase' ), __( 'Cardinality', 'wp-dbase' ), __( 'Assessment', 'wp-dbase' ) ].map( ( t ) => h( 'span', { text: t } ) ) ),
					s.indexes.map( ( i ) =>
						h(
							'div',
							{ style: { gridTemplateColumns: cols } },
							h( 'span', { style: { fontWeight: 600 }, text: i.name } ),
							h( 'span.wpdbase-muted', { text: i.columns.join( ', ' ) } ),
							h( 'span.wpdbase-faint', { text: i.unique ? 'YES' : 'NO' } ),
							h( 'span.wpdbase-faint', { text: i.cardinality === null ? '—' : num( i.cardinality ) } ),
							h( 'span', { style: { color: i.assessment === 'ok' ? 'var(--wpdbase-ok)' : 'var(--wpdbase-warn)', fontFamily: 'var(--wpdbase-sans)' }, text: sprintf( NOTES[ i.assessment ], i.covered_by || '' ) } )
						)
					)
				)
			)
		);
	}

	/* ── object view ───────────────────────────────────────── */

	async function openObject( table, id, fresh ) {
		try {
			if ( fresh ) {
				state.trail = [];
			} else if ( state.inspector ) {
				state.trail.push( { table: state.inspector.table, id: state.inspector.id } );
			}
			const prevTable = state.table;
			state.inspector = await api( dbPath( '/tables/' + encodeURIComponent( table ) + '/objects/' + encodeURIComponent( id ) ) );
			state.showSql = false;
			if ( state.view !== 'explorer' || ! prevTable ) {
				state.table = table;
				go( 'explorer' );
			} else {
				renderView();
			}
		} catch ( e ) {
			fail( e );
		}
	}

	function back() {
		const prev = state.trail.pop();
		if ( prev ) {
			const keep = state.trail.slice();
			openObject( prev.table, prev.id, true ).then( () => {
				state.trail = keep;
				renderView();
			} );
		}
	}

	function valueEl( m ) {
		if ( m.tree && m.tree.length ) {
			return h(
				'div.wpdbase-tree',
				null,
				m.tree.map( ( n ) =>
					h( 'div', { style: { paddingLeft: 8 + n.depth * 16 + 'px' } }, h( 'span.k', { text: n.key } ), h( 'span.wpdbase-faint', { text: ':' } ), h( 'span', { text: n.value } ), h( 'span.t', { text: n.type } ) )
				)
			);
		}
		return h( 'div.wpdbase-value', { class: m.value === null ? 'wpdbase-faint' : null, text: m.value === null ? 'NULL' : m.value } );
	}

	const TYPE_CLASS = { serialized: 'is-pri', json: 'is-pri', int: 'is-ok', datetime: 'is-warn', date: 'is-warn', url: 'is-pri', html: 'is-danger' };

	function metaEl( m ) {
		return h(
			'div.wpdbase-meta',
			null,
			h( 'div.wpdbase-meta-h', null, h( 'span', { text: m.key } ), h( 'span.wpdbase-badge', { class: TYPE_CLASS[ m.type ] || null, text: m.type } ) ),
			valueEl( m ),
			m.ref ? h( 'button.wpdbase-ref', { type: 'button', onClick: () => openObject( m.ref.table, m.ref.id, false ) }, icon( 'link', 12 ), m.ref.table + ' #' + m.ref.id ) : null,
			m.tree && m.tree.length ? h( 'details', null, h( 'summary.wpdbase-faint', { text: __( 'Raw value', 'wp-dbase' ) } ), h( 'div.wpdbase-value', { text: m.value } ) ) : null
		);
	}

	function renderInspector() {
		const o = state.inspector;
		const canDelete = state.ctx.mode !== 'read';

		const blocks = o.blocks.map( ( b ) => {
			let content;
			if ( b.type === 'roles' ) {
				content = h( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: '5px' } }, b.items.length ? b.items.map( ( r ) => h( 'span.wpdbase-badge.wpdbase-chip.is-pri', { text: r } ) ) : h( 'span.wpdbase-faint', { text: __( 'No role', 'wp-dbase' ) } ) );
			} else if ( b.type === 'sessions' ) {
				content = b.items.length
					? b.items.map( ( s ) => h( 'div.wpdbase-value', { style: { display: 'flex', gap: '10px' } }, h( 'strong', { text: s.ip } ), h( 'span.wpdbase-muted', { text: new Date( s.login * 1000 ).toLocaleString() } ), h( 'span.wpdbase-faint.wpdbase-spacer', { text: '→ ' + new Date( s.expiration * 1000 ).toLocaleString() } ) ) )
					: h( 'span.wpdbase-faint', { text: __( 'No active sessions', 'wp-dbase' ) } );
			} else {
				content = h( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: '5px' } }, b.items.length ? b.items.map( ( t ) => h( 'button.wpdbase-badge.wpdbase-chip', { type: 'button', title: t.taxonomy, onClick: () => openObject( state.ctx.prefix + 'terms', t.term_id, false ) }, t.taxonomy + ': ' + t.name ) ) : h( 'span.wpdbase-faint', { text: __( 'No terms', 'wp-dbase' ) } ) );
			}
			const labels = { roles: __( 'Roles', 'wp-dbase' ), sessions: __( 'Sessions', 'wp-dbase' ), terms: __( 'Terms', 'wp-dbase' ) };
			return h(
				'div',
				{ style: { padding: '12px 13px', borderBottom: '1px solid var(--wpdbase-border2)', display: 'flex', flexDirection: 'column', gap: '8px' } },
				h( 'div', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, h( 'span.wpdbase-label', { text: labels[ b.type ] } ), h( 'span.mono.wpdbase-faint', { style: { fontSize: '11px' }, text: b.source } ), h( 'span.wpdbase-badge.is-pri.wpdbase-spacer', { text: __( 'deserialized', 'wp-dbase' ) } ) ),
				content
			);
		} );

		return h(
			'aside.wpdbase-inspector',
			{ 'aria-label': __( 'Object View', 'wp-dbase' ) },
			h(
				'div.wpdbase-insp-head',
				null,
				h(
					'div',
					{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
					state.trail.length ? h( 'button.wpdbase-btn.is-link', { type: 'button', onClick: back }, icon( 'back', 13 ), state.trail[ state.trail.length - 1 ].table + ' #' + state.trail[ state.trail.length - 1 ].id ) : null,
					h( 'span.wpdbase-label', { text: o.kind } ),
					h( 'span.mono.wpdbase-faint', { style: { fontSize: '11.5px' }, text: o.table } ),
					h( 'button.wpdbase-btn.is-link.wpdbase-spacer', { type: 'button', 'aria-label': __( 'Close', 'wp-dbase' ), onClick: () => {
						state.inspector = null;
						state.trail = [];
						renderView();
					} }, icon( 'close', 15 ) )
				),
				h( 'div.wpdbase-insp-title', null, h( 'span', { text: o.title } ), h( 'span.mono.wpdbase-faint', { style: { fontSize: '12px' }, text: '#' + o.id } ) ),
				h(
					'div',
					{ style: { display: 'flex', gap: '6px', flexWrap: 'wrap' } },
					o.meta ? h( 'span.wpdbase-badge.is-pri', { text: '+ ' + o.meta.meta } ) : null,
					h( 'span.wpdbase-badge', { text: sprintf( /* translators: %s: number of fields. */ __( '%s fields', 'wp-dbase' ), o.fields.length ) } ),
					canDelete ? h( 'button.wpdbase-btn.is-ghost-danger.wpdbase-spacer', { type: 'button', onClick: () => deleteObject( o ) }, __( 'Delete row…', 'wp-dbase' ) ) : null
				)
			),
			h(
				'div.wpdbase-insp-body',
				null,
				h( 'div.wpdbase-section-h', null, h( 'span.wpdbase-label', { text: __( 'Table columns', 'wp-dbase' ) } ), h( 'span.mono.wpdbase-faint', { style: { fontSize: '11px' }, text: o.table } ) ),
				o.fields.map( ( f ) =>
					h(
						'div.wpdbase-kv',
						null,
						h( 'span', { text: f.key } ),
						f.ref
							? h( 'span', null, h( 'button.wpdbase-ref', { type: 'button', onClick: () => openObject( f.ref.table, f.ref.id, false ) }, icon( 'link', 12 ), f.ref.table + ' #' + f.ref.id ) )
							: ( f.tree && f.tree.length ? valueEl( f ) : h( 'span', { class: f.value === null ? 'wpdbase-faint' : null, text: f.value === null ? 'NULL' : f.value } ) )
					)
				),
				blocks,
				o.meta
					? [
						h( 'div.wpdbase-section-h', null, h( 'span.wpdbase-label', { text: __( 'Meta fields', 'wp-dbase' ) } ), h( 'span.mono.wpdbase-faint', { style: { fontSize: '11px' }, text: o.meta.meta } ), h( 'span.mono.wpdbase-faint.wpdbase-spacer', { style: { fontSize: '11px' }, text: o.metas.length } ) ),
						o.metas.length ? o.metas.map( metaEl ) : h( 'div.wpdbase-faint', { style: { padding: '0 13px 8px' }, text: __( 'No public meta.', 'wp-dbase' ) } ),
						o.internal.length
							? [
								h( 'button.wpdbase-collapse', { type: 'button', 'aria-expanded': state.showInternal ? 'true' : 'false', onClick: () => {
									state.showInternal = ! state.showInternal;
									renderView();
								} }, icon( state.showInternal ? 'chevron' : 'chevron', 13 ), __( 'Internal metas', 'wp-dbase' ), h( 'span.mono.wpdbase-faint', { text: o.internal.length } ) ),
								state.showInternal ? o.internal.map( metaEl ) : null,
							]
							: null,
					]
					: null,
				o.related.length
					? h(
						'div',
						{ style: { padding: '12px 13px', display: 'flex', flexDirection: 'column', gap: '7px' } },
						h( 'span.wpdbase-label', { text: __( 'Related', 'wp-dbase' ) } ),
						o.related.map( ( r ) =>
							h(
								'button.wpdbase-related',
								{ type: 'button', onClick: () => openInConsole( r.sql + ';' ) },
								h( 'span.mono.wpdbase-faint', { style: { fontSize: '11.5px', width: '200px', overflow: 'hidden', textOverflow: 'ellipsis' }, text: r.label } ),
								h( 'strong', { style: { color: 'var(--wpdbase-pri)' }, text: r.where } ),
								h( 'span.mono.wpdbase-faint.wpdbase-spacer', { text: num( r.count ) } )
							)
						)
					)
					: null
			),
			h(
				'div.wpdbase-insp-foot',
				null,
				h( 'button.wpdbase-btn.is-link', { type: 'button', 'aria-expanded': state.showSql ? 'true' : 'false', onClick: () => {
					state.showSql = ! state.showSql;
					renderView();
				} }, icon( 'term', 14 ), __( 'View the SQL behind this panel', 'wp-dbase' ) ),
				h( 'span.mono.wpdbase-faint.wpdbase-spacer', { style: { fontSize: '11px' }, text: sprintf( /* translators: 1: queries, 2: ms. */ __( '%1$s queries · %2$s ms', 'wp-dbase' ), o.sql.length, o.timing_ms ) } )
			),
			state.showSql ? h( 'pre.wpdbase-code', { style: { borderRadius: '0', maxHeight: '180px', flex: 'none' }, text: o.sql.join( ';\n\n' ) + ';' } ) : null
		);
	}

	async function deleteObject( o ) {
		let dry;
		try {
			dry = await api( dbPath( '/tables/' + encodeURIComponent( o.table ) + '/rows/' + encodeURIComponent( o.id ) ), { method: 'DELETE', data: { dry_run: true } } );
		} catch ( e ) {
			fail( e );
			return;
		}
		const typed = await confirmTyped( {
			title: __( 'Delete this row?', 'wp-dbase' ),
			body: o.meta ? sprintf( /* translators: %s: meta table. */ __( 'Only the row is deleted. Its meta in %s stays (and becomes orphaned).', 'wp-dbase' ), o.meta.meta ) : null,
			facts: [ [ __( 'Table', 'wp-dbase' ), o.table ], [ __( 'Row', 'wp-dbase' ), '#' + o.id + ' — ' + o.title ] ],
			sql: dry.sql + ';',
			phrase: o.table,
			cta: __( 'Delete row', 'wp-dbase' ),
		} );
		if ( typed === null ) {
			return;
		}
		try {
			await api( dbPath( '/tables/' + encodeURIComponent( o.table ) + '/rows/' + encodeURIComponent( o.id ) ), { method: 'DELETE', data: { confirm: typed } } );
			toast( __( 'Row deleted', 'wp-dbase' ), __( 'Logged to the audit trail.', 'wp-dbase' ) );
			state.inspector = null;
			renderView();
		} catch ( e ) {
			fail( e );
		}
	}

	/* ── console ───────────────────────────────────────────── */

	function openInConsole( sql ) {
		state.console = sql;
		state.consoleResult = null;
		state.preflight = null;
		store( 'console', sql );
		go( 'console' );
	}

	function viewConsole() {
		const wrap = h( 'section.wpdbase-center' );
		appendAll( mainEl, wrap );

		const editor = h( 'textarea.wpdbase-sql', {
			spellcheck: 'false',
			'aria-label': __( 'SQL', 'wp-dbase' ),
			value: state.console,
			onInput: ( e ) => {
				state.console = e.target.value;
				store( 'console', state.console );
			},
		} );

		const selected = () => {
			const s = editor.value.substring( editor.selectionStart, editor.selectionEnd );
			return s.trim() ? s : editor.value;
		};

		editor.addEventListener( 'keydown', ( e ) => {
			if ( ( e.ctrlKey || e.metaKey ) && ( e.key === 'Enter' || e.key === 's' ) ) {
				e.preventDefault();
				run( e.key === 's' ? editor.value : selected() );
			}
		} );

		const resultEl = h( 'div.wpdbase-scroll' );
		const preflightEl = h( 'div' );

		appendAll(
			wrap,
			editor,
			h(
				'div.wpdbase-toolbar',
				null,
				h( 'button.wpdbase-btn.is-primary', { type: 'button', onClick: () => run( selected() ) }, icon( 'play', 12 ), __( 'Run', 'wp-dbase' ) ),
				h( 'span.mono.wpdbase-faint', { style: { fontSize: '11.5px' }, text: __( 'Ctrl+Enter runs the selection (or everything) · Ctrl+S runs everything', 'wp-dbase' ) } ),
				h( 'span.mono.wpdbase-muted.wpdbase-spacer', { style: { fontSize: '11.5px' }, text: sprintf( /* translators: 1: max rows, 2: timeout seconds. */ __( 'max %1$s rows · %2$ss timeout', 'wp-dbase' ), num( state.ctx.max_rows ), state.ctx.timeout ) } )
			),
			preflightEl,
			resultEl
		);

		const drawResult = () => {
			resultEl.textContent = '';
			const r = state.consoleResult;
			if ( ! r ) {
				appendAll( resultEl, h( 'div.wpdbase-empty', null, h( 'div', null, __( 'Results appear here.', 'wp-dbase' ), h( 'br' ), h( 'span.mono', { style: { fontSize: '12px' }, text: state.ctx.mode === 'read' ? __( 'Read-only mode blocks writes even from this console.', 'wp-dbase' ) : '' } ) ) ) );
				return;
			}
			if ( r.error && ! r.results.length ) {
				appendAll( resultEl, h( 'div.wpdbase-error', { text: refusalText( r.error ) } ) );
				return;
			}
			r.results.forEach( ( res ) => {
				appendAll(
					resultEl,
					h(
						'div.wpdbase-result',
						null,
						h(
							'div.wpdbase-result-h',
							null,
							h( 'span.wpdbase-badge', { class: res.kind === 'read' ? null : 'is-danger', text: res.kind } ),
							h( 'code', { text: res.sql } ),
							h( 'span', { text: res.error ? __( 'error', 'wp-dbase' ) : ( res.columns.length ? sprintf( /* translators: %s: rows. */ _n( '%s row', '%s rows', res.rows.length, 'wp-dbase' ), num( res.rows.length ) ) + ( res.more ? '+' : '' ) : sprintf( /* translators: %s: affected rows. */ __( '%s affected', 'wp-dbase' ), num( res.affected ) ) ) } ),
							h( 'span', { text: res.ms + ' ms' } )
						),
						res.error ? h( 'div.wpdbase-error', { text: 'Error ' + res.errno + ' — ' + res.error } ) : null,
						res.more ? h( 'div.wpdbase-faint', { style: { padding: '6px 12px' }, text: sprintf( /* translators: %s: max rows. */ __( 'Only the first %s rows are shown. Add a LIMIT to see others.', 'wp-dbase' ), num( state.ctx.max_rows ) ) } ) : null,
						res.columns.length
							? h(
								'div',
								{ style: { overflow: 'auto', maxHeight: '420px' } },
								h(
									'table.wpdbase-table',
									null,
									h( 'thead', null, h( 'tr', null, res.columns.map( ( c ) => h( 'th', null, h( 'button', { type: 'button', text: c } ) ) ) ) ),
									h( 'tbody', null, res.rows.map( ( row ) => h( 'tr', null, row.map( ( v ) => h( 'td', { class: v === null ? 'is-null' : null, title: v, text: v === null ? 'NULL' : v } ) ) ) ) )
								)
							)
							: null
					)
				);
			} );
		};

		const refusalText = ( code ) => ( {
			empty: __( 'Nothing to run.', 'wp-dbase' ),
			mode: __( 'Blocked by the safety mode — nothing was executed.', 'wp-dbase' ),
			confirmation: __( 'Writes need the database name typed as confirmation.', 'wp-dbase' ),
			connection: __( 'Could not reach the database.', 'wp-dbase' ),
			statement: __( 'A statement failed; the ones after it did not run.', 'wp-dbase' ),
		}[ code ] || code );

		const drawPreflight = ( plan, sql ) => {
			preflightEl.textContent = '';
			if ( ! plan ) {
				return;
			}
			const blocked = ! plan.allowed;
			appendAll(
				preflightEl,
				h(
					'div.wpdbase-preflight',
					{ class: blocked ? 'is-blocked' : null, role: 'alert' },
					h(
						'div',
						{ style: { display: 'flex', alignItems: 'center', gap: '9px', fontWeight: 600 } },
						icon( 'alert', 16 ),
						blocked
							? sprintf( /* translators: %s: safety mode. */ __( 'Pre-flight — blocked in %s mode', 'wp-dbase' ), MODES[ state.ctx.mode ].label )
							: __( 'Pre-flight — this script writes to your database', 'wp-dbase' )
					),
					h(
						'div.wpdbase-rows',
						null,
						plan.statements.map( ( s ) =>
							h( 'div', { style: { gridTemplateColumns: '70px 70px 1fr' } }, h( 'span', null, h( 'span.wpdbase-badge', { class: s.kind === 'read' ? null : 'is-danger', text: s.kind } ) ), h( 'span', { style: { color: s.allowed ? 'var(--wpdbase-ok)' : 'var(--wpdbase-danger)' }, text: s.allowed ? __( 'allowed', 'wp-dbase' ) : __( 'blocked', 'wp-dbase' ) } ), h( 'span', { text: s.sql } ) )
						)
					),
					h(
						'div',
						{ style: { display: 'flex', gap: '8px', alignItems: 'center' } },
						blocked
							? h( 'span.wpdbase-muted', { text: state.ctx.mode === 'expert' ? '' : __( 'Switch to Expert mode to run free SQL writes or DDL.', 'wp-dbase' ) } )
							: h( 'span.wpdbase-muted', { text: __( 'Statements run one by one and stop at the first error. Each write is logged.', 'wp-dbase' ) } ),
						h( 'div.wpdbase-spacer', { style: { display: 'flex', gap: '8px' } },
							h( 'button.wpdbase-btn', { type: 'button', onClick: () => {
								state.preflight = null;
								drawPreflight( null );
							} }, __( 'Cancel', 'wp-dbase' ) ),
							blocked ? null : h( 'button.wpdbase-btn.is-danger', { type: 'button', onClick: () => execute( sql, true ) }, __( 'Execute…', 'wp-dbase' ) )
						)
					)
				)
			);
		};

		async function run( sql ) {
			try {
				const plan = await api( dbPath( '/query' ), { method: 'POST', data: { sql, plan_only: true } } );
				if ( plan.writes || ! plan.allowed ) {
					state.preflight = plan;
					drawPreflight( plan, sql );
					return;
				}
				drawPreflight( null );
				await execute( sql, false );
			} catch ( e ) {
				fail( e );
			}
		}

		async function execute( sql, writes ) {
			let confirm = '';
			if ( writes ) {
				confirm = await confirmTyped( {
					title: __( 'Run statements that change data?', 'wp-dbase' ),
					body: __( 'There is no undo in this version. Make sure you have a backup.', 'wp-dbase' ),
					facts: [ [ __( 'Database', 'wp-dbase' ), state.db ], [ __( 'Mode', 'wp-dbase' ), MODES[ state.ctx.mode ].label ] ],
					sql,
					phrase: state.db,
					cta: __( 'Execute', 'wp-dbase' ),
				} );
				if ( confirm === null ) {
					return;
				}
			}
			const started = performance.now();
			try {
				state.consoleResult = await api( dbPath( '/query' ), { method: 'POST', data: { sql, confirm } } );
				state.preflight = null;
				drawPreflight( null );
				setStatus( sprintf( /* translators: 1: statements, 2: ms. */ __( '%1$s statements · %2$s ms', 'wp-dbase' ), state.consoleResult.results.length, Math.round( performance.now() - started ) ) );
				if ( writes && state.consoleResult.ok ) {
					toast( __( 'Executed', 'wp-dbase' ), __( 'Logged to the audit trail.', 'wp-dbase' ) );
					loadTables().catch( () => {} );
				}
			} catch ( e ) {
				fail( e );
			}
			drawResult();
		}

		drawResult();
		if ( state.preflight ) {
			drawPreflight( state.preflight, state.console );
		}
		editor.focus();
	}

	/* ── audit ─────────────────────────────────────────────── */

	function viewAudit() {
		const page = h( 'div.wpdbase-page' );
		appendAll( mainEl, page );
		api( '/audit' ).then( ( data ) => {
			const cols = '150px 110px 110px 70px 1fr 80px 70px';
			appendAll(
				page,
				h(
					'div.wpdbase-page-inner',
					null,
					h( 'div', null, h( 'h2.wpdbase-h', { text: __( 'Audit trail', 'wp-dbase' ) } ), h( 'div.wpdbase-muted', { text: __( 'Every write made through WP DBase: who, when, from where, where, and how many rows moved.', 'wp-dbase' ) } ) ),
					h(
						'div.wpdbase-panel',
						null,
						h(
							'div.wpdbase-rows',
							null,
							h( 'div.is-head', { style: { gridTemplateColumns: cols } }, [ __( 'When (UTC)', 'wp-dbase' ), __( 'Who', 'wp-dbase' ), __( 'Database', 'wp-dbase' ), __( 'Kind', 'wp-dbase' ), __( 'Statement', 'wp-dbase' ), __( 'Rows', 'wp-dbase' ), __( 'Mode', 'wp-dbase' ) ].map( ( t ) => h( 'span', { text: t } ) ) ),
							data.entries.length
								? data.entries.map( ( e ) =>
									h(
										'div',
										{ style: { gridTemplateColumns: cols }, title: e.ip },
										h( 'span.wpdbase-muted', { text: e.created_at } ),
										h( 'span', { text: e.user_login } ),
										h( 'span.wpdbase-muted', { text: e.database_name } ),
										h( 'span', null, h( 'span.wpdbase-badge', { class: e.error ? 'is-danger' : null, text: e.kind } ) ),
										h( 'span', { style: { whiteSpace: 'nowrap', color: e.error ? 'var(--wpdbase-danger)' : null }, title: e.error || e.statement, text: e.statement } ),
										h( 'span.wpdbase-muted', { text: e.affected_rows === null ? '—' : num( e.affected_rows ) } ),
										h( 'span.wpdbase-faint', { text: e.safety_mode } )
									)
								)
								: h( 'div', { style: { padding: '14px' } }, h( 'span.wpdbase-faint', { text: __( 'No writes yet.', 'wp-dbase' ) } ) )
						)
					)
				)
			);
		} ).catch( fail );
	}

	/* ── settings ──────────────────────────────────────────── */

	function viewSettings() {
		const c = state.ctx;
		const rows = [
			[ 'WPDBASE_DATABASES', c.databases.length > 1 ? sprintf( /* translators: %s: number of databases. */ __( '%s databases', 'wp-dbase' ), c.databases.length ) : __( 'only this site', 'wp-dbase' ), __( 'Extra databases that can be opened, using the wp-config.php credentials: a list of names, or "*" for every database the MySQL user can see.', 'wp-dbase' ) ],
			[ 'WPDBASE_MAX_SAFETY_MODE', MODES[ c.max_mode ].label, __( 'The most permissive mode anyone can switch to on this site.', 'wp-dbase' ) ],
			[ 'WPDBASE_CAPABILITY', c.capability, __( 'Capability required to open WP DBase (filter: wpdbase_user_can_access to narrow it further).', 'wp-dbase' ) ],
			[ 'WPDBASE_QUERY_TIMEOUT', c.timeout + ' s', __( 'Console statements are aborted after this many seconds.', 'wp-dbase' ) ],
			[ 'WPDBASE_MAX_ROWS', num( c.max_rows ), __( 'Rows returned per console statement.', 'wp-dbase' ) ],
		];
		appendAll(
			mainEl,
			h(
				'div.wpdbase-page',
				null,
				h(
					'div.wpdbase-page-inner',
					{ style: { maxWidth: '780px' } },
					h( 'div', null, h( 'h2.wpdbase-h', { text: __( 'Settings', 'wp-dbase' ) } ), h( 'div.wpdbase-muted', { text: __( 'Infrastructure settings are constants in wp-config.php, so they belong to whoever runs the server — not to the dashboard.', 'wp-dbase' ) } ) ),
					rows.map( ( [ name, value, help ] ) =>
						h(
							'div.wpdbase-card',
							{ style: { flexDirection: 'row', alignItems: 'center', gap: '14px' } },
							h( 'div', { style: { minWidth: 0 } }, h( 'strong.mono', { text: name } ), h( 'div.wpdbase-muted', { style: { fontSize: '12.5px' }, text: help } ) ),
							h( 'span.wpdbase-badge.wpdbase-spacer', { style: { flex: 'none' }, text: value } )
						)
					),
					h( 'pre.wpdbase-code', { text: "// wp-config.php\ndefine( 'WPDBASE_MAX_SAFETY_MODE', 'assisted' );\ndefine( 'WPDBASE_DATABASES', array( 'staging_db' ) );" } )
				)
			)
		);
	}

	/* ── boot ──────────────────────────────────────────────── */

	// Capture phase: on this screen Ctrl/Cmd+K opens our palette instead of the core command palette.
	document.addEventListener(
		'keydown',
		( e ) => {
			if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'k' && state.ctx ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				openPalette();
			}
		},
		true
	);
	window.addEventListener( 'resize', fitHeight );

	async function boot() {
		try {
			state.ctx = await api( '/context' );
			const saved = store( 'db' );
			state.db = state.ctx.databases.includes( saved ) ? saved : state.ctx.own;
			await loadTables();
			renderShell();
			fitHeight();
			if ( ! state.ctx.onboarded ) {
				renderOnboarding();
			}
		} catch ( e ) {
			root.textContent = '';
			appendAll( root, h( 'div.wpdbase-error', { text: ( e && e.message ) || String( e ) } ) );
		}
	}

	boot();
}() );
