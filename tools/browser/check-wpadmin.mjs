/*
 * Browser checks against the plugin's four pages inside a REAL wp-admin.
 *
 * Unlike tools/browser/check.mjs — which renders the plugin's markup outside
 * WordPress and is therefore only an interface sample — this script logs into
 * a real WordPress with a real session and measures the pages as WordPress
 * actually serves them: core's admin chrome, core's stylesheets, the admin
 * menu, the admin bar, and the plugin's own markup inside all of it.
 *
 * SCOPE AND ATTRIBUTION
 *   Everything WordPress core renders around the plugin is NOT the plugin's
 *   markup. Every scoped check below is therefore limited to `.tmc-admin`,
 *   the plugin's page shell. axe additionally runs a second, unscoped pass
 *   over the whole document, and each violation node is attributed to the
 *   plugin or to the surrounding page by its DOM ancestry — so a finding in
 *   core's markup is neither blamed on the plugin nor quietly hidden.
 *
 *   Automated checks do not replace a manual screen-reader pass. That remains
 *   Not Run (docs/compatibility-matrix.md).
 *
 * ZOOM — TWO DIFFERENT THINGS, NAMED APART
 *   `layout-space-640x512` is CDP viewport emulation: it hands the page the
 *   layout space a 1280x1024 screen has at 200%, and nothing more. It is a
 *   SIMULATION OF LAYOUT SPACE, not zoom.
 *   `zoom200-browser` is the browser doing the scaling itself: a second
 *   Chromium launched with --force-device-scale-factor=2 and a 640x512 DIP
 *   window, i.e. a real 1280x1024 physical window where every CSS pixel
 *   covers two device pixels, with NO Emulation.setDeviceMetricsOverride.
 *   Chromium's literal Ctrl+ setting (HostZoomMap) is not reachable through
 *   CDP or Playwright; that exact mechanism stays Not Run.
 *
 * Usage: node tools/browser/check-wpadmin.mjs
 *   TMC_SITE      site URL              (default http://127.0.0.1:8080)
 *   TMC_USER      administrator login   (default tmcadmin)
 *   TMC_PASS_FILE file holding the password (default /root/.wp_pass)
 *   TMC_OUT       output directory      (default docs/evidence/acceptance/wpadmin-a11y)
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const SITE = process.env.TMC_SITE || 'http://127.0.0.1:8080';
const USER = process.env.TMC_USER || 'tmcadmin';
const PASS = readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const outDir = process.env.TMC_OUT || resolve(root, 'docs/evidence/acceptance/wpadmin-a11y');
const shotDir = resolve(outDir, 'screenshots');
mkdirSync(shotDir, { recursive: true });

const PAGES = [
  { slug: 'tmc-dashboard', name: 'dashboard' },
  { slug: 'tmc-health', name: 'health' },
  { slug: 'tmc-settings', name: 'settings' },
  { slug: 'tmc-modules', name: 'modules' },
];
const VIEWPORTS = [
  { name: '320', width: 320, height: 720 },
  { name: '375', width: 375, height: 812 },
  { name: '768', width: 768, height: 1024 },
  { name: '1024', width: 1024, height: 768 },
  { name: '1440', width: 1440, height: 900 },
];
const MIN_TOUCH = 44;
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const SCOPE = '.tmc-admin';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
/** Tab presses allowed to walk WordPress's own admin chrome before the plugin. */
const MAX_TAB_STOPS = 250;

const results = [];
const failures = [];
const foreignFindings = [];
const localeSamples = [];
let zoomEnvironment = null;
let zoomBrowserVersion = '';

function record(page, viewport, check, ok, detail) {
  results.push({ page, viewport, check, ok, detail });
  if (!ok) failures.push(`${page} @ ${viewport}: ${check} — ${detail}`);
}

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

