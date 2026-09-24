// 히센스 현장 점검표 — HTML 을 A4 PDF 로 인쇄한다. 사용: node /root/hisense-docs/make_pdf_checklist.js
const { chromium } = require('/root/caren/careand-www/.ds-sync/node_modules/playwright-core');
const SRC = '/root/hisense-docs/FIELD-CHECKLIST.html';
const OUT = '/root/hisense-docs/히센스-현장-점검표-20260924.pdf';
(async () => {
  const browser = await chromium.launch({
    executablePath: '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
  });
  const page = await (await browser.newContext({ locale: 'ko-KR', colorScheme: 'light' })).newPage();
  await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());   // egress 차단 서버 — 시스템 한글 폰트로
  await page.goto('file://' + SRC, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach(d => d.open = true));  // 대조표도 인쇄
  await page.pdf({ path: OUT, format: 'A4', printBackground: true,
    margin: { top: '12mm', bottom: '14mm', left: '9mm', right: '9mm' },
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="font-size:7pt;width:100%;text-align:center;color:#888">TelomX · 히센스 MES 현장 점검표 · 기준 2026-09-24 · <span class="pageNumber"></span>/<span class="totalPages"></span></div>' });
  await page.setViewportSize({ width: 1200, height: 1600 });
  await page.emulateMedia({ media: 'screen' });
  await page.evaluate(() => document.querySelector('.part:not(.first)').scrollIntoView());
  await page.screenshot({ path: '/tmp/claude-0/-root/ef96dcb9-5382-4827-afc8-1277f736a27c/scratchpad/ck_shot.png' });
  await browser.close();
  console.log(OUT);
})();
