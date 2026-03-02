/**
 * Enhanced table features: Pagination and Sorting for Unique Pages and All URLs tables
 */

$(document).ready(function() {
    // All URLs Table Pagination
    (function() {
        const table = $('#allUrlsTable');
        if (!table.length) return;
        
        const rowsPerPage = 50;
        let currentPage = 1;
        let allRows = [];
        
        function initPagination() {
            allRows = table.find('tbody tr').not(':has(td[colspan])').toArray(); // Exclude "no data" rows
            
            if (allRows.length <= rowsPerPage) {
                $('#allUrlsPagination').parent().hide();
                return;
            }
            
            $('#allUrlsPagination').parent().show();
            renderPage(1);
        }
        
        function renderPage(page) {
            currentPage = page;
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            
            // Hide all rows first
            $(allRows).each(function() {
                $(this).hide();
            });
            
            // Show current page rows
            for (let i = start; i < end && i < allRows.length; i++) {
                $(allRows[i]).show();
            }
            
            // Update info (both top and bottom)
            const total = allRows.length;
            const showing = Math.min(end, total);
            const infoText = `Showing ${start + 1}-${showing} of ${total} URLs`;
            $('#allUrlsInfo').text(infoText);
            $('#allUrlsInfoBottom').text(infoText);
            
            // Render pagination controls (both top and bottom)
            renderPaginationControls();
        }
        
        function renderPaginationControls() {
            const totalPages = Math.ceil(allRows.length / rowsPerPage);
            
            // Render both top and bottom pagination
            ['#allUrlsPagination', '#allUrlsPaginationBottom'].forEach(function(selector) {
                const pagination = $(selector);
                pagination.empty();
                
                // Previous button
                pagination.append(`
                    <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a>
                    </li>
                `);
                
                // Page numbers (show max 5 pages)
                let startPage = Math.max(1, currentPage - 2);
                let endPage = Math.min(totalPages, startPage + 4);
                
                if (endPage - startPage < 4) {
                    startPage = Math.max(1, endPage - 4);
                }
                
                for (let i = startPage; i <= endPage; i++) {
                    pagination.append(`
                        <li class="page-item ${i === currentPage ? 'active' : ''}">
                            <a class="page-link" href="#" data-page="${i}">${i}</a>
                        </li>
                    `);
                }
                
                // Next button
                pagination.append(`
                    <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${currentPage + 1}">Next</a>
                    </li>
                `);
                
                // Bind click events
                pagination.find('a').on('click', function(e) {
                    e.preventDefault();
                    if ($(this).parent().hasClass('disabled')) return;
                    const page = parseInt($(this).data('page'));
                    if (page >= 1 && page <= totalPages) {
                        renderPage(page);
                        // Scroll to top of table
                        $('html, body').animate({
                            scrollTop: table.offset().top - 100
                        }, 300);
                    }
                });
            });
        }
        
        // Initialize on page load if tab is active
        if ($('#allurls-sub-tab').hasClass('active')) {
            setTimeout(initPagination, 100);
        }
        
        // Initialize on tab show
        $('#allurls-sub-tab').on('shown.bs.tab', function() {
            setTimeout(initPagination, 100);
        });
        
        // Re-initialize when filters change
        const originalApplyAllUrlsFilters = window.applyAllUrlsFilters;
        if (typeof originalApplyAllUrlsFilters === 'function') {
            window.applyAllUrlsFilters = function() {
                originalApplyAllUrlsFilters();
                // After filtering, re-collect visible rows and paginate
                setTimeout(function() {
                    allRows = table.find('tbody tr:visible').not(':has(td[colspan])').toArray();
                    if (allRows.length > 0) {
                        renderPage(1);
                    }
                }, 50);
            };
        }
    })();
    
    // Unique Pages Table Sorting
    (function() {
        const table = $('#uniquePagesTable');
        if (!table.length) return;
        
        let sortColumn = null;
        let sortDirection = 'asc';
        
        // Make headers clickable for sorting
        const sortableColumns = [1, 2, 8]; // Page No, Page Name, Page Status
        
        table.find('thead th').each(function(index) {
            if (sortableColumns.includes(index)) {
                $(this).css('cursor', 'pointer').attr('title', 'Click to sort');
                $(this).prepend('<i class="fas fa-sort text-muted me-1"></i>');
                
                $(this).on('click', function() {
                    const newColumn = index;
                    
                    // Toggle direction if same column
                    if (sortColumn === newColumn) {
                        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortColumn = newColumn;
                        sortDirection = 'asc';
                    }
                    
                    sortTable(sortColumn, sortDirection);
                    updateSortIcons();
                });
            }
        });
        
        function sortTable(colIndex, direction) {
            const tbody = table.find('tbody');
            const rows = tbody.find('tr').toArray();
            
            rows.sort(function(a, b) {
                let aVal = $(a).find('td').eq(colIndex).text().trim();
                let bVal = $(b).find('td').eq(colIndex).text().trim();
                
                // Handle special sorting for Page No (Global first, then Page, then others)
                if (colIndex === 1) {
                    const aIsGlobal = aVal.toLowerCase().startsWith('global');
                    const bIsGlobal = bVal.toLowerCase().startsWith('global');
                    const aIsPage = aVal.toLowerCase().startsWith('page');
                    const bIsPage = bVal.toLowerCase().startsWith('page');
                    
                    // Priority: Global < Page < Others
                    if (aIsGlobal && !bIsGlobal) return direction === 'asc' ? -1 : 1;
                    if (!aIsGlobal && bIsGlobal) return direction === 'asc' ? 1 : -1;
                    if (aIsPage && !bIsPage && !bIsGlobal) return direction === 'asc' ? -1 : 1;
                    if (!aIsPage && bIsPage && !aIsGlobal) return direction === 'asc' ? 1 : -1;
                    
                    // If both are same type (both Global or both Page), sort by number
                    if ((aIsGlobal && bIsGlobal) || (aIsPage && bIsPage)) {
                        const aNum = parseInt(aVal.match(/\d+/)?.[0] || '0');
                        const bNum = parseInt(bVal.match(/\d+/)?.[0] || '0');
                        return direction === 'asc' ? aNum - bNum : bNum - aNum;
                    }
                }
                
                // String sorting for other columns
                const aLower = aVal.toLowerCase();
                const bLower = bVal.toLowerCase();
                if (aLower < bLower) return direction === 'asc' ? -1 : 1;
                if (aLower > bLower) return direction === 'asc' ? 1 : -1;
                return 0;
            });
            
            tbody.empty().append(rows);
        }
        
        function updateSortIcons() {
            table.find('thead th i.fa-sort, i.fa-sort-up, i.fa-sort-down').removeClass('fa-sort-up fa-sort-down text-primary').addClass('fa-sort text-muted');
            
            if (sortColumn !== null) {
                const icon = table.find('thead th').eq(sortColumn).find('i');
                icon.removeClass('fa-sort text-muted').addClass(sortDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down').addClass('text-primary');
            }
        }
        
        // Apply initial sort on page load (Page No. column, ascending)
        if (table.find('tbody tr').length > 0) {
            sortColumn = 1; // Page No. column
            sortDirection = 'asc';
            sortTable(sortColumn, sortDirection);
            updateSortIcons();
        }
    })();
});