// ---- one real login, reused by every context -------------------------------
const loginCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const loginPage = await loginCtx.newPage();
await loginPage.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await loginPage.fill('#user_login', USER);
await loginPage.fill('#user_pass', PASS);
await Promise.all([
  loginPage.waitForURL(/wp-admin/, { timeout: 30000 }),
  loginPage.click('#wp-submit'),
]);
const storageState = await loginCtx.storageState();
await loginCtx.close();

const PLUGIN_ASSET = '/wp-content/plugins/tecteb-marketplace-core/';
const blockedHosts = new Set();

async function newSignedInPage(options) {
  const ctx = await browser.newContext({ storageState, locale: 'fa-IR', reducedMotion: 'reduce', ...options });
  const page = await ctx.newPage();
  const pageErrors = [];
  const failedRequests = [];
  page.on('pageerror', (e) => pageErrors.push(String(e)));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    // A blocked third-party request surfaces as a console error with no URL of
    // its own; the requestfailed handler below records the URL, so drop the
    // duplicate here rather than counting one event twice.
    if (/Failed to load resource/.test(m.text())) return;
    pageErrors.push(m.text());
  });
  page.on('requestfailed', (r) => {
    const url = r.url();
    failedRequests.push({ url, failure: r.failure()?.errorText || '' });
    if (!url.startsWith(SITE)) { try { blockedHosts.add(new URL(url).host); } catch { /* ignore */ } }
  });
  return { ctx, page, pageErrors, failedRequests };
}

/**
 * What WordPress itself says the locale and direction are. A browser locale
 * does not put wp-admin into Persian or RTL — only WordPress's own translation
 * files do — so this reads the served document, not the request.
 */
async function localeFacts(page) {
  return page.evaluate(() => {
    const rtlSheets = [...document.querySelectorAll('link[rel=stylesheet]')]
      .map((l) => l.getAttribute('href') || '')
      .filter((h) => /-rtl(\.min)?\.css/.test(h));
    return {
      htmlLang: document.documentElement.lang || '',
      htmlDir: document.documentElement.dir || '',
      bodyClassRtl: document.body.classList.contains('rtl'),
      bodyComputedDir: getComputedStyle(document.body).direction,
      pluginShellDir: (document.querySelector('.tmc-admin') || {}).dir || '',
      pluginShellLang: (document.querySelector('.tmc-admin') || {}).lang || '',
      adminRtlStylesheets: rtlSheets.length,
      sampleRtlStylesheet: rtlSheets[0] ? rtlSheets[0].split('/').pop() : '',
    };
  });
}

