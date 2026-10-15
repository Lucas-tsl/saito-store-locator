=== Saito Store Locator ===
Contributors: lucastsl
Tags: store locator, map, stores, dealer locator, google sheets
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Map of your points of sale: search by town, postcode or position, indexable store pages, CSV import and Google Sheets sync.

== Description ==

Saito Store Locator shows **where to find your products**: a full-width map with the list of your points of sale, a search field, and a page for each store that search engines can index.

**For visitors**

* Search by **town, postcode** (French communes are built in, even when typed without the leading zero) or **store name**, with keyboard navigation (ARIA combobox).
* **"Around me"** button: the position is never requested on page load, only when the visitor asks for it.
* **"Search this area"** when the map is moved or zoomed, and **"Show more"** under the list.
* Each store card shows the distance, the address, an **"Open / Closed"** state computed from the opening hours (in the site's timezone), the phone number and a **directions menu** (Google Maps, Apple Maps or Waze — the last app used is offered first).
* A **store sheet** opens in a dialog with its own URL (`?magasin=…`): weekly opening hours, services, website and directions. The Back and Forward buttons work and the link can be shared.
* Filters by **brand** and by **service**, marker clustering, linked hover between the list and the map.
* Mobile: Map / List switch, sticky search bar, two-finger map panning. Desktop: map zoom with Ctrl (⌘ on Mac) + wheel only, so the page keeps scrolling.
* Black, white and grey interface in a light (default) or dark theme that blends into any site; animations are disabled when the visitor prefers reduced motion.

**Local SEO**

* Every store has its own indexable URL, with a title, a description, a canonical URL and `schema.org/Store` structured data (opening hours, phone, brand, coordinates), each reseller being described as selling your brand.
* Store pages are added to the WordPress sitemap (`wp-sitemap.xml`) or to Yoast SEO's page sitemap; titles, descriptions and canonical URLs are passed to Yoast SEO and Rank Math.
* `[saito_store_locator_list]` prints the full list of stores in HTML, by country and town.

**For editors**

* **CSV import** with a report before anything goes live: ignored lines and why, duplicates, missing postcodes, positions computed from the address. Excel files (semicolons, Windows-1252) are accepted.
* **Google Sheets sync**: paste the sheet address, click "Sync now" — or let it sync every day. An automatic sync is blocked if the sheet suddenly contains less than half of the stores, and an e-mail alert is sent when it fails (at most once a day).
* For French stores without coordinates, the position is computed from the address (French public geocoding service, see below).
* The 10 previous lists are kept and can be restored in one click; the current list can be downloaded as CSV.

**Requirements and language**

The admin interface and visitor texts are in French and ready for translation. The postcode / town search is built for France (stores in other countries can be found by name or town). Opening hours are read in French ("Lundi-Samedi 10h-19h ; Dimanche fermé").

== Installation ==

1. Upload the `saito-store-locator` folder to `/wp-content/plugins/`, or install the plugin from the **Plugins → Add New** screen.
2. Activate the plugin.
3. Go to **Points de vente → Magasins** and import your stores (CSV file, or the address of a Google Sheets sheet in **Points de vente → Paramètres**).
4. Add the `[saito_store_locator]` shortcode to a page.

Recognized CSV columns: `name`, `latitude`, `longitude` (required), `id_store`, `active`, `address1`, `address2`, `postcode`, `city`, `country`, `phone`, `website`, `enseigne` (brand), `services`, `horaires` (opening hours), `icone` (`signature` for a highlighted marker). French column names (`nom`, `ville`, `code postal`…) are accepted too.

== Frequently Asked Questions ==

= Which shortcode options are available? =

`[saito_store_locator results="6"]` shows 6 stores after a search instead of the default setting. `theme="dark"` switches to the dark theme on this page. `width="full"` uses the full page width and `width="content"` the text width (default: the theme's wide width).

= Do I need an API key? =

No. Without a key the map uses OpenStreetMap tiles, which are fine for moderate traffic. For a busy site, create a free MapTiler account and paste its key in the settings; restrict the key to your domain in your MapTiler account.

= Where are the stores stored? =

In `wp-content/uploads/saito-store-locator/` (a public `stores.json` file read by the map, and private backups), so they survive plugin updates. Settings are stored as WordPress options.

= What is stored in the visitor's browser? =

The last search (shown again when the visitor comes back, for 30 days) and the last directions app used, in the browser's local storage. Nothing is sent to your server or to a third party.

= What happens to my data if I delete the plugin? =

Deleting the plugin from the Plugins screen removes its settings, its scheduled task and the `wp-content/uploads/saito-store-locator/` folder. Deactivating it keeps everything.

== External services ==

This plugin connects to the following third-party services. No data is sent to any of them until the feature that needs it is used.

**Map tiles — OpenStreetMap** (default)
When a page with the store locator is displayed, the visitor's browser loads the map images from `tile.openstreetmap.org`. The OpenStreetMap Foundation receives the visitor's IP address, browser information and the area of the map being viewed.
Tile usage policy: https://operations.osmfoundation.org/policies/tiles/ — Privacy policy: https://osmfoundation.org/wiki/Privacy_Policy

**Map tiles — MapTiler** (only if a MapTiler API key is set in the settings)
The map images are then loaded from `api.maptiler.com` instead of OpenStreetMap, with the same data (IP address, browser information, map area) and the API key.
Terms: https://www.maptiler.com/terms/ — Privacy policy: https://www.maptiler.com/privacy-policy/

**Geocoding — Géoplateforme (IGN, French public service)**
When stores located in France are imported without latitude/longitude, the server sends their postal address to `data.geopf.fr/geocodage` to compute their position (at most 50 addresses per import, cached for 30 days). Only store addresses are sent, never visitor data.
Terms and licences: https://geoservices.ign.fr/cgu-licences — Service: https://geoservices.ign.fr/documentation/services/services-geoplateforme/geocodage

**Google Sheets** (only if a sheet address is set in the settings)
When an administrator clicks "Sync now", or once a day if the automatic sync is enabled, the server downloads the CSV export of the configured sheet from `docs.google.com`. No data is sent apart from the request itself.
Terms: https://policies.google.com/terms — Privacy policy: https://policies.google.com/privacy

**Directions links**
The "J'y vais" menu contains links to Google Maps, Apple Maps and Waze with the store's coordinates (and the visitor's position if they used "Around me"). Nothing is sent unless the visitor clicks one of these links.
Google: https://policies.google.com/privacy — Apple: https://www.apple.com/legal/privacy/ — Waze: https://www.waze.com/legal/privacy

== Screenshots ==

1. The store locator on a desktop: search, map with clustered markers and the list of the nearest stores.
2. A store sheet with its own URL: address, opening hours, open/closed state and directions menu.
3. Mobile view, with the Map / List switch.
4. The import report shown before the new list goes live.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.

== Third-party libraries and data ==

This plugin bundles the following open-source software and open data, unmodified:

* Leaflet 1.9.4 (BSD 2-Clause License) — https://github.com/Leaflet/Leaflet
* Leaflet.markercluster 1.5.3 (MIT License) — https://github.com/Leaflet/Leaflet.markercluster

The files in `assets/vendor/` are the official distribution builds of these projects, with their license files. Their full source code is available at the addresses above.

`assets/data/communes.min.json` (French towns, postcodes and coordinates) is derived from the "Base officielle des codes postaux" published by La Poste on data.gouv.fr under the Licence Ouverte / Open Licence 2.0 (Etalab): https://www.data.gouv.fr/datasets/base-officielle-des-codes-postaux — Source: La Poste. It was reduced to five fields per entry (town, postcode, delivery line, latitude, longitude), and the coordinates of two towns missing from the source were added.
