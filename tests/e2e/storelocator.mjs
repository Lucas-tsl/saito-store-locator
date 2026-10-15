/**
 * Test navigateur du store locator (Playwright).
 *
 * Prérequis : une page contenant [saito_store_locator], avec les magasins de démonstration
 * tests/fixtures/demo-stores.csv importés (Points de vente > Magasins > Importer un fichier CSV).
 *
 *   npm i playwright
 *   node tests/e2e/storelocator.mjs "http://localhost:8080/?page_id=4" [dossier-captures]
 *
 * Les tuiles de carte sont remplacées par une image vide (aucun appel à MapTiler).
 * Variable d'environnement CHROMIUM_PATH pour utiliser un Chromium déjà installé.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const url = process.argv[2];
const out = process.argv[3] || 'e2e-captures';
if (!url) {
	console.error('Usage : node tests/e2e/storelocator.mjs <url de la page> [dossier-captures]');
	process.exit(1);
}
mkdirSync(out, { recursive: true });

const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
let failures = 0;
const check = (ok, label) => {
	console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}`);
	if (!ok) failures++;
};

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});

async function newPage(viewport, geo) {
	const context = await browser.newContext({
		viewport,
		locale: 'fr-FR',
		...(geo ? { geolocation: geo, permissions: ['geolocation'] } : {}),
	});
	const page = await context.newPage();
	const errors = [];
	const requests = {};
	page.on('pageerror', (e) => errors.push(e.message));
	page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
	page.on('request', (r) => {
		const name = r.url().split('?')[0].split('/').pop();
		requests[name] = (requests[name] || 0) + 1;
	});
	await page.route(/api\.maptiler\.com|tile\.openstreetmap\.org/, (route) => route.fulfill({ body: PNG_1PX, contentType: 'image/png' }));
	await page.goto(url, { waitUntil: 'networkidle' });
	return { page, errors, requests, context };
}

const root = '.saito-sl';
const input = `${root} .saito-sl__input`;

/* ---------------- Bureau ---------------- */
{
	const { page, errors, requests, context } = await newPage({ width: 1280, height: 900 });

	await page.waitForSelector(`${root} .saito-sl-cluster, ${root} .saito-sl-pin`);
	check(true, 'marqueurs regroupés affichés au chargement');
	check(!requests['communes.min.json'], 'fichier des communes non chargé avant la recherche');
	check(await page.locator('script[type="application/ld+json"]').count() === 1, 'données structurées JSON-LD présentes');
	const attribution = await page.locator(`${root} .leaflet-control-attribution`).innerText();
	check(/OpenStreetMap/.test(attribution), `crédit cartographique affiché (« ${attribution.replace(/\s+/g, ' ').trim()} »)`);
	check(await page.locator(`${root} style`).count() === 0, 'aucun <style> global injecté par le shortcode');

	// Recherche d'une ville, clavier uniquement.
	await page.fill(input, 'lyon');
	await page.waitForSelector(`${root} .saito-sl__suggestion--place`);
	const first = await page.locator(`${root} .saito-sl__suggestion`).first().innerText();
	check(/^Lyon/.test(first), `première suggestion pour « lyon » : ${first.replace(/\s+/g, ' ')}`);
	await page.keyboard.press('ArrowDown');
	check(await page.locator(input).getAttribute('aria-activedescendant') !== null, 'navigation clavier dans les suggestions (aria-activedescendant)');
	await page.keyboard.press('Enter');
	await page.waitForSelector(`${root} .saito-sl__card`);
	const cards = await page.locator(`${root} .saito-sl__card`).count();
	check(cards >= 4, `${cards} magasins listés après la recherche`);
	check(/km|m\b/.test(await page.locator(`${root} .saito-sl__distance`).first().innerText()), 'distance affichée sur chaque fiche');
	check(/plus proches de Lyon/.test(await page.locator(`${root} .saito-sl__status`).textContent()), 'résultat annoncé aux lecteurs d\'écran');
	check(/^\d+ points de vente près de Lyon/.test(await page.locator(`${root} .saito-sl__panel-title`).innerText()), `titre de la liste avec le contexte (« ${await page.locator(`${root} .saito-sl__panel-title`).innerText()} »)`);
	check((await page.locator(`${root} .saito-sl__status`).boundingBox()).height <= 1, 'pas de phrase en double au-dessus de la carte (ordinateur)');
	// « J'Y VAIS » : choix de l'application d'itinéraire.
	const go = page.locator(`${root} .saito-sl__card .saito-sl__go`).first();
	await go.locator('summary').click();
	const links = await go.locator('.saito-sl__go-link').evaluateAll((as) => as.map((a) => a.textContent + ' ' + a.href));
	check(links.length === 3 && /Google Maps https:\/\/www\.google\.com\/maps\/dir/.test(links[0]) && /Apple Plans https:\/\/maps\.apple\.com\/\?daddr=/.test(links[1]) && /Waze https:\/\/waze\.com\/ul\?ll=/.test(links[2]), '« J\'Y VAIS » propose Google Maps, Apple Plans et Waze');
	await page.keyboard.press('Escape');
	check(!(await go.evaluate((d) => d.open)), 'Échap referme le choix d\'application');

	// Molette sans Ctrl : la carte ne zoome pas (la page défile).
	const zoomBefore = await page.evaluate(() => document.querySelector('.saito-sl__map .leaflet-proxy').style.transform);
	const mapBox = await page.locator(`${root} .saito-sl__map`).boundingBox();
	await page.mouse.move(mapBox.x + mapBox.width - 120, mapBox.y + mapBox.height / 2);
	await page.mouse.wheel(0, -400);
	await page.waitForTimeout(600);
	const zoomAfter = await page.evaluate(() => document.querySelector('.saito-sl__map .leaflet-proxy').style.transform);
	check(zoomBefore === zoomAfter, 'molette sans Ctrl : pas de zoom involontaire de la carte');
	await page.screenshot({ path: `${out}/1-recherche-lyon.png` });

	// Clic sur une fiche : popup ouvert et fiche mise en évidence.
	await page.locator(`${root} .saito-sl__card-title`).nth(1).click();
	await page.waitForSelector(`${root} .leaflet-popup .saito-sl-popup`, { timeout: 5000 });
	check(true, 'clic sur une fiche : popup du magasin ouvert');
	check(await page.locator(`${root} .saito-sl__card.is-active`).count() === 1, 'fiche active mise en évidence');
	await page.screenshot({ path: `${out}/2-fiche-ouverte.png` });

	// Escape ferme la liste ; code postal à 4 chiffres ; magasins étrangers ; noms de magasins.
	await page.fill(input, '');
	await page.fill(input, '1400');
	await page.locator(`${root} .saito-sl__suggestion--place`, { hasText: '01400' }).first().waitFor();
	check(/01400/.test(await page.locator(`${root} .saito-sl__suggestions`).innerText()), 'code postal saisi sans le zéro initial (1400 → 01400)');
	await page.keyboard.press('Escape');
	check(await page.locator(`${root} .saito-sl__suggestions`).isHidden(), 'Échap ferme les suggestions');

	await page.fill(input, 'luxembourg');
	await page.locator(`${root} .saito-sl__suggestion`, { hasText: 'Luxembourg' }).first().waitFor();
	check(/Luxembourg/.test(await page.locator(`${root} .saito-sl__suggestions`).innerText()), 'ville d\'un magasin hors de France trouvée');

	await page.fill(input, 'culey');
	await page.locator(`${root} .saito-sl__suggestion--place`, { hasText: 'Culey' }).first().click();
	await page.waitForFunction(() => /Culey/.test(document.querySelector('.saito-sl__status').textContent));
	check(true, 'commune autrefois sans coordonnées (Culey) utilisable');

	await page.fill(input, 'maison dupont nice');
	await page.locator(`${root} .saito-sl__suggestion--store`, { hasText: 'NICE' }).first().click();
	await page.waitForSelector(`${root} .leaflet-popup .saito-sl-popup`, { timeout: 5000 });
	await page.waitForTimeout(1500); // Fin des animations de zoom.
	const popups = await page.locator(`${root} .leaflet-popup`).allInnerTexts();
	check(popups.length === 1 && /NICE/i.test(popups[0]), `recherche par nom de magasin : un seul popup ouvert, sur le bon magasin (${popups.length})`);

	await page.fill(input, 'zzzzzz');
	await page.waitForSelector(`${root} .saito-sl__suggestion--message`);
	check(/Aucun résultat/.test(await page.locator(`${root} .saito-sl__suggestions`).innerText()), 'message « aucun résultat »');

	// Survol lié : fiche → marqueur.
	await page.fill(input, 'bordeaux');
	await page.locator(`${root} .saito-sl__suggestion--place`, { hasText: '33000' }).first().click();
	await page.waitForSelector(`${root} .saito-sl__card`);
	await page.waitForTimeout(900);
	await page.locator(`${root} .saito-sl__card`).first().hover();
	check(await page.locator(`${root} .saito-sl-pin.is-hot, ${root} .saito-sl-cluster.is-hot`).count() === 1, 'survol d\'une fiche : son marqueur est mis en avant sur la carte');
	await page.locator(`${root} .saito-sl__label`).hover();
	check(await page.locator(`${root} .saito-sl-pin.is-hot, ${root} .saito-sl-cluster.is-hot`).count() === 0, 'fin du survol : marqueur rétabli');

	// Fiche magasin en fenêtre, avec URL propre.
	const baseUrl = page.url();
	await page.locator(`${root} .saito-sl__card .saito-sl__more`).first().click();
	await page.waitForSelector(`${root} .saito-sl__modal[open] .saito-sl__modal-title`);
	check(await page.evaluate(() => document.querySelector('.saito-sl__modal').matches(':modal')), '« Voir la fiche » ouvre la fiche en fenêtre (sans changer de page)');
	check(/[?&]magasin=[a-z0-9-]+/.test(page.url()), `URL propre au magasin : ${page.url().replace(/^.*\?/, '?')}`);
	check(/adresse, horaires et téléphone/.test(await page.title()), 'titre de l\'onglet propre au magasin');
	await page.keyboard.press('Escape');
	await page.waitForTimeout(300);
	check(!(await page.evaluate(() => document.querySelector('.saito-sl__modal').open)) && page.url() === baseUrl, 'Échap ferme la fiche et restaure l\'URL');
	await page.locator(`${root} .saito-sl__card .saito-sl__more`).nth(1).click();
	await page.waitForSelector(`${root} .saito-sl__modal[open]`);
	await page.goBack();
	await page.waitForTimeout(300);
	check(!(await page.evaluate(() => document.querySelector('.saito-sl__modal').open)), 'bouton Précédent : la fiche se ferme');
	await page.goForward();
	await page.waitForTimeout(300);
	check(await page.evaluate(() => document.querySelector('.saito-sl__modal').open), 'bouton Suivant : la fiche se rouvre');
	await page.keyboard.press('Escape');

	// Liste repliable.
	await page.locator(`${root} .saito-sl__panel-toggle`).click();
	const collapsed = await page.locator(`${root} .saito-sl__panel`).waitFor({ state: 'hidden', timeout: 3000 }).then(() => true, () => false);
	check(collapsed && await page.locator(`${root} .saito-sl__panel-open`).isVisible(), 'liste repliable pour voir toute la carte');
	await page.locator(`${root} .saito-sl__panel-open`).click();
	const expanded = await page.locator(`${root} .saito-sl__panel`).waitFor({ state: 'visible', timeout: 3000 }).then(() => true, () => false);
	check(expanded, 'liste réaffichée');

	// Noir et blanc uniquement : aucune couleur saturée dans l'interface (hors fond de carte).
	const colored = await page.evaluate(() => {
		const bad = [];
		const sat = (c) => {
			const m = c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)/);
			return m && Math.max(m[1], m[2], m[3]) - Math.min(m[1], m[2], m[3]) > 12;
		};
		document.querySelectorAll('.saito-sl *').forEach((e) => {
			if (e.closest('.leaflet-tile-pane')) return;
			const cs = getComputedStyle(e);
			['color', 'backgroundColor', 'borderTopColor', 'fill', 'stroke'].forEach((p) => {
				if (cs[p] && sat(cs[p])) bad.push((e.className.baseVal ?? e.className) + ' ' + p + ' ' + cs[p]);
			});
		});
		return [...new Set(bad)].slice(0, 5);
	});
	check(colored.length === 0, `interface uniquement en noir, blanc et gris${colored.length ? ' : ' + colored.join(' | ') : ''}`);

	check((requests['communes.min.json'] || 0) === 1, `fichier des communes téléchargé une seule fois (${requests['communes.min.json'] || 0})`);
	check(errors.length === 0, `aucune erreur JavaScript${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

/* ---------------- Fiche ouverte depuis son URL ---------------- */
{
	const sep = url.includes('?') ? '&' : '?';
	const { page, errors, context } = await newPage({ width: 1280, height: 900 });
	await page.goto(url + sep + 'magasin=maison-dupont-le-bouscat', { waitUntil: 'networkidle' });
	await page.waitForFunction(() => document.querySelector('.saito-sl__modal').matches(':modal'));
	const sheet = await page.locator('.saito-sl__modal').innerText();
	check(/Le Bouscat/.test(sheet) && /Lundi/.test(sheet) && /01 99 00 20 40/.test(sheet), 'lien direct : fiche ouverte avec adresse, horaires et téléphone');
	check(/^(Ouvert|Fermé)/m.test(await page.locator('.saito-sl__modal .saito-sl__open-state').innerText()), 'état « Ouvert / Fermé » calculé dans le fuseau horaire du site');
	await page.screenshot({ path: `${out}/4-fiche-magasin.png` });
	check(await page.locator('.saito-sl__modal .saito-sl__show-map').count() === 0, 'pas de bouton « Voir sur la carte » redondant dans la fiche');
	await page.locator('.saito-sl__modal .saito-sl__modal-close').click();
	await page.waitForTimeout(1500);
	check(!(await page.evaluate(() => document.querySelector('.saito-sl__modal').open)) && /Le Bouscat/.test(await page.locator('.leaflet-popup').innerText()), 'lien direct : à la fermeture, le magasin est montré sur la carte');
	check(errors.length === 0, `aucune erreur JavaScript (fiche)${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

/* ---------------- Zone, « Voir plus », mémoire ---------------- */
{
	const { page, errors, context } = await newPage({ width: 1280, height: 900 });
	await page.waitForSelector(`${root} .saito-sl-cluster, ${root} .saito-sl-pin`);
	await page.fill(input, 'paris');
	await page.waitForTimeout(250);
	check(!/Recherche en cours/.test(await page.locator(root).innerText()), 'plus de texte « Recherche en cours… »');
	await page.locator(`${root} .saito-sl__suggestion--place`, { hasText: '75001' }).first().click();
	await page.waitForSelector(`${root} .saito-sl__card`);
	await page.waitForTimeout(1200);
	check(await page.locator(`${root} .saito-sl__area`).isHidden(), '« Rechercher dans cette zone » masqué après une recherche');

	// « Voir plus ».
	const before = await page.locator(`${root} .saito-sl__card`).count();
	await page.locator(`${root} .saito-sl__more-results`).click();
	await page.waitForTimeout(300);
	const after = await page.locator(`${root} .saito-sl__card`).count();
	check(after > before, `« Voir plus » ajoute des points de vente (${before} → ${after})`);

	// Déplacement de la carte par le visiteur → « Rechercher dans cette zone ».
	const box = await page.locator(`${root} .saito-sl__map`).boundingBox();
	await page.mouse.move(box.x + box.width - 150, box.y + box.height / 2);
	await page.mouse.down();
	await page.mouse.move(box.x + box.width - 450, box.y + box.height / 2 - 120, { steps: 12 });
	await page.mouse.up();
	await page.locator(`${root} .saito-sl__area`).waitFor({ state: 'visible', timeout: 3000 });
	check(true, '« Rechercher dans cette zone » apparaît quand on déplace la carte');
	await page.locator(`${root} .saito-sl__area`).click();
	await page.waitForTimeout(400);
	check(/dans cette zone/.test(await page.locator(`${root} .saito-sl__status`).textContent()), 'recherche dans la zone visible');

	// Application d'itinéraire mémorisée.
	await page.locator(`${root} .saito-sl__card .saito-sl__go summary`).first().click();
	const [popup] = await Promise.all([
		context.waitForEvent('page'),
		page.locator(`${root} .saito-sl__card .saito-sl__go-link--waze`).first().click()
	]);
	await popup.close();

	// Rechargement : dernière recherche et Waze en premier.
	await page.reload({ waitUntil: 'networkidle' });
	await page.waitForSelector(`${root} .saito-sl__card`, { timeout: 8000 });
	check(/Votre dernière recherche/.test(await page.locator(`${root} .saito-sl__status`).textContent()) && /Paris/.test(await page.locator(input).inputValue()), 'dernière recherche réaffichée au retour sur la page');
	const firstApp = await page.locator(`${root} .saito-sl__card .saito-sl__go-link`).first().textContent();
	check(firstApp === 'Waze', `application d'itinéraire choisie proposée en premier (${firstApp})`);
	check(errors.length === 0, `aucune erreur JavaScript (zone, mémoire)${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

/* ---------------- Filtres ---------------- */
{
	const { page, errors, context } = await newPage({ width: 1280, height: 900 });
	await page.waitForSelector(`${root} .saito-sl__chip`);
	const chips = await page.locator(`${root} .saito-sl__chip`).allInnerTexts();
	check(chips.length >= 3, `filtres affichés : ${chips.map((c) => c.replace(/\s+/g, ' ')).join(' | ')}`);
	const brandChip = page.locator(`${root} .saito-sl__chip--brand`).nth(1);
	const brand = (await brandChip.innerText()).replace(/\s*\d+$/, '').trim();
	await page.fill(input, 'paris');
	await page.locator(`${root} .saito-sl__suggestion--place`).first().click();
	await page.waitForSelector(`${root} .saito-sl__card`);
	await brandChip.click();
	await page.waitForTimeout(400);
	const names = await page.locator(`${root} .saito-sl__card .saito-sl__eyebrow`).allInnerTexts();
	check(names.length > 0 && names.every((n) => n.trim().toUpperCase() === brand.toUpperCase()), `filtre « ${brand} » : seules ses fiches restent (${names.length})`);
	check(await brandChip.getAttribute('aria-pressed') === 'true', 'filtre actif signalé (aria-pressed)');
	await page.locator(`${root} .saito-sl__chip--all`).click();
	await page.waitForTimeout(300);
	check(await page.locator(`${root} .saito-sl__chip--all`).getAttribute('aria-pressed') === 'true', '« Tous » réinitialise les filtres');
	check(errors.length === 0, `aucune erreur JavaScript (filtres)${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

/* ---------------- Géolocalisation + mobile ---------------- */
{
	const { page, errors, context } = await newPage({ width: 390, height: 844 }, { latitude: 48.8566, longitude: 2.3522 });
	// Permission déjà accordée : la position est utilisée sans clic.
	await page.waitForSelector(`${root} .saito-sl__card`, { timeout: 8000 });
	check(/votre position/.test(await page.locator(`${root} .saito-sl__status`).textContent()), 'géolocalisation déjà autorisée utilisée automatiquement');
	check(/origin=48\.8566/.test(await page.locator(`${root} .saito-sl__card .saito-sl__go-link--google`).first().getAttribute('href')), 'itinéraire depuis la position du visiteur');
	check(await page.locator(input).isVisible() && await page.locator(`${root} .saito-sl__results`).isVisible() && !(await page.locator(`${root} .saito-sl__map`).isVisible()), 'mobile : résultats affichés en vue Liste');
	await page.locator(`${root} .saito-sl__view[data-view="map"]`).click();
	check(await page.locator(`${root} .saito-sl__map`).isVisible() && !(await page.locator(`${root} .saito-sl__results`).isVisible()), 'mobile : bascule vers la vue Carte');
	await page.locator(`${root} .saito-sl__view[data-view="list"]`).click();
	await page.locator(`${root} .saito-sl__card-title`).nth(1).click();
	await page.waitForSelector(`${root} .leaflet-popup .saito-sl-popup`, { timeout: 5000 });
	check(await page.locator(`${root} .saito-sl__map`).isVisible(), 'mobile : un clic sur une fiche ouvre la carte sur le magasin');
	check(/Liste \(\d+\)/.test(await page.locator(`${root} .saito-sl__view[data-view="list"]`).innerText()), 'mobile : nombre de résultats sur le bouton Liste');
	await page.evaluate(() => window.scrollTo(0, 1200));
	const searchTop = await page.locator(`${root} .saito-sl__search`).evaluate((e) => e.getBoundingClientRect().top);
	check(searchTop >= -1 && searchTop < 5, `mobile : barre de recherche toujours visible en haut (${Math.round(searchTop)} px)`);
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
	check(!overflow, 'pas de défilement horizontal sur mobile');
	await page.screenshot({ path: `${out}/3-mobile-geoloc.png`, fullPage: true });
	check(errors.length === 0, `aucune erreur JavaScript (mobile)${errors.length ? ' : ' + errors.join(' | ') : ''}`);
	await context.close();
}

{
	const { page, context } = await newPage({ width: 1280, height: 900 });
	check(await page.locator(`${root} .saito-sl__card`).count() === 0, 'sans autorisation : aucune demande de position au chargement');
	await context.close();
}

await browser.close();
console.log(failures ? `\n${failures} échec(s)` : '\nTous les tests navigateur sont passés.');
process.exit(failures ? 1 : 0);