/** Runs the per-viewport measurements on an already-loaded admin page. */
async function measure(page, pageErrors, failedRequests, label, vpName, clientWidthNote) {
  // 1. does the DOCUMENT scroll sideways, and if so, whose element causes it?
  const overflow = await page.evaluate((scope) => {
    const w = document.documentElement.clientWidth;
    const culprits = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > w + 1) {
        culprits.push({
          sel: `${el.tagName.toLowerCase()}.${(el.className || '').toString().trim().split(/\s+/)[0] || ''}`,
          right: Math.round(r.right),
          mine: !!el.closest(scope),
        });
      }
    }
    return {
      scrollW: document.documentElement.scrollWidth,
      clientW: w,
      culprits: culprits.slice(0, 8),
      mineCulprits: culprits.filter((c) => c.mine).length,
    };
  }, SCOPE);
  const pageScrolls = overflow.scrollW > overflow.clientW + 1;
  record(label, vpName, 'no-horizontal-page-scroll', !pageScrolls,
    `scrollWidth=${overflow.scrollW} clientWidth=${overflow.clientW}${clientWidthNote}` +
    (pageScrolls ? ` culprits=${overflow.culprits.map((c) => `${c.sel}${c.mine ? '(plugin)' : '(core)'}`).join(', ')}` : ''));

  // 2. no element of the PLUGIN sticking out of the viewport
  const spill = await page.evaluate((scope) => {
    const w = document.documentElement.clientWidth;
    const out = [];
    for (const el of document.querySelectorAll(`${scope} *`)) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && (r.right > w + 1 || r.left < -1)) {
        out.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().trim().split(/\s+/)[0]} right=${Math.round(r.right)}`);
      }
    }
    return out.slice(0, 5);
  }, SCOPE);
  record(label, vpName, 'no-element-overflow', spill.length === 0, spill.join('; ') || 'none');

  // 3. interactive targets of the plugin are at least 44x44 (UX §1.1)
  const small = await page.evaluate(([scope, min]) => {
    const out = [];
    for (const el of document.querySelectorAll(`${scope} a, ${scope} button, ${scope} input:not([type=hidden]), ${scope} select`)) {
      if (el.classList.contains('tmc-skip')) continue; // visually hidden until focused
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) continue;
      if (el.closest('p, dd, .tmc-field__desc, .tmc-error-summary')) continue; // inline prose links
      if (r.height < min - 0.5 || r.width < min - 0.5) {
        out.push(`${el.tagName.toLowerCase()} ${Math.round(r.width)}x${Math.round(r.height)} "${(el.textContent || '').trim().slice(0, 20)}"`);
      }
    }
    return out.slice(0, 6);
  }, [SCOPE, MIN_TOUCH]);
  record(label, vpName, 'touch-target-44px', small.length === 0, small.join('; ') || 'none');

  // 4a. no JavaScript errors anywhere on the page
  record(label, vpName, 'no-js-errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | ') || 'none');

  // 4b. every asset the PLUGIN asks for actually loads. Requests to hosts
  //     outside this site are blocked by the container's egress policy, which
  //     is an environment fact, not a plugin fault — they are recorded under
  //     blocked_external_hosts instead of being counted as a failure.
  const mineFailed = failedRequests.filter((r) => r.url.includes(PLUGIN_ASSET));
  const externalFailed = failedRequests.filter((r) => !r.url.startsWith(SITE));
  record(label, vpName, 'plugin-assets-load', mineFailed.length === 0,
    mineFailed.map((r) => `${r.url} (${r.failure})`).join(' | ') ||
    (externalFailed.length ? `none of the plugin's own; ${externalFailed.length} external request(s) blocked by the sandbox` : 'none'));

  // 5a. axe over the PLUGIN's markup
  const mine = await new AxeBuilder({ page }).withTags(AXE_TAGS).include(SCOPE).analyze();
  record(label, vpName, 'axe-wcag-aa-plugin', mine.violations.length === 0,
    mine.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none');

  // 5b. axe over the WHOLE admin page, attributed. Findings outside the
  //     plugin's shell are WordPress core's markup: reported, never blamed.
  const whole = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  const foreign = [];
  for (const v of whole.violations) {
    const outside = v.nodes.filter((n) => !String(n.target).includes('tmc-'));
    if (outside.length) foreign.push(`${v.id}(${outside.length})`);
  }
  if (foreign.length) foreignFindings.push({ page: label, viewport: vpName, violations: foreign });
  record(label, vpName, 'axe-whole-page-recorded', true,
    foreign.length ? `outside .tmc-admin (WordPress core markup): ${foreign.join(', ')}` : 'none anywhere on the page');

  // 6. WordPress itself is in the locale and direction under test
  const loc = await localeFacts(page);
  localeSamples.push({ page: label, viewport: vpName, ...loc });
  record(label, vpName, 'wp-admin-is-fa-IR-rtl',
    loc.htmlLang.toLowerCase().startsWith('fa') && loc.htmlDir === 'rtl' && loc.bodyClassRtl && loc.bodyComputedDir === 'rtl',
    `html lang="${loc.htmlLang}" dir="${loc.htmlDir}" body.rtl=${loc.bodyClassRtl} computed=${loc.bodyComputedDir} rtl-stylesheets=${loc.adminRtlStylesheets}`);
}

// ---- per page: viewports, keyboard, 200% zoom -------------------------------
for (const p of PAGES) {
  const url = `${SITE}/wp-admin/admin.php?page=${p.slug}`;

  for (const vp of VIEWPORTS) {
    const { ctx, page, pageErrors, failedRequests } = await newSignedInPage({ viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: 1 });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector(SCOPE, { timeout: 15000 });
    await measure(page, pageErrors, failedRequests, p.name, vp.name, '');
    if (vp.name === '375' || vp.name === '1440') {
      await page.screenshot({ path: resolve(shotDir, `${p.name}-${vp.name}.png`), fullPage: true });
    }
    await ctx.close();
  }

  // ---- keyboard, by ACTUAL Tab navigation ---------------------------------
  // Nothing here calls focus() and nothing changes tabindex. The walk starts
  // where a real keyboard user starts — the top of the document — and presses
  // Tab, so "the skip link is the first stop inside the plugin" is a property
  // of the page, not of the test. The only DOM touch is a data-tmc-probe
  // marker used to identify elements; it affects neither focus order nor
  // styling.
  {
    const { ctx, page } = await newSignedInPage({ viewport: { width: 1024, height: 768 } });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector(SCOPE);

    // Everything inside the plugin's shell that a keyboard must be able to
    // reach. Derived from the page, so no expectation is hand-written and
    // none can silently go missing.
    const expected = await page.evaluate((scope) => {
      const shell = document.querySelector(scope);
      const sel = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
      const out = [];
      let i = 0;
      for (const el of shell.querySelectorAll(sel)) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        const hidden = cs.visibility === 'hidden' || cs.display === 'none';
        // The skip link is off-screen until focused; it is still expected.
        const isSkip = el.classList.contains('tmc-skip');
        if (hidden || (!isSkip && r.width === 0 && r.height === 0)) continue;
        el.setAttribute('data-tmc-probe', String(i));
        out.push({
          probe: String(i),
          tag: el.tagName.toLowerCase(),
          cls: (el.className || '').toString().trim().split(/\s+/)[0] || '',
          name: el.getAttribute('name') || '',
          text: (el.textContent || el.value || '').trim().slice(0, 24),
        });
        i++;
      }
      return out;
    }, SCOPE);

    // Start the walk at the very top of the document.
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.keyboard.press('Home').catch(() => {});
    const walk = [];
    let enteredPlugin = false;
    let leftPlugin = false;
    for (let i = 0; i < MAX_TAB_STOPS && !leftPlugin; i++) {
      await page.keyboard.press('Tab');
      const stop = await page.evaluate((scope) => {
        const el = document.activeElement;
        if (!el || el === document.body || el === document.documentElement) return null;
        const cs = getComputedStyle(el);
        return {
          tag: el.tagName.toLowerCase(),
          cls: (el.className || '').toString().trim().split(/\s+/)[0] || '',
          id: el.id || '',
          probe: el.getAttribute('data-tmc-probe'),
          mine: !!el.closest(scope),
          ring: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0,
        };
      }, SCOPE);
      if (!stop) continue;
      walk.push(stop);
      if (stop.mine) enteredPlugin = true;
      else if (enteredPlugin) leftPlugin = true;
    }

    const pluginStops = walk.filter((w) => w.mine);
    const chromeStopsBefore = walk.findIndex((w) => w.mine);
    const first = pluginStops[0];
    record(p.name, 'keyboard', 'skip-link-is-the-first-plugin-stop',
      !!first && first.cls === 'tmc-skip',
      first
        ? `after ${chromeStopsBefore} WordPress chrome stops, the first stop inside ${SCOPE} is .${first.cls} (real Tab presses, no focus() and no tabindex change)`
        : `Tab never reached ${SCOPE} within ${MAX_TAB_STOPS} presses`);

    // Every focusable control the page offers must actually be reachable.
    const reached = new Set(pluginStops.map((w) => w.probe).filter((x) => x !== null));
    const missed = expected.filter((e) => !reached.has(e.probe));
    record(p.name, 'keyboard', 'every-plugin-control-is-reachable', missed.length === 0,
      `${reached.size}/${expected.length} reached` +
      (missed.length ? `; unreachable: ${missed.map((m) => `${m.tag}.${m.cls}${m.name ? `[${m.name}]` : ''} "${m.text}"`).join(', ')}` : ''));

    const noRing = pluginStops.filter((w) => !w.ring).map((w) => `${w.tag}.${w.cls}`);
    record(p.name, 'keyboard', 'focus-visible', noRing.length === 0, noRing.join(', ') || `all ${pluginStops.length} plugin stops show a ring`);
    await ctx.close();
  }

  // ---- the skip link must MOVE FOCUS, not just change the hash -------------
  {
    const { ctx, page } = await newSignedInPage({ viewport: { width: 1024, height: 768 } });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector(SCOPE);
    // Tab to the skip link the way a keyboard user does, then press Enter.
    let onSkip = false;
    for (let i = 0; i < MAX_TAB_STOPS && !onSkip; i++) {
      await page.keyboard.press('Tab');
      onSkip = await page.evaluate(() => !!document.activeElement && document.activeElement.classList.contains('tmc-skip'));
    }
    record(p.name, 'keyboard', 'skip-link-reachable-by-tab', onSkip, onSkip ? 'reached by Tab' : 'never focused by Tab');
    if (onSkip) {
      await page.keyboard.press('Enter');
      const landed = await page.evaluate(() => {
        const target = document.getElementById('tmc-main');
        const active = document.activeElement;
        return {
          hash: location.hash,
          exists: !!target,
          focusOnTarget: !!target && (active === target || target.contains(active)),
          activeTag: active ? active.tagName.toLowerCase() : 'none',
          activeId: active ? active.id : '',
        };
      });
      record(p.name, 'keyboard', 'skip-link-reaches-main',
        landed.hash === '#tmc-main' && landed.focusOnTarget,
        `hash=${landed.hash || '(none)'} focusMovedToTarget=${landed.focusOnTarget} activeElement=<${landed.activeTag} id="${landed.activeId}">`);
    }
    await ctx.close();
  }

  // Reflow (WCAG 1.4.10) in the LAYOUT SPACE a 1280x1024 screen has at 200%.
  // This is CDP viewport emulation — a simulation of the available layout
  // space, NOT the browser zooming. The real thing is measured further down.
  {
    const { ctx, page, pageErrors, failedRequests } = await newSignedInPage({ viewport: { width: 640, height: 512 }, deviceScaleFactor: 2 });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector(SCOPE);
    await measure(page, pageErrors, failedRequests, p.name, 'layout-space-640x512',
      ' (emulated layout space of 1280x1024 at 200% — viewport emulation, not zoom)');
    await page.screenshot({ path: resolve(shotDir, `${p.name}-layout-space-640x512.png`), fullPage: true });
    await ctx.close();
  }
}

