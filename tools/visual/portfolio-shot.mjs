// Portfolio capture harness (DEV-ONLY, zero-dependency) — sibling of shot.mjs.
//
// WHY THIS IS NOT shot.mjs: that file is a GATE. It sweeps every screen × width ×
// locale at 1× and its job is to exit non-zero. This one produces ARTWORK: a few
// named screens at deviceScaleFactor 2, one page per process, optionally clipped
// to a region. Two different jobs; folding retina + clipping into the gate would
// have made the gate's failure semantics depend on framing options.
//
// ONE PAGE PER PROCESS is deliberate, not laziness. A retina full-page capture of
// the seeded queue is a ~4x-area bitmap; running the whole set in one browser
// session accumulated enough decode + transfer time to blow the CDP timeouts, and
// a partial sweep is worse than six clean runs because you cannot tell a slow
// page from a broken one. Each invocation launches Chrome, logs in, shoots, dies.
//
// It still REPORTS what the gate asserts — console errors, horizontal overflow,
// images that never painted — and exits non-zero on any of them, so a pretty
// screenshot of a broken page cannot be produced silently.
//
// Usage:
//   node tools/visual/portfolio-shot.mjs --path /library --out /tmp/library.png
//   node tools/visual/portfolio-shot.mjs --path /queue --out /tmp/q.png \
//        --clip '.approve-card' --clip-first 2 --clip-from-top
//   node tools/visual/portfolio-shot.mjs --path /queue --probe '.approve-card'

import { spawn } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';

const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf(n); return i !== -1 && args[i + 1] ? args[i + 1] : d; };
const flag = (n) => args.includes(n);

const BASE_URL = (opt('--base-url', process.env.VISUAL_BASE_URL || 'http://127.0.0.1:8099')).replace(/\/$/, '');
const PATH_ = opt('--path', '/dashboard');
const OUT = opt('--out', null);
const WIDTH = Number(opt('--width', '1280'));
const DSF = Number(opt('--dsf', '2'));
const CLIP_SEL = opt('--clip', null);
const CLIP_FIRST = Number(opt('--clip-first', '0'));   // 0 = all matches
const CLIP_FROM_TOP = flag('--clip-from-top');
const CLIP_PAD = Number(opt('--clip-pad', '0'));
// Default clip is a full-width horizontal STRIP (x:0, width:WIDTH) — right for
// a card that spans the layout. --clip-tight instead bounds x/width to the
// matched elements' own horizontal extent, for a small standalone element (a
// preview frame, a calendar cell) that would otherwise ship as a wide strip
// with the element floating in the middle of mostly-empty page background.
const CLIP_TIGHT = flag('--clip-tight');
// captureBeyondViewport asks Chrome to composite the whole page in one pass. On
// most screens that is the cheapest way to a full-page shot; on at least one
// (/dashboard, which carries a repeating live-poll timer) it never returns at
// all. --tall-viewport takes the other road: grow the emulated viewport to the
// page's own height and take an ordinary screenshot of it. Same pixels, and the
// page is measured first either way, so anything sized against the viewport is
// worth an eye before trusting the result.
const TALL_VIEWPORT = flag('--tall-viewport');
const PROBE = opt('--probe', null);
const SETTLE = Number(opt('--settle', '900'));
// A CDP call has no deadline of its own: if the renderer wedges, `await
// cdp.send(...)` never settles and never throws, so the harness sits there
// looking like slow work instead of failing. That is how a stuck capture ate a
// seven-minute budget in silence. Every unbounded step now races a clock.
const STEP_TIMEOUT = Number(opt('--step-timeout', '90000'));
const EMAIL = process.env.VISUAL_TEST_EMAIL || 'visual@kuyash.local';
const PASSWORD = process.env.VISUAL_TEST_PASSWORD || 'visual-dev-only-password';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const DEBUG_PORT = Number(opt('--port', '0')) || (9400 + Math.floor(Math.random() * 400));

if (!OUT && !PROBE) { console.error('need --out <file.png> (or --probe <selector>)'); process.exit(2); }

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const withTimeout = (promise, ms, label) => {
  let timer;
  return Promise.race([
    promise.finally(() => clearTimeout(timer)),
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(`${label} did not return within ${ms}ms`)), ms);
    }),
  ]);
};
const IGNORE_ERROR = (t) => /favicon\.ico/i.test(t || '');

