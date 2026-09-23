#!/usr/bin/env node
// 온톨로지 산출물을 telomx PMS(프로젝트 OPS-2026-01) 문서관리에 올린다.
//
//   새 문서:   node pms_push.js new  <파일> --title "..." [--cat spec|report|meet|etc] [--status draft|review|final] [--note "..."] [--url <아티팩트 주소>] [--task <업무 id>]
//   개정본:    node pms_push.js rev  <문서번호|문서id> <파일> --note "r1.2 — 무엇을 고쳤는지"
//   정보수정:  node pms_push.js meta <문서번호|문서id> [--title "..."] [--status draft|review|final]   (파일은 그대로)
//   목록:      node pms_push.js list
//
// PMS API를 그대로 쓴다(문서번호 자동부여·버전 증가·활동이력을 서버에 맡긴다).
const fs = require('fs'), path = require('path');
const BE = '/var/www/telomx-backend';
require(BE + '/node_modules/dotenv').config({ path: BE + '/.env' });
const jwt = require(BE + '/node_modules/jsonwebtoken');

const API = 'http://127.0.0.1:3200/api/pms';
const PROJECT_ID = 11;                 // OPS-2026-01 · 3사 MES 온톨로지
const DOCS_TASK_ID = 17;               // 설계·문서화 업무
const TOKEN = jwt.sign(
  { id: 5, email: 'immps9014@gmail.com', name: '강종규', role: 'owner' },
  process.env.JWT_SECRET, { expiresIn: '10m' });

const argv = process.argv.slice(2);
const opt = (name, def = null) => {
  const i = argv.indexOf('--' + name);
  return i === -1 ? def : argv[i + 1];
};
const positional = argv.filter((a, i) => !a.startsWith('--') && !(i > 0 && argv[i - 1].startsWith('--')));

async function call(method, url, body) {
  const r = await fetch(API + url, {
    method, headers: { Authorization: 'Bearer ' + TOKEN, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(`${r.status} ${JSON.stringify(j)}`);
  return j;
}

async function send(url, fields, file) {
  if (!fs.existsSync(file)) throw new Error('파일이 없습니다: ' + file);
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) if (v != null) fd.append(k, String(v));
  const ext = path.extname(file).toLowerCase();
  const type = ext === '.pdf' ? 'application/pdf' : ext === '.html' ? 'text/html' : 'application/octet-stream';
  fd.append('file', new Blob([fs.readFileSync(file)], { type }), path.basename(file));
  const r = await fetch(API + url, { method: 'POST', headers: { Authorization: 'Bearer ' + TOKEN }, body: fd });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(`${r.status} ${JSON.stringify(j)}`);
  return j.data || j;
}

// 문서번호(OPS-2026-01-SPEC-001)로도, id 로도 찾을 수 있게 한다.
async function findDoc(key) {
  const { data } = await call('GET', `/documents?project_id=${PROJECT_ID}`);
  const hit = data.find(d => String(d.id) === String(key) || d.doc_no === key);
  if (!hit) throw new Error('문서를 찾지 못했습니다: ' + key);
  return hit;
}

(async () => {
  const cmd = positional[0];
  if (cmd === 'list') {
    const { data } = await call('GET', `/documents?project_id=${PROJECT_ID}`);
    for (const d of data) {
      console.log(`${d.doc_no}  v${d.current_version}(총${d.version_count})  ${d.status.padEnd(8)} ${d.title}`);
    }
    return;
  }
  if (cmd === 'new') {
    const file = positional[1];
    const d = await send('/documents', {
      // 산출물이 특정 업무의 것이면 --task 로 그 업무에 매단다(기본은 설계·문서화)
      project_id: PROJECT_ID, task_id: opt('task', DOCS_TASK_ID),
      category: opt('cat', 'report'), status: opt('status', 'draft'),
      title: opt('title') || path.basename(file, path.extname(file)),
      tags: opt('tags', '온톨로지'),
      // 아티팩트에서 나온 문서는 원본 주소를 비고에 남긴다 — PMS 가 아티팩트 목록 역할을 한다.
      note: [opt('doc-note'), opt('url') && '아티팩트 ' + opt('url')].filter(Boolean).join(' · ') || null,
      change_note: opt('note', '최초 등록'),
    }, file);
    console.log('등록', d.doc_no, d.title);
    return;
  }
  if (cmd === 'rev') {
    const doc = await findDoc(positional[1]);
    const v = await send(`/documents/${doc.id}/versions`, { change_note: opt('note') }, positional[2]);
    console.log('개정', doc.doc_no, `v${doc.current_version} → v${v.version}`);
    return;
  }
  if (cmd === 'meta') {
    // 개정판을 올린 뒤 제목의 판번호(r1.0 → r1.1)나 상태를 맞출 때 쓴다
    const doc = await findDoc(positional[1]);
    const body = {};
    if (opt('title')) body.title = opt('title');
    if (opt('status')) body.status = opt('status');
    if (!Object.keys(body).length) throw new Error('--title 또는 --status 가 필요합니다');
    await call('PATCH', `/documents/${doc.id}`, body);
    console.log('수정', doc.doc_no, JSON.stringify(body));
    return;
  }
  console.log(fs.readFileSync(__filename, 'utf8').split('\n').slice(1, 9).map(l => l.replace(/^\/\/ ?/, '')).join('\n'));
})().catch(e => { console.error('실패:', e.message); process.exit(1); });
