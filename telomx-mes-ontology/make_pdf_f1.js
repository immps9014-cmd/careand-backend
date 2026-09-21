// infurs 품목 마스터 통합 검토(F1) — 아티팩트 HTML 을 A4 PDF 로 인쇄한다.
// 사용: node /root/telomx-mes-ontology/make_pdf_term.js
// 사용: node /root/telomx-mes-ontology/make_pdf.js
const { chromium } = require('/root/caren/careand-www/.ds-sync/node_modules/playwright-core');
const fs = require('fs');
const os = require('os');
const path = require('path');

const SRC = '/root/telomx-mes-ontology/F1-ITEM-MASTER.html';
const OUT = '/root/telomx-mes-ontology/pdf/infurs-품목마스터-통합검토-F1-r1.8.pdf';

// 아티팩트 호스트가 붙이는 껍데기 + 인쇄용 CSS
const PRINT_CSS = `
  @page { size: A4; margin: 15mm 12mm 17mm 12mm; }
  :root { color-scheme: light; }
  html, body { background: #fff !important; }
  body { font-size: 10.5pt; }
  .wrap { max-width: none; padding-inline: 0; padding-block: 0; }
  .mast { padding-top: 0; }
  section { margin-top: 26pt; }
  h2.sec-title, h3, h4, .sec-head, .phase h4 { break-after: avoid; }
  .callout, figure, .phase, .pull, thead, tr, .principles li, .docmeta { break-inside: avoid; }
  .scroll { overflow: visible; border-radius: 0; }
  table { font-size: 8.8pt; }
  th, td { padding: 6px 8px; }
  td.mono, th.mono { font-size: 8pt; }
  figure svg { min-width: 0; }
  figure .fbox { overflow: visible; box-shadow: none; }
  .phase { box-shadow: none; }
  .callout { box-shadow: none; }
  .toc ol { break-inside: avoid; }
  a { text-decoration: none; }
  footer { display: none; }
`;

(async () => {
  const html = fs.readFileSync(SRC, 'utf8');
  const wrapped = `<!doctype html><html lang="ko"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:0;font:14px -apple-system,BlinkMacSystemFont,sans-serif;background:#faf9f5;color:#141413}img{max-width:100%}[hidden]{display:none!important}</style>
</head><body>${html}<style>${PRINT_CSS}</style></body></html>`;
  const tmp = path.join(os.tmpdir(), 'tx-terms.print.html');   // 원본 디렉터리를 더럽히지 않는다
  fs.writeFileSync(tmp, wrapped);

  const browser = await chromium.launch({
    executablePath: '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
  });
  const ctx = await browser.newContext({ viewport: { width: 1040, height: 1400 }, colorScheme: 'light', locale: 'ko-KR' });
  // 폰트 요청마다 8초 상한 — 매달린 요청 하나가 load 를 영원히 막지 않게.
  await ctx.route(/^https:\/\/fonts\.(googleapis|gstatic)\.com\//, async route => {
    try {
      const r = await route.fetch({ timeout: 8000 });
      return route.fulfill({ status: r.status(), headers: r.headers(), body: await r.body() });
    } catch (e) {
      console.error('font request dropped:', route.request().url().slice(0, 80));
      return route.abort();
    }
  });
  const page = await ctx.newPage();
  await page.goto('file://' + tmp, { waitUntil: 'load', timeout: 300000 });
  await Promise.race([page.evaluate(() => document.fonts.ready), page.waitForTimeout(25000)]);
  const fonts = await page.evaluate(() => [...new Set([...document.fonts].filter(f => f.status === 'loaded').map(f => f.family))]);
  if (fonts.length < 2) console.error('경고: 웹폰트 미적재 — 설치 폰트로 진행', fonts);

  // 발행 전 한 번만 보는 확인용 스크린샷(화면 모드) — 회의 워크시트라 확인란이 인쇄·화면 양쪽에서 보여야 한다.
  await page.screenshot({ path: '/tmp/claude-0/-root/83274fe9-a830-4a33-8d4d-53ff24446c16/scratchpad/term-map.png', fullPage: false });
  await page.emulateMedia({ media: 'print' });
  await page.pdf({
    path: OUT, format: 'A4', printBackground: true, preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: `<div style="width:100%;font-size:7pt;color:#64706C;font-family:'IBM Plex Mono',monospace;padding:0 12mm;display:flex;justify-content:space-between">
      <span>infurs 품목 마스터 통합 검토 · TX-F1-ITEM r1.8 · 2026-09-21</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`,
    margin: { top: '15mm', bottom: '17mm', left: '12mm', right: '12mm' },
  });
  await browser.close();
  console.log(JSON.stringify({ out: OUT, bytes: fs.statSync(OUT).size, fonts }, null, 1));
})().catch(e => { console.error(e); process.exit(1); });
