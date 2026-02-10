// issue_title_field.js
// Handles issue title input for Final Issue Modal with Apply Preset button

(function() {
    function getApiUrl() {
        const base = (window.ProjectConfig && window.ProjectConfig.baseDir) ? window.ProjectConfig.baseDir : '';
        return base + '/api/issue_titles.php';
    }

    // Inject input field
    function injectIssueTitleField(defaultValue) {
        const wrap = document.getElementById('customIssueTitleWrap');
        if (!wrap) {
            return;
        }
        
        // Check if field already exists - don't re-inject to prevent reset
        const existingInput = document.getElementById('customIssueTitle');
        if (existingInput) {
            // Only update the value if explicitly provided (not undefined or null)
            // Allow empty string to clear the field if explicitly passed
            if (defaultValue !== undefined && defaultValue !== null) {
                existingInput.value = defaultValue;
            }
            return;
        }
        
        wrap.innerHTML = '';
        const label = document.createElement('label');
        label.className = 'form-label fw-bold mb-1';
        label.textContent = 'Issue Title';
        
        // Create input with button wrapper
        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-lg';
        input.id = 'customIssueTitle';
        input.placeholder = 'Type or search issue title...';
        input.autocomplete = 'off';
        input.value = defaultValue || '';
        
        // Apply Preset button
        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'btn btn-outline-primary';
        applyBtn.id = 'applyPresetBtn';
        applyBtn.innerHTML = '<i class="fas fa-magic"></i> Apply Preset';
        applyBtn.title = 'Load preset data for selected title';
        applyBtn.style.display = 'none'; // Hidden by default
        
        // Suggestion dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'dropdown-menu w-100';
        dropdown.style.position = 'absolute';
        dropdown.style.zIndex = 10610;
        dropdown.style.maxHeight = '220px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.display = 'none';
        
        inputGroup.appendChild(input);
        inputGroup.appendChild(applyBtn);
        
        wrap.appendChild(label);
        wrap.appendChild(inputGroup);
        wrap.appendChild(dropdown);
        
        // Suggestion logic
        let timer = null;
        let isMouseOverDropdown = false;
        let selectedPresetTitle = null;
        
        // Apply Preset button click handler
        applyBtn.addEventListener('click', function() {
            const title = input.value.trim();
            if (title) {
                loadPresetData(title);
                applyBtn.style.display = 'none';
                if (window.showToast) {
                    showToast('Preset applied: ' + title, 'success');
                }
            }
        });
        
        // Show/hide Apply button based on input
        input.addEventListener('input', function() {
            // Hide apply button when typing
            applyBtn.style.display = 'none';
            selectedPresetTitle = null;
            
            clearTimeout(timer);
            const val = input.value.trim();
            if (val.length < 2) {
                dropdown.style.display = 'none';
                return;
            }
            timer = setTimeout(() => fetchSuggestions(val), 250);
        });
        
        input.addEventListener('focus', function() {
            const val = input.value.trim();
            // Show suggestions on focus if there's text, or fetch all presets if empty
            if (val.length >= 2) {
                fetchSuggestions(val);
            } else {
                // Fetch all presets when focusing on empty field
                fetchAllPresets();
            }
        });
        
        input.addEventListener('blur', function() {
            // Only hide if mouse is not over dropdown
            setTimeout(() => { 
                if (!isMouseOverDropdown) {
                    dropdown.style.display = 'none'; 
                }
            }, 200);
        });
        
        // Track mouse over dropdown to prevent blur from hiding it
        dropdown.addEventListener('mouseenter', function() {
            isMouseOverDropdown = true;
        });
        dropdown.addEventListener('mouseleave', function() {
            isMouseOverDropdown = false;
        });
        
        function fetchAllPresets() {
            const apiUrl = getApiUrl();
            if (!apiUrl || apiUrl.includes('undefined')) {
                return;
            }
            const projectType = (window.ProjectConfig && window.ProjectConfig.projectType) ? window.ProjectConfig.projectType : 'web';
            // Fetch presets with empty query to get all
            fetch(apiUrl + '?q=&project_type=' + encodeURIComponent(projectType) + '&presets_only=1', { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    if (data && Array.isArray(data.titles) && data.titles.length) {
                        data.titles.forEach(title => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'dropdown-item';
                            item.textContent = title;
                            item.onclick = () => {
                                input.value = title;
                                dropdown.style.display = 'none';
                                // Show Apply Preset button
                                selectedPresetTitle = title;
                                applyBtn.style.display = 'block';
                                };
                            dropdown.appendChild(item);
                        });
                        dropdown.style.display = 'block';
                    } else {
                        }
                })
                .catch(err => {
                    });
        }
        
        function fetchSuggestions(query) {
            // Safety check: don't fetch if apiUrl is invalid
            const apiUrl = getApiUrl();
            if (!apiUrl || apiUrl.includes('undefined')) {
                dropdown.style.display = 'none';
                return;
            }
            const projectType = (window.ProjectConfig && window.ProjectConfig.projectType) ? window.ProjectConfig.projectType : 'web';
            fetch(apiUrl + '?q=' + encodeURIComponent(query) + '&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    if (data && Array.isArray(data.titles) && data.titles.length) {
                        data.titles.forEach(title => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'dropdown-item';
                            item.textContent = title;
                            item.onclick = () => {
                                input.value = title;
                                dropdown.style.display = 'none';
                                // Show Apply Preset button
                                selectedPresetTitle = title;
                                applyBtn.style.display = 'block';
                                };
                            dropdown.appendChild(item);
                        });
                        dropdown.style.display = 'block';
                    } else {
                        dropdown.style.display = 'none';
                    }
                })
                .catch(err => { 
                    dropdown.style.display = 'none'; 
                });
        }
        
        function loadPresetData(title) {
            // Fetch preset details and populate form
            const apiUrl = getApiUrl();
            if (!apiUrl || apiUrl.includes('undefined')) return;
            
            const projectType = (window.ProjectConfig && window.ProjectConfig.projectType) ? window.ProjectConfig.projectType : 'web';
            const presetApiUrl = apiUrl.replace('issue_titles.php', 'issue_presets.php');
            
            fetch(presetApiUrl + '?action=get_by_title&title=' + encodeURIComponent(title) + '&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    if (data && data.preset) {
                        // Populate description if available
                        if (data.preset.description_html) {
                            const descField = document.getElementById('finalIssueDetails');
                            if (descField) {
                                if (window.jQuery && jQuery.fn.summernote && jQuery(descField).summernote) {
                                    jQuery(descField).summernote('code', data.preset.description_html);
                                } else {
                                    descField.value = data.preset.description_html;
                                }
                            }
                        }
                        
                        // Populate metadata fields if available
                        if (data.preset.metadata_json) {
                            let metadata = data.preset.metadata_json;
                            if (typeof metadata === 'string') {
                                try {
                                    metadata = JSON.parse(metadata);
                                } catch (e) {
                                    return;
                                }
                            }
                            
                            // Map common metadata fields
                            const fieldMap = {
                                'severity': 'severity',
                                'priority': 'priority',
                                'status': 'finalIssueStatus',
                                'wcag_sc': 'wcag_sc',
                                'wcag_level': 'wcag_level',
                                'gigw': 'gigw',
                                'is17802': 'is17802',
                                'users_affected': 'users_affected'
                            };
                            
                            Object.keys(metadata).forEach(key => {
                                const fieldId = fieldMap[key] || key;
                                let field = document.getElementById(fieldId);
                                
                                // Try with finalIssue prefix if not found
                                if (!field && !fieldId.startsWith('finalIssue')) {
                                    field = document.getElementById('finalIssue' + key.charAt(0).toUpperCase() + key.slice(1));
                                }
                                
                                // Try looking in metadata container
                                if (!field) {
                                    field = document.querySelector('#finalIssueMetadataContainer [data-field="' + key + '"]');
                                }
                                
                                if (field) {
                                    const value = metadata[key];
                                    if (Array.isArray(value)) {
                                        // For multi-select fields
                                        if (window.jQuery && jQuery.fn.select2) {
                                            jQuery(field).val(value).trigger('change');
                                        } else {
                                            Array.from(field.options).forEach(opt => {
                                                opt.selected = value.includes(opt.value);
                                            });
                                        }
                                    } else {
                                        // For single value fields
                                        field.value = value;
                                        if (window.jQuery && jQuery.fn.select2) {
                                            jQuery(field).trigger('change');
                                        }
                                    }
                                    }
                            });
                        }
                    }
                })
                .catch(err => {});
        }
    }

    // Expose for modal open
    window.injectIssueTitleField = injectIssueTitleField;

    // Note: Field injection is now handled by openFinalEditor() in view_issues.js
    // No need for modal shown event listener as it causes race conditions
})();
