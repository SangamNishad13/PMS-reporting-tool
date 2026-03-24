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

    // Simple approach: load modules using document.write for synchronous loading
    var modules = [
        'view_issues-core.js',
        'view_issues-utilities.js', 
        'view_issues-modals.js',
        'view_issues-interactions.js',
        'view_issues-init.js'
    ];

    // Get the current script path
    var scripts = document.getElementsByTagName('script');
    var currentScript = scripts[scripts.length - 1];
    var scriptPath = currentScript.src.substring(0, currentScript.src.lastIndexOf('/') + 1);

    console.log('Loading issues modules from:', scriptPath);

    // Load modules synchronously
    modules.forEach(function(module) {
        try {
            document.write('<script src="' + scriptPath + module + '"></script>');
        } catch (e) {
            console.error('Failed to load module:', module, e);
        }
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
