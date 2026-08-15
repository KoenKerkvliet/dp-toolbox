# DP Toolbox

Design Pixels gereedschapskist voor WordPress. Een modulaire verzameling van site-tools, ontwikkeld voor Design Pixels klant-sites.

## Modules

- **Activity Log** — Log van logins, content-wijzigingen, gebruikers en plugins
- **Quick Setup** — One-click configuratie voor nieuwe sites
- **Plugin Installer** — Installeer aanbevolen plugins uit WordPress.org
- **Dashboard Widgets** — Aangepaste dashboard-widgets met Independent Analytics integratie
- **Site Navigator** — Snelnavigatie via de admin bar voor Bricks Builder
- **Role Manager** — Beheer wat rollen kunnen zien
- **Branding** — Custom kleur voor admin-sidebar iconen
- **SMTP Mailer** — Externe SMTP-server configureren
- **Redirects** — 301/302 redirects met regex-ondersteuning
- **Security Headers** — HTTP-beveiligingsheaders
- **Custom Login URL** — Verplaats wp-login.php naar eigen URL
- **Maintenance Mode** — Site offline voor bezoekers
- **Revision Limiter** — Beperk aantal post-revisies
- **Etch GSAP** — GSAP + ScrollTrigger op Etch-sites, met een kleine set scroll-animaties via `data-dp-anim` / `data-dp-stagger`. Alleen in te schakelen wanneer Etch actief is; op Bricks-sites wordt er niets geladen.
- **Attribute Pricing** (WooCommerce) — Extra options tab op simpele producten met meerprijs per attribuut-waarde, zonder variaties
- **Free Shipping Bar** (WooCommerce) — Voortgangsbalk "Nog €X tot gratis verzending" in cart en mini-cart
- **Low Stock Urgency** (WooCommerce) — "Nog X stuks beschikbaar!" indicator op de productpagina bij lage voorraad
- **Sticky Add to Cart** (WooCommerce) — Vaste add-to-cart bar onderin op mobiel zodra de hoofdknop uit beeld scrollt
- ... en meer

Zie de volledige lijst op DP Toolbox → Modules na installatie.

## Installatie

Download de laatste release-ZIP en upload via WordPress → Plugins → Nieuwe plugin → Upload.

Voor automatische updates vanaf GitHub: installeer [Git Updater](https://git-updater.com/).

## Versiebeheer

Deze plugin volgt semantic versioning. De huidige versie staat in de plugin-header van `dp-toolbox.php`.

## Licentie

DP Toolbox zelf is GPL-2.0-or-later (zie `LICENSE`).

**Uitzondering — meegeleverde software van derden.** De module `etch-gsap` bundelt GSAP en
ScrollTrigger 3.13.0 (`modules/etch-gsap/assets/gsap.min.js`, `ScrollTrigger.min.js`).
Die bestanden zijn © GreenSock, vallen onder de GSAP-standaardlicentie
(https://gsap.com/standard-license) en **niet** onder de GPL van deze plugin. Gebruik is
gratis, ook commercieel; ze mogen niet onder GPL-voorwaarden worden doorgegeven.
Alle overige bestanden in de module (`etch-gsap.php`, `dp-motion.js`, `dp-motion.css`)
zijn wél GPL.

## Auteur

Design Pixels — https://designpixels.nl
