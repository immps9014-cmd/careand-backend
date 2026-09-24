// MES 계층 아키텍처 — HTML 을 A4 PDF 로 인쇄한다.
// 사용: node /root/telomx-mes-ontology/make_pdf_mcp.js
const { chromium } = require('/root/caren/careand-www/.ds-sync/node_modules/playwright-core');
const SRC = '/root/hisense-docs/ORDER-WO-SCREENS.html';
const OUT = '/root/hisense-docs/히센스-수주작업지시-화면정리안-r1.0.pdf';
(async () => {
  const browser = await chromium.launch({
    executablePath: '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
  });
  const page = await (await browser.newContext({ locale: 'ko-KR' })).newPage();
  // fonts.googleapis.com 이 막히면 load 가 30초 넘게 멈춘다(2026-09-23 실측) — 웹폰트는 끊고 시스템 한글 폰트로 인쇄
  await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
  await page.goto('file://' + SRC, { waitUntil: 'load' });
  await page.pdf({ path: OUT, format: 'A4', printBackground: true, preferCSSPageSize: true,
    margin: { top: '14mm', bottom: '16mm', left: '10mm', right: '10mm' },   // 바닥글이 본문에 겹치지 않게
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="font-size:7pt;width:100%;text-align:center;color:#888">TelomX · 히센스 수주·작업지시 화면 정리안 HS-UX-01 r1.0 · <span class="pageNumber"></span>/<span class="totalPages"></span></div>' });
  await browser.close();
  console.log(OUT);
})();
