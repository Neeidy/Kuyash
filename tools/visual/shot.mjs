// Phase 15.9 — headless screenshot harness (DEV-ONLY, zero-dependency).
//
// Drives the ALREADY-INSTALLED system Chrome over the DevTools Protocol using
// only Node built-ins (global WebSocket + fetch, child_process, fs). No npm
// install, no package.json, no Playwright — the app stays build-free.
//
// It logs into the running app once, then for every screen × width × locale:
//   - navigates and waits for load,
//   - records console errors (JS exceptions, console.error, network failures),
//   - measures horizontal overflow (scrollWidth - innerWidth),
//   - captures a full-page PNG.
// Writes summary.json and exits NON-ZERO if any page had a console error or
// horizontal overflow — so the visual gate can genuinely FAIL, not rubber-stamp.
//
// Usage (normally invoked by tools/visual/gate.sh, which starts the server):
//   node tools/visual/shot.mjs --base-url http://127.0.0.1:8099 --out storage/visual/run
//   node tools/visual/shot.mjs --only /dashboard        # self-test → 6 PNGs
//
// Env: VISUAL_TEST_EMAIL, VISUAL_TEST_PASSWORD (shared with bin/visual-seed.php),
//      CHROME_PATH (override the Chrome binary).

import { spawn } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readFileSync } from 'node:fs';

const HERE = fileURLToPath(new URL('.', import.meta.url));

// --- args -------------------------------------------------------------------
const args = process.argv.slice(2);
const opt = (name, def) => {
  const i = args.indexOf(name);
  return i !== -1 && args[i + 1] ? args[i + 1] : def;
};
const BASE_URL = (opt('--base-url', process.env.VISUAL_BASE_URL || 'http://127.0.0.1:8099')).replace(/\/$/, '');
const OUT_DIR = opt('--out', join(process.cwd(), 'storage/visual/run'));
const ROUTES_FILE = opt('--routes', join(HERE, 'routes.json'));
const ONLY = opt('--only', null); // a single path like /dashboard for self-test
const EMAIL = process.env.VISUAL_TEST_EMAIL || 'visual@kuyash.local';
const PASSWORD = process.env.VISUAL_TEST_PASSWORD || 'visual-dev-only-password';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const DEBUG_PORT = Number(opt('--port', '0')) || 9333;

const cfg = JSON.parse(readFileSync(ROUTES_FILE, 'utf8'));
let screens = cfg.screens;
if (ONLY) screens = screens.filter((s) => s.path === ONLY);
if (screens.length === 0) {
  console.error(`No screens matched (--only ${ONLY}).`);
  process.exit(2);
}
const WIDTHS = cfg.widths;
const LOCALES = cfg.locales;

// favicon 404s are browser-initiated noise, not an app defect — ignore them.
const IGNORE_ERROR = (text) => /favicon\.ico/i.test(text || '');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// --- minimal CDP client over the browser websocket --------------------------
class CDP {
  constructor(ws) {
    this.ws = ws;
    this.seq = 0;
    this.pending = new Map();
    this.listeners = [];
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(typeof ev.data === 'string' ? ev.data : ev.data.toString());
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      } else if (msg.method) {
        for (const fn of this.listeners) fn(msg);
      }
    });
  }
  send(method, params = {}, sessionId) {
    const id = ++this.seq;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
  }
  on(fn) {
    this.listeners.push(fn);
  }
}

async function openWs(url) {
  const ws = new WebSocket(url);
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', () => reject(new Error('websocket error: ' + url)), { once: true });
  });
  return ws;
}

