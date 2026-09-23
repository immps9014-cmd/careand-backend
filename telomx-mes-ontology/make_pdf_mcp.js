// 온톨로지 MCP 확장안 — HTML 을 A4 PDF 로 인쇄한다.
// 사용: node /root/telomx-mes-ontology/make_pdf_mcp.js
const { chromium } = require('/root/caren/careand-www/.ds-sync/node_modules/playwright-core');
const SRC = '/root/telomx-mes-ontology/MCP-EXTENSION.html';
const OUT = '/root/telomx-mes-ontology/pdf/텔롬엑스-MES-온톨로지-MCP확장안-r1.2.pdf';
(async () => {
  const browser = await chromium.launch({
    executablePath: '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
  });
  const page = await (await browser.newContext({ locale: 'ko-KR' })).newPage();
  await page.goto('file://' + SRC, { waitUntil: 'load' });
  await page.pdf({ path: OUT, format: 'A4', printBackground: true, preferCSSPageSize: true,
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="font-size:7pt;width:100%;text-align:center;color:#888">TelomX · 온톨로지 MCP 확장안 TX-ONT-MCP r1.2 · <span class="pageNumber"></span>/<span class="totalPages"></span></div>' });
  await browser.close();
  console.log(OUT);
})();
