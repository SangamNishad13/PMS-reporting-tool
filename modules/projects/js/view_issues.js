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

    // Simple approach: use absolute path from window.location
    var currentPath = window.location.pathname;
    var pathParts = currentPath.split('/');
    var basePath = window.location.origin + '/' + pathParts[1] + '/modules/projects/js/';
    
    console.log('Current path:', currentPath);
    console.log('Base path for modules:', basePath);

    // Load all modules in the correct order
    var moduleScripts = [
        basePath + 'view_issues-core.js',
        basePath + 'view_issues-utilities.js', 
        basePath + 'view_issues-modals.js',
        basePath + 'view_issues-interactions.js',
        basePath + 'view_issues-init.js'
    ];

    var loadedModules = 0;
    var totalModules = moduleScripts.length;

    function loadModuleScript(src, callback) {
        var script = document.createElement('script');
        script.src = src + '?v=' + Date.now(); // Add cache buster
        script.onload = callback;
        script.onerror = function() {
            console.error('Failed to load module:', src);
            console.log('Current path:', currentPath);
            console.log('Base path:', basePath);
            callback();
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
