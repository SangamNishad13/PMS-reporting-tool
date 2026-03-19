<?php
$filePath = __DIR__ . '/modules/projects/partials/tab_pages.php';
$content = file_get_contents($filePath);

// Find second script block start
$pos = 0;
$count = 0;
$secondStart = -1;
while (($idx = strpos($content, '<script>', $pos)) !== false) {
    $count++;
    if ($count === 2) { $secondStart = $idx; break; }
    $pos = $idx + 1;
}

if ($secondStart === -1) { die("Second script block not found\n"); }

// Keep everything before second script block
$before = substr($content, 0, $secondStart);

$newScript = <<<'ENDSCRIPT'
<script>
(function () {
    var projectId    = PROJ_ID;
    var projectTitle = PROJ_TITLE;
    var baseDir      = BASE_DIR;

    function collectPagesRows() {
        var rows = [['Page No.', 'Page Name', 'Unique URL', 'Grouped URLs', 'FT Tester', 'AT Tester', 'QA', 'Page Status', 'Notes']];
        document.querySelectorAll('#uniquePagesTable tbody tr[id^="unique-row-"]').forEach(function (tr) {
            var cells = tr.querySelectorAll('td');
            if (cells.length < 10) return;
            var pageNo    = cells[1].textContent.trim();
            var pageName  = (cells[2].querySelector('.page-name-display') || cells[2]).textContent.trim();
            var uniqueUrl = cells[3].textContent.trim();
            var groupedList = cells[4].querySelectorAll('.grouped-url-item');
            var groupedUrls = [];
            groupedList.forEach(function (el) { groupedUrls.push(el.textContent.trim()); });
            var ftText     = cells[5].textContent.replace(/\s+/g, ' ').trim();
            var atText     = cells[6].textContent.replace(/\s+/g, ' ').trim();
            var qaText     = cells[7].textContent.replace(/\s+/g, ' ').trim();
            var statusText = cells[8].textContent.trim();
            var notesText  = (cells[9].querySelector('.notes-display') || cells[9]).textContent.trim();
            rows.push([pageNo, pageName, uniqueUrl, groupedUrls.join('\n'), ftText, atText, qaText, statusText, notesText]);
        });
        return rows;
    }

    function collectUrlRows() {
        var rows = [['URL', 'Unique Page No.']];
        document.querySelectorAll('#allUrlsTable tbody tr[id^="grouped-row-"]').forEach(function (tr) {
            var cells = tr.querySelectorAll('td');
            if (cells.length < 5) return;
            var url = cells[1].textContent.trim();
            var uniqueSel = cells[2].querySelector('select');
            var uniquePage = uniqueSel
                ? (uniqueSel.options[uniqueSel.selectedIndex] ? uniqueSel.options[uniqueSel.selectedIndex].text : '')
                : cells[2].textContent.trim();
            rows.push([url, uniquePage]);
        });
        return rows;
    }

    var clientReportBtn = document.getElementById('exportClientReportBtn');
    if (!clientReportBtn) return;

    clientReportBtn.addEventListener('click', function () {
        if (typeof XLSX === 'undefined') { alert('Excel library not loaded. Please refresh.'); return; }

        clientReportBtn.disabled = true;
        clientReportBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Preparing...';

        Promise.all([
            fetch(baseDir + '/api/serve_template.php', { credentials: 'same-origin' }).then(function (r) {
                if (!r.ok) throw new Error('Template fetch failed: ' + r.status);
                return r.arrayBuffer();
            }),
            fetch(baseDir + '/api/issues.php?action=get_all&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
            fetch(baseDir + '/api/export_overview_data.php?project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
        ]).then(function (results) {
            var templateBuf  = results[0];
            var issuesJson   = results[1];
            var overviewData = results[2];
            var allIssues    = (issuesJson && issuesJson.issues) ? issuesJson.issues : [];

            var wb = XLSX.read(templateBuf, { type: 'array', cellFormula: true, cellStyles: true });

            // helper: set cell value, preserve existing cell object if present
            function sc(ws, addr, val) {
                if (!ws[addr]) ws[addr] = {};
                ws[addr].v = val;
                ws[addr].t = (typeof val === 'number') ? 'n' : 's';
                if (typeof val !== 'number') delete ws[addr].f;
            }

            // ── Overview sheet ────────────────────────────────────────────────
            var wsOV = wb.Sheets['Overview'];
            if (wsOV && overviewData && !overviewData.error) {
                var proj = overviewData.project || {};

                sc(wsOV, 'F2', proj.title || '');
                sc(wsOV, 'F3', proj.project_type || '');
                sc(wsOV, 'F4', 'Sakshi Infotech Solutions LLP');
                sc(wsOV, 'F5', 'Sangam Nishad');
                sc(wsOV, 'F6', overviewData.export_date || '');

                // WCAG failing SC counts
                sc(wsOV, 'F12', overviewData.wcag_level_a || 0);
                sc(wsOV, 'G12', overviewData.wcag_level_aa || 0);

                // Top 5 issues: L12:L16 = titles, M12:M16 = WCAG SC numbers
                var topIssues = overviewData.top_issues || [];
                var lCols = ['L12','L13','L14','L15','L16'];
                var mCols = ['M12','M13','M14','M15','M16'];
                for (var ti = 0; ti < 5; ti++) {
                    var tiss = topIssues[ti] || {};
                    sc(wsOV, lCols[ti], tiss.title || '');
                    sc(wsOV, mCols[ti], tiss.sc_nums || '');
                }

                // Users affected: B16 onwards = user name, C16 onwards = count
                var usersAff = overviewData.users_affected || [];
                for (var ui = 0; ui < usersAff.length; ui++) {
                    sc(wsOV, 'B' + (16 + ui), usersAff[ui].user || '');
                    sc(wsOV, 'C' + (16 + ui), usersAff[ui].count || 0);
                }

                // Severity counts: O24 onwards = severity name, P24 onwards = count
                var sevCounts = overviewData.severity_counts || [];
                for (var si = 0; si < sevCounts.length; si++) {
                    sc(wsOV, 'O' + (24 + si), sevCounts[si].severity || '');
                    sc(wsOV, 'P' + (24 + si), sevCounts[si].count || 0);
                }

                // Team members: B28 onwards = name, C28 onwards = role
                var team = overviewData.team || [];
                for (var mi = 0; mi < team.length; mi++) {
                    sc(wsOV, 'B' + (28 + mi), team[mi].name || '');
                    sc(wsOV, 'C' + (28 + mi), team[mi].role || '');
                }
            }

            // ── URL Details sheet ─────────────────────────────────────────────
            var wsUD = wb.Sheets['URL Details'];
            if (wsUD) {
                var pagesRows = collectPagesRows();
                var udRange = XLSX.utils.decode_range(wsUD['!ref'] || 'A1:K200');
                for (var r = 1; r <= udRange.e.r; r++) {
                    for (var c = 0; c <= 10; c++) { delete wsUD[XLSX.utils.encode_cell({r:r,c:c})]; }
                }
                for (var i = 1; i < pagesRows.length; i++) {
                    var row = pagesRows[i];
                    wsUD[XLSX.utils.encode_cell({r:i,c:0})]  = {t:'s', v: String(row[0]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:1})]  = {t:'s', v: String(row[1]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:2})]  = {t:'s', v: String(row[2]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:3})]  = {t:'s', v: String(row[3]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:4})]  = {t:'s', v: ''};
                    wsUD[XLSX.utils.encode_cell({r:i,c:5})]  = {t:'s', v: String(row[5]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:6})]  = {t:'s', v: String(row[4]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:7})]  = {t:'s', v: String(row[6]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:8})]  = {t:'s', v: String(row[8]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:9})]  = {t:'s', v: String(row[7]||'')};
                    wsUD[XLSX.utils.encode_cell({r:i,c:10})] = {t:'s', v: ''};
                }
                wsUD['!ref'] = XLSX.utils.encode_range({s:{r:0,c:0}, e:{r:Math.max(1,pagesRows.length-1),c:10}});
            }

            // ── All URLs sheet ────────────────────────────────────────────────
            var wsAU = wb.Sheets['All URLs'];
            if (wsAU) {
                var urlRows = collectUrlRows();
                var auRange = XLSX.utils.decode_range(wsAU['!ref'] || 'A1:B200');
                for (var r = 1; r <= auRange.e.r; r++) {
                    delete wsAU[XLSX.utils.encode_cell({r:r,c:0})];
                    delete wsAU[XLSX.utils.encode_cell({r:r,c:1})];
                }
                for (var i = 1; i < urlRows.length; i++) {
                    wsAU[XLSX.utils.encode_cell({r:i,c:0})] = {t:'s', v: String(urlRows[i][0]||'')};
                    wsAU[XLSX.utils.encode_cell({r:i,c:1})] = {t:'s', v: String(urlRows[i][1]||'')};
                }
                wsAU['!ref'] = XLSX.utils.encode_range({s:{r:0,c:0}, e:{r:Math.max(1,urlRows.length-1),c:1}});
            }

            // ── Final Report sheet ────────────────────────────────────────────
            var wsFR = wb.Sheets['Final Report'];
            if (wsFR) {
                var frRange = XLSX.utils.decode_range(wsFR['!ref'] || 'A1:AJ1000');
                for (var r = 1; r <= frRange.e.r; r++) {
                    for (var c = 0; c <= 35; c++) { delete wsFR[XLSX.utils.encode_cell({r:r,c:c})]; }
                }

                function stripHtml(html) {
                    if (!html) return '';
                    var d = document.createElement('div');
                    d.innerHTML = html;
                    return (d.textContent || d.innerText || '').replace(/\s+/g, ' ').trim();
                }
                function s(v) { return {t:'s', v: String(v||'')}; }
                function metaVal(meta, key) {
                    if (!meta || !meta[key]) return '';
                    var v = meta[key];
                    if (Array.isArray(v)) return v.join(', ');
                    return String(v);
                }

                for (var i = 0; i < allIssues.length; i++) {
                    var iss = allIssues[i];
                    var ri  = i + 1;
                    var meta = iss.metadata || {};
                    var pageNo   = iss.pages || '';
                    var pageName = iss.pages || '';
                    var wcagNums  = metaVal(meta, 'wcagsuccesscriteria');
                    var wcagNames = metaVal(meta, 'wcagsuccesscriterianame');
                    var wcagLevel = metaVal(meta, 'wcagsuccesscriterialevel');
                    var gigw      = metaVal(meta, 'gigw30');
                    var is17802   = metaVal(meta, 'is17802');
                    var usersAffFR = metaVal(meta, 'usersaffected');
                    var qaStatus  = (iss.qa_statuses || []).map(function(q){ return q.label||q.key||''; }).join(', ');
                    var reporter  = iss.reporters || iss.reporter_name || '';
                    var desc      = stripHtml(iss.description || '');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:0})]  = s(pageNo);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:1})]  = s(pageName);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:2})]  = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:3})]  = s(iss.title||'');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:4})]  = s(desc);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:5})]  = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:6})]  = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:7})]  = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:8})]  = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:9})]  = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:10})] = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:11})] = s(iss.severity||'');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:12})] = s(usersAffFR);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:13})] = s(wcagNums);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:14})] = s(wcagNames);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:15})] = s(wcagLevel);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:16})] = s(gigw);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:17})] = s(is17802);
                    for (var ec = 18; ec <= 25; ec++) { wsFR[XLSX.utils.encode_cell({r:ri,c:ec})] = s(''); }
                    wsFR[XLSX.utils.encode_cell({r:ri,c:26})] = s(iss.status_name||iss.status||'');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:27})] = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:28})] = s(reporter);
                    wsFR[XLSX.utils.encode_cell({r:ri,c:29})] = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:30})] = s(iss.status_name||iss.status||'');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:31})] = s(iss.qa_name||'');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:32})] = s('');
                    wsFR[XLSX.utils.encode_cell({r:ri,c:33})] = s(qaStatus);
                }
                wsFR['!ref'] = XLSX.utils.encode_range({s:{r:0,c:0}, e:{r:Math.max(1,allIssues.length),c:35}});
            }

            var safeTitle = projectTitle.replace(/[^a-zA-Z0-9_\- ]/g, '').trim() || 'Project';
            XLSX.writeFile(wb, safeTitle + ' - Accessibility Audit Report.xlsx');

        }).catch(function (err) {
            console.error('Client report export failed:', err);
            alert('Export failed: ' + err.message);
        }).finally(function () {
            clientReportBtn.disabled = false;
            clientReportBtn.innerHTML = '<i class="fas fa-file-excel me-1"></i> Export Client Report';
        });
    });
}());
</script>
ENDSCRIPT;

$newScript = str_replace('PROJ_ID',    "<?php echo (int)(\$projectId ?? 0); ?>",          $newScript);
$newScript = str_replace('PROJ_TITLE', "<?php echo json_encode(\$project['title'] ?? 'Project'); ?>", $newScript);
$newScript = str_replace('BASE_DIR',   "<?php echo json_encode(\$baseDir ?? ''); ?>",      $newScript);

$newContent = $before . $newScript;
file_put_contents($filePath, $newContent);
echo "Done. New size: " . strlen($newContent) . "\n";
