import { chromium } from 'playwright';
const [,, base, out] = process.argv;
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
const ua = 'SaitoStoreLocator-screenshots/1.0 (contact: plugin author)';

async function page(viewport, opts = {}) {
	const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, reducedMotion: 'reduce', locale: 'fr-FR', userAgent: ua, ...opts });
	const p = await ctx.newPage();
	return { ctx, p };
}
const settle = (p) => p.waitForTimeout(2500);

// 1. Bureau : recherche « Lyon ».
{
	const { ctx, p } = await page({ width: 1280, height: 800 });
	await p.goto(base + '/ou-nous-trouver/', { waitUntil: 'networkidle' });
	await p.fill('.saito-sl__input', 'lyon');
	await p.waitForSelector('.saito-sl__suggestion--place');
	await p.keyboard.press('ArrowDown');
	await p.keyboard.press('Enter');
	await p.waitForSelector('.saito-sl__card');
	await settle(p);
	await p.locator('.saito-sl').screenshot({ path: `${out}/screenshot-1.png`, animations: 'disabled' });
	// 2. Fiche magasin.
	await p.locator('.saito-sl__more').first().click();
	await p.waitForSelector('.saito-sl__modal[open] .saito-sl__sheet');
	await settle(p);
	await p.screenshot({ path: `${out}/screenshot-2.png`, animations: 'disabled' });
	await ctx.close();
}
// 3. Mobile.
{
	const { ctx, p } = await page({ width: 390, height: 844 }, { isMobile: true, hasTouch: true });
	await p.goto(base + '/ou-nous-trouver/', { waitUntil: 'networkidle' });
	await p.fill('.saito-sl__input', 'bordeaux');
	await p.locator('.saito-sl__suggestion--place', { hasText: '33000' }).first().click();
	await p.waitForSelector('.saito-sl__card');
	await settle(p);
	await p.locator('.saito-sl').scrollIntoViewIfNeeded();
	await p.screenshot({ path: `${out}/screenshot-3.png`, animations: 'disabled' });
	await ctx.close();
}
// 4. Rapport d'import (administration).
{
	const { ctx, p } = await page({ width: 1280, height: 900 });
	await p.goto(base + '/wp-login.php', { waitUntil: 'networkidle' });
	await p.fill('#user_login', 'admin');
	await p.fill('#user_pass', 'admin');
	await p.click('#wp-submit');
	await p.waitForLoadState('networkidle');
	await p.goto(base + '/wp-admin/admin.php?page=saito-store-locator', { waitUntil: 'networkidle' });
	await p.setInputFiles('input[name="csv_file"]', process.env.IMPORT_CSV);
	await p.click('form[enctype] button[type="submit"]');
	await p.waitForSelector('.saito-sl-preview');
	await p.screenshot({ path: `${out}/screenshot-4.png`, animations: 'disabled' });
	await p.goto(base + '/wp-admin/admin.php?page=saito-store-locator-settings', { waitUntil: 'networkidle' });
	await p.screenshot({ path: `${out}/admin-settings.png`, fullPage: true });
	await ctx.close();
}
await browser.close();
console.log('captures ok');
