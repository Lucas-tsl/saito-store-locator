import { chromium } from 'playwright';
const [,, dir] = process.argv;
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
const jobs = [
	['banner.html', 1544, 500, 1, 'banner-1544x500.png'],
	['banner.html', 1544, 500, 0.5, 'banner-772x250.png'],
	['icon.html', 256, 256, 1, 'icon-256x256.png'],
	['icon.html', 256, 256, 0.5, 'icon-128x128.png'],
];
for (const [file, w, h, scale, out] of jobs) {
	const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: scale });
	const p = await ctx.newPage();
	await p.goto(`file://${dir}/${file}`);
	await p.screenshot({ path: `${dir}/${out}` });
	await ctx.close();
}
await browser.close();
