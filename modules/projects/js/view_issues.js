/**
 * view_issues.js
 * Main entry point for Issues functionality
 * Loads all modular components and maintains backward compatibility
 */

(function () {
    'use strict';

    // Check if we're on a page that needs issues functionality
    try {
        var list = document.getElementById('issuesPageList');
        if (!list) {
            // Element not found - likely on detail page
        } else {
            var rows = list.querySelectorAll('.issues-page-row');
        }
    } catch (e) { 
        if (typeof window.showToast === 'function') { 
            showToast('Issue script error: ' + e, 'danger'); 
        } 
    }

    // Load all modules in the correct order (relative to current script)
    var moduleScripts = [
        './view_issues-core.js',
        './view_issues-utilities.js', 
        './view_issues-modals.js',
        './view_issues-interactions.js',
        './view_issues-init.js'
    ];

    var loadedModules = 0;
    var totalModules = moduleScripts.length;

    function loadModuleScript(src, callback) {
        var script = document.createElement('script');
        script.src = src;
        script.onload = callback;
        script.onerror = function() {
            console.error('Failed to load module:', src, 'Path attempted:', src);
            // Try fallback path
            if (src.indexOf('/modules/') === -1) {
                var fallbackSrc = '/modules/projects/js/' + src.split('/').pop();
                console.log('Trying fallback path:', fallbackSrc);
                script.src = fallbackSrc;
            } else {
                callback();
            }
        };
        document.head.appendChild(script);
    }

    function onModuleLoaded() {
        loadedModules++;
        if (loadedModules >= totalModules) {
            // All modules loaded, initialization will be handled by IssuesInit
            console.log('All issues modules loaded successfully');
        }
    }

    // Load all modules
    moduleScripts.forEach(function(src) {
        loadModuleScript(src, onModuleLoaded);
    });

    // Legacy compatibility - expose some functions that might be called directly
    window.editFinalIssue = function (id) {
        if (window.IssuesModals && window.IssuesCore) {
            var issue = IssuesCore.data.pages[IssuesCore.data.selectedPageId].final.find(function (i) { 
                return String(i.id) === String(id); 
            });
            if (issue) IssuesModals.openFinalEditor(issue);
        }
    };

})();
