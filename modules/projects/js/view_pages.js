/**
 * view_pages.js
 * Handles Unique Pages, Grouped URLs, and related CSV imports.
 */

$(document).ready(function () {
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
    var baseDir = window.ProjectConfig ? window.ProjectConfig.baseDir : '';

    // Environment Status Update Handler (for Pages tab dropdowns)
    $(document).on('change', '.env-status-update', function() {
        var $select = $(this);
        var pageId = $select.data('page-id');
        var envId = $select.data('env-id');
        var statusType = $select.data('status-type'); // 'testing' or 'qa'
        var newStatus = $select.val();
        var previousValue = $select.data('previous-value') || $select.find('option:first').val();
        
        // Disable dropdown during update
        $select.prop('disabled', true);
        
        // Send update request using JSON (like issues_page_detail.php)
        fetch(baseDir + '/api/update_page_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                page_id: parseInt(pageId),
                environment_id: parseInt(envId),
                status_type: statusType,  // 'testing' or 'qa'
                status: newStatus
            }),
            credentials: 'same-origin'
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            $select.prop('disabled', false);
            
            if (data.success) {
                // Show success feedback
                $select.addClass('border-success');
                setTimeout(function() {
                    $select.removeClass('border-success');
                }, 1000);
                
                // Store new value as previous
                $select.data('previous-value', newStatus);
                
                // Show toast if available
                if (typeof showToast === 'function') {
                    showToast('Status updated successfully', 'success');
                }
            } else {
                // Show error and revert
                alert('Error updating status: ' + (data.message || 'Unknown error'));
                $select.val(previousValue);
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            $select.prop('disabled', false);
            alert('Error updating status. Please try again.');
            $select.val(previousValue);
        });
    });
    
    // Store initial value when dropdown is focused
    $(document).on('focus', '.env-status-update', function() {
        if (!$(this).data('previous-value')) {
            $(this).data('previous-value', $(this).val());
        }
    });

    // Initialize All URLs tab when it's shown
    var allUrlsTabBtn = document.getElementById('allurls-sub-tab');
    if (allUrlsTabBtn) {
        allUrlsTabBtn.addEventListener('shown.bs.tab', function () {
            // Force table to recalculate layout
            var table = document.getElementById('allUrlsTable');
            if (table) {
                table.style.display = 'table';
            }
            // Reapply filters to ensure data is visible
            applyAllUrlsFilters();
        });
    }

    // Initialize Unique Pages tab when it's shown
    var uniqueTabBtn = document.getElementById('project-sub-tab');
    if (uniqueTabBtn) {
        uniqueTabBtn.addEventListener('shown.bs.tab', function () {
            // Force table to recalculate layout
            var table = document.getElementById('uniquePagesTable');
            if (table) {
                table.style.display = 'table';
            }
            // Reapply filters to ensure data is visible
            applyUniqueFilters();
        });
    }

    // Open Add Grouped URL modal (All URLs tab)
    $(document).on('click', '#openAddGroupedUrlModal', function (e) {
        e.preventDefault();
        var modal = new bootstrap.Modal(document.getElementById('addGroupedUrlModal'));
        modal.show();
    });

    $(document).on('submit', '#addGroupedUrlForm', function (e) {
        e.preventDefault();
        var form = $(this);
        var fd = new FormData(this);
        fetch(baseDir + '/api/project_pages.php?action=map_url', {
            method: 'POST', body: fd, credentials: 'same-origin'
        }).then(r => r.json()).then(function (j) {
            if (j && j.success) {
                var bsModalEl = document.getElementById('addGroupedUrlModal');
                var modal = bootstrap.Modal.getInstance(bsModalEl);
                if (modal) modal.hide();
                if (typeof showToast === 'function') showToast('URL added', 'success');
                setTimeout(function () { location.reload(); }, 500);
            } else {
                alert('Add failed: ' + (j && (j.error || j.message) ? (j.error || j.message) : JSON.stringify(j)));
            }
        }).catch(function () { alert('Request failed'); });
    });

    // Handle assigning grouped URL to a Unique via dropdown (ONLY for All URLs tab)
    $(document).on('change', '.grouped-unique-select', function (e) {
        e.preventDefault();
        e.stopPropagation();
        
        var sel = $(this);
        var uniqueId = sel.val();
        var groupedId = sel.data('grouped-id');
        
        if (!groupedId) {
            return;
        }
        
        // Store original value before confirmation
        var originalValue = sel.data('original-value') || '';
        
        // Confirm before assignment
        if (!confirm('Assign this URL to the selected page?')) {
            sel.val(originalValue);
            return;
        }
        
        var payload = { 
            project_id: projectId, 
            unique_page_id: uniqueId !== '' ? parseInt(uniqueId, 10) : 0, 
            grouped_ids: [groupedId] 
        };
        
        sel.prop('disabled', true);
        
        fetch(baseDir + '/api/project_pages.php?action=assign_bulk', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/json; charset=utf-8' }, 
            body: JSON.stringify(payload), 
            credentials: 'same-origin' 
        })
        .then(r => r.json())
        .then(function (j) {
            sel.prop('disabled', false);
            
            if (j && j.success) {
                // Update the row without full page reload
                var row = sel.closest('tr');
                var selectedOption = sel.find('option:selected');
                var pageName = selectedOption.text();
                var urlText = row.find('td:eq(1)').text().trim();
                
                // Update the "Mapped To" column
                var mappedCell = row.find('.mapped-col');
                if (uniqueId && uniqueId !== '' && uniqueId !== '0') {
                    mappedCell.html('<div><strong>' + pageName + '</strong></div>');
                } else {
                    mappedCell.html('<div><span class="text-muted">(Unassigned)</span></div>');
                }
                
                // Update the grouped URLs list in Unique Pages tab
                // Step 1: Remove URL from old unique page (if it was assigned before)
                if (originalValue && originalValue !== '' && originalValue !== '0') {
                    var oldUniqueList = $('.unique-grouped-list[data-unique-id="' + originalValue + '"]');
                    if (oldUniqueList.length > 0) {
                        // Find and remove the URL from old list
                        oldUniqueList.find('.grouped-url-item').each(function() {
                            if ($(this).text().trim() === urlText) {
                                $(this).remove();
                            }
                        });
                        
                        // If no URLs left, show "No grouped URLs" message
                        if (oldUniqueList.find('.grouped-url-item').length === 0) {
                            oldUniqueList.html('<span class="text-muted">No grouped URLs</span>');
                        }
                    }
                }
                
                // Step 2: Add URL to new unique page (if assigned)
                if (uniqueId && uniqueId !== '' && uniqueId !== '0') {
                    var newUniqueList = $('.unique-grouped-list[data-unique-id="' + uniqueId + '"]');
                    if (newUniqueList.length > 0) {
                        // Remove "No grouped URLs" message if present
                        if (newUniqueList.find('.text-muted').length > 0) {
                            newUniqueList.html('');
                        }
                        
                        // Check if URL already exists in the list
                        var urlExists = false;
                        newUniqueList.find('.grouped-url-item').each(function() {
                            if ($(this).text().trim() === urlText) {
                                urlExists = true;
                                return false;
                            }
                        });
                        
                        // Add URL if it doesn't exist
                        if (!urlExists) {
                            newUniqueList.append('<div class="grouped-url-item">' + urlText + '</div>');
                        }
                    }
                }
                
                // Show success message
                if (typeof showToast === 'function') {
                    showToast('URL assigned successfully!', 'success');
                }
                
                // Store new value as original
                sel.data('original-value', uniqueId);
            } else {
                alert('Assignment failed: ' + (j && j.error ? j.error : 'Unknown error'));
                sel.val(originalValue);
            }
        })
        .catch(function (err) {
            sel.prop('disabled', false);
            alert('Request failed. Please try again.');
            sel.val(originalValue);
        });
    });
    
    // Store original value when dropdown is focused
    $(document).on('focus', '.grouped-unique-select', function() {
        $(this).data('original-value', $(this).val());
    });

    // Add Unique modal handling
    $('#addUniqueBtn').on('click', function (e) { e.preventDefault(); $('#addUniqueModal').modal('show'); });
    $('#createUniqueBtn').on('click', function (e) {
        var name = $('#newUniqueName').val().trim();
        var canonical = $('#newUniqueCanonical').val().trim();
        var err = $('#addUniqueError'); err.hide().text('');
        
        $(this).prop('disabled', true);
        fetch(baseDir + '/api/project_pages.php?action=create_unique', { method: 'POST', headers: { 'Content-Type': 'application/json; charset=utf-8' }, body: JSON.stringify({ project_id: projectId, name: name, canonical_url: canonical }), credentials: 'same-origin' })
            .then(r => r.json()).then(function (j) {
                $('#createUniqueBtn').prop('disabled', false); if (j && j.success) {
                    $('#addUniqueModal').modal('hide');
                    location.reload();
                } else { err.text(j && (j.error || j.message) ? (j.error || j.message) : 'Create failed').show(); }
            }).catch(function () { $('#createUniqueBtn').prop('disabled', false); err.text('Request failed').show(); });
    });

    // Unique filter handling
    function applyUniqueFilters() {
        var q = $('#uniqueFilter').val().toLowerCase().trim();
        var user = $('#uniqueFilterUser').val();
        var env = $('#uniqueFilterEnv').val();
        var qa = $('#uniqueFilterQa').val();
        var pageStatus = $('#uniqueFilterPageStatus').val().toLowerCase().trim();
        $('#project_pages_sub table tbody tr').each(function () {
            var name = $(this).find('td').eq(2).text().toLowerCase();
            var url = $(this).find('td').eq(3).text().toLowerCase();
            var ft = $(this).find('td').eq(5).text().toLowerCase();
            var at = $(this).find('td').eq(6).text().toLowerCase();
            var qaText = $(this).find('td').eq(7).text().toLowerCase();
            var pageStatusText = $(this).find('td').eq(8).text().toLowerCase();
            var envText = $(this).find('td').eq(5).text().toLowerCase() + ' ' + $(this).find('td').eq(6).text().toLowerCase();
            var show = true;
            if (q && name.indexOf(q) === -1 && url.indexOf(q) === -1) show = false;
            if (user && show) {
                var userLower = user.toLowerCase();
                if (ft.indexOf(userLower) === -1 && at.indexOf(userLower) === -1 && qaText.indexOf(userLower) === -1) show = false;
            }
            if (env && show) {
                if (envText.indexOf(env.toLowerCase()) === -1) show = false;
            }
            if (qa && show) {
                if (qaText.indexOf(qa.toLowerCase()) === -1) show = false;
            }
            if (pageStatus && show) {
                if (pageStatusText.indexOf(pageStatus) === -1) show = false;
            }
            if (show) $(this).show(); else $(this).hide();
        });
    }
    $('#uniqueFilter').on('input', applyUniqueFilters);
    $('#uniqueFilterUser, #uniqueFilterEnv, #uniqueFilterQa, #uniqueFilterPageStatus').on('change', applyUniqueFilters);

    // All URLs filter handling
    function applyAllUrlsFilters() {
        var urlQuery = $('#allUrlsFilter').val().toLowerCase().trim();
        var uniqueFilter = $('#allUrlsUniqueFilter').val().toLowerCase().trim();
        var mappingFilter = $('#allUrlsMappingFilter').val();
        
        $('#all_urls_sub table tbody tr').each(function () {
            var row = $(this);
            var url = row.find('td').eq(1).text().toLowerCase();
            var uniquePage = row.find('td').eq(2).find('option:selected').text().toLowerCase();
            var mapped = row.find('td').eq(3).text().toLowerCase();
            var isMapped = mapped.indexOf('unassigned') === -1;
            
            var show = true;
            
            // URL filter
            if (urlQuery && url.indexOf(urlQuery) === -1) {
                show = false;
            }
            
            // Unique page filter
            if (uniqueFilter && show) {
                if (uniquePage.indexOf(uniqueFilter) === -1) {
                    show = false;
                }
            }
            
            // Mapping status filter
            if (mappingFilter && show) {
                if (mappingFilter === 'mapped' && !isMapped) {
                    show = false;
                } else if (mappingFilter === 'unassigned' && isMapped) {
                    show = false;
                }
            }
            
            if (show) row.show(); else row.hide();
        });
    }
    $('#allUrlsFilter').on('input', applyAllUrlsFilters);
    $('#allUrlsUniqueFilter, #allUrlsMappingFilter').on('change', applyAllUrlsFilters);

    // Open Import Modals
    $(document).on('click', '#importUrlsBtn', function (e) {
        e.preventDefault();
        $('#importUrlsModal').modal('show');
    });

    $(document).on('click', '#importAllUrlsBtn', function (e) {
        e.preventDefault();
        $('#importAllUrlsModal').modal('show');
    });

    // Delete Selected Unique
    $(document).on('click', '#deleteSelectedUnique', function () {
        var ids = [];
        $('.unique-check:checked').each(function () { ids.push($(this).val()); });
        if (ids.length === 0) { alert('Select at least one unique page'); return; }
        if (typeof confirmModal === 'function') {
            confirmModal('Delete ' + ids.length + ' unique pages and their mappings? This action cannot be undone.', function() {
                fetch(baseDir + '/api/project_pages.php?action=remove_unique_bulk', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'project_id=' + projectId + '&ids=' + ids.join(','), credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) location.reload();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            });
        } else {
            if (confirm('Delete ' + ids.length + ' unique pages and their mappings?')) {
                fetch(baseDir + '/api/project_pages.php?action=remove_unique_bulk', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'project_id=' + projectId + '&ids=' + ids.join(','), credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) location.reload();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            }
        }
    });

    // Select All Unique
    $(document).on('change', '#selectAllUnique', function () {
        $('.unique-check').prop('checked', $(this).prop('checked'));
    });

    // Delete Unique
    $(document).on('click', '.delete-unique', function () {
        var id = $(this).data('id');
        if (typeof confirmModal === 'function') {
            confirmModal('Are you sure you want to delete this unique page? This action cannot be undone.', function() {
                fetch(baseDir + '/api/project_pages.php?action=delete_unique', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id, credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) $('#unique-row-' + id).remove();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            });
        } else {
            if (confirm('Are you sure you want to delete this unique page?')) {
                fetch(baseDir + '/api/project_pages.php?action=delete_unique', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id, credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) $('#unique-row-' + id).remove();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            }
        }
    });

    // Edit Page Name / Notes handled in view_core.js to avoid duplicate bindings

    // Select All Grouped
    $(document).on('change', '#selectAllGrouped', function () {
        $('.grouped-check').prop('checked', $(this).prop('checked'));
    });

    // Delete Selected Grouped
    $(document).on('click', '#deleteSelectedGrouped', function () {
        var ids = [];
        $('.grouped-check:checked').each(function () { ids.push($(this).val()); });
        if (ids.length === 0) { alert('Select at least one URL'); return; }
        if (typeof confirmModal === 'function') {
            confirmModal('Delete ' + ids.length + ' URLs? This action cannot be undone.', function() {
                fetch(baseDir + '/api/project_pages.php?action=remove_grouped_bulk', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'project_id=' + projectId + '&ids=' + ids.join(','), credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) location.reload();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            });
        } else {
            if (confirm('Delete ' + ids.length + ' URLs?')) {
                fetch(baseDir + '/api/project_pages.php?action=remove_grouped_bulk', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'project_id=' + projectId + '&ids=' + ids.join(','), credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) location.reload();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            }
        }
    });

    // Delete Grouped
    $(document).on('click', '.delete-grouped', function () {
        var id = $(this).data('id');
        if (typeof confirmModal === 'function') {
            confirmModal('Delete this URL? This action cannot be undone.', function() {
                fetch(baseDir + '/api/project_pages.php?action=remove_grouped', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id, credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) $('#grouped-row-' + id).remove();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            });
        } else {
            if (confirm('Delete this URL?')) {
                fetch(baseDir + '/api/project_pages.php?action=remove_grouped', {
                    method: 'DELETE', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id, credentials: 'same-origin'
                }).then(r => r.json()).then(j => {
                    if (j && j.success) $('#grouped-row-' + id).remove();
                    else alert('Delete failed');
                }).catch(() => alert('Request failed'));
            }
        }
    });

    // CSV import preview and upload (Unique Pages)
    (function () {
        const fileInput = document.getElementById('importCsvFile');
        const previewArea = document.getElementById('csvPreviewArea');
        const previewTable = document.getElementById('csvPreviewTable');
        const mapPageNumber = document.getElementById('mapPageNumberCol');
        const mapPageName = document.getElementById('mapPageNameCol');
        const mapUniqueUrl = document.getElementById('mapUniqueUrlCol');
        const mapScreenName = document.getElementById('mapScreenNameCol');
        const mapNotes = document.getElementById('mapNotesCol');
        const mapGroupedUrls = document.getElementById('mapGroupedUrlsCol');
        const uploadBtn = document.getElementById('uploadCsvBtn');

        function clearPreview() { 
            if (previewArea) previewArea.style.display = 'none'; 
            if (previewTable) { 
                previewTable.querySelector('thead').innerHTML = ''; 
                previewTable.querySelector('tbody').innerHTML = ''; 
            } 
            [mapPageNumber, mapPageName, mapUniqueUrl, mapScreenName, mapNotes, mapGroupedUrls].forEach(sel => {
                if (sel) {
                    const hasNone = sel.querySelector('option[value=""]');
                    sel.innerHTML = hasNone ? '<option value="">-- None --</option>' : '';
                }
            });
        }

        fileInput?.addEventListener('change', function () {
            clearPreview();
            const f = this.files && this.files[0]; if (!f) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                const text = e.target.result.replace(/\r\n|\r/g, '\n');
                const lines = text.split('\n').filter(l => l.trim() != '');
                if (!lines.length) return;
                const rows = lines.slice(0, 6).map(r => {
                    const cols = r.match(/(?:"([^"]*)")|([^,]+)/g);
                    if (cols) return cols.map(c => c.replace(/^"|"$/g, '').trim());
                    return r.split(',').map(c => c.trim());
                });
                const header = rows[0];
                
                // Populate all dropdowns
                [mapPageNumber, mapPageName, mapUniqueUrl, mapScreenName, mapNotes, mapGroupedUrls].forEach(sel => {
                    if (!sel) return;
                    const hasNone = sel.querySelector('option[value=""]');
                    if (hasNone) sel.innerHTML = '<option value="">-- None --</option>';
                    else sel.innerHTML = '';
                    
                    for (let i = 0; i < header.length; i++) {
                        const txt = header[i] || ('Column ' + (i + 1));
                        const opt = document.createElement('option');
                        opt.value = i;
                        opt.textContent = txt;
                        sel.appendChild(opt);
                    }
                });
                
                // Smart auto-mapping based on header names
                if (header.length > 0) {
                    header.forEach((h, idx) => {
                        const lower = h.toLowerCase();
                        if (mapPageNumber && (lower.includes('page') && lower.includes('no')) || lower.includes('page_number')) {
                            mapPageNumber.value = idx;
                        } else if (mapPageName && (lower.includes('page') && lower.includes('name')) || lower === 'name') {
                            mapPageName.value = idx;
                        } else if (mapUniqueUrl && (lower.includes('url') || lower.includes('link') || lower.includes('unique'))) {
                            if (!mapUniqueUrl.value) mapUniqueUrl.value = idx; // First URL column
                        } else if (mapScreenName && lower.includes('screen')) {
                            mapScreenName.value = idx;
                        } else if (mapNotes && lower.includes('note')) {
                            mapNotes.value = idx;
                        } else if (mapGroupedUrls && lower.includes('grouped')) {
                            mapGroupedUrls.value = idx;
                        }
                    });
                    
                    // If no URL was auto-detected, select first column
                    if (mapUniqueUrl && !mapUniqueUrl.value) {
                        mapUniqueUrl.value = 0;
                    }
                }

                const thead = previewTable.querySelector('thead'); 
                const tbody = previewTable.querySelector('tbody');
                thead.innerHTML = '<tr>' + header.map(h => '<th>' + (h || '') + '</th>').join('') + '</tr>';
                tbody.innerHTML = '';
                for (let r = 1; r < rows.length; r++) {
                    const cols = rows[r];
                    const tr = document.createElement('tr');
                    for (let c = 0; c < header.length; c++) { 
                        const td = document.createElement('td'); 
                        td.textContent = cols[c] || ''; 
                        tr.appendChild(td); 
                    }
                    tbody.appendChild(tr);
                }
                previewArea.style.display = '';
            };
            reader.readAsText(f);
        });

        uploadBtn?.addEventListener('click', function () {
            const f = fileInput.files && fileInput.files[0]; 
            if (!f) { alert('Select a CSV file'); return; }
            
            const uniqueUrl = mapUniqueUrl ? mapUniqueUrl.value : '';
            if (uniqueUrl === '' || uniqueUrl === undefined) { 
                alert('Unique URL column is required'); 
                return; 
            }
            
            const fd = new FormData(); 
            fd.append('file', f); 
            fd.append('project_id', projectId);
            fd.append('unique_url_col', uniqueUrl);
            
            if (mapPageNumber && mapPageNumber.value) fd.append('page_number_col', mapPageNumber.value);
            if (mapPageName && mapPageName.value) fd.append('page_name_col', mapPageName.value);
            if (mapScreenName && mapScreenName.value) fd.append('screen_name_col', mapScreenName.value);
            if (mapNotes && mapNotes.value) fd.append('notes_col', mapNotes.value);
            if (mapGroupedUrls && mapGroupedUrls.value) fd.append('grouped_urls_col', mapGroupedUrls.value);
            
            uploadBtn.disabled = true; 
            uploadBtn.textContent = 'Uploading...';
            
            fetch(baseDir + '/modules/projects/upload_pages.php', { 
                method: 'POST', 
                body: fd, 
                credentials: 'same-origin' 
            })
            .then(r => r.json())
            .then(j => {
                uploadBtn.disabled = false; 
                uploadBtn.textContent = 'Upload';
                if (j && j.success) {
                    alert('Import complete. Added unique pages: ' + (j.added_unique || 0) + ', grouped URLs: ' + (j.added_grouped || 0));
                    location.reload();
                } else {
                    alert('Import failed: ' + (j.error || j.message || 'Unknown'));
                }
            }).catch(err => { 
                uploadBtn.disabled = false; 
                uploadBtn.textContent = 'Upload'; 
                alert('Upload error'); 
            });
        });
    })();

    // CSV import (All URLs only) preview and upload
    (function () {
        const fileInput = document.getElementById('importAllCsvFile');
        const previewArea = document.getElementById('csvAllPreviewArea');
        const previewTable = document.getElementById('csvAllPreviewTable');
        const mapAllOnly = document.getElementById('mapAllOnlyCol');
        const uploadBtn = document.getElementById('uploadAllCsvBtn');

        function clearPreview() { if (previewArea) previewArea.style.display = 'none'; if (previewTable) { previewTable.querySelector('thead').innerHTML = ''; previewTable.querySelector('tbody').innerHTML = ''; } if (mapAllOnly) mapAllOnly.innerHTML = ''; }

        fileInput?.addEventListener('change', function () {
            clearPreview();
            const f = this.files && this.files[0]; if (!f) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                const text = e.target.result.replace(/\r\n|\r/g, '\n');
                const lines = text.split('\n').filter(l => l.trim() != '');
                if (!lines.length) return;
                const rows = lines.slice(0, 6).map(r => { const cols = r.match(/(?:(?:"([^"]*)")|([^,]+))/g); if (cols) return cols.map(c => c.replace(/^"|"$/g, '').trim()); return r.split(',').map(c => c.trim()); });
                const header = rows[0];
                mapAllOnly.innerHTML = '';
                for (let i = 0; i < header.length; i++) { const txt = header[i] || ('Column ' + (i + 1)); const opt = document.createElement('option'); opt.value = i; opt.textContent = txt; mapAllOnly.appendChild(opt); }
                if (header.length > 0) { mapAllOnly.querySelector('option:last-child').selected = true; }
                const thead = previewTable.querySelector('thead'); const tbody = previewTable.querySelector('tbody');
                thead.innerHTML = '<tr>' + header.map(h => '<th>' + (h || '') + '</th>').join('') + '</tr>';
                tbody.innerHTML = '';
                for (let r = 1; r < rows.length; r++) { const cols = rows[r]; const tr = document.createElement('tr'); for (let c = 0; c < header.length; c++) { const td = document.createElement('td'); td.textContent = cols[c] || ''; tr.appendChild(td); } tbody.appendChild(tr); }
                previewArea.style.display = '';
            };
            reader.readAsText(f);
        });

        uploadBtn?.addEventListener('click', function () {
            const f = fileInput.files && fileInput.files[0]; if (!f) { alert('Select a CSV file'); return; }
            const acolArr = Array.from(mapAllOnly.selectedOptions).map(o => o.value);
            if (acolArr.length === 0) { alert('Choose column mapping'); return; }
            const fd = new FormData(); fd.append('file', f); fd.append('project_id', projectId); acolArr.forEach(function (v) { fd.append('all_cols[]', v); });
            uploadBtn.disabled = true; uploadBtn.textContent = 'Uploading...';
            fetch(baseDir + '/modules/projects/upload_pages.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(j => {
                    uploadBtn.disabled = false; uploadBtn.textContent = 'Upload All URLs';
                    if (j && j.success) { alert('Import complete. Added urls: ' + (j.added_grouped || 0)); location.reload(); }
                    else { alert('Import failed: ' + (j.error || j.message || 'Unknown')); }
                }).catch(err => { uploadBtn.disabled = false; uploadBtn.textContent = 'Upload All URLs'; alert('Upload error'); });
        });
    })();

}); // Close $(document).ready()
