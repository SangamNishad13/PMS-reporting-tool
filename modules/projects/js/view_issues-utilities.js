/**
 * view_issues-utilities.js
 * Helper functions, data processing, and utility methods
 */

window.IssuesUtilities = (function() {
    'use strict';

    // Modal utilities
    var modalUtils = {
        cleanupModalOverlayState: function() {
            if (document.querySelectorAll('.modal.show').length > 0) return;
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.body.removeAttribute('aria-hidden');
            document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
            
            // Remove any stale ARIA hidden attributes from common layouts
            document.querySelectorAll('.main-wrapper, .page-wrapper').forEach(function(el) {
                el.removeAttribute('aria-hidden');
            });
        },

        clearIssueConflictNotice: function() {
            var existing = document.getElementById('finalIssueConflictNotice');
            if (existing) existing.remove();
        },

        showIssueConflictNotice: function(message) {
            var modalBody = document.querySelector('#finalIssueModal .modal-body');
            if (!modalBody) return;
            this.clearIssueConflictNotice();
            var box = document.createElement('div');
            box.id = 'finalIssueConflictNotice';
            box.className = 'alert alert-warning d-flex align-items-start gap-2 mb-3';
            box.setAttribute('role', 'alert');
            box.innerHTML =
                '<i class="fas fa-exclamation-triangle mt-1" aria-hidden="true"></i>' +
                '<div><strong>Issue Updated By Another User.</strong><br>' +
                IssuesCore.utils.escapeHtml(message || 'Latest data has been loaded. Please review and save again.') +
                '</div>';
            modalBody.insertBefore(box, modalBody.firstChild);
        },

        showIssueConflictDialog: function(message, onOk) {
            var existing = document.getElementById('issueConflictModal');
            if (existing) {
                try {
                    var existingInst = bootstrap.Modal.getInstance(existing);
                    if (existingInst) existingInst.dispose();
                } catch (e) { }
                existing.remove();
            }

            var html = '' +
                '<div class="modal fade" id="issueConflictModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">' +
                '  <div class="modal-dialog modal-dialog-centered">' +
                '    <div class="modal-content">' +
                '      <div class="modal-header bg-warning-subtle">' +
                '        <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Conflict Detected</h5>' +
                '      </div>' +
                '      <div class="modal-body">' +
                '        <p class="mb-0">' + IssuesCore.utils.escapeHtml(message || 'This issue was modified by another user. Latest data has been loaded. Please review and save again.') + '</p>' +
                '      </div>' +
                '      <div class="modal-footer">' +
                '        <button type="button" class="btn btn-primary" id="issueConflictOkBtn">OK</button>' +
                '      </div>' +
                '    </div>' +
                '  </div>' +
                '</div>';

            document.body.insertAdjacentHTML('beforeend', html);
            var modalEl = document.getElementById('issueConflictModal');
            var okBtn = document.getElementById('issueConflictOkBtn');
            var modal = new bootstrap.Modal(modalEl);

            okBtn.addEventListener('click', function () {
                modal.hide();
                if (typeof onOk === 'function') onOk();
            });
            modalEl.addEventListener('hidden.bs.modal', function () {
                modalEl.remove();
                this.cleanupModalOverlayState();
            }.bind(this));
            modal.show();
        }
    };

    // Instance and code processing utilities
    var instanceUtils = {
        cleanInstanceValue: function(raw) {
            var txt = String(raw || '').trim();
            if (!txt) return '';
            var lower = txt.toLowerCase();
            
            // Remove common prefixes
            var prefixes = ['path:', 'instance:', 'element:'];
            prefixes.forEach(function(prefix) {
                if (lower.startsWith(prefix)) {
                    txt = txt.substring(prefix.length).trim();
                    lower = txt.toLowerCase();
                }
            });
            
            // Split by pipe and clean
            var parts = txt.split('|').map(function(part) {
                return part.trim();
            }).filter(function(part) {
                return part.length > 0;
            });
            
            // Extract meaningful parts
            var extracted = [];
            parts.forEach(function(part) {
                var lowerPart = part.toLowerCase();
                if (!lowerPart.startsWith('path:') && 
                    !lowerPart.startsWith('instance:') && 
                    !lowerPart.startsWith('element:')) {
                    extracted.push(part);
                }
            });
            
            return extracted.join(' | ');
        },

        parseInstanceParts: function(instanceRaw) {
            var v = String(instanceRaw || '').trim();
            if (!v) return { name: '', path: '' };
            var parts = v.split('|');
            if (parts.length >= 2) {
                return { name: parts[0].trim(), path: parts.slice(1).join('|').trim() };
            }
            return { name: '', path: v };
        },

        extractLabelFromIncorrectCode: function(codeHtml) {
            var raw = String(codeHtml || '').trim();
            if (!raw) return '';
            var tmp = document.createElement('div');
            tmp.innerHTML = raw;
            var txt = tmp.textContent || tmp.innerText || '';
            tmp.remove();
            return txt;
        },

        enrichInstanceWithName: function(instanceRaw, incorrectCode) {
            var cleaned = this.cleanInstanceValue(instanceRaw || '');
            var parsed = this.parseInstanceParts(cleaned);
            if (parsed.name) return parsed.name + ' | ' + parsed.path;
            
            // Try to extract name from incorrect code
            var inferred = this.extractLabelFromIncorrectCode(incorrectCode);
            if (inferred && inferred.length < 100) {
                return inferred + ' | ' + (parsed.path || '');
            }
            
            return parsed.path || '';
        },

        formatInstanceReadable: function(instanceRaw) {
            var p = this.parseInstanceParts(instanceRaw);
            var pathText = String(p.path || '').trim();
            if (/^path\s*:/i.test(pathText)) pathText = pathText.replace(/^path\s*:/i, '').trim();
            if (p.name && pathText && p.name !== pathText) {
                return p.name + ' (' + pathText + ')';
            }
            return p.name || '';
        }
    };

    // Text processing utilities
    var textUtils = {
        formatFailureSummaryText: function(raw) {
            var s = String(raw || '').trim();
            if (!s) return '';
            s = s.replace(/Fix any of the following:\s*/ig, '');
            s = s.replace(/^\s*[-•*]\s*/gm, '');
            return s.trim();
        },

        normalizeIncorrectCodeList: function(incorrectCodes, fallbackBad) {
            var codeList = Array.isArray(incorrectCodes) ? 
                incorrectCodes.filter(function (x) { return String(x || '').trim() !== ''; }) : [];
            if (!codeList.length && fallbackBad) codeList = [fallbackBad];
            
            var uniq = [];
            var seen = {};
            codeList.forEach(function(code) {
                var normalized = String(code || '').trim();
                if (normalized && !seen[normalized]) {
                    seen[normalized] = true;
                    uniq.push(normalized);
                }
            });
            
            return uniq;
        },

        cleanIncorrectCodeSnippet: function(raw) {
            var s = String(raw || '').trim();
            if (!s) return '';
            s = s.replace(/^\s*(<\/strong><\/p>\s*)+/ig, '');
            s = s.replace(/(\s*<p>\s*<strong>\s*\[)?\s*(Screenshots|Recommendation)\s*\]\s*<\/strong>\s*<\/p>\s*$/ig, '');
            return String(s || '').trim();
        },

        extractIncorrectCodeSnippets: function(raw) {
            var src = String(raw || '').trim();
            if (!src) return [];
            var out = [];
            
            // Pattern 1: <p><strong>[Incorrect Code]</strong></p>...content...</p>
            var pattern1 = /<p>\s*<strong>\s*\[Incorrect\s+Code\]\s*<\/strong>\s*<\/p>([\s\S]*?)(?=<p>\s*<strong>\s*\[(Screenshots|Recommendation)\]\s*<\/strong>\s*<\/p>|$)/ig;
            var match1;
            while ((match1 = pattern1.exec(src)) !== null) {
                var cleaned = this.cleanIncorrectCodeSnippet(match1[1]);
                if (cleaned) out.push(cleaned);
            }
            
            // Pattern 2: <code class="issue-incorrect-code">...</code>
            var pattern2 = /<code[^>]*class="[^"]*issue-incorrect-code[^"]*"[^>]*>([\s\S]*?)<\/code>/ig;
            var match2;
            while ((match2 = pattern2.exec(src)) !== null) {
                var cleaned = this.cleanIncorrectCodeSnippet(match2[1]);
                if (cleaned && out.indexOf(cleaned) === -1) out.push(cleaned);
            }
            
            // Pattern 3: Pre tags with specific class
            var pattern3 = /<pre[^>]*class="[^"]*issue-incorrect-code[^"]*"[^>]*>([\s\S]*?)<\/pre>/ig;
            var match3;
            while ((match3 = pattern3.exec(src)) !== null) {
                var cleaned = this.cleanIncorrectCodeSnippet(match3[1]);
                if (cleaned && out.indexOf(cleaned) === -1) out.push(cleaned);
            }
            
            return out;
        },

        renderIncorrectCodeBlocks: function(codeList) {
            if (!Array.isArray(codeList) || !codeList.length) return '<code class="issue-incorrect-code"></code>';
            return codeList.map(function (c) {
                var safe = IssuesCore.utils.escapeHtml(String(c || '')).replace(/\n/g, '<br>');
                return '<code class="issue-incorrect-code">' + safe + '</code>';
            }).join('');
        },

        injectIncorrectCodeBlocksIntoSectionedRaw: function(raw, codeList) {
            var text = String(raw || '');
            if (!text) return text;
            var blocks = this.renderIncorrectCodeBlocks(codeList);
            var placeholder = '<code class="issue-incorrect-code"></code>';
            return text.replace(placeholder, blocks);
        },

        extractIncorrectCodeSectionRaw: function(raw) {
            var text = String(raw || '');
            if (!text) return '';
            var mHtml = text.match(/<p[^>]*>\s*<strong>\s*\[Incorrect Code\]\s*<\/strong>\s*<\/p>([\s\S]*?)<p[^>]*>\s*<strong>\s*\[(Screenshots|Recommendation)\]\s*<\/strong>\s*<\/p>/i);
            return mHtml ? mHtml[1] : '';
        },

        normalizeReviewDetailsForEditor: function(raw) {
            var text = String(raw || '');
            if (!text) return text;
            if (!/\[Incorrect Code\]/i.test(text)) return text;
            
            var codeList = this.extractIncorrectCodeSnippets(text);
            return this.injectIncorrectCodeBlocksIntoSectionedRaw(text, codeList);
        },

        wrapReviewDetailsWithMeta: function(detailsHtml, title, meta) {
            var raw = String(detailsHtml || '');
            // Clean known broken leading fragments injected by previous malformed saves.
            raw = raw.replace(/^(\s*<\/strong><\/p>\s*)+/, '');
            var metaHtml = '';
            if (meta && typeof meta === 'object') {
                var parts = [];
                if (meta.instance) parts.push('Instance: ' + IssuesCore.utils.escapeHtml(meta.instance));
                if (meta.checker && meta.checker !== 'unknown') parts.push('Checker: ' + IssuesCore.utils.escapeHtml(meta.checker));
                if (meta.severity && meta.severity !== 'unknown') parts.push('Severity: ' + IssuesCore.utils.escapeHtml(meta.severity));
                if (parts.length > 0) {
                    metaHtml = '<div class="review-meta">' + parts.join(' | ') + '</div>';
                }
            }
            var titleHtml = title ? '<h6>' + IssuesCore.utils.escapeHtml(title) + '</h6>' : '';
            return titleHtml + metaHtml + raw;
        }
    };

    // Public API
    return {
        modalUtils: modalUtils,
        instanceUtils: instanceUtils,
        textUtils: textUtils
    };
})();
