/**
 * Saito Store Locator — carte et recherche des points de vente.
 *
 * Configuration fournie par PHP dans window.saitoStoreLocator (voir includes/frontend.php).
 * Aucune variable globale n'est exposée et aucun HTML n'est construit par concaténation :
 * toutes les données (magasins, communes) sont insérées avec textContent.
 */
(function () {
	'use strict';

	var CFG = window.saitoStoreLocator;
	if (!CFG || typeof window.L === 'undefined') {
		return;
	}
	var L = window.L;

	// Traductions (wp-i18n, fichiers JSON de translate.wordpress.org). Repli : texte source.
	var i18n = window.wp && window.wp.i18n;
	var __ = i18n ? i18n.__ : function (text) {
		return text;
	};
	var _n = i18n ? i18n._n : function (single, plural, number) {
		return number === 1 ? single : plural;
	};
	var sprintf = i18n ? i18n.sprintf : function (format) {
		var args = arguments;
		var next = 1;
		return String(format).replace(/%(\d+)\$[sd]|%[sd]/g, function (match, position) {
			return String(args[position ? parseInt(position, 10) : next++]);
		});
	};

	var FRANCE_CENTER = [46.614985, 2.4636];
	var FOCUS_ZOOM = 14;
	var MAX_PLACES = 8;
	var MAX_STORE_SUGGESTIONS = 4;

	/* ------------------------------------------------------------------
	 * Utilitaires
	 * ---------------------------------------------------------------- */

	function normalize(text) {
		return String(text || '')
			.normalize('NFD')
			.replace(/[̀-ͯ]/g, '')
			.toLowerCase()
			.replace(/[-'’_.,/()]/g, ' ')
			.replace(/\bste\b/g, 'sainte')
			.replace(/\bst\b/g, 'saint')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function isFrance(country) {
		var c = normalize(country);
		return c === '' || c === 'france' || c === 'fr';
	}

	function distanceKm(lat1, lon1, lat2, lon2) {
		var rad = Math.PI / 180;
		var dLat = (lat2 - lat1) * rad;
		var dLon = (lon2 - lon1) * rad;
		var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
			Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
		return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
	}

	function formatDistance(km) {
		if (km < 1) {
			return Math.max(10, Math.round(km * 100) * 10) + ' m';
		}
		return km.toLocaleString('fr-FR', { maximumFractionDigits: km < 10 ? 1 : 0 }) + ' km';
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null && text !== '') {
			node.textContent = text;
		}
		return node;
	}

	function debounce(fn, wait) {
		var timer;
		return function () {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	function fetchJSON(url) {
		return fetch(url, { credentials: 'same-origin' }).then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status + ' sur ' + url);
			}
			return response.json();
		});
	}

	function rankText(text, query) {
		if (!text) {
			return -1;
		}
		if (text === query) {
			return 0;
		}
		if (text.indexOf(query) === 0) {
			return 1;
		}
		if (text.indexOf(' ' + query) !== -1) {
			return 2;
		}
		return text.indexOf(query) !== -1 ? 3 : -1;
	}

	/* ------------------------------------------------------------------
	 * Données (chargées une seule fois, partagées entre les instances)
	 * ---------------------------------------------------------------- */

	var storesPromise = null;
	var communesPromise = null;

	function loadStores() {
		if (!storesPromise) {
			storesPromise = fetchJSON(CFG.storesUrl)
				.then(function (list) {
					var stores = [];
					(Array.isArray(list) ? list : []).forEach(function (s) {
						var lat = parseFloat(s.lat);
						var lng = parseFloat(s.lng);
						if (!isFinite(lat) || !isFinite(lng)) {
							return;
						}
						stores.push({
							index: stores.length,
							name: String(s.name || ''),
							address1: String(s.address1 || ''),
							address2: String(s.address2 || ''),
							postcode: String(s.postcode || ''),
							city: String(s.city || ''),
							country: String(s.country || ''),
							phone: String(s.phone || ''),
							website: String(s.website || ''),
							icone: String(s.icone || ''),
							brand: String(s.brand || ''),
							services: Array.isArray(s.services) ? s.services.map(String).filter(Boolean) : [],
							slug: String(s.slug || ''),
							hours: Array.isArray(s.hours) ? s.hours : null,
							hoursText: String(s.hours_text || ''),
							lat: lat,
							lng: lng,
							n: normalize(s.name),
							nCity: normalize(s.city)
						});
					});
					stores.forEach(function (store) {
						store.brandName = detectBrand(store);
						store.brandLabel = store.brandName || OTHER_BRAND;
						store.serviceList = detectServices(store);
						store.title = titleCase(store.name);
						store.short = shortTitle(store);
					});
					return stores;
				})
				.catch(function (error) {
					storesPromise = null;
					throw error;
				});
		}
		return storesPromise;
	}

	function loadCommunes() {
		if (!communesPromise) {
			communesPromise = fetchJSON(CFG.communesUrl)
				.then(function (rows) {
					return rows.map(function (r) {
						return { name: r[0], cp: r[1], l5: r[2], lat: r[3], lng: r[4], n: normalize(r[0]), nL5: normalize(r[2]) };
					});
				})
				.catch(function (error) {
					communesPromise = null;
					throw error;
				});
		}
		return communesPromise;
	}

	/** Villes des magasins hors de France (Belgique, Luxembourg, Suisse…), absentes de la base des communes. */
	function foreignPlaces(stores) {
		var seen = {};
		var places = [];
		stores.forEach(function (s) {
			if (isFrance(s.country) || !s.city) {
				return;
			}
			var key = s.nCity + '|' + s.postcode;
			if (seen[key]) {
				return;
			}
			seen[key] = true;
			places.push({ name: s.city, cp: s.postcode, l5: '', country: s.country, lat: s.lat, lng: s.lng, n: s.nCity, nL5: '' });
		});
		return places;
	}

	/** Recherche dans les communes, les villes étrangères et les noms de magasins. */
	function search(query, communes, stores) {
		var q = normalize(query);
		if (q.length < 2) {
			return [];
		}
		var digits = /^\d+$/.test(q);
		var q5 = digits && q.length === 4 ? '0' + q : null;
		var places = [];
		var seen = {};

		communes.concat(foreignPlaces(stores)).forEach(function (c) {
			var rank = -1;
			var viaL5 = false;
			if (digits) {
				if (c.cp === q || c.cp === q5) {
					rank = 0;
				} else if (c.cp.indexOf(q) === 0) {
					rank = 1;
				}
			} else {
				rank = rankText(c.n, q);
				if (rank < 0 && c.nL5) {
					var rankL5 = rankText(c.nL5, q);
					if (rankL5 >= 0) {
						viaL5 = true;
						rank = rankL5 + 4; // Lieu-dit : après les noms de communes.
					}
				}
			}
			if (rank < 0) {
				return;
			}
			var key = c.n + '|' + c.cp + '|' + (viaL5 ? c.nL5 : '');
			if (seen[key]) {
				return;
			}
			seen[key] = true;
			places.push({ type: 'place', place: c, rank: rank, viaL5: viaL5 });
		});

		places.sort(function (a, b) {
			return a.rank - b.rank || a.place.name.length - b.place.name.length || (a.place.cp < b.place.cp ? -1 : 1);
		});

		var storeHits = [];
		if (!digits) {
			stores.forEach(function (s) {
				var rank = rankText(s.n, q);
				if (rank >= 0) {
					storeHits.push({ type: 'store', store: s, rank: rank });
				}
			});
			storeHits.sort(function (a, b) {
				return a.rank - b.rank || a.store.name.localeCompare(b.store.name, 'fr');
			});
		}

		return places.slice(0, MAX_PLACES).concat(storeHits.slice(0, MAX_STORE_SUGGESTIONS));
	}
	/* ------------------------------------------------------------------
	 * Mise en forme : enseigne, noms, téléphone, horaires
	 * (mêmes règles que includes/store.php)
	 * ---------------------------------------------------------------- */

	var OTHER_BRAND = (CFG.labels && CFG.labels.otherBrand) || __('Autres', 'saito-store-locator');
	var SIGNATURE = (CFG.labels && CFG.labels.signature) || '';
	var DAY_NAMES = CFG.dayNames || ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

	/** « MAISON DUPONT LE BOUSCAT » → « Maison Dupont Le Bouscat » (texte déjà en casse mixte : inchangé). */
	function titleCase(text) {
		text = String(text || '').replace(/\s+/g, ' ').trim();
		if (!text || /\p{Ll}/u.test(text)) {
			return text;
		}
		var keepUpper = ['cc', 'ri', 'sas', 'zac', 'zi', 'za', 'cv'];
		var small = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'en', 'sur', 'sous', 'aux', 'au', 'a', 'lès'];
		var parts = text.split(/([\s\-'’]+)/);
		var prevDelim = '';
		return parts.map(function (part, i) {
			if (i % 2 === 1) {
				prevDelim = part;
				return part;
			}
			var lower = part.toLowerCase();
			var next = parts[i + 1] || '';
			if (keepUpper.indexOf(lower) !== -1) {
				return lower.toUpperCase();
			}
			if (i > 0 && (lower === 'd' || lower === 'l') && /['’]/.test(next)) {
				return lower;
			}
			if ((i > 0 || lower === 'l') && (lower === 'd' || lower === 'l') && next === ' ' && /^[aeiouyhàâéèêëîïôûü]/i.test(parts[i + 2] || '')) {
				parts[i + 1] = ''; // « D ORNON » saisi sans apostrophe → d'Ornon.
				return (i === 0 ? lower.toUpperCase() : lower) + "'";
			}
			if (i > 0 && prevDelim.trim() === '-' && small.indexOf(lower) !== -1) {
				return lower;
			}
			return lower.charAt(0).toUpperCase() + lower.slice(1);
		}).join('');
	}

	/** Enseigne : colonne « enseigne », sinon début du nom comparé à la liste des réglages, sinon ''. */
	function detectBrand(store) {
		if (store.brand) {
			return store.brand;
		}
		var name = normalize(store.name);
		var brands = CFG.brands || [];
		for (var i = 0; i < brands.length; i++) {
			var b = normalize(brands[i]);
			if (b && name.indexOf(b) === 0) {
				return brands[i];
			}
		}
		return '';
	}

	/** Services : colonne « services » + libellé des magasins mis en avant (icône signature), s'il est réglé. */
	function detectServices(store) {
		var list = store.services.slice();
		if (SIGNATURE && store.icone === 'signature' && list.indexOf(SIGNATURE) === -1) {
			list.push(SIGNATURE);
		}
		return list;
	}

	/** Nom sans l'enseigne (« Le Bouscat ») quand l'enseigne est affichée à part. */
	function shortTitle(store) {
		var full = titleCase(store.name);
		if (store.brandName) {
			var b = normalize(store.brandName);
			if (normalize(full).indexOf(b) === 0) {
				var rest = full.slice(store.brandName.length).trim();
				if (rest.length > 1) {
					return rest;
				}
			}
		}
		return full;
	}

	function formatPhone(phone) {
		var digits = String(phone || '').replace(/\D/g, '');
		if (digits.length === 10 && digits.charAt(0) === '0') {
			return digits.replace(/(\d{2})(?=\d)/g, '$1 ');
		}
		return String(phone || '').trim();
	}

	function addressLine(store) {
		return [store.address1, store.address2, (store.postcode + ' ' + titleCase(store.city)).trim(), isFrance(store.country) ? '' : store.country]
			.filter(Boolean)
			.join(', ');
	}

	function formatTime(hhmm) {
		var p = String(hhmm).split(':');
		return parseInt(p[0], 10) + 'h' + (p[1] && p[1] !== '00' ? p[1] : '');
	}

	/** Jour (0 = lundi) et minutes écoulées, dans le fuseau horaire du site (réglages WordPress). */
	function siteNow() {
		try {
			var parts = new Intl.DateTimeFormat('en-GB', { timeZone: tzName(), weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
			var get = function (type) {
				var found = parts.filter(function (p) {
					return p.type === type;
				})[0];
				return found ? found.value : '';
			};
			var day = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(get('weekday'));
			return { day: day, minutes: parseInt(get('hour'), 10) * 60 + parseInt(get('minute'), 10) };
		} catch {
			var d = new Date();
			return { day: (d.getDay() + 6) % 7, minutes: d.getHours() * 60 + d.getMinutes() };
		}
	}

	/** Fuseau IANA du site ; un décalage fixe (« +02:00 ») est converti en Etc/GMT∓N quand c'est possible. */
	function tzName() {
		var tz = CFG.timezone || 'UTC';
		var m = /^([+-])(\d{2}):00$/.exec(tz);
		if (m) {
			var hours = parseInt(m[2], 10);
			return hours === 0 ? 'UTC' : 'Etc/GMT' + (m[1] === '+' ? '-' : '+') + hours;
		}
		return tz;
	}

	function toMinutes(hhmm) {
		var p = String(hhmm).split(':');
		return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
	}

	/** « Ouvert · ferme à 19h » / « Fermé · ouvre demain à 10h », ou null sans horaires lisibles. */
	function openState(hours) {
		if (!Array.isArray(hours) || hours.length !== 7) {
			return null;
		}
		var now = siteNow();
		var today = hours[now.day] || [];
		for (var i = 0; i < today.length; i++) {
			if (now.minutes >= toMinutes(today[i][0]) && now.minutes < toMinutes(today[i][1])) {
				/* translators: %s: heure de fermeture. */
				return { open: true, text: sprintf(__('Ouvert · ferme à %s', 'saito-store-locator'), formatTime(today[i][1])) };
			}
		}
		for (var j = 0; j < today.length; j++) {
			if (toMinutes(today[j][0]) > now.minutes) {
				/* translators: %s: heure d'ouverture. */
				return { open: false, text: sprintf(__('Fermé · ouvre à %s', 'saito-store-locator'), formatTime(today[j][0])) };
			}
		}
		for (var k = 1; k <= 7; k++) {
			var slots = hours[(now.day + k) % 7] || [];
			if (slots.length) {
				var text = k === 1
					/* translators: %s: heure d'ouverture. */
					? sprintf(__('Fermé · ouvre demain à %s', 'saito-store-locator'), formatTime(slots[0][0]))
					/* translators: 1: jour de la semaine, 2: heure d'ouverture. */
					: sprintf(__('Fermé · ouvre %1$s à %2$s', 'saito-store-locator'), DAY_NAMES[(now.day + k) % 7].toLowerCase(), formatTime(slots[0][0]));
				return { open: false, text: text };
			}
		}
		return { open: false, text: __('Fermé', 'saito-store-locator') };
	}

	function isMobile() {
		return window.matchMedia('(max-width: 899px)').matches;
	}

	function prefersReducedMotion() {
		return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	/* ------------------------------------------------------------------
	 * Marqueurs (noir et blanc, suivent le thème via les variables CSS)
	 * ---------------------------------------------------------------- */

	var PIN_PATH = 'M15 1C7.3 1 1 7.1 1 14.8 1 25.3 15 39 15 39s14-13.7 14-24.2C29 7.1 22.7 1 15 1z';
	var STAR_PATH = 'M15 8.6l1.9 3.9 4.3.6-3.1 3 .7 4.3-3.8-2-3.8 2 .7-4.3-3.1-3 4.3-.6z';
	var pinCache = {};

	function pinIcon(kind) {
		if (!pinCache[kind]) {
			var inner = kind === 'signature'
				? '<path class="saito-sl-pin__dot" d="' + STAR_PATH + '"/>'
				: '<circle class="saito-sl-pin__dot" cx="15" cy="15" r="5"/>';
			pinCache[kind] = L.divIcon({
				className: 'saito-sl-pin saito-sl-pin--' + kind,
				html: '<svg viewBox="0 0 30 40" width="30" height="40" aria-hidden="true" focusable="false"><path class="saito-sl-pin__body" d="' + PIN_PATH + '"/>' + inner + '</svg>',
				iconSize: [30, 40],
				iconAnchor: [15, 39],
				popupAnchor: [0, -34]
			});
		}
		return pinCache[kind];
	}

	var userIcon = null;
	function getUserIcon() {
		if (!userIcon) {
			userIcon = L.divIcon({
				className: 'saito-sl-user',
				html: '<span class="saito-sl-user__pulse"></span><span class="saito-sl-user__dot"></span>',
				iconSize: [22, 22],
				iconAnchor: [11, 11],
				popupAnchor: [0, -12]
			});
		}
		return userIcon;
	}

	/* ------------------------------------------------------------------
	 * Mémoire du navigateur : dernière recherche, application d'itinéraire
	 * (stockage local du visiteur, sans effet si le navigateur le refuse)
	 * ---------------------------------------------------------------- */

	var memory = {
		get: function (key) {
			try {
				return JSON.parse(window.localStorage.getItem('saito-sl:' + key));
			} catch {
				return null;
			}
		},
		set: function (key, value) {
			try {
				window.localStorage.setItem('saito-sl:' + key, JSON.stringify(value));
			} catch {
				// Navigation privée ou stockage bloqué : on s'en passe.
			}
		}
	};

	/* ------------------------------------------------------------------
	 * Contenus : itinéraire, fiche, popup, fenêtre de détail
	 * ---------------------------------------------------------------- */

	/** Liens d'itinéraire ; l'application choisie la dernière fois est proposée en premier. */
	function directionsLinks(store, origin) {
		var dest = store.lat + ',' + store.lng;
		var from = origin ? origin.lat + ',' + origin.lng : '';
		var preferred = memory.get('app');
		var links = [
			{ key: 'google', label: 'Google Maps', url: 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dest) + (from ? '&origin=' + encodeURIComponent(from) : '') },
			{ key: 'apple', label: 'Apple Plans', url: 'https://maps.apple.com/?daddr=' + encodeURIComponent(dest) + '&dirflg=d' + (from ? '&saddr=' + encodeURIComponent(from) : '') },
			{ key: 'waze', label: 'Waze', url: 'https://waze.com/ul?ll=' + encodeURIComponent(dest) + '&navigate=yes' }
		];
		return links.sort(function (a, b) {
			return (b.key === preferred) - (a.key === preferred);
		});
	}

	/** Bouton « J'Y VAIS » qui déplie le choix de l'application d'itinéraire. */
	function directionsMenu(store, origin) {
		var details = el('details', 'saito-sl__go');
		var summary = el('summary', 'saito-sl__btn', __('J\'Y VAIS', 'saito-store-locator'));
		/* translators: %s: nom du magasin. */
		summary.setAttribute('aria-label', sprintf(__('J\'y vais : choisir l\'application d\'itinéraire vers %s', 'saito-store-locator'), store.title));
		details.appendChild(summary);
		var menu = el('div', 'saito-sl__go-menu');
		menu.setAttribute('role', 'group');
		menu.setAttribute('aria-label', __('Itinéraire avec', 'saito-store-locator'));
		directionsLinks(store, origin).forEach(function (app) {
			var link = el('a', 'saito-sl__go-link saito-sl__go-link--' + app.key, app.label);
			link.href = app.url;
			link.target = '_blank';
			link.rel = 'noopener';
			/* translators: 1: nom du magasin, 2: application d'itinéraire (Google Maps, Apple Plans, Waze). */
			link.setAttribute('aria-label', sprintf(__('Itinéraire vers %1$s avec %2$s (nouvel onglet)', 'saito-store-locator'), store.title, app.label));
			link.addEventListener('click', function () {
				memory.set('app', app.key);
			});
			menu.appendChild(link);
		});
		details.appendChild(menu);
		details.addEventListener('toggle', function () {
			if (details.open) {
				Array.prototype.forEach.call(document.querySelectorAll('.saito-sl__go[open]'), function (other) {
					if (other !== details) {
						other.open = false;
					}
				});
			}
		});
		return details;
	}

	function openStateEl(store) {
		var state = openState(store.hours);
		if (!state) {
			return null;
		}
		return el('p', 'saito-sl__open-state ' + (state.open ? 'is-open' : 'is-closed'), state.text);
	}

	/* ------------------------------------------------------------------
	 * Instance du store locator
	 * ---------------------------------------------------------------- */

	function StoreLocator(root) {
		this.root = root;
		this.input = root.querySelector('.saito-sl__input');
		this.listbox = root.querySelector('.saito-sl__suggestions');
		this.locateBtn = root.querySelector('.saito-sl__locate');
		this.statusEl = root.querySelector('.saito-sl__status');
		this.mapEl = root.querySelector('.saito-sl__map');
		this.panel = root.querySelector('.saito-sl__panel');
		this.panelTitle = root.querySelector('.saito-sl__panel-title');
		this.panelToggle = root.querySelector('.saito-sl__panel-toggle');
		this.panelOpen = root.querySelector('.saito-sl__panel-open');
		this.emptyEl = root.querySelector('.saito-sl__empty');
		this.resultsEl = root.querySelector('.saito-sl__results');
		this.filtersEl = root.querySelector('.saito-sl__filters');
		this.viewButtons = root.querySelectorAll('.saito-sl__view');
		this.modal = root.querySelector('.saito-sl__modal');
		this.modalBody = root.querySelector('.saito-sl__modal-body');
		this.resultsCount = parseInt(root.getAttribute('data-results'), 10) || CFG.resultsCount || 4;
		this.queryVar = CFG.queryVar || 'magasin';
		this.baseTitle = document.title;

		this.stores = [];
		this.bySlug = {};
		this.filters = { brands: [], services: [] };
		this.lastSearch = null;
		this.suggestions = [];
		this.activeIndex = -1;
		this.markers = [];
		this.activeIndexStore = null;
		this.userMarker = null;
		this.userPosition = null;
		this.userInteracted = false;
		this.searchToken = 0;
		this.historyPushed = false;
		this.moving = false;
		this.resultPool = [];
		this.shown = [];

		this.initMap();
		this.bindPanel();
		this.bindSearch();
		this.bindLocate();
		this.bindViews();
		this.bindModal();
		this.bindArea();
		this.bindMore();
		this.loadMarkers();
	}

	/**
	 * Message annoncé aux lecteurs d'écran. Les erreurs restent visibles ; les informations
	 * (nombre de résultats) ne sont pas répétées à l'écran, le titre de la liste les affiche déjà.
	 */
	StoreLocator.prototype.setStatus = function (message, isError) {
		this.statusEl.textContent = message || '';
		this.statusEl.classList.toggle('is-error', !!isError);
		this.statusEl.classList.toggle('is-info', !isError && !!message);
	};

	/* ---------- Carte ---------- */

	StoreLocator.prototype.initMap = function () {
		var tiles = CFG.tiles || {};
		this.map = L.map(this.mapEl, { center: FRANCE_CENTER, zoom: 6, zoomControl: false });
		L.control.zoom({ position: 'topright', zoomInTitle: __('Zoomer', 'saito-store-locator'), zoomOutTitle: __('Dézoomer', 'saito-store-locator') }).addTo(this.map);
		this.map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>'); // Sans drapeau : noir et blanc uniquement.
		L.tileLayer(tiles.url, {
			attribution: tiles.attribution,
			tileSize: tiles.tileSize || 256,
			zoomOffset: tiles.zoomOffset || 0,
			maxZoom: 19,
			crossOrigin: true
		}).addTo(this.map);

		this.cluster = L.markerClusterGroup({
			showCoverageOnHover: false,
			maxClusterRadius: 45,
			disableClusteringAtZoom: 13,
			chunkedLoading: true,
			iconCreateFunction: function (cluster) {
				var count = cluster.getChildCount();
				var size = count < 10 ? 34 : count < 50 ? 40 : 46;
				return L.divIcon({
					html: '<span>' + count + '</span>',
					className: 'saito-sl-cluster',
					iconSize: [size, size]
				});
			}
		});
		this.map.addLayer(this.cluster);
		this.bindMapGestures();

		var map = this.map;
		if (typeof window.ResizeObserver === 'function') {
			new window.ResizeObserver(function () {
				map.invalidateSize();
			}).observe(this.mapEl);
		}
	};

	/**
	 * La carte ne capture pas le défilement de la page :
	 * ordinateur → zoom à la molette avec Ctrl/⌘ ; tactile → déplacement à deux doigts.
	 */
	StoreLocator.prototype.bindMapGestures = function () {
		var map = this.map;
		var mapEl = this.mapEl;
		var hint = el('div', 'saito-sl__map-hint');
		hint.setAttribute('aria-hidden', 'true');
		mapEl.appendChild(hint);
		var timer;
		var showHint = function (text) {
			hint.textContent = text;
			hint.classList.add('is-visible');
			clearTimeout(timer);
			timer = setTimeout(function () {
				hint.classList.remove('is-visible');
			}, 1300);
		};
		var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
		mapEl.addEventListener('wheel', function (event) {
			if (!event.ctrlKey && !event.metaKey) {
				event.stopPropagation();
				showHint(isMac ? __('Utilisez ⌘ + molette pour zoomer sur la carte', 'saito-store-locator') : __('Utilisez Ctrl + molette pour zoomer sur la carte', 'saito-store-locator'));
			}
		}, { capture: true, passive: true });
		if (window.matchMedia('(pointer: coarse)').matches) {
			map.dragging.disable();
			mapEl.addEventListener('touchmove', function (event) {
				if (event.touches.length === 1) {
					showHint(__('Utilisez deux doigts pour déplacer la carte', 'saito-store-locator'));
				}
			}, { passive: true });
		}
	};

	/** Marge à laisser autour des résultats : la liste recouvre la gauche de la carte sur ordinateur. */
	StoreLocator.prototype.mapPadding = function () {
		if (isMobile() || this.root.classList.contains('is-panel-collapsed')) {
			return { paddingTopLeft: [40, 40], paddingBottomRight: [40, 40] };
		}
		var left = this.panel.offsetLeft + this.panel.offsetWidth + 32;
		return { paddingTopLeft: [left, 40], paddingBottomRight: [56, 40] };
	};

	/** Centre la carte sur un point en tenant compte de la liste superposée. */
	/** Déplacement de la carte décidé par le store locator (et non par le visiteur). */
	StoreLocator.prototype.autoMove = function (fn) {
		var self = this;
		this.moving = true;
		clearTimeout(this.movingTimer);
		var done = function () {
			clearTimeout(self.movingTimer);
			self.movingTimer = setTimeout(function () {
				self.moving = false;
			}, 400);
		};
		this.map.once('moveend', done);
		this.movingTimer = setTimeout(function () {
			self.moving = false;
		}, 2500);
		fn();
	};

	StoreLocator.prototype.centerOn = function (latlng, zoom) {
		var pad = this.mapPadding();
		var offset = (pad.paddingTopLeft[0] - pad.paddingBottomRight[0]) / 2;
		var point = this.map.project(latlng, zoom).subtract([offset, 0]);
		var map = this.map;
		this.autoMove(function () {
			map.setView(map.unproject(point, zoom), zoom, { animate: !prefersReducedMotion() });
		});
	};

	/* ---------- Liste superposée ---------- */

	StoreLocator.prototype.bindPanel = function () {
		var self = this;
		var set = function (collapsed) {
			self.root.classList.toggle('is-panel-collapsed', collapsed);
			self.panelToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			self.panelOpen.hidden = !collapsed;
			if (collapsed) {
				self.panelOpen.focus();
			} else {
				self.panelToggle.focus();
			}
		};
		this.panelToggle.addEventListener('click', function () {
			set(true);
		});
		this.panelOpen.addEventListener('click', function () {
			set(false);
		});
	};

	/* ---------- Vue Carte / Liste (mobile) ---------- */

	StoreLocator.prototype.bindViews = function () {
		var self = this;
		this.setView('map');
		Array.prototype.forEach.call(this.viewButtons, function (button) {
			button.addEventListener('click', function () {
				self.setView(button.getAttribute('data-view'));
			});
		});
	};

	StoreLocator.prototype.setView = function (view) {
		// Une animation de zoom interrompue par le masquage de la carte bloquerait les suivantes.
		if (view !== 'map' && this.map._animatingZoom && typeof this.map._onZoomTransitionEnd === 'function') {
			this.map._onZoomTransitionEnd();
		}
		this.map.stop();
		this.root.setAttribute('data-view', view);
		Array.prototype.forEach.call(this.viewButtons, function (button) {
			button.setAttribute('aria-pressed', button.getAttribute('data-view') === view ? 'true' : 'false');
		});
		if (view === 'map') {
			this.map.invalidateSize();
			if (this.pendingBounds) {
				var map = this.map;
				var pending = this.pendingBounds;
				this.autoMove(function () {
					map.fitBounds(pending, { padding: [40, 40], maxZoom: FOCUS_ZOOM, animate: false });
				});
				this.pendingBounds = null;
			}
		}
	};

	StoreLocator.prototype.updateListCount = function (count, context) {
		if (context !== undefined) {
			this.listContext = context;
		}
		Array.prototype.forEach.call(this.viewButtons, function (button) {
			if (button.getAttribute('data-view') === 'list') {
				/* translators: %d: nombre de points de vente dans la liste. */
				button.textContent = count ? sprintf(__('Liste (%d)', 'saito-store-locator'), count) : __('Liste', 'saito-store-locator');
			}
		});
		/* translators: %d: nombre de points de vente. */
		var counted = sprintf(_n('%d point de vente', '%d points de vente', count, 'saito-store-locator'), count);
		this.panelTitle.textContent = count
			? counted + (this.listContext ? ' ' + this.listContext : '')
			: __('Points de vente', 'saito-store-locator');
	};

	/* ---------- Filtres par enseigne et service ---------- */

	StoreLocator.prototype.matches = function (store) {
		var f = this.filters;
		if (f.brands.length && f.brands.indexOf(store.brandLabel) === -1) {
			return false;
		}
		for (var i = 0; i < f.services.length; i++) {
			if (store.serviceList.indexOf(f.services[i]) === -1) {
				return false;
			}
		}
		return true;
	};

	StoreLocator.prototype.renderFilters = function () {
		var self = this;
		if (!this.filtersEl || CFG.filters === false) {
			return;
		}
		var brandCounts = {};
		var serviceCounts = {};
		this.stores.forEach(function (s) {
			brandCounts[s.brandLabel] = (brandCounts[s.brandLabel] || 0) + 1;
			s.serviceList.forEach(function (sv) {
				serviceCounts[sv] = (serviceCounts[sv] || 0) + 1;
			});
		});
		var brands = Object.keys(brandCounts).sort(function (a, b) {
			if (a === OTHER_BRAND) {
				return 1;
			}
			if (b === OTHER_BRAND) {
				return -1;
			}
			return brandCounts[b] - brandCounts[a];
		});
		var services = Object.keys(serviceCounts).sort();
		if (brands.length < 2 && !services.length) {
			return;
		}
		var chip = function (kind, value, label, count) {
			var button = el('button', 'saito-sl__chip saito-sl__chip--' + kind);
			button.type = 'button';
			button.setAttribute('data-kind', kind);
			button.setAttribute('data-value', value);
			button.setAttribute('aria-pressed', 'false');
			button.appendChild(document.createTextNode(label));
			if (count) {
				button.appendChild(el('span', 'saito-sl__chip-count', String(count)));
			}
			return button;
		};
		this.filtersEl.textContent = '';
		this.filtersEl.appendChild(chip('all', '', __('Tous', 'saito-store-locator'), this.stores.length));
		if (brands.length > 1) {
			brands.forEach(function (b) {
				self.filtersEl.appendChild(chip('brand', b, b, brandCounts[b]));
			});
		}
		services.forEach(function (sv) {
			self.filtersEl.appendChild(chip('service', sv, sv, serviceCounts[sv]));
		});
		this.filtersEl.hidden = false;
		this.updateChips();

		this.filtersEl.addEventListener('click', function (event) {
			var button = event.target.closest('.saito-sl__chip');
			if (!button) {
				return;
			}
			var kind = button.getAttribute('data-kind');
			var value = button.getAttribute('data-value');
			if (kind === 'all') {
				self.filters = { brands: [], services: [] };
			} else {
				var list = self.filters[kind === 'brand' ? 'brands' : 'services'];
				var pos = list.indexOf(value);
				if (pos === -1) {
					list.push(value);
				} else {
					list.splice(pos, 1);
				}
			}
			self.updateChips();
			self.applyFilters();
		});
	};

	StoreLocator.prototype.updateChips = function () {
		var f = this.filters;
		var none = !f.brands.length && !f.services.length;
		Array.prototype.forEach.call(this.filtersEl.querySelectorAll('.saito-sl__chip'), function (button) {
			var kind = button.getAttribute('data-kind');
			var value = button.getAttribute('data-value');
			var on = kind === 'all' ? none : (kind === 'brand' ? f.brands : f.services).indexOf(value) !== -1;
			button.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
	};

	StoreLocator.prototype.applyFilters = function () {
		var self = this;
		this.map.closePopup();
		var visible = this.markers.filter(function (marker, i) {
			return self.matches(self.stores[i]);
		});
		this.cluster.clearLayers();
		this.cluster.addLayers(visible);
		if (this.lastSearch && this.lastSearch.options.area) {
			this.searchArea();
		} else if (this.lastSearch) {
			this.showNearest(this.lastSearch.lat, this.lastSearch.lng, this.lastSearch.options, true);
		} else if (!visible.length) {
			this.setStatus(__('Aucun point de vente ne correspond à ces filtres.', 'saito-store-locator'), true);
		} else {
			/* translators: %d: nombre de points de vente. */
			this.setStatus(visible.length === this.markers.length ? '' : sprintf(_n('%d point de vente correspond à ces filtres.', '%d points de vente correspondent à ces filtres.', visible.length, 'saito-store-locator'), visible.length));
		}
	};

	/* ---------- Marqueurs ---------- */

	StoreLocator.prototype.loadMarkers = function () {
		var self = this;
		loadStores()
			.then(function (stores) {
				self.stores = stores;
				stores.forEach(function (store) {
					if (store.slug) {
						self.bySlug[store.slug] = store;
					}
				});
				self.markers = stores.map(function (store) {
					var marker = L.marker([store.lat, store.lng], {
						icon: pinIcon(store.icone === 'signature' ? 'signature' : 'default'),
						title: store.title,
						alt: store.title,
						riseOnHover: true
					});
					marker.bindPopup(function () {
						return self.popupContent(store);
					}, { maxWidth: 280, minWidth: 220 });
					marker.on('click', function () {
						self.setActive(store.index, true);
					});
					marker.on('mouseover', function () {
						self.setHot(store.index, true, 'marker');
					});
					marker.on('mouseout', function () {
						self.setHot(store.index, false, 'marker');
					});
					return marker;
				});
				self.cluster.addLayers(self.markers);
				self.renderFilters();
				self.openFromUrl(true);
				self.restoreLastSearch();
			})
			.catch(function (error) {
				self.setStatus(__('Impossible de charger la liste des points de vente. Veuillez réessayer plus tard.', 'saito-store-locator'), true);
				if (window.console) {
					window.console.error('[Saito Store Locator]', error);
				}
			});
	};

	/** Élément visible représentant un magasin : son marqueur, ou le groupe qui le contient. */
	StoreLocator.prototype.markerElement = function (index) {
		var marker = this.markers[index];
		if (!marker) {
			return null;
		}
		if (marker._icon) {
			return marker._icon;
		}
		var parent = this.cluster.getVisibleParent(marker);
		return parent && parent._icon ? parent._icon : null;
	};

	/** Survol lié : fiche ↔ marqueur. */
	StoreLocator.prototype.setHot = function (index, on, from) {
		var icon = this.markerElement(index);
		if (icon) {
			icon.classList.toggle('is-hot', on);
			if (this.markers[index]._icon === icon) {
				this.markers[index].setZIndexOffset(on ? 900 : 0);
			}
		}
		if (from === 'marker') {
			var card = this.resultsEl.querySelector('[data-index="' + index + '"]');
			if (card) {
				card.classList.toggle('is-hot', on);
			}
		}
	};

	/** Magasin sélectionné : fiche et marqueur mis en évidence. */
	StoreLocator.prototype.setActive = function (index, scroll) {
		var previous = this.activeIndexStore;
		if (previous !== null && this.markers[previous] && this.markers[previous]._icon) {
			this.markers[previous]._icon.classList.remove('is-active');
		}
		this.activeIndexStore = index;
		var icon = this.markers[index] && this.markers[index]._icon;
		if (icon) {
			icon.classList.add('is-active');
		}
		var target = null;
		Array.prototype.forEach.call(this.resultsEl.querySelectorAll('.saito-sl__card'), function (card) {
			var match = card.getAttribute('data-index') === String(index);
			card.classList.toggle('is-active', match);
			if (match) {
				target = card;
			}
		});
		if (target && scroll && this.panel.scrollHeight > this.panel.clientHeight + 1) {
			this.panel.scrollTo({ top: target.offsetTop - 12, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
		}
	};

	StoreLocator.prototype.storeUrl = function (store) {
		// Garde les paramètres de la page (?page_id=…, ?lang=…) et remplace seulement celui du magasin.
		var url = new URL(this.root.getAttribute('data-page-url') || window.location.href, window.location.href);
		url.hash = '';
		url.searchParams.set(this.queryVar, store.slug);
		return url.toString();
	};

	/** Lien « Voir la fiche » : vraie URL (indexable), ouverte dans la fenêtre de détail. */
	StoreLocator.prototype.sheetLink = function (store, label) {
		var self = this;
		var link = el('a', 'saito-sl__more', label || __('Voir la fiche', 'saito-store-locator'));
		if (!store.slug) {
			return null;
		}
		link.href = this.storeUrl(store);
		/* translators: %s: nom du magasin. */
		link.setAttribute('aria-label', sprintf(__('Voir la fiche de %s', 'saito-store-locator'), store.title));
		link.addEventListener('click', function (event) {
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) {
				return; // Ouvrir dans un nouvel onglet : comportement normal.
			}
			event.preventDefault();
			self.openStore(store, { push: true });
		});
		return link;
	};

	StoreLocator.prototype.popupContent = function (store) {
		var box = el('div', 'saito-sl-popup');
		if (store.brandName) {
			box.appendChild(el('p', 'saito-sl__eyebrow', store.brandName));
		}
		box.appendChild(el('p', 'saito-sl-popup__title', store.short));
		box.appendChild(el('p', 'saito-sl__address', addressLine(store)));
		var state = openStateEl(store);
		if (state) {
			box.appendChild(state);
		}
		var actions = el('div', 'saito-sl__card-actions');
		actions.appendChild(directionsMenu(store, this.userPosition));
		var more = this.sheetLink(store);
		if (more) {
			actions.appendChild(more);
		}
		box.appendChild(actions);
		return box;
	};

	StoreLocator.prototype.focusStore = function (store, withPopup) {
		var marker = this.markers[store.index];
		if (!marker) {
			return;
		}
		if (isMobile() && this.root.getAttribute('data-view') !== 'map') {
			this.pendingBounds = null;
			this.setView('map');
		}
		var self = this;
		var cluster = this.cluster;
		var open = function () {
			self.moving = true;
			cluster.zoomToShowLayer(marker, function () {
				setTimeout(function () {
					self.moving = false;
				}, 400);
				self.setActive(store.index, false);
				if (withPopup !== false) {
					marker.openPopup();
				}
			});
		};
		this.setActive(store.index, false);
		var zoom = Math.max(this.map.getZoom(), FOCUS_ZOOM);
		this.map.once('moveend', function () {
			setTimeout(open, 0);
		});
		this.centerOn(marker.getLatLng(), zoom);
	};

	/* ---------- Dernière recherche ---------- */

	StoreLocator.prototype.rememberSearch = function (entry) {
		entry.t = Date.now();
		entry.input = this.input.value;
		memory.set('last', entry);
	};

	/** Au retour sur la page, réaffiche la dernière recherche (sauf si la position est déjà autorisée). */
	StoreLocator.prototype.restoreLastSearch = function () {
		var self = this;
		var last = memory.get('last');
		if (!last || typeof last.lat !== 'number' || Date.now() - (last.t || 0) > 30 * 24 * 3600 * 1000) {
			return;
		}
		if (new URL(window.location.href).searchParams.get(this.queryVar)) {
			return; // Une fiche est ouverte depuis son lien.
		}
		var run = function () {
			if (self.userInteracted || self.lastSearch) {
				return;
			}
			self.input.value = last.input || last.label;
			self.showNearest(last.lat, last.lng, { label: last.label, postcode: last.postcode || '', restored: true });
		};
		if (navigator.permissions && navigator.permissions.query) {
			navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
				if (p.state !== 'granted') {
					run();
				}
			}).catch(run);
		} else {
			run();
		}
	};

	/* ---------- Résultats ---------- */

	StoreLocator.prototype.showNearest = function (lat, lng, options, fromFilter) {
		var self = this;
		options = options || {};
		this.lastSearch = { lat: lat, lng: lng, options: options };
		this.hideArea();
		return loadStores().then(function (stores) {
			if (!stores.length) {
				self.setStatus(__('Aucun point de vente n\'est disponible pour le moment.', 'saito-store-locator'), true);
				return;
			}
			var pool = stores.filter(function (s) {
				return self.matches(s);
			});
			if (!pool.length) {
				self.renderResults([]);
				self.updateListCount(0, '');
				self.setStatus(__('Aucun point de vente ne correspond à ces filtres.', 'saito-store-locator'), true);
				return;
			}
			var ranked = pool
				.map(function (s) {
					return { store: s, distance: distanceKm(lat, lng, s.lat, s.lng) };
				})
				.sort(function (a, b) {
					return a.distance - b.distance;
				});

			var list = ranked.slice(0, self.resultsCount);
			if (options.postcode) {
				ranked.slice(self.resultsCount).forEach(function (r) {
					if (r.store.postcode === options.postcode) {
						list.push(r);
					}
				});
			}

			self.resultPool = ranked;
			self.renderResults(list);
			/* translators: %s: ville ou code postal recherché. */
			var where = options.here ? __('autour de vous', 'saito-store-locator') : sprintf(__('près de %s', 'saito-store-locator'), options.label);
			self.updateListCount(list.length, where);
			if (isMobile() && !fromFilter) {
				self.setView(options.focusStore ? 'map' : 'list');
			}

			var bounds = L.latLngBounds([[lat, lng]]);
			list.forEach(function (r) {
				bounds.extend([r.store.lat, r.store.lng]);
			});
			self.pendingBounds = null;
			if (!options.focusStore) {
				if (self.root.getAttribute('data-view') === 'list' && isMobile()) {
					self.pendingBounds = bounds;
				} else {
					var pad = self.mapPadding();
					self.autoMove(function () {
						self.map.fitBounds(bounds, { paddingTopLeft: pad.paddingTopLeft, paddingBottomRight: pad.paddingBottomRight, maxZoom: FOCUS_ZOOM, animate: !prefersReducedMotion() });
					});
				}
			}

			var count = list.length;
			var text = options.here
				/* translators: %d: nombre de points de vente. */
				? sprintf(_n('Le point de vente le plus proche de votre position.', 'Les %d points de vente les plus proches de votre position.', count, 'saito-store-locator'), count)
				/* translators: 1: nombre de points de vente, 2: ville ou code postal recherché. */
				: sprintf(_n('Le point de vente le plus proche de %2$s.', 'Les %1$d points de vente les plus proches de %2$s.', count, 'saito-store-locator'), count, options.label);
			/* translators: %s: résultat de la recherche (« Les 4 points de vente les plus proches de Lyon. »). */
			self.setStatus(options.restored ? sprintf(__('Votre dernière recherche : %s', 'saito-store-locator'), text) : text);
		}).catch(function () {
			self.setStatus(__('Impossible de charger la liste des points de vente. Veuillez réessayer plus tard.', 'saito-store-locator'), true);
		});
	};

	StoreLocator.prototype.renderResults = function (list) {
		this.resultsEl.textContent = '';
		this.emptyEl.hidden = list.length > 0;
		this.activeIndexStore = null;
		this.shown = [];
		this.appendResults(list);
		this.panel.scrollTop = 0;
	};

	StoreLocator.prototype.appendResults = function (list) {
		var self = this;
		list.forEach(function (r, i) {
			self.shown.push(r.store.index);
			var store = r.store;
			var card = el('li', 'saito-sl__card');
			card.setAttribute('data-index', String(store.index));
			card.style.setProperty('--i', String(i));

			var top = el('div', 'saito-sl__card-top');
			top.appendChild(el('p', 'saito-sl__eyebrow', store.brandName || ' '));
			top.appendChild(el('p', 'saito-sl__distance', formatDistance(r.distance)));
			card.appendChild(top);

			var heading = el('h3', 'saito-sl__card-heading');
			var title = el('button', 'saito-sl__card-title', store.short);
			title.type = 'button';
			/* translators: %s: nom du magasin. */
			title.setAttribute('aria-label', sprintf(__('%s — afficher sur la carte', 'saito-store-locator'), store.title));
			heading.appendChild(title);
			card.appendChild(heading);

			card.appendChild(el('p', 'saito-sl__address', addressLine(store)));
			var state = openStateEl(store);
			if (state) {
				card.appendChild(state);
			}
			if (store.phone) {
				var phone = el('a', 'saito-sl__phone', formatPhone(store.phone));
				phone.href = 'tel:' + store.phone.replace(/[^0-9+]/g, '');
				var p = el('p', 'saito-sl__contact');
				p.appendChild(phone);
				card.appendChild(p);
			}

			var actions = el('div', 'saito-sl__card-actions');
			actions.appendChild(directionsMenu(store, self.userPosition));
			var more = self.sheetLink(store);
			if (more) {
				actions.appendChild(more);
			}
			card.appendChild(actions);

			card.addEventListener('click', function (event) {
				if (event.target.closest('a, details')) {
					return;
				}
				self.focusStore(store);
			});
			card.addEventListener('mouseenter', function () {
				self.setHot(store.index, true, 'card');
			});
			card.addEventListener('mouseleave', function () {
				self.setHot(store.index, false, 'card');
			});
			card.addEventListener('focusin', function () {
				self.setHot(store.index, true, 'card');
			});
			card.addEventListener('focusout', function () {
				self.setHot(store.index, false, 'card');
			});
			self.resultsEl.appendChild(card);
		});
		this.updateMore();
	};

	/* ---------- « Voir plus » ---------- */

	StoreLocator.prototype.bindMore = function () {
		var self = this;
		this.moreBtn = el('button', 'saito-sl__btn saito-sl__btn--ghost saito-sl__more-results', __('Voir plus de points de vente', 'saito-store-locator'));
		this.moreBtn.type = 'button';
		this.moreBtn.hidden = true;
		this.resultsEl.insertAdjacentElement('afterend', this.moreBtn);
		this.moreBtn.addEventListener('click', function () {
			var next = self.resultPool.filter(function (r) {
				return self.shown.indexOf(r.store.index) === -1;
			}).slice(0, self.resultsCount);
			if (!next.length) {
				return;
			}
			var first = self.resultsEl.children.length;
			self.appendResults(next);
			self.updateListCount(self.shown.length);
			/* translators: %d: nombre de points de vente affichés. */
			self.setStatus(sprintf(_n('%d point de vente affiché.', '%d points de vente affichés.', self.shown.length, 'saito-store-locator'), self.shown.length));
			var card = self.resultsEl.children[first];
			if (card) {
				var button = card.querySelector('.saito-sl__card-title');
				if (button) {
					button.focus({ preventScroll: true });
				}
				if (self.panel.scrollHeight > self.panel.clientHeight + 1) {
					self.panel.scrollTo({ top: card.offsetTop - 12, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
				}
			}
		});
	};

	StoreLocator.prototype.updateMore = function () {
		var self = this;
		var remaining = this.resultPool.some(function (r) {
			return self.shown.indexOf(r.store.index) === -1;
		});
		this.moreBtn.hidden = !remaining || !this.shown.length;
	};

	/* ---------- « Rechercher dans cette zone » ---------- */

	StoreLocator.prototype.bindArea = function () {
		var self = this;
		var body = this.root.querySelector('.saito-sl__body');
		this.areaBtn = el('button', 'saito-sl__area');
		this.areaBtn.type = 'button';
		this.areaBtn.hidden = true;
		this.areaBtn.innerHTML = '<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5Zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14Z"/></svg>';
		this.areaBtn.appendChild(document.createTextNode(__('Rechercher dans cette zone', 'saito-store-locator')));
		body.appendChild(this.areaBtn);
		this.areaBtn.addEventListener('click', function () {
			self.searchArea();
		});
		var show = function () {
			if (!self.moving && self.stores.length) {
				self.areaBtn.hidden = false;
			}
		};
		this.map.on('dragend', show);
		this.map.on('zoomend', show);
	};

	StoreLocator.prototype.hideArea = function () {
		if (this.areaBtn) {
			this.areaBtn.hidden = true;
		}
	};

	/** Magasins visibles à l'écran, du plus proche au plus éloigné du centre de la carte. */
	StoreLocator.prototype.searchArea = function () {
		var self = this;
		var bounds = this.map.getBounds();
		var center = this.map.getCenter();
		this.hideArea();
		this.lastSearch = { lat: center.lat, lng: center.lng, options: { label: __('cette zone', 'saito-store-locator'), area: true } };
		var ranked = this.stores
			.filter(function (s) {
				return self.matches(s) && bounds.contains([s.lat, s.lng]);
			})
			.map(function (s) {
				return { store: s, distance: distanceKm(center.lat, center.lng, s.lat, s.lng) };
			})
			.sort(function (a, b) {
				return a.distance - b.distance;
			});
		this.resultPool = ranked;
		var list = ranked.slice(0, Math.max(this.resultsCount, 8));
		this.renderResults(list);
		this.updateListCount(list.length, __('dans cette zone', 'saito-store-locator'));
		if (!ranked.length) {
			this.setStatus(__('Aucun point de vente dans cette zone. Dézoomez ou déplacez la carte.', 'saito-store-locator'), true);
			return;
		}
		/* translators: %d: nombre de points de vente dans la zone visible. */
		var found = sprintf(_n('%d point de vente dans cette zone.', '%d points de vente dans cette zone.', ranked.length, 'saito-store-locator'), ranked.length);
		/* translators: %d: nombre de points de vente affichés dans la liste. */
		this.setStatus(found + (ranked.length > list.length ? ' ' + sprintf(__('%d affichés.', 'saito-store-locator'), list.length) : ''));
		if (this.root.classList.contains('is-panel-collapsed')) {
			this.panelOpen.click();
		}
	};

	/* ---------- Fenêtre de détail (fiche magasin, URL propre) ---------- */

	StoreLocator.prototype.bindModal = function () {
		var self = this;
		var modal = this.modal;
		if (!modal) {
			return;
		}
		modal.querySelector('.saito-sl__modal-close').addEventListener('click', function () {
			self.closeStore();
		});
		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				self.closeStore(); // Clic sur le fond.
			}
		});
		// Échap géré ici : Chrome peut ignorer Échap sur une fenêtre ouverte sans geste de l'utilisateur
		// (Précédent / Suivant, lien direct).
		modal.addEventListener('cancel', function (event) {
			event.preventDefault();
			self.closeStore();
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && modal.open && !document.querySelector('.saito-sl__go[open]')) {
				event.preventDefault();
				self.closeStore();
			}
		});
		// Fermeture par le navigateur lui-même : l'URL et le titre suivent.
		modal.addEventListener('close', function () {
			// L'événement arrive après coup : une fiche fermée puis aussitôt rouverte
			// (fiche rendue par le serveur, voir openStore()) est de nouveau ouverte ici.
			if (self.modal.open) {
				return;
			}
			if (self.root.classList.contains('has-modal')) {
				self.afterClose(false);
			}
		});
		window.addEventListener('popstate', function () {
			self.openFromUrl(false);
		});
	};

	StoreLocator.prototype.fillSheet = function (store) {
		var id = this.modal.id + '-title';
		var sheet = el('article', 'saito-sl__sheet');
		sheet.setAttribute('data-slug', store.slug);
		if (store.brandName) {
			sheet.appendChild(el('p', 'saito-sl__eyebrow', store.brandName));
		}
		var h = el('h2', 'saito-sl__modal-title', store.title);
		h.id = id;
		sheet.appendChild(h);
		var state = openStateEl(store);
		if (state) {
			sheet.appendChild(state);
		}

		var facts = el('dl', 'saito-sl__facts');
		var fact = function (label, content) {
			var wrap = el('div', 'saito-sl__fact');
			wrap.appendChild(el('dt', '', label));
			var dd = el('dd');
			dd.appendChild(content);
			wrap.appendChild(dd);
			facts.appendChild(wrap);
		};

		var address = el('address');
		[store.address1, store.address2, (store.postcode + ' ' + titleCase(store.city)).trim(), isFrance(store.country) ? '' : store.country]
			.filter(Boolean)
			.forEach(function (line, i) {
				if (i) {
					address.appendChild(document.createElement('br'));
				}
				address.appendChild(document.createTextNode(line));
			});
		if (this.userPosition) {
			/* translators: %s: distance (« 3,2 km »). */
			address.appendChild(el('span', 'saito-sl__distance', ' · ' + sprintf(__('à %s', 'saito-store-locator'), formatDistance(distanceKm(this.userPosition.lat, this.userPosition.lng, store.lat, store.lng)))));
		}
		fact(__('Adresse', 'saito-store-locator'), address);

		if (store.phone) {
			var phone = el('a', '', formatPhone(store.phone));
			phone.href = 'tel:' + store.phone.replace(/[^0-9+]/g, '');
			fact(__('Téléphone', 'saito-store-locator'), phone);
		}

		if (Array.isArray(store.hours) && store.hours.length === 7) {
			var table = el('table', 'saito-sl__week');
			var tbody = el('tbody');
			var today = siteNow().day;
			DAY_NAMES.forEach(function (day, i) {
				var tr = el('tr');
				tr.setAttribute('data-day', String(i));
				if (i === today) {
					tr.className = 'is-today';
				}
				var th = el('th', '', day);
				th.setAttribute('scope', 'row');
				tr.appendChild(th);
				var slots = store.hours[i] || [];
				tr.appendChild(el('td', '', slots.length ? slots.map(function (s) {
					return formatTime(s[0]) + ' – ' + formatTime(s[1]);
				}).join(', ') : __('Fermé', 'saito-store-locator')));
				tbody.appendChild(tr);
			});
			table.appendChild(tbody);
			fact(__('Horaires', 'saito-store-locator'), table);
		} else if (store.hoursText) {
			var text = el('div');
			store.hoursText.split('\n').forEach(function (line, i) {
				if (i) {
					text.appendChild(document.createElement('br'));
				}
				text.appendChild(document.createTextNode(line));
			});
			fact(__('Horaires', 'saito-store-locator'), text);
		}

		if (store.serviceList.length) {
			var tags = el('ul', 'saito-sl__tags');
			store.serviceList.forEach(function (s) {
				tags.appendChild(el('li', '', s));
			});
			fact(__('Services', 'saito-store-locator'), tags);
		}

		if (/^https?:\/\//i.test(store.website)) {
			var site = el('a', '', store.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, ''));
			site.href = store.website;
			site.target = '_blank';
			site.rel = 'noopener';
			fact(__('Site web', 'saito-store-locator'), site);
		}
		sheet.appendChild(facts);

		var actions = el('div', 'saito-sl__sheet-actions');
		actions.appendChild(directionsMenu(store, this.userPosition));
		sheet.appendChild(actions);

		this.modalBody.textContent = '';
		this.modalBody.appendChild(sheet);
	};

	StoreLocator.prototype.openStore = function (store, options) {
		options = options || {};
		if (!this.modal || !store) {
			return;
		}
		this.lastFocus = document.activeElement;
		this.fillSheet(store);
		if (this.modal.open) {
			this.modal.close(); // Fiche rendue par le serveur (non modale) : rouverte en modale.
		}
		if (typeof this.modal.showModal === 'function') {
			this.modal.showModal();
		} else {
			this.modal.setAttribute('open', '');
		}
		this.root.classList.add('has-modal');
		/* translators: 1: nom du magasin, 2: titre de la page. */
		document.title = sprintf(__('%1$s : adresse, horaires et téléphone – %2$s', 'saito-store-locator'), store.title, this.baseTitle);
		var url = this.storeUrl(store);
		if (options.push && window.location.href !== url) {
			window.history.pushState(historyState({ saitoSl: store.slug }), '', url);
			this.historyPushed = true;
		}
		// La carte se place sur le magasin derrière la fenêtre.
		this.setActive(store.index, true);
		var marker = this.markers[store.index];
		if (marker && !isMobile()) {
			this.centerOn(marker.getLatLng(), Math.max(this.map.getZoom(), FOCUS_ZOOM));
		}
	};

	/**
	 * État d'historique à pousser : complète celui de la page au lieu de le remplacer.
	 * L'Interactivity API de WordPress y range son identifiant de session et recharge
	 * la page sur Précédent/Suivant quand il manque.
	 */
	function historyState(extra) {
		var state = {};
		var current = window.history.state;
		if (current && typeof current === 'object') {
			Object.keys(current).forEach(function (key) {
				state[key] = current[key];
			});
		}
		Object.keys(extra).forEach(function (key) {
			if (null === extra[key]) {
				delete state[key];
			} else {
				state[key] = extra[key];
			}
		});
		return state;
	}

	StoreLocator.prototype.closeStore = function (fromHistory) {
		if (!this.modal || !this.modal.open) {
			return;
		}
		this.root.classList.remove('has-modal');
		this.modal.close();
		this.afterClose(fromHistory);
	};

	StoreLocator.prototype.afterClose = function (fromHistory) {
		this.root.classList.remove('has-modal');
		document.title = this.baseTitle;
		if (!fromHistory) {
			if (this.historyPushed) {
				this.historyPushed = false;
				window.history.back();
			} else {
				var url = new URL(window.location.href);
				url.searchParams.delete(this.queryVar);
				window.history.replaceState(historyState({ saitoSl: null }), '', url.toString());
			}
		}
		if (this.lastFocus && typeof this.lastFocus.focus === 'function' && document.body.contains(this.lastFocus)) {
			this.lastFocus.focus();
		}
		// Arrivée par un lien direct (Google, lien partagé) : on montre ensuite le magasin sur la carte.
		if (this.revealOnClose) {
			var store = this.revealOnClose;
			this.revealOnClose = null;
			this.focusStore(store);
		}
	};

	/** Ouvre ou ferme la fiche selon l'URL (chargement direct ou boutons Précédent/Suivant). */
	StoreLocator.prototype.openFromUrl = function (initial) {
		var slug = new URL(window.location.href).searchParams.get(this.queryVar);
		var store = slug ? this.bySlug[slug] : null;
		if (store) {
			if (!this.modal.open || initial || this.modal.querySelector('.saito-sl__sheet[data-slug]') === null ||
				this.modal.querySelector('.saito-sl__sheet').getAttribute('data-slug') !== slug || !this.root.classList.contains('has-modal')) {
				this.openStore(store, { push: false });
				this.revealOnClose = initial ? store : null;
			}
		} else if (this.modal && this.modal.open) {
			this.historyPushed = false;
			this.closeStore(true);
		}
	};

	/* ---------- Recherche (combobox accessible) ---------- */

	StoreLocator.prototype.bindSearch = function () {
		var self = this;
		var input = this.input;

		var prefetch = function () {
			loadCommunes().catch(function () {});
		};
		input.addEventListener('focus', prefetch);
		input.addEventListener('pointerenter', prefetch);

		input.addEventListener('input', function () {
			self.userInteracted = true;
		});
		input.addEventListener('input', debounce(function () {
			self.updateSuggestions();
		}, 200));

		input.addEventListener('keydown', function (event) {
			var open = !self.listbox.hidden;
			switch (event.key) {
				case 'ArrowDown':
					event.preventDefault();
					if (!open) {
						self.updateSuggestions();
					} else {
						self.setActiveOption(self.activeIndex + 1);
					}
					break;
				case 'ArrowUp':
					if (open) {
						event.preventDefault();
						self.setActiveOption(self.activeIndex - 1);
					}
					break;
				case 'Enter':
					event.preventDefault();
					if (open && self.suggestions.length) {
						self.choose(self.suggestions[Math.max(0, self.activeIndex)]);
					} else {
						self.updateSuggestions(true);
					}
					break;
				case 'Escape':
					if (open) {
						event.preventDefault();
						self.closeSuggestions();
					}
					break;
				case 'Tab':
					self.closeSuggestions();
					break;
			}
		});

		input.addEventListener('blur', function () {
			setTimeout(function () {
				self.closeSuggestions();
			}, 150);
		});

		// Empêche la perte de focus du champ au clic dans la liste.
		this.listbox.addEventListener('mousedown', function (event) {
			event.preventDefault();
		});
		this.listbox.addEventListener('click', function (event) {
			var option = event.target.closest('[data-i]');
			if (option) {
				self.choose(self.suggestions[parseInt(option.getAttribute('data-i'), 10)]);
			}
		});

		document.addEventListener('click', function (event) {
			if (!self.root.contains(event.target)) {
				self.closeSuggestions();
			}
		});
	};

	StoreLocator.prototype.updateSuggestions = function (chooseFirst) {
		var self = this;
		var query = this.input.value;
		var token = ++this.searchToken;

		if (normalize(query).length < 2) {
			this.closeSuggestions();
			return;
		}

		Promise.all([loadCommunes(), loadStores()])
			.then(function (data) {
				if (token !== self.searchToken) {
					return; // Une saisie plus récente a pris le relais.
				}
				self.suggestions = search(query, data[0], data[1]);
				if (chooseFirst && self.suggestions.length) {
					self.choose(self.suggestions[0]);
					return;
				}
				self.renderSuggestions();
			})
			.catch(function () {
				self.renderMessage(__('La recherche est momentanément indisponible.', 'saito-store-locator'));
			});
	};

	StoreLocator.prototype.renderMessage = function (message) {
		this.listbox.textContent = '';
		var li = el('li', 'saito-sl__suggestion saito-sl__suggestion--message', message);
		li.setAttribute('role', 'option');
		li.setAttribute('aria-disabled', 'true');
		this.listbox.appendChild(li);
		this.openSuggestions();
	};

	StoreLocator.prototype.renderSuggestions = function () {
		var self = this;
		var id = this.listbox.id;
		this.activeIndex = -1;
		this.input.removeAttribute('aria-activedescendant');

		if (!this.suggestions.length) {
			this.renderMessage(__('Aucun résultat. Essayez un code postal ou une ville voisine.', 'saito-store-locator'));
			return;
		}

		this.listbox.textContent = '';
		this.suggestions.forEach(function (s, i) {
			var li = el('li', 'saito-sl__suggestion saito-sl__suggestion--' + s.type);
			li.id = id + '-opt-' + i;
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', 'false');
			li.setAttribute('data-i', String(i));
			if (s.type === 'place') {
				var p = s.place;
				var label = p.name + (s.viaL5 && p.l5 ? ' (' + p.l5 + ')' : '');
				li.appendChild(el('span', 'saito-sl__suggestion-main', label));
				li.appendChild(el('span', 'saito-sl__suggestion-meta', p.cp + (p.country ? ' · ' + p.country : '')));
			} else {
				li.appendChild(el('span', 'saito-sl__suggestion-main', s.store.name));
				/* translators: %s: ville du magasin. */
				li.appendChild(el('span', 'saito-sl__suggestion-meta', sprintf(__('Magasin · %s', 'saito-store-locator'), s.store.city)));
			}
			self.listbox.appendChild(li);
		});
		this.openSuggestions();
	};

	StoreLocator.prototype.setActiveOption = function (index) {
		var options = this.listbox.querySelectorAll('[data-i]');
		if (!options.length) {
			return;
		}
		index = (index + options.length) % options.length;
		this.activeIndex = index;
		Array.prototype.forEach.call(options, function (option, i) {
			option.setAttribute('aria-selected', i === index ? 'true' : 'false');
			option.classList.toggle('is-active', i === index);
		});
		this.input.setAttribute('aria-activedescendant', options[index].id);
		options[index].scrollIntoView({ block: 'nearest' });
	};

	StoreLocator.prototype.openSuggestions = function () {
		this.listbox.hidden = false;
		this.input.setAttribute('aria-expanded', 'true');
	};

	StoreLocator.prototype.closeSuggestions = function () {
		this.listbox.hidden = true;
		this.input.setAttribute('aria-expanded', 'false');
		this.input.removeAttribute('aria-activedescendant');
		this.activeIndex = -1;
	};

	StoreLocator.prototype.choose = function (suggestion) {
		if (!suggestion) {
			return;
		}
		this.closeSuggestions();
		this.userInteracted = true;
		if (suggestion.type === 'place') {
			var p = suggestion.place;
			var label = p.name + (suggestion.viaL5 && p.l5 ? ' (' + p.l5 + ')' : '');
			this.input.value = label + ' ' + p.cp;
			this.showNearest(p.lat, p.lng, { label: label, postcode: p.cp });
			this.rememberSearch({ label: label, lat: p.lat, lng: p.lng, postcode: p.cp });
		} else {
			var store = suggestion.store;
			var self = this;
			this.input.value = store.name;
			this.rememberSearch({ label: store.name, lat: store.lat, lng: store.lng });
			this.showNearest(store.lat, store.lng, { label: store.name, focusStore: true }).then(function () {
				self.focusStore(store);
			});
		}
	};

	/* ---------- Géolocalisation (sur demande) ---------- */

	StoreLocator.prototype.bindLocate = function () {
		var self = this;
		if (!('geolocation' in navigator)) {
			this.locateBtn.hidden = true;
			return;
		}
		this.locateBtn.addEventListener('click', function () {
			self.userInteracted = true;
			self.locate(true);
		});
		// Si le visiteur a déjà autorisé la géolocalisation sur ce site, on l'utilise sans redemander.
		if (navigator.permissions && navigator.permissions.query) {
			navigator.permissions.query({ name: 'geolocation' }).then(function (permission) {
				if (permission.state === 'granted') {
					self.locate(false);
				}
			}).catch(function () {});
		}
	};

	StoreLocator.prototype.locate = function (explicit) {
		var self = this;
		var btn = this.locateBtn;
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		if (explicit) {
			this.setStatus(__('Localisation en cours…', 'saito-store-locator'));
		}

		navigator.geolocation.getCurrentPosition(
			function (position) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
				if (!explicit && self.userInteracted) {
					return; // Le visiteur a déjà lancé sa propre recherche.
				}
				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				self.userPosition = { lat: lat, lng: lng };

				if (!self.userMarker) {
					self.userMarker = L.marker([lat, lng], {
						icon: getUserIcon(),
						title: __('Votre position', 'saito-store-locator'),
						alt: __('Votre position', 'saito-store-locator'),
						zIndexOffset: 1000
					}).bindPopup(__('Vous êtes ici !', 'saito-store-locator')).addTo(self.map);
				} else {
					self.userMarker.setLatLng([lat, lng]);
				}
				self.input.value = '';
				self.showNearest(lat, lng, { label: __('votre position', 'saito-store-locator'), here: true });
			},
			function (error) {
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
				if (!explicit) {
					return;
				}
				var message = error && error.code === 1
					? __('La géolocalisation a été refusée. Saisissez votre ville ou votre code postal ci-dessus.', 'saito-store-locator')
					: __('Votre position n\'a pas pu être déterminée. Saisissez votre ville ou votre code postal ci-dessus.', 'saito-store-locator');
				self.setStatus(message, true);
			},
			{ enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
		);
	};

	/* ------------------------------------------------------------------
	 * Démarrage
	 * ---------------------------------------------------------------- */

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			var menus = document.querySelectorAll('.saito-sl__go[open]');
			if (menus.length) {
				event.preventDefault(); // Ferme d'abord le menu, pas la fiche.
			}
			Array.prototype.forEach.call(menus, function (d) {
				d.open = false;
				d.querySelector('summary').focus();
			});
		}
	});

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.saito-sl'), function (root) {
			if (root.getAttribute('data-ready')) {
				return;
			}
			root.setAttribute('data-ready', '1');
			try {
				new StoreLocator(root);
			} catch (error) {
				if (window.console) {
					window.console.error('[Saito Store Locator]', error);
				}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
