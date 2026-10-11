const { chromium } = require('/root/caren/careand-member-web/.ds-sync/node_modules/playwright-core');
const fs = require('fs'); const D = __dirname + '/';
(async () => {
  const b = await chromium.launch({ headless: true, executablePath: process.env.HOME + '/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 512, height: 512 } });
  fs.mkdirSync(D + 'png', { recursive: true });
  const keys = fs.readdirSync(D + 'svg').filter(f => f.endsWith('.svg')).map(f => f.slice(0, -4)).sort();
  for (const k of keys) {
    await p.setContent(`<html><body style="margin:0">${fs.readFileSync(D + 'svg/' + k + '.svg', 'utf8')}</body></html>`);
    await p.locator('svg').screenshot({ path: D + `png/${k}.png` });
  }
  // 한눈에 보기
  const tiles = keys.map(k => `<div style="text-align:center;font:14px sans-serif"><img src="data:image/png;base64,${fs.readFileSync(D + 'png/' + k + '.png').toString('base64')}" width="160" style="border-radius:24px"><br>${k}</div>`).join('');
  const s = await b.newPage({ viewport: { width: 760, height: 420 } });
  await s.setContent(`<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:12px">${tiles}</div>`);
  await s.screenshot({ path: D + 'sheet.png' });
  await b.close();
})();