// Poll the DevTools HTTP endpoint until Chrome is ready, return browser ws URL.
async function browserWsUrl(port) {
  for (let i = 0; i < 100; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/json/version`);
      if (res.ok) return (await res.json()).webSocketDebuggerUrl;
    } catch {
      /* not up yet */
    }
    await sleep(100);
  }
  throw new Error('Chrome DevTools endpoint never came up');
}

// --- main -------------------------------------------------------------------
const userDataDir = mkdtempSync(join(tmpdir(), 'kuyash-visual-'));
let chrome;
let exitCode = 0;

function fail(stage, err) {
  console.error(`\n[harness] ${stage}: ${err?.message || err}`);
  exitCode = exitCode === 0 ? 2 : exitCode; // setup failure = 2
}

try {
  mkdirSync(OUT_DIR, { recursive: true });

  chrome = spawn(
    CHROME,
    [
      '--headless=new',
      `--remote-debugging-port=${DEBUG_PORT}`,
      `--user-data-dir=${userDataDir}`,
      '--no-first-run',
      '--no-default-browser-check',
      '--hide-scrollbars',
      '--force-color-profile=srgb',
      '--disable-extensions',
      '--disable-background-networking',
      '--mute-audio',
    ],
    { stdio: ['ignore', 'ignore', 'ignore'] },
  );
  chrome.on('error', (e) => fail('chrome-launch', e));

  const bws = await openWs(await browserWsUrl(DEBUG_PORT));
  const cdp = new CDP(bws);

  const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

  await cdp.send('Page.enable', {}, sessionId);
  await cdp.send('Runtime.enable', {}, sessionId);
  await cdp.send('Log.enable', {}, sessionId);
  await cdp.send('Network.enable', {}, sessionId);

  // collect console errors for the page currently being rendered
  let errors = [];
  cdp.on((msg) => {
    if (msg.sessionId !== sessionId) return;
    if (msg.method === 'Runtime.exceptionThrown') {
      const d = msg.params.exceptionDetails;
      const t = d.exception?.description || d.text || 'exception';
      if (!IGNORE_ERROR(t)) errors.push(`exception: ${t}`);
    } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
      const t = (msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ');
      if (!IGNORE_ERROR(t)) errors.push(`console.error: ${t}`);
    } else if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') {
      const t = `${msg.params.entry.text} ${msg.params.entry.url || ''}`.trim();
      if (!IGNORE_ERROR(t)) errors.push(`log.error: ${t}`);
    }
  });

  const loadEvent = () =>
    new Promise((resolve) => {
      const fn = (msg) => {
        if (msg.sessionId === sessionId && msg.method === 'Page.loadEventFired') {
          cdp.listeners = cdp.listeners.filter((l) => l !== fn);
          resolve();
        }
      };
      cdp.on(fn);
      setTimeout(() => {
        cdp.listeners = cdp.listeners.filter((l) => l !== fn);
        resolve();
      }, 15000);
    });

  async function go(path) {
    const p = loadEvent();
    await cdp.send('Page.navigate', { url: BASE_URL + path }, sessionId);
    await p;
    await sleep(450); // settle: late images / async errors
  }
  const evaluate = async (expression) => {
    const r = await cdp.send('Runtime.evaluate', { expression, returnByValue: true }, sessionId);
    return r.result?.value;
  };
  const currentPath = () => evaluate('location.pathname');

  async function setWidth(width) {
    await cdp.send(
      'Emulation.setDeviceMetricsOverride',
      { width, height: 900, deviceScaleFactor: 1, mobile: false },
      sessionId,
    );
  }

  async function shoot(name, width, locale) {
    errors = [];
    await go(screenPath(name));
    const overflow = Number(await evaluate('Math.max(0, document.documentElement.scrollWidth - window.innerWidth)')) || 0;
    const png = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }, sessionId);
    const file = join(OUT_DIR, `${name}__${width}__${locale}.png`);
    writeFileSync(file, Buffer.from(png.data, 'base64'));
    const pageErrors = [...errors];
    const ok = pageErrors.length === 0 && overflow === 0;
    if (!ok) exitCode = 1; // a real visual-gate failure (vs setup failure = 2)
    results.push({ name, width, locale, overflow, errors: pageErrors, ok, file });
    process.stdout.write(ok ? '.' : 'x');
  }

  // map screen name -> path (for the --only/self-test and the loops below)
  const byName = Object.fromEntries(screens.map((s) => [s.name, s.path]));
  function screenPath(name) {
    return byName[name];
  }

  const results = [];

  // --- unauthenticated pass (login page only; anon locale is always default) -
  for (const s of screens.filter((x) => x.auth === false)) {
    for (const width of WIDTHS) {
      await setWidth(width);
      await shoot(s.name, width, 'en');
    }
  }

  const authedScreens = screens.filter((x) => x.auth !== false);

  if (authedScreens.length > 0) {
    // login once (form carries its own _csrf)
    await go('/login');
    const loginLoaded = loadEvent(); // attach BEFORE submit so a fast redirect isn't missed
    await evaluate(
      `(() => { const e=document.querySelector('input[name=email]'); const p=document.querySelector('input[name=password]');
        e.value=${JSON.stringify(EMAIL)}; p.value=${JSON.stringify(PASSWORD)};
        document.querySelector('form[action="/login"]').submit(); })()`,
    );
    await loginLoaded;
    await sleep(300);
    if ((await currentPath()) === '/login') {
      fail('login', new Error('still on /login after submit — bad credentials or unseeded DB'));
      throw new Error('login-failed');
    }

    for (const locale of LOCALES) {
      // switch locale via the topbar form (auth + CSRF); no-op if already there
      await go('/dashboard');
      const localeLoaded = loadEvent(); // attach BEFORE the form submit navigates
      const switched = await evaluate(
        `(() => { const f=[...document.querySelectorAll('form[action="/locale"]')]
            .find(f => f.querySelector('[name=locale]')?.value===${JSON.stringify(locale)});
          if (f) { f.submit(); return true; } return false; })()`,
      );
      if (switched) {
        await localeLoaded;
        await sleep(200);
      }
      for (const width of WIDTHS) {
        await setWidth(width);
        for (const s of authedScreens) await shoot(s.name, width, locale);
      }
    }
  }

  // --- summary --------------------------------------------------------------
  const failed = results.filter((r) => !r.ok);
  const summary = {
    base_url: BASE_URL,
    total: results.length,
    passed: results.length - failed.length,
    failed: failed.length,
    out_dir: OUT_DIR,
    results,
  };
  writeFileSync(join(OUT_DIR, 'summary.json'), JSON.stringify(summary, null, 2));

  console.log(`\n\n[harness] ${results.length} screenshots → ${OUT_DIR}`);
  if (failed.length) {
    console.log(`[harness] ${failed.length} FAILED:`);
    for (const r of failed) {
      const why = [r.overflow ? `overflow ${r.overflow}px` : null, ...r.errors].filter(Boolean).join('; ');
      console.log(`  ✗ ${r.name} @ ${r.width}px/${r.locale} — ${why}`);
    }
  } else {
    console.log('[harness] all pages: 0 console errors, 0 horizontal overflow.');
  }

  await cdp.send('Target.closeTarget', { targetId });
  bws.close();
} catch (err) {
  if (err?.message !== 'login-failed') fail('run', err);
} finally {
  try {
    chrome?.kill('SIGKILL');
  } catch {
    /* ignore */
  }
  try {
    rmSync(userDataDir, { recursive: true, force: true });
  } catch {
    /* ignore */
  }
}

process.exit(exitCode);
