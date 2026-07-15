<?php
// Connect to the database
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

// Start the session
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// AJAX Handler for fetching paginated data
if (isset($_POST['action']) && $_POST['action'] === 'fetch_transactions') {
    header('Content-Type: application/json');
    
    $startDate = $_POST['startDate'] ?? '';
    $endDate = $_POST['endDate'] ?? '';
    $page = max(1, intval($_POST['page'] ?? 1));
    $perPage = max(10, min(100, intval($_POST['perPage'] ?? 25)));
    $sortColumn = $_POST['sortColumn'] ?? 'datetime';
    $sortDirection = ($_POST['sortDirection'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $searchTerm = $_POST['search'] ?? '';
    
    // Allowed sort columns (whitelist for security)
    $allowedColumns = ['branch_id', 'outlet', 'region', 'reference_no', 'amount_paid', 'charge_to_partner', 'charge_to_customer', 'datetime', 'cancellation_date'];
    if (!in_array($sortColumn, $allowedColumns)) {
        $sortColumn = 'datetime';
    }
    
    $offset = ($page - 1) * $perPage;
    
    try {
        // Build WHERE clause
        $whereConditions = ["post_transaction = 'unposted'"];
        $params = [];
        $types = '';
        
        if (!empty($startDate) && !empty($endDate)) {
            $whereConditions[] = "(datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ?)";
            $params[] = $startDate;
            $params[] = $endDate;
            $params[] = $startDate;
            $params[] = $endDate;
            $types .= 'ssss';
        }
        
        // Search filter
        if (!empty($searchTerm)) {
            $whereConditions[] = "(branch_id LIKE ? OR outlet LIKE ? OR region LIKE ? OR reference_no LIKE ?)";
            $searchParam = "%{$searchTerm}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'ssss';
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Count total records
        $countQuery = "SELECT COUNT(*) as total FROM mldb.billspayment_transaction WHERE {$whereClause}";
        $countStmt = $conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        
        // Fetch paginated data with sorting
        $dataQuery = "SELECT branch_id, outlet, region, reference_no, amount_paid, charge_to_partner, charge_to_customer, datetime, cancellation_date 
                      FROM mldb.billspayment_transaction 
                      WHERE {$whereClause} 
                      ORDER BY {$sortColumn} {$sortDirection}, cancellation_date DESC 
                      LIMIT ? OFFSET ?";
        
        $dataStmt = $conn->prepare($dataQuery);
        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';
        $dataStmt->bind_param($types, ...$params);
        $dataStmt->execute();
        $result = $dataStmt->get_result();
        
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        $dataStmt->close();
        
        // Calculate totals (only for current filter, not just current page)
        $totalQuery = "SELECT 
                        COUNT(*) as count,
                        COALESCE(SUM(amount_paid), 0) as total_amount,
                        COALESCE(SUM(charge_to_partner), 0) as total_partner,
                        COALESCE(SUM(charge_to_customer), 0) as total_customer
                       FROM mldb.billspayment_transaction 
                       WHERE {$whereClause}";
        
        $totalStmt = $conn->prepare($totalQuery);
        if (!empty($params)) {
            // Remove last 2 params (LIMIT and OFFSET)
            $tempParams = array_slice($params, 0, -2);
            $tempTypes = substr($types, 0, -2);
            if (!empty($tempParams)) {
                $totalStmt->bind_param($tempTypes, ...$tempParams);
            }
        }
        $totalStmt->execute();
        $totals = $totalStmt->get_result()->fetch_assoc();
        $totalStmt->close();
        
        echo json_encode([
            'success' => true,
            'data' => $transactions,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $totalRecords,
                'totalPages' => ceil($totalRecords / $perPage)
            ],
            'totals' => $totals,
            'sort' => [
                'column' => $sortColumn,
                'direction' => $sortDirection
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching transactions: ' . $e->getMessage()
        ]);
    }
    exit;
}

// AJAX Handler for posting transactions
if (isset($_POST['action']) && $_POST['action'] === 'post_transactions') {
    header('Content-Type: application/json');
    
    $startDate = $_POST['startDate'] ?? '';
    $endDate = $_POST['endDate'] ?? '';
    
    if (empty($startDate) || empty($endDate)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid date range'
        ]);
        exit;
    }
    
    try {
        // Optimized bulk update with single query
        $updateQuery = "UPDATE mldb.billspayment_transaction 
                        SET post_transaction = 'posted' 
                        WHERE post_transaction = 'unposted' 
                        AND (datetime BETWEEN ? AND ? OR cancellation_date BETWEEN ? AND ?)";
        
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("ssss", $startDate, $endDate, $startDate, $endDate);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully posted {$affectedRows} transaction(s)",
            'affectedRows' => $affectedRows
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error posting transactions: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Store date range in session when form is submitted
if (isset($_POST['proceed'])) {
    $startingDate = $_POST['startingDate'];
    
    if (!empty($startingDate)) {
        try {
            $year = date('Y', strtotime($startingDate));
            $month = date('m', strtotime($startingDate));
            $lastDay = date('t', strtotime($startingDate));
            
            $startDate = date('Y-m-d H:i:s', strtotime($year . '-' . $month . '-01 00:00:00'));
            $endDate = date('Y-m-d H:i:s', strtotime($year . '-' . $month . '-' . $lastDay . ' 23:59:59'));
            
            $_SESSION['selected_month'] = $startingDate;
            $_SESSION['startdate'] = $startDate;
            $_SESSION['enddate'] = $endDate;
            
        } catch (Exception $e) {
            $error_message = "Error processing date: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Transaction | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">

    <style>
        :root {
            --primary-color: #dc3545;
            --success-color: #28a745;
            --dark-color: #212529;
            --light-color: #f8f9fa;
            --border-radius: 0.5rem;
            --box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        /* Modern Card Styling */
        .filter-card, .results-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            transition: box-shadow 0.3s ease;
        }

        .filter-card:hover, .results-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        .card-header-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, #c82333 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-body-custom {
            padding: 1.5rem;
        }

        /* Responsive Table Container */
        .table-wrapper {
            position: relative;
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid #dee2e6;
        }

        .data-table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .data-table thead th {
            position: sticky;
            top: 0;
            background: var(--dark-color);
            color: white;
            padding: 0.75rem;
            font-weight: 600;
            border-bottom: 2px solid #454d55;
            white-space: nowrap;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }

        .data-table thead th:hover {
            background: #343a40;
        }

        .data-table thead th.sortable::after {
            content: ' ⇅';
            opacity: 0.5;
        }

        .data-table thead th.sort-asc::after {
            content: ' ↑';
            opacity: 1;
        }

        .data-table thead th.sort-desc::after {
            content: ' ↓';
            opacity: 1;
        }

        .data-table tbody tr {
            transition: background-color 0.15s ease;
        }

        .data-table tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.05);
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
        }

        /* Pagination Controls */
        .pagination-controls {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: var(--light-color);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }

        .pagination-info {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .pagination-buttons {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination-buttons button {
            padding: 0.375rem 0.75rem;
            border: 1px solid #dee2e6;
            background: white;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .pagination-buttons button:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .pagination-buttons button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-buttons button.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Search and Filter Controls */
        .controls-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
        }

        .per-page-select {
            min-width: 120px;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(3px);
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 6px solid #f3f3f3;
            border-top: 6px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: white;
            font-size: 1.125rem;
            font-weight: 500;
            margin-top: 1rem;
            text-align: center;
        }

        /* Summary Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--primary-color);
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .card-body-custom {
                padding: 1rem;
            }

            .pagination-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .pagination-buttons {
                justify-content: center;
            }

            .data-table {
                font-size: 0.875rem;
            }

            .data-table thead th,
            .data-table td {
                padding: 0.5rem 0.25rem;
            }

            .stat-value {
                font-size: 1.25rem;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .controls-row {
                flex-direction: column;
            }

            .search-box,
            .per-page-select {
                width: 100%;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Action Buttons */
        .action-bar {
            display: flex;
            justify-content: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--light-color);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }

        .btn-modern {
            padding: 0.75rem 2rem;
            border-radius: 0.375rem;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-modern:active {
            transform: translateY(0);
        }

        .btn-post {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
            color: white;
        }

        .btn-post:hover {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="top-content">
            <div class="nav-container">
                <i id="menu-btn" class="fa-solid fa-bars"></i>
                <div class="usernav">
                    <h6><?php 
                            if($_SESSION['user_type'] === 'admin'){
                                echo $_SESSION['admin_name'];
                            }elseif($_SESSION['user_type'] === 'user'){
                                echo $_SESSION['user_name']; 
                            }else{
                                echo "GUEST";
                            }
                    ?></h6>
                    <h6 style="margin-left:5px;"><?php 
                        if($_SESSION['user_type'] === 'admin'){
                            echo "(".$_SESSION['admin_email'].")";
                        }elseif($_SESSION['user_type'] === 'user'){
                            echo "(".$_SESSION['user_email'].")";
                        }else{
                            echo "GUEST";
                        }
                    ?></h6>
                </div>
            </div>
        </div>

        <?php include '../../../templates/sidebar.php'; ?>

        <div class="loading-overlay" id="loadingOverlay">
            <div>
                <div class="loading-spinner"></div>
                <div class="loading-text" id="loadingText">Processing...</div>
            </div>
        </div>

        <div class="container-fluid" style="padding: 2rem;">
            <center><h1 style="margin-bottom: 2rem;">Post Transaction</h1></center>

            <!-- Filter Card -->
            <div class="filter-card">
                <div class="card-header-custom">
                    <h5 style="margin: 0;">
                        <i class="fas fa-filter me-2"></i>Select Month
                    </h5>
                </div>
                <div class="card-body-custom">
                    <form id="filterForm" method="post">
                        <div class="row align-items-end">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="startingDate" class="form-label fw-bold">Transaction Month:</label>
                                <input type="month" 
                                       id="startingDate" 
                                       name="startingDate" 
                                       class="form-control" 
                                       value="<?php echo $_SESSION['selected_month'] ?? ''; ?>"
                                       required>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="proceed" class="btn btn-danger w-100">
                                    <i class="fas fa-search me-2"></i>Load Transactions
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($_POST['proceed']) || isset($_SESSION['selected_month'])): ?>
            <!-- Summary Stats -->
            <div class="stats-grid" id="statsGrid" style="display: none;">
                <div class="stat-card">
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value" id="totalCount">0</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Principal Amount</div>
                    <div class="stat-value" id="totalAmount">₱0.00</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Charge to Partner</div>
                    <div class="stat-value" id="totalPartner">₱0.00</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Charge to Customer</div>
                    <div class="stat-value" id="totalCustomer">₱0.00</div>
                </div>
            </div>

            <!-- Results Card -->
            <div class="results-card">
                <div class="card-header-custom">
                    <h5 style="margin: 0;">
                        <i class="fas fa-table me-2"></i>Transaction Preview
                    </h5>
                    <span id="selectedMonth" class="badge bg-light text-dark">
                        <?php 
                        if (isset($_SESSION['selected_month'])) {
                            echo date('F Y', strtotime($_SESSION['selected_month']));
                        }
                        ?>
                    </span>
                </div>

                <div class="card-body-custom">
                    <!-- Search and Pagination Controls -->
                    <div class="controls-row">
                        <div class="search-box">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-search"></i>
                                </span>
                                <input type="text" 
                                       id="searchInput" 
                                       class="form-control" 
                                       placeholder="Search by branch, outlet, region, or reference...">
                            </div>
                        </div>
                        <div class="per-page-select">
                            <select id="perPageSelect" class="form-select">
                                <option value="10">10 per page</option>
                                <option value="25" selected>25 per page</option>
                                <option value="50">50 per page</option>
                                <option value="100">100 per page</option>
                            </select>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="sortable" data-column="branch_id">Branch ID</th>
                                    <th class="sortable" data-column="outlet">ML Branch Outlet</th>
                                    <th class="sortable" data-column="region">Region</th>
                                    <th class="sortable" data-column="reference_no">Reference Number</th>
                                    <th class="sortable" data-column="amount_paid">Amount Paid</th>
                                    <th class="sortable" data-column="charge_to_partner">Charge to Partner</th>
                                    <th class="sortable" data-column="charge_to_customer">Charge to Customer</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <tr>
                                    <td colspan="7" class="text-center">
                                        <div class="spinner-border text-danger" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination-controls">
                        <div class="pagination-info" id="paginationInfo">
                            Showing 0 to 0 of 0 entries
                        </div>
                        <div class="pagination-buttons" id="paginationButtons">
                            <!-- Generated dynamically -->
                        </div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div class="action-bar" id="actionBar" style="display: none;">
                    <button type="button" id="postButton" class="btn-modern btn-post">
                        <i class="fas fa-check-circle"></i>
                        POST TRANSACTIONS
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        let currentPage = 1;
        let perPage = 25;
        let sortColumn = 'datetime';
        let sortDirection = 'DESC';
        let searchTerm = '';
        let searchTimeout = null;
        let startDate = '<?php echo $_SESSION['startdate'] ?? ''; ?>';
        let endDate = '<?php echo $_SESSION['enddate'] ?? ''; ?>';

        // Load transactions on page load if dates are set
        <?php if (isset($_SESSION['startdate']) && isset($_SESSION['enddate'])): ?>
        loadTransactions();
        <?php endif; ?>

        // Search input handler with debouncing
        $('#searchInput').on('input', function() {
            clearTimeout(searchTimeout);
            searchTerm = $(this).val();
            searchTimeout = setTimeout(function() {
                currentPage = 1;
                loadTransactions();
            }, 500);
        });

        // Per page change handler
        $('#perPageSelect').on('change', function() {
            perPage = parseInt($(this).val());
            currentPage = 1;
            loadTransactions();
        });

        // Table header click for sorting
        $('.sortable').on('click', function() {
            const column = $(this).data('column');
            if (sortColumn === column) {
                sortDirection = sortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                sortColumn = column;
                sortDirection = 'ASC';
            }
            updateSortUI();
            loadTransactions();
        });

        function updateSortUI() {
            $('.sortable').removeClass('sort-asc sort-desc');
            $(`.sortable[data-column="${sortColumn}"]`)
                .addClass(sortDirection === 'ASC' ? 'sort-asc' : 'sort-desc');
        }

        function loadTransactions() {
            if (!startDate || !endDate) return;

            showLoading('Loading transactions...');

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'fetch_transactions',
                    startDate: startDate,
                    endDate: endDate,
                    page: currentPage,
                    perPage: perPage,
                    sortColumn: sortColumn,
                    sortDirection: sortDirection,
                    search: searchTerm
                },
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        renderTable(response.data);
                        updatePagination(response.pagination);
                        updateTotals(response.totals);
                        updateSortUI();
                        
                        // Show action bar if there are records
                        if (response.totals.count > 0) {
                            $('#actionBar').show();
                        } else {
                            $('#actionBar').hide();
                        }
                    } else {
                        showError(response.message);
                    }
                },
                error: function() {
                    hideLoading();
                    showError('Failed to load transactions. Please try again.');
                }
            });
        }

        function renderTable(data) {
            const tbody = $('#tableBody');
            tbody.empty();

            if (data.length === 0) {
                tbody.html(`
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No transactions found</p>
                            </div>
                        </td>
                    </tr>
                `);
                return;
            }

            data.forEach(function(row) {
                tbody.append(`
                    <tr>
                        <td class="text-center">${escapeHtml(row.branch_id)}</td>
                        <td>${escapeHtml(row.outlet)}</td>
                        <td>${escapeHtml(row.region)}</td>
                        <td>${escapeHtml(row.reference_no)}</td>
                        <td class="text-end">${formatCurrency(row.amount_paid)}</td>
                        <td class="text-end">${formatCurrency(row.charge_to_partner)}</td>
                        <td class="text-end">${formatCurrency(row.charge_to_customer)}</td>
                    </tr>
                `);
            });
        }

        function updatePagination(pagination) {
            const info = $('#paginationInfo');
            const buttons = $('#paginationButtons');

            const start = (pagination.page - 1) * pagination.perPage + 1;
            const end = Math.min(pagination.page * pagination.perPage, pagination.total);

            info.html(`Showing ${start.toLocaleString()} to ${end.toLocaleString()} of ${pagination.total.toLocaleString()} entries`);

            buttons.empty();

            // Previous button
            buttons.append(`
                <button class="btn-prev" ${pagination.page === 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left"></i>
                </button>
            `);

            // Page numbers
            const maxButtons = 5;
            let startPage = Math.max(1, pagination.page - Math.floor(maxButtons / 2));
            let endPage = Math.min(pagination.totalPages, startPage + maxButtons - 1);

            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }

            if (startPage > 1) {
                buttons.append(`<button class="btn-page" data-page="1">1</button>`);
                if (startPage > 2) {
                    buttons.append(`<span style="padding: 0 0.5rem;">...</span>`);
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                buttons.append(`
                    <button class="btn-page ${i === pagination.page ? 'active' : ''}" data-page="${i}">
                        ${i}
                    </button>
                `);
            }

            if (endPage < pagination.totalPages) {
                if (endPage < pagination.totalPages - 1) {
                    buttons.append(`<span style="padding: 0 0.5rem;">...</span>`);
                }
                buttons.append(`<button class="btn-page" data-page="${pagination.totalPages}">${pagination.totalPages}</button>`);
            }

            // Next button
            buttons.append(`
                <button class="btn-next" ${pagination.page === pagination.totalPages ? 'disabled' : ''}>
                    <i class="fas fa-chevron-right"></i>
                </button>
            `);

            // Attach event handlers
            $('.btn-prev').on('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    loadTransactions();
                }
            });

            $('.btn-next').on('click', function() {
                if (currentPage < pagination.totalPages) {
                    currentPage++;
                    loadTransactions();
                }
            });

            $('.btn-page').on('click', function() {
                currentPage = parseInt($(this).data('page'));
                loadTransactions();
            });
        }

        function updateTotals(totals) {
            $('#statsGrid').show();
            $('#totalCount').text(parseInt(totals.count).toLocaleString());
            $('#totalAmount').text(formatCurrency(totals.total_amount));
            $('#totalPartner').text(formatCurrency(totals.total_partner));
            $('#totalCustomer').text(formatCurrency(totals.total_customer));
        }

        // Post button handler
        $('#postButton').on('click', function() {
            Swal.fire({
                title: 'Confirm Posting',
                text: 'Are you sure you want to post these transactions? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Post Transactions',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    postTransactions();
                }
            });
        });

        function postTransactions() {
            showLoading('Posting transactions...');

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'post_transactions',
                    startDate: startDate,
                    endDate: endDate
                },
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            confirmButtonColor: '#28a745'
                        }).then(() => {
                            // Reload transactions to show updated state
                            loadTransactions();
                        });
                    } else {
                        showError(response.message);
                    }
                },
                error: function() {
                    hideLoading();
                    showError('Failed to post transactions. Please try again.');
                }
            });
        }

        function showLoading(message) {
            $('#loadingText').text(message);
            $('#loadingOverlay').addClass('active');
        }

        function hideLoading() {
            $('#loadingOverlay').removeClass('active');
        }

        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: message,
                confirmButtonColor: '#dc3545'
            });
        }

        function formatCurrency(value) {
            return '₱' + parseFloat(value).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    });
    </script>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>
