/**
 * view_production.js
 * Handles production hours logging and breakdown logic.
 */

(function () {
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
    var baseDir = window.ProjectConfig ? window.ProjectConfig.baseDir : '';
    var userId = window.ProjectConfig ? window.ProjectConfig.userId : 0;

    function updateTopHoursSummary(summary) {
        if (!summary) return;
        var budget = parseFloat(summary.total_hours || 0);
        if (!(budget > 0)) budget = parseFloat(summary.allocated_hours || 0);
        var used = parseFloat(summary.utilized_hours || 0);
        var remaining = budget - used;
        var overshoot = remaining < 0 ? Math.abs(remaining) : 0;
        var percent = budget > 0 ? (used / budget) * 100 : 0;
        var isOvershoot = overshoot > 0;

        var $budget = jQuery('#hoursSummaryBudget');
        var $used = jQuery('#hoursSummaryUsed');
        var $remaining = jQuery('#hoursSummaryRemaining');
        var $remainingLabel = jQuery('#hoursSummaryRemainingLabel');
        var $usedBar = jQuery('#hoursSummaryUsedBar');
        var $overBar = jQuery('#hoursSummaryOverBar');
        var $percentText = jQuery('#hoursSummaryPercentText');
        var $overText = jQuery('#hoursSummaryOverText');

        if ($budget.length) $budget.text(budget.toFixed(1));
        if ($used.length) {
            $used.text(used.toFixed(1));
            $used.removeClass('text-success text-danger').addClass(isOvershoot ? 'text-danger' : 'text-success');
        }
        if ($remaining.length) {
            $remaining.text((isOvershoot ? overshoot : remaining).toFixed(1));
            $remaining.removeClass('text-warning text-danger').addClass(isOvershoot ? 'text-danger' : 'text-warning');
        }
        if ($remainingLabel.length) $remainingLabel.text(isOvershoot ? 'Overshoot' : 'Remaining');

        if ($usedBar.length) {
            $usedBar.css('width', (budget > 0 ? Math.max(0, Math.min(100, percent)) : 0) + '%');
            $usedBar.attr('title', 'Used: ' + used.toFixed(1) + ' hours');
        }
        if ($overBar.length) {
            $overBar.css('width', (isOvershoot && budget > 0 ? (overshoot / budget) * 100 : 0) + '%');
            $overBar.attr('title', 'Overshoot: ' + overshoot.toFixed(1) + ' hours');
            $overBar.toggleClass('d-none', !isOvershoot);
        }
        if ($percentText.length) {
            $percentText.contents().filter(function () { return this.nodeType === 3; }).remove();
            $percentText.prepend(percent.toFixed(1) + '% used ');
        }
        if ($overText.length) {
            if (isOvershoot) {
                $overText.text('(' + overshoot.toFixed(1) + 'h over!)').removeClass('d-none');
            } else {
                $overText.text('').addClass('d-none');
            }
        }
    }

    // Production Hours quick-form: load pages/environments/issues and submit
    window.initProductionHours = function () {
        if (!window.jQuery) return;
        var $form = $('#logProductionHoursForm');
        var $pageSel = $('#productionPageSelect');
        var $envSel = $('#productionEnvSelect');
        var $issueCont = $('#productionIssueSelect');
        var $issueSel = $('#productionIssueSelect');
        var $taskType = $('#taskTypeSelect');
        var $pageTestingCont = $('#pageTestingContainer');
        var $phaseCont = $('#projectPhaseContainer');
        var $genericCont = $('#genericTaskContainer');
        var $genericCat = $('#genericCategorySelect');
        var $phaseSelect = $('#projectPhaseSelect');
        var $regressionCont = $('#regressionContainer');
        var $regressionSummary = $('#regressionSummary');
        var $regressionIssueCount = $('#regressionIssueCount');
        var $dateInput = $form.find('[name="log_date"]');

        // Restrict allowed log dates
        function setAllowedLogDates() {
            var today = new Date();
            var dow = today.getDay(); // 0 = Sun, 1 = Mon
            var prev = new Date(today);
            if (dow === 1) { // Monday -> allow Saturday
                prev.setDate(prev.getDate() - 2);
            } else {
                prev.setDate(prev.getDate() - 1);
            }
            function fmt(d) {
                var y = d.getFullYear();
                var m = ('0' + (d.getMonth() + 1)).slice(-2);
                var dd = ('0' + d.getDate()).slice(-2);
                return y + '-' + m + '-' + dd;
            }
            var min = fmt(prev), max = fmt(today);
            $dateInput.attr('min', min).attr('max', max);
            var cur = $dateInput.val();
            if (!cur || cur < min || cur > max) $dateInput.val(max);
        }
        setAllowedLogDates();

        function loadProjectPages() {
            $pageSel.html('<option value="">Loading pages...</option>');
            fetch(baseDir + '/api/tasks.php?project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(function (pages) {
                    $pageSel.empty();
                    $pageSel.append('<option value="">(none)</option>');
                    if (Array.isArray(pages)) pages.forEach(function (pg) {
                        $pageSel.append('<option value="' + pg.id + '">' + (pg.page_name || pg.title || pg.url || ('Page ' + pg.id)) + '</option>');
                    });
                }).catch(function () {
                    $pageSel.html('<option value="">Error loading pages</option>');
                });
        }

        function loadProjectPhases() {
            $phaseSelect.html('<option value="">Loading phases...</option>');
            function formatPhaseLabel(raw) {
                var txt = String(raw || '').trim();
                if (!txt) return '';
                var known = {
                    'po_received': 'PO received',
                    'scoping_confirmation': 'Scoping confirmation',
                    'testing': 'Testing',
                    'regression': 'Regression',
                    'training': 'Training',
                    'vpat_acr': 'VPAT ACR'
                };
                if (known[txt]) return known[txt];
                return txt
                    .replace(/[_-]+/g, ' ')
                    .split(' ')
                    .map(function (w) {
                        var lw = w.toLowerCase();
                        if (lw === 'po') return 'PO';
                        if (lw === 'qa') return 'QA';
                        if (lw === 'uat') return 'UAT';
                        if (lw === 'ui') return 'UI';
                        if (lw === 'ux') return 'UX';
                        if (lw === 'vpat') return 'VPAT';
                        if (lw === 'acr') return 'ACR';
                        return lw.charAt(0).toUpperCase() + lw.slice(1);
                    })
                    .join(' ');
            }
            fetch(baseDir + '/api/projects.php?action=get_phases&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
                .then(r => r.text())
                .then(function (txt) {
                    try { var phases = JSON.parse(txt); } catch (e) { throw new Error(txt); }
                    $phaseSelect.empty();
                    $phaseSelect.append('<option value="">Select project phase</option>');
                    if (Array.isArray(phases)) {
                        phases.forEach(function (phase) {
                            var label = formatPhaseLabel(phase.phase_name || phase.name || phase.id);
                            var opt = '<option value="' + phase.id + '">' + label + '</option>';
                            $phaseSelect.append(opt);
                        });
                    }
                }).catch(function () { $phaseSelect.html('<option value="">Error loading phases</option>'); });
        }

        function loadGenericCategories() {
            $genericCat.html('<option value="">Loading categories...</option>');
            fetch(baseDir + '/api/generic_tasks.php?action=get_categories', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(function (categories) {
                    $genericCat.empty(); $genericCat.append('<option value="">Select category</option>');
                    if (Array.isArray(categories)) categories.forEach(function (c) { $genericCat.append('<option value="' + c.id + '">' + (c.name || c.title || c.id) + '</option>'); });
                }).catch(function () { $genericCat.html('<option value="">Error loading categories</option>'); });
        }

        function loadRegressionSummary() {
            $regressionSummary.text('Loading...');
            fetch(baseDir + '/api/regression_actions.php?action=get_stats&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(function (json) {
                    if (!json || !json.success) { $regressionSummary.text('Error'); return; }
                    var html = '<div><strong>Total issues:</strong> ' + (json.issues_total || 0) + '</div>';
                    html += '<div><strong>Attempted:</strong> ' + (json.attempted_issues_total || 0) + '</div>';
                    html += '<div class="mt-1"><strong>By status:</strong><br/>';
                    var asc = json.attempted_status_counts || {};
                    for (var k in asc) html += '<div>' + k + ': ' + asc[k] + '</div>';
                    html += '</div>';
                    $regressionSummary.html(html);
                }).catch(function () { $regressionSummary.text('Error'); });
        }

        function loadPageEnvironments(pageId) {
            $envSel.html('<option value="">Loading environments...</option>');
            if (!pageId) { $envSel.html('<option value="">Select page first</option>'); return; }
            fetch(baseDir + '/api/tasks.php?page_id=' + encodeURIComponent(pageId), { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) return res.text().then(t => { throw new Error('HTTP ' + res.status + ': ' + t); });
                    return res.json();
                })
                .then(function (page) {
                    $envSel.empty();
                    if (page && page.environments && page.environments.length) {
                        $envSel.append('<option value="">(none)</option>');
                        page.environments.forEach(function (env) {
                            var id = env.id || env.environment_id || env.environmentId || '';
                            var name = env.name || env.env_name || env.environment_name || ('Env ' + id);
                            if (!id) return;
                            $envSel.append('<option value="' + id + '">' + (name) + '</option>');
                        });
                    } else {
                        $envSel.append('<option value="">No environments</option>');
                    }
                }).catch(function (err) {
                    $envSel.html('<option value="">Error loading environments</option>');
                });
        }

        function loadProjectIssues(pageId) {
            $issueSel.html('<option value="">Loading issues...</option>');
            fetch(baseDir + '/api/regression_actions.php?action=get_project_issues&project_id=' + encodeURIComponent(projectId) + (pageId ? '&page_id=' + encodeURIComponent(pageId) : ''), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(function (data) {
                    $issueSel.empty();
                    $issueSel.append('<option value="">(none)</option>');
                    if (data && Array.isArray(data.issues)) {
                        data.issues.forEach(function (it) {
                            $issueSel.append('<option value="' + it.id + '">' + ((it.issue_key ? it.issue_key + ' - ' : '') + (it.title || ('Issue ' + it.id))) + '</option>');
                        });
                    }
                }).catch(function () { $issueSel.html('<option value="">Error loading issues</option>'); });
        }

        // init
        loadProjectPages();

        $pageSel.off('change').on('change', function () {
            var val = $(this).val();
            var pid = Array.isArray(val) ? (val[0] || '') : (val || '');
            loadPageEnvironments(pid);
            if ($taskType.val() === 'regression_testing') loadProjectIssues(pid);
        });

        $taskType.off('change').on('change', function () {
            var t = $(this).val();
            $pageTestingCont.hide(); $phaseCont.hide(); $genericCont.hide(); $regressionCont.hide(); $issueCont.hide();
            if ($regressionIssueCount.length) $regressionIssueCount.val('');
            if (!t) return;
            if (t === 'page_testing' || t === 'page_qa') {
                $pageTestingCont.show(); loadProjectPages();
            } else if (t === 'regression_testing') {
                $regressionCont.show(); loadRegressionSummary();
                $issueCont.show();
                var firstPage = $pageSel.val() || null;
                loadProjectIssues(firstPage);
            } else if (t === 'project_phase') {
                $phaseCont.show(); loadProjectPhases();
            } else if (t === 'generic_task') {
                $genericCont.show(); loadGenericCategories();
            }
        });

        $form.off('submit').on('submit', function (e) {
            e.preventDefault();
            var fd = {};
            fd.action = 'log';
            fd.user_id = $form.find('[name="user_id"]').val() || userId;
            fd.project_id = $form.find('[name="project_id"]').val();
            var pageVals = $form.find('[name="page_ids[]"]').val() || $form.find('[name="page_id"]').val() || '';
            fd.page_id = Array.isArray(pageVals) ? (pageVals[0] || '') : (pageVals || '');
            var envVals = $form.find('[name="environment_ids[]"]').val() || $form.find('[name="environment_id"]').val() || '';
            fd.environment_id = Array.isArray(envVals) ? (envVals[0] || '') : (envVals || '');
            fd.issue_id = $form.find('[name="issue_id"]').val() || '';
            fd.task_type = $form.find('[name="task_type"]').val() || '';
            fd.testing_type = $form.find('[name="testing_type"]').val() || '';
            fd.issue_count = $form.find('[name="issue_count"]').val() || '';
            fd.log_date = $form.find('[name="log_date"]').val() || '';
            fd.hours = $form.find('[name="hours"]').val();
            fd.description = $form.find('[name="description"]').val();
            fd.is_utilized = 1;

            if (!fd.hours || parseFloat(fd.hours) <= 0) { if (typeof showToast === 'function') showToast('Please enter valid hours', 'warning'); return; }
            if (fd.task_type === 'regression_testing' && fd.issue_count !== '') {
                var n = parseInt(fd.issue_count, 10);
                if (isNaN(n) || n <= 0) {
                    if (typeof showToast === 'function') showToast('Please enter a valid issue count', 'warning');
                    return;
                }
            }

            $.ajax({
                url: baseDir + '/api/project_hours.php',
                method: 'POST',
                data: fd,
                dataType: 'json',
                success: function (resp) {
                    if (resp.success) {
                        updateTopHoursSummary(resp.summary || null);
                        // fetch updated production-hours panel and replace
                        fetch(window.location.href, { credentials: 'same-origin' }).then(r => r.text()).then(function (html) {
                            try {
                                var parser = new DOMParser();
                                var doc = parser.parseFromString(html, 'text/html');
                                var newPanel = doc.querySelector('#production-hours');
                                var curPanel = document.querySelector('#production-hours');
                                if (newPanel && curPanel) {
                                    curPanel.innerHTML = newPanel.innerHTML;
                                    var newSummary = doc.querySelector('#projectHoursSummary');
                                    var curSummary = document.querySelector('#projectHoursSummary');
                                    if (newSummary && curSummary) {
                                        curSummary.innerHTML = newSummary.innerHTML;
                                    }
                                    initProductionHours();
                                } else {
                                    location.reload();
                                }
                            } catch (e) { location.reload(); }
                        }).catch(function () { location.reload(); });
                    } else {
                        if (typeof showToast === 'function') showToast('Failed to log hours: ' + (resp.message || resp.error || 'Unknown'), 'danger');
                    }
                },
                error: function (xhr) { if (typeof showToast === 'function') showToast('Error logging hours', 'danger'); }
            });
        });
    }

    // Call init on load
    $(document).ready(function () {
        // Check if tab is already active
        if (document.querySelector('#production-hours.active')) {
            initProductionHours();
        }
        // Bind to tab shown
        const phbtn = document.getElementById('production-hours-tab') || document.querySelector('button[data-bs-target="#production-hours"]');
        if (phbtn) {
            phbtn.addEventListener('shown.bs.tab', function () { try { if (typeof initProductionHours === 'function') initProductionHours(); } catch (e) { } });
        }
    });

})();
