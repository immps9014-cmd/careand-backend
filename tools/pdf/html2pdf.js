#!/usr/bin/env node
// HTML → PDF (A4). 큐 워커(root)가 부른다: node html2pdf.js <in.html> <out.pdf>
// 이 서버 EDR 이 --remote-debugging-port 크롬을 죽이므로 playwright-core(파이프 통신)로만 띄운다.
// 외부 요청은 전부 막는다 — 서명 이미지·글꼴은 HTML 안(data URI·시스템 Noto CJK)에 있어야 한다.
const { chromium } = require('playwright-core');
const fs = require('fs');
const [, , input, output] = process.argv;
const exe = process.env.CHROMIUM_PATH || '/root/.cache/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-linux64/chrome-headless-shell';
(async () => {
  const b = await chromium.launch({ headless: true, executablePath: exe, args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
  try {
    const p = await b.newPage();
    await p.route('**/*', (r) => (r.request().url().startsWith('file:') || r.request().url().startsWith('data:') ? r.continue() : r.abort()));
    await p.setContent(fs.readFileSync(input, 'utf8'), { waitUntil: 'load' });
    await p.pdf({ path: output, format: 'A4', printBackground: true, margin: { top: '16mm', bottom: '16mm', left: '14mm', right: '14mm' } });
  } finally {
    await b.close();
  }
})().catch((e) => { console.error(e.message); process.exit(1); });
