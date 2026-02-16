#!/usr/bin/env node
/*
 * Run accessibility scan using Playwright + axe-core.
 * Usage:
 *   node run_axe_scan.js --url https://example.com --cookie "PHPSESSID=..." --timeout 45000
 */
const args = process.argv.slice(2);
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
function getArg(name, fallback = '') {
  const idx = args.indexOf(name);
  if (idx === -1) return fallback;
  return args[idx + 1] || fallback;
}

const url = getArg('--url', '');
const cookie = getArg('--cookie', '');
const timeout = Number(getArg('--timeout', '45000')) || 45000;
const outDirArg = getArg('--outDir', '');

if (!url) {
  console.error('Missing --url');
  process.exit(2);
}

let playwright;
let axeCore;
try {
  playwright = require('playwright');
  axeCore = require('axe-core');
} catch (e) {
  console.error('Missing dependencies. Install: npm i -D playwright axe-core');
  process.exit(3);
}

(async () => {
  const defaultOutDir = path.resolve(__dirname, '..', '..', 'assets', 'uploads', 'automated_findings');
  const outDir = outDirArg ? path.resolve(outDirArg) : defaultOutDir;
  fs.mkdirSync(outDir, { recursive: true });

  function makeShotPath() {
    const name = `scan_${Date.now()}_${crypto.randomBytes(4).toString('hex')}.png`;
    return {
      abs: path.join(outDir, name),
      web: `/assets/uploads/automated_findings/${name}`
    };
  }

  const browser = await playwright.chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: cookie ? { Cookie: cookie } : {}
    });
    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'networkidle', timeout });

    await page.addScriptTag({ content: axeCore.source });
    const result = await page.evaluate(async () => {
      return await window.axe.run(document, {
        resultTypes: ['violations'],
        runOnly: {
          type: 'tag',
          values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice']
        }
      });
    });

    const violations = Array.isArray(result.violations) ? result.violations : [];

    async function clearAxeOverlay() {
      try {
        await page.evaluate(() => {
          const old = document.getElementById('__pms_axe_highlight_overlay__');
          if (old && old.parentNode) old.parentNode.removeChild(old);
        });
      } catch (_) {}
    }

    async function highlightTargets(items) {
      const list = Array.isArray(items) ? items.filter((x) => x && x.selector) : [];
      if (!list.length) return [];
      try {
        return await page.evaluate((itemList) => {
          const old = document.getElementById('__pms_axe_highlight_overlay__');
          if (old && old.parentNode) old.parentNode.removeChild(old);
          const overlay = document.createElement('div');
          overlay.id = '__pms_axe_highlight_overlay__';
          overlay.style.position = 'fixed';
          overlay.style.left = '0';
          overlay.style.top = '0';
          overlay.style.width = '100%';
          overlay.style.height = '100%';
          overlay.style.zIndex = '2147483647';
          overlay.style.pointerEvents = 'none';

          const normalize = (s) => String(s || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .replace(/\"/g, '\'')
            .trim();

          const pickBest = (selector, htmlSnippet, expectedTop, visibleOnly) => {
            let nodes = [];
            try { nodes = Array.from(document.querySelectorAll(selector)); } catch (_) { nodes = []; }
            if (!nodes.length) return null;
            const targetNorm = normalize(htmlSnippet || '').slice(0, 180);
            let best = null;
            let bestScore = -1e9;
            nodes.forEach((el) => {
              const r = el.getBoundingClientRect();
              if (!r || r.width <= 0 || r.height <= 0) return;
              if (visibleOnly) {
                const inView = r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth;
                if (!inView) return;
              }
              let score = 1;
              const absTop = (window.scrollY || 0) + r.top;
              if (typeof expectedTop === 'number' && Number.isFinite(expectedTop)) {
                const dist = Math.abs(absTop - expectedTop);
                score += Math.max(0, 40 - Math.min(40, dist / 8)); // proximity bonus
              }
              if (targetNorm) {
                const elNorm = normalize(el.outerHTML || '').slice(0, 400);
                if (elNorm.indexOf(targetNorm) !== -1) score += 25;
                else if (targetNorm.indexOf(elNorm.slice(0, Math.min(80, elNorm.length))) !== -1) score += 12;
              }
              if (score > bestScore) {
                best = el;
                bestScore = score;
              }
            });
            return best;
          };

          const highlighted = [];
          itemList.forEach((it) => {
            const expTop = (typeof it.expectedTop === 'number') ? it.expectedTop : null;
            const el = pickBest(it.selector, it.htmlSnippet || '', expTop, false);
            if (!el) return;
            const r = el.getBoundingClientRect();
            if (!r || r.width <= 0 || r.height <= 0) return;
            const pad = 4;
            const box = document.createElement('div');
            box.style.position = 'fixed';
            box.style.left = `${Math.max(0, r.left - pad)}px`;
            box.style.top = `${Math.max(0, r.top - pad)}px`;
            box.style.width = `${Math.max(1, Math.min(window.innerWidth, r.width + pad * 2))}px`;
            box.style.height = `${Math.max(1, Math.min(window.innerHeight, r.height + pad * 2))}px`;
            box.style.border = '2px solid #dc3545';
            box.style.background = 'transparent';
            box.style.boxSizing = 'border-box';
            box.style.borderRadius = '2px';
            overlay.appendChild(box);
            highlighted.push(String(it.key || ''));
          });
          if (highlighted.length) document.body.appendChild(overlay);
          return highlighted.filter(Boolean);
        }, list);
      } catch (_) {
        return [];
      }
    }

    async function highlightVisibleTargets(items) {
      const list = Array.isArray(items) ? items.filter((x) => x && x.selector) : [];
      if (!list.length) return [];
      try {
        return await page.evaluate((itemList) => {
          const old = document.getElementById('__pms_axe_highlight_overlay__');
          if (old && old.parentNode) old.parentNode.removeChild(old);
          const overlay = document.createElement('div');
          overlay.id = '__pms_axe_highlight_overlay__';
          overlay.style.position = 'fixed';
          overlay.style.left = '0';
          overlay.style.top = '0';
          overlay.style.width = '100%';
          overlay.style.height = '100%';
          overlay.style.zIndex = '2147483647';
          overlay.style.pointerEvents = 'none';

          const normalize = (s) => String(s || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .replace(/\"/g, '\'')
            .trim();

          const pickBestVisible = (selector, htmlSnippet, expectedTop) => {
            let nodes = [];
            try { nodes = Array.from(document.querySelectorAll(selector)); } catch (_) { nodes = []; }
            if (!nodes.length) return;
            const targetNorm = normalize(htmlSnippet || '').slice(0, 180);
            let best = null;
            let bestScore = -1e9;
            nodes.forEach((el) => {
              const r = el.getBoundingClientRect();
              if (!r || r.width <= 0 || r.height <= 0) return;
              const inView = r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth;
              if (!inView) return;
              let score = 1;
              const absTop = (window.scrollY || 0) + r.top;
              if (typeof expectedTop === 'number' && Number.isFinite(expectedTop)) {
                const dist = Math.abs(absTop - expectedTop);
                score += Math.max(0, 40 - Math.min(40, dist / 8));
              }
              if (targetNorm) {
                const elNorm = normalize(el.outerHTML || '').slice(0, 400);
                if (elNorm.indexOf(targetNorm) !== -1) score += 25;
                else if (targetNorm.indexOf(elNorm.slice(0, Math.min(80, elNorm.length))) !== -1) score += 12;
              }
              if (score > bestScore) {
                best = el;
                bestScore = score;
              }
            });
            return best;
          };

          const highlighted = [];
          itemList.forEach((it) => {
            const expTop = (typeof it.expectedTop === 'number') ? it.expectedTop : null;
            const target = pickBestVisible(it.selector, it.htmlSnippet || '', expTop);
            if (!target) return;
            const r = target.getBoundingClientRect();
            if (!r || r.width <= 0 || r.height <= 0) return;
            const pad = 4;
            const box = document.createElement('div');
            box.style.position = 'fixed';
            box.style.left = `${Math.max(0, r.left - pad)}px`;
            box.style.top = `${Math.max(0, r.top - pad)}px`;
            box.style.width = `${Math.max(1, Math.min(window.innerWidth, r.width + pad * 2))}px`;
            box.style.height = `${Math.max(1, Math.min(window.innerHeight, r.height + pad * 2))}px`;
            box.style.border = '2px solid #dc3545';
            box.style.background = 'transparent';
            box.style.boxSizing = 'border-box';
            box.style.borderRadius = '2px';
            overlay.appendChild(box);
            highlighted.push(String(it.key || ''));
          });
          if (highlighted.length) document.body.appendChild(overlay);
          return highlighted.filter(Boolean);
        }, list);
      } catch (_) {
        return [];
      }
    }

    async function collectSelectorMetric(selector, htmlSnippet) {
      if (!selector) return null;
      try {
        return await page.evaluate((sel, snippet) => {
          const normalize = (s) => String(s || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .replace(/\"/g, '\'')
            .trim();
          const extractAttrs = (s) => {
            const out = {};
            const str = String(s || '');
            const idMatch = str.match(/\sid\s*=\s*["']([^"']+)["']/i);
            const classMatch = str.match(/\sclass\s*=\s*["']([^"']+)["']/i);
            const hrefMatch = str.match(/\shref\s*=\s*["']([^"']+)["']/i);
            const nameMatch = str.match(/\sname\s*=\s*["']([^"']+)["']/i);
            const typeMatch = str.match(/\stype\s*=\s*["']([^"']+)["']/i);
            if (idMatch) out.id = idMatch[1].trim();
            if (classMatch) out.className = classMatch[1].trim();
            if (hrefMatch) out.href = hrefMatch[1].trim();
            if (nameMatch) out.name = nameMatch[1].trim();
            if (typeMatch) out.type = typeMatch[1].trim();
            return out;
          };

          let nodes = [];
          try { nodes = Array.from(document.querySelectorAll(sel)); } catch (_) { nodes = []; }
          if (!nodes.length) return null;

          const targetNorm = normalize(snippet || '').slice(0, 220);
          const attrs = extractAttrs(snippet || '');
          let best = null;
          let bestScore = -1e9;
          nodes.forEach((el) => {
            const r = el.getBoundingClientRect();
            if (!r || r.width <= 0 || r.height <= 0) return;
            let score = 1;
            const elNorm = normalize(el.outerHTML || '').slice(0, 500);
            if (targetNorm) {
              if (elNorm.indexOf(targetNorm) !== -1) score += 40;
              else if (targetNorm.indexOf(elNorm.slice(0, Math.min(120, elNorm.length))) !== -1) score += 16;
            }
            if (attrs.id && el.id === attrs.id) score += 25;
            if (attrs.className) {
              const cls = attrs.className.split(/\s+/).filter(Boolean);
              let matched = 0;
              cls.forEach((c) => { if (el.classList.contains(c)) matched += 1; });
              score += Math.min(12, matched * 3);
            }
            if (attrs.href && String(el.getAttribute('href') || '') === attrs.href) score += 12;
            if (attrs.name && String(el.getAttribute('name') || '') === attrs.name) score += 8;
            if (attrs.type && String(el.getAttribute('type') || '') === attrs.type) score += 6;
            if (score > bestScore) {
              bestScore = score;
              best = el;
            }
          });
          if (!best) return null;
          const r = best.getBoundingClientRect();
          if (!r || r.width <= 0 || r.height <= 0) return null;
          return {
            selector: sel,
            absTop: r.top + window.scrollY,
            absBottom: r.bottom + window.scrollY
          };
        }, selector, htmlSnippet || '');
      } catch (_) {
        return null;
      }
    }

    const viewportHeight = (page.viewportSize() && page.viewportSize().height) ? page.viewportSize().height : 720;
    const pageInfo = await page.evaluate(() => ({
      scrollHeight: Math.max(
        document.body ? document.body.scrollHeight : 0,
        document.documentElement ? document.documentElement.scrollHeight : 0,
        document.body ? document.body.offsetHeight : 0,
        document.documentElement ? document.documentElement.offsetHeight : 0
      )
    }));
    const pageHeight = Math.max(viewportHeight, Number(pageInfo && pageInfo.scrollHeight ? pageInfo.scrollHeight : viewportHeight));
    const segmentCount = Math.max(1, Math.ceil(pageHeight / viewportHeight));

    // Group screenshots rule-wise (per violation) so each rule/issue title has its own screenshot set.
    for (let vi = 0; vi < violations.length; vi++) {
      const v = violations[vi];
      const nodes = Array.isArray(v.nodes) ? v.nodes : [];
      const refs = [];
      for (let ni = 0; ni < nodes.length; ni++) {
        const n = nodes[ni];
        const targets = Array.isArray(n.target) ? n.target.filter(Boolean) : [];
        const selector = targets.length ? String(targets[0]) : '';
        const metric = selector ? await collectSelectorMetric(selector, String(n && n.html ? n.html : '')) : null;
        refs.push({ ni, selector, metric, htmlSnippet: String(n && n.html ? n.html : '') });
      }

      const metricRefs = refs.filter((x) => x.metric && x.selector);
      const assigned = new Set();
      const maxScroll = Math.max(0, pageHeight - viewportHeight);

      // Greedy viewport grouping: each capture includes all instances visible at the chosen scroll.
      while (true) {
        const remaining = metricRefs.filter((x) => !assigned.has(x.ni));
        if (!remaining.length) break;

        // Start from top-most remaining instance.
        remaining.sort((a, b) => Number(a.metric.absTop || 0) - Number(b.metric.absTop || 0));
        const seed = remaining[0];
        const seedTop = Number(seed.metric.absTop || 0);
        let targetScroll = Math.max(0, Math.min(maxScroll, Math.floor(seedTop - viewportHeight * 0.2)));

        // If this instance is near footer, align capture to bottom so all footer items stay in one view.
        if (seedTop > maxScroll) targetScroll = maxScroll;

        try { await page.evaluate((y) => window.scrollTo(0, y), targetScroll); } catch (_) {}
        await page.waitForTimeout(220).catch(() => {});

        const visibleKeys = await highlightVisibleTargets(remaining.map((x) => ({
          key: String(x.ni),
          selector: x.selector,
          htmlSnippet: x.htmlSnippet || '',
          expectedTop: x.metric ? Number(x.metric.absTop || 0) : null
        })));
        if (!visibleKeys.length) {
          await clearAxeOverlay();
          // Avoid infinite loop if selector cannot be rendered.
          assigned.add(seed.ni);
          continue;
        }

        const shot = makeShotPath();
        await page.screenshot({ path: shot.abs, fullPage: false, timeout: 5000 }).catch(() => {});
        await clearAxeOverlay();

        remaining.forEach((ref) => {
          if (visibleKeys.indexOf(String(ref.ni)) === -1) return;
          if (!nodes[ref.ni]) return;
          nodes[ref.ni].screenshotPath = shot.web;
          assigned.add(ref.ni);
        });
      }

      // Fallback for nodes of THIS rule not covered by grouped viewport screenshots.
      for (const ref of refs) {
        const node = nodes[ref.ni];
        if (!node) continue;
        if (assigned.has(ref.ni) && node.screenshotPath) continue;

        let screenshotPath = '';
        try {
          const shot = makeShotPath();
          if (ref.selector) {
            const locator = page.locator(ref.selector).first();
            const count = await locator.count();
            if (count > 0) {
              await locator.scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {});
              await highlightTargets([{
                key: String(ref.ni),
                selector: ref.selector,
                htmlSnippet: ref.htmlSnippet || '',
                expectedTop: ref.metric ? Number(ref.metric.absTop || 0) : null
              }]);
              await page.waitForTimeout(80).catch(() => {});
              await page.screenshot({ path: shot.abs, fullPage: false, timeout: 5000 });
              await clearAxeOverlay();
              screenshotPath = shot.web;
            }
          }
          if (!screenshotPath) {
            await clearAxeOverlay();
            await page.screenshot({ path: shot.abs, fullPage: false });
            screenshotPath = shot.web;
          }
        } catch (e) {
          await clearAxeOverlay();
          screenshotPath = '';
        }
        node.screenshotPath = screenshotPath;
      }
    }

    process.stdout.write(JSON.stringify({
      url,
      violations
    }));
  } finally {
    await browser.close();
  }
})().catch((err) => {
  console.error(String(err && err.message ? err.message : err));
  process.exit(1);
});
