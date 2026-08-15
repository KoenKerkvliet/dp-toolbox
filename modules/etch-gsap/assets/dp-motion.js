/**
 * DP Motion — kleine, terughoudende scroll-animaties.
 *
 * Gebruik in Etch: zet een data-attribuut op het element.
 *   data-dp-anim="fade-up"          fade | fade-up | fade-down | fade-left | fade-right | scale
 *   data-dp-anim-delay="0.2"        vertraging in seconden (optioneel)
 *   data-dp-stagger                 op een OUDER: animeer z'n directe kinderen na elkaar
 *   data-dp-stagger="0.2"           idem, met eigen tussenpauze
 *
 * Triggeren gebeurt met IntersectionObserver, niet met ScrollTrigger. Dat is
 * bewust: ScrollTrigger rekent scrollposities één keer door en die kloppen niet
 * meer zodra afbeeldingen naladen en de layout verschuift — met permanent
 * onzichtbare content tot gevolg. IntersectionObserver laat de browser dat
 * bijhouden en is immuun voor layoutverschuivingen.
 *
 * ScrollTrigger wordt wél meegeladen door de module, zodat je hem in Etch kunt
 * gebruiken voor eigen animaties via het script-attribuut van een element.
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

	var g = window.gsap;
	var root = document.documentElement;
	var touched = [];

	/**
	 * Alles zichtbaar maken en ophouden. Verwijdert zowel de verbergende class
	 * als de inline begintoestand die gsap.set() al gezet kan hebben — anders
	 * blijft content alsnog op opacity 0 staan.
	 */
	function abort() {
		root.classList.remove('dp-motion');
		if (touched.length) {
			try { g.set(touched, { clearProps: 'opacity,transform' }); } catch (e) {}
			touched.length = 0;
		}
	}

	// Zonder GSAP, in een editor-iframe, bij reduced-motion of zonder
	// IntersectionObserver: niets verbergen, niets animeren.
	if (!g) return;
	if (window.self !== window.top) return;
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
	if (!('IntersectionObserver' in window)) return;

	// Meteen zetten — dit script staat in de <head>, dus de begintoestand geldt
	// al vóór de eerste paint. Geen flits van zichtbaar-dan-verborgen.
	root.classList.add('dp-motion');

	function init() {
		var cfg = window.dpMotionConfig || {};
		var DUR = cfg.duration || 1;
		var EASE = cfg.ease || 'power2.out';
		var DIST = cfg.distance || 24;
		var STAG = cfg.stagger || 0.14;
		var MARGIN = cfg.rootMargin || '0px 0px -10% 0px';

		var FROM = {
			'fade': { opacity: 0 },
			'fade-up': { opacity: 0, y: DIST },
			'fade-down': { opacity: 0, y: -DIST },
			'fade-left': { opacity: 0, x: DIST },
			'fade-right': { opacity: 0, x: -DIST },
			'scale': { opacity: 0, scale: 0.96 }
		};

		function reveal(els) {
			els.forEach(function (el) { el.classList.add('is-dp-revealed'); });
			// Inline transform van GSAP wissen, anders wint die van je CSS :hover.
			g.set(els, { clearProps: 'opacity,transform' });
		}

		try {
			var groups = new Map();
			var claimed = [];

			function add(trigger, els, name, delay, stagger) {
				g.set(els, FROM[name] || FROM['fade-up']);
				els.forEach(function (el) { touched.push(el); });
				groups.set(trigger, { els: els, delay: delay, stagger: stagger });
			}

			// 1. Groepen: een ouder met data-dp-stagger animeert z'n directe kinderen.
			document.querySelectorAll('[data-dp-stagger]').forEach(function (parent) {
				var kids = Array.prototype.slice.call(parent.children);
				if (!kids.length) return;
				kids.forEach(function (k) { claimed.push(k); });

				var step = parseFloat(parent.getAttribute('data-dp-stagger'));
				add(
					parent,
					kids,
					parent.getAttribute('data-dp-anim') || 'fade-up',
					parseFloat(parent.getAttribute('data-dp-anim-delay')) || 0,
					isNaN(step) ? STAG : step
				);
			});

			// 2. Losse elementen, voor zover niet al als groepskind afgehandeld.
			document.querySelectorAll('[data-dp-anim]').forEach(function (el) {
				if (el.hasAttribute('data-dp-stagger')) return;
				if (claimed.indexOf(el) !== -1) return;
				add(el, [el], el.getAttribute('data-dp-anim'), parseFloat(el.getAttribute('data-dp-anim-delay')) || 0, 0);
			});

			// Niets te animeren op deze pagina: verbergende CSS weer uitzetten.
			if (!groups.size) {
				abort();
				return;
			}

			var delivered = false;

			var io = new IntersectionObserver(function (entries) {
				delivered = true;
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) return;
					var group = groups.get(entry.target);
					io.unobserve(entry.target);
					if (!group) return;
					groups.delete(entry.target);

					var trigger = entry.target;
					g.to(group.els, {
						opacity: 1, x: 0, y: 0, scale: 1,
						duration: DUR,
						ease: EASE,
						delay: group.delay,
						stagger: group.stagger,
						onComplete: function () {
							reveal(group.els);
							// Ook de trigger zelf markeren: bij een stagger-groep
							// is dat de container, die niet in group.els zit.
							trigger.classList.add('is-dp-revealed');
						}
					});
				});
			}, { rootMargin: MARGIN, threshold: 0 });

			groups.forEach(function (_group, trigger) { io.observe(trigger); });

			/**
			 * Vangnet tegen permanent onzichtbare content.
			 *
			 * In een normaal renderende pagina levert IntersectionObserver
			 * meteen na het observeren een eerste callback voor élk element —
			 * ook voor elementen die nog niet in beeld zijn. Blijft die callback
			 * uit terwijl het document zichtbaar is, dan draait de
			 * rendering-pipeline niet: een screenshot-tool, een print-renderer,
			 * een headless capture of een achtergrondtab. In dat geval is
			 * gewoon alles tonen altijd beter dan witte vlakken.
			 *
			 * Dit trapt niet af wanneer de bezoeker simpelweg nog niet gescrold
			 * heeft — de eerste callback komt dan namelijk wél.
			 */
			var checkDelivery = function () {
				if (delivered) return;
				if (document.visibilityState !== 'visible') return;
				abort();
			};

			window.setTimeout(checkDelivery, 3000);
			document.addEventListener('visibilitychange', function () {
				if (document.visibilityState === 'visible') {
					window.setTimeout(checkDelivery, 3000);
				}
			});
		} catch (e) {
			abort();
			return;
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
