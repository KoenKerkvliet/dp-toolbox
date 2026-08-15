/**
 * DP Motion — kleine, terughoudende scroll-animaties op GSAP + ScrollTrigger.
 *
 * Gebruik in Etch: zet een data-attribuut op het element.
 *   data-dp-anim="fade-up"          fade | fade-up | fade-down | fade-left | fade-right | scale
 *   data-dp-anim-delay="0.2"        vertraging in seconden (optioneel)
 *   data-dp-stagger                 op een OUDER: animeer z'n directe kinderen na elkaar
 *   data-dp-stagger="0.2"           idem, met eigen tussenpauze
 *
 * Ontwerpregels (bewust beperkt):
 *   - alleen opacity + transform, nooit layout-properties
 *   - één gedeelde duur/easing voor de hele site
 *   - elke animatie speelt één keer
 *   - respecteert prefers-reduced-motion
 *   - faalt zichtbaar: gaat er iets mis, dan staat alles gewoon zichtbaar
 */
(function () {
	'use strict';

	function init() {
		var g = window.gsap;
		var ST = window.ScrollTrigger;

		// Geen GSAP => niets doen. De verbergende CSS hangt aan .dp-motion,
		// die we hieronder pas zetten, dus de pagina blijft gewoon zichtbaar.
		if (!g || !ST) return;

		// Etch-editor / builder draait in een iframe. Daar willen we geen
		// animaties: de gebruiker moet z'n content zien staan.
		if (window.self !== window.top) return;

		if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

		var nodes = document.querySelectorAll('[data-dp-anim], [data-dp-stagger]');
		if (!nodes.length) return;

		try {
			g.registerPlugin(ST);
		} catch (e) {
			return;
		}

		var cfg = window.dpMotionConfig || {};
		var DUR = cfg.duration || 1;
		var EASE = cfg.ease || 'power2.out';
		var DIST = cfg.distance || 24;
		var STAG = cfg.stagger || 0.14;
		var START = cfg.start || 'top 85%';

		var FROM = {
			'fade': { opacity: 0 },
			'fade-up': { opacity: 0, y: DIST },
			'fade-down': { opacity: 0, y: -DIST },
			'fade-left': { opacity: 0, x: DIST },
			'fade-right': { opacity: 0, x: -DIST },
			'scale': { opacity: 0, scale: 0.96 }
		};

		var revealed = [];

		function reveal(els) {
			els.forEach(function (el) { el.classList.add('is-dp-revealed'); });
			// Inline transform van GSAP wissen, anders wint die van je CSS :hover.
			g.set(els, { clearProps: 'opacity,transform' });
		}

		function run(els, name, delay, stagger, trigger) {
			var from = FROM[name] || FROM['fade-up'];
			g.set(els, from);
			revealed.push(els);

			ST.create({
				trigger: trigger,
				start: START,
				once: true,
				onEnter: function () {
					g.to(els, {
						opacity: 1, x: 0, y: 0, scale: 1,
						duration: DUR,
						ease: EASE,
						delay: delay,
						stagger: stagger,
						onComplete: function () { reveal(els); }
					});
				}
			});
		}

		document.documentElement.classList.add('dp-motion');

		try {
			var claimed = [];

			// 1. Groepen: een ouder met data-dp-stagger animeert z'n directe kinderen.
			document.querySelectorAll('[data-dp-stagger]').forEach(function (parent) {
				var kids = Array.prototype.slice.call(parent.children);
				if (!kids.length) return;
				kids.forEach(function (k) { claimed.push(k); });

				var step = parseFloat(parent.getAttribute('data-dp-stagger'));
				run(
					kids,
					parent.getAttribute('data-dp-anim') || 'fade-up',
					parseFloat(parent.getAttribute('data-dp-anim-delay')) || 0,
					isNaN(step) ? STAG : step,
					parent
				);
			});

			// 2. Losse elementen, voor zover niet al als groepskind afgehandeld.
			document.querySelectorAll('[data-dp-anim]').forEach(function (el) {
				if (el.hasAttribute('data-dp-stagger')) return;
				if (claimed.indexOf(el) !== -1) return;
				run(
					[el],
					el.getAttribute('data-dp-anim'),
					parseFloat(el.getAttribute('data-dp-anim-delay')) || 0,
					0,
					el
				);
			});

			ST.refresh();
		} catch (e) {
			// Iets ging mis tijdens het opzetten: alles alsnog zichtbaar maken.
			document.documentElement.classList.remove('dp-motion');
			return;
		}

		// Vangnet. Mocht een trigger door een rare layout nooit vuren, dan staat
		// content na 4 seconden alsnog gewoon op het scherm.
		window.setTimeout(function () {
			revealed.forEach(function (els) {
				var stuck = els.filter(function (el) { return !el.classList.contains('is-dp-revealed'); });
				if (stuck.length && stuck[0].getBoundingClientRect().top < window.innerHeight) {
					g.to(stuck, {
						opacity: 1, x: 0, y: 0, scale: 1,
						duration: 0.4, ease: EASE,
						onComplete: function () { reveal(stuck); }
					});
				}
			});
		}, 4000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
