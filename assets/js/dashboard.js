/**
 * Dashboard Common Functions
 * Shared logic for client analytics dashboard
 */

/**
 * Export dashboard data in specified format
 * @param {string} format - 'pdf' or 'excel'
 */
function exportDashboard(format) {
    // Get parameters from attributes or globals
    const clientId = window.actualClientId || document.body.dataset.clientId;
    const projectId = window.selectedProjectId || document.getElementById('projectFilter')?.value || '';
    const baseUrl = window.baseUrl || '/PMS';
    
    if (!clientId || clientId === '0') {
        alert('Cannot export: Client information not found.');
        return;
    }
    
    // Provide visual feedback
    const btn = event ? event.target.closest('.btn') : null;
    let originalHtml = '';
    if (btn) {
        originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        btn.disabled = true;
    }
    
    // Construct export URL
    let exportUrl = `${baseUrl}/api/client_export.php?client_id=${clientId}&format=${format}`;
    if (projectId) {
        exportUrl += `&project_id=${projectId}`;
    }
    
    if (format === 'pdf') {
        window.open(exportUrl, '_blank');
        // Reset button state
        setTimeout(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }, 2000);
    } else {
        window.location.href = exportUrl;
        // Reset button state after a delay
        setTimeout(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }, 2000);
    }
}

/**
 * Refresh dashboard data
 */
function refreshDashboard() {
    const btn = event ? event.target.closest('.btn') : null;
    if (btn) {
        btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Refreshing...';
        btn.disabled = true;
    }
    window.location.reload();
}

/**
 * Basic table sorting functionality
 * @param {HTMLTableElement} table 
 * @param {number} n - Column index
 */
function sortTable(table, n) {
    var rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
    switching = true;
    // Set the sorting direction to ascending:
    dir = "asc";
    /* Make a loop that will continue until
    no switching has been done: */
    while (switching) {
        // Start by saying: no switching is done:
        switching = false;
        rows = table.rows;
        /* Loop through all table rows (except the
        first, which contains table headers): */
        for (i = 1; i < (rows.length - 1); i++) {
            // Start by saying there should be no switching:
            shouldSwitch = false;
            /* Get the two elements you want to compare,
            one from current row and one from the next: */
            x = rows[i].getElementsByTagName("TD")[n];
            y = rows[i + 1].getElementsByTagName("TD")[n];
            /* Check if the two rows should switch place,
            based on the direction, asc or desc: */
            if (dir == "asc") {
                if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
                    // If so, mark as a switch and break the loop:
                    shouldSwitch = true;
                    break;
                }
            } else if (dir == "desc") {
                if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) {
                    // If so, mark as a switch and break the loop:
                    shouldSwitch = true;
                    break;
                }
            }
        }
        if (shouldSwitch) {
            /* If a switch has been marked, make the switch
            and mark that a switch has been done: */
            rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
            switching = true;
            // Each time a switch is done, increase this count by 1:
            switchcount ++;
        } else {
            /* If no switching has been done AND the direction is "asc",
            set the direction to "desc" and run the while loop again. */
            if (switchcount == 0 && dir == "asc") {
                dir = "desc";
                switching = true;
            }
        }
    }
}
