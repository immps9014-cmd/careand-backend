const WebSocket = require('/root/aiclaude-auth/node_modules/ws');
const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');

const CHROME = '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome';
const sleep = ms => new Promise(r => setTimeout(r, ms));
const L = m => { fs.appendFileSync('/tmp/smoke.log', `[${Date.now()%100000}] ${m}\n`); };
process.on('uncaughtException', e => { L('uncaught ' + e.stack); });
process.on('unhandledRejection', e => { L('unhandled ' + e); });

function httpJson(path) {
  return new Promise((res, rej) => {
    http.get({ host: '127.0.0.1', port: 9222, path }, r => {
      let d = ''; r.on('data', c => d += c); r.on('end', () => res(JSON.parse(d)));
    }).on('error', rej);
  });
}

(async () => {
  const proc = spawn(CHROME, [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
    '--remote-debugging-port=9222', '--user-data-dir=/tmp/cprof1',
    '--window-size=1440,900', '--ignore-certificate-errors',
    '--host-resolver-rules=MAP careand.aiclaude.kr 127.0.0.1',
    'about:blank',
  ], { stdio: 'ignore', detached: false });

  // wait for devtools
  let ver;
  for (let i = 0; i < 40; i++) { try { ver = await httpJson('/json/version'); break; } catch { await sleep(250); } }
  if (!ver) { console.error('devtools 미기동'); proc.kill(); process.exit(1); }
  const targets = await httpJson('/json');
  const page = targets.find(t => t.type === 'page');
  const ws = new WebSocket(page.webSocketDebuggerUrl, { perMessageDeflate: false, maxPayload: 256*1024*1024 });
  await new Promise(r => ws.on('open', r));

  let msgId = 0; const pending = {}; const waiters = [];
  ws.on('message', data => {
    const m = JSON.parse(data);
    if (m.id && pending[m.id]) { pending[m.id](m.result); delete pending[m.id]; }
    if (m.method) waiters.forEach(w => w(m));
  });
  const send = (method, params = {}) => new Promise(r => { const id = ++msgId; pending[id] = r; ws.send(JSON.stringify({ id, method, params })); });
  const onceEvent = name => new Promise(r => { const w = m => { if (m.method === name) { waiters.splice(waiters.indexOf(w), 1); r(m.params); } }; waiters.push(w); });

  await send('Page.enable');
  await send('Runtime.enable');

  const loaded = onceEvent('Page.loadEventFired');
  await send('Page.navigate', { url: 'https://careand.aiclaude.kr/app/login' });
  await Promise.race([loaded, sleep(8000)]);
  await sleep(2500);

  const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync('/tmp/smoke_login.png', Buffer.from(shot.data, 'base64'));
  const sz = fs.statSync('/tmp/smoke_login.png').size;
  console.log('스크린샷 저장:', sz, 'bytes');

  ws.close(); proc.kill();
  process.exit(0);
})().catch(e => { console.error('ERR', e); process.exit(1); });
