/**
 * view_issues.js
 * Logic for the Issues tab: issue management, drafting, rendering, and interaction.
 */

(function () {
    try {
        var list = document.getElementById('issuesPageList');
        if (!list) {
            // Element not found - likely on detail page
        } else {
            var rows = list.querySelectorAll('.issues-page-row');
        }
    } catch (e) { alert('view_issues.js error: ' + e); }

    // Check if we're on a page that needs issues functionality
    // Allow execution on detail pages even without #issues or #issuesSubTabs
    var hasIssuesTab = document.getElementById('issues') || document.getElementById('issuesSubTabs');
    var hasIssueModal = document.getElementById('finalIssueModal');
    var hasAddIssueBtn = document.getElementById('issueAddFinalBtn');
    var hasCommonIssues = document.getElementById('commonIssuesBody') || document.getElementById('commonAddBtn');

    if (!hasIssuesTab && !hasIssueModal && !hasAddIssueBtn && !hasCommonIssues) {
        return; // Exit early if no issues-related elements found
    }

    // Config from global object
    var pages = ProjectConfig.projectPages || [];
    var groupedUrls = ProjectConfig.groupedUrls || [];
    var projectId = ProjectConfig.projectId;
    var projectType = ProjectConfig.projectType || 'web';
    var apiBase = ProjectConfig.baseDir + '/api/automated_findings.php';
    var isInfinityFreeHost = /(?:^|\.)infinityfreeapp\.com$/i.test(String(window.location.hostname || ''));
    var axeCoreCdnUrl = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.10.3/axe.min.js';
    var issuesApiBase = ProjectConfig.baseDir + '/api/issues.php';
    var issueImageUploadUrl = ProjectConfig.baseDir + '/api/issue_upload_image.php';
    var issueTemplatesApi = ProjectConfig.baseDir + '/api/issue_templates.php';
    var issueCommentsApi = ProjectConfig.baseDir + '/api/issue_comments.php';
    var issueDraftsApi = ProjectConfig.baseDir + '/api/issue_drafts.php';
    var uniqueIssuePages = ProjectConfig.uniqueIssuePages || [];

    var issueData = {
        selectedPageId: null,
        pages: {},
        common: [],
        comments: {},
        counters: { final: 1, review: 1, common: 1 },
        draftTimer: null,
        initialFormState: null,
        isDraftRestored: false,
        imageUpload: {
            pendingFile: null,
            pendingEditor: null,
            lastPasteTime: 0,
            isEditing: false,
            editingImg: null,
            savedRange: null
        }
    };

    // Expose issueData globally for external access
    window.issueData = issueData;
    var issueTemplates = [];
    var defaultSections = [];
    var issuePresets = [];
    var issueMetadataFields = [];
    var isSyncingUrlModal = false;
    var liveIssueSyncTimer = null;
    var liveIssueSyncInFlight = false;
    var LIVE_ISSUE_SYNC_INTERVAL_MS = 15000;
    var issuePresenceTimer = null;
    var issuePresenceIssueId = null;
    var issuePresenceSessionToken = null;
    var ISSUE_PRESENCE_PING_MS = 2000;
    var reviewPageSize = 25;
    var reviewCurrentPage = 1;
    var pendingScanUrl = '';
    var reviewIssueInitialFormState = null;
    var reviewIssueBypassCloseConfirm = false;

    // Expose issueData for debug if needed, or keep private? 
    // view_core.js might need it? No, view_core is generic.
    // We might need to expose some functions to window if view.php calls them inline (unlikely, we are extracting everything).

    function ensurePageStore(store, pageId) {
        if (!store[pageId]) store[pageId] = { final: [], review: [] };
        if (!store[pageId].final) store[pageId].final = [];
        if (!store[pageId].review) store[pageId].review = [];
    }

    function canEdit() {
        return true;
    }

    function updateEditingState() {
        var editable = canEdit() && !!issueData.selectedPageId;
        var addBtn = document.getElementById('issueAddFinalBtn');
        var reviewAddBtn = document.getElementById('reviewAddBtn');
        var runScanBtn = document.getElementById('reviewRunScanBtn');
        if (addBtn) addBtn.disabled = !editable;
        if (reviewAddBtn) reviewAddBtn.disabled = !editable;
        if (runScanBtn) runScanBtn.disabled = !editable;

        if (!canEdit()) {
            hideEditors();
        }
    }

    function escapeAttr(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function cleanInstanceValue(raw) {
        var txt = String(raw || '').trim();
        if (!txt) return '';
        var lower = txt.toLowerCase();
        var pollutedKeys = ['issue:', 'rule id:', 'impact:', 'source url:', 'description:', 'failure:', 'recommendation:', 'incorrect code:'];
        var isPolluted = pollutedKeys.some(function (k) { return lower.indexOf(k) !== -1; });
        if (!isPolluted) return txt;

        var extracted = [];
        var match;
        var re = /instance\s+\d+\s*:\s*([^-\n\r][^-\n\r]*)/ig;
        while ((match = re.exec(txt)) !== null) {
            var val = String(match[1] || '').trim();
            if (val && extracted.indexOf(val) === -1) extracted.push(val);
        }
        return extracted.join(' | ');
    }

    function parseInstanceParts(instanceRaw) {
        var v = String(instanceRaw || '').trim();
        if (!v) return { name: '', path: '' };
        var parts = v.split('|');
        if (parts.length >= 2) {
            return { name: String(parts[0] || '').trim(), path: String(parts.slice(1).join('|') || '').trim() };
        }
        return { name: '', path: v };
    }

    function extractLabelFromIncorrectCode(codeHtml) {
        var raw = String(codeHtml || '').trim();
        if (!raw) return '';
        var tmp = document.createElement('div');
        tmp.innerHTML = raw;
        var txt = (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
        if (!txt) return '';
        if (txt.length > 60) txt = txt.slice(0, 57) + '...';
        return txt;
    }

    function enrichInstanceWithName(instanceRaw, incorrectCode) {
        var cleaned = cleanInstanceValue(instanceRaw || '');
        var parsed = parseInstanceParts(cleaned);
        if (parsed.name) return parsed.name + ' | ' + parsed.path;
        var inferred = extractLabelFromIncorrectCode(incorrectCode || '');
        if (!inferred) return parsed.path || '';
        return inferred + ' | ' + (parsed.path || '');
    }

    function formatInstanceReadable(instanceRaw) {
        var p = parseInstanceParts(instanceRaw);
        var pathText = String(p.path || '').trim();
        if (/^path\s*:/i.test(pathText)) pathText = pathText.replace(/^path\s*:/i, '').trim();
        if (p.name && pathText) return p.name + ' | Path: ' + pathText;
        if (pathText) return 'Path: ' + pathText;
        return p.name || '';
    }

    function formatFailureSummaryText(raw) {
        var s = String(raw || '').trim();
        if (!s) return '';
        s = s.replace(/Fix any of the following:\s*/ig, '');
        s = s.replace(/\s*\n\s*/g, ' | ');
        s = s.replace(/\|\s*\|/g, '|');
        s = s.replace(/^\s*\|\s*|\s*\|\s*$/g, '');
        s = s.replace(/\s{2,}/g, ' ').trim();
        return s;
    }

    function normalizeIncorrectCodeList(incorrectCodes, fallbackBad) {
        var codeList = Array.isArray(incorrectCodes) ? incorrectCodes.filter(function (x) { return String(x || '').trim() !== ''; }) : [];
        if (!codeList.length && fallbackBad) codeList = [fallbackBad];
        var uniq = [];
        codeList.forEach(function (c) {
            var val = cleanIncorrectCodeSnippet(c);
            if (val && uniq.indexOf(val) === -1) uniq.push(val);
        });
        return uniq;
    }

    function decodeHtmlEntities(text) {
        var s = String(text || '');
        if (!s) return '';
        var el = document.createElement('textarea');
        el.innerHTML = s;
        return el.value;
    }

    function cleanIncorrectCodeSnippet(raw) {
        var s = String(raw || '').trim();
        if (!s) return '';
        s = s.replace(/^\s*(<\/strong><\/p>\s*)+/ig, '');
        s = s.replace(/^\s*(&lt;\/strong&gt;&lt;\/p&gt;\s*)+/ig, '');
        s = s.replace(/<p>\s*<strong>\s*$/ig, '');
        s = s.replace(/&lt;p&gt;\s*&lt;strong&gt;\s*$/ig, '');
        s = s.replace(/&lt;pre&gt;\s*&lt;code&gt;/ig, '');
        s = s.replace(/&lt;\/code&gt;\s*&lt;\/pre&gt;/ig, '');
        s = decodeHtmlEntities(s);
        s = s.replace(/<pre>\s*<code>/ig, '');
        s = s.replace(/<\/code>\s*<\/pre>/ig, '');
        return String(s || '').trim();
    }

    function extractIncorrectCodeSnippets(raw) {
        var src = String(raw || '').trim();
        if (!src) return [];
        var out = [];

        // Try direct <pre><code> blocks first.
        var preRe = /<pre[^>]*>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/ig;
        var m;
        while ((m = preRe.exec(src)) !== null) {
            var snippet = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet && out.indexOf(snippet) === -1) out.push(snippet);
        }
        if (out.length) return out;

        // Try decoded content if it was entity-encoded.
        var decoded = decodeHtmlEntities(src);
        preRe.lastIndex = 0;
        while ((m = preRe.exec(decoded)) !== null) {
            var snippet2 = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet2 && out.indexOf(snippet2) === -1) out.push(snippet2);
        }
        if (out.length) return out;

        // Support plain <code> blocks too (for autoscan-rendered code format).
        var codeRe = /<code[^>]*>([\s\S]*?)<\/code>/ig;
        while ((m = codeRe.exec(src)) !== null) {
            var snippet3 = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet3 && out.indexOf(snippet3) === -1) out.push(snippet3);
        }
        if (out.length) return out;
        codeRe.lastIndex = 0;
        while ((m = codeRe.exec(decoded)) !== null) {
            var snippet4 = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet4 && out.indexOf(snippet4) === -1) out.push(snippet4);
        }
        if (out.length) return out;

        // Fallback: treat entire text as one snippet.
        var single = cleanIncorrectCodeSnippet(src);
        if (single) out.push(single);
        return out;
    }

    function renderIncorrectCodeBlocks(codeList) {
        if (!Array.isArray(codeList) || !codeList.length) return '<p><code class="autoscan-incorrect-code"></code></p>';
        return codeList.map(function (c) {
            var safe = escapeHtml(String(c || '')).replace(/\n/g, '<br>');
            return '<p><code class="autoscan-incorrect-code">' + safe + '</code></p>';
        }).join('');
    }

    function injectIncorrectCodeBlocksIntoSectionedRaw(raw, codeList) {
        var text = String(raw || '');
        if (!text) return text;
        var blocks = renderIncorrectCodeBlocks(codeList);

        // HTML section format
        var htmlPattern = /(<p[^>]*>\s*<strong>\s*\[Incorrect Code\]\s*<\/strong>\s*<\/p>)([\s\S]*?)(<p[^>]*>\s*<strong>\s*\[(Screenshots|Recommendation)\]\s*<\/strong>\s*<\/p>)/i;
        if (htmlPattern.test(text)) {
            return text.replace(htmlPattern, '$1' + blocks + '$3');
        }

        // Plain-text section format
        var plainPattern = /(\[Incorrect Code\]\s*)([\s\S]*?)(\n\s*\[(Screenshots|Recommendation)\])/i;
        if (plainPattern.test(text)) {
            return text.replace(plainPattern, '$1\n\n' + blocks + '\n\n$3');
        }
        return text;
    }

    function extractIncorrectCodeSectionRaw(raw) {
        var text = String(raw || '');
        if (!text) return '';
        var mHtml = text.match(/<p[^>]*>\s*<strong>\s*\[Incorrect Code\]\s*<\/strong>\s*<\/p>([\s\S]*?)<p[^>]*>\s*<strong>\s*\[(Screenshots|Recommendation)\]\s*<\/strong>\s*<\/p>/i);
        if (mHtml && mHtml[1]) return String(mHtml[1]).trim();
        var mPlain = text.match(/\[Incorrect Code\]\s*([\s\S]*?)\n\s*\[(Screenshots|Recommendation)\]/i);
        if (mPlain && mPlain[1]) return String(mPlain[1]).trim();
        return '';
    }

    function normalizeReviewDetailsForEditor(raw) {
        var text = String(raw || '');
        if (!text) return text;
        if (!/\[Incorrect Code\]/i.test(text)) return text;
        var sectionRaw = extractIncorrectCodeSectionRaw(text);
        var snippets = extractIncorrectCodeSnippets(sectionRaw);
        var codeList = normalizeIncorrectCodeList(snippets, '');
        return injectIncorrectCodeBlocksIntoSectionedRaw(text, codeList);
    }

    function wrapReviewDetailsWithMeta(detailsHtml, title, meta) {
        var raw = String(detailsHtml || '');
        // Clean known broken leading fragments injected by previous malformed saves.
        raw = raw.replace(/^\s*(<\/strong><\/p>\s*)+/i, '');
        var cleanTitle = String(title || '').replace(/-->/g, '').trim();
        var ruleId = String((meta && meta.rule_id) || '').replace(/-->/g, '').trim();
        var impact = String((meta && meta.impact) || '').replace(/-->/g, '').trim();
        var sourceUrl = String((meta && meta.source_url) || '').replace(/-->/g, '').trim();
        var marker =
            '<!-- ISSUE_TITLE: ' + cleanTitle + ' -->\n' +
            '<!-- RULE_ID: ' + ruleId + ' -->\n' +
            '<!-- IMPACT: ' + impact + ' -->\n' +
            '<!-- SOURCE_URL: ' + sourceUrl + ' -->\n';
        // Replace existing marker if present.
        raw = raw.replace(/^\s*<!--\s*ISSUE_TITLE:.*?-->\s*/i, '');
        raw = raw.replace(/^\s*<!--\s*RULE_ID:.*?-->\s*/i, '');
        raw = raw.replace(/^\s*<!--\s*IMPACT:.*?-->\s*/i, '');
        raw = raw.replace(/^\s*<!--\s*SOURCE_URL:.*?-->\s*/i, '');
        return marker + raw;
    }

    function extractUrlsFromDetails(details) {
        var text = String(details || '');
        var urls = [];
        var re = /https?:\/\/[^\s<>"']+/ig;
        var m;
        while ((m = re.exec(text)) !== null) {
            var u = String(m[0] || '').trim();
            if (u && urls.indexOf(u) === -1) urls.push(u);
        }
        return urls;
    }

    function extractSourceUrlsFromDetails(details) {
        var text = String(details || '');
        var urls = [];
        var re = /URL\s+\d+\s*:\s*(https?:\/\/[^\s<>"']+)/ig;
        var m;
        while ((m = re.exec(text)) !== null) {
            var u = String(m[1] || '').trim();
            if (u && urls.indexOf(u) === -1) urls.push(u);
        }
        return urls;
    }

    function extractLabeledValue(text, label) {
        var src = String(text || '');
        var labels = ['Issue', 'Rule ID', 'Impact', 'Source URL', 'Description', 'Failure', 'Incorrect Code', 'Screenshots', 'Recommendation'];
        var pattern = new RegExp('\\b' + label.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&') + ':\\s*([\\s\\S]*?)(?=\\s+(?:' + labels.join('|') + '):|$)', 'i');
        var m = src.match(pattern);
        return m && m[1] ? String(m[1]).trim() : '';
    }

    function normalizeScreenshotList(rawValue, extra) {
        var all = [];
        function add(v) {
            var s = String(v || '').trim();
            if (!s) return;
            if (!/^https?:\/\//i.test(s) && s.indexOf('/assets/uploads/automated_findings/') === -1) return;
            if (all.indexOf(s) === -1) all.push(s);
        }
        String(rawValue || '').split(/\s*\|\s*|\s*,\s*|\s*\n\s*/).forEach(add);
        if (Array.isArray(extra)) extra.forEach(add);
        return all;
    }

    function buildSectionedReviewDetails(baseDetails, urls, instances, fallback, entryRows, incorrectCodes, screenshots) {
        var raw = String(baseDetails || '');
        var bad = extractLabeledValue(raw, 'Incorrect Code') || String((fallback && fallback.incorrect_code) || '').trim();
        var codeList = normalizeIncorrectCodeList(incorrectCodes, bad);
        if (/\[Actual Results\]|\[Incorrect Code\]|\[Recommendation\]|\[Correct Code\]/i.test(raw)) {
            var cleanedRaw = raw.replace(/Fix any of the following:\s*/ig, '');
            return injectIncorrectCodeBlocksIntoSectionedRaw(cleanedRaw, codeList);
        }
        var desc = extractLabeledValue(raw, 'Description') || String((fallback && fallback.description_text) || '').trim();
        var fail = formatFailureSummaryText(extractLabeledValue(raw, 'Failure') || String((fallback && fallback.failure_summary) || ''));
        var rec = extractLabeledValue(raw, 'Recommendation') || String((fallback && fallback.recommendation) || '').trim();

        var parts = [];
        parts.push('<p><strong>[Actual Results]</strong></p>');
        if (desc) parts.push('<p>' + escapeHtml(desc) + '</p>');
        var rows = Array.isArray(entryRows) ? entryRows.filter(function (r) { return r && r.instance; }) : [];
        if (rows.length) {
            var byUrl = {};
            rows.forEach(function (r) {
                var key = String(r.url || '').trim() || (urls[0] || '');
                if (!byUrl[key]) byUrl[key] = [];
                var rowKey = [String(r.instance || ''), String(r.failure || '')].join('||');
                var exists = byUrl[key].some(function (x) {
                    return [String(x.instance || ''), String(x.failure || '')].join('||') === rowKey;
                });
                if (!exists) byUrl[key].push({ instance: String(r.instance || ''), failure: formatFailureSummaryText(r.failure || '') });
            });
            var urlList = Object.keys(byUrl).filter(function (u) { return String(u || '').trim() !== ''; });
            if (!urlList.length && urls.length) urlList = [urls[0]];
            urlList.forEach(function (u, idx) {
                parts.push('<p><strong>URL ' + (idx + 1) + ':</strong> ' + escapeHtml(u) + '</p>');
                var urlRows = byUrl[u] || [];
                if (!urlRows.length && instances.length) {
                    if (fail) parts.push('<p class="review-actual-results-text">' + escapeHtml(fail) + '</p>');
                    parts.push('<ul class="review-actual-results-list">' + instances.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul>');
                    return;
                }
                var uniqueFails = [];
                urlRows.forEach(function (r) {
                    var f = String(r.failure || '').trim();
                    if (f && uniqueFails.indexOf(f) === -1) uniqueFails.push(f);
                });
                if (uniqueFails.length <= 1) {
                    var sharedFail = uniqueFails[0] || fail;
                    if (sharedFail) parts.push('<p class="review-actual-results-text">' + escapeHtml(sharedFail) + '</p>');
                    parts.push('<ul class="review-actual-results-list">' + urlRows.map(function (r) { return '<li>' + escapeHtml(r.instance) + '</li>'; }).join('') + '</ul>');
                } else {
                    parts.push('<ul class="review-actual-results-list">' + urlRows.map(function (r) {
                        var line = '<li>' + escapeHtml(r.instance);
                        if (r.failure) line += '<br><span class="review-actual-results-text">' + escapeHtml(r.failure) + '</span>';
                        line += '</li>';
                        return line;
                    }).join('') + '</ul>');
                }
            });
        } else {
            if (urls.length) parts.push('<p><strong>URL 1:</strong> ' + escapeHtml(urls[0]) + '</p>');
            if (fail) parts.push('<p class="review-actual-results-text">' + escapeHtml(fail) + '</p>');
            if (instances.length) {
                parts.push('<ul class="review-actual-results-list">' + instances.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul>');
            }
        }
        parts.push('<p><strong>[Incorrect Code]</strong></p>');
        parts.push(renderIncorrectCodeBlocks(codeList));
        parts.push('<p><strong>[Screenshots]</strong></p>');
        var shotList = normalizeScreenshotList(extractLabeledValue(raw, 'Screenshots'), screenshots);
        if (shotList.length) {
            parts.push('<div class="issue-image-grid">' + shotList.map(function (u, idx) {
                var src = (u.indexOf('http') === 0 ? u : (u.charAt(0) === '/' ? u : ('/' + u)));
                var safeSrc = escapeAttr(src);
                var alt = 'Screenshot ' + (idx + 1);
                return '<a href="' + safeSrc + '" target="_blank" rel="noopener" aria-label="' + escapeAttr(alt) + '">' +
                    '<img src="' + safeSrc + '" alt="' + escapeAttr(alt) + '" class="issue-image-thumb">' +
                    '</a>';
            }).join('') + '</div>');
        } else {
            parts.push('<p></p>');
        }
        parts.push('<p><strong>[Recommendation]</strong></p>');
        parts.push('<p>' + escapeHtml(rec) + '</p>');
        parts.push('<p><br></p>');
        parts.push('<p><strong>[Correct Code]</strong></p>');
        parts.push('<pre><code></code></pre>');
        return parts.join('');
    }

    function resolveScanUrlCandidates(pageId) {
        var results = [];
        var seen = {};
        var page = (pages || []).find(function (p) { return String(p.id) === String(pageId); });
        var pageUrl = page && page.url ? String(page.url).trim() : '';
        if (page && page.url) {
            seen[String(page.url).trim()] = true;
            results.push({ label: 'Unique URL', value: String(page.url).trim() });
        }
        (groupedUrls || []).forEach(function (g) {
            if (!g) return;
            var mapped = g.mapped_page_id || g.page_id || g.project_page_id || null;
            var matchesMappedPage = mapped && String(mapped) === String(pageId);
            var urlVal = String(g.url || g.normalized_url || '').trim();
            var normalizedVal = String(g.normalized_url || '').trim();
            var matchesPageUrl = pageUrl && (urlVal === pageUrl || normalizedVal === pageUrl);
            if (!matchesMappedPage && !matchesPageUrl) return;
            var val = String(g.url || g.normalized_url || '').trim();
            if (!val || seen[val]) return;
            seen[val] = true;
            results.push({ label: 'Grouped URL', value: val });
        });
        return results;
    }

    function showScanConfigModal() {
        if (!issueData.selectedPageId) {
            alert('Please select a page first.');
            return;
        }
        var modalEl = document.getElementById('reviewScanConfigModal');
        if (!modalEl) {
            runAutomatedScanForSelectedPage();
            return;
        }
        var checklist = document.getElementById('reviewScanUrlChecklist');
        var customUrlInput = document.getElementById('reviewScanCustomUrl');
        var iframeWrap = document.getElementById('reviewScanIframeWrap');
        var iframe = document.getElementById('reviewScanIframe');
        var pageInfo = document.getElementById('reviewScanPageInfo');
        var pageName = getPageName(issueData.selectedPageId);
        if (pageInfo) {
            var modeNote = isInfinityFreeHost ? ' | Mode: Browser scan (same-origin URLs only)' : '';
            pageInfo.textContent = 'Page: ' + pageName + modeNote;
        }
        if (iframeWrap) iframeWrap.classList.add('d-none');
        if (iframe) iframe.src = 'about:blank';
        if (customUrlInput) {
            customUrlInput.value = '';
        }
        var options = resolveScanUrlCandidates(issueData.selectedPageId);
        if (checklist) {
            checklist.innerHTML = options.map(function (o, idx) {
                var id = 'scan-url-' + idx;
                return '<div class="form-check mb-1">' +
                    '<input class="form-check-input review-scan-url-check" type="checkbox" value="' + escapeAttr(o.value) + '" id="' + escapeAttr(id) + '" checked>' +
                    '<label class="form-check-label" for="' + escapeAttr(id) + '">' + escapeHtml(o.label + ' - ' + o.value) + '</label>' +
                    '</div>';
            }).join('') || '<div class="text-muted small">No mapped URLs found for this page.</div>';
        }
        var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();
    }

    function getScanUrlsFromModal() {
        var urls = [];
        document.querySelectorAll('.review-scan-url-check:checked').forEach(function (el) {
            var v = String(el.value || '').trim();
            if (!v) return;
            if (urls.indexOf(v) === -1) urls.push(v);
        });
        return urls;
    }

    function getPrimaryScanUrlFromModal() {
        var urls = getScanUrlsFromModal();
        if (urls.length) return urls[0];
        var custom = (document.getElementById('reviewScanCustomUrl') || {}).value || '';
        return String(custom).trim();
    }

    function normalizeScanUrl(url) {
        var raw = String(url || '').trim();
        if (!raw) return '';
        return /^https?:\/\//i.test(raw) ? raw : ('https://' + raw.replace(/^\/+/, ''));
    }

    function isSameOriginScanUrl(url) {
        try {
            var resolved = new URL(url, window.location.href);
            return resolved.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function loadAxeIntoFrame(frameWin) {
        return new Promise(function (resolve, reject) {
            try {
                if (!frameWin || !frameWin.document) {
                    reject(new Error('Unable to access scan frame.'));
                    return;
                }
                if (frameWin.axe && typeof frameWin.axe.run === 'function') {
                    resolve();
                    return;
                }
                var doc = frameWin.document;
                var existing = doc.querySelector('script[data-pms-axe="1"]');
                if (existing && frameWin.axe && typeof frameWin.axe.run === 'function') {
                    resolve();
                    return;
                }
                var script = existing || doc.createElement('script');
                script.setAttribute('data-pms-axe', '1');
                script.src = axeCoreCdnUrl;
                script.async = true;
                script.onload = function () {
                    if (frameWin.axe && typeof frameWin.axe.run === 'function') {
                        resolve();
                    } else {
                        reject(new Error('axe-core failed to load in scan frame.'));
                    }
                };
                script.onerror = function () {
                    reject(new Error('Unable to load axe-core library.'));
                };
                if (!existing) {
                    (doc.head || doc.documentElement || doc.body).appendChild(script);
                }
            } catch (e) {
                reject(e);
            }
        });
    }

    async function collectBrowserViolations(scanUrl) {
        var normalized = normalizeScanUrl(scanUrl);
        if (!normalized) throw new Error('Scan URL is required.');
        if (!isSameOriginScanUrl(normalized)) {
            throw new Error('Browser scan supports only same-origin URLs on free hosting.');
        }

        var iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.width = '1px';
        iframe.style.height = '1px';
        iframe.style.left = '-9999px';
        iframe.style.top = '-9999px';
        iframe.style.opacity = '0';
        iframe.setAttribute('aria-hidden', 'true');
        document.body.appendChild(iframe);

        try {
            await new Promise(function (resolve, reject) {
                var done = false;
                var timer = setTimeout(function () {
                    if (done) return;
                    done = true;
                    reject(new Error('Timed out while loading scan URL.'));
                }, 45000);
                iframe.onload = function () {
                    if (done) return;
                    done = true;
                    clearTimeout(timer);
                    resolve();
                };
                iframe.onerror = function () {
                    if (done) return;
                    done = true;
                    clearTimeout(timer);
                    reject(new Error('Unable to load scan URL in browser frame.'));
                };
                iframe.src = normalized;
            });

            var frameWin = iframe.contentWindow;
            if (!frameWin || !frameWin.document) {
                throw new Error('Unable to access loaded page for scan.');
            }
            await loadAxeIntoFrame(frameWin);
            var axeResult = await frameWin.axe.run(frameWin.document, {
                resultTypes: ['violations'],
                runOnly: {
                    type: 'tag',
                    values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice']
                }
            });
            return {
                scan_url: normalized,
                violations: Array.isArray(axeResult && axeResult.violations) ? axeResult.violations : []
            };
        } finally {
            try { iframe.remove(); } catch (e) {}
        }
    }

    async function storeBrowserScanFindings(scanUrl, violations) {
        var fd = new FormData();
        fd.append('action', 'store_scan_results');
        fd.append('project_id', projectId);
        fd.append('page_id', issueData.selectedPageId);
        fd.append('scan_url', normalizeScanUrl(scanUrl));
        fd.append('violations_json', JSON.stringify(Array.isArray(violations) ? violations : []));
        var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
        var json = await res.json();
        if (!res.ok || !json || json.error) {
            throw new Error((json && json.error) ? json.error : 'Failed to store scan results');
        }
        return Number(json.created || 0);
    }

    async function runBrowserAutomatedScanForSelectedPage(scanUrl) {
        var result = await collectBrowserViolations(scanUrl);
        var created = await storeBrowserScanFindings(result.scan_url, result.violations);
        return created;
    }

    async function runAutomatedScanForSelectedPage(scanUrl, options) {
        options = options || {};
        if (!issueData.selectedPageId) {
            alert('Please select a page first.');
            return;
        }
        var btn = document.getElementById('reviewRunScanBtn');
        var progressEl = document.getElementById('reviewScanProgress');
        var oldText = btn ? btn.textContent : '';
        var progressVal = 0;
        var progressTimer = null;
        if (progressEl && !options.manageProgressExternally) progressEl.textContent = '0%';
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Scanning...';
        }
        if (!options.manageProgressExternally) {
            progressTimer = setInterval(function () {
                progressVal = Math.min(95, progressVal + 5);
                if (progressEl) progressEl.textContent = progressVal + '%';
            }, 500);
        }
        try {
            var createdCount = 0;
            if (isInfinityFreeHost) {
                createdCount = await runBrowserAutomatedScanForSelectedPage(scanUrl);
            } else {
                var fd = new FormData();
                fd.append('action', 'run_scan');
                fd.append('project_id', projectId);
                fd.append('page_id', issueData.selectedPageId);
                if (scanUrl) fd.append('scan_url', scanUrl);
                var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
                var json = await res.json();
                if (!res.ok || !json || json.error) {
                    throw new Error((json && json.error) ? json.error : 'Scan failed');
                }
                createdCount = Number(json.created || 0);
            }
            if (progressEl && !options.manageProgressExternally) progressEl.textContent = '100%';
            if (!options.skipReload) await loadReviewFindings(issueData.selectedPageId);
            if (!options.silent && window.showToast) showToast('Scan complete. ' + createdCount + ' findings added for review.', 'success');
            return createdCount;
        } catch (e) {
            if (!options.silent) alert('Automated scan failed: ' + e.message);
            throw e;
        } finally {
            if (progressTimer) clearInterval(progressTimer);
            if (btn) {
                btn.textContent = oldText || 'Run Auto Scan';
                updateEditingState();
            }
            if (progressEl && !options.manageProgressExternally) setTimeout(function () { progressEl.textContent = ''; }, 1500);
        }
    }

    async function loadReviewFindings(pageId) {
        if (!pageId) return;
        var tbody = document.getElementById('reviewIssuesBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="text-muted text-center">Loading automated findings...</td></tr>';
        var store = issueData.pages;
        ensurePageStore(store, pageId);
        try {
            var url = apiBase + '?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.findings) ? json.findings : [];
            store[pageId].review = items.map(function (it) {
                var impactVal = String(it.impact || '').toLowerCase();
                var derivedSeverity = (function () {
                    if (impactVal === 'critical') return 'critical';
                    if (impactVal === 'serious') return 'high';
                    if (impactVal === 'moderate') return 'medium';
                    if (impactVal === 'minor') return 'low';
                    return 'medium';
                })();
                return {
                    id: String(it.id),
                    title: it.title || 'Automated Issue',
                    instance: cleanInstanceValue(it.instance_name || ''),
                    wcag: it.wcag_failure || '',
                    rule_id: it.rule_id || '',
                    impact: it.impact || '',
                    source_url: it.source_url || '',
                    description_text: it.description_text || '',
                    failure_summary: it.failure_summary || '',
                    incorrect_code: it.incorrect_code || '',
                    recommendation: it.recommendation || '',
                    severity: (it.severity || derivedSeverity),
                    details: it.details || '',
                    page_id: it.page_id || pageId
                };
            });
            reviewCurrentPage = 1;
            renderReviewIssues();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="text-muted text-center">Unable to load automated findings.</td></tr>';
        }
    }

    async function loadFinalIssues(pageId) {
        if (!pageId) return;
        var tbody = document.getElementById('finalIssuesBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center">Loading final issues...</td></tr>';
        var store = issueData.pages;
        ensurePageStore(store, pageId);
        try {
            var url = issuesApiBase + '?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.issues) ? json.issues : [];
            store[pageId].final = items.map(function (it) {
                return {
                    id: String(it.id),
                    issue_key: it.issue_key || '',
                    title: it.title || 'Issue',
                    details: it.description || '',
                    status: it.status || 'open',
                    status_id: it.status_id || null,
                    qa_status: Array.isArray(it.qa_status) ? it.qa_status : (it.qa_status ? [it.qa_status] : []),
                    severity: it.severity || 'medium',
                    priority: it.priority || 'medium',
                    pages: it.pages || [],
                    grouped_urls: it.grouped_urls || [],
                    reporter_name: it.reporter_name || null,
                    qa_name: it.qa_name || null,
                    page_id: it.page_id || pageId,
                    // Metadata fields - use correct field names from API
                    usersaffected: it.usersaffected || [],
                    wcagsuccesscriteria: it.wcagsuccesscriteria || [],
                    wcagsuccesscriterianame: it.wcagsuccesscriterianame || [],
                    wcagsuccesscriterialevel: it.wcagsuccesscriterialevel || [],
                    gigw30: it.gigw30 || [],
                    is17802: it.is17802 || [],
                    common_title: it.common_title || '',
                    reporters: it.reporters || [],
                    // Add created_at and updated_at timestamps
                    created_at: it.created_at || null,
                    updated_at: it.updated_at || null
                };
            });
            renderFinalIssues();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center">Unable to load final issues.</td></tr>';
        }
    }

    async function loadCommonIssues() {
        var tbody = document.getElementById('commonIssuesBody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Loading common issues...</td></tr>';
        try {
            var url = issuesApiBase + '?action=common_list&project_id=' + encodeURIComponent(projectId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.common) ? json.common : [];
            issueData.common = items.map(function (it) {
                return {
                    id: String(it.id),
                    issue_id: it.issue_id,
                    title: it.title || 'Common Issue',
                    description: it.description || '',
                    pages: it.pages || []
                };
            });
            renderCommonIssues();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Unable to load common issues.</td></tr>';
        }
    }

    function initSelect2() {
        if (!window.jQuery || !jQuery.fn.select2) return;
        jQuery('.issue-select2').each(function () {
            var $el = jQuery(this);
            var $parent = $el.closest('.modal');
            $el.select2({
                width: '100%',
                dropdownParent: $parent.length ? $parent : null
            });
        });
        jQuery('.issue-select2-tags').each(function () {
            var $el = jQuery(this);
            var $parent = $el.closest('.modal');
            $el.select2({
                width: '100%',
                tags: true,
                tokenSeparators: [','],
                dropdownParent: $parent.length ? $parent : null
            });
        });

        // Grouped URLs should allow adding ad-hoc URLs from the modal.
        var $grouped = jQuery('#finalIssueGroupedUrls');
        if ($grouped.length) {
            try { if ($grouped.data('select2')) $grouped.select2('destroy'); } catch (e) { }
            var $gpParent = $grouped.closest('.modal');
            $grouped.select2({
                width: '100%',
                tags: true,
                tokenSeparators: [','],
                closeOnSelect: false,
                placeholder: 'Search or add URLs...',
                dropdownParent: $gpParent.length ? $gpParent : null
            });
        }

        // Add event listener for pages select to auto-populate grouped URLs
        jQuery('#finalIssuePages').off('change.issueGrouped').on('change.issueGrouped', function () {
            // Use the existing updateGroupedUrls function which properly handles URLs
            updateGroupedUrls();
        });

        jQuery('#finalIssueGroupedUrls').off('change.issueSummary').on('change.issueSummary', function () {
            updateUrlSelectionSummary();
            updateGroupedUrlsPreview();
        });

    }

    function uploadIssueImage(file, $el) {
        if (!file || !file.type || !file.type.startsWith('image/')) return;
        var now = Date.now();
        if (now - issueData.imageUpload.lastPasteTime < 500) return;
        issueData.imageUpload.lastPasteTime = now;
        issueData.imageUpload.savedRange = $el.summernote('createRange');
        issueData.imageUpload.pendingFile = file;
        issueData.imageUpload.pendingEditor = $el;
        issueData.imageUpload.isEditing = false;
        showImageAltModal('');
    }

    function showImageAltModal(currentAlt) {
        var $modal = jQuery('#imageAltTextModal');
        if (!$modal.length) {
            var modalHtml = `
                <div class="modal fade" id="imageAltTextModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Image Alt-Text</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <label class="form-label">Enter descriptive alt-text for this image:</label>
                                <input type="text" class="form-control" id="imageAltTextInput" placeholder="e.g., Screenshot showing login error">
                                <div class="form-text">Alt-text helps with accessibility and SEO. You can edit this later by clicking the image.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="btnConfirmAltText">Upload Image</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            jQuery('body').append(modalHtml);
            $modal = jQuery('#imageAltTextModal');
            jQuery('#btnConfirmAltText').on('click', confirmImageAltText);
            jQuery('#imageAltTextInput').on('keypress', function (e) {
                if (e.which === 13) { e.preventDefault(); confirmImageAltText(); }
            });
        }
        jQuery('#imageAltTextInput').val(currentAlt);
        var modal = new bootstrap.Modal($modal[0]);
        modal.show();
        $modal.one('shown.bs.modal', function () { jQuery('#imageAltTextInput').focus(); });
    }

    function confirmImageAltText() {
        var altText = jQuery('#imageAltTextInput').val().trim();
        if (issueData.imageUpload.isEditing && issueData.imageUpload.editingImg) {
            issueData.imageUpload.editingImg.attr('alt', altText || 'Issue Screenshot');
            bootstrap.Modal.getInstance(jQuery('#imageAltTextModal')[0]).hide();
            issueData.imageUpload.isEditing = false;
            issueData.imageUpload.editingImg = null;
        } else if (issueData.imageUpload.pendingFile && issueData.imageUpload.pendingEditor) {
            var file = issueData.imageUpload.pendingFile;
            var $el = issueData.imageUpload.pendingEditor;
            var fd = new FormData();
            fd.append('image', file);
            fetch(issueImageUploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json()).then(function (res) {
                    if (res && res.success && res.url) {
                        var safeAlt = (altText || 'Issue Screenshot').replace(/"/g, '&quot;');
                        var imgHtml = '<img src="' + res.url + '" alt="' + safeAlt + '" style="max-width:100%; height:auto; cursor:pointer;" class="editable-issue-image" />';
                        if (issueData.imageUpload.savedRange) {
                            $el.summernote('restoreRange');
                            issueData.imageUpload.savedRange.pasteHTML(imgHtml);
                            issueData.imageUpload.savedRange = null;
                        } else {
                            $el.summernote('insertNode', $('<img>').attr({ src: res.url, alt: safeAlt, style: 'max-width:100%; height:auto; cursor:pointer;', class: 'editable-issue-image' })[0]);
                        }
                        bootstrap.Modal.getInstance(jQuery('#imageAltTextModal')[0]).hide();
                    } else if (res && res.error) { alert(res.error); }
                }).catch(function () { alert('Image upload failed'); })
                .finally(function () {
                    issueData.imageUpload.pendingFile = null;
                    issueData.imageUpload.pendingEditor = null;
                });
        }
    }

    function initSummernote(el) {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        var $el = jQuery(el);
        if ($el.data('summernote')) return;

        if (!document.getElementById('issue-codeblock-btn-style')) {
            var st = document.createElement('style');
            st.id = 'issue-codeblock-btn-style';
            st.textContent = '.note-btn-codeblock.active{background-color:#0d6efd!important;color:#fff!important;border-color:#0a58ca!important;}';
            document.head.appendChild(st);
        }

        function setCodeBlockButtonState() {
            var $btn = $el.next('.note-editor').find('.note-btn-codeblock');
            if (!$btn.length) return;
            var inCode = false;
            try {
                var range = $el.summernote('createRange');
                var sc = range && range.sc ? range.sc : null;
                var node = sc && sc.nodeType === 3 ? sc.parentNode : sc;
                var editable = $el.next('.note-editor').find('.note-editable')[0];
                inCode = !!(node && editable && jQuery(node).closest('code', editable).length);
            } catch (e) { inCode = false; }
            $btn
                .toggleClass('active', inCode)
                .attr('aria-pressed', inCode ? 'true' : 'false')
                .attr('title', 'Code Block')
                .attr('aria-label', 'Code Block');
        }

        function enableToolbarKeyboardA11y() {
            var $toolbar = $el.next('.note-editor').find('.note-toolbar').first();
            if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;
            function getItems() {
                return $toolbar.find('.note-btn-group button').filter(function () {
                    var $b = jQuery(this);
                    if ($b.is(':hidden')) return false;
                    if ($b.prop('disabled')) return false;
                    if ($b.closest('.dropdown-menu').length) return false;
                    if ($b.attr('aria-hidden') === 'true') return false;
                    return true;
                });
            }

            function setActiveIndex(idx) {
                var $items = getItems();
                if (!$items.length) return;
                var next = Math.max(0, Math.min(idx, $items.length - 1));
                $items.attr('tabindex', '-1');
                $items.eq(next).attr('tabindex', '0');
                $toolbar.data('kbdIndex', next);
            }

            function ensureOneTabStop() {
                var $items = getItems();
                if (!$items.length) return;
                if (!$items.filter('[tabindex="0"]').length) {
                    $items.attr('tabindex', '-1');
                    $items.eq(0).attr('tabindex', '0');
                }
            }

            $toolbar.attr('role', 'toolbar');
            if (!$toolbar.attr('aria-label')) {
                $toolbar.attr('aria-label', 'Editor toolbar');
            }

            setActiveIndex(0);

            $toolbar.on('focusin', 'button', function () {
                var $items = getItems();
                var idx = $items.index(this);
                if (idx >= 0) setActiveIndex(idx);
            });
            $toolbar.on('click', 'button', function () {
                var $items = getItems();
                var idx = $items.index(this);
                if (idx >= 0) setActiveIndex(idx);
            });

            function handleToolbarArrowNav(e) {
                var key = e.key || e.originalEvent && e.originalEvent.key;
                if (key !== 'ArrowRight' && key !== 'ArrowLeft' && key !== 'Home' && key !== 'End') return;

                var $items = getItems();
                if (!$items.length) return;
                var activeEl = document.activeElement;
                var idx = $items.index(activeEl);
                if (idx < 0 && activeEl && activeEl.closest) {
                    var parentBtn = activeEl.closest('button');
                    if (parentBtn) idx = $items.index(parentBtn);
                }
                if (idx < 0) {
                    var savedIdx = parseInt($toolbar.data('kbdIndex'), 10);
                    if (!isNaN(savedIdx) && savedIdx >= 0 && savedIdx < $items.length) idx = savedIdx;
                }
                if (idx < 0) idx = $items.index($items.filter('[tabindex="0"]').first());
                if (idx < 0) idx = 0;

                e.preventDefault();
                if (e.stopPropagation) e.stopPropagation();
                if (key === 'Home') idx = 0;
                else if (key === 'End') idx = $items.length - 1;
                else if (key === 'ArrowRight') idx = (idx + 1) % $items.length;
                else if (key === 'ArrowLeft') idx = (idx - 1 + $items.length) % $items.length;

                setActiveIndex(idx);
                var $target = $items.eq(idx);
                $target.focus();
                if (document.activeElement !== $target.get(0)) {
                    setTimeout(function () { $target.focus(); }, 0);
                }
            }

            $toolbar.on('keydown', handleToolbarArrowNav);
            if (!$toolbar.data('kbdA11yNativeKeyBound')) {
                $toolbar.get(0).addEventListener('keydown', handleToolbarArrowNav, true);
                $toolbar.data('kbdA11yNativeKeyBound', true);
            }

            // Keep one button tabbable even if Summernote resets tabindex to -1.
            var observer = new MutationObserver(function () { ensureOneTabStop(); });
            observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
            $toolbar.data('kbdA11yObserver', observer);
            var fixTimer = setInterval(ensureOneTabStop, 1000);
            $toolbar.data('kbdA11yTimer', fixTimer);
            ensureOneTabStop();

            $toolbar.data('kbdA11yBound', true);
        }

        function toggleCodeBlock(context) {
            context.invoke('editor.focus');
            context.invoke('editor.saveRange');
            var range = context.invoke('editor.createRange');
            var sc = range && range.sc ? range.sc : null;
            var node = sc && sc.nodeType === 3 ? sc.parentNode : sc;
            var editable = context.layoutInfo && context.layoutInfo.editable ? context.layoutInfo.editable[0] : ($el.next('.note-editor').find('.note-editable')[0] || null);
            var inCode = false;
            if (node && editable) {
                inCode = jQuery(node).closest('code', editable).length > 0;
            }
            if (inCode) {
                var $code = jQuery(node).closest('code', editable).first();
                if ($code.length) {
                    var txt = document.createTextNode($code.text());
                    var codeNode = $code.get(0);
                    codeNode.parentNode.replaceChild(txt, codeNode);
                    try {
                        var sel = window.getSelection();
                        if (sel) {
                            var r = document.createRange();
                            r.setStart(txt, txt.textContent.length);
                            r.collapse(true);
                            sel.removeAllRanges();
                            sel.addRange(r);
                        }
                    } catch (e) { }
                }
            } else {
                var sel = window.getSelection();
                if (sel && sel.rangeCount) {
                    var nativeRange = sel.getRangeAt(0);
                    var selectedText = nativeRange.toString();
                    var code = document.createElement('code');
                    if (selectedText) {
                        code.textContent = selectedText;
                        nativeRange.deleteContents();
                        nativeRange.insertNode(code);
                        var after = document.createRange();
                        after.setStartAfter(code);
                        after.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(after);
                    } else {
                        code.textContent = '\u200B';
                        nativeRange.insertNode(code);
                        var inside = document.createRange();
                        inside.setStart(code.firstChild, 1);
                        inside.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(inside);
                    }
                }
            }
            setTimeout(setCodeBlockButtonState, 0);
        }

        var editorHeight = 180;
        if ($el && $el.attr && $el.attr('id') === 'reviewIssueDetails') {
            editorHeight = 320;
        }
        $el.summernote({
            height: editorHeight,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
                ['fontname', ['fontname']],
                ['fontsize', ['fontsize']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph', 'height']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'video', 'hr', 'codeBlockToggle']],
                ['view', ['fullscreen', 'help']]
            ],
            styleTags: ['p', { title: 'Blockquote', tag: 'blockquote', className: 'blockquote', value: 'blockquote' }, 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            popover: { image: [['image', ['resizeFull', 'resizeHalf', 'resizeQuarter', 'resizeNone']], ['float', ['floatLeft', 'floatRight', 'floatNone']], ['remove', ['removeMedia']], ['custom', ['imageAltText']]] },
            buttons: {
                codeBlockToggle: function (context) {
                    var ui = jQuery.summernote.ui;
                    var $btn = ui.button({
                        contents: '&lt;/&gt;',
                        className: 'note-btn-codeblock',
                        click: function () { toggleCodeBlock(context); }
                    }).render();
                    try {
                        $btn.attr('title', 'Code Block');
                        $btn.attr('aria-label', 'Code Block');
                    } catch (e) { }
                    return $btn;
                },
                imageAltText: function (context) {
                    var ui = jQuery.summernote.ui;
                    return ui.button({
                        contents: '<i class="fas fa-tag"/> <span style="font-size:0.75em;">Alt Text</span>',
                        tooltip: 'Edit alt text',
                        click: function () {
                            var $img = jQuery(context.invoke('restoreTarget'));
                            if ($img && $img.length) {
                                issueData.imageUpload.isEditing = true;
                                issueData.imageUpload.editingImg = $img;
                                showImageAltModal($img.attr('alt') || '');
                            }
                        }
                    }).render();
                }
            },
            callbacks: {
                onInit: function () {
                    setTimeout(setCodeBlockButtonState, 0);
                    setTimeout(enableToolbarKeyboardA11y, 0);
                    setTimeout(enableToolbarKeyboardA11y, 200);
                },
                onFocus: function () { setCodeBlockButtonState(); },
                onKeyup: function () { setCodeBlockButtonState(); },
                onMouseup: function () { setCodeBlockButtonState(); },
                onChange: function () { setCodeBlockButtonState(); },
                onImageUpload: function (files) { (files || []).forEach(function (f) { uploadIssueImage(f, $el); }); },
                onPaste: function (e) {
                    var clipboard = e.originalEvent && e.originalEvent.clipboardData;
                    if (clipboard && clipboard.items) {
                        for (var i = 0; i < clipboard.items.length; i++) {
                            var item = clipboard.items[i];
                            if (item.type && item.type.indexOf('image') === 0) {
                                e.preventDefault(); uploadIssueImage(item.getAsFile(), $el); break;
                            }
                        }
                    }
                }
            }
        });
    }

    function initEditors() {
        document.querySelectorAll('.issue-summernote').forEach(function (el) { initSummernote(el); });
        jQuery(document).on('click', '.note-editable img', function (e) {
            e.preventDefault();
            var $img = jQuery(this);
            issueData.imageUpload.isEditing = true;
            issueData.imageUpload.editingImg = $img;
            showImageAltModal($img.attr('alt') || '');
        });

        // Initialize @ mention for comment editor
        initMentionSupport();
    }

    function initMentionSupport() {
        var $editor = jQuery('#finalIssueCommentEditor');
        if (!$editor.length) return;

        var mentionDropdown = null;
        var mentionIndex = -1;
        var mentionList = [];
        var lastAtPosition = null;

        // Create mention dropdown
        var dropdownHtml = '<div id="issueMentionDropdown" class="dropdown-menu" style="display:none; position:fixed; z-index:99999; max-height:200px; overflow-y:auto;"></div>';
        if (!document.getElementById('issueMentionDropdown')) {
            jQuery('body').append(dropdownHtml);
        }
        mentionDropdown = document.getElementById('issueMentionDropdown');

        // Handle keydown in Summernote (for preventing default behavior)
        $editor.on('summernote.keydown', function (we, e) {
            // Check if dropdown is visible
            var dropdownVisible = mentionDropdown && mentionDropdown.style.display === 'block';

            if (dropdownVisible) {
                if (e.keyCode === 40) { // Arrow down
                    e.preventDefault();
                    e.stopPropagation();
                    moveMentionHighlight(1);
                    return false;
                } else if (e.keyCode === 38) { // Arrow up
                    e.preventDefault();
                    e.stopPropagation();
                    moveMentionHighlight(-1);
                    return false;
                } else if (e.keyCode === 13) { // Enter
                    e.preventDefault();
                    e.stopPropagation();
                    var active = mentionDropdown.querySelector('.mention-item.active');
                    if (active) {
                        insertMention(active.getAttribute('data-username'));
                    }
                    return false;
                } else if (e.keyCode === 9) { // Tab
                    e.preventDefault();
                    e.stopPropagation();
                    var active = mentionDropdown.querySelector('.mention-item.active');
                    if (active) {
                        insertMention(active.getAttribute('data-username'));
                    }
                    return false;
                } else if (e.keyCode === 27) { // Escape
                    e.preventDefault();
                    e.stopPropagation(); // CRITICAL: Stop event from reaching modal
                    e.stopImmediatePropagation(); // Also stop other handlers
                    hideMentionDropdown();
                    return false;
                }
            }
        });

        // Handle keyup in Summernote (for showing/hiding dropdown)
        $editor.on('summernote.keyup', function (we, e) {
            // Don't process if we just handled navigation keys
            if (mentionDropdown && mentionDropdown.style.display === 'block') {
                if ([9, 13, 27, 38, 40].indexOf(e.keyCode) !== -1) {
                    return;
                }
            }

            // Don't show dropdown if it was just closed by Escape
            if (e.keyCode === 27) {
                return;
            }

            // Get the editable div content
            var $editable = $editor.next('.note-editor').find('.note-editable');
            if (!$editable.length) return;

            var text = $editable.text();
            var lastAtPos = text.lastIndexOf('@');

            // Check if @ was just typed or we're typing after @
            if (lastAtPos >= 0) {
                var afterAt = text.substring(lastAtPos + 1);
                // Check if we're still in a mention (no space after @)
                var spacePos = afterAt.indexOf(' ');
                var query = spacePos >= 0 ? afterAt.substring(0, spacePos) : afterAt;

                // Only show dropdown if query is reasonable (no special chars, reasonable length)
                if (query.length <= 50 && /^[\w]*$/.test(query)) {
                    showMentionDropdown(query, $editable);
                } else if (query.length === 0) {
                    showMentionDropdown('', $editable);
                } else {
                    hideMentionDropdown();
                }
            } else {
                hideMentionDropdown();
            }
        });

        function showMentionDropdown(query, $editable) {
            var users = ProjectConfig.projectUsers || [];
            var q = String(query || '').toLowerCase();
            mentionList = users.filter(function (u) {
                var fullName = String(u.full_name || '').toLowerCase();
                var username = String(u.username || '').toLowerCase();
                return fullName.indexOf(q) >= 0 || username.indexOf(q) >= 0;
            }).sort(function (a, b) {
                var aUser = String(a.username || '').toLowerCase();
                var bUser = String(b.username || '').toLowerCase();
                var aIsAdmin = aUser === 'admin' || aUser === 'super_admin' || aUser === 'superadmin' || String(a.role || '').toLowerCase().indexOf('admin') >= 0;
                var bIsAdmin = bUser === 'admin' || bUser === 'super_admin' || bUser === 'superadmin' || String(b.role || '').toLowerCase().indexOf('admin') >= 0;
                if (aIsAdmin !== bIsAdmin) return aIsAdmin ? -1 : 1;
                return String(a.full_name || '').localeCompare(String(b.full_name || ''));
            });

            if (mentionList.length === 0) {
                hideMentionDropdown();
                return;
            }

            var html = mentionList.map(function (u, idx) {
                var username = String(u.username || '').trim() || String(u.full_name || '').replace(/\s+/g, '');
                return '<a href="#" class="dropdown-item mention-item' + (idx === 0 ? ' active' : '') + '" data-username="' +
                    escapeHtml(username) + '" data-id="' + u.id + '">' +
                    escapeHtml(u.full_name) + '</a>';
            }).join('');

            mentionDropdown.innerHTML = html;
            mentionDropdown.style.display = 'block';
            mentionIndex = 0;

            // Position dropdown near @ symbol using cursor position
            if ($editable && $editable.length) {
                try {
                    // Get cursor position from Summernote
                    var range = $editor.summernote('createRange');
                    if (range && range.getClientRects) {
                        var rects = range.getClientRects();
                        if (rects && rects.length > 0) {
                            var rect = rects[0];
                            // Position dropdown just below cursor
                            mentionDropdown.style.left = rect.left + 'px';
                            mentionDropdown.style.top = (rect.bottom + 5) + 'px';
                        } else {
                            // Fallback to editor position
                            var offset = $editable.offset();
                            mentionDropdown.style.left = offset.left + 'px';
                            mentionDropdown.style.top = (offset.top + 30) + 'px';
                        }
                    } else {
                        // Fallback to editor position
                        var offset = $editable.offset();
                        mentionDropdown.style.left = offset.left + 'px';
                        mentionDropdown.style.top = (offset.top + 30) + 'px';
                    }
                } catch (e) {
                    // Fallback to editor position
                    var offset = $editable.offset();
                    mentionDropdown.style.left = offset.left + 'px';
                    mentionDropdown.style.top = (offset.top + 30) + 'px';
                }
            }

            // Click handler
            jQuery(mentionDropdown).find('.mention-item').off('click').on('click', function (e) {
                e.preventDefault();
                insertMention(jQuery(this).attr('data-username'));
            });
        }

        function hideMentionDropdown() {
            if (mentionDropdown) {
                mentionDropdown.style.display = 'none';
            }
        }

        function moveMentionHighlight(direction) {
            var items = mentionDropdown.querySelectorAll('.mention-item');
            if (items.length === 0) return;

            items[mentionIndex].classList.remove('active');
            mentionIndex += direction;
            if (mentionIndex < 0) mentionIndex = items.length - 1;
            if (mentionIndex >= items.length) mentionIndex = 0;
            items[mentionIndex].classList.add('active');
            items[mentionIndex].scrollIntoView({ block: 'nearest' });
        }

        function insertMention(username) {
            var $editable = $editor.next('.note-editor').find('.note-editable');
            var text = $editable.text();
            var lastAtPos = text.lastIndexOf('@');

            if (lastAtPos >= 0) {
                // Get current HTML
                var currentHtml = $editor.summernote('code');

                // Find position of @ in text
                var beforeAtText = text.substring(0, lastAtPos);
                var afterAtText = text.substring(lastAtPos);

                // Find where the query ends (space or end of string)
                var queryEndPos = afterAtText.indexOf(' ');
                if (queryEndPos === -1) queryEndPos = afterAtText.length;

                // Build new text with mention
                var newText = beforeAtText + '@' + username + ' ' + afterAtText.substring(queryEndPos);

                // For HTML, we need to be more careful
                // Find the last @ in HTML
                var lastAtHtmlPos = currentHtml.lastIndexOf('@');
                if (lastAtHtmlPos >= 0) {
                    var beforeAtHtml = currentHtml.substring(0, lastAtHtmlPos);
                    var afterAtHtml = currentHtml.substring(lastAtHtmlPos + 1);

                    // Remove the partial query after @
                    // Find the next space, tag, or end
                    var endMatch = afterAtHtml.match(/^[\w]*/);
                    var queryLength = endMatch ? endMatch[0].length : 0;
                    afterAtHtml = afterAtHtml.substring(queryLength);

                    // Insert the mention with space
                    var newHtml = beforeAtHtml + '@' + username + ' ' + afterAtHtml;

                    // Set the new HTML
                    $editor.summernote('code', newHtml);

                    // Move cursor to end
                    $editor.summernote('editor.saveRange');
                    $editor.summernote('editor.restoreRange');

                    // Focus at the end
                    var range = document.createRange();
                    var sel = window.getSelection();
                    var editableEl = $editable[0];

                    // Move to end of content
                    range.selectNodeContents(editableEl);
                    range.collapse(false);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }
            hideMentionDropdown();
        }
    }

    function showFinalIssuesTab() {
        var tabBtn = document.getElementById('final-issues-tab');
        if (!tabBtn) return;
        try { new bootstrap.Tab(tabBtn).show(); } catch (e) { }
    }

    function setSelectedPage(btn) {
        if (!btn) return;
        var pid = btn.getAttribute('data-page-id');
        if (!pid || pid === '0') return;
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (b) { b.classList.remove('table-active'); });
        btn.classList.add('table-active');
        issueData.selectedPageId = pid;
        ensurePageStore(issueData.pages, issueData.selectedPageId);

        var name = btn.getAttribute('data-page-name') || 'Page';
        var tester = btn.getAttribute('data-page-tester') || '-';
        var env = btn.getAttribute('data-page-env') || '-';
        var issues = btn.getAttribute('data-page-issues') || '0';
        var nameEl = document.getElementById('issueSelectedPageName');
        var metaEl = document.getElementById('issueSelectedPageMeta');
        if (nameEl) nameEl.textContent = name;
        if (metaEl) metaEl.textContent = 'Tester: ' + tester + ' | Env: ' + env + ' | Issues: ' + issues;
        showFinalIssuesTab();

        showIssuesDetail();
        updateEditingState();
        populatePageUrls(issueData.selectedPageId);
        reviewCurrentPage = 1;
        renderAll();
        loadReviewFindings(issueData.selectedPageId);
        loadFinalIssues(issueData.selectedPageId);
    }

    function attachPageClickListeners() {
        var pageRows = document.querySelectorAll('#issuesPageList .issues-page-row');
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (btn) {
            // Remove existing listeners to avoid duplicates
            if (btn._pageClickHandler) {
                btn.removeEventListener('click', btn._pageClickHandler);
            }
            // Create and attach new handler
            btn._pageClickHandler = function (e) {
                // Don't trigger if clicking on the collapse button
                if (e.target.closest('button[data-bs-toggle="collapse"]')) return;
                console.debug('issues: row clicked (direct) on', btn.getAttribute('data-page-id') || btn.getAttribute('data-unique-id'));
                var pageId = btn.getAttribute('data-page-id');
                if (pageId && pageId !== '0') {
                    setSelectedPage(btn);
                    return;
                }
                var uniqueId = btn.getAttribute('data-unique-id');
                if (!uniqueId) return;
                setSelectedUniquePage(btn, uniqueId);
            };
            btn.addEventListener('click', btn._pageClickHandler);
        });

        // Delegated click handler as a fallback for dynamic row updates
        try {
            var container = document.getElementById('issuesPageList');
            if (container && !container._issuesDelegateAttached) {
                container._issuesDelegateAttached = true;
                try { } catch (e) { }
                container.addEventListener('click', function (e) {
                    var row = e.target.closest && e.target.closest('.issues-page-row');
                    if (!row) return;
                    // Ignore clicks on collapse toggle buttons
                    if (e.target.closest && e.target.closest('button[data-bs-toggle="collapse"]')) return;
                    console.debug('issues: delegated row click on', row.getAttribute('data-page-id') || row.getAttribute('data-unique-id'));
                    var pageId = row.getAttribute('data-page-id');
                    if (pageId && pageId !== '0') { setSelectedPage(row); return; }
                    var uniqueId = row.getAttribute('data-unique-id');
                    if (!uniqueId) return;
                    setSelectedUniquePage(row, uniqueId);
                });
            }
        } catch (e) { /* ignore */ }
    }

    function populatePageUrls(pageId) {
        var card = document.getElementById('pageUrlsCard');
        var content = document.getElementById('pageUrlsListContent');
        var count = document.getElementById('urlsCount');

        if (!pageId || !card || !content) return;

        // Get URLs for this page from groupedUrls array
        var urls = groupedUrls.filter(function (u) {
            return u.mapped_page_id == pageId;
        });

        if (urls.length === 0) {
            card.style.display = 'none';
            return;
        }

        // Show card and populate URLs
        card.style.display = 'block';
        count.textContent = urls.length;

        content.innerHTML = urls.map(function (u) {
            return '<li class="mb-1"><i class="fas fa-angle-right text-muted me-2"></i>' +
                escapeHtml(u.url) + '</li>';
        }).join('');
    }

    function showIssuesDetail() {
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) pagesCol.classList.add('d-none');
        if (detailCol) {
            detailCol.classList.remove('d-none');
            detailCol.classList.remove('col-lg-8');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.remove('d-none');
    }

    function showIssuesPages() {
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) {
            pagesCol.classList.remove('d-none');
            pagesCol.classList.remove('col-lg-4');
            pagesCol.classList.add('col-lg-12');
        }
        if (detailCol) {
            detailCol.classList.add('d-none');
            detailCol.classList.remove('col-lg-12');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.add('d-none');
    }

    function captureFormState() {
        var titleInput = document.getElementById('customIssueTitle');
        var titleVal = titleInput ? titleInput.value.trim() : '';
        var detailsVal = jQuery('#finalIssueDetails').summernote('code') || '';
        var statusVal = document.getElementById('finalIssueStatus').value;
        var qaStatusVal = jQuery('#finalIssueQaStatus').val() || [];
        var pagesVal = jQuery('#finalIssuePages').val() || [];
        var groupedUrlsVal = normalizeGroupedUrlsSelection(jQuery('#finalIssueGroupedUrls').val() || []);
        var reportersVal = jQuery('#finalIssueReporters').val() || [];
        var commonTitleVal = document.getElementById('finalIssueCommonTitle').value;
        var dynamicFields = {};
        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                var el = document.getElementById('finalIssueField_' + f.field_key);
                if (el) dynamicFields[f.field_key] = jQuery(el).val();
            });
        }
        return {
            title: titleVal, details: detailsVal, status: statusVal, qa_status: qaStatusVal,
            pages: pagesVal, grouped_urls: groupedUrlsVal, reporters: reportersVal,
            common_title: commonTitleVal, dynamic_fields: dynamicFields
        };
    }

    function hasFormChanges() {
        if (!issueData.initialFormState) return false;
        return JSON.stringify(captureFormState()) !== JSON.stringify(issueData.initialFormState);
    }

    async function saveDraft() {
        if (!projectId) return;
        var formState = captureFormState();
        var plainText = String(formState.details || '').replace(/<[^>]*>/g, '').trim();
        if (!formState.title && !plainText) return;
        try {
            var fd = new FormData();
            fd.append('action', 'save'); fd.append('project_id', projectId);
            fd.append('issue_params', JSON.stringify(formState));
            await fetch(issueDraftsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
    }

    async function loadDraft() {
        if (!projectId) return null;
        try {
            var res = await fetch(issueDraftsApi + '?action=get&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' });
            var json = await res.json();
            if (json && json.success && json.draft) return { data: json.draft, updated_at: json.updated_at };
        } catch (e) { }
        return null;
    }

    async function deleteDraft() {
        if (!projectId) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId);
            await fetch(issueDraftsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
    }

    function startDraftAutosave() {
        if (issueData.draftTimer) clearInterval(issueData.draftTimer);
        issueData.draftTimer = setInterval(function () { if (hasFormChanges()) saveDraft(); }, 8000);
    }

    function stopDraftAutosave() {
        if (issueData.draftTimer) { clearInterval(issueData.draftTimer); issueData.draftTimer = null; }
    }

    function getReviewDraftStorageKey() {
        return 'pms_review_issue_draft_' + String(projectId || '0') + '_' + String(issueData.selectedPageId || '0');
    }

    function captureReviewFormState() {
        var details = '';
        if (window.jQuery && jQuery.fn.summernote) details = jQuery('#reviewIssueDetails').summernote('code') || '';
        else details = (document.getElementById('reviewIssueDetails') || {}).value || '';
        return {
            title: (document.getElementById('reviewIssueTitle') || {}).value || '',
            instance: (document.getElementById('reviewIssueInstance') || {}).value || '',
            source_urls: (document.getElementById('reviewIssueSourceUrls') || {}).value || '',
            wcag: (document.getElementById('reviewIssueWcag') || {}).value || '',
            severity: (document.getElementById('reviewIssueSeverity') || {}).value || 'medium',
            details: details
        };
    }

    function hasReviewFormChanges() {
        if (!reviewIssueInitialFormState) return false;
        return JSON.stringify(captureReviewFormState()) !== JSON.stringify(reviewIssueInitialFormState);
    }

    function applyReviewFormState(state) {
        if (!state || typeof state !== 'object') return;
        if (document.getElementById('reviewIssueTitle')) document.getElementById('reviewIssueTitle').value = state.title || '';
        if (document.getElementById('reviewIssueInstance')) document.getElementById('reviewIssueInstance').value = state.instance || '';
        if (document.getElementById('reviewIssueSourceUrls')) document.getElementById('reviewIssueSourceUrls').value = state.source_urls || '';
        if (document.getElementById('reviewIssueWcag')) document.getElementById('reviewIssueWcag').value = state.wcag || '';
        if (document.getElementById('reviewIssueSeverity')) document.getElementById('reviewIssueSeverity').value = state.severity || 'medium';
        if (window.jQuery && jQuery.fn.summernote) jQuery('#reviewIssueDetails').summernote('code', state.details || '');
    }

    function loadReviewDraftLocal() {
        try {
            var raw = localStorage.getItem(getReviewDraftStorageKey());
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            return parsed && parsed.form ? parsed : null;
        } catch (e) { return null; }
    }

    function saveReviewDraftLocal() {
        try {
            var form = captureReviewFormState();
            var plainText = String(form.details || '').replace(/<[^>]*>/g, '').trim();
            if (!String(form.title || '').trim() && !plainText) return false;
            localStorage.setItem(getReviewDraftStorageKey(), JSON.stringify({
                updated_at: new Date().toISOString(),
                form: form
            }));
            return true;
        } catch (e) { return false; }
    }

    function clearReviewDraftLocal() {
        try { localStorage.removeItem(getReviewDraftStorageKey()); } catch (e) { }
    }

    function hideEditors() {
        ['finalIssueModal', 'reviewIssueModal', 'commonIssueModal'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            var inst = bootstrap.Modal.getInstance(el);
            if (inst) inst.hide();
        });
    }

    function toggleFinalIssueFields(enable) {
        var form = document.getElementById('finalIssueModal');
        if (!form) return;
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el.type === 'hidden') return;
            if (el.closest('#finalIssueComments')) return;
            el.disabled = !enable;
        });
        if (window.jQuery && jQuery.fn.summernote) {
            jQuery('#finalIssueDetails').summernote(enable ? 'enable' : 'disable');
            jQuery('#finalIssueCommentEditor').summernote('enable');
        }
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.issue-select2, .issue-select2-tags').prop('disabled', !enable);
        }
    }

    function openFinalViewer(issue) {
        if (!issue) return;
        document.getElementById('finalIssueEditId').value = issue.id;
        var expectedUpdatedAtEl = document.getElementById('finalIssueExpectedUpdatedAt');
        if (expectedUpdatedAtEl) expectedUpdatedAtEl.value = issue.updated_at || '';
        startIssuePresenceTracking(issue.id);

        // Inject/update custom title field with issue title
        if (window.injectIssueTitleField) {
            window.injectIssueTitleField(issue.title || '');
        }

        document.getElementById('finalIssueStatus').value = issue.status || 'Open';
        jQuery('#finalIssueQaStatus').val(issue.qa_status || []).trigger('change');
        jQuery('#finalIssuePages').val(issue.pages || [issueData.selectedPageId]).trigger('change');
        jQuery('#finalIssueGroupedUrls').val(issue.grouped_urls || []).trigger('change');
        if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('code', issue.description || '');
        else document.getElementById('finalIssueDetails').value = issue.description || '';
        document.getElementById('finalIssueCommonTitle').value = issue.common_title || '';

        Object.keys(issue).forEach(function (k) {
            if (k.startsWith('meta:')) {
                var fieldKey = k.substring(5);
                var el = document.getElementById('finalIssueField_' + fieldKey);
                if (el) {
                    var val = issue[k];
                    if (val && typeof val === 'string' && val.startsWith('[')) { try { val = JSON.parse(val); } catch (e) { } }
                    jQuery(el).val(val).trigger('change');
                }
            } else if (k === 'reporters') { jQuery('#finalIssueReporters').val(issue.reporters || []).trigger('change'); }
        });

        renderIssueComments(issue.id);
        loadIssueComments(issue.id);

        var modalTitle = document.getElementById('finalIssueModalLabel');
        if (modalTitle) modalTitle.textContent = 'View Issue';
        document.getElementById('finalIssueSaveBtn').classList.add('d-none');

        var footer = document.querySelector('#finalIssueModal .modal-footer');
        var editBtn = document.getElementById('finalIssueEditBtn');
        if (!editBtn && footer) {
            editBtn = document.createElement('button');
            editBtn.type = 'button'; editBtn.id = 'finalIssueEditBtn'; editBtn.className = 'btn btn-primary';
            editBtn.textContent = 'Edit Issue';
            editBtn.addEventListener('click', function () {
                toggleFinalIssueFields(true);
                this.classList.add('d-none');
                document.getElementById('finalIssueSaveBtn').classList.remove('d-none');
                if (modalTitle) modalTitle.textContent = 'Edit Issue';
            });
            var saveBtn = document.getElementById('finalIssueSaveBtn');
            if (saveBtn) footer.insertBefore(editBtn, saveBtn); else footer.appendChild(editBtn);
        }
        if (editBtn) editBtn.classList.remove('d-none');
        toggleFinalIssueFields(false);
        var chatDiv = document.getElementById('finalIssueComments');
        if (chatDiv) {
            chatDiv.querySelectorAll('input, select, textarea, button').forEach(function (el) { el.disabled = false; el.classList.remove('disabled'); });
            if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueCommentEditor').summernote('enable');
        }
        var modal = new bootstrap.Modal(document.getElementById('finalIssueModal'));
        modal.show();
        setTimeout(function () {
            var activeTab = document.querySelector('#finalIssueModal .nav-link.active');
            if (activeTab) activeTab.dispatchEvent(new Event('shown.bs.tab', { bubbles: true }));
        }, 200);
    }

    async function openFinalEditor(issue) {
        var modalEl = document.getElementById('finalIssueModal');
        if (!modalEl) return;

        toggleFinalIssueFields(true);
        document.getElementById('finalEditorTitle').textContent = issue ? 'Edit Final Issue' : 'New Final Issue';
        document.getElementById('finalIssueEditId').value = issue ? issue.id : '';
        var expectedUpdatedAtEl = document.getElementById('finalIssueExpectedUpdatedAt');
        if (expectedUpdatedAtEl) expectedUpdatedAtEl.value = issue && issue.updated_at ? issue.updated_at : '';
        startIssuePresenceTracking(issue && issue.id ? issue.id : null);

        // Ensure save button is visible and edit button is hidden
        var saveBtn = document.getElementById('finalIssueSaveBtn');
        if (saveBtn) saveBtn.classList.remove('d-none');
        var editBtn = document.getElementById('finalIssueEditBtn');
        if (editBtn) editBtn.classList.add('d-none');

        var draftData = null;
        if (!issue) {
            var draft = await loadDraft();
            if (draft && draft.data) {
                draftData = draft.data;
                issueData.isDraftRestored = true;
                if (window.showToast) showToast('Draft restored from ' + new Date(draft.updated_at).toLocaleString(), 'info');
            }
        }

        // Inject title field with value (won't re-inject if exists, just updates value)
        var titleVal = issue ? (issue.title || '') : (draftData ? draftData.title : '');
        if (window.injectIssueTitleField) {
            window.injectIssueTitleField(titleVal);
        }

        // Verify field was created/updated
        setTimeout(function () {
            var titleInput = document.getElementById('customIssueTitle');
            var applyBtn = document.getElementById('applyPresetBtn');
        }, 100);

        var detailsVal = issue ? (issue.details || '') : (draftData ? draftData.details : '');
        jQuery('#finalIssueDetails').summernote('code', detailsVal);

        // Note: Issue status options are already populated by PHP in the modal HTML
        // We only need to set the selected value

        // Set the selected value - convert status name to ID if needed
        var statusValue = '1'; // Default to Open
        if (issue && issue.status_id) {
            // Ensure it's a string for proper comparison with option values
            statusValue = String(issue.status_id);
        } else if (issue && issue.status && ProjectConfig.issueStatuses) {
            // Try to find the ID by name or label
            var statusOption = ProjectConfig.issueStatuses.find(function (s) {
                var label = s.status_label || s.name || '';
                return label && issue.status && label.toLowerCase() === issue.status.toLowerCase();
            });
            if (statusOption) statusValue = String(statusOption.id);
        } else if (draftData && draftData.status && ProjectConfig.issueStatuses) {
            var statusOption = ProjectConfig.issueStatuses.find(function (s) {
                var label = s.status_label || s.name || '';
                return label && draftData.status && label.toLowerCase() === draftData.status.toLowerCase();
            });
            if (statusOption) statusValue = String(statusOption.id);
        }
        document.getElementById('finalIssueStatus').value = statusValue;

        // Store values to set after modal is shown
        var qaStatusValue = issue ? (issue.qa_status || []) : (draftData ? draftData.qa_status : []);
        var reportersValue = issue ? (issue.reporters || []) : (draftData ? draftData.reporters : []);
        var pageIds = (issue && issue.pages) ? issue.pages : ((draftData && draftData.pages) ? draftData.pages : [issueData.selectedPageId]);

        // Set pages immediately (this usually works)
        jQuery('#finalIssuePages').val(pageIds).trigger('change');

        // Wait for modal to be fully shown before setting Select2 values
        var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);

        // Remove any existing event listeners to avoid duplicates
        modalEl.removeEventListener('shown.bs.modal', modalEl._select2SetterHandler);

        // Create new handler
        modalEl._select2SetterHandler = function () {
            // Set QA Status
            setTimeout(function () {
                jQuery('#finalIssueQaStatus').val(qaStatusValue).trigger('change');
            }, 100);

            // Set Reporters
            setTimeout(function () {
                jQuery('#finalIssueReporters').val(reportersValue).trigger('change');
            }, 100);
        };

        // Attach the handler
        modalEl.addEventListener('shown.bs.modal', modalEl._select2SetterHandler, { once: true });

        // Populate metadata fields with a slight delay to ensure Select2 is initialized
        setTimeout(function () {
            if (typeof issueMetadataFields !== 'undefined') {
                issueMetadataFields.forEach(function (f) {
                    var elId = 'finalIssueField_' + f.field_key;
                    var val = null;

                    // Get value from issue data
                    if (issue && issue[f.field_key] !== undefined) {
                        val = issue[f.field_key];
                    } else if (draftData && draftData.dynamic_fields && draftData.dynamic_fields[f.field_key] !== undefined) {
                        val = draftData.dynamic_fields[f.field_key];
                    } else if (!issue && (f.field_key === 'severity' || f.field_key === 'priority')) {
                        val = 'medium';
                    }

                    var $el = jQuery('#' + elId);
                    if ($el.length) {
                        // For select2 multi-select, ensure value is an array
                        if ($el.prop('multiple') && val && !Array.isArray(val)) {
                            val = [val];
                        }
                        // For single select, ensure value is a string
                        if (!$el.prop('multiple') && Array.isArray(val)) {
                            val = val[0] || null;
                        }
                        $el.val(val).trigger('change');
                    }
                });
            }
        }, 100);

        var commonTitleVal = issue ? (issue.common_title || '') : (draftData ? draftData.common_title : '');
        document.getElementById('finalIssueCommonTitle').value = commonTitleVal;
        if (issue && issue.grouped_urls) setGroupedUrls(issue.grouped_urls);
        else if (draftData && draftData.grouped_urls) setGroupedUrls(draftData.grouped_urls);
        else updateGroupedUrls();
        toggleCommonTitle();
        if (!issue) ensureDefaultSections();
        renderIssueComments(issue ? String(issue.id) : 'new');
        if (issue && issue.id) loadIssueComments(String(issue.id));

        setTimeout(function () {
            issueData.initialFormState = captureFormState();
            if (!issue) startDraftAutosave();
        }, 500);

        var modal = new bootstrap.Modal(modalEl);
        modal.show();
        // Removed the condition - always ensure metadata fields are properly initialized
        setTimeout(function () { var at = modalEl.querySelector('.nav-link.active'); if (at) at.dispatchEvent(new Event('shown.bs.tab', { bubbles: true })); }, 200);
    }

    function openReviewEditor(issue) {
        if (!canEdit()) return;
        var modalEl = document.getElementById('reviewIssueModal');
        if (!modalEl) return;
        document.getElementById('reviewEditorTitle').textContent = issue ? 'Edit Review Issue' : 'New Review Issue';
        document.getElementById('reviewIssueEditId').value = issue ? issue.id : '';
        var ruleInput = document.getElementById('reviewIssueRuleId');
        var impactInput = document.getElementById('reviewIssueImpact');
        var sourceInput = document.getElementById('reviewIssuePrimarySourceUrl');
        if (ruleInput) ruleInput.value = issue ? (issue.rule_id || '') : '';
        if (impactInput) impactInput.value = issue ? (issue.impact || '') : '';
        if (sourceInput) sourceInput.value = issue ? ((issue.source_url || (Array.isArray(issue.source_urls) && issue.source_urls[0]) || '')) : '';
        document.getElementById('reviewIssueTitle').value = issue ? issue.title : '';
        document.getElementById('reviewIssueInstance').value = issue ? issue.instance : '';
        var urlsEl = document.getElementById('reviewIssueSourceUrls');
        if (urlsEl) {
            var urlsText = '';
            if (issue && Array.isArray(issue.source_urls) && issue.source_urls.length) {
                urlsText = issue.source_urls.map(function (u, idx) { return (idx + 1) + '. ' + u; }).join('\n');
            } else if (issue && issue.source_url) {
                urlsText = issue.source_url;
            }
            urlsEl.value = urlsText;
        }
        document.getElementById('reviewIssueWcag').value = issue ? issue.wcag : '';
        document.getElementById('reviewIssueSeverity').value = issue ? (issue.severity || 'medium') : 'medium';

        var detailsForEditor = issue ? normalizeReviewDetailsForEditor(issue.details || '') : '';
        jQuery('#reviewIssueDetails').summernote('code', detailsForEditor);
        if (!issue) {
            var savedDraft = loadReviewDraftLocal();
            if (savedDraft && savedDraft.form) {
                applyReviewFormState(savedDraft.form);
                if (window.showToast && savedDraft.updated_at) {
                    showToast('Review draft restored from ' + new Date(savedDraft.updated_at).toLocaleString(), 'info');
                }
            }
        }
        var moveBtn = document.getElementById('reviewIssueMoveToFinalBtn');
        if (moveBtn) {
            if (issue && issue.id) {
                moveBtn.classList.remove('d-none');
                var moveIds = (issue && Array.isArray(issue.ids) && issue.ids.length) ? issue.ids : [issue.id];
                moveBtn.setAttribute('data-ids', moveIds.join(','));
            } else {
                moveBtn.classList.add('d-none');
                moveBtn.removeAttribute('data-ids');
            }
        }
        reviewIssueInitialFormState = captureReviewFormState();
        reviewIssueBypassCloseConfirm = false;
        new bootstrap.Modal(modalEl).show();
    }

    function openCommonEditor(issue) {
        var modalEl = document.getElementById('commonIssueModal');
        if (!modalEl) return;
        document.getElementById('commonEditorTitle').textContent = issue ? 'Edit Common Issue' : 'New Common Issue';
        document.getElementById('commonIssueEditId').value = issue ? issue.id : '';
        document.getElementById('commonIssueTitle').value = issue ? issue.title : '';
        jQuery('#commonIssuePages').val(issue ? issue.pages : []).trigger('change');
        jQuery('#commonIssueDetails').summernote('code', issue ? issue.details : '');
        new bootstrap.Modal(modalEl).show();
    }

    function toggleCommonTitle() {
        var sel = jQuery('#finalIssuePages').val() || [];
        var wrap = document.getElementById('finalIssueCommonTitleWrap');
        if (!wrap) return;
        if (sel.length > 1) wrap.classList.remove('d-none'); else wrap.classList.add('d-none');
    }

    function groupedUrlsByPages(pageIds) {
        var urls = [];
        function addUrl(val) {
            if (!val) return;
            var s = String(val).trim();
            if (!s) return;
            if (urls.indexOf(s) === -1) urls.push(s);
        }
        function getUniqueUrlForPage(pageId) {
            var page = pages.find(function (p) { return String(p.id) === String(pageId); }) || null;
            var pageName = page && page.page_name ? String(page.page_name).trim().toLowerCase() : '';
            var pageNumber = page && page.page_number ? String(page.page_number).trim().toLowerCase() : '';
            var pageUrl = page && page.url ? String(page.url).trim().toLowerCase() : '';

            var row = (uniqueIssuePages || []).find(function (u) {
                var mapped = String(u.mapped_page_id || '') === String(pageId);
                var uidMatch = String(u.unique_id || '') === String(pageId);
                var nameMatch = pageName && String(u.unique_name || '').trim().toLowerCase() === pageName;
                var numberMatch = pageNumber && String(u.unique_name || '').trim().toLowerCase() === pageNumber;
                var urlMatch = pageUrl && String(u.canonical_url || '').trim().toLowerCase() === pageUrl;
                return mapped || uidMatch || nameMatch || numberMatch || urlMatch;
            });
            if (row) {
                return row.canonical_url || row.unique_url || row.url || '';
            }
            return '';
        }

        // For each selected page
        pageIds.forEach(function (pageId) {
            // First, add all grouped URLs for this page
            var hasGroupedUrls = false;
            groupedUrls.forEach(function (row) {
                var rowMappedPageId = row.mapped_page_id;
                var rowUniquePageId = row.unique_page_id;
                if (String(rowMappedPageId) === String(pageId) || String(rowUniquePageId) === String(pageId)) {
                    hasGroupedUrls = true;
                    addUrl(row.url || row.normalized_url);
                }
            });

            // If no grouped URLs found, add the page's primary URL
            if (!hasGroupedUrls) {
                // Prefer URL from Unique URLs mapping when available.
                addUrl(getUniqueUrlForPage(pageId));

                var page = pages.find(function (p) {
                    return String(p.id) === String(pageId);
                });

                if (page) {
                    addUrl(page.url || page.canonical_url || page.unique_url || page.normalized_url || page.page_url);
                }

                // Extra fallback from grouped URLs dataset if canonical is available
                if (!page || !(page.url || page.canonical_url || page.unique_url || page.normalized_url || page.page_url)) {
                    var rowWithCanonical = groupedUrls.find(function (row) {
                        return String(row.mapped_page_id) === String(pageId) && (row.canonical_url || row.unique_name);
                    });
                    if (rowWithCanonical) {
                        addUrl(rowWithCanonical.canonical_url || rowWithCanonical.unique_name);
                    }
                }
            }
        });

        return urls;
    }

    function getAllGroupedUrlOptions() {
        var all = [];
        function addUrl(val) {
            if (!val) return;
            var s = String(val).trim();
            if (!s) return;
            if (all.indexOf(s) === -1) all.push(s);
        }

        (groupedUrls || []).forEach(function (row) {
            addUrl(row.url || row.normalized_url);
        });

        // Keep useful fallbacks visible too.
        (uniqueIssuePages || []).forEach(function (row) {
            addUrl(row.canonical_url || row.url || row.unique_url);
        });

        return all;
    }

    function updateGroupedUrls() {
        var pageIds = jQuery('#finalIssuePages').val() || [];
        var urls = groupedUrlsByPages(pageIds);
        setGroupedUrls(urls);
    }

    function setGroupedUrls(values) {
        var $sel = jQuery('#finalIssueGroupedUrls');
        var current = $sel.val() || [];
        function appendOption(val, label) {
            var safeVal = String(val || '').trim();
            if (!safeVal) return;
            if ($sel.find('option').filter(function () { return this.value === safeVal; }).length) return;
            $sel.append('<option value="' + safeVal.replace(/"/g, '&quot;') + '">' + (label || safeVal) + '</option>');
        }
        var uniqueValues = [];
        (values || []).forEach(function (u) {
            var s = String(u || '').trim();
            if (!s) return;
            if (uniqueValues.indexOf(s) === -1) uniqueValues.push(s);
        });

        var allOptions = getAllGroupedUrlOptions();

        $sel.empty();
        allOptions.forEach(function (u) { appendOption(u, u); });
        // Ensure selected values (including custom typed URLs) remain present.
        uniqueValues.forEach(function (u) { appendOption(u, u); });
        current.forEach(function (u) {
            var s = String(u || '').trim();
            if (!s) return;
            appendOption(s, s);
        });
        $sel.val(uniqueValues).trigger('change');
        updateUrlSelectionSummary();
        updateGroupedUrlsPreview();
    }

    function normalizeGroupedUrlsSelection(rawValues) {
        return (Array.isArray(rawValues) ? rawValues : []).filter(function (v) {
            return String(v || '').trim() !== '';
        });
    }

    function updateUrlSelectionSummary() {
        var summary = document.getElementById('urlSelectionSummary');
        if (!summary) return;
        var pagesCount = (jQuery('#finalIssuePages').val() || []).length;
        var urlsCount = (jQuery('#finalIssueGroupedUrls').val() || []).length;
        summary.textContent = 'Pages: ' + pagesCount + ' | Grouped URLs: ' + urlsCount + ' selected';
    }

    function updateGroupedUrlsPreview() {
        var countEl = document.getElementById('groupedUrlsPreviewCount');
        var listEl = document.getElementById('finalIssueGroupedUrlsPreviewList');
        if (!countEl || !listEl) return;
        var urls = normalizeGroupedUrlsSelection(jQuery('#finalIssueGroupedUrls').val() || []);
        countEl.textContent = String(urls.length);
        if (!urls.length) {
            listEl.innerHTML = '<li class="text-muted">No grouped URLs selected.</li>';
            return;
        }
        listEl.innerHTML = urls.map(function (u) {
            return '<li class="text-break"><a href="' + encodeURI(u) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(u) + '</a></li>';
        }).join('');
    }

    function syncUrlModalFromMain() {
        if (isSyncingUrlModal) return;
        isSyncingUrlModal = true;
        try {
            var $mainPages = jQuery('#finalIssuePages');
            var $mainUrls = jQuery('#finalIssueGroupedUrls');
            var $modalPages = jQuery('#urlModalPages');
            var $modalUrls = jQuery('#urlModalGroupedUrls');

            if (!$modalPages.length || !$modalUrls.length || !$mainPages.length || !$mainUrls.length) return;

            $modalPages.empty();
            $mainPages.find('option').each(function () {
                $modalPages.append('<option value="' + String(this.value).replace(/"/g, '&quot;') + '">' + this.text + '</option>');
            });
            $modalPages.val($mainPages.val() || []).trigger('change');

            $modalUrls.empty();
            $mainUrls.find('option').each(function () {
                $modalUrls.append('<option value="' + String(this.value).replace(/"/g, '&quot;') + '">' + this.text + '</option>');
            });
            var selectedUrls = $mainUrls.val() || [];
            selectedUrls.forEach(function (u) {
                if ($modalUrls.find('option').filter(function () { return this.value === String(u); }).length === 0) {
                    $modalUrls.append('<option value="' + String(u).replace(/"/g, '&quot;') + '">' + String(u) + '</option>');
                }
            });
            $modalUrls.val(selectedUrls).trigger('change');
        } finally {
            isSyncingUrlModal = false;
        }
    }

    function applyUrlModalToMain() {
        var modalPages = jQuery('#urlModalPages').val() || [];
        var modalUrls = normalizeGroupedUrlsSelection(jQuery('#urlModalGroupedUrls').val() || []);
        var $mainPages = jQuery('#finalIssuePages');
        var $mainUrls = jQuery('#finalIssueGroupedUrls');

        $mainPages.val(modalPages).trigger('change');

        modalUrls.forEach(function (u) {
            if ($mainUrls.find('option').filter(function () { return this.value === String(u); }).length === 0) {
                $mainUrls.append('<option value="' + String(u).replace(/"/g, '&quot;') + '">' + String(u) + '</option>');
            }
        });
        $mainUrls.val(modalUrls).trigger('change');
        updateUrlSelectionSummary();
        updateGroupedUrlsPreview();
    }

    function initUrlSelectionModal() {
        var $openBtn = jQuery('#btnOpenUrlSelectionModal');
        var $modal = jQuery('#urlSelectionModal');
        var $modalPages = jQuery('#urlModalPages');
        var $modalUrls = jQuery('#urlModalGroupedUrls');
        if (!$openBtn.length || !$modal.length || !$modalPages.length || !$modalUrls.length) return;

        if (window.jQuery && jQuery.fn.select2) {
            try { if ($modalPages.data('select2')) $modalPages.select2('destroy'); } catch (e) { }
            try { if ($modalUrls.data('select2')) $modalUrls.select2('destroy'); } catch (e) { }
            $modalPages.select2({ width: '100%', closeOnSelect: false, dropdownParent: $modal });
            $modalUrls.select2({ width: '100%', tags: true, tokenSeparators: [','], closeOnSelect: false, dropdownParent: $modal });
        }

        $openBtn.off('click.urlModal').on('click.urlModal', function () {
            syncUrlModalFromMain();
            var instance = bootstrap.Modal.getInstance($modal[0]) || new bootstrap.Modal($modal[0]);
            instance.show();
        });

        $modalPages.off('change.urlModalPages').on('change.urlModalPages', function () {
            if (isSyncingUrlModal) return;
            var selectedPages = $modalPages.val() || [];
            jQuery('#finalIssuePages').val(selectedPages).trigger('change');
            syncUrlModalFromMain();
        });

        jQuery('#btnApplyUrlSelection').off('click.urlModalApply').on('click.urlModalApply', function () {
            applyUrlModalToMain();
        });

        jQuery('#btnCopyGroupedUrls').off('click.urlModalCopy').on('click.urlModalCopy', function () {
            var selected = normalizeGroupedUrlsSelection($modalUrls.val() || []);
            var text = selected.join('\n');
            if (!text) {
                if (window.showToast) showToast('No URLs selected to copy', 'info');
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    if (window.showToast) showToast('Grouped URLs copied', 'success');
                }).catch(function () {
                    if (window.showToast) showToast('Copy failed', 'danger');
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); if (window.showToast) showToast('Grouped URLs copied', 'success'); } catch (e) { if (window.showToast) showToast('Copy failed', 'danger'); }
                document.body.removeChild(ta);
            }
        });
    }

    function clearIssueMetadataForTemplateReset() {
        // Clear metadata fields (dynamic + key meta controls) before applying template content.
        var container = document.getElementById('finalIssueMetadataContainer');
        if (container) {
            container.querySelectorAll('[id^="finalIssueField_"]').forEach(function (el) {
                var id = el.id || '';
                if (id === 'finalIssueField_severity' || id === 'finalIssueField_priority') {
                    jQuery(el).val(['medium']).trigger('change');
                    return;
                }
                if (el.multiple) jQuery(el).val([]).trigger('change');
                else jQuery(el).val('').trigger('change');
            });
        }

        jQuery('#finalIssueQaStatus').val([]).trigger('change');
        jQuery('#finalIssueReporters').val([]).trigger('change');
        document.getElementById('finalIssueCommonTitle').value = '';

        // Recalculate grouped URLs from selected page(s), with fallback to page URL if no grouped URL exists.
        updateGroupedUrls();
    }

    function renderFinalIssues() {
        var tbody = document.getElementById('finalIssuesBody');
        if (!tbody) return;
        // Preserve expanded rows across live refresh/re-render.
        var expandedIssueIds = [];
        document.querySelectorAll('#finalIssuesBody tr.collapse.show[id^="issue-details-"]').forEach(function (row) {
            var id = String(row.id || '');
            var issueId = id.replace('issue-details-', '');
            if (issueId) expandedIssueIds.push(issueId);
        });
        if (!issueData.selectedPageId) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5"><div class="text-muted mb-2"><i class="fas fa-arrow-left fa-2x opacity-25"></i></div><div class="text-muted fw-medium">Select a page from the list to view issues.</div></td></tr>';
            updateIssueTabCounts();
            return;
        }
        var issues = issueData.pages[issueData.selectedPageId].final || [];
        if (!issues.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5"><div class="text-muted mb-2"><i class="fas fa-check-circle fa-2x opacity-25"></i></div><div class="text-muted fw-medium">No final issues recorded yet.</div></td></tr>';
            updateIssueTabCounts();
            return;
        }

        // Helper functions for badges
        var getSeverityBadge = function (s) {
            if (!s || s === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            s = String(s).toLowerCase();
            var colors = {
                'critical': 'danger',
                'high': 'warning',
                'medium': 'info',
                'low': 'success',
                'major': 'warning',
                'minor': 'info'
            };
            var color = colors[s] || 'secondary';
            return '<span class="badge bg-' + color + '">' + escapeHtml(s.toUpperCase()) + '</span>';
        };

        var getPriorityBadge = function (p) {
            if (!p || p === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            p = String(p).toLowerCase();
            var colors = {
                'urgent': 'danger',
                'critical': 'danger',
                'high': 'warning',
                'medium': 'info',
                'low': 'success'
            };
            var color = colors[p] || 'secondary';
            return '<span class="badge bg-' + color + '">' + escapeHtml(p.toUpperCase()) + '</span>';
        };

        var getStatusBadge = function (statusId) {
            if (!statusId) return '<span class="badge bg-secondary">N/A</span>';
            // statusId can be either numeric ID or status key string
            if (ProjectConfig.issueStatuses) {
                var found = ProjectConfig.issueStatuses.find(function (s) {
                    // Try matching by ID first (numeric comparison)
                    if (s.id == statusId) return true;
                    // Fallback to matching by name (case-insensitive)
                    if (s.name && String(s.name).toLowerCase() === String(statusId).toLowerCase()) return true;
                    return false;
                });
                if (found) {
                    var color = found.color || '#6c757d';
                    var name = found.name || 'Unknown';
                    // If color is a hex code, use inline style; otherwise use Bootstrap class
                    if (color.startsWith('#')) {
                        return '<span class="badge" style="background-color: ' + color + '; color: white;">' + escapeHtml(name) + '</span>';
                    } else {
                        return '<span class="badge bg-' + color + '">' + escapeHtml(name) + '</span>';
                    }
                }
            }
            return '<span class="badge bg-secondary">' + escapeHtml(String(statusId)) + '</span>';
        };

        var getQaBadge = function (q) {
            if (!q || q === 'pending' || q === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
            q = String(q).toLowerCase();
            if (q === 'pass' || q === 'passed') return '<span class="badge bg-success">PASS</span>';
            if (q === 'fail' || q === 'failed') return '<span class="badge bg-danger">FAIL</span>';
            return '<span class="badge bg-warning">' + escapeHtml(q.toUpperCase()) + '</span>';
        };

        var stripHtml = function (html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            return tmp.textContent || tmp.innerText || '';
        };

        tbody.innerHTML = issues.map(function (issue) {
            // Extract values and handle arrays OR stringified arrays
            var severity = issue.severity || 'N/A';

            // Check if it's a stringified array like '["Low"]'
            if (typeof severity === 'string' && severity.startsWith('[')) {
                try {
                    var parsed = JSON.parse(severity);
                    if (Array.isArray(parsed)) {
                        severity = parsed[0] || 'N/A';
                    }
                } catch (e) { }
            } else if (Array.isArray(severity)) {
                severity = severity[0] || 'N/A';
            }

            var priority = issue.priority || 'N/A';

            // Check if it's a stringified array like '["Low"]'
            if (typeof priority === 'string' && priority.startsWith('[')) {
                try {
                    var parsed = JSON.parse(priority);
                    if (Array.isArray(parsed)) {
                        priority = parsed[0] || 'N/A';
                    }
                } catch (e) { }
            } else if (Array.isArray(priority)) {
                priority = priority[0] || 'N/A';
            }

            var status = issue.status || 'open';
            var statusId = issue.status_id || null;
            // QA Status is now an array - display as badges with proper labels and colors
            var qaStatusArray = Array.isArray(issue.qa_status) ? issue.qa_status : (issue.qa_status ? [issue.qa_status] : []);
            var qaStatusHtml = '';
            if (qaStatusArray.length > 0) {
                qaStatusHtml = qaStatusArray.map(function (qs) {
                    // Get label from qaStatuses mapping or format the key
                    var label = qs;
                    var badgeColor = 'secondary';
                    if (ProjectConfig.qaStatuses) {
                        var found = ProjectConfig.qaStatuses.find(function (s) {
                            return s.status_key === qs;
                        });
                        if (found) {
                            label = found.status_label;
                            badgeColor = found.badge_color || 'secondary';
                        } else {
                            // Format key: TYPO_GRAMMAR → Typo Grammar
                            label = qs.split('_').map(function (word) {
                                return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
                            }).join(' ');
                        }
                    }

                    // Convert Bootstrap color names to hex - same as issues_all.php
                    var getBootstrapColor = function (colorName) {
                        var colorMap = {
                            'primary': '#0d6efd',
                            'secondary': '#6c757d',
                            'success': '#198754',
                            'danger': '#dc3545',
                            'warning': '#ffc107',
                            'info': '#0dcaf0',
                            'light': '#f8f9fa',
                            'dark': '#212529'
                        };
                        if (colorName && colorName.startsWith('#')) {
                            return colorName;
                        }
                        return colorMap[colorName] || colorMap['secondary'];
                    };

                    // Calculate contrast color - same as issues_all.php
                    var getContrastColor = function (hexColor) {
                        var hex = hexColor.replace('#', '');
                        var r = parseInt(hex.substr(0, 2), 16);
                        var g = parseInt(hex.substr(2, 2), 16);
                        var b = parseInt(hex.substr(4, 2), 16);
                        var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
                        return luminance > 0.5 ? '#000000' : '#ffffff';
                    };

                    var bgColor = getBootstrapColor(badgeColor);
                    var textColor = getContrastColor(bgColor);

                    // Use qa-status-badge class to match issues_all.php
                    return '<span class="qa-status-badge" style="background-color: ' + bgColor + ' !important; color: ' + textColor + ' !important;">' + escapeHtml(label) + '</span>';
                }).join(' ');
            } else {
                qaStatusHtml = '<span class="text-muted">N/A</span>';
            }

            // Handle multiple reporters
            var reportersArray = Array.isArray(issue.reporters) && issue.reporters.length > 0
                ? issue.reporters
                : (issue.reporter_name ? [issue.reporter_name] : []);

            var reporterHtml = '';
            if (reportersArray.length > 0) {
                reporterHtml = reportersArray.map(function (reporterId) {
                    // Get reporter name from project users
                    var reporterName = 'Unknown';
                    if (ProjectConfig.projectUsers) {
                        var found = ProjectConfig.projectUsers.find(function (u) {
                            return u.id == reporterId;
                        });
                        if (found) {
                            reporterName = found.full_name;
                        }
                    }
                    return '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>';
                }).join('');
            } else {
                reporterHtml = '<span class="text-muted">N/A</span>';
            }

            var qaName = issue.qa_name || 'N/A';
            var issueKey = issue.issue_key || 'N/A';
            var pageCount = (issue.pages && issue.pages.length) || 1;
            var titlePreview = stripHtml(issue.details).substring(0, 100);
            if (titlePreview && stripHtml(issue.details).length > 100) titlePreview += '...';
            var uniqueId = 'issue-details-' + issue.id;

            // Main row - NOT directly clickable, only chevron button is
            var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '">' +
                '<td class="checkbox-cell"><input type="checkbox" class="final-select" value="' + issue.id + '"></td>' +
                '<td><span class="badge bg-primary">' + escapeHtml(issueKey) + '</span></td>' +
                '<td style="min-width: 250px; max-width: 400px;">' +
                '<div class="d-flex align-items-center">' +
                '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" ' +
                'data-collapse-target="#' + uniqueId + '" ' +
                'aria-label="Expand details for ' + escapeHtml(issueKey) + ': ' + escapeHtml(issue.title) + '" ' +
                'style="border: none; background: none; font-size: 1rem;">' +
                '<i class="fas fa-chevron-right chevron-icon"></i>' +
                '</button>' +
                '<div style="cursor: pointer;" class="issue-title-click" data-issue-id="' + issue.id + '">' +
                (issue.common_title ?
                    '<div class="fw-bold">' + escapeHtml(issue.common_title) + '</div>' +
                    '<div class="small text-muted">' + escapeHtml(issue.title) + '</div>'
                    :
                    '<div class="fw-bold">' + escapeHtml(issue.title) + '</div>' +
                    (titlePreview ? '<div class="small text-muted">' + escapeHtml(titlePreview) + '</div>' : '')
                ) +
                '</div>' +
                '</div>' +
                '</td>' +
                '<td>' + getSeverityBadge(severity) + '</td>' +
                '<td>' + getPriorityBadge(priority) + '</td>' +
                '<td>' + getStatusBadge(statusId) + '</td>' +
                '<td>' + qaStatusHtml + '</td>' +
                '<td>' + reporterHtml + '</td>' +
                '<td>' +
                (qaName !== 'N/A' ?
                    '<span class="badge bg-success">' + escapeHtml(qaName) + '</span>' :
                    '<span class="text-muted">N/A</span>') +
                '</td>' +
                '<td>' +
                '<span class="badge bg-secondary">' + pageCount + ' page(s)</span>' +
                '</td>' +
                '<td class="action-buttons-cell">' +
                '<button class="btn btn-sm btn-outline-primary me-1 final-edit" data-id="' + issue.id + '" type="button" title="Edit Issue">' +
                '<i class="fas fa-edit"></i>' +
                '</button>' +
                '<button class="btn btn-sm btn-outline-danger final-delete" data-id="' + issue.id + '" type="button" title="Delete Issue">' +
                '<i class="fas fa-trash"></i>' +
                '</button>' +
                '</td>' +
                '</tr>';

            // Expandable details row
            var detailsRow = '<tr class="collapse" id="' + uniqueId + '">' +
                '<td colspan="11" class="p-0 border-0">' +
                '<div class="bg-light p-4 border-top">' +
                '<div class="row g-3">' +
                '<div class="col-md-8">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                (issue.details || '<p class="text-muted">No details provided.</p>') +
                '</div>' +
                '</div>' +
                '</div>' +
                '<div class="col-md-4">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                '<div class="mb-2"><strong>Issue Key:</strong><br>' +
                '<span class="badge bg-primary">' + escapeHtml(issueKey) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Status:</strong><br>' + getStatusBadge(statusId) + '</div>' +
                '<div class="mb-2"><strong>QA Status:</strong><br>' + qaStatusHtml + '</div>' +
                '<div class="mb-2"><strong>Severity:</strong><br>' +
                '<span class="badge bg-warning text-dark">' + escapeHtml((severity || 'N/A').toUpperCase()) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Priority:</strong><br>' +
                '<span class="badge bg-info text-dark">' + escapeHtml((priority || 'N/A').toUpperCase()) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Reporter(s):</strong><br>' +
                (reportersArray.length > 0 ? reportersArray.map(function (reporterId) {
                    var reporterName = 'Unknown';
                    if (ProjectConfig.projectUsers) {
                        var found = ProjectConfig.projectUsers.find(function (u) { return u.id == reporterId; });
                        if (found) reporterName = found.full_name;
                    }
                    return escapeHtml(reporterName);
                }).join(', ') : (issue.reporter_name ? escapeHtml(issue.reporter_name) : '<span class="text-muted">N/A</span>')) +
                '</div>' +
                '<div class="mb-2"><strong>QA Name:</strong><br>' + escapeHtml(qaName) + '</div>' +
                (function () {
                    // Pages section with names
                    var pagesHtml = '<div class="mb-2"><strong>Pages:</strong> ';
                    if (issue.pages && issue.pages.length > 0) {
                        var pageNames = issue.pages.map(function (pageId) {
                            return getPageName(pageId);
                        });
                        pagesHtml += '<span class="badge bg-secondary">' + pageNames.length + '</span><br>';
                        pagesHtml += '<small class="text-muted">' + pageNames.join(', ') + '</small>';
                    } else {
                        pagesHtml += '<span class="text-muted">N/A</span>';
                    }
                    pagesHtml += '</div>';

                    // Grouped URLs section with expand/collapse
                    var urlsHtml = '';
                    if (issue.grouped_urls && issue.grouped_urls.length > 0) {
                        var urlsId = 'urls-' + issue.id;
                        urlsHtml += '<div class="mb-2">';
                        urlsHtml += '<strong>Grouped URLs:</strong> ';
                        urlsHtml += '<span class="badge bg-info">' + issue.grouped_urls.length + '</span> ';
                        urlsHtml += '<button class="btn btn-xs btn-link p-0 grouped-urls-toggle" data-bs-toggle="collapse" data-bs-target="#' + urlsId + '" aria-expanded="false">';
                        urlsHtml += '<i class="fas fa-chevron-down transition-transform"></i>';
                        urlsHtml += '</button>';
                        urlsHtml += '<div class="mt-2" id="' + urlsId + '" style="display: none;">';
                        urlsHtml += '<div class="small p-2 border rounded bg-light" style="max-height: 150px; overflow-y: auto;">';

                        var urlsFound = 0;
                        issue.grouped_urls.forEach(function (urlString) {
                            // The issue stores actual URL strings, not IDs
                            // Find matching URL data from ProjectConfig.groupedUrls
                            var urlData = (ProjectConfig.groupedUrls || []).find(function (u) {
                                return u.url === urlString || u.normalized_url === urlString;
                            });

                            // If not found in groupedUrls, just display the URL string directly
                            var displayUrl = urlData ? urlData.url : urlString;

                            if (displayUrl) {
                                urlsFound++;
                                urlsHtml += '<div class="mb-1">';
                                urlsHtml += '<a href="' + escapeHtml(displayUrl) + '" target="_blank" class="text-decoration-none">';
                                urlsHtml += '<i class="fas fa-external-link-alt me-1 text-primary"></i>';
                                urlsHtml += '<span class="text-primary">' + escapeHtml(displayUrl) + '</span>';
                                urlsHtml += '</a>';
                                urlsHtml += '</div>';
                            }
                        });

                        // If no URLs found, show message
                        if (urlsFound === 0) {
                            urlsHtml += '<div class="text-muted">No URL data available</div>';
                        }

                        urlsHtml += '</div></div></div>';
                    }

                    return pagesHtml + urlsHtml;
                })() +
                (function () {
                    var metaHtml = '';
                    if (typeof issueMetadataFields !== 'undefined') {
                        issueMetadataFields.forEach(function (f) {
                            // Skip severity and priority as they're already shown above
                            if (f.field_key === 'severity' || f.field_key === 'priority') return;

                            var value = issue[f.field_key];
                            if (value && value.length > 0) {
                                var displayValue = Array.isArray(value) ? value.join(', ') : value;
                                metaHtml += '<div class="mb-2"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                            }
                        });
                    }
                    // Add created_at and updated_at timestamps
                    if (issue.created_at) {
                        metaHtml += '<div class="mb-2"><strong>Created:</strong><br><small class="text-muted">' + new Date(issue.created_at).toLocaleString() + '</small></div>';
                    }
                    if (issue.updated_at) {
                        metaHtml += '<div class="mb-2"><strong>Updated:</strong><br><small class="text-muted">' + new Date(issue.updated_at).toLocaleString() + '</small></div>';
                    }
                    return metaHtml;
                })() +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</td>' +
                '</tr>';

            return mainRow + detailsRow;
        }).join('');

        // Add click handlers for chevron toggle buttons
        document.querySelectorAll('#finalIssuesBody .chevron-toggle-btn').forEach(function (btn) {
            // Click handler for chevron button
            btn.addEventListener('click', function (e) {
                e.stopPropagation(); // Prevent event bubbling
                toggleIssueRow(this);
            });

            // Keyboard handler (Enter or Space)
            btn.addEventListener('keydown', function (e) {
                // Only handle Enter (13) or Space (32)
                if (e.keyCode === 13 || e.keyCode === 32) {
                    e.preventDefault(); // Prevent page scroll on Space
                    e.stopPropagation();
                    toggleIssueRow(this);
                }
            });
        });

        // Add click handler for issue title to open edit modal
        document.querySelectorAll('#finalIssuesBody .issue-title-click').forEach(function (titleEl) {
            titleEl.addEventListener('click', function (e) {
                e.stopPropagation();
                var issueId = this.getAttribute('data-issue-id');
                if (issueId && issueData.selectedPageId) {
                    var issue = issueData.pages[issueData.selectedPageId].final.find(function (i) {
                        return String(i.id) === String(issueId);
                    });
                    if (issue) openFinalEditor(issue);
                }
            });
        });

        // Add click handler for entire row (for mouse users)
        document.querySelectorAll('#finalIssuesBody .issue-expandable-row').forEach(function (row) {
            row.style.cursor = 'pointer';

            row.addEventListener('click', function (e) {
                // Don't expand if clicking on buttons, checkbox, inputs, or action buttons
                if (e.target.closest('button') ||
                    e.target.closest('input') ||
                    e.target.closest('select') ||
                    e.target.closest('.action-buttons-cell') ||
                    e.target.closest('.checkbox-cell') ||
                    e.target.closest('.issue-title-click')) {
                    return;
                }

                // Find the chevron button in this row and trigger it
                var chevronBtn = this.querySelector('.chevron-toggle-btn');
                if (chevronBtn) {
                    toggleIssueRow(chevronBtn);
                }
            });
        });

        // Helper function to toggle issue row expansion
        function toggleIssueRow(btn) {
            var targetId = btn.getAttribute('data-collapse-target');
            if (targetId) {
                var collapseEl = document.querySelector(targetId);
                var chevronIcon = btn.querySelector('.chevron-icon');

                if (collapseEl) {
                    // Check current state and toggle
                    var isExpanded = collapseEl.classList.contains('show');

                    if (isExpanded) {
                        // Collapse
                        collapseEl.classList.remove('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-right chevron-icon';
                    } else {
                        // Expand
                        collapseEl.classList.add('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                    }
                }
            }
        }

        // Restore expanded state after table render.
        if (expandedIssueIds.length) {
            expandedIssueIds.forEach(function (issueId) {
                var detailsRow = document.getElementById('issue-details-' + issueId);
                if (detailsRow) detailsRow.classList.add('show');
                var chevronIcon = document.querySelector('#finalIssuesBody [data-collapse-target="#issue-details-' + issueId + '"] .chevron-icon');
                if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
            });
        }

        // Add click handlers for images in expanded details
        setTimeout(function () {
            document.querySelectorAll('#finalIssuesBody img').forEach(function (img) {
                img.style.cursor = 'pointer';
                img.addEventListener('click', function (e) {
                    e.stopPropagation(); // Prevent row collapse
                    var imgSrc = this.src;
                    var imgAlt = this.alt || '';

                    var modal = document.getElementById('issueImageModal');
                    var previewImg = document.getElementById('issueImagePreview');
                    var altTextDiv = document.getElementById('issueImageAltText');
                    var altTextContent = document.getElementById('issueImageAltTextContent');

                    if (modal && previewImg) {
                        previewImg.src = imgSrc;
                        previewImg.alt = imgAlt;

                        if (imgAlt && altTextDiv && altTextContent) {
                            altTextContent.textContent = imgAlt;
                            altTextDiv.style.display = 'block';
                        } else if (altTextDiv) {
                            altTextDiv.style.display = 'none';
                        }

                        var bsModal = new bootstrap.Modal(modal);
                        bsModal.show();
                    }
                });
            });

            // Add event listeners for grouped URLs collapse with manual toggle
            document.querySelectorAll('#finalIssuesBody .grouped-urls-toggle').forEach(function (toggleBtn) {
                toggleBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var targetId = this.getAttribute('data-bs-target');
                    if (targetId) {
                        var collapseEl = document.querySelector(targetId);
                        var chevron = this.querySelector('i');

                        if (collapseEl) {
                            // Toggle using inline style
                            var isHidden = collapseEl.style.display === 'none';

                            if (isHidden) {
                                // Expand
                                collapseEl.style.display = 'block';
                                if (chevron) {
                                    chevron.classList.remove('fa-chevron-down');
                                    chevron.classList.add('fa-chevron-up');
                                }
                                this.setAttribute('aria-expanded', 'true');
                            } else {
                                // Collapse
                                collapseEl.style.display = 'none';
                                if (chevron) {
                                    chevron.classList.remove('fa-chevron-up');
                                    chevron.classList.add('fa-chevron-down');
                                }
                                this.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                });
            });
        }, 100);
    }

    function renderReviewIssues() {
        var tbody = document.getElementById('reviewIssuesBody');
        if (!tbody) return;
        var rawItems = (issueData.selectedPageId && issueData.pages[issueData.selectedPageId]) ? (issueData.pages[issueData.selectedPageId].review || []) : [];
        var allItems = groupReviewItems(rawItems);
        if (!allItems.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-5 text-muted">No review findings logged.</td></tr>';
            renderReviewPagination(0);
            updateIssueTabCounts();
            return;
        }
        var totalPages = Math.max(1, Math.ceil(allItems.length / reviewPageSize));
        if (reviewCurrentPage > totalPages) reviewCurrentPage = totalPages;
        if (reviewCurrentPage < 1) reviewCurrentPage = 1;
        var start = (reviewCurrentPage - 1) * reviewPageSize;
        var pageItems = allItems.slice(start, start + reviewPageSize);

        tbody.innerHTML = pageItems.map(function (it) {
            var instanceText = it.instances && it.instances.length ? it.instances.map(formatInstanceReadable).join(' || ') : '-';
            var fullDesc = (it.description_text || stripHtml(it.details || '') || '');
            if (instanceText && instanceText !== '-') fullDesc += (fullDesc ? ' | ' : '') + 'Instances: ' + instanceText;
            var detailsPreview = escapeHtml(fullDesc.slice(0, 140));
            if (fullDesc.length > 140) detailsPreview += '...';
            var displayInstances = escapeHtml(instanceText.length > 110 ? (instanceText.slice(0, 107) + '...') : instanceText);
            var sourceUrls = Array.isArray(it.source_urls) ? it.source_urls.filter(Boolean) : (it.source_url ? [it.source_url] : []);
            var sourceUrl = sourceUrls.length ? sourceUrls[0] : '-';
            var safeHref = (/^https?:\/\//i.test(String(sourceUrl)) ? sourceUrl : '#');
            var sourceUrlHtml = sourceUrl !== '-' ? '<a href="' + escapeAttr(safeHref) + '" target="_blank" rel="noopener">' + escapeHtml(sourceUrl) + '</a>' : '-';
            var sourceCountHtml = '<div class="small text-muted">' + sourceUrls.length + ' URL' + (sourceUrls.length === 1 ? '' : 's') + '</div>';
            var recommendation = String(it.recommendation || '-').trim() || '-';
            var idsCsv = (it.ids || []).join(',');
            var primaryId = String(it.primary_id || (it.ids && it.ids[0]) || '');
            return '<tr class="align-middle">' +
                '<td class="text-center"><input type="checkbox" class="form-check-input review-select" data-id="' + escapeAttr(idsCsv) + '"></td>' +
                '<td><div class="fw-medium text-dark">' + escapeHtml(it.title) + '</div>' + (detailsPreview ? '<div class="small text-muted">' + detailsPreview + '</div>' : '') + '</td>' +
                '<td class="small text-break">' + sourceUrlHtml + sourceCountHtml + '</td>' +
                '<td class="font-monospace small text-primary" title="' + escapeAttr(instanceText) + '">' + displayInstances + '</td>' +
                '<td><span class="badge bg-light text-dark border">' + escapeHtml(it.rule_id || '-') + '</span></td>' +
                '<td><span class="badge bg-secondary-subtle text-secondary text-uppercase">' + escapeHtml(it.impact || '-') + '</span></td>' +
                '<td><span class="badge bg-light text-dark border">' + escapeHtml(formatWcagDisplay(it.wcag || 'N/A')) + '</span></td>' +
                '<td><span class="badge bg-warning-subtle text-warning text-uppercase">' + escapeHtml(it.severity || '-') + '</span></td>' +
                '<td class="small text-break">' + escapeHtml(recommendation) + '</td>' +
                '<td class="text-end"><div class="btn-group"><button class="btn btn-sm btn-outline-primary review-edit bg-white" data-id="' + escapeAttr(primaryId) + '" data-ids="' + escapeAttr(idsCsv) + '"><i class="fas fa-pencil-alt"></i></button><button class="btn btn-sm btn-outline-danger review-delete bg-white" data-id="' + escapeAttr(idsCsv) + '"><i class="far fa-trash-alt"></i></button></div></td>' +
                '</tr>';
        }).join('');
        renderReviewPagination(allItems.length);
        updateIssueTabCounts();
    }

    function updateIssueTabCounts() {
        var finalCount = 0;
        var reviewCount = 0;
        if (issueData.selectedPageId && issueData.pages[issueData.selectedPageId]) {
            finalCount = (issueData.pages[issueData.selectedPageId].final || []).length;
            reviewCount = groupReviewItems(issueData.pages[issueData.selectedPageId].review || []).length;
        }
        var finalBadge = document.getElementById('finalIssuesCountBadge');
        var reviewBadge = document.getElementById('reviewIssuesCountBadge');
        if (finalBadge) finalBadge.textContent = String(finalCount);
        if (reviewBadge) reviewBadge.textContent = String(reviewCount);
    }

    function normalizeIdList(raw) {
        if (Array.isArray(raw)) {
            return Array.from(new Set(raw.reduce(function (acc, item) {
                return acc.concat(normalizeIdList(item));
            }, [])));
        }
        if (raw == null) return [];
        return String(raw)
            .split(',')
            .map(function (x) { return String(x).trim(); })
            .filter(function (x) { return x !== ''; });
    }

    function collectSelectedReviewIds() {
        var selected = Array.from(document.querySelectorAll('.review-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
        return Array.from(new Set(normalizeIdList(selected)));
    }

    function mapImpactToSeverity(impact) {
        var v = String(impact || '').toLowerCase();
        if (v === 'critical') return 'critical';
        if (v === 'serious') return 'high';
        if (v === 'moderate') return 'medium';
        if (v === 'minor') return 'low';
        return 'medium';
    }

    function formatWcagDisplay(wcagRaw) {
        var src = String(wcagRaw || '');
        if (!src) return 'N/A';
        var tags = src.split(',').map(function (x) { return x.trim().toLowerCase(); }).filter(Boolean);
        var level = '';
        if (tags.indexOf('wcag2aaa') !== -1 || tags.indexOf('wcag21aaa') !== -1) level = 'AAA';
        else if (tags.indexOf('wcag2aa') !== -1 || tags.indexOf('wcag21aa') !== -1) level = 'AA';
        else if (tags.indexOf('wcag2a') !== -1 || tags.indexOf('wcag21a') !== -1) level = 'A';
        var criteria = tags
            .filter(function (t) { return /^wcag\d{3,4}$/.test(t); })
            .map(function (t) {
                var d = t.replace('wcag', '');
                if (d.length === 3) return d[0] + '.' + d[1] + '.' + d[2];
                if (d.length === 4) return d[0] + '.' + d[1] + '.' + d[2] + d[3];
                return t;
            });
        criteria = Array.from(new Set(criteria));
        if (!criteria.length) return level ? ('WCAG ' + level) : src;
        return criteria.join(', ') + (level ? (' (' + level + ')') : '');
    }

    function groupReviewItems(items) {
        var grouped = {};
        (items || []).forEach(function (it) {
            var title = String(it.title || 'Automated Issue').trim();
            var rule = String(it.rule_id || '').trim();
            var impact = String(it.impact || '').trim().toLowerCase();
            var wcag = String(it.wcag || '').trim();
            var source = String(it.source_url || '').trim();
            // Keep grouping tight to avoid unrelated findings being merged together.
            var key = [title.toLowerCase(), rule.toLowerCase(), impact, wcag.toLowerCase()].join('||');
            if (!grouped[key]) {
                grouped[key] = {
                    primary_id: String(it.id),
                    ids: [],
                    title: title,
                    source_url: source,
                    rule_id: rule,
                    impact: impact || '-',
                    wcag: wcag,
                    severity: mapImpactToSeverity(impact),
                    description_text: '',
                    details: '',
                    instances: [],
                    recommendation: '',
                    source_urls: []
                };
            }
            grouped[key].ids.push(String(it.id));
            if (source && grouped[key].source_urls.indexOf(source) === -1) grouped[key].source_urls.push(source);
            extractSourceUrlsFromDetails(it.details || '').forEach(function (u) {
                if (grouped[key].source_urls.indexOf(u) === -1) grouped[key].source_urls.push(u);
            });
            var cleanedInstance = enrichInstanceWithName(it.instance || '', it.incorrect_code || '');
            if (cleanedInstance && grouped[key].instances.indexOf(cleanedInstance) === -1) grouped[key].instances.push(cleanedInstance);
            var desc = String(it.description_text || stripHtml(it.details || '') || '').trim();
            if (desc) {
                if (!grouped[key].description_text) grouped[key].description_text = desc;
                else if (grouped[key].description_text.indexOf(desc) === -1) grouped[key].description_text += ' | ' + desc;
            }
            var rec = String(it.recommendation || '').trim();
            if (rec && !grouped[key].recommendation) grouped[key].recommendation = rec;
        });
        return Object.keys(grouped).map(function (k) { return grouped[k]; });
    }

    function buildReviewEditIssueFromIds(rawIds) {
        var ids = normalizeIdList(rawIds);
        var all = (issueData.selectedPageId && issueData.pages[issueData.selectedPageId]) ? (issueData.pages[issueData.selectedPageId].review || []) : [];
        var matched = all.filter(function (x) { return ids.indexOf(String(x.id)) !== -1; });
        if (!matched.length && ids.length) {
            matched = all.filter(function (x) { return String(x.id) === String(ids[0]); });
        }
        if (!matched.length) return null;
        var first = matched[0];
        var urls = [];
        var instances = [];
        var entryRows = [];
        var incorrectCodes = [];
        var screenshots = [];
        matched.forEach(function (x) {
            var u = String(x.source_url || '').trim();
            if (!u) {
                var m = String(x.description_text || x.details || '').match(/URL\s+\d+\s*:\s*(https?:\/\/\S+)/i);
                if (m && m[1]) u = String(m[1]).trim();
            }
            if (u && urls.indexOf(u) === -1) urls.push(u);
            var iv = formatInstanceReadable(enrichInstanceWithName(x.instance || '', x.incorrect_code || ''));
            if (iv && instances.indexOf(iv) === -1) instances.push(iv);
            if (iv) {
                entryRows.push({
                    url: u || '',
                    instance: iv,
                    failure: formatFailureSummaryText(x.failure_summary || extractLabeledValue(String(x.details || ''), 'Failure') || '')
                });
            }
            var codeVal = String(x.incorrect_code || extractLabeledValue(String(x.details || ''), 'Incorrect Code') || '').trim();
            extractIncorrectCodeSnippets(codeVal).forEach(function (snippet) {
                if (snippet && incorrectCodes.indexOf(snippet) === -1) incorrectCodes.push(snippet);
            });
            var shotVal = String(extractLabeledValue(String(x.details || ''), 'Screenshots') || '').trim();
            normalizeScreenshotList(shotVal, []).forEach(function (s) {
                if (screenshots.indexOf(s) === -1) screenshots.push(s);
            });
        });
        extractSourceUrlsFromDetails(first.details).forEach(function (u) {
            if (urls.indexOf(u) === -1) urls.push(u);
        });
        var instanceLines = instances.map(function (p, idx) { return '- Instance ' + (idx + 1) + ': ' + p; }).join('\n');
        var detailsOut = buildSectionedReviewDetails(first.details || '', urls, instances, first, entryRows, incorrectCodes, screenshots);
        return {
            id: String(first.id),
            ids: ids,
            title: first.title || 'Automated Issue',
            instance: instanceLines || first.instance || '',
            source_urls: urls,
            source_url: (urls[0] || String(first.source_url || '').trim() || ''),
            wcag: first.wcag || '',
            severity: first.severity || 'medium',
            details: detailsOut,
            rule_id: first.rule_id || '',
            impact: first.impact || ''
        };
    }

    function renderReviewPagination(totalItems) {
        var el = document.getElementById('reviewPagination');
        if (!el) return;
        if (!totalItems || totalItems <= reviewPageSize) {
            el.innerHTML = '';
            return;
        }
        var totalPages = Math.ceil(totalItems / reviewPageSize);
        var prevDisabled = reviewCurrentPage <= 1 ? ' disabled' : '';
        var nextDisabled = reviewCurrentPage >= totalPages ? ' disabled' : '';
        el.innerHTML =
            '<div class="d-flex justify-content-between align-items-center small text-muted">' +
            '<div>Showing ' + (((reviewCurrentPage - 1) * reviewPageSize) + 1) + '-' + Math.min(reviewCurrentPage * reviewPageSize, totalItems) + ' of ' + totalItems + '</div>' +
            '<div class="btn-group btn-group-sm">' +
            '<button type="button" class="btn btn-outline-secondary" data-review-page="prev"' + prevDisabled + '>Prev</button>' +
            '<button type="button" class="btn btn-outline-secondary disabled">Page ' + reviewCurrentPage + ' / ' + totalPages + '</button>' +
            '<button type="button" class="btn btn-outline-secondary" data-review-page="next"' + nextDisabled + '>Next</button>' +
            '</div>' +
            '</div>';
    }

    function renderCommonIssues() {
        var tbody = document.getElementById('commonIssuesBody');
        if (!tbody) return;
        if (!issueData.common.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-5 text-muted">No common issues found.</td></tr>';
            return;
        }

        tbody.innerHTML = issueData.common.map(function (it) {
            var pagesStr = (it.pages || []).map(getPageName).slice(0, 5).join(', ') + ((it.pages && it.pages.length > 5) ? '...' : '');
            var uniqueId = 'common-issue-details-' + it.id;
            var pageCount = (it.pages || []).length;
            var descriptionPreview = stripHtml(it.description || '').substring(0, 100);
            if (descriptionPreview && stripHtml(it.description || '').length > 100) descriptionPreview += '...';

            // Try to find the actual issue data from loaded pages
            var actualIssue = null;
            if (it.issue_id && it.pages && it.pages.length > 0) {
                var firstPageId = it.pages[0];
                if (issueData.pages[firstPageId] && issueData.pages[firstPageId].final) {
                    actualIssue = issueData.pages[firstPageId].final.find(function (i) {
                        return String(i.id) === String(it.issue_id);
                    });
                }
            }

            // Main row with expand button
            var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '">' +
                '<td class="text-center checkbox-cell">' +
                '<input type="checkbox" class="form-check-input common-select" data-id="' + it.id + '">' +
                '</td>' +
                '<td style="min-width: 250px; max-width: 400px;">' +
                '<div class="d-flex align-items-center">' +
                '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" ' +
                'data-collapse-target="#' + uniqueId + '" ' +
                'aria-label="Expand details for ' + escapeHtml(it.title) + '" ' +
                'style="border: none; background: none; font-size: 1rem;">' +
                '<i class="fas fa-chevron-right chevron-icon"></i>' +
                '</button>' +
                '<div>' +
                '<div class="fw-bold text-dark">' + escapeHtml(it.title) + '</div>' +
                (descriptionPreview ? '<div class="small text-muted">' + escapeHtml(descriptionPreview) + '</div>' : '') +
                '</div>' +
                '</div>' +
                '</td>' +
                '<td class="small text-muted">' +
                '<span class="badge bg-secondary">' + pageCount + ' page(s)</span>' +
                '</td>' +
                '<td class="text-end action-buttons-cell">' +
                '<div class="btn-group">' +
                '<button class="btn btn-sm btn-outline-primary common-edit bg-white" data-id="' + it.id + '" title="Edit Common Issue">' +
                '<i class="fas fa-pencil-alt"></i>' +
                '</button>' +
                '<button class="btn btn-sm btn-outline-danger common-delete bg-white" data-id="' + it.id + '" title="Delete Common Issue">' +
                '<i class="far fa-trash-alt"></i>' +
                '</button>' +
                '</div>' +
                '</td>' +
                '</tr>';

            // Build metadata section if actual issue is found
            var metadataHtml = '';
            if (actualIssue) {
                var severity = actualIssue.severity || 'N/A';
                var priority = actualIssue.priority || 'N/A';
                var status = actualIssue.status || 'open';
                var statusId = actualIssue.status_id || null;

                // QA Status - match issues_all.php styling exactly
                var qaStatusArray = Array.isArray(actualIssue.qa_status) ? actualIssue.qa_status : (actualIssue.qa_status ? [actualIssue.qa_status] : []);
                var qaStatusHtml = '';
                if (qaStatusArray.length > 0) {
                    qaStatusHtml = qaStatusArray.map(function (qs) {
                        var label = qs;
                        var badgeColor = 'secondary';
                        if (ProjectConfig.qaStatuses) {
                            var found = ProjectConfig.qaStatuses.find(function (s) { return s.status_key === qs; });
                            if (found) {
                                label = found.status_label;
                                badgeColor = found.badge_color || 'secondary';
                            } else {
                                label = qs.split('_').map(function (word) {
                                    return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
                                }).join(' ');
                            }
                        }

                        // Convert Bootstrap color names to hex - same as issues_all.php
                        var getBootstrapColor = function (colorName) {
                            var colorMap = {
                                'primary': '#0d6efd',
                                'secondary': '#6c757d',
                                'success': '#198754',
                                'danger': '#dc3545',
                                'warning': '#ffc107',
                                'info': '#0dcaf0',
                                'light': '#f8f9fa',
                                'dark': '#212529'
                            };
                            if (colorName && colorName.startsWith('#')) {
                                return colorName;
                            }
                            return colorMap[colorName] || colorMap['secondary'];
                        };

                        // Calculate contrast color - same as issues_all.php
                        var getContrastColor = function (hexColor) {
                            var hex = hexColor.replace('#', '');
                            var r = parseInt(hex.substr(0, 2), 16);
                            var g = parseInt(hex.substr(2, 2), 16);
                            var b = parseInt(hex.substr(4, 2), 16);
                            var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
                            return luminance > 0.5 ? '#000000' : '#ffffff';
                        };

                        var bgColor = getBootstrapColor(badgeColor);
                        var textColor = getContrastColor(bgColor);

                        // Use qa-status-badge class to match issues_all.php
                        return '<span class="qa-status-badge" style="background-color: ' + bgColor + ' !important; color: ' + textColor + ' !important;">' + escapeHtml(label) + '</span>';
                    }).join(' ');
                } else {
                    qaStatusHtml = '<span class="text-muted">N/A</span>';
                }

                // Reporters - handle both IDs and names
                var reportersArray = [];

                // First, add reporters from reporters array (these are IDs)
                if (Array.isArray(actualIssue.reporters) && actualIssue.reporters.length > 0) {
                    reportersArray = actualIssue.reporters;
                }

                // If no reporters array, use reporter_name (this is already a name string)
                var reporterHtml = '';
                if (reportersArray.length > 0) {
                    // These are IDs, convert to names
                    reporterHtml = reportersArray.map(function (reporterId) {
                        var reporterName = 'Unknown';
                        if (ProjectConfig.projectUsers) {
                            var found = ProjectConfig.projectUsers.find(function (u) { return u.id == reporterId; });
                            if (found) reporterName = found.full_name;
                        }
                        return '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>';
                    }).join('');
                } else if (actualIssue.reporter_name) {
                    // This is already a name string
                    reporterHtml = '<span class="badge bg-info me-1">' + escapeHtml(actualIssue.reporter_name) + '</span>';
                } else {
                    reporterHtml = '<span class="text-muted">N/A</span>';
                }

                // Grouped URLs
                var urlsHtml = '';
                if (actualIssue.grouped_urls && actualIssue.grouped_urls.length > 0) {
                    var urlsId = 'common-urls-' + it.id;
                    urlsHtml = '<div class="mb-2">';
                    urlsHtml += '<strong>Grouped URLs:</strong> ';
                    urlsHtml += '<span class="badge bg-info">' + actualIssue.grouped_urls.length + '</span> ';
                    urlsHtml += '<button class="btn btn-xs btn-link p-0 grouped-urls-toggle" data-bs-toggle="collapse" data-bs-target="#' + urlsId + '" aria-expanded="false">';
                    urlsHtml += '<i class="fas fa-chevron-down transition-transform"></i>';
                    urlsHtml += '</button>';
                    urlsHtml += '<div class="mt-2" id="' + urlsId + '" style="display: none;">';
                    urlsHtml += '<div class="small p-2 border rounded bg-light" style="max-height: 150px; overflow-y: auto;">';

                    actualIssue.grouped_urls.forEach(function (urlString) {
                        var urlData = (ProjectConfig.groupedUrls || []).find(function (u) {
                            return u.url === urlString || u.normalized_url === urlString;
                        });
                        var displayUrl = urlData ? urlData.url : urlString;
                        if (displayUrl) {
                            urlsHtml += '<div class="mb-1">';
                            urlsHtml += '<a href="' + escapeHtml(displayUrl) + '" target="_blank" class="text-decoration-none">';
                            urlsHtml += '<i class="fas fa-external-link-alt me-1 text-primary"></i>';
                            urlsHtml += '<span class="text-primary">' + escapeHtml(displayUrl) + '</span>';
                            urlsHtml += '</a></div>';
                        }
                    });
                    urlsHtml += '</div></div></div>';
                }

                metadataHtml = '<div class="mb-2"><strong>Issue Key:</strong><br>' +
                    '<span class="badge bg-primary">' + escapeHtml(actualIssue.issue_key || 'N/A') + '</span>' +
                    '</div>' +
                    '<div class="mb-2"><strong>Status:</strong><br>' + getStatusBadge(statusId) + '</div>' +
                    '<div class="mb-2"><strong>QA Status:</strong><br>' + qaStatusHtml + '</div>' +
                    '<div class="mb-2"><strong>Severity:</strong><br>' +
                    '<span class="badge bg-warning text-dark">' + escapeHtml((severity || 'N/A').toUpperCase()) + '</span>' +
                    '</div>' +
                    '<div class="mb-2"><strong>Priority:</strong><br>' +
                    '<span class="badge bg-info text-dark">' + escapeHtml((priority || 'N/A').toUpperCase()) + '</span>' +
                    '</div>' +
                    '<div class="mb-2"><strong>Reporter(s):</strong><br>' +
                    (reportersArray.length > 0 ? reportersArray.map(function (reporterId) {
                        var reporterName = 'Unknown';
                        if (ProjectConfig.projectUsers) {
                            var found = ProjectConfig.projectUsers.find(function (u) { return u.id == reporterId; });
                            if (found) reporterName = found.full_name;
                        }
                        return escapeHtml(reporterName);
                    }).join(', ') : (actualIssue.reporter_name ? escapeHtml(actualIssue.reporter_name) : '<span class="text-muted">N/A</span>')) +
                    '</div>' +
                    '<div class="mb-2"><strong>Page(s):</strong><br>' + escapeHtml(pagesStr) + '</div>' +
                    urlsHtml +
                    (actualIssue.common_title ? '<div class="mb-2"><strong>Common Title:</strong><br>' + escapeHtml(actualIssue.common_title) + '</div>' : '') +
                    (actualIssue.created_at ? '<div class="mb-2"><strong>Created:</strong><br><small class="text-muted">' + new Date(actualIssue.created_at).toLocaleString() + '</small></div>' : '') +
                    (actualIssue.updated_at ? '<div class="mb-2"><strong>Updated:</strong><br><small class="text-muted">' + new Date(actualIssue.updated_at).toLocaleString() + '</small></div>' : '');

                // Add metadata fields
                if (typeof issueMetadataFields !== 'undefined') {
                    issueMetadataFields.forEach(function (f) {
                        if (f.field_key === 'severity' || f.field_key === 'priority') return;
                        var value = actualIssue[f.field_key];
                        if (value && value.length > 0) {
                            var displayValue = Array.isArray(value) ? value.join(', ') : value;
                            metadataHtml += '<div class="mb-2"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                        }
                    });
                }
            } else {
                metadataHtml = '<div class="mb-2"><strong>Title:</strong> ' + escapeHtml(it.title) + '</div>' +
                    '<div class="mb-2"><strong>Pages:</strong> ' +
                    '<span class="badge bg-secondary">' + pageCount + '</span><br>' +
                    '<small class="text-muted">' + escapeHtml(pagesStr) + '</small>' +
                    '</div>' +
                    (it.issue_id ? '<div class="mb-2"><strong>Issue ID:</strong> ' + escapeHtml(it.issue_id) + '</div>' : '') +
                    '<div class="alert alert-info small mt-2"><i class="fas fa-info-circle me-1"></i>Load the page to see full issue details</div>';
            }

            // Expandable details row
            var detailsRow = '<tr class="collapse" id="' + uniqueId + '">' +
                '<td colspan="4" class="p-0 border-0">' +
                '<div class="bg-light p-4 border-top">' +
                '<div class="row g-3">' +
                '<div class="col-md-8">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Description</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                (it.description || actualIssue && actualIssue.details || '<p class="text-muted">No description provided.</p>') +
                '</div>' +
                '</div>' +
                '</div>' +
                '<div class="col-md-4">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Details</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                metadataHtml +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</td>' +
                '</tr>';

            return mainRow + detailsRow;
        }).join('');

        // Add click handlers for chevron toggle buttons in common issues
        document.querySelectorAll('#commonIssuesBody .chevron-toggle-btn').forEach(function (btn) {
            // Click handler for chevron button
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleCommonIssueRow(this);
            });

            // Keyboard handler (Enter or Space)
            btn.addEventListener('keydown', function (e) {
                if (e.keyCode === 13 || e.keyCode === 32) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleCommonIssueRow(this);
                }
            });
        });

        // Add click handler for entire row
        document.querySelectorAll('#commonIssuesBody .issue-expandable-row').forEach(function (row) {
            row.style.cursor = 'pointer';

            row.addEventListener('click', function (e) {
                // Don't expand if clicking on buttons, checkbox, inputs, or action buttons
                if (e.target.closest('button') ||
                    e.target.closest('input') ||
                    e.target.closest('select') ||
                    e.target.closest('.action-buttons-cell') ||
                    e.target.closest('.checkbox-cell')) {
                    return;
                }

                // Find the chevron button in this row and trigger it
                var chevronBtn = this.querySelector('.chevron-toggle-btn');
                if (chevronBtn) {
                    toggleCommonIssueRow(chevronBtn);
                }
            });
        });

        // Helper function to toggle common issue row expansion
        function toggleCommonIssueRow(btn) {
            var targetId = btn.getAttribute('data-collapse-target');
            if (targetId) {
                var collapseEl = document.querySelector(targetId);
                var chevronIcon = btn.querySelector('.chevron-icon');

                if (collapseEl) {
                    var isExpanded = collapseEl.classList.contains('show');

                    if (isExpanded) {
                        collapseEl.classList.remove('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-right chevron-icon';
                    } else {
                        // Before expanding, check if we need to load issue details
                        var commonIssueId = targetId.replace('#common-issue-details-', '');
                        var commonIssue = issueData.common.find(function (ci) { return String(ci.id) === String(commonIssueId); });

                        if (commonIssue && commonIssue.issue_id && commonIssue.pages && commonIssue.pages.length > 0) {
                            var firstPageId = commonIssue.pages[0];

                            // Check if page data is already loaded
                            if (!issueData.pages[firstPageId] || !issueData.pages[firstPageId].final) {
                                // Load the page data first
                                loadFinalIssues(firstPageId).then(function () {
                                    // Re-render common issues with updated data
                                    renderCommonIssues();
                                    // Now expand the row
                                    var newCollapseEl = document.querySelector(targetId);
                                    if (newCollapseEl) {
                                        newCollapseEl.classList.add('show');
                                        var newChevronIcon = document.querySelector('[data-collapse-target="' + targetId + '"] .chevron-icon');
                                        if (newChevronIcon) newChevronIcon.className = 'fas fa-chevron-down chevron-icon';
                                    }
                                }).catch(function (err) {
                                    console.error('Failed to load issue data:', err);
                                    // Expand anyway with basic info
                                    collapseEl.classList.add('show');
                                    if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                                });
                                return; // Don't expand yet, wait for data to load
                            }
                        }

                        // Expand normally if data is already loaded
                        collapseEl.classList.add('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                    }
                }
            }
        }
    }

    function renderAll() { renderFinalIssues(); renderReviewIssues(); renderCommonIssues(); updateSelectionButtons(); }

    function renderIssuePresence(users) {
        var el = document.getElementById('finalIssuePresenceIndicator');
        if (!el) return;
        var currentUserId = String(ProjectConfig.userId || '');
        var others = Array.isArray(users) ? users.filter(function (u) { return String(u.user_id || '') !== currentUserId; }) : [];
        if (!others.length) {
            el.className = 'small mt-1 text-muted';
            el.textContent = 'No other active viewers/editors on this issue.';
            return;
        }
        var names = others.map(function (u) { return u.full_name || 'User'; });
        el.className = 'small mt-1 text-warning';
        el.textContent = 'Currently active on this issue: ' + names.join(', ');
    }

    async function pingIssuePresence(issueId) {
        if (!issueId) return;
        try {
            var fd = new FormData();
            fd.append('action', 'presence_ping');
            fd.append('project_id', projectId);
            fd.append('issue_id', issueId);
            if (issuePresenceSessionToken) fd.append('session_token', issuePresenceSessionToken);
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            if (!res.ok) {
                renderIssuePresence([]);
                return;
            }
            var json = await res.json();
            if (json && json.success) {
                renderIssuePresence(json.users || []);
            } else {
                renderIssuePresence([]);
            }
        } catch (e) {
            renderIssuePresence([]);
        }
    }

    function stopIssuePresenceTracking() {
        if (issuePresenceTimer) {
            clearInterval(issuePresenceTimer);
            issuePresenceTimer = null;
        }
        var issueId = issuePresenceIssueId;
        issuePresenceIssueId = null;
        if (!issueId) {
            renderIssuePresence([]);
            return;
        }
        try {
            var fd = new FormData();
            fd.append('action', 'presence_leave');
            fd.append('project_id', projectId);
            fd.append('issue_id', issueId);
            if (issuePresenceSessionToken) fd.append('session_token', issuePresenceSessionToken);
            fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
        issuePresenceSessionToken = null;
        renderIssuePresence([]);
    }

    async function openIssuePresenceSession(issueId) {
        issuePresenceSessionToken = null;
        try {
            var fd = new FormData();
            fd.append('action', 'presence_open_session');
            fd.append('project_id', projectId);
            fd.append('issue_id', issueId);
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            if (!res.ok) return;
            var json = await res.json();
            if (json && json.success && json.session_token) {
                issuePresenceSessionToken = json.session_token;
            }
        } catch (e) { }
    }

    async function startIssuePresenceTracking(issueId) {
        stopIssuePresenceTracking();
        if (!issueId) {
            var el = document.getElementById('finalIssuePresenceIndicator');
            if (el) {
                el.className = 'small mt-1 text-muted';
                el.textContent = 'New issue mode (not yet shared).';
            }
            return;
        }
        var labelEl = document.getElementById('finalIssuePresenceIndicator');
        if (labelEl) {
            labelEl.className = 'small mt-1 text-muted';
            labelEl.textContent = 'Checking active users...';
        }
        issuePresenceIssueId = String(issueId);
        await openIssuePresenceSession(issuePresenceIssueId);
        pingIssuePresence(issuePresenceIssueId);
        issuePresenceTimer = setInterval(function () {
            if (document.hidden || !issuePresenceIssueId) return;
            pingIssuePresence(issuePresenceIssueId);
        }, ISSUE_PRESENCE_PING_MS);
    }

    function leaveIssuePresenceOnUnload() {
        if (!issuePresenceIssueId) return;
        try {
            var payload = new URLSearchParams();
            payload.append('action', 'presence_leave');
            payload.append('project_id', String(projectId));
            payload.append('issue_id', String(issuePresenceIssueId));
            if (issuePresenceSessionToken) payload.append('session_token', String(issuePresenceSessionToken));
            if (navigator.sendBeacon) {
                navigator.sendBeacon(issuesApiBase, payload);
            } else {
                fetch(issuesApiBase, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }
                });
            }
        } catch (e) { }
    }

    function isIssueEditorOpen() {
        var modal = document.getElementById('finalIssueModal');
        return !!(modal && modal.classList.contains('show'));
    }

    async function runLiveIssueSync() {
        if (document.hidden || liveIssueSyncInFlight || isIssueEditorOpen()) return;
        var tasks = [];
        if (issueData.selectedPageId) {
            // Do not refresh Final Issues while any row is expanded; prevents auto-collapse.
            var hasExpandedFinalRows = !!document.querySelector('#finalIssuesBody tr.collapse.show');
            if (!hasExpandedFinalRows) {
                tasks.push(loadFinalIssues(issueData.selectedPageId));
            }
        }
        if (document.getElementById('commonIssuesBody')) {
            tasks.push(loadCommonIssues());
        }
        if (!tasks.length) return;

        liveIssueSyncInFlight = true;
        try {
            await Promise.all(tasks);
        } catch (e) {
            // Silent background refresh failure.
        } finally {
            liveIssueSyncInFlight = false;
        }
    }

    function startLiveIssueSync() {
        if (liveIssueSyncTimer) return;
        liveIssueSyncTimer = setInterval(runLiveIssueSync, LIVE_ISSUE_SYNC_INTERVAL_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) runLiveIssueSync();
        });
        window.addEventListener('beforeunload', function () {
            if (liveIssueSyncTimer) {
                clearInterval(liveIssueSyncTimer);
                liveIssueSyncTimer = null;
            }
        });
    }

    function updateSelectionButtons() {
        var finalChecked = document.querySelectorAll('.final-select:checked').length;
        var reviewChecked = document.querySelectorAll('.review-select:checked').length;
        var finalDelete = document.getElementById('finalDeleteSelected');
        var reviewDelete = document.getElementById('reviewDeleteSelected');
        var reviewMove = document.getElementById('reviewMoveSelected');
        if (finalDelete) finalDelete.disabled = !finalChecked || !canEdit();
        if (reviewDelete) reviewDelete.disabled = !reviewChecked || !canEdit();
        if (reviewMove) reviewMove.disabled = !reviewChecked || !canEdit();
    }

    function getPageName(id) { var p = (pages || []).find(function (x) { return String(x.id) === String(id); }); return p ? p.page_name : id; }
    function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/[&<>"']/g, function (m) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[m]); }); }
    function stripHtml(html) { if (!html) return ''; var tmp = document.createElement('div'); tmp.innerHTML = html; return tmp.textContent || tmp.innerText || ''; }

    function getSeverityBadge(s) {
        if (!s || s === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        return '<span class="badge bg-warning text-dark">' + escapeHtml(String(s).toUpperCase()) + '</span>';
    }

    function getPriorityBadge(p) {
        if (!p || p === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        return '<span class="badge bg-info text-dark">' + escapeHtml(String(p).toUpperCase()) + '</span>';
    }

    function getStatusBadge(statusId) {
        if (!statusId) return '<span class="badge bg-secondary">N/A</span>';
        if (ProjectConfig.issueStatuses) {
            var found = ProjectConfig.issueStatuses.find(function (s) {
                if (s.id == statusId) return true;
                if (s.name && String(s.name).toLowerCase() === String(statusId).toLowerCase()) return true;
                return false;
            });
            if (found) {
                var color = found.color || '#6c757d';
                var name = found.name || 'Unknown';
                if (color.startsWith('#')) {
                    return '<span class="badge" style="background-color: ' + color + '; color: white;">' + escapeHtml(name) + '</span>';
                } else {
                    return '<span class="badge bg-' + color + '">' + escapeHtml(name) + '</span>';
                }
            }
        }
        return '<span class="badge bg-secondary">' + escapeHtml(String(statusId)) + '</span>';
    }

    function extractAltText(html) { if (!html) return ''; var matches = []; var re = /<img[^>]*alt=['"]([^'"]*)['"][^>]*>/gi; var m; while ((m = re.exec(html)) !== null) { if (m[1] && matches.indexOf(m[1]) === -1) matches.push(m[1]); } return matches.join(' | '); }
    function decorateIssueImages(html) { if (!html) return ''; return String(html).replace(/<img\b([^>]*)>/gi, function (_, attrs) { if (/class\s*=/.test(attrs)) { return '<img' + attrs.replace(/class\s*=(["\'])([^"\']*)\1/, 'class="$2 issue-image-thumb"') + '>'; } return '<img class="issue-image-thumb"' + attrs + '>'; }); }
    function openIssueImageModal(src) {
        var m = document.getElementById('issueImageModal');
        var i = document.getElementById('issueImagePreview');
        if (!m || !i) return;
        i.src = src || '';
        new bootstrap.Modal(m).show();
    }

    function renderIssueComments(issueId) {
        var listEl = document.getElementById('finalIssueCommentsList');
        if (!listEl) return;
        var items = issueData.comments[issueId || 'new'] || [];

        if (!items.length) {
            listEl.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-comments fa-3x mb-3 opacity-25"></i><p>No comments yet. Start the conversation!</p></div>';
            return;
        }

        var currentUserId = ProjectConfig.userId;

        listEl.innerHTML = items.map(function (c, idx) {
            var isOwn = (c.user_id === currentUserId);
            var isRegression = (c.comment_type === 'regression');

            var commentText = decorateIssueImages(c.text || '');
            // Highlight @ mentions
            commentText = commentText.replace(/@(\w+)/g, '<span class="badge bg-warning text-dark">@$1</span>');

            // Reply preview if exists
            var replyPreview = '';
            if (c.reply_to && c.reply_preview) {
                var rp = c.reply_preview;
                var replyText = (rp.text || '').replace(/<[^>]*>/g, '').substring(0, 80);
                if (rp.text && rp.text.length > 80) replyText += '...';

                replyPreview = '<div class="reply-preview mb-2 p-2 rounded" style="background: #f8f9fa; border-left: 3px solid #0d6efd;">' +
                    '<div class="d-flex align-items-center mb-1">' +
                    '<i class="fas fa-reply text-primary me-2" style="font-size: 0.75rem;"></i>' +
                    '<small class="text-muted fw-bold">Replying to ' + escapeHtml(rp.user_name || 'User') + '</small>' +
                    '</div>' +
                    '<small class="text-muted" style="font-style: italic;">' + escapeHtml(replyText) + '</small>' +
                    '</div>';
            }

            // Determine background color based on comment type
            var bgClass = '';
            var borderStyle = '';
            var regressionHeading = '';

            if (isRegression) {
                // Regression comment: always very light blue with border
                bgClass = ''; // No class to avoid conflicts
                borderStyle = 'background: #e7f3ff !important; border-left: 3px solid #0d6efd;';
                regressionHeading = '<div class="mb-2 pb-2 border-bottom" style="border-color: #b6d4fe !important;">' +
                    '<span class="badge" style="background: #0d6efd; font-size: 0.75rem;">' +
                    '<i class="fas fa-retweet me-1"></i>Regression Comment' +
                    '</span>' +
                    '</div>';
            } else if (isOwn) {
                bgClass = 'bg-primary-subtle';
            } else {
                bgClass = 'bg-light';
            }

            // Add regression badge next to name (smaller, for header)
            var regressionBadge = isRegression ? '<span class="badge bg-info ms-2" style="font-size: 0.65rem;"><i class="fas fa-retweet me-1"></i>Regression</span>' : '';

            return '<div class="message ' + (isOwn ? 'own-message' : 'other-message') + ' mb-3" data-comment-id="' + (c.id || idx) + '">' +
                '<div class="d-flex justify-content-between align-items-start mb-1">' +
                '<div>' +
                '<span class="fw-semibold text-primary">' + escapeHtml(c.user_name || 'User') + '</span>' +
                regressionBadge +
                '</div>' +
                '<div class="d-flex align-items-center gap-2">' +
                '<small class="text-muted">' + escapeHtml(c.time || '') + '</small>' +
                '<button class="btn btn-xs btn-link p-0 text-decoration-none issue-comment-reply" ' +
                'data-comment-id="' + (c.id || idx) + '" ' +
                'data-user-name="' + escapeHtml(c.user_name || 'User') + '" ' +
                'data-comment-text="' + escapeHtml((c.text || '').replace(/<[^>]*>/g, '').substring(0, 100)) + '">' +
                '<i class="fas fa-reply"></i> Reply' +
                '</button>' +
                '</div>' +
                '</div>' +
                replyPreview +
                '<div class="message-content p-2 rounded ' + bgClass + '" style="' + borderStyle + '">' +
                regressionHeading +
                commentText +
                '</div>' +
                '</div>';
        }).join('');

        // Add reply click handlers
        document.querySelectorAll('.issue-comment-reply').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var commentId = this.getAttribute('data-comment-id');
                var userName = this.getAttribute('data-user-name');
                var commentText = this.getAttribute('data-comment-text');
                showReplyPreview(commentId, userName, commentText);
            });
        });
    }

    function showReplyPreview(commentId, userName, commentText) {
        // Create or update reply preview
        var previewEl = document.getElementById('issueCommentReplyPreview');
        if (!previewEl) {
            var editorWrap = document.querySelector('#finalIssueCommentEditor').closest('.mb-3');
            if (!editorWrap) return;

            var previewHtml = '<div id="issueCommentReplyPreview" class="alert alert-info mb-3" style="display:none; background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%); border: 1px solid #b6d4fe; border-left: 4px solid #0d6efd;">' +
                '<div class="d-flex align-items-start">' +
                '<div class="flex-shrink-0">' +
                '<i class="fas fa-reply text-primary me-2" style="font-size: 1.1rem; margin-top: 2px;"></i>' +
                '</div>' +
                '<div class="flex-grow-1">' +
                '<div class="fw-bold text-primary mb-1">' +
                'Replying to <span id="replyUserName" class="text-decoration-underline"></span>' +
                '</div>' +
                '<div class="small text-muted" id="replyCommentText" style="font-style: italic; padding-left: 0.25rem; border-left: 2px solid #dee2e6;"></div>' +
                '</div>' +
                '<button type="button" class="btn-close ms-2" id="cancelReply" aria-label="Cancel" style="font-size: 0.75rem;"></button>' +
                '</div>' +
                '<input type="hidden" id="replyToCommentId" value="">' +
                '</div>';

            editorWrap.insertAdjacentHTML('afterbegin', previewHtml);
            previewEl = document.getElementById('issueCommentReplyPreview');

            // Add cancel handler
            document.getElementById('cancelReply').addEventListener('click', function () {
                previewEl.style.display = 'none';
                document.getElementById('replyToCommentId').value = '';
            });
        }

        // Update preview content
        document.getElementById('replyUserName').textContent = userName;
        document.getElementById('replyCommentText').textContent = commentText;
        document.getElementById('replyToCommentId').value = commentId;
        previewEl.style.display = 'block';

        // Smooth scroll to editor
        previewEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Focus editor after a short delay
        setTimeout(function () {
            if (window.jQuery && jQuery.fn.summernote) {
                jQuery('#finalIssueCommentEditor').summernote('focus');
            }
        }, 300);
    }

    function addIssueComment(issueId) {
        var key = issueId || 'new';
        if (key === 'new') { alert('Please save the issue before adding chat.'); return; }
        var html = (window.jQuery && jQuery.fn.summernote) ? jQuery('#finalIssueCommentEditor').summernote('code') : document.getElementById('finalIssueCommentEditor').value;
        if (!String(html || '').replace(/<[^>]*>/g, '').trim()) return;

        // Get comment type
        var commentTypeEl = document.getElementById('finalIssueCommentType');
        var commentType = commentTypeEl ? commentTypeEl.value : 'normal';

        // Get reply_to if exists
        var replyToEl = document.getElementById('replyToCommentId');
        var replyTo = replyToEl ? replyToEl.value : '';

        // Extract mentions from comment
        var mentions = [];
        var mentionRegex = /@(\w+)/g;
        var match;
        while ((match = mentionRegex.exec(html)) !== null) {
            var username = match[1];
            // Find user ID by username
            var users = ProjectConfig.projectUsers || [];
            var user = users.find(function (u) {
                var uUsername = String(u.username || '').toLowerCase();
                var uFullNameAsUser = String(u.full_name || '').toLowerCase().replace(/\s+/g, '');
                var target = String(username || '').toLowerCase();
                return uUsername === target || uFullNameAsUser === target;
            });
            if (user && mentions.indexOf(user.id) === -1) {
                mentions.push(user.id);
            }
        }

        var fd = new FormData();
        fd.append('action', 'create');
        fd.append('project_id', projectId);
        fd.append('issue_id', key);
        fd.append('comment_html', html);
        fd.append('comment_type', commentType);
        fd.append('mentions', JSON.stringify(mentions));
        if (replyTo) {
            fd.append('reply_to', replyTo);
        }

        fetch(issueCommentsApi, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(function (res) {
            if (!res || res.error) return;
            if (!issueData.comments[key]) issueData.comments[key] = [];
            issueData.comments[key].unshift({
                user_id: ProjectConfig.userId,
                user_name: 'You',
                text: html,
                time: new Date().toLocaleString(),
                reply_to: replyTo || null,
                comment_type: commentType
            });
            if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueCommentEditor').summernote('code', '');

            // Reset comment type to normal
            if (commentTypeEl) {
                commentTypeEl.value = 'normal';
            }

            // Hide reply preview
            var previewEl = document.getElementById('issueCommentReplyPreview');
            if (previewEl) {
                previewEl.style.display = 'none';
            }
            if (replyToEl) {
                replyToEl.value = '';
            }

            renderIssueComments(key);
        });
    }

    function loadIssueComments(issueId) {
        if (!issueId) return;
        fetch(issueCommentsApi + '?action=list&project_id=' + encodeURIComponent(projectId) + '&issue_id=' + encodeURIComponent(issueId), { credentials: 'same-origin' }).then(r => r.json()).then(function (res) {
            if (res && res.comments) {
                issueData.comments[String(issueId)] = res.comments.map(function (c) {
                    return {
                        id: c.id,
                        user_id: c.user_id,
                        user_name: c.user_name,
                        qa_status: c.qa_status_name || '',
                        text: c.comment_html,
                        time: c.created_at,
                        reply_to: c.reply_to || null,
                        reply_preview: c.reply_preview || null,
                        comment_type: c.comment_type || 'normal'
                    };
                });
                renderIssueComments(String(issueId));
            }
        });
    }

    function applyPreset(preset) {
        if (!preset) return;
        jQuery('#finalIssueTitle').val(preset.name).trigger('change');
        if (window.jQuery && jQuery.fn.summernote) {
            jQuery('#finalIssueDetails').summernote('code', preset.description_html || preset.description || '');
        }

        var meta = {};
        if (preset.metadata_json) {
            if (typeof preset.metadata_json === 'string') {
                try { meta = JSON.parse(preset.metadata_json) || {}; } catch (e) { meta = {}; }
            } else if (typeof preset.metadata_json === 'object') {
                meta = preset.metadata_json || {};
            }
        } else if (preset.meta_json) {
            try { meta = JSON.parse(preset.meta_json) || {}; } catch (e) { meta = {}; }
        }

        var sev = (meta.severity || preset.severity || 'medium');
        var pri = (meta.priority || preset.priority || 'medium');
        toggleFinalIssueFields(true);
        var $s = jQuery('#finalIssueField_severity'); if ($s.length) $s.val(sev.toLowerCase()).trigger('change');
        var $p = jQuery('#finalIssueField_priority'); if ($p.length) $p.val(pri.toLowerCase()).trigger('change');

        Object.keys(meta).forEach(function (k) {
            if (['status', 'qa_status', 'pages', 'reporters', 'grouped_urls', 'common_title'].indexOf(k) !== -1) return;
            var dynId = 'finalIssueField_' + k;
            var field = document.getElementById(dynId);
            if (field) {
                var val = meta[k];
                if (Array.isArray(val)) {
                    jQuery(field).val(val).trigger('change');
                } else {
                    jQuery(field).val(val != null ? [String(val)] : []).trigger('change');
                }
            }
        });
        setTimeout(enableToolbarKeyboardA11y, 0);
        setTimeout(enableToolbarKeyboardA11y, 200);
    }

    function renderSectionButtons(sections) {
        var wrap = document.getElementById('finalIssueSectionButtons');
        if (!wrap) return;
        wrap.innerHTML = '';
        (sections || []).forEach(function (s) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-secondary'; btn.textContent = s;
            btn.addEventListener('click', function () {
                if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('pasteHTML', '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p>');
            });
            wrap.appendChild(btn);
        });
    }

    function ensureDefaultSections() {
        if (!defaultSections.length) return;
        if (window.jQuery && jQuery.fn.summernote) {
            var cur = jQuery('#finalIssueDetails').summernote('code');
            var plain = String(cur || '').replace(/<[^>]*>/g, '').trim();
            if (plain) return;
            var html = defaultSections.map(function (s) { return '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p>'; }).join('');
            jQuery('#finalIssueDetails').summernote('code', html);
        }
    }

    function setDefaultSectionsInEditor() {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        if (!defaultSections.length) {
            alert('No default template sections configured for this project type.');
            return;
        }
        var html = defaultSections.map(function (s) {
            return '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p><p><br></p>';
        }).join('');
        jQuery('#finalIssueDetails').summernote('code', html);
    }

    function resetToTemplateWithConfirm() {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        var cur = jQuery('#finalIssueDetails').summernote('code');
        var plain = String(cur || '').replace(/<[^>]*>/g, '').trim();
        if (plain && !window.confirm('This will replace the current content with the default template. Continue?')) {
            return;
        }
        clearIssueMetadataForTemplateReset();
        if (defaultSections.length) {
            setDefaultSectionsInEditor();
            return;
        }
        fetch(issueTemplatesApi + '?action=list&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                defaultSections = (res && res.default_sections) ? res.default_sections : [];
                setDefaultSectionsInEditor();
            })
            .catch(function () {
                alert('Failed to load template sections. Please try again.');
            });
    }

    function loadTemplates() {
        if (!issueTemplatesApi) return;
        fetch(issueTemplatesApi + '?action=list&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                issuePresets = res.templates || [];
                defaultSections = res.default_sections || [];
                var sel = document.getElementById('finalIssueTitle');
                if (sel) {
                    // Professional Select2 setup with custom template and fallback
                    if (window.jQuery && jQuery.fn.select2) {
                        // Pehle destroy karo, fir options inject karo, fir select2 init karo
                        try {
                            if (jQuery(sel).data('select2')) {
                                jQuery(sel).select2('destroy');
                            }
                            jQuery(sel).empty();
                            jQuery(sel).append('<option value="">Select preset or type title...</option>');
                            (issuePresets || []).forEach(function (t) {
                                jQuery(sel).append('<option value="PRESET:' + t.id + '">' + t.name + '</option>');
                            });
                        } catch (e) { }
                        jQuery(sel).select2({
                            tags: true,
                            theme: 'bootstrap-5',
                            placeholder: 'Select preset or type title...',
                            dropdownParent: jQuery('#finalIssueModal'),
                            width: '100%',
                            templateResult: function (data) {
                                if (data.loading) return data.text;
                                if (data.id && String(data.id).startsWith('PRESET:')) {
                                    return '<span class="text-primary fw-bold"><i class="fas fa-star me-1"></i>' + data.text + '</span>';
                                }
                                return '<span>' + data.text + '</span>';
                            },
                            templateSelection: function (data) {
                                return data.text;
                            },
                            escapeMarkup: function (m) { return m; }
                        }).on('change', function () {
                            var val = jQuery(this).val();
                            if (val && typeof val === 'string' && val.indexOf('PRESET:') === 0) {
                                var pid = val.split(':')[1];
                                var preset = issuePresets.find(function (p) { return String(p.id) === String(pid); });
                                if (preset) applyPreset(preset);
                            }
                        });
                        // Trigger change on modal open (no auto-focus)
                        jQuery('#finalIssueModal').on('shown.bs.modal', function () {
                            setTimeout(function () {
                                jQuery(sel).trigger('change.select2');
                            }, 300);
                        });
                    } else {
                        // Fallback: datalist input
                        try {
                            sel.innerHTML = '';
                            var container = sel.parentElement;
                            var input = document.createElement('input');
                            input.type = 'text'; input.id = 'finalIssueTitleInput'; input.className = 'form-control form-control-lg';
                            input.placeholder = 'Type issue title...';
                            var dl = document.createElement('datalist'); dl.id = 'finalIssueTitleList';
                            issuePresets.forEach(function (t) { var o = document.createElement('option'); o.value = t.name; dl.appendChild(o); });
                            container.replaceChild(input, sel);
                            container.appendChild(dl);
                            input.setAttribute('list', dl.id);
                        } catch (e) { }
                    }
                }
                renderSectionButtons(defaultSections);
            });
    }

    function applyMetadataOptions(fields) {
        if (!fields || !fields.length) return;
        var container = document.getElementById('finalIssueMetadataContainer');
        if (!container) return;
        container.innerHTML = '';
        fields.forEach(function (f) {
            var label = document.createElement('label'); label.className = 'form-label mt-2'; label.textContent = f.field_label; container.appendChild(label);
            var select = document.createElement('select'); select.className = 'form-select form-select-sm issue-dynamic-field issue-select2-tags';
            select.id = 'finalIssueField_' + f.field_key; select.multiple = true;
            (f.options || []).forEach(function (o) { select.appendChild(new Option(o.option_label, o.option_value)); });
            container.appendChild(select);
        });
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.issue-dynamic-field.issue-select2-tags').select2({ width: '100%', tags: true, tokenSeparators: [','], dropdownParent: jQuery('#finalIssueModal') });
        }
    }

    function loadMetadataOptions() {
        if (!issueTemplatesApi) return;
        fetch(issueTemplatesApi + '?action=metadata_options&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res && res.fields) { issueMetadataFields = res.fields; applyMetadataOptions(res.fields); }
            });
    }

    async function addOrUpdateFinalIssue() {
        var selectedPageId = issueData.selectedPageId;
        if (!selectedPageId) {
            var selectedPagesFallback = (window.jQuery ? (jQuery('#finalIssuePages').val() || []) : []);
            if (selectedPagesFallback.length) {
                selectedPageId = selectedPagesFallback[0];
                issueData.selectedPageId = selectedPageId;
            }
        }
        if (!selectedPageId) {
            alert('Please select at least one page before saving the issue.');
            return;
        }
        var editId = document.getElementById('finalIssueEditId').value;
        var expectedUpdatedAt = '';
        var expectedUpdatedAtEl = document.getElementById('finalIssueExpectedUpdatedAt');
        if (expectedUpdatedAtEl) expectedUpdatedAt = (expectedUpdatedAtEl.value || '').trim();
        var titleVal = '';
        var titleInput = document.getElementById('customIssueTitle');
        if (titleInput) {
            titleVal = titleInput.value.trim();
        }
        var data = {
            title: titleVal,
            details: jQuery('#finalIssueDetails').summernote('code'),
            status: document.getElementById('finalIssueStatus').value,
            qa_status: jQuery('#finalIssueQaStatus').val() || [],
            priority: document.getElementById('finalIssueField_priority') ? document.getElementById('finalIssueField_priority').value : 'medium',
            pages: jQuery('#finalIssuePages').val() || [],
            grouped_urls: normalizeGroupedUrlsSelection(jQuery('#finalIssueGroupedUrls').val() || []),
            reporters: jQuery('#finalIssueReporters').val() || [],
            common_title: document.getElementById('finalIssueCommonTitle').value.trim()
        };

        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                var el = document.getElementById('finalIssueField_' + f.field_key);
                if (el) {
                    var value = jQuery(el).val();
                    data[f.field_key] = value;
                }
            });
        }

        // Separate metadata fields
        var metadata = {};
        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                if (data.hasOwnProperty(f.field_key)) {
                    metadata[f.field_key] = data[f.field_key];
                    delete data[f.field_key];
                }
            });
        }

        if (!data.title) { alert('Issue title is required.'); return; }

        try {
            var fd = new FormData();
            fd.append('action', editId ? 'update' : 'create');
            fd.append('project_id', projectId);
            if (editId) fd.append('id', editId);
            if (editId && expectedUpdatedAt) fd.append('expected_updated_at', expectedUpdatedAt);
            fd.append('page_id', selectedPageId);
            fd.append('metadata', JSON.stringify(metadata));

            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) { fd.append(k, JSON.stringify(v)); }
                else {
                    if (k === 'status') fd.append('issue_status', v);
                    else if (k === 'details') fd.append('description', v);
                    else fd.append(k, v);
                }
            });

            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();

            if (!json || json.error) {
                throw new Error(json && json.error ? json.error : 'Save failed');
            }

            if (!json.success) {
                throw new Error('Save failed - server returned unsuccessful response');
            }

            var store = issueData.pages;
            ensurePageStore(store, selectedPageId);
            var pagesArr = (data.pages && data.pages.length) ? data.pages : [selectedPageId];

            var payload = Object.assign({ id: String(editId || json.id || ''), issue_key: String(json.issue_key || '') }, data);
            var list = store[selectedPageId].final || [];
            var idx = list.findIndex(function (it) { return String(it.id) === String(payload.id); });
            if (idx >= 0) list[idx] = payload; else list.unshift(payload);
            store[selectedPageId].final = list;

            renderFinalIssues();
            updateSelectionButtons();
            showFinalIssuesTab();

            if (!editId && issueData.comments['new']) {
                issueData.comments[String(json.id)] = issueData.comments['new'];
                delete issueData.comments['new'];
            }
            if (!editId) await deleteDraft();
            stopDraftAutosave();
            issueData.initialFormState = null;
            hideEditors();
            await loadFinalIssues(selectedPageId);
            await loadCommonIssues();
        } catch (e) {
            if (String(e.message || '').toLowerCase().indexOf('modified by another user') !== -1) {
                alert('Issue was updated by another user. Latest version loaded. Please review and save again.');
                await loadFinalIssues(selectedPageId);
                var freshList = (issueData.pages[selectedPageId] && issueData.pages[selectedPageId].final) ? issueData.pages[selectedPageId].final : [];
                var freshIssue = freshList.find(function (it) { return String(it.id) === String(editId); });
                if (freshIssue) openFinalEditor(freshIssue);
                return;
            }
            alert('Unable to save issue: ' + e.message);
        }
    }

    async function addOrUpdateReviewIssue() {
        if (!issueData.selectedPageId) return;
        var editId = document.getElementById('reviewIssueEditId').value;
        var editIds = normalizeIdList(editId);
        var detailsHtml = jQuery('#reviewIssueDetails').summernote('code');
        var meta = {
            rule_id: (document.getElementById('reviewIssueRuleId') || {}).value || '',
            impact: (document.getElementById('reviewIssueImpact') || {}).value || '',
            source_url: (document.getElementById('reviewIssuePrimarySourceUrl') || {}).value || ''
        };
        var data = {
            title: document.getElementById('reviewIssueTitle').value.trim(),
            instance: document.getElementById('reviewIssueInstance').value.trim(),
            wcag: document.getElementById('reviewIssueWcag').value.trim(),
            severity: document.getElementById('reviewIssueSeverity').value,
            details: wrapReviewDetailsWithMeta(detailsHtml, document.getElementById('reviewIssueTitle').value.trim(), meta)
        };
        if (!data.title) { alert('Issue title is required.'); return; }
        if (!data.details || data.details.trim() === '') data.details = data.title;
        try {
            var fd = new FormData();
            fd.append('project_id', projectId);
            fd.append('page_id', issueData.selectedPageId);
            fd.append('title', data.title);
            fd.append('instance_name', data.instance);
            fd.append('wcag_failure', data.wcag);
            fd.append('details', data.details);
            fd.append('summary', '');
            fd.append('snippet', '');

            if (editIds.length) {
                for (var i = 0; i < editIds.length; i++) {
                    var fdUpd = new FormData();
                    fdUpd.append('project_id', projectId);
                    fdUpd.append('page_id', issueData.selectedPageId);
                    fdUpd.append('title', data.title);
                    fdUpd.append('instance_name', data.instance);
                    fdUpd.append('wcag_failure', data.wcag);
                    fdUpd.append('details', data.details);
                    fdUpd.append('summary', '');
                    fdUpd.append('snippet', '');
                    fdUpd.append('action', 'update');
                    fdUpd.append('id', editIds[i]);
                    var resUpd = await fetch(apiBase, { method: 'POST', body: fdUpd, credentials: 'same-origin' });
                    var jsonUpd = await resUpd.json();
                    if (!jsonUpd || jsonUpd.error) throw new Error(jsonUpd && jsonUpd.error ? jsonUpd.error : 'Save failed');
                }
            } else {
                fd.append('action', 'create');
                var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
                var json = await res.json();
                if (!json || json.error) throw new Error(json && json.error ? json.error : 'Save failed');
            }
            reviewIssueBypassCloseConfirm = true;
            reviewIssueInitialFormState = null;
            hideEditors();
            clearReviewDraftLocal();
            await loadReviewFindings(issueData.selectedPageId);
        } catch (e) { alert('Unable to save automated finding.'); }
    }

    async function moveCurrentReviewIssueToFinal() {
        if (!issueData.selectedPageId) return;
        var btn = document.getElementById('reviewIssueMoveToFinalBtn');
        if (!btn) return;
        var ids = normalizeIdList(btn.getAttribute('data-ids') || '');
        if (!ids.length) return;
        try {
            var mergedTitle = (document.getElementById('reviewIssueTitle') || {}).value || '';
            var mergedDetails = '';
            if (window.jQuery && jQuery.fn.summernote) {
                mergedDetails = jQuery('#reviewIssueDetails').summernote('code') || '';
            } else {
                mergedDetails = (document.getElementById('reviewIssueDetails') || {}).value || '';
            }
            var mergedSeverity = (document.getElementById('reviewIssueSeverity') || {}).value || 'medium';
            var sourceUrlsRaw = ((document.getElementById('reviewIssueSourceUrls') || {}).value || '').split('\n');
            var mergedSourceUrls = sourceUrlsRaw.map(function (line) {
                var s = String(line || '').trim();
                if (!s) return '';
                s = s.replace(/^\d+\.\s*/, '').trim();
                return s;
            }).filter(function (u) { return /^https?:\/\//i.test(u); });
            mergedSourceUrls = Array.from(new Set(mergedSourceUrls));

            var fd = new FormData();
            fd.append('action', 'move_to_issue');
            fd.append('project_id', projectId);
            fd.append('ids', ids.join(','));
            fd.append('merged_title', String(mergedTitle).trim());
            fd.append('merged_details', String(mergedDetails || ''));
            fd.append('merged_severity', String(mergedSeverity || 'medium').trim());
            fd.append('merged_source_urls', JSON.stringify(mergedSourceUrls));
            var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Move failed');
            hideEditors();
            await loadReviewFindings(issueData.selectedPageId);
            await loadFinalIssues(issueData.selectedPageId);
            updateIssueTabCounts();
            if (window.showToast) showToast('Moved to Final Issues.', 'success');
        } catch (e) {
            alert('Unable to move finding to final issue.');
        }
    }

    async function addOrUpdateCommonIssue() {
        var editId = document.getElementById('commonIssueEditId').value;
        var data = {
            title: document.getElementById('commonIssueTitle').value.trim(),
            pages: jQuery('#commonIssuePages').val() || [],
            details: jQuery('#commonIssueDetails').summernote('code')
        };
        if (!data.title) { alert('Common issue title is required.'); return; }
        try {
            var fd = new FormData();
            fd.append('action', editId ? 'common_update' : 'common_create');
            fd.append('project_id', projectId);
            if (editId) fd.append('id', editId);
            fd.append('title', data.title);
            fd.append('description', data.details);
            fd.append('pages', JSON.stringify(data.pages || []));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Save failed');
            hideEditors();
            await loadCommonIssues();
        } catch (e) { alert('Unable to save common issue.'); }
    }

    async function moveReviewToFinal() {
        if (!issueData.selectedPageId) return;
        var selected = collectSelectedReviewIds();
        if (!selected.length) return;
        try {
            var fd = new FormData();
            fd.append('action', 'move_to_issue'); fd.append('project_id', projectId); fd.append('ids', selected.join(','));
            var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Move failed');
            await loadReviewFindings(issueData.selectedPageId);
            await loadFinalIssues(issueData.selectedPageId);
            updateIssueTabCounts();
        } catch (e) { alert('Unable to move findings to final report.'); }
    }

    async function deleteReviewIds(ids) {
        ids = Array.from(new Set(normalizeIdList(ids)));
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadReviewFindings(issueData.selectedPageId);
        } catch (e) { alert('Unable to delete automated findings.'); }
    }

    async function deleteFinalIds(ids) {
        ids = Array.from(new Set(normalizeIdList(ids)));
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadFinalIssues(issueData.selectedPageId);
            await loadCommonIssues();
        } catch (e) { alert('Unable to delete issues.'); }
    }

    async function deleteCommonIds(ids) {
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'common_delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadCommonIssues();
        } catch (e) { alert('Unable to delete common issues.'); }
    }

    async function deleteSelected(type) {
        if (!issueData.selectedPageId && type !== 'common') return;
        if (type === 'final' || type === 'review') {
            var sel = type === 'review'
                ? collectSelectedReviewIds()
                : Array.from(document.querySelectorAll('.' + type + '-select:checked')).map(function (el) {
                    return el.getAttribute('data-id') || el.value || '';
                });
            sel = Array.from(new Set(normalizeIdList(sel)));
            if (!sel.length) return;
            if (type === 'review') {
                showConfirmDeleteFindings(sel);
            } else {
                await deleteFinalIds(sel);
            }
        } else if (type === 'common') {
            var selC = Array.from(document.querySelectorAll('.common-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
            if (!selC.length) return;
            await deleteCommonIds(selC);
        }
    }

    function showConfirmDeleteFindings(ids) {
        var modalEl = document.getElementById('confirmDeleteFindingsModal');
        if (!modalEl) {
            // Fallback: delete immediately
            deleteReviewIds(ids);
            return;
        }
        // Store ids in dataset for confirm handler
        modalEl.dataset.deleteIds = JSON.stringify(ids);
        var msg = document.getElementById('confirmDeleteFindingsMessage');
        if (msg) msg.textContent = 'Are you sure you want to permanently delete ' + ids.length + ' automated finding' + (ids.length>1?'s':'') + '? This will also remove any associated screenshots.';
        var bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
    }

    // Confirm button handler
    document.addEventListener('DOMContentLoaded', function () {
        var confirmBtn = document.getElementById('confirmDeleteFindingsBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', async function (e) {
                var modalEl = document.getElementById('confirmDeleteFindingsModal');
                try {
                    var ids = JSON.parse(modalEl.dataset.deleteIds || '[]');
                } catch (e) { ids = []; }
                var bs = bootstrap.Modal.getInstance(modalEl);
                if (bs) bs.hide();
                if (ids && ids.length) await deleteReviewIds(ids);
            });
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        try { } catch (e) { }
        initSelect2();
        initEditors();
        loadTemplates();
        loadMetadataOptions();

        // Only attach page click listeners if issues tab is active
        var issuesTab = document.querySelector('#issues');
        if (issuesTab && issuesTab.classList.contains('active')) {
            attachPageClickListeners();
        }

        // Auto-select first page if issues tab is active
        var firstPageBtn = document.querySelector('#issuesPageList .issues-page-row');
        if (firstPageBtn && issuesTab && issuesTab.classList.contains('active')) {
            var pageId = firstPageBtn.getAttribute('data-page-id');
            if (pageId && pageId !== '0') {
                setSelectedPage(firstPageBtn);
            } else {
                var uniqueId = firstPageBtn.getAttribute('data-unique-id');
                if (uniqueId) setSelectedUniquePage(firstPageBtn, uniqueId);
            }
        }
    });

    // Prevent page reload/navigation when there are unsaved changes
    window.addEventListener('beforeunload', function (e) {
        leaveIssuePresenceOnUnload();
        if (hasFormChanges()) {
            e.preventDefault();
            e.returnValue = ''; // Chrome requires returnValue to be set
            return ''; // For older browsers
        }
    });

    // New function for unique page selection
    window.setSelectedUniquePage = function (btn, uniqueId) {
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (b) { b.classList.remove('table-active'); });
        btn.classList.add('table-active');
        // Show details section
        var name = btn.getAttribute('data-page-name') || 'Page';
        var tester = btn.getAttribute('data-page-tester') || '-';
        var env = btn.getAttribute('data-page-env') || '-';
        var issues = btn.getAttribute('data-page-issues') || '0';
        var nameEl = document.getElementById('issueSelectedPageName');
        var metaEl = document.getElementById('issueSelectedPageMeta');
        if (nameEl) nameEl.textContent = name;
        if (metaEl) metaEl.textContent = 'Tester: ' + tester + ' | Env: ' + env + ' | Issues: ' + issues;
        // Show/hide columns
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) pagesCol.classList.add('d-none');
        if (detailCol) {
            detailCol.classList.remove('d-none');
            detailCol.classList.remove('col-lg-8');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.remove('d-none');
        // If we have a mapped page id, load issues for it
        var pageId = btn.getAttribute('data-page-id');
        if (pageId && pageId !== '0') {
            issueData.selectedPageId = pageId;
            ensurePageStore(issueData.pages, issueData.selectedPageId);
            updateEditingState();
            populatePageUrls(issueData.selectedPageId);
            reviewCurrentPage = 1;
            renderAll();
            loadReviewFindings(issueData.selectedPageId);
            loadFinalIssues(issueData.selectedPageId);
        }
    };

    var issuesTabBtn = document.querySelector('button[data-bs-target="#issues"]');
    if (issuesTabBtn) {
        issuesTabBtn.addEventListener('shown.bs.tab', function () {
            attachPageClickListeners();

            if (!issueData.selectedPageId) {
                var fp = document.querySelector('#issuesPageList .issues-page-row');
                if (fp) {
                    var pageId = fp.getAttribute('data-page-id');
                    if (pageId && pageId !== '0') {
                        setSelectedPage(fp);
                    } else {
                        var uniqueId = fp.getAttribute('data-unique-id');
                        if (uniqueId) setSelectedUniquePage(fp, uniqueId);
                    }
                }
            } else {
                updateModeUI();
                renderAll();
                showFinalIssuesTab();
            }
        });
    }

    var addF = document.getElementById('issueAddFinalBtn'); if (addF) addF.addEventListener('click', function () { openFinalEditor(null); });

    var finalIssueModalEl = document.getElementById('finalIssueModal');
    if (finalIssueModalEl) {
        finalIssueModalEl.addEventListener('click', function (e) {
            var dismissBtn = e.target && e.target.closest ? e.target.closest('[data-bs-dismiss="modal"]') : null;
            if (dismissBtn) {
                // Try immediate leave when user explicitly closes modal.
                stopIssuePresenceTracking();
            }
        });
        finalIssueModalEl.addEventListener('hide.bs.modal', function (e) {
            var editId = document.getElementById('finalIssueEditId').value;
            // Check for changes in both NEW and EDIT modes
            if (hasFormChanges()) {
                e.preventDefault();
                e.stopPropagation();

                // Show custom confirmation modal
                showDraftConfirmation(function (action) {
                    if (action === 'save') {
                        // For new issues, save as draft; for edit, save the issue
                        if (!editId) {
                            saveDraft().then(function () {
                                stopDraftAutosave();
                                issueData.initialFormState = null;
                                var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                if (modal) modal.hide();
                            });
                        } else {
                            // For edit mode, trigger save button click
                            document.getElementById('finalIssueSaveBtn').click();
                            // Modal will close after successful save
                        }
                    } else if (action === 'discard') {
                        if (!editId) {
                            deleteDraft().then(function () {
                                stopDraftAutosave();
                                issueData.initialFormState = null;
                                var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                if (modal) modal.hide();
                            });
                        } else {
                            // For edit mode, just close without saving
                            stopDraftAutosave();
                            issueData.initialFormState = null;
                            var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                            if (modal) modal.hide();
                        }
                    }
                    // If action === 'keep', do nothing (modal stays open)
                }, editId);
            } else {
                stopDraftAutosave();
                issueData.initialFormState = null;
                stopIssuePresenceTracking();
            }
        });
        finalIssueModalEl.addEventListener('hidden.bs.modal', function () {
            stopIssuePresenceTracking();
        });
    }
    document.addEventListener('hidden.bs.modal', function (e) {
        if (e && e.target && e.target.id === 'finalIssueModal') {
            stopIssuePresenceTracking();
        }
    });

    // Draft confirmation modal function
    function showDraftConfirmation(callback, editId) {
        var isEditMode = !!editId;
        var saveButtonText = isEditMode ? 'Save Changes' : 'Save Draft';
        var saveButtonIcon = isEditMode ? 'save' : 'file-alt';

        var modalHtml = `
                <div class="modal fade" id="draftConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-warning-subtle">
                                <h5 class="modal-title">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    Unsaved Changes
                                </h5>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0">You have unsaved changes in this issue. What would you like to do?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" id="draftKeepEditing">
                                    <i class="fas fa-edit me-1"></i> Keep Editing
                                </button>
                                <button type="button" class="btn btn-outline-danger" id="draftDiscard">
                                    <i class="fas fa-trash me-1"></i> Discard
                                </button>
                                <button type="button" class="btn btn-primary" id="draftSave">
                                    <i class="fas fa-${saveButtonIcon} me-1"></i> ${saveButtonText}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

        // Remove existing modal if any
        var existing = document.getElementById('draftConfirmModal');
        if (existing) existing.remove();

        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var confirmModal = document.getElementById('draftConfirmModal');
        var bsModal = new bootstrap.Modal(confirmModal);

        // Event listeners
        document.getElementById('draftSave').addEventListener('click', function () {
            bsModal.hide();
            callback('save');
        });

        document.getElementById('draftDiscard').addEventListener('click', function () {
            bsModal.hide();
            callback('discard');
        });

        document.getElementById('draftKeepEditing').addEventListener('click', function () {
            bsModal.hide();
            callback('keep');
        });

        // Cleanup after modal is hidden
        confirmModal.addEventListener('hidden.bs.modal', function () {
            confirmModal.remove();
        });

        bsModal.show();
    }

    var reviewIssueModalEl = document.getElementById('reviewIssueModal');
    if (reviewIssueModalEl) {
        reviewIssueModalEl.addEventListener('hide.bs.modal', function (e) {
            if (reviewIssueBypassCloseConfirm) {
                reviewIssueBypassCloseConfirm = false;
                reviewIssueInitialFormState = null;
                return;
            }
            if (!hasReviewFormChanges()) {
                reviewIssueInitialFormState = null;
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var editId = (document.getElementById('reviewIssueEditId') || {}).value || '';
            showDraftConfirmation(function (action) {
                if (action === 'keep') return;
                if (action === 'save') {
                    if (editId) {
                        var saveBtn = document.getElementById('reviewIssueSaveBtn');
                        if (saveBtn) saveBtn.click();
                    } else {
                        saveReviewDraftLocal();
                        reviewIssueBypassCloseConfirm = true;
                        reviewIssueInitialFormState = null;
                        var instSave = bootstrap.Modal.getOrCreateInstance(reviewIssueModalEl);
                        instSave.hide();
                    }
                    return;
                }
                if (action === 'discard') {
                    clearReviewDraftLocal();
                    reviewIssueBypassCloseConfirm = true;
                    reviewIssueInitialFormState = null;
                    var instDiscard = bootstrap.Modal.getOrCreateInstance(reviewIssueModalEl);
                    instDiscard.hide();
                }
            }, editId);
        });
    }

    var addR = document.getElementById('reviewAddBtn'); if (addR) addR.addEventListener('click', function () { openReviewEditor(null); });
    var runScanBtn = document.getElementById('reviewRunScanBtn');
    if (runScanBtn) runScanBtn.addEventListener('click', function () { showScanConfigModal(); });
    var scanPreviewBtn = document.getElementById('reviewScanOpenIframeBtn');
    if (scanPreviewBtn) {
        scanPreviewBtn.addEventListener('click', function () {
            var url = getPrimaryScanUrlFromModal();
            if (!url) {
                alert('Please select or enter URL first.');
                return;
            }
            if (!/^https?:\/\//i.test(url)) url = 'https://' + url.replace(/^\/+/, '');
            var iframeWrap = document.getElementById('reviewScanIframeWrap');
            var iframe = document.getElementById('reviewScanIframe');
            if (iframeWrap) iframeWrap.classList.remove('d-none');
            if (iframe) iframe.src = url;
        });
    }
    var scanSelectAllBtn = document.getElementById('reviewScanSelectAllBtn');
    if (scanSelectAllBtn) {
        scanSelectAllBtn.addEventListener('click', function () {
            document.querySelectorAll('.review-scan-url-check').forEach(function (el) { el.checked = true; });
        });
    }
    var scanSelectNoneBtn = document.getElementById('reviewScanSelectNoneBtn');
    if (scanSelectNoneBtn) {
        scanSelectNoneBtn.addEventListener('click', function () {
            document.querySelectorAll('.review-scan-url-check').forEach(function (el) { el.checked = false; });
        });
    }
    var scanAddCustomBtn = document.getElementById('reviewScanAddCustomBtn');
    if (scanAddCustomBtn) {
        scanAddCustomBtn.addEventListener('click', function () {
            var input = document.getElementById('reviewScanCustomUrl');
            var checklist = document.getElementById('reviewScanUrlChecklist');
            var raw = String((input && input.value) || '').trim();
            if (!raw) return;
            var url = /^https?:\/\//i.test(raw) ? raw : ('https://' + raw.replace(/^\/+/, ''));
            if (!checklist) return;
            var exists = Array.from(checklist.querySelectorAll('.review-scan-url-check')).some(function (el) {
                return String(el.value || '').trim() === url;
            });
            if (exists) {
                if (input) input.value = '';
                return;
            }
            var id = 'scan-url-custom-' + Date.now();
            var row = document.createElement('div');
            row.className = 'form-check mb-1';
            row.innerHTML = '<input class="form-check-input review-scan-url-check" type="checkbox" checked value="' + escapeAttr(url) + '" id="' + escapeAttr(id) + '">' +
                '<label class="form-check-label" for="' + escapeAttr(id) + '">' + escapeHtml('Custom URL - ' + url) + '</label>';
            checklist.appendChild(row);
            if (input) input.value = '';
        });
    }
    var scanStartBtn = document.getElementById('reviewScanStartBtn');
    if (scanStartBtn) {
        scanStartBtn.addEventListener('click', async function () {
            var urls = getScanUrlsFromModal();
            if (!urls.length) {
                var fallback = getPrimaryScanUrlFromModal();
                if (fallback) urls = [fallback];
            }
            if (!urls.length) {
                alert('Please select at least one URL.');
                return;
            }
            urls = urls.map(function (u) {
                var s = String(u || '').trim();
                return /^https?:\/\//i.test(s) ? s : ('https://' + s.replace(/^\/+/, ''));
            });
            var modalEl = document.getElementById('reviewScanConfigModal');
            if (modalEl) {
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            var modeEl = document.querySelector('input[name="reviewScanRunMode"]:checked');
            var mode = modeEl ? modeEl.value : 'sequential';
            var progressEl = document.getElementById('reviewScanProgress');
            var completed = 0;
            var total = urls.length;
            function paintScanCounter(pagePercent) {
                if (!progressEl) return;
                var current = Math.min(total, completed + 1);
                var pct = (typeof pagePercent === 'number')
                    ? Math.max(0, Math.min(100, Math.round(pagePercent)))
                    : (total > 0 ? Math.round((completed / total) * 100) : 0);
                progressEl.textContent = pct + '% - Scanning page ' + current + '/' + total;
            }
            paintScanCounter();
            try {
                var totalCreated = 0;
                if (mode === 'parallel' && urls.length > 1) {
                    var results = await Promise.all(urls.map(function (u) {
                        return runAutomatedScanForSelectedPage(u, { skipReload: true, silent: true, manageProgressExternally: true })
                            .then(function (v) { completed += 1; paintScanCounter(); return v; })
                            .catch(function () { completed += 1; paintScanCounter(); return 0; });
                    }));
                    totalCreated = results.reduce(function (sum, v) { return sum + Number(v || 0); }, 0);
                } else {
                    for (var i = 0; i < urls.length; i++) {
                        var pagePct = 0;
                        paintScanCounter(pagePct);
                        var ticker = setInterval(function () {
                            pagePct = Math.min(95, pagePct + 4);
                            paintScanCounter(pagePct);
                        }, 300);
                        try {
                            totalCreated += await runAutomatedScanForSelectedPage(urls[i], { skipReload: true, silent: true, manageProgressExternally: true });
                        } finally {
                            clearInterval(ticker);
                        }
                        paintScanCounter(100);
                        completed += 1;
                    }
                }
                await loadReviewFindings(issueData.selectedPageId);
                if (progressEl) {
                    progressEl.textContent = '100% - Completed ' + total + '/' + total;
                    setTimeout(function () { progressEl.textContent = ''; }, 1500);
                }
                if (window.showToast) showToast('Scan complete for ' + urls.length + ' URL(s). ' + totalCreated + ' findings added for review.', 'success');
            } catch (e) {
                if (progressEl) {
                    var stoppedPct = total > 0 ? Math.round((completed / total) * 100) : 0;
                    progressEl.textContent = stoppedPct + '% - Stopped at ' + completed + '/' + total;
                }
                alert('Automated scan failed: ' + (e && e.message ? e.message : 'Unknown error'));
            }
        });
    }
    var saveF = document.getElementById('finalIssueSaveBtn'); if (saveF) {
        saveF.addEventListener('click', addOrUpdateFinalIssue);
    }
    var pageSel = jQuery('#finalIssuePages');
    if (pageSel && pageSel.length) {
        pageSel.on('change', function () { updateGroupedUrls(); toggleCommonTitle(); });
    }

    var tplApply = document.getElementById('finalIssueApplyTemplateBtn');
    if (tplApply) {
        tplApply.addEventListener('click', function (e) {
            e.preventDefault();
            var sel = document.getElementById('finalIssueTemplate');
            var id = sel ? sel.value : '';
            if (!id) return;
            var tpl = itemTemplates.find(function (t) { return String(t.id) === String(id); });
            if (tpl) applyPreset(tpl);
        });
    }

    var addCBtn = document.getElementById('finalIssueAddCommentBtn');
    if (addCBtn) {
        addCBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var id = document.getElementById('finalIssueEditId').value || 'new';
            addIssueComment(String(id));
        });
    }

    var resetBtn = document.getElementById('btnResetToTemplate');
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            e.preventDefault();
            resetToTemplateWithConfirm();
        });
    }

    // Fallback delegated handler: keeps reset working even if button is re-rendered/cloned later.
    if (!window.__issueResetTemplateDelegatedBound) {
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#btnResetToTemplate') : null;
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            resetToTemplateWithConfirm();
        }, true);
        window.__issueResetTemplateDelegatedBound = true;
    }

    var historyBtn = document.getElementById('btnShowHistory');
    if (historyBtn) {
        historyBtn.addEventListener('shown.bs.tab', function () {
            var id = document.getElementById('finalIssueEditId').value;
            if (!id) {
                document.getElementById('historyEntries').innerHTML = '<div class="text-center py-4 text-muted">No history for new issues.</div>';
                return;
            }
            fetch(ProjectConfig.baseDir + '/api/issue_history.php?issue_id=' + id, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    var wrap = document.getElementById('historyEntries');
                    if (!wrap || !res || !res.history) return;
                    if (!res.history.length) { wrap.innerHTML = '<div class="text-center py-4 text-muted">No edits recorded yet.</div>'; return; }

                    // Helper function to strip HTML tags and preserve spacing
                    var stripHtml = function (html) {
                        if (!html) return '';
                        html = String(html);
                        // Preserve images in history diff (add/delete should be visible).
                        html = html.replace(/<img\b[^>]*>/gi, function (tag) {
                            var alt = '';
                            var src = '';
                            var altMatch = tag.match(/\balt\s*=\s*["']([^"']*)["']/i);
                            var srcMatch = tag.match(/\bsrc\s*=\s*["']([^"']*)["']/i);
                            if (altMatch && altMatch[1]) alt = altMatch[1].trim();
                            if (srcMatch && srcMatch[1]) src = srcMatch[1].trim();
                            var label = alt || (src ? src.split('/').pop() : 'image');
                            return ' [Image: ' + label + '] ';
                        });
                        var tmp = document.createElement('div');
                        // Preserve line breaks from rich text blocks
                        html = html.replace(/<br\s*\/?>/gi, '\n');
                        html = html.replace(/<\/(p|div|h[1-6]|li|tr|td|th)>/gi, '\n');
                        tmp.innerHTML = html;
                        var text = tmp.textContent || tmp.innerText || '';
                        text = text
                            .replace(/\r\n/g, '\n')
                            .replace(/\r/g, '\n')
                            .replace(/[ \t]+\n/g, '\n')
                            .replace(/\n[ \t]+/g, '\n')
                            .replace(/[ \t]{2,}/g, ' ')
                            .replace(/\n{3,}/g, '\n\n')
                            .trim();
                        return text;
                    };

                    wrap.innerHTML = res.history.map(function (h, idx) {
                        var oldVal = h.old_value || '';
                        var newVal = h.new_value || '';
                        var fieldName = h.field_name || 'field';
                        var uniqueId = 'history-' + idx;

                        // Format field name: remove "meta:" prefix and format nicely
                        var displayFieldName = fieldName;
                        if (fieldName.startsWith('meta:')) {
                            displayFieldName = fieldName.substring(5); // Remove "meta:"
                        }
                        // Format: qa_status → QA Status, severity → Severity
                        displayFieldName = displayFieldName.split('_').map(function (word) {
                            return word.charAt(0).toUpperCase() + word.slice(1);
                        }).join(' ');

                        // Format QA status values if it's qa_status field
                        if (fieldName === 'meta:qa_status' || fieldName === 'qa_status') {
                            var formatQaStatusValue = function (raw) {
                                if (!raw) return '';
                                var values = [];
                                try {
                                    var parsed = JSON.parse(raw);
                                    if (Array.isArray(parsed)) {
                                        values = parsed;
                                    }
                                } catch (e) { }
                                if (!values.length) {
                                    values = String(raw).split(',').map(function (v) { return String(v).trim(); }).filter(Boolean);
                                }
                                return values.map(function (v) {
                                    return v.split('_').map(function (w) {
                                        if (!w) return '';
                                        return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                    }).join(' ');
                                }).join(', ');
                            };
                            // Format old value
                            if (oldVal) {
                                oldVal = formatQaStatusValue(oldVal);
                            }
                            // Format new value
                            if (newVal) {
                                newVal = formatQaStatusValue(newVal);
                            }
                        }

                        // For description field, create inline diff view
                        if (fieldName === 'description') {
                            var oldText = stripHtml(oldVal);
                            var newText = stripHtml(newVal);

                            // If texts are identical, show a message
                            if (oldText.trim() === newText.trim()) {
                                return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                    '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                    '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                    '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                    '</small>' +
                                    '<small class="text-muted">' +
                                    '<i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • ' +
                                    '<i class="fas fa-clock me-1"></i>' + h.created_at +
                                    '</small>' +
                                    '</div>' +
                                    '<div class="alert alert-info mb-0">' +
                                    '<i class="fas fa-info-circle me-2"></i>No visible changes detected (possibly formatting or whitespace changes)' +
                                    '</div>' +
                                    '</div>';
                            }

                            // Split by words and spaces, keeping delimiters
                            var oldWords = oldText.split(/(\s+)/);
                            var newWords = newText.split(/(\s+)/);

                            // LCS-based diff algorithm
                            var lcs = function (arr1, arr2) {
                                var m = arr1.length;
                                var n = arr2.length;
                                var dp = [];

                                // Initialize DP table
                                for (var i = 0; i <= m; i++) {
                                    dp[i] = [];
                                    for (var j = 0; j <= n; j++) {
                                        dp[i][j] = 0;
                                    }
                                }

                                // Fill DP table
                                for (var i = 1; i <= m; i++) {
                                    for (var j = 1; j <= n; j++) {
                                        if (arr1[i - 1] === arr2[j - 1]) {
                                            dp[i][j] = dp[i - 1][j - 1] + 1;
                                        } else {
                                            dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                                        }
                                    }
                                }

                                // Backtrack to find LCS
                                var result = [];
                                var i = m, j = n;
                                while (i > 0 && j > 0) {
                                    if (arr1[i - 1] === arr2[j - 1]) {
                                        result.unshift({ type: 'common', value: arr1[i - 1], oldIdx: i - 1, newIdx: j - 1 });
                                        i--;
                                        j--;
                                    } else if (dp[i - 1][j] > dp[i][j - 1]) {
                                        i--;
                                    } else {
                                        j--;
                                    }
                                }

                                return result;
                            };

                            // Get LCS
                            var common = lcs(oldWords, newWords);

                            // Build diff HTML
                            var diffHtml = '';
                            var oldIdx = 0;
                            var newIdx = 0;

                            for (var k = 0; k < common.length; k++) {
                                var item = common[k];

                                // Add removed words before this common word
                                if (oldIdx < item.oldIdx) {
                                    var removedText = '';
                                    while (oldIdx < item.oldIdx) {
                                        removedText += escapeHtml(oldWords[oldIdx]);
                                        oldIdx++;
                                    }
                                    diffHtml += '<span style="background-color:#ffd7d5;color:#d73a49;text-decoration:line-through;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + removedText + '</span>';
                                }

                                // Add added words before this common word
                                if (newIdx < item.newIdx) {
                                    var addedText = '';
                                    while (newIdx < item.newIdx) {
                                        addedText += escapeHtml(newWords[newIdx]);
                                        newIdx++;
                                    }
                                    diffHtml += '<span style="background-color:#d4edda;color:#28a745;font-weight:600;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + addedText + '</span>';
                                }

                                // Add the common word
                                diffHtml += escapeHtml(item.value);
                                oldIdx++;
                                newIdx++;
                            }

                            // Add remaining removed words
                            if (oldIdx < oldWords.length) {
                                var removedText = '';
                                while (oldIdx < oldWords.length) {
                                    removedText += escapeHtml(oldWords[oldIdx]);
                                    oldIdx++;
                                }
                                diffHtml += '<span style="background-color:#ffd7d5;color:#d73a49;text-decoration:line-through;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + removedText + '</span>';
                            }

                            // Add remaining added words
                            if (newIdx < newWords.length) {
                                var addedText = '';
                                while (newIdx < newWords.length) {
                                    addedText += escapeHtml(newWords[newIdx]);
                                    newIdx++;
                                }
                                diffHtml += '<span style="background-color:#d4edda;color:#28a745;font-weight:600;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + addedText + '</span>';
                            }

                            // Ensure diffHtml is valid
                            if (!diffHtml || diffHtml.trim() === '') {
                                diffHtml = escapeHtml(newText); // Fallback
                            }

                            // Show highlighted diff by default (no truncation), so removed/added
                            // formatting is visible without clicking "Read More".
                            var preview = diffHtml;
                            var needsExpand = false;

                            var oldDisplay = stripHtml(oldVal || 'N/A');
                            var newDisplay = stripHtml(newVal || 'N/A');
                            return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                '</small>' +
                                '<small class="text-muted">' +
                                '<i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • ' +
                                '<i class="fas fa-clock me-1"></i>' + h.created_at +
                                '</small>' +
                                '</div>' +
                                '<div class="diff-container bg-white p-3 rounded border" style="line-height: 1.8;">' +
                                '<div class="diff-preview" id="preview-' + uniqueId + '" style="white-space: pre-wrap; word-wrap: break-word;">' +
                                preview +
                                '</div>' +
                                (needsExpand ?
                                    '<div class="diff-full" id="full-' + uniqueId + '" style="display:none;white-space:pre-wrap;word-wrap:break-word;line-height:1.8;">' +
                                    diffHtml +
                                    '</div>' +
                                    '<button class="btn btn-sm btn-outline-primary mt-2" onclick="toggleHistoryDiff(\'' + uniqueId + '\', event)">' +
                                    '<i class="fas fa-chevron-down me-1"></i>' +
                                    '<span class="toggle-text">Read More</span>' +
                                    '</button>'
                                    : '') +
                                '</div>' +
                                '<div class="mt-2 small">' +
                                '<span class="badge bg-danger-subtle text-danger me-2">' +
                                '<i class="fas fa-minus me-1"></i>Removed' +
                                '</span>' +
                                '<span class="badge bg-success-subtle text-success">' +
                                '<i class="fas fa-plus me-1"></i>Added' +
                                '</span>' +
                                '</div>' +
                                '</div>';
                        } else {
                            // For other fields, simple before/after
                            return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                '</small>' +
                                '<small class="text-muted">' +
                                '<i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • ' +
                                '<i class="fas fa-clock me-1"></i>' + h.created_at +
                                '</small>' +
                                '</div>' +
                                '<div class="row g-2 bg-white p-3 rounded border">' +
                                '<div class="col-md-5">' +
                                '<div class="small text-muted mb-1 fw-bold">Before:</div>' +
                                '<div class="p-2 bg-danger-subtle text-danger rounded border border-danger" style="white-space:pre-wrap;">' +
                                '<small>' + escapeHtml(oldDisplay) + '</small>' +
                                '</div>' +
                                '</div>' +
                                '<div class="col-md-2 d-flex align-items-center justify-content-center">' +
                                '<i class="fas fa-arrow-right text-primary fs-4"></i>' +
                                '</div>' +
                                '<div class="col-md-5">' +
                                '<div class="small text-muted mb-1 fw-bold">After:</div>' +
                                '<div class="p-2 bg-success-subtle text-success rounded border border-success" style="white-space:pre-wrap;">' +
                                '<small>' + escapeHtml(newDisplay) + '</small>' +
                                '</div>' +
                                '</div>' +
                                '</div>' +
                                '</div>';
                        }
                    }).join('');

                    // Add toggle function to window scope
                    window.toggleHistoryDiff = function (id, event) {
                        var preview = document.getElementById('preview-' + id);
                        var full = document.getElementById('full-' + id);
                        var btn = event.target.closest('button');
                        var icon = btn.querySelector('i');
                        var text = btn.querySelector('.toggle-text');

                        if (full.style.display === 'none' || full.style.display === '') {
                            preview.style.display = 'none';
                            full.style.display = 'block';
                            icon.className = 'fas fa-chevron-up me-1';
                            text.textContent = 'Read Less';
                        } else {
                            preview.style.display = 'block';
                            full.style.display = 'none';
                            icon.className = 'fas fa-chevron-down me-1';
                            text.textContent = 'Read More';
                        }
                    };
                });
        });
    }

    function formatVisitDuration(seconds, openedAt, closedAt) {
        if (!closedAt) return 'In progress';
        var sec = parseInt(seconds || 0, 10);
        if (!isFinite(sec) || sec < 0) sec = 0;
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        if (h > 0) return h + 'h ' + m + 'm ' + s + 's';
        if (m > 0) return m + 'm ' + s + 's';
        return s + 's';
    }

    function renderVisitHistory(entries) {
        var wrap = document.getElementById('visitHistoryEntries');
        if (!wrap) return;
        if (!Array.isArray(entries) || !entries.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">No visit history recorded yet.</div>';
            return;
        }
        wrap.innerHTML = entries.map(function (e) {
            var opened = e.opened_at ? new Date(e.opened_at).toLocaleString() : '-';
            var closed = e.closed_at ? new Date(e.closed_at).toLocaleString() : 'Still open';
            var duration = formatVisitDuration(e.duration_seconds, e.opened_at, e.closed_at);
            return '<div class="border rounded p-2 mb-2 bg-white">' +
                '<div class="fw-bold">' + escapeHtml(e.full_name || 'User') + '</div>' +
                '<div><span class="text-muted">Opened:</span> ' + escapeHtml(opened) + '</div>' +
                '<div><span class="text-muted">Closed:</span> ' + escapeHtml(closed) + '</div>' +
                '<div><span class="text-muted">Duration:</span> ' + escapeHtml(duration) + '</div>' +
                '</div>';
        }).join('');
        updateIssueTabCounts();
    }

    var visitBtn = document.getElementById('btnShowVisitHistory');
    if (visitBtn) {
        visitBtn.addEventListener('shown.bs.tab', function () {
            var wrap = document.getElementById('visitHistoryEntries');
            if (wrap) wrap.innerHTML = '<div class="text-center py-4 text-muted">Loading visit history...</div>';
            var id = document.getElementById('finalIssueEditId').value;
            if (!id) {
                if (wrap) wrap.innerHTML = '<div class="text-center py-4 text-muted">No visit history for new issues.</div>';
                return;
            }
            var url = issuesApiBase + '?action=presence_session_list&project_id=' + encodeURIComponent(projectId) + '&issue_id=' + encodeURIComponent(id);
            fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    if (!res || !res.success) {
                        renderVisitHistory([]);
                        return;
                    }
                    renderVisitHistory(res.sessions || []);
                })
                .catch(function () { renderVisitHistory([]); });
        });
    }

    var chatBtn = document.getElementById('btnShowChat');
    if (chatBtn) {
        chatBtn.addEventListener('shown.bs.tab', function () {
            var id = document.getElementById('finalIssueEditId').value || 'new';
            renderIssueComments(String(id));
            if (window.jQuery && jQuery.fn.summernote) { jQuery('#finalIssueCommentEditor').summernote('code', jQuery('#finalIssueCommentEditor').summernote('code')); }
        });
    }

    var finalSubTabBtn = document.getElementById('final-issues-tab');
    if (finalSubTabBtn) { finalSubTabBtn.addEventListener('shown.bs.tab', function () { renderFinalIssues(); }); }

    document.addEventListener('click', function (e) {
        var target = e.target;
        if (target && target.classList && target.classList.contains('issue-image-thumb')) {
            e.preventDefault();
            var src = target.getAttribute('src');
            if (src) openIssueImageModal(src);
        }
    });

    document.addEventListener('shown.bs.collapse', function (e) {
        var id = e.target && e.target.id ? e.target.id : '';
        if (!id) return;
        var btn = document.querySelector('[data-bs-target="#' + id + '"]');
        if (btn && btn.classList.contains('issue-url-toggle')) { btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
    });
    document.addEventListener('hidden.bs.collapse', function (e) {
        var id = e.target && e.target.id ? e.target.id : '';
        if (!id) return;
        var btn = document.querySelector('[data-bs-target="#' + id + '"]');
        if (btn && btn.classList.contains('issue-url-toggle')) { btn.innerHTML = '<i class="fas fa-globe"></i>'; }
    });

    var saveR = document.getElementById('reviewIssueSaveBtn'); if (saveR) saveR.addEventListener('click', addOrUpdateReviewIssue);
    var moveInModalBtn = document.getElementById('reviewIssueMoveToFinalBtn');
    if (moveInModalBtn) moveInModalBtn.addEventListener('click', moveCurrentReviewIssueToFinal);

    // Use event delegation for commonAddBtn to handle dynamic loading
    document.addEventListener('click', function (e) {
        var target = e.target;
        var commonBtn = target.closest('#commonAddBtn');

        if (commonBtn || (target && target.id === 'commonAddBtn')) {
            e.preventDefault();
            e.stopPropagation();
            openCommonEditor(null);
        }
    });

    var saveCom = document.getElementById('commonIssueSaveBtn'); if (saveCom) saveCom.addEventListener('click', addOrUpdateCommonIssue);
    var backBtn = document.getElementById('issuesBackBtn'); if (backBtn) backBtn.addEventListener('click', showIssuesPages);

    var delF = document.getElementById('finalDeleteSelected'); if (delF) delF.addEventListener('click', function () {
        if (typeof confirmModal === 'function') {
            confirmModal('Delete selected issues? This action cannot be undone.', function () { deleteSelected('final'); });
        } else {
            if (confirm('Delete selected issues?')) deleteSelected('final');
        }
    });
    var delR = document.getElementById('reviewDeleteSelected'); if (delR) delR.addEventListener('click', function () {
        if (typeof confirmModal === 'function') {
            confirmModal('Delete selected findings? This action cannot be undone.', function () { deleteSelected('review'); });
        } else {
            if (confirm('Delete selected findings?')) deleteSelected('review');
        }
    });
    var movR = document.getElementById('reviewMoveSelected'); if (movR) movR.addEventListener('click', moveReviewToFinal);
    var reviewPagination = document.getElementById('reviewPagination');
    if (reviewPagination) {
        reviewPagination.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-review-page]') : null;
            if (!btn || btn.disabled) return;
            var action = btn.getAttribute('data-review-page');
            if (action === 'prev') reviewCurrentPage = Math.max(1, reviewCurrentPage - 1);
            if (action === 'next') reviewCurrentPage = reviewCurrentPage + 1;
            renderReviewIssues();
        });
    }

    ['common', 'final', 'review'].forEach(function (t) {
        var c = document.getElementById(t + 'SelectAll');
        if (c) c.addEventListener('change', function (e) {
            document.querySelectorAll('.' + t + '-select').forEach(function (cb) { cb.checked = e.target.checked; });
            updateSelectionButtons();
        });
        var body = document.getElementById(t + 'IssuesBody');
        if (body) {
            body.addEventListener('change', updateSelectionButtons);
            body.addEventListener('click', function (e) {
                var target = e.target.closest('.' + t + '-edit, .' + t + '-delete, .issue-open');
                if (!target) return;

                var id = target.getAttribute('data-id');
                var idsCsv = target.getAttribute('data-ids') || id;

                    if (target.classList.contains(t + '-edit') || target.classList.contains('issue-open')) {
                        if (t === 'final') { 
                            var i = issueData.pages[issueData.selectedPageId].final.find(function (x) { return String(x.id) === id; }); 
                            openFinalEditor(i);
                        }
                        if (t === 'review') {
                            var reviewIssue = buildReviewEditIssueFromIds(idsCsv);
                            openReviewEditor(reviewIssue);
                        }
                    if (t === 'common') {
                        var i = issueData.common.find(function (x) { return String(x.id) === id; });

                        if (i) {
                            var actualIssueId = i.issue_id;

                            if (i.pages && i.pages.length > 0) {
                                var firstPageId = i.pages[0];

                                ensurePageStore(issueData.pages, firstPageId);
                                issueData.selectedPageId = firstPageId;

                                if (!issueData.pages[firstPageId].final || issueData.pages[firstPageId].final.length === 0) {
                                    loadFinalIssues(firstPageId).then(function () {
                                        var finalIssue = issueData.pages[firstPageId].final.find(function (x) {
                                            return String(x.id) === String(actualIssueId);
                                        });

                                        if (finalIssue) {
                                            openFinalEditor(finalIssue);
                                        }
                                    }).catch(function (error) {
                                        console.error('Error loading issues:', error);
                                    });
                                } else {
                                    var finalIssue = issueData.pages[firstPageId].final.find(function (x) {
                                        return String(x.id) === String(actualIssueId);
                                    });

                                    if (finalIssue) {
                                        openFinalEditor(finalIssue);
                                    }
                                }
                            }
                        }
                    }
                } else if (target.classList.contains(t + '-delete')) {
                    if (typeof confirmModal === 'function') {
                        confirmModal('Delete this item? This action cannot be undone.', function () {
                            if (t === 'final') deleteFinalIds([id]);
                            if (t === 'review') deleteReviewIds([id]);
                            if (t === 'common') deleteCommonIds([id]);
                        });
                    } else {
                        if (confirm('Delete this item?')) {
                            if (t === 'final') deleteFinalIds([id]);
                            if (t === 'review') deleteReviewIds([id]);
                            if (t === 'common') deleteCommonIds([id]);
                        }
                    }
                }
            });
        }
    });

    if (finalIssueModalEl) {
        finalIssueModalEl.addEventListener('shown.bs.modal', function () {
            // No auto-focus - let modal container handle focus

            // Legacy code for old select field (if it exists)
            var sel = document.getElementById('finalIssueTitle');
            if (sel) {
                sel.disabled = false;
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery('#finalIssueTitle').prop('disabled', false).trigger('change.select2');
                }
            }
        });
    }

    initSelect2();
    initUrlSelectionModal();
    updateUrlSelectionSummary();
    updateGroupedUrlsPreview();
    initSummernote();
    loadCommonIssues();
    startLiveIssueSync();

    // Define editFinalIssue for table edit buttons
    window.editFinalIssue = function (id) {
        var issue = issueData.pages[issueData.selectedPageId].final.find(function (i) { return String(i.id) === String(id); });
        if (issue) openFinalEditor(issue);
    };

    // Expose necessary functions globally for external pages
    window.loadFinalIssues = loadFinalIssues;
    window.loadReviewFindings = loadReviewFindings;
    window.updateEditingState = updateEditingState;
    window.loadCommonIssues = loadCommonIssues;
    window.openFinalEditor = openFinalEditor;
})(); // IIFE invocation - this actually executes the function














