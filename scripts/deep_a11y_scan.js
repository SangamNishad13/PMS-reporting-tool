const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const axeSource = require('axe-core').source;

function parseArgs(argv) {
  const out = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const key = a.slice(2);
    const val = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : '1';
    out[key] = val;
  }
  return out;
}

function findBrowserExecutable() {
  const candidates = [
    process.env.CHROME_PATH,
    process.env.PUPPETEER_EXECUTABLE_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
  ].filter(Boolean);

  for (const c of candidates) {
    try {
      if (fs.existsSync(c)) return c;
    } catch (_) {}
  }
  return null;
}

function sanitizeName(v) {
  return String(v || '')
    .toLowerCase()
    .replace(/[^a-z0-9-_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 80) || 'finding';
}

function uniq(arr) {
  return Array.from(new Set((arr || []).filter(Boolean)));
}

function normalizeSectionLabel(sectionRaw) {
  const s = String(sectionRaw || "").trim();
  if (!s) return "page section";
  const lower = s.toLowerCase();
  if (lower === "header") return "Header section";
  if (lower === "nav") return "Navigation section";
  if (lower === "main") return "Main section";
  if (lower === "footer") return "Footer section";
  if (lower === "aside") return "Sidebar section";
  if (lower === "section") return "Page section";
  if (lower === "article") return "Article section";
  if (lower === "form") return "Form section";
  if (lower === "page section") return "Page section";
  if (lower === "footer section") return "Footer section";
  if (lower === "header section") return "Header section";
  return s;
}

function formatSectionForOutput(sectionRaw) {
  const section = normalizeSectionLabel(sectionRaw);
  const lower = section.toLowerCase();
  const unquoted = new Set([
    "header section",
    "navigation section",
    "main section",
    "footer section",
    "sidebar section",
    "page section",
    "article section",
    "form section"
  ]);
  if (unquoted.has(lower)) return section;
  return `"${section}"`;
}

function normalizeFailureSummary(text) {
  let s = String(text || "").replace(/\s+/g, " ").trim();
  if (!s) return "";
  s = s.replace(/^Fix (any|all) of the following:\s*/i, "");
  s = s.replace(/^Fix all of these:\s*/i, "");
  s = s.replace(/^Fix any of these:\s*/i, "");
  return s.trim();
}

function simplifyFailureSummary(summary, ruleId) {
  const s = normalizeFailureSummary(summary);
  const r = String(ruleId || "").toLowerCase().trim();
  if (!s) return "";

  function normalizeElementPhrase(text) {
    let out = String(text || "");
    const map = [
      { re: /\bselect element\b/gi, tag: "select" },
      { re: /\binput element\b/gi, tag: "input" },
      { re: /\bbutton element\b/gi, tag: "button" },
      { re: /\blink element\b/gi, tag: "a" },
      { re: /\bimage element\b/gi, tag: "img" },
      { re: /\bform element\b/gi, tag: "form" },
      { re: /\btextarea element\b/gi, tag: "textarea" }
    ];
    map.forEach((item) => {
      out = out.replace(item.re, `The <${item.tag}> element`);
    });
    return out;
  }

  if (r === "select-name") {
    return "The <select> element is missing an accessible name (label, aria-label, aria-labelledby, or title).";
  }
  if (r === "label") {
    return "The form control element is missing an accessible label (visible label or ARIA label association).";
  }
  return normalizeElementPhrase(s);
}

function normalizeIssueDescription(description, recommendation, ruleId) {
  let d = String(description || "").replace(/\s+/g, " ").trim();
  if (!d) return "";
  const rec = String(recommendation || "").replace(/\s+/g, " ").trim();
  if (rec && d.toLowerCase() === rec.toLowerCase()) {
    return "";
  }
  if (rec && d.toLowerCase().includes(rec.toLowerCase())) {
    d = d.replace(new RegExp(rec.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "ig"), "").trim();
  }
  if (!d) return "";
  return d;
}

function formatActualResults(url, description, groupedFailures, recommendation, ruleId) {
  const lines = [];
  const cleanedDescription = normalizeIssueDescription(description, recommendation, ruleId);
  if (cleanedDescription) lines.push(cleanedDescription);
  lines.push("");
  lines.push(`URL: ${url}`);
    const groups = Array.isArray(groupedFailures) ? groupedFailures : [];
  if (!groups.length) {
    lines.push("");
    lines.push('- "No instance details available" in "page section"');
    return lines.join("\n");
  }

  for (const g of groups) {
    const summary = simplifyFailureSummary(g && g.summary ? g.summary : "", ruleId);
    const instances = Array.isArray(g && g.instances) ? g.instances : [];
    lines.push("");
    if (summary) lines.push(summary);
    if (instances.length) {
      for (const item of instances) {
        const name = item.instance_name || "Unnamed element";
        const section = formatSectionForOutput(item.section_context || "page section");
        lines.push(`- "${name}" in ${section}`);
      }
    } else {
      lines.push('- "No instance details available" in Page section');
    }
  }
  return lines.join("\n");
}

function toNeedsReviewSeverity(impact) {
  const v = String(impact || "").toLowerCase().trim();
  if (v === "critical") return "Blocker";
  if (v === "serious") return "Critical";
  if (v === "moderate") return "Major";
  if (v === "minor") return "Minor";
  return "Major";
}

function extractWcagMeta(violation) {
  const tags = Array.isArray(violation.tags) ? violation.tags.map((t) => String(t || "").toLowerCase()) : [];
  const scTags = uniq(tags.filter((t) => /^wcag\d{3,4}$/.test(t)));
  const scList = scTags.map((t) => {
    const d = t.replace("wcag", "");
    if (d.length === 3) return `${d[0]}.${d[1]}.${d[2]}`;
    if (d.length === 4) return `${d[0]}.${d[1]}.${d[2]}${d[3]}`;
    return t;
  });
  let level = "";
  if (tags.includes("wcag2aaa") || tags.includes("wcag21aaa")) level = "AAA";
  else if (tags.includes("wcag2aa") || tags.includes("wcag21aa")) level = "AA";
  else if (tags.includes("wcag2a") || tags.includes("wcag21a")) level = "A";

  const nameMap = {
    "color-contrast": "Contrast (Minimum)",
    "link-name": "Link Purpose (In Context)",
    "button-name": "Name, Role, Value",
    "image-alt": "Non-text Content",
    "label": "Labels or Instructions",
    "document-title": "Page Titled",
    "html-has-lang": "Language of Page",
    "html-lang-valid": "Language of Page",
    "heading-order": "Headings and Labels",
    "landmark-one-main": "Bypass Blocks",
  };
  const wcagName = nameMap[String(violation.id || "").toLowerCase()] || String(violation.help || "").trim() || "WCAG criterion";
  return { scList, wcagName, level };
}

function getRecommendation(violation) {
  const id = String(violation.id || "").toLowerCase();
  if (id.includes("color-contrast") || id.includes("contrast")) {
    return "Ensure the contrast between foreground and background colors meets WCAG 2 AA minimum contrast ratio thresholds";
  }
  const help = String(violation.help || "").trim();
  if (help) return help;
  return "Fix this accessibility issue according to WCAG 2 AA requirements.";
}

function extractControlHintsFromSnippets(snippets, tagName) {
  const hints = [];
  const seen = new Set();
  (snippets || []).forEach((raw) => {
    const html = String(raw || "").trim();
    if (!html) return;
    const tag = String(tagName || "").toLowerCase();
    if (tag && !new RegExp(`<\\s*${tag}\\b`, "i").test(html)) return;
    const idMatch = html.match(/\bid\s*=\s*["']([^"']+)["']/i);
    const nameMatch = html.match(/\bname\s*=\s*["']([^"']+)["']/i);
    const clsMatch = html.match(/\bclass\s*=\s*["']([^"']+)["']/i);
    const idVal = idMatch ? String(idMatch[1]).trim() : "";
    const nameVal = nameMatch ? String(nameMatch[1]).trim() : "";
    const clsVal = clsMatch ? String(clsMatch[1]).trim() : "";
    const key = `${idVal.toLowerCase()}||${nameVal.toLowerCase()}||${tag}`;
    if (seen.has(key)) return;
    seen.add(key);
    hints.push({ id: idVal, name: nameVal, cls: clsVal, tag: tag || "control" });
  });
  return hints;
}

function getRuleSpecificGuidance(violation, context) {
  const id = String(violation && violation.id || "").toLowerCase().trim();
  const defaultRec = getRecommendation(violation);
  const snippets = (context && Array.isArray(context.snippets)) ? context.snippets : [];

  const guidanceMap = {
    "color-contrast": {
      recommendation: "Adjust foreground/background colors so text and interactive controls meet WCAG 2 AA contrast thresholds (minimum 4.5:1 for normal text, 3:1 for large text).",
      correctCode: `<style>
.btn-primary {
  color: #ffffff;
  background-color: #005fcc; /* ensure contrast ratio >= 4.5:1 */
}
</style>`
    },
    "label": {
      recommendation: "Associate every form control with a visible or programmatic label using <label for>, aria-label, or aria-labelledby.",
      correctCode: `<label for="email">Email address</label>
<input id="email" name="email" type="email" required>`
    },
    "select-name": {
      recommendation: "Each <select> must have an accessible name. Add a visible <label for=\"...\"> linked to the select id, or use aria-label/aria-labelledby when a visible label is not possible.",
      correctCode: ""
    },
    "image-alt": {
      recommendation: "Provide meaningful alt text for informative images and use empty alt (alt=\"\") for decorative images.",
      correctCode: `<img src="/images/support.png" alt="Contact investor support">
<!-- decorative image -->
<img src="/images/divider.svg" alt="">`
    },
    "link-name": {
      recommendation: "Give every link an accessible name that clearly describes its destination or action.",
      correctCode: `<a href="/investor-login" aria-label="Investor Login">Investor Login</a>`
    },
    "button-name": {
      recommendation: "Ensure each button has an accessible name via visible text, aria-label, or aria-labelledby.",
      correctCode: `<button type="button" aria-label="Close dialog">
  <i class="icon-close" aria-hidden="true"></i>
</button>`
    },
    "document-title": {
      recommendation: "Set a unique, descriptive <title> for the page to identify its purpose.",
      correctCode: `<head>
  <title>Careers - Samco Mutual Fund</title>
</head>`
    },
    "html-has-lang": {
      recommendation: "Set the document language using the lang attribute on the <html> element.",
      correctCode: `<html lang="en">`
    },
    "html-lang-valid": {
      recommendation: "Use a valid BCP 47 language tag in the html lang attribute.",
      correctCode: `<html lang="en-IN">`
    },
    "heading-order": {
      recommendation: "Use a logical heading hierarchy (h1 to h6) without skipping levels.",
      correctCode: `<h1>Careers</h1>
<h2>Current Openings</h2>
<h3>Frontend Developer</h3>`
    },
    "landmark-one-main": {
      recommendation: "Provide exactly one main landmark to help screen-reader users identify primary page content.",
      correctCode: `<header>...</header>
<main id="main-content">...</main>
<footer>...</footer>`
    }
  };

  if (guidanceMap[id]) {
    const out = Object.assign({}, guidanceMap[id]);
    if (id === "select-name") {
      const controls = extractControlHintsFromSnippets(snippets, "select");
      if (controls.length) {
        out.recommendation = "Each affected <select> needs a programmatically associated label. Add explicit <label for=\"...\"> for these controls: " +
          controls.map((c) => `"${c.id || c.name || "unnamed-select"}"`).join(", ") + ".";
        out.correctCode = controls.map((c) => {
          const idAttr = c.id || c.name || "select_field";
          const nameAttr = c.name || idAttr;
          const labelText = idAttr.replace(/[_-]+/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
          return `<label for="${idAttr}">${labelText}</label>
<select id="${idAttr}" name="${nameAttr}" class="${c.cls || "form-input"}">
  <option value="">Select ${labelText}</option>
</select>`;
        }).join("\n\n");
      } else {
        out.correctCode = `<label for="department_name">Department</label>
<select id="department_name" name="department_name">
  <option value="">Select Department</option>
</select>`;
      }
    }
    return out;
  }

  const help = String(violation && violation.help || "").trim();
  return {
    recommendation: help || defaultRec,
    correctCode: `<!-- Example remediation pattern -->
<!-- Update markup/styles as needed for rule: ${id || "accessibility-rule"} -->`
  };
}

(async function run() {
  const args = parseArgs(process.argv);
  const url = String(args.url || '').trim();
  const outPath = String(args.out || '').trim();
  const screenshotDir = String(args['screenshot-dir'] || '').trim();
  const maxNodesArg = parseInt(args['max-nodes'] || '0', 10) || 0;

  if (!url || !outPath || !screenshotDir) {
    throw new Error('Missing required args: --url --out --screenshot-dir');
  }

  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.mkdirSync(screenshotDir, { recursive: true });

  const executablePath = findBrowserExecutable();
  if (!executablePath) {
    throw new Error('Chrome/Edge executable not found. Set CHROME_PATH env var.');
  }

  const browser = await puppeteer.launch({
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 });

  // Wait a bit for async UI widgets.
  await new Promise((r) => setTimeout(r, 1200));

  await page.evaluate((source) => {
    // eslint-disable-next-line no-eval
    eval(source);
  }, axeSource);

  const axe = await page.evaluate(async () => {
    return await axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa', 'best-practice'] },
      resultTypes: ['violations']
    });
  });

  const findings = [];
  let screenshotSeq = 1;

  for (const violation of (axe.violations || [])) {
    const allNodes = violation.nodes || [];
    const nodes = maxNodesArg > 0 ? allNodes.slice(0, maxNodesArg) : allNodes;
    const snippets = uniq(nodes.map((n) => String(n.html || '').trim()).filter(Boolean));
    const guidance = getRuleSpecificGuidance(violation, { snippets });
    const recommendation = guidance.recommendation;
    const nodeInputs = nodes
      .map((n) => ({
        selector: (Array.isArray(n.target) ? n.target[0] : "") || "",
        failure_summary: String(n.failureSummary || "").replace(/\s+/g, " ").trim()
      }))
      .filter((x) => x.selector);

    const instanceContext = await page.evaluate((nodeList) => {
      function getInstanceName(el) {
        if (!el) return "";
        const aria = (el.getAttribute("aria-label") || "").trim();
        if (aria) return aria;
        const alt = (el.getAttribute("alt") || "").trim();
        if (alt) return alt;
        const title = (el.getAttribute("title") || "").trim();
        if (title) return title;
        const nameAttr = (el.getAttribute("name") || "").trim();
        if (nameAttr) return nameAttr;
        const id = (el.id || "").trim();
        if (id) return `#${id}`;
        const text = (el.innerText || el.textContent || "").replace(/\s+/g, " ").trim();
        if (text) return text.slice(0, 80);
        return el.tagName ? el.tagName.toLowerCase() : "element";
      }

      function getSectionContext(el) {
        if (!el) return "page section";
        const directSection = el.closest("header, nav, main, footer, aside, section, article, form, [role='region'], [role='main'], [role='navigation']");
        if (directSection) {
          if (directSection.tagName && directSection.tagName.toLowerCase() === "footer") {
            return "Footer section";
          }
          const heading = directSection.querySelector("h1, h2, h3, h4, h5, h6");
          if (heading) {
            const hText = (heading.innerText || heading.textContent || "").replace(/\s+/g, " ").trim();
            if (hText) return hText.slice(0, 90);
          }
          if (directSection.id) return `section#${directSection.id}`;
          if (directSection.getAttribute("aria-label")) return directSection.getAttribute("aria-label").trim();
          return directSection.tagName.toLowerCase();
        }
        const headingUp = el.closest("div, li, td, th, tr");
        if (headingUp) {
          const h = headingUp.querySelector("h1, h2, h3, h4, h5, h6");
          if (h) {
            const t = (h.innerText || h.textContent || "").replace(/\s+/g, " ").trim();
            if (t) return t.slice(0, 90);
          }
        }
        const footerEl = document.querySelector("footer, [role='contentinfo']");
        if (footerEl) {
          const elTop = Math.max(0, (window.scrollY || 0) + (el.getBoundingClientRect().top || 0));
          const footerTop = Math.max(0, (window.scrollY || 0) + (footerEl.getBoundingClientRect().top || 0));
          if (elTop >= (footerTop - 4)) return "Footer section";
        }
        return "page section";
      }

      const out = [];
      for (const item of nodeList || []) {
        const selector = String(item && item.selector ? item.selector : "").trim();
        if (!selector) continue;
        const el = document.querySelector(selector);
        if (!el) continue;
        out.push({
          selector,
          failure_summary: String(item && item.failure_summary ? item.failure_summary : "").trim(),
          instance_name: getInstanceName(el),
          section_context: getSectionContext(el),
          abs_top: Math.max(0, (window.scrollY || 0) + (el.getBoundingClientRect().top || 0))
        });
      }
      return out;
    }, nodeInputs);

    const groupedFailureMap = new Map();
    for (const item of (instanceContext || [])) {
      const summary = simplifyFailureSummary(item && item.failure_summary ? item.failure_summary : "", violation.id);
      const key = summary || "__default__";
      if (!groupedFailureMap.has(key)) groupedFailureMap.set(key, { summary, instances: [] });
      groupedFailureMap.get(key).instances.push(item);
    }
    const groupedFailures = Array.from(groupedFailureMap.values()).map((g) => {
      const seenInst = new Set();
      const outInst = [];
      for (const inst of (g.instances || [])) {
        const name = String(inst.instance_name || "Unnamed element").trim();
        const section = String(inst.section_context || "page section").trim();
        const dedupKey = `${name.toLowerCase()}||${section.toLowerCase()}`;
        if (seenInst.has(dedupKey)) continue;
        seenInst.add(dedupKey);
        outInst.push({
          selector: inst.selector,
          instance_name: name,
          section_context: section,
          abs_top: inst.abs_top
        });
      }
      return { summary: g.summary, instances: outInst };
    });

    const viewportHeight = 900;
    const screenshotInstances = [];
    const seenScreenshotSelectors = new Set();
    for (const inst of (instanceContext || [])) {
      const sel = String(inst && inst.selector ? inst.selector : "").trim();
      if (!sel || seenScreenshotSelectors.has(sel)) continue;
      seenScreenshotSelectors.add(sel);
      screenshotInstances.push(inst);
    }
    const sortedInstances = screenshotInstances.slice().sort((a, b) => Number(a.abs_top || 0) - Number(b.abs_top || 0));
    const chunks = [];
    for (const inst of sortedInstances) {
      const top = Number(inst.abs_top || 0);
      const last = chunks[chunks.length - 1];
      if (!last || Math.abs(top - last.anchorTop) > Math.floor(viewportHeight * 0.75)) {
        chunks.push({ anchorTop: top, selectors: [inst.selector] });
      } else {
        last.selectors.push(inst.selector);
      }
    }

    const screenshots = [];
    for (const chunk of chunks) {
      try {
        await page.evaluate((selectorList) => {
          document.querySelectorAll('[data-pms-a11y-focus="1"]').forEach((el) => {
            el.style.outline = "";
            el.style.outlineOffset = "";
            el.style.boxShadow = "";
            el.removeAttribute("data-pms-a11y-focus");
          });
          let firstEl = null;
          (selectorList || []).forEach((selector) => {
            const el = document.querySelector(selector);
            if (!el) return;
            if (!firstEl) firstEl = el;
            el.setAttribute("data-pms-a11y-focus", "1");
            el.style.outline = "3px solid #d90429";
            el.style.outlineOffset = "2px";
            el.style.boxShadow = "0 0 0 3px rgba(217,4,41,0.25)";
          });
          if (firstEl && firstEl.scrollIntoView) {
            firstEl.scrollIntoView({ behavior: "instant", block: "center", inline: "center" });
          }
        }, chunk.selectors);
        await new Promise((r) => setTimeout(r, 150));
        const fileName = `${String(Date.now())}_${sanitizeName(violation.id)}_${screenshotSeq++}.png`;
        const absShot = path.join(screenshotDir, fileName);
        await page.screenshot({ path: absShot, fullPage: false });
        screenshots.push(fileName);
      } catch (_) {
        // keep going
      }
    }

    const actualResults = formatActualResults(
      url,
      String(violation.description || "").trim(),
      groupedFailures,
      recommendation,
      String(violation.id || "").trim()
    );
    const wcagMeta = extractWcagMeta(violation);

    findings.push({
      rule_id: String(violation.id || '').trim(),
      title: String(violation.help || violation.id || 'Accessibility issue').trim(),
      severity: String(violation.impact || 'moderate').trim(),
      needs_review_severity: toNeedsReviewSeverity(String(violation.impact || '')),
      wcag_sc: wcagMeta.scList,
      wcag_name: wcagMeta.wcagName,
      wcag_level: wcagMeta.level,
      actual_results: actualResults,
      incorrect_code: snippets.join('\n\n'),
      screenshots,
      recommendation,
      correct_code: String(guidance.correctCode || '').trim(),
      help_url: '',
      occurrence_count: Number(violation.nodes ? violation.nodes.length : 0) || 0,
      raw_nodes: nodes
    });
  }

  await page.close();
  await browser.close();

  const summary = {
    issues: findings.length,
    critical: findings.filter((f) => f.severity === 'critical').length,
    serious: findings.filter((f) => f.severity === 'serious').length,
    moderate: findings.filter((f) => f.severity === 'moderate').length,
    minor: findings.filter((f) => f.severity === 'minor').length
  };

  fs.writeFileSync(outPath, JSON.stringify({ success: true, url, summary, findings }, null, 2), 'utf8');
})().catch((err) => {
  const msg = err && err.stack ? err.stack : String(err);
  try {
    const args = parseArgs(process.argv);
    if (args.out) {
      fs.mkdirSync(path.dirname(args.out), { recursive: true });
      fs.writeFileSync(args.out, JSON.stringify({ success: false, error: msg }, null, 2), 'utf8');
    }
  } catch (_) {}
  console.error(msg);
  process.exit(1);
});
