<!-- under observation start -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Admin dropdown toggle (fix for .dropdown .dropdown-btn not opening)
    document.querySelectorAll('.dropdown').forEach(function(drop) {
        var btn = drop.querySelector('.dropdown-btn');
        var content = drop.querySelector('.dropdown-content');
        if (!btn || !content) return;

        // Ensure initial state
        content.style.display = content.style.display || 'none';

        // Toggle on button click
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // prevent other document click handlers from closing immediately
            // Close other dropdowns first
            document.querySelectorAll('.dropdown .dropdown-content').forEach(function(other) {
                if (other !== content) other.style.display = 'none';
            });
            // Toggle this one
            content.style.display = (content.style.display === 'block') ? 'none' : 'block';
            btn.classList.toggle('active');
        });
    });

    // Close any open dropdown when clicking elsewhere
    document.addEventListener('click', function () {
        document.querySelectorAll('.dropdown .dropdown-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.querySelectorAll('.dropdown .dropdown-btn').forEach(function(b) {
            b.classList.remove('active');
        });
    });

    // Optional: close on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown .dropdown-content').forEach(function(content) {
                content.style.display = 'none';
            });
            document.querySelectorAll('.dropdown .dropdown-btn').forEach(function(b) {
                b.classList.remove('active');
            });
        }
    });
});
</script>
<!-- under observation end -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Side menu open/close
    var menubtn = document.getElementById('menu-btn');
    var sidemenu = document.getElementById('sidemenu');

    document.addEventListener('click', function (event) {
        if (!sidemenu) return;
        var insideMenu = sidemenu.contains(event.target);
        var insideButton = menubtn && menubtn.contains(event.target);
        if (!insideMenu && !insideButton) {
            sidemenu.style.animation = 'slide-out-to-left 0.5s ease';
            setTimeout(function () {
                sidemenu.style.display = 'none';
            }, 450);
        }
    });

    if (menubtn) {
        menubtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!sidemenu || sidemenu.style.display === 'none' || sidemenu.style.display === '') {
                sidemenu.style.animation = 'slide-in-from-left 0.5s ease';
                sidemenu.style.display = 'block';
            } else {
                sidemenu.style.animation = 'slide-out-to-left 0.5s ease';
                setTimeout(function () {
                    sidemenu.style.display = 'none';
                }, 450);
            }
        });
    }

    function isVisible(el) {
        return !!el && window.getComputedStyle(el).display !== 'none';
    }

    function setVisible(el, visible, displayMode) {
        if (!el) return;
        el.style.display = visible ? (displayMode || 'block') : 'none';
    }

    function setArrowExpanded(menuElement, expanded) {
        if (!menuElement) return;
        var openIcon = menuElement.querySelector('[id^="open-"]');
        var closedIcon = menuElement.querySelector('[id^="closed-"]');
        if (openIcon) openIcon.style.display = expanded ? 'block' : 'none';
        if (closedIcon) closedIcon.style.display = expanded ? 'none' : 'block';
    }

    // One generic hierarchy handler for all menu trees
    document.body.addEventListener('click', function (e) {
        var target = e.target;
        if (target && target.nodeType !== 1 && target.parentElement) target = target.parentElement;
        if (!target || target.nodeType !== 1) return;

        // Main menu: onetab -> tabcat / onetab-sub
        var mainBtn = target.closest('.onetab[id$="-btn"]');
        if (mainBtn) {
            var siblings = [];
            var node = mainBtn.nextElementSibling;
            while (node && !(node.classList && node.classList.contains('onetab'))) {
                siblings.push(node);
                node = node.nextElementSibling;
            }

            var tabcats = [];
            var navs = [];
            siblings.forEach(function (el) {
                if (!el.classList) return;
                if (el.classList.contains('tabcat')) tabcats.push(el);
                if (el.classList.contains('onetab-sub')) navs.push(el);
            });

            if (tabcats.length === 0 && navs.length === 0) return;

            var expandedNow = tabcats.length > 0 ? isVisible(tabcats[0]) : isVisible(navs[0]);
            var shouldExpand = !expandedNow;

            if (tabcats.length > 0) {
                tabcats.forEach(function (tabcat) {
                    setVisible(tabcat, shouldExpand, 'flex');
                    if (!shouldExpand) setArrowExpanded(tabcat, false);
                });
                // Child navs stay collapsed until tabcat click.
                navs.forEach(function (nav) { setVisible(nav, false, 'block'); });
            } else {
                navs.forEach(function (nav) { setVisible(nav, shouldExpand, 'block'); });
            }

            setArrowExpanded(mainBtn, shouldExpand);
            return;
        }

        // Sub menu category: tabcat -> onetab-sub
        var subBtn = target.closest('.tabcat[id$="-btn"]');
        if (subBtn) {
            var nav = null;
            var next = subBtn.nextElementSibling;
            while (next && !(next.classList && (next.classList.contains('tabcat') || next.classList.contains('onetab')))) {
                if (next.classList && next.classList.contains('onetab-sub')) {
                    nav = next;
                    break;
                }
                next = next.nextElementSibling;
            }
            if (!nav) return;

            var shouldShow = !isVisible(nav);
            setVisible(nav, shouldShow, 'block');
            setArrowExpanded(subBtn, shouldShow);
        }
    }, false);
});
</script>

