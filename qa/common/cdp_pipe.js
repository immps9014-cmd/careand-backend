// CDP over pipe (--remote-debugging-pipe): TCP 리스닝 소켓 없이 크롬 구동.
const { spawn } = require('child_process');
const fs = require('fs');
const CHROME = '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome';
const sleep = ms => new Promise(r => setTimeout(r, ms));

function makeCDP(extraArgs = []) {
  const child = spawn(CHROME, [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
    '--hide-scrollbars', '--mute-audio', '--disable-extensions',
    '--user-data-dir=/tmp/cprofpipe', '--window-size=1440,900',
    '--ignore-certificate-errors',
    '--host-resolver-rules=MAP careand.aiclaude.kr 127.0.0.1',
    '--remote-debugging-pipe', ...extraArgs, 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'inherit', 'pipe', 'pipe'] });

  const wpipe = child.stdio[3]; // 우리가 write → 크롬 read
  const rpipe = child.stdio[4]; // 크롬 write → 우리가 read
  let buf = Buffer.alloc(0);
  let id = 0;
  const pending = {};
  const evWaiters = [];
  rpipe.on('data', chunk => {
    buf = Buffer.concat([buf, chunk]);
    let i;
    while ((i = buf.indexOf(0)) !== -1) {
      const msg = buf.slice(0, i).toString('utf8');
      buf = buf.slice(i + 1);
      let m; try { m = JSON.parse(msg); } catch { continue; }
      if (m.id && pending[m.id]) { pending[m.id](m); delete pending[m.id]; }
      else if (m.method) evWaiters.forEach(w => w(m));
    }
  });
  const send = (method, params = {}, sessionId) => new Promise((res, rej) => {
    const mid = ++id;
    pending[mid] = m => m.error ? rej(new Error(method + ': ' + JSON.stringify(m.error))) : res(m.result);
    const o = { id: mid, method, params };
    if (sessionId) o.sessionId = sessionId;
    wpipe.write(JSON.stringify(o) + '\0');
  });
  const waitEvent = (name, timeout = 8000) => new Promise(res => {
    const t = setTimeout(() => { const ix = evWaiters.indexOf(w); if (ix >= 0) evWaiters.splice(ix, 1); res(null); }, timeout);
    const w = m => { if (m.method === name) { clearTimeout(t); const ix = evWaiters.indexOf(w); if (ix >= 0) evWaiters.splice(ix, 1); res(m.params); } };
    evWaiters.push(w);
  });
  return { child, send, waitEvent };
}

(async () => {
  const cdp = makeCDP();
  await sleep(1500);
  try {
    const v = await cdp.send('Browser.getVersion');
    fs.writeFileSync('/tmp/pipe_smoke.txt', 'OK ' + v.product + '\n');
    console.log('CDP OK:', v.product);
  } catch (e) {
    fs.writeFileSync('/tmp/pipe_smoke.txt', 'FAIL ' + e.message + '\n');
    console.log('CDP FAIL', e.message);
  }
  cdp.child.kill('SIGKILL');
  process.exit(0);
})();