class CDP {
  constructor(ws) {
    this.ws = ws; this.seq = 0; this.pending = new Map(); this.listeners = [];
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(typeof ev.data === 'string' ? ev.data : ev.data.toString());
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      } else if (msg.method) { for (const fn of this.listeners) fn(msg); }
    });
  }
  send(method, params = {}, sessionId) {
    const id = ++this.seq;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
  }
  on(fn) { this.listeners.push(fn); }
}

async function openWs(url) {
  const ws = new WebSocket(url);
  await new Promise((res, rej) => {
    ws.addEventListener('open', res, { once: true });
    ws.addEventListener('error', () => rej(new Error('websocket error: ' + url)), { once: true });
  });
  return ws;
}
async function browserWsUrl(port) {
  for (let i = 0; i < 120; i++) {
    try { const r = await fetch(`http://127.0.0.1:${port}/json/version`); if (r.ok) return (await r.json()).webSocketDebuggerUrl; } catch { /* not up */ }
    await sleep(100);
  }
  throw new Error('Chrome DevTools endpoint never came up');
}

const userDataDir = mkdtempSync(join(tmpdir(), 'kuyash-portfolio-'));
let chrome; let exitCode = 0;

try {
  chrome = spawn(CHROME, [
    '--headless=new', `--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${userDataDir}`,
    '--no-first-run', '--no-default-browser-check', '--hide-scrollbars',
    '--force-color-profile=srgb', '--disable-extensions', '--disable-background-networking', '--mute-audio',
  ], { stdio: ['ignore', 'ignore', 'ignore'] });
  // NOTE ON BIG SURFACES: a full-page retina capture is one bitmap, and past
  // roughly 2560x4200 this Chrome stops returning from Page.captureScreenshot
  // altogether — /plan (2560x4192) comes back in seconds, /dashboard
  // (2560x5790) never does, while the same dashboard clipped to a third of its
  // height takes three. --disable-gpu was tried and changed nothing, so it is
  // not the GPU path. Use --clip to stay under the ceiling on very tall pages.

  const cdp = new CDP(await openWs(await browserWsUrl(DEBUG_PORT)));
  const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  for (const d of ['Page', 'Runtime', 'Log', 'Network']) await cdp.send(`${d}.enable`, {}, sessionId);

  // DO NOT DOWNLOAD THE RAW CLIPS. A still capture needs the poster, which is a
  // separate file; the <video> bytes behind it are pure cost — the library and
  // the queue point at the workspace's real footage, and one 62MB clip stalled
  // a capture past a seven-minute budget while the page sat there looking idle.
  //
  // This changes no pixel: at rest a <video> paints its poster, which is exactly
  // what it paints with preload disabled. It is installed on the NEW DOCUMENT so
  // it runs before the parser reaches the first <video> — setting preload after
  // load has already lost the race that matters.
  await cdp.send('Page.addScriptToEvaluateOnNewDocument', {
    // Observe `document`, NOT `document.documentElement`: this runs at document
    // start, where documentElement is still null and observe() throws — which is
    // worse than not running at all, because the harness counts console errors
    // and would have failed the page for the instrumentation's own bug. The
    // try/catch is the second half of that lesson.
    source: `(() => {
      try {
        const strip = (el) => { if (el && el.tagName === 'VIDEO') { el.preload = 'none'; el.autoplay = false; } };
        new MutationObserver((records) => {
          for (const r of records) for (const n of r.addedNodes) {
            strip(n);
            if (n.querySelectorAll) n.querySelectorAll('video').forEach(strip);
          }
        }).observe(document, { childList: true, subtree: true });
        addEventListener('DOMContentLoaded', () => document.querySelectorAll('video').forEach(strip));
      } catch (e) { /* never let the instrumentation fail the page it measures */ }
    })()`,
  }, sessionId);

  let errors = [];
  cdp.on((msg) => {
    if (msg.sessionId !== sessionId) return;
    if (msg.method === 'Runtime.exceptionThrown') {
      const d = msg.params.exceptionDetails; const t = d.exception?.description || d.text || 'exception';
      if (!IGNORE_ERROR(t)) errors.push(`exception: ${t}`);
    } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
      const t = (msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ');
      if (!IGNORE_ERROR(t)) errors.push(`console.error: ${t}`);
    } else if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') {
      const t = `${msg.params.entry.text} ${msg.params.entry.url || ''}`.trim();
      if (!IGNORE_ERROR(t)) errors.push(`log.error: ${t}`);
    }
  });

  const loadEvent = () => new Promise((resolve) => {
    const fn = (m) => { if (m.sessionId === sessionId && m.method === 'Page.loadEventFired') { cdp.listeners = cdp.listeners.filter((l) => l !== fn); resolve(); } };
    cdp.on(fn);
    setTimeout(() => { cdp.listeners = cdp.listeners.filter((l) => l !== fn); resolve(); }, 20000);
  });
  const evaluate = async (e) => (await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true }, sessionId)).result?.value;
  const evaluateAsync = async (e) => (await cdp.send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }, sessionId)).result?.value;
  const go = async (p) => { const w = loadEvent(); await cdp.send('Page.navigate', { url: BASE_URL + p }, sessionId); await w; await sleep(SETTLE); };

  // Same reasoning as shot.mjs: reduced motion makes the count-up print its FINAL
  // value instead of whatever the settle timer happened to catch mid-animation.
  await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] }, sessionId);
  const setMetrics = () => cdp.send('Emulation.setDeviceMetricsOverride', { width: WIDTH, height: 900, deviceScaleFactor: DSF, mobile: false }, sessionId);
  await setMetrics();

  // login
  await go('/login');
  const li = loadEvent();
  await evaluate(`(() => { const e=document.querySelector('input[name=email]'),p=document.querySelector('input[name=password]');
    e.value=${JSON.stringify(EMAIL)}; p.value=${JSON.stringify(PASSWORD)};
    document.querySelector('form[action="/login"]').submit(); })()`);
  await li; await sleep(300);
  if ((await evaluate('location.pathname')) === '/login') throw new Error('still on /login — bad credentials or unseeded DB');

  await go(PATH_);
  await setMetrics(); // navigation can reset the override

  // Force every lazy image in BEFORE measuring or capturing — otherwise the tall
  // grids capture blank tiles and the "did it paint" count is a lie. (shot.mjs
  // learned this the hard way; the same trap applies to a captureBeyondViewport
  // retina shot, only more so, because the page is taller than the viewport.)
  const media = await withTimeout(evaluateAsync(`(async () => {
    const imgs = Array.from(document.images).filter(i => i.getAttribute('src'));
    imgs.forEach(i => { if (i.loading === 'lazy') i.loading = 'eager'; });
    await Promise.all(imgs.map(i => i.complete ? Promise.resolve() : new Promise(res => {
      i.addEventListener('load', res, { once: true });
      i.addEventListener('error', res, { once: true });
      setTimeout(res, 6000);
    })));
    const broken = imgs.filter(i => i.naturalWidth === 0).map(i => i.getAttribute('src'));

    /* POSTERS COUNT TOO. The approval queue and the library paint their frames
       through <video poster="">, not <img> (ADR-025), so an <img>-only tally
       reports 0 painted on exactly the screens whose previews matter most —
       a green "no broken images" over six black boxes. Probed the same way
       shot.mjs does it. */
    const posters = Array.from(document.querySelectorAll('video[poster]')).map(v => v.getAttribute('poster'));
    const posterBad = (await Promise.all(posters.map(src => new Promise(res => {
      const probe = new Image();
      probe.onload = () => res(probe.naturalWidth === 0 ? src : null);
      probe.onerror = () => res(src);
      setTimeout(() => res(src), 6000);
      probe.src = src;
    })))).filter(Boolean);

    return {
      broken: broken.concat(posterBad),
      rendered: imgs.filter(i => i.naturalWidth > 0).length + (posters.length - posterBad.length),
      total: imgs.length + posters.length,
    };
  })()`), STEP_TIMEOUT, 'forcing images/posters to load') || { broken: [], rendered: 0, total: 0 };

  const overflow = Number(await evaluate('Math.max(0, document.documentElement.scrollWidth - window.innerWidth)')) || 0;
  const pageH = Number(await evaluate('document.documentElement.scrollHeight')) || 0;

  if (PROBE) {
    const info = await evaluate(`JSON.stringify(Array.from(document.querySelectorAll(${JSON.stringify(PROBE)})).map((el,i) => {
      const r = el.getBoundingClientRect();
      return { i, cls: el.className, top: Math.round(r.top + scrollY), h: Math.round(r.height), w: Math.round(r.width),
               text: (el.innerText||'').slice(0,70).replace(/\\s+/g,' ') };
    }))`);
    console.log(JSON.stringify({ path: PATH_, width: WIDTH, pageHeight: pageH, overflow, errors, media, matches: JSON.parse(info || '[]') }, null, 2));
  } else {
    let clip = null;
    if (CLIP_SEL) {
      const raw = await evaluate(`(() => {
        let els = Array.from(document.querySelectorAll(${JSON.stringify(CLIP_SEL)}));
        if (els.length === 0) return null;
        if (${CLIP_FIRST} > 0) els = els.slice(0, ${CLIP_FIRST});
        let top = Infinity, bottom = -Infinity, left = Infinity, right = -Infinity;
        for (const el of els) {
          const r = el.getBoundingClientRect();
          top = Math.min(top, r.top + scrollY); bottom = Math.max(bottom, r.bottom + scrollY);
          left = Math.min(left, r.left + scrollX); right = Math.max(right, r.right + scrollX);
        }
        return JSON.stringify({ top, bottom, left, right });
      })()`);
      if (!raw) throw new Error(`--clip selector matched nothing: ${CLIP_SEL}`);
      const { top, bottom, left, right } = JSON.parse(raw);
      const y = CLIP_FROM_TOP ? 0 : Math.max(0, Math.floor(top - CLIP_PAD));
      const h = Math.ceil(bottom + CLIP_PAD - y);
      const x = CLIP_TIGHT ? Math.max(0, Math.floor(left - CLIP_PAD)) : 0;
      const w = CLIP_TIGHT ? Math.ceil(right + CLIP_PAD - x) : WIDTH;
      // scale: 1, not DSF — the emulated deviceScaleFactor (already set via
      // Emulation.setDeviceMetricsOverride, above) is what drives the retina
      // multiplier for a clipped capture just like it does for a full-page one.
      // clip.scale is a SEPARATE page-zoom-style factor CDP applies on top of
      // that; setting it to DSF compounded the two (DSF=2 device × clip.scale=2
      // = 4×, a 2560px screen shipped as a 5120px file) until this was caught
      // by measuring the actual output dimensions against the requested DSF.
      clip = { x, y, width: w, height: h, scale: 1 };
    }
    if (TALL_VIEWPORT) {
      await cdp.send('Emulation.setDeviceMetricsOverride',
        { width: WIDTH, height: Math.max(900, pageH), deviceScaleFactor: DSF, mobile: false }, sessionId);
      await sleep(SETTLE);
    }
    const beyond = !TALL_VIEWPORT;
    const shot = await withTimeout(cdp.send('Page.captureScreenshot',
      clip ? { format: 'png', captureBeyondViewport: beyond, clip }
           : { format: 'png', captureBeyondViewport: beyond }, sessionId),
      STEP_TIMEOUT, `Page.captureScreenshot(${PATH_})`);
    mkdirSync(dirname(OUT), { recursive: true });
    writeFileSync(OUT, Buffer.from(shot.data, 'base64'));
    const bad = errors.length || overflow || media.broken.length;
    if (bad) exitCode = 1;
    console.log(JSON.stringify({
      out: OUT, path: PATH_, width: WIDTH, dsf: DSF, pageHeight: pageH,
      clip: clip ? { y: clip.y, height: clip.height } : 'full-page',
      overflow, imagesPainted: media.rendered, imagesTotal: media.total,
      brokenImages: media.broken, consoleErrors: errors,
    }, null, 2));
  }

  await cdp.send('Target.closeTarget', { targetId });
} catch (err) {
  console.error('[portfolio-shot] ' + (err?.message || err));
  exitCode = 2;
} finally {
  try { chrome?.kill('SIGKILL'); } catch { /* ignore */ }
  try { rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
}
process.exit(exitCode);