// ---- REAL 200% zoom, done by the browser itself ----------------------------
// A separate Chromium process with --force-device-scale-factor=2 and a 640x512
// DIP window: a real 1280x1024 physical window in which every CSS pixel covers
// two device pixels. The context passes viewport:null, so Playwright sends NO
// Emulation.setDeviceMetricsOverride — the scaling is the browser's, not the
// protocol's. window.devicePixelRatio, screen and the media-query response are
// recorded as the proof of which mechanism produced the result.
{
  const zoomBrowser = await chromium.launch({
    executablePath: CHROMIUM,
    args: ['--no-sandbox', '--force-device-scale-factor=2', '--window-size=640,512'],
  });
  const zctx = await zoomBrowser.newContext({ storageState, locale: 'fa-IR', reducedMotion: 'reduce', viewport: null });
  for (const p of PAGES) {
    const page = await zctx.newPage();
    const pageErrors = [];
    const failedRequests = [];
    page.on('pageerror', (e) => pageErrors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) pageErrors.push(m.text()); });
    page.on('requestfailed', (r) => {
      const u = r.url();
      failedRequests.push({ url: u, failure: r.failure()?.errorText || '' });
      if (!u.startsWith(SITE)) { try { blockedHosts.add(new URL(u).host); } catch { /* ignore */ } }
    });
    await page.goto(`${SITE}/wp-admin/admin.php?page=${p.slug}`, { waitUntil: 'load' });
    await page.waitForSelector(SCOPE);

    const env = await page.evaluate(() => ({
      dpr: window.devicePixelRatio,
      inner: [window.innerWidth, window.innerHeight],
      outer: [window.outerWidth, window.outerHeight],
      screen: [screen.width, screen.height],
      mqNarrow: window.matchMedia('(max-width: 700px)').matches,
      rootZoom: getComputedStyle(document.documentElement).zoom,
    }));
    zoomEnvironment = env;
    // At 200% the browser must be handing the page half the CSS width and a
    // device pixel ratio of 2; if it is not, the pass proves nothing.
    record(p.name, 'zoom200-browser', 'browser-really-scaled',
      env.dpr === 2 && env.inner[0] <= 700 && env.mqNarrow,
      `devicePixelRatio=${env.dpr} innerWidth=${env.inner[0]} outerWidth=${env.outer[0]} screen=${env.screen.join('x')} narrowMediaQuery=${env.mqNarrow} rootZoom=${env.rootZoom} (no CDP viewport override)`);

    await measure(page, pageErrors, failedRequests, p.name, 'zoom200-browser',
      ' (real browser scaling: 1280x1024 physical window, device scale factor 2)');
    await page.screenshot({ path: resolve(shotDir, `${p.name}-zoom200-browser.png`), fullPage: true });
    await page.close();
  }
  zoomBrowserVersion = zoomBrowser.version();
  await zctx.close();
  await zoomBrowser.close();
}

