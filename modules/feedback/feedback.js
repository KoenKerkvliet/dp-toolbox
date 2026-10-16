/*!
 * DP Toolbox — Feedback
 *
 * Gebaseerd op de opmerkingenfunctie van Pencilino by Rino de Boer
 * (https://github.com/rinothecoder/pencilino), GPL-2.0-or-later. Het kiezen van
 * een element, de selector en het terugvinden ervan zijn daaruit overgenomen;
 * de interface is opnieuw opgebouwd en in het Nederlands.
 */
( function () {
	'use strict';

	const cfg = window.dpFeedback;
	if ( ! cfg || ! window.fetch ) {
		return;
	}

	const html = document.documentElement;
	const NEGEER = '.dp-fb-ui, .dp-be-ui, .dp-balk, #wpadminbar, .pencil-ui, .media-modal, .media-modal-backdrop, script, style, iframe';

	let actief = false;
	let items = [];
	let gekozen = null; // element waar de popup bij hoort
	let doel = null; // opgeslagen doel van een nieuwe opmerking
	let anker = null; // klikpunt binnen het element (0–1)
	let bezig = false;

	/* ------------------------------------------------------------------
	   Interface opbouwen. Alle tekst via textContent; nooit innerHTML met data.
	   ------------------------------------------------------------------ */

	const maak = ( tag, klasse, tekst ) => {
		const el = document.createElement( tag );
		if ( klasse ) {
			el.className = klasse;
		}
		if ( tekst ) {
			el.textContent = tekst;
		}
		return el;
	};

	const knop = maak( 'button', 'dp-fb-ui dp-fb-knop' );
	knop.type = 'button';
	knop.setAttribute( 'aria-pressed', 'false' );
	const knopIcoon = maak( 'span', 'dp-fb-knop__icoon' );
	knopIcoon.setAttribute( 'aria-hidden', 'true' );
	const knopTekst = maak( 'span', 'dp-fb-knop__tekst', 'Feedback geven' );
	const knopTeller = maak( 'span', 'dp-fb-knop__teller' );
	knopTeller.hidden = true;
	knop.append( knopIcoon, knopTekst, knopTeller );

	// Eén balk linksonder, gedeeld met de module Bewerken op de site. Wie het eerst laadt, maakt hem.
	// Voor een klant (zonder adminbalk) komt er ook een uitlogknop in.
	const balk = document.querySelector( '.dp-balk' ) || maak( 'div', 'dp-balk' );
	balk.setAttribute( 'role', 'toolbar' );
	balk.setAttribute( 'aria-label', 'Design Pixels' );
	knop.classList.add( 'dp-balk__knop', 'dp-balk__knop--feedback' );
	balk.append( knop );
	if ( cfg.uitloggen && ! balk.querySelector( '.dp-balk__uitlog' ) ) {
		const uitlog = maak( 'a', 'dp-balk__uitlog', 'Uitloggen' );
		uitlog.href = cfg.uitloggen;
		balk.append( uitlog );
	}

	const kader = maak( 'div', 'dp-fb-ui dp-fb-kader' );
	const kaderLabel = maak( 'span', 'dp-fb-kader__label' );
	kader.append( kaderLabel );
	kader.hidden = true;

	const pins = maak( 'div', 'dp-fb-ui dp-fb-pins' );
	pins.hidden = true;

	const melding = maak( 'div', 'dp-fb-ui dp-fb-melding' );
	melding.setAttribute( 'role', 'status' );
	melding.setAttribute( 'aria-live', 'polite' );
	melding.hidden = true;

	const popup = maak( 'div', 'dp-fb-ui dp-fb-popup' );
	popup.setAttribute( 'role', 'dialog' );
	popup.setAttribute( 'aria-labelledby', 'dp-fb-popup-titel' );
	popup.hidden = true;

	const popupTitel = maak( 'p', 'dp-fb-popup__titel' );
	popupTitel.id = 'dp-fb-popup-titel';

	// Nieuwe feedback
	const nieuw = maak( 'form', 'dp-fb-popup__nieuw' );
	const veldLabel = maak( 'label', 'dp-fb-popup__veldlabel', 'Wat moet er anders?' );
	veldLabel.htmlFor = 'dp-fb-tekst';
	const invoer = maak( 'textarea', 'dp-fb-popup__invoer' );
	invoer.id = 'dp-fb-tekst';
	invoer.rows = 4;
	invoer.maxLength = 5000;
	invoer.placeholder = 'Bijvoorbeeld: andere foto, of het telefoonnummer klopt niet.';
	const hint = maak( 'p', 'dp-fb-popup__hint', 'Alleen zichtbaar voor jou en Design Pixels. De pagina verandert niet.' );
	const fout = maak( 'p', 'dp-fb-popup__fout' );
	fout.hidden = true;
	const nieuwActies = maak( 'div', 'dp-fb-popup__acties' );
	const annuleer = maak( 'button', 'dp-fb-popup__knop dp-fb-popup__knop--stil', 'Annuleren' );
	annuleer.type = 'button';
	const plaats = maak( 'button', 'dp-fb-popup__knop', 'Plaats feedback' );
	plaats.type = 'submit';
	nieuwActies.append( annuleer, plaats );
	nieuw.append( veldLabel, invoer, hint, fout, nieuwActies );

	// Bestaande feedback bekijken
	const bekijk = maak( 'div', 'dp-fb-popup__bekijk' );
	const bekijkMeta = maak( 'p', 'dp-fb-popup__meta' );
	const bekijkTekst = maak( 'p', 'dp-fb-popup__notitie' );
	const bekijkFout = maak( 'p', 'dp-fb-popup__hint' );
	bekijkFout.hidden = true;
	const bekijkActies = maak( 'div', 'dp-fb-popup__acties' );
	const sluit = maak( 'button', 'dp-fb-popup__knop dp-fb-popup__knop--stil', 'Sluiten' );
	sluit.type = 'button';
	const overzicht = maak( 'a', 'dp-fb-popup__knop dp-fb-popup__knop--stil', 'Overzicht' );
	overzicht.hidden = ! cfg.overzicht;
	if ( cfg.overzicht ) {
		overzicht.href = cfg.overzicht;
	}
	const afvinken = maak( 'button', 'dp-fb-popup__knop', 'Afvinken' );
	afvinken.type = 'button';
	afvinken.hidden = ! cfg.magAfhandelen;
	bekijkActies.append( sluit, overzicht, afvinken );
	bekijk.append( bekijkMeta, bekijkTekst, bekijkFout, bekijkActies );

	popup.append( popupTitel, nieuw, bekijk );
	document.body.append( kader, pins, popup, melding, balk );

	/* ------------------------------------------------------------------
	   Elementen kiezen en terugvinden (uit Pencilino)
	   ------------------------------------------------------------------ */

	const kiesbaar = ( el ) => {
		if ( ! ( el instanceof Element ) || el.closest( NEGEER ) ) {
			return null;
		}
		return el.closest( '[data-pencil-field]' ) || el;
	};

	const isContainer = ( el ) => el.matches( 'section, article, main, header, footer, div, body, nav, aside, figure, ul, ol' );

	const labelVan = ( el ) => {
		if ( ! el ) {
			return 'Paginasectie';
		}
		if ( el.dataset.pencilLabel ) {
			return el.dataset.pencilLabel;
		}
		if ( el.getAttribute( 'aria-label' ) ) {
			return el.getAttribute( 'aria-label' );
		}
		if ( el.tagName === 'IMG' ) {
			return el.alt ? 'Afbeelding: ' + el.alt.slice( 0, 60 ) : 'Afbeelding';
		}
		if ( isContainer( el ) ) {
			const kop = el.querySelector( 'h1, h2, h3, h4' );
			return kop && kop.textContent.trim() ? kop.textContent.trim().slice( 0, 80 ) : 'Paginasectie';
		}
		return el.textContent.trim().slice( 0, 80 ) || el.tagName.toLowerCase();
	};

	const selectorVan = ( el ) => {
		const delen = [];
		let node = el;
		while ( node && node !== document.body && node.nodeType === 1 ) {
			if ( node.id && document.querySelectorAll( '#' + CSS.escape( node.id ) ).length === 1 ) {
				delen.unshift( '#' + CSS.escape( node.id ) );
				break;
			}
			const tag = node.tagName.toLowerCase();
			const klassen = Array.from( node.classList ).filter( ( k ) => ! /^(dp-fb-|pencil-|is-|has-|active|hover|focus)/.test( k ) ).slice( 0, 3 );
			const broers = node.parentElement ? Array.from( node.parentElement.children ).filter( ( c ) => c.tagName === node.tagName ) : [];
			delen.unshift( tag + klassen.map( ( k ) => '.' + CSS.escape( k ) ).join( '' ) + ( broers.length > 1 ? ':nth-of-type(' + ( broers.indexOf( node ) + 1 ) + ')' : '' ) );
			node = node.parentElement;
		}
		return ( delen.length ? delen.join( ' > ' ) : 'body' ).slice( 0, 2000 );
	};

	const doelVan = ( el, x, y ) => {
		const r = el.getBoundingClientRect();
		return {
			selector: selectorVan( el ),
			veld: el.dataset.pencilField || '',
			id: el.id || '',
			tag: el.tagName.toLowerCase(),
			label: labelVan( el ),
			tekst: isContainer( el ) ? '' : el.textContent.trim().slice( 0, 240 ),
			x: typeof x === 'number' && r.width ? Math.max( 0, Math.min( 1, ( x - r.left ) / r.width ) ) : 0.5,
			y: typeof y === 'number' && r.height ? Math.max( 0, Math.min( 1, ( y - r.top ) / r.height ) ) : 0.5,
		};
	};

	const vind = ( d ) => {
		if ( ! d ) {
			return null;
		}
		if ( d.veld ) {
			const veld = document.querySelector( '[data-pencil-field="' + CSS.escape( d.veld ) + '"]' );
			if ( veld ) {
				return veld;
			}
		}
		if ( d.id ) {
			const el = document.getElementById( d.id );
			if ( el && ! el.closest( '.dp-fb-ui' ) ) {
				return el;
			}
		}
		try {
			const el = d.selector ? document.querySelector( d.selector ) : null;
			if ( el && ! el.closest( '.dp-fb-ui' ) && ( ! d.tekst || el.textContent.trim().slice( 0, 240 ) === d.tekst ) ) {
				return el;
			}
		} catch ( e ) {
			// Een verouderde selector: de feedback blijft in het overzicht staan.
		}
		if ( d.tekst && /^[a-z][a-z0-9-]*$/.test( d.tag || '' ) ) {
			const treffers = Array.from( document.querySelectorAll( d.tag ) ).filter( ( el ) => ! el.closest( '.dp-fb-ui' ) && el.textContent.trim().slice( 0, 240 ) === d.tekst );
			if ( treffers.length === 1 ) {
				return treffers[ 0 ];
			}
		}
		return null;
	};

	/* ------------------------------------------------------------------
	   Weergave
	   ------------------------------------------------------------------ */

	let meldingTimer = null;
	const toon = ( tekst ) => {
		window.clearTimeout( meldingTimer );
		melding.textContent = tekst;
		melding.hidden = false;
		meldingTimer = window.setTimeout( () => {
			melding.hidden = true;
		}, 1800 );
	};

	const zetKader = ( el ) => {
		if ( ! actief || ! el ) {
			kader.hidden = true;
			return;
		}
		const r = el.getBoundingClientRect();
		kader.style.left = Math.max( 0, r.left - 4 ) + 'px';
		kader.style.top = Math.max( 0, r.top - 4 ) + 'px';
		kader.style.width = r.width + 8 + 'px';
		kader.style.height = r.height + 8 + 'px';
		kaderLabel.textContent = labelVan( el );
		kader.classList.toggle( 'dp-fb-kader--label-binnen', r.top < 36 );
		kader.hidden = false;
	};

	const zetPopup = () => {
		if ( popup.hidden ) {
			return;
		}
		const rand = 16;
		const p = popup.getBoundingClientRect();
		let x = window.innerWidth / 2 - p.width / 2;
		let y = window.innerHeight / 2 - p.height / 2;

		if ( gekozen && gekozen !== document.body ) {
			const r = gekozen.getBoundingClientRect();
			x = anker ? r.left + r.width * anker.x : r.left;
			y = ( anker ? r.top + r.height * anker.y : r.bottom ) + 12;
			if ( y + p.height > window.innerHeight - rand ) {
				y = ( anker ? r.top + r.height * anker.y : r.top ) - p.height - 12;
			}
		}

		popup.style.left = Math.min( Math.max( x, rand ), Math.max( rand, window.innerWidth - p.width - rand ) ) + 'px';
		popup.style.top = Math.min( Math.max( y, rand ), Math.max( rand, window.innerHeight - p.height - rand ) ) + 'px';
	};

	const tekenPins = () => {
		pins.replaceChildren();
		if ( ! actief ) {
			return;
		}
		// Pins tonen het vaste nummer (#id), zodat "pin 8" op de site en "#8" in het overzicht hetzelfde zijn.
		items.filter( ( i ) => ! i.afgehandeld ).forEach( ( item ) => {
			const el = vind( item.doel );
			if ( ! el ) {
				return;
			}
			const r = el.getBoundingClientRect();
			if ( ! r.width || ! r.height ) {
				return;
			}
			const x = r.left + r.width * ( item.doel.x ?? 0.5 );
			const y = r.top + r.height * ( item.doel.y ?? 0.5 );
			if ( y < 0 || y > window.innerHeight || x < 0 || x > window.innerWidth ) {
				return;
			}
			const pin = maak( 'button', 'dp-fb-pin', String( item.id ) );
			pin.type = 'button';
			pin.style.left = x + 'px';
			pin.style.top = y + 'px';
			pin.setAttribute( 'aria-label', 'Feedback #' + item.id + ': ' + item.tekst.slice( 0, 80 ) );
			pin.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				openBestaand( item );
			} );
			pins.append( pin );
		} );
	};

	const zetTeller = () => {
		const open = items.filter( ( i ) => ! i.afgehandeld ).length;
		knopTeller.textContent = String( open );
		knopTeller.hidden = ! open;
		knop.setAttribute( 'aria-label', open ? 'Feedback geven (' + open + ' open op deze pagina)' : 'Feedback geven' );
	};

	let frame = 0;
	const verversLater = () => {
		if ( frame ) {
			return;
		}
		frame = window.requestAnimationFrame( () => {
			frame = 0;
			tekenPins();
			if ( gekozen && ! popup.hidden ) {
				zetKader( gekozen === document.body ? null : gekozen );
				zetPopup();
			} else if ( ! gekozen ) {
				kader.hidden = true;
			}
		} );
	};

	/* ------------------------------------------------------------------
	   Popup
	   ------------------------------------------------------------------ */

	const sluitPopup = () => {
		popup.hidden = true;
		gekozen = null;
		doel = null;
		anker = null;
		invoer.value = '';
		fout.hidden = true;
		kader.hidden = true;
	};

	const openNieuw = ( el, x, y ) => {
		gekozen = el;
		doel = doelVan( el, x, y );
		anker = typeof x === 'number' ? { x: doel.x, y: doel.y } : null;
		popupTitel.textContent = doel.label;
		nieuw.hidden = false;
		bekijk.hidden = true;
		fout.hidden = true;
		popup.hidden = false;
		zetKader( el );
		zetPopup();
		window.requestAnimationFrame( () => invoer.focus() );
	};

	const openBestaand = ( item ) => {
		const el = vind( item.doel );
		if ( el ) {
			el.scrollIntoView( { block: 'center', behavior: 'instant' } );
		}
		gekozen = el || document.body;
		anker = el ? { x: item.doel.x ?? 0.5, y: item.doel.y ?? 0.5 } : null;
		popup.dataset.id = String( item.id );
		popupTitel.textContent = '#' + item.id + ' · ' + ( item.doel.label || 'Feedback' );
		bekijkMeta.textContent = item.auteur + ' · ' + item.datum + ( item.afgehandeld ? ' · afgehandeld' : '' );
		bekijkTekst.textContent = item.tekst;
		bekijkFout.textContent = 'Het element is veranderd of verplaatst. De feedback blijft bewaard met de pagina erbij.';
		bekijkFout.hidden = Boolean( el );
		afvinken.hidden = ! cfg.magAfhandelen || item.afgehandeld;
		nieuw.hidden = true;
		bekijk.hidden = false;
		popup.hidden = false;
		zetKader( el );
		zetPopup();
		window.requestAnimationFrame( () => sluit.focus() );
	};

	/* ------------------------------------------------------------------
	   Server
	   ------------------------------------------------------------------ */

	const vraag = async ( url, opties = {} ) => {
		const antwoord = await window.fetch( url, {
			credentials: 'same-origin',
			cache: 'no-store',
			...opties,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce, ...( opties.headers || {} ) },
		} );
		let data = null;
		try {
			data = await antwoord.json();
		} catch ( e ) {
			throw new Error( 'Er ging iets mis. Probeer het opnieuw.' );
		}
		if ( ! antwoord.ok ) {
			throw new Error( ( data && data.message ) || 'Er ging iets mis. Probeer het opnieuw.' );
		}
		return data;
	};

	const laad = async () => {
		try {
			const url = new URL( cfg.rest );
			url.searchParams.set( 'pagina', window.location.href );
			items = await vraag( url.href );
		} catch ( e ) {
			items = [];
		}
		zetTeller();
		tekenPins();

		const gevraagd = Number( new URLSearchParams( window.location.search ).get( 'dp-feedback' ) );
		if ( gevraagd ) {
			let item = items.find( ( i ) => i.id === gevraagd );
			if ( ! item ) {
				try {
					item = await vraag( cfg.rest + '/' + gevraagd );
				} catch ( e ) {
					item = null;
				}
			}
			if ( item ) {
				zetActief( true );
				openBestaand( item );
			}
		}
	};

	nieuw.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		if ( bezig || ! doel ) {
			return;
		}
		const tekst = invoer.value.trim();
		if ( ! tekst ) {
			fout.textContent = 'Schrijf eerst wat er anders moet.';
			fout.hidden = false;
			invoer.focus();
			return;
		}
		bezig = true;
		plaats.disabled = true;
		try {
			const item = await vraag( cfg.rest, {
				method: 'POST',
				body: JSON.stringify( {
					pagina: window.location.href,
					pagina_id: Number( cfg.paginaId ) || 0,
					paginatitel: document.title.slice( 0, 191 ),
					doel,
					tekst,
				} ),
			} );
			items.push( item );
			sluitPopup();
			zetTeller();
			tekenPins();
			toon( 'Feedback geplaatst' );
		} catch ( err ) {
			fout.textContent = err.message;
			fout.hidden = false;
		} finally {
			bezig = false;
			plaats.disabled = false;
		}
	} );

	afvinken.addEventListener( 'click', async () => {
		const id = Number( popup.dataset.id );
		if ( bezig || ! id ) {
			return;
		}
		bezig = true;
		afvinken.disabled = true;
		try {
			await vraag( cfg.rest + '/' + id, { method: 'POST', body: JSON.stringify( { afgehandeld: true } ) } );
			items = items.filter( ( i ) => i.id !== id );
			sluitPopup();
			zetTeller();
			tekenPins();
			toon( 'Afgevinkt' );
		} catch ( err ) {
			bekijkFout.textContent = err.message;
			bekijkFout.hidden = false;
		} finally {
			bezig = false;
			afvinken.disabled = false;
		}
	} );

	/* ------------------------------------------------------------------
	   Bediening
	   ------------------------------------------------------------------ */

	const zetActief = ( aan ) => {
		if ( aan ) {
			// Er staat altijd maar één modus aan: bewerken en feedback geven sluiten elkaar uit.
			document.dispatchEvent( new CustomEvent( 'dp-modus', { detail: 'feedback' } ) );
		}
		actief = aan;
		html.classList.toggle( 'dp-fb-actief', actief );
		knop.setAttribute( 'aria-pressed', actief ? 'true' : 'false' );
		knopTekst.textContent = actief ? 'Klaar met feedback' : 'Feedback geven';
		pins.hidden = ! actief;
		if ( ! actief ) {
			sluitPopup();
		}
		tekenPins();
		if ( actief ) {
			toon( 'Klik op wat er anders moet' );
		}
	};

	knop.addEventListener( 'click', () => zetActief( ! actief ) );
	document.addEventListener( 'dp-modus', ( e ) => {
		if ( e.detail !== 'feedback' && actief ) {
			zetActief( false );
		}
	} );
	annuleer.addEventListener( 'click', sluitPopup );
	sluit.addEventListener( 'click', sluitPopup );

	// Staat de bewerkmodus van Pencilino aan, dan laten we de klik aan die.
	const pencilActief = () => html.classList.contains( 'pencil-selection-active' ) || html.classList.contains( 'dp-be-actief' );

	document.addEventListener( 'pointerover', ( e ) => {
		if ( ! actief || ! popup.hidden || pencilActief() ) {
			return;
		}
		const el = kiesbaar( e.target );
		if ( el ) {
			zetKader( el );
		}
	} );

	document.addEventListener( 'pointerout', ( e ) => {
		if ( actief && popup.hidden && ! e.relatedTarget ) {
			kader.hidden = true;
		}
	} );

	document.addEventListener( 'click', ( e ) => {
		if ( ! actief || pencilActief() || ! popup.hidden ) {
			return;
		}
		const el = kiesbaar( e.target );
		if ( ! el ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		openNieuw( el, e.detail > 0 ? e.clientX : undefined, e.detail > 0 ? e.clientY : undefined );
	}, true );

	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Escape' || bezig ) {
			return;
		}
		if ( ! popup.hidden ) {
			sluitPopup();
		} else if ( actief ) {
			zetActief( false );
			knop.focus();
		}
	} );

	invoer.addEventListener( 'keydown', ( e ) => {
		if ( ( e.metaKey || e.ctrlKey ) && e.key === 'Enter' ) {
			e.preventDefault();
			nieuw.requestSubmit();
		}
	} );

	window.addEventListener( 'scroll', ( e ) => {
		if ( popup.contains( e.target ) ) {
			return;
		}
		verversLater();
	}, true );
	window.addEventListener( 'resize', verversLater );

	/* ------------------------------------------------------------------
	   De balk staat onderaan het midden. Zit daar toch een vast element van de site onder (een brede
	   cookiemelding, een "naar boven"- of chatknop), dan tillen we de balk erboven, zodat hij nooit
	   een knop van de site afdekt. De hoogte gaat via de CSS-variabele --dp-balk-extra.
	   ------------------------------------------------------------------ */

	const ontwijk = () => {
		html.style.setProperty( '--dp-balk-extra', '0px' );

		const r = balk.getBoundingClientRect();
		if ( ! r.width || ! r.height ) {
			return;
		}

		const hoogte = window.innerHeight;
		const xs = [ r.left + 4, r.left + r.width * 0.25, r.left + r.width / 2, r.left + r.width * 0.75, r.right - 4 ];
		const ys = [ r.top + 4, r.top + r.height / 2, r.bottom - 4 ];
		const gezien = new Set();
		let extra = 0;

		xs.forEach( ( x ) => ys.forEach( ( y ) => {
			document.elementsFromPoint( x, y ).forEach( ( el ) => {
				if ( gezien.has( el ) || el.closest( NEGEER ) ) {
					return;
				}
				gezien.add( el );

				let vast = el;
				while ( vast && vast !== document.body && vast !== html ) {
					const positie = window.getComputedStyle( vast ).position;
					if ( 'fixed' === positie || 'sticky' === positie ) {
						break;
					}
					vast = vast.parentElement;
				}
				if ( ! vast || vast === document.body || vast === html ) {
					return;
				}

				// Alleen kleine vaste elementen in de onderste helft. Volledige lagen (popups, menu's) laten we met rust.
				const v = vast.getBoundingClientRect();
				if ( v.height > hoogte * 0.45 || v.top < hoogte * 0.5 || v.bottom < r.top ) {
					return;
				}
				extra = Math.max( extra, Math.ceil( r.bottom - v.top + 10 ) );
			} );
		} ) );

		html.style.setProperty( '--dp-balk-extra', extra + 'px' );
	};

	let ontwijkTimer = 0;
	const ontwijkLater = () => {
		window.clearTimeout( ontwijkTimer );
		ontwijkTimer = window.setTimeout( ontwijk, 120 );
	};

	window.addEventListener( 'resize', ontwijkLater );
	window.addEventListener( 'load', ontwijkLater );
	document.addEventListener( 'transitionend', ontwijkLater, true );
	document.addEventListener( 'animationend', ontwijkLater, true );
	new MutationObserver( ( lijst ) => {
		if ( lijst.some( ( m ) => ! m.target.closest || ! m.target.closest( NEGEER ) ) ) {
			ontwijkLater();
		}
	} ).observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'class', 'style', 'hidden' ] } );
	// Banners verschijnen vaak pas na het laden van de pagina.
	[ 400, 1200, 2500 ].forEach( ( ms ) => window.setTimeout( ontwijkLater, ms ) );
	ontwijkLater();

	laad();
}() );