<!-- <script>
// Table functionality
let currentPage = 1;
let rowsPerPage = 50;
let filteredData = [];

document.addEventListener('DOMContentLoaded', function() {
    // Initialize table
    initializeTable();
    
    // Search functionality
    document.getElementById('tableSearch').addEventListener('input', function() {
        filterTable();
    });
    
    // Filter functionality
    document.getElementById('statusFilter').addEventListener('change', function() {
        filterTable();
    });
    
    document.getElementById('partnerFilter').addEventListener('change', function() {
        filterTable();
    });
});

function initializeTable() {
    const tableBody = document.getElementById('tableBody');
    const rows = Array.from(tableBody.querySelectorAll('tr'));
    filteredData = rows;
    updatePagination();
}

function filterTable() {
    const searchTerm = document.getElementById('tableSearch').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    const partnerFilter = document.getElementById('partnerFilter').value;
    
    const tableBody = document.getElementById('tableBody');
    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    
    filteredData = allRows.filter(row => {
        if (row.querySelector('.no-data')) return false;
        
        const rowText = row.textContent.toLowerCase();
        const rowStatus = row.getAttribute('data-status');
        const rowPartner = row.getAttribute('data-partner');
        
        const matchesSearch = rowText.includes(searchTerm);
        const matchesStatus = !statusFilter || rowStatus === statusFilter || (statusFilter === 'normal' && !['*', '**', '***'].includes(rowStatus));
        const matchesPartner = !partnerFilter || rowPartner === partnerFilter;
        
        return matchesSearch && matchesStatus && matchesPartner;
    });
    
    currentPage = 1;
    displayTable();
    updatePagination();
}

function displayTable() {
    const tableBody = document.getElementById('tableBody');
    const startIndex = (currentPage - 1) * rowsPerPage;
    const endIndex = startIndex + rowsPerPage;
    
    // Hide all rows
    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    allRows.forEach(row => row.style.display = 'none');
    
    // Show filtered rows for current page
    const rowsToShow = filteredData.slice(startIndex, endIndex);
    rowsToShow.forEach(row => row.style.display = '');
    
    // Show no data message if no results
    if (filteredData.length === 0) {
        if (!document.querySelector('.no-data-row')) {
            const noDataRow = document.createElement('tr');
            noDataRow.className = 'no-data-row';
            noDataRow.innerHTML = '<td colspan="9" class="no-data">No data matches your search criteria</td>';
            tableBody.appendChild(noDataRow);
        }
    } else {
        const noDataRow = document.querySelector('.no-data-row');
        if (noDataRow) {
            noDataRow.remove();
        }
    }
    
    updatePaginationInfo();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredData.length / rowsPerPage);
    
    document.getElementById('prevBtn').disabled = currentPage === 1;
    document.getElementById('nextBtn').disabled = currentPage === totalPages || totalPages === 0;
    
    // Update page numbers
    const pageNumbers = document.getElementById('pageNumbers');
    pageNumbers.innerHTML = '';
    
    for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
        const pageBtn = document.createElement('button');
        pageBtn.className = 'pagination-btn' + (i === currentPage ? ' active' : '');
        pageBtn.textContent = i;
        pageBtn.onclick = () => goToPage(i);
        pageNumbers.appendChild(pageBtn);
    }
    
    displayTable();
}

function updatePaginationInfo() {
    const start = filteredData.length === 0 ? 0 : (currentPage - 1) * rowsPerPage + 1;
    const end = Math.min(currentPage * rowsPerPage, filteredData.length);
    const total = filteredData.length;
    
    document.getElementById('paginationInfo').textContent = 
        `Showing ${start}-${end} of ${total} entries`;
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredData.length / rowsPerPage);
    const newPage = currentPage + direction;
    
    if (newPage >= 1 && newPage <= totalPages) {
        currentPage = newPage;
        updatePagination();
    }
}

function goToPage(page) {
    currentPage = page;
    updatePagination();
}

function refreshTable() {
    location.reload();
}

function exportTable() {
    // Create CSV content
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "ID,Partner,Transaction Date,Reference Number,Amount Paid,Charge to Partner,Charge to Customer,Status\n";
    
    filteredData.forEach(row => {
        const cells = Array.from(row.querySelectorAll('td')).slice(0, 8); // Exclude actions column
        const rowData = cells.map(cell => {
            let text = cell.textContent.trim();
            // Remove currency symbols and format numbers
            if (cell.classList.contains('amount')) {
                text = text.replace(/[^\d.-]/g, '');
            }
            return `"${text}"`;
        }).join(',');
        csvContent += rowData + "\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "bills_payment_data.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function viewTransaction(id) {
    // Implementation for viewing transaction details
    alert(`Viewing transaction ID: ${id}`);
}

function editTransaction(id) {
    // Implementation for editing transaction
    alert(`Editing transaction ID: ${id}`);
}
</script> -->