// ---- the settings page in its error state ----------------------------------
// UX §17: a rejected field moves focus to the error summary on load. This is
// the one state the four plain page loads cannot show.
{
  const { ctx, page } = await newSignedInPage({ viewport: { width: 1024, height: 768 } });
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-settings`, { waitUntil: 'load' });
  await page.fill('input[name="tmc_settings[default_commission_rate]"]', '100.01');
  // The save is a POST to options.php that redirects back to the settings
  // page. Waiting for 'load' alone can sample DURING that redirect — the
  // final document is not there yet and activeElement is nothing. Wait for
  // the settings page itself, and for the error summary the assertion is
  // about, before reading where focus landed.
  await Promise.all([
    page.waitForURL(/page=tmc-settings/, { timeout: 30000 }),
    page.click('.tmc-form button[type=submit], .tmc-form input[type=submit]'),
  ]);
  await page.waitForSelector(SCOPE);
  await page.waitForSelector('#tmc-error-summary', { timeout: 15000 }).catch(() => {});
  const state = await page.evaluate(() => {
    const summary = document.getElementById('tmc-error-summary');
    return {
      hasSummary: !!summary,
      focusedSummary: !!summary && (document.activeElement === summary || summary.contains(document.activeElement)),
      links: summary ? summary.querySelectorAll('a').length : 0,
      activeId: document.activeElement ? document.activeElement.id : '',
    };
  });
  record('settings-error', 'keyboard', 'error-summary-present', state.hasSummary, `activeElement id="${state.activeId}"`);
  record('settings-error', 'keyboard', 'error-summary-focused-on-load', state.focusedSummary, `activeElement id="${state.activeId}"`);
  record('settings-error', 'keyboard', 'error-summary-links-reachable', state.links > 0, `${state.links} links`);
  const axeErr = await new AxeBuilder({ page }).withTags(AXE_TAGS).include(SCOPE).analyze();
  record('settings-error', '1024', 'axe-wcag-aa-plugin', axeErr.violations.length === 0,
    axeErr.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none');
  await page.screenshot({ path: resolve(shotDir, 'settings-error-1024.png'), fullPage: true });
  await ctx.close();
}

const chromium_version = browser.version();
await browser.close();

const summary = {
  generated_at: new Date().toISOString(),
  scope: 'The plugin\'s four pages inside a REAL wp-admin, signed in as an administrator. Scoped checks measure .tmc-admin only; axe also runs unscoped and attributes findings.',
  site: SITE,
  chromium: chromium_version,
  playwright: JSON.parse(readFileSync(resolve(here, 'node_modules/playwright/package.json'), 'utf8')).version,
  axe_core: JSON.parse(readFileSync(resolve(here, 'node_modules/axe-core/package.json'), 'utf8')).version,
  viewports: VIEWPORTS.map((v) => v.name).concat(['layout-space-640x512', 'zoom200-browser']),
  zoom: {
    'layout-space-640x512': 'CDP viewport emulation: the layout space a 1280x1024 screen has at 200%. A simulation of layout space, not zoom.',
    'zoom200-browser': 'A separate Chromium with --force-device-scale-factor=2 and a 640x512 DIP window (a real 1280x1024 physical window), viewport:null so no Emulation.setDeviceMetricsOverride is sent. The browser does the scaling.',
    'not_run': "Chromium's literal Ctrl+ zoom setting (HostZoomMap) is not reachable through CDP or Playwright and was NOT exercised.",
    observed: null,
    browser: '',
  },
  locale: {
    intended: 'fa_IR, right-to-left, verified from the served document rather than the request',
    samples: [],
  },
  total: results.length,
  failed: failures.length,
  foreign_findings: foreignFindings,
  blocked_external_hosts: [...blockedHosts].sort(),
  results,
};
summary.zoom.observed = zoomEnvironment;
summary.zoom.browser = zoomBrowserVersion;
summary.locale.samples = localeSamples.slice(0, 8);
writeFileSync(resolve(outDir, 'wpadmin-a11y.json'), JSON.stringify(summary, null, 2));

const byCheck = {};
for (const r of results) {
  byCheck[r.check] ??= { pass: 0, fail: 0 };
  byCheck[r.check][r.ok ? 'pass' : 'fail']++;
}
const lines = [];
lines.push(`site ${SITE}`);
lines.push(`playwright ${summary.playwright}  axe-core ${summary.axe_core}  chromium ${summary.chromium}`);
for (const [k, v] of Object.entries(byCheck)) lines.push(`  ${k.padEnd(34)} pass=${v.pass} fail=${v.fail}`);
lines.push(`\ntotal ${results.length}, failures ${failures.length}`);
for (const f of failures) lines.push('  FAIL ' + f);
lines.push('');
lines.push(`locale under test: ${summary.locale.samples[0] ? `html lang="${summary.locale.samples[0].htmlLang}" dir="${summary.locale.samples[0].htmlDir}" body.rtl=${summary.locale.samples[0].bodyClassRtl} rtl-stylesheets=${summary.locale.samples[0].adminRtlStylesheets}` : 'not recorded'}`);
lines.push(`zoom, emulated  : layout-space-640x512 — CDP viewport emulation (simulation of layout space)`);
lines.push(`zoom, real      : zoom200-browser — ${zoomEnvironment ? `devicePixelRatio=${zoomEnvironment.dpr} innerWidth=${zoomEnvironment.inner[0]} outerWidth=${zoomEnvironment.outer[0]} screen=${zoomEnvironment.screen.join('x')}` : 'not recorded'}`);
lines.push(`zoom, NOT run   : Chromium's Ctrl+ setting (HostZoomMap) is not drivable from Playwright`);
if (blockedHosts.size) {
  lines.push(`\nexternal hosts the sandbox refused (environment, not the plugin): ${[...blockedHosts].sort().join(', ')}`);
}
if (foreignFindings.length) {
  lines.push(`\naxe findings OUTSIDE the plugin's markup (WordPress core admin chrome — recorded, not attributed to the plugin):`);
  const seen = new Set();
  for (const f of foreignFindings) {
    const key = f.violations.join(',');
    if (seen.has(key)) continue;
    seen.add(key);
    lines.push(`  ${f.page} @ ${f.viewport}: ${key}`);
  }
}
const text = lines.join('\n');
writeFileSync(resolve(outDir, 'wpadmin-a11y.log'), text + '\n');
console.log(text);
process.exit(failures.length === 0 ? 0 : 1);
