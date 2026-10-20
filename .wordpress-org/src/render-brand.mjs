/**
 * Bannières WordPress.org (même gabarit que Saito Navi, Saito FAQ et Saito Calendar Event).
 * Les icônes sont communes à tous les plugins Saito : copiées telles quelles depuis
 * navi-faq/.wordpress-org/assets/, pas générées ici.
 *
 * Police DejaVu Sans requise (absente de l'image Playwright, la monter depuis le système) :
 *   docker run --rm -v "$PWD/.wordpress-org/src:/b" \
 *     -v /usr/share/fonts/truetype/dejavu:/usr/share/fonts/truetype/dejavu:ro \
 *     mcr.microsoft.com/playwright:v1.64.1-noble bash -c \
 *     'fc-cache -f && mkdir /w && cd /w && npm i playwright@1.64.1 && cp /b/render-brand.mjs . && node render-brand.mjs'
 * puis copier banner-*.png dans .wordpress-org/assets/.
 */
import { chromium } from 'playwright';

const browser = await chromium.launch();
for (const [scale, out] of [[2, 'banner-1544x500.png'], [1, 'banner-772x250.png']]) {
	const context = await browser.newContext({ viewport: { width: 772, height: 250 }, deviceScaleFactor: scale });
	const page = await context.newPage();
	await page.goto('file:///b/banner.html');
	await page.waitForTimeout(300);
	await page.screenshot({ path: `/b/${out}` });
	await context.close();
}
await browser.close();
