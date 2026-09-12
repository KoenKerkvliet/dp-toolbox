/**
 * DP Toolbox — Lorem ipsum
 *
 * Typ {ipsum3s} (zinnen), {ipsum2p} (alinea's) of {ipsum5w} (woorden) en de code wordt meteen
 * vervangen door opvultekst, zodra de sluitaccolade er staat. Werkt in gewone velden, Gutenberg,
 * de klassieke editor (TinyMCE), JetEngine, FluentCart en de Bricks-builder, ook in iframes van
 * dezelfde site. De tekst gaat erin via execCommand('insertText'): dat ziet elk framework
 * (React, Vue) als gewoon typen. Code-editors (CodeMirror e.d.) blijven buiten schot.
 */
(function () {
	'use strict';
	if (window.dpIpsumActief) return;
	window.dpIpsumActief = true;

	var ZINNEN = window.dpIpsumZinnen || ['Lorem ipsum dolor sit amet, consectetur adipiscing elit.'];
	var WOORDEN = [];
	ZINNEN.join(' ').toLowerCase().replace(/[^a-z\s]/g, '').split(/\s+/).forEach(function (w) {
		if (w.length > 2 && WOORDEN.indexOf(w) === -1 && w !== 'lorem' && w !== 'ipsum') WOORDEN.push(w);
	});
	var PATROON = /\{ipsum(\d{1,2})([swp])\}$/i;
	var MAX = 20;
	var UITGESLOTEN = '.CodeMirror, .cm-editor, .monaco-editor, .ace_editor, [data-no-ipsum]';

	/* ---------------------------------------------------------------- */
	/*  Tekst maken                                                       */
	/* ---------------------------------------------------------------- */

	function schud(lijst) {
		var a = lijst.slice();
		for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; }
		return a;
	}
	// Elke keer andere zinnen, en binnen één invoeging geen herhaling tot de voorraad op is.
	function zinnen(n) {
		var uit = [], stapel = [];
		while (uit.length < n) { if (!stapel.length) stapel = schud(ZINNEN); uit.push(stapel.pop()); }
		return uit;
	}
	function woorden(n) {
		var uit = ['Lorem', 'ipsum'], stapel = [];
		while (uit.length < n) { if (!stapel.length) stapel = schud(WOORDEN); uit.push(stapel.pop()); }
		return uit.slice(0, n).join(' ');
	}
	function maak(n, soort) {
		n = Math.max(1, Math.min(MAX, n));
		if (soort === 'w') return { tekst: woorden(n) };
		if (soort === 's') return { tekst: zinnen(n).join(' ') };
		var alineas = [];
		for (var i = 0; i < n; i++) alineas.push(zinnen(4 + Math.floor(Math.random() * 3)).join(' '));
		return { alineas: alineas };
	}
	function html(alineas) { return alineas.map(function (p) { return '<p>' + p + '</p>'; }).join(''); }

	/* ---------------------------------------------------------------- */
	/*  Gewone velden: input en textarea                                  */
	/* ---------------------------------------------------------------- */

	function isTekstveld(el) {
		if (el.tagName === 'TEXTAREA') return true;
		if (el.tagName !== 'INPUT') return false;
		var type = (el.getAttribute('type') || 'text').toLowerCase();
		return type === 'text' || type === 'search';
	}

	function vervangVeld(el, code, n, soort) {
		var eind = el.selectionEnd, oud = el.value, begin = eind - code.length;
		if (eind == null || oud.slice(begin, eind) !== code) return;
		var r = maak(n, soort);
		// Eén regel: alinea's achter elkaar. Tekstvak: witregel ertussen (wordt in WordPress een alinea).
		var tekst = r.alineas ? r.alineas.join(el.tagName === 'TEXTAREA' ? '\n\n' : ' ') : r.tekst;
		var doc = el.ownerDocument, w = doc.defaultView;
		el.focus();
		el.setSelectionRange(begin, eind);
		var gelukt = false;
		try { gelukt = doc.execCommand('insertText', false, tekst); } catch (e) {}
		if (gelukt && el.value.slice(begin, begin + tekst.length) === tekst) return;
		// Terugval (browser zonder insertText in velden): waarde zetten zoals een framework die verwacht.
		var proto = el.tagName === 'TEXTAREA' ? w.HTMLTextAreaElement.prototype : w.HTMLInputElement.prototype;
		Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, oud.slice(0, begin) + tekst + oud.slice(eind));
		el.setSelectionRange(begin + tekst.length, begin + tekst.length);
		el.dispatchEvent(new w.Event('input', { bubbles: true }));
		el.dispatchEvent(new w.Event('change', { bubbles: true }));
	}

	/* ---------------------------------------------------------------- */
	/*  Bewerkbare tekst: Gutenberg, TinyMCE, Bricks                      */
	/* ---------------------------------------------------------------- */

	function tinymceVan(doc) {
		try {
			var frame = doc.defaultView.frameElement;
			if (!frame || !/_ifr$/.test(frame.id)) return null;
			var tm = frame.ownerDocument.defaultView.tinymce;
			return tm && tm.get ? tm.get(frame.id.replace(/_ifr$/, '')) : null;
		} catch (e) { return null; }
	}

	// Alinea's in een Gutenberg-alineablok: de eerste op de plek van de code, de rest als nieuwe blokken.
	function gutenberg(host, doc, alineas) {
		var wp = window.wp;
		if (!host.classList.contains('block-editor-rich-text__editable') || !wp || !wp.data || !wp.blocks) return false;
		var kies = wp.data.select('core/block-editor');
		var id = kies && kies.getSelectedBlockClientId();
		var blok = id && kies.getBlock(id);
		if (!blok || blok.name !== 'core/paragraph') return false;
		doc.execCommand('insertText', false, alineas[0]);
		if (alineas.length > 1) {
			var nieuw = alineas.slice(1).map(function (p) { return wp.blocks.createBlock('core/paragraph', { content: p }); });
			wp.data.dispatch('core/block-editor').insertBlocks(nieuw, kies.getBlockIndex(id) + 1, kies.getBlockRootClientId(id), false);
		}
		return true;
	}

	// Kan dit element alinea's bevatten? Een kop, alinea of knop niet: daar komt doorlopende tekst.
	function meerRegelig(host) {
		return !/^(P|H[1-6]|SPAN|A|LI|BUTTON|LABEL|TD|TH|STRONG|EM|B|I|FIGCAPTION|BLOCKQUOTE|CITE)$/.test(host.tagName);
	}

	function vervangBewerkbaar(doc, code, n, soort) {
		var sel = doc.getSelection();
		if (!sel || !sel.rangeCount || !sel.isCollapsed) return;
		var knoop = sel.focusNode, pos = sel.focusOffset;
		if (!knoop || knoop.nodeType !== 3 || knoop.data.slice(pos - code.length, pos) !== code) return;
		var host = knoop.parentElement && knoop.parentElement.closest('[contenteditable="true"], [contenteditable=""]');
		if (!host || host.closest(UITGESLOTEN)) return;
		var bereik = doc.createRange();
		bereik.setStart(knoop, pos - code.length);
		bereik.setEnd(knoop, pos);
		var r = maak(n, soort);

		var editor = tinymceVan(doc);
		if (editor) {
			editor.selection.setRng(bereik);
			editor.insertContent(r.alineas ? html(r.alineas) : r.tekst);
			return;
		}
		sel.removeAllRanges();
		sel.addRange(bereik);
		if (r.alineas && gutenberg(host, doc, r.alineas)) return;
		if (r.alineas && meerRegelig(host) && doc.execCommand('insertHTML', false, html(r.alineas))) return;
		doc.execCommand('insertText', false, r.alineas ? r.alineas.join(' ') : r.tekst);
	}

	/* ---------------------------------------------------------------- */
	/*  Luisteren, ook in iframes (Gutenberg-canvas, TinyMCE, Bricks)     */
	/* ---------------------------------------------------------------- */

	function bijInvoer(e) {
		var doc = e.currentTarget, el = e.target;
		if (e.isComposing || !el || el.nodeType !== 1 || (el.closest && el.closest(UITGESLOTEN))) return;
		var m;
		if (isTekstveld(el)) {
			if (el.selectionEnd == null) return;
			m = el.value.slice(0, el.selectionEnd).match(PATROON);
			// Na afloop van dit invoer-event, zodat het framework eerst zijn eigen verwerking afrondt.
			if (m) setTimeout(function () { vervangVeld(el, m[0], +m[1], m[2].toLowerCase()); }, 0);
			return;
		}
		if (!el.isContentEditable) return;
		var sel = doc.getSelection();
		if (!sel || !sel.rangeCount || !sel.isCollapsed || !sel.focusNode || sel.focusNode.nodeType !== 3) return;
		m = sel.focusNode.data.slice(0, sel.focusOffset).match(PATROON);
		if (m) setTimeout(function () { vervangBewerkbaar(doc, m[0], +m[1], m[2].toLowerCase()); }, 0);
	}

	// Het vlaggetje staat op het <html>-element: na document.open() (TinyMCE) is dat nieuw en koppelen we opnieuw.
	function koppel(doc) {
		var wortel = doc && doc.documentElement;
		if (!wortel || wortel.dpIpsum) return;
		wortel.dpIpsum = true;
		doc.addEventListener('input', bijInvoer, true);
		Array.prototype.forEach.call(doc.querySelectorAll('iframe'), koppelFrame);
		new doc.defaultView.MutationObserver(function (lijst) {
			lijst.forEach(function (m) {
				Array.prototype.forEach.call(m.addedNodes, function (k) {
					if (k.nodeType !== 1) return;
					if (k.tagName === 'IFRAME') koppelFrame(k);
					else if (k.querySelectorAll) Array.prototype.forEach.call(k.querySelectorAll('iframe'), koppelFrame);
				});
			});
		}).observe(wortel, { childList: true, subtree: true });
	}

	function koppelFrame(frame) {
		var probeer = function () { try { koppel(frame.contentDocument); } catch (e) { /* ander domein */ } };
		probeer();
		frame.addEventListener('load', probeer);
	}

	function tinymceKoppelen(tm) {
		if (!tm || !tm.on || tm.dpIpsum) return;
		tm.dpIpsum = true;
		var perEditor = function (ed) {
			var doe = function () { try { koppel(ed.getDoc()); } catch (e) {} };
			if (ed.initialized) doe();
			ed.on('init', doe);
		};
		(tm.editors || []).forEach(perEditor);
		tm.on('AddEditor', function (e) { perEditor(e.editor); });
	}

	koppel(document);
	tinymceKoppelen(window.tinymce);
	// TinyMCE kan later laden dan dit script (JetEngine, Bricks).
	if (!window.tinymce) {
		var pogingen = 0, t = setInterval(function () { if (window.tinymce || ++pogingen > 20) { clearInterval(t); tinymceKoppelen(window.tinymce); } }, 500);
	}
})();
