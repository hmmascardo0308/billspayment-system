<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }

if (!function_exists('has_permission') || !has_permission('Maintenance Accounts User Management')) { header('Location: ../../home.php'); exit; }

// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Fetch users from database using MySQLi

$user_status_options = 'SELECT status FROM mldb.user_form WHERE status IS NOT NULL group by status';
$status_result = mysqli_query($conn, $user_status_options);

$user_type_options = 'SELECT user_type FROM mldb.user_form WHERE user_type IS NOT NULL group by user_type';
$user_type_result = mysqli_query($conn, $user_type_options);

$users = [];
try {
    $query = "SELECT id_number, first_name, middle_name, last_name, email as username, user_type, status, last_online, date_created, created_by, modified_date, modified_by FROM mldb.user_form ORDER BY date_created DESC";
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = $row;
        }
        mysqli_free_result($result);
    } else {
        error_log("Database query error: " . mysqli_error($conn));
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">

    <style>
        /* Additional styling for better UX */
        .dropdown-item:hover {
            background-color: #dc3545;
            color: white;
        }

        .dropdown-item.active {
            background-color: #dc3545;
            color: white;
        }

        #searchInput:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        #table-info {
            margin-bottom: 10px;
            font-style: italic;
        }

        .no-results {
            background-color: #f8f9fa;
        }

        .text-success {
            color: #28a745 !important;
        }

        .text-danger {
            color: #dc3545 !important;
        }

        .text-warning {
            color: #ffc107 !important;
        }

        .table td {
            vertical-align: middle;
        }

        .badge {
            font-size: 0.75em;
        }

        .table tbody tr.selected {
            background-color: #f8d7da !important;
        }

        .table tbody tr.selected:hover {
            background-color: #f5c6cb !important;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-users-cog" aria-hidden="true"></i>
                <div>
                    <h2>User Management</h2>
                    <p class="bp-section-sub">Manage user accounts and permissions</p>
                </div>
            </div>
        </div>
        <div class="bp-card container-fluid mt-3 p-4">
            <!-- Your content goes here -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                    <div class="card-header">
                        <div class="input-group" style="display: flex; justify-content: space-between; align-items: center;">
                            <div class="input-group-append" style="display: flex; align-items: center; gap: 10px;">
                                <form action="" style="display: flex; align-items: center; gap: 10px;">
                                <input type="text" id="searchInput" class="form-control" placeholder="Search by any field..." style="width: 250px;">
                                <div class="dropdown">
                                    <button class="btn btn-danger dropdown-toggle" type="button" id="dropdownMenuButton1" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span id="userTypeText">User Type</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton1">
                                        <a class="dropdown-item" href="#" data-value="">All</a>
                                        <?php
                                        if ($user_type_result && mysqli_num_rows($user_type_result) > 0) {
                                            while ($row = mysqli_fetch_assoc($user_type_result)) {
                                            $user_type = htmlspecialchars($row['user_type']);
                                            $selected = (isset($_GET['user_type']) && $_GET['user_type'] == $user_type) ? 'active' : '';
                                            echo "<a class='dropdown-item $selected' href='#' data-value='$user_type'>" . ucfirst($user_type) . "</a>";
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-danger dropdown-toggle" type="button" id="dropdownMenuButton2" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span id="statusText">Status</span>
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                        <a class="dropdown-item" href="#" data-value="">All</a>
                                        <?php
                                        if ($status_result && mysqli_num_rows($status_result) > 0) {
                                            while ($row = mysqli_fetch_assoc($status_result)) {
                                            $status = htmlspecialchars($row['status']);
                                            $selected = (isset($_GET['status']) && $_GET['status'] == $status) ? 'active' : '';
                                            echo "<a class='dropdown-item $selected' href='#' data-value='$status'>" . ucfirst($status) . "</a>";
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <button type="button" id="clearFilters" class="btn btn-secondary">Clear</button>
                                </form>
                            </div>
                            <div class="input-group-append" style="display: flex; align-items: center; gap: 5px;">
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa fa-plus"></i> Add</button>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#editUserModal"><i class="fa fa-edit"></i> Edit</button>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"><i class="fa fa-key"></i> Reset Password</button>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#changeStatusModal"><i class="fa fa-exchange"></i> Change Status</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <table class="table table-hover" id="users-table">
                            <thead>
                                <tr>
                                <th>No.</th>
                                <th>ID Number</th>
                                <th>Username</th>
                                <th>First Name</th>
                                <th>Middle Name</th>
                                <th>Last Name</th>
                                <th>Last Online</th>
                                <th>Date Created</th>
                                <th>Date Modified</th>
                                <th>User Type</th>
                                <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($users)): ?>
                                <?php foreach ($users as $index => $user): ?>
                                    <tr data-user-id="<?php echo htmlspecialchars($user['id_number'] ?? ''); ?>"
                                        data-user-data='<?php echo json_encode($user); ?>'
                                        style="cursor: pointer;">
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($user['id_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(strtoupper($user['username'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($user['first_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($user['middle_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($user['last_name'] ?? ''); ?></td>
                                        <td>
                                            <?php if (!empty($user['last_online'])) :?>
                                            <?php echo date('F d, Y ', strtotime($user['last_online'])); ?>
                                            at
                                            <?php echo date(' g:i A', strtotime($user['last_online']));
                                            else :?>
                                            <?php echo '-'; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['date_created'])) :?>
                                            <?php echo date('F d, Y ', strtotime($user['date_created'])); ?>
                                            at
                                            <?php echo date(' g:i A', strtotime($user['date_created']));
                                            else :?>
                                            <?php echo '-'; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['modified_date'])) :?>
                                            <?php echo date('F d, Y ', strtotime($user['modified_date'])); ?>
                                            at
                                            <?php echo date(' g:i A', strtotime($user['modified_date']));
                                            else :?>
                                            <?php echo '-'; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge text-<?php echo ($user['user_type'] ?? '') === 'admin' ? 'bg-danger' : 'bg-primary'; ?>">
                                            <?php echo htmlspecialchars(ucfirst($user['user_type'] ?? '')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="<?php echo ($user['status'] ?? '') === 'Active' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo htmlspecialchars($user['status'] ?? ''); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">
                                        <i class="fa fa-users fa-2x mb-2"></i><br>
                                        No users found in the database
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                            </tfoot>
                        </table>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="addUserModalLabel">
                    <i class="fa fa-plus me-2"></i>Add New User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addUserForm">
                    <div class="row">
                        <!-- User Type Dropdown -->
                        <div class="col-md-6 mb-3">
                        <label for="addUserType" class="form-label">
                            <i class="fa fa-user-tag me-1"></i>User Type <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="addUserType" name="user_type" required>
                            <option value="">Select User Type</option>
                            <?php
                            // Reset the result pointer for user types
                            if ($user_type_result) {
                                mysqli_data_seek($user_type_result, 0);
                                while ($row = mysqli_fetch_assoc($user_type_result)) {
                                    $user_type = htmlspecialchars($row['user_type']);
                                    echo "<option value='$user_type'>" . ucfirst($user_type) . "</option>";
                                }
                            }
                            ?>
                        </select>
                        </div>
                        <!-- Username -->
                        <div class="col-md-6 mb-3">
                        <label for="addUsername" class="form-label">
                            Username
                        </label>
                        <input type="text" class="form-control" id="addUsername" name="username" placeholder="Username" disabled>
                        </div>
                    </div>

                    <div class="row">
                        <!-- ID Number -->
                        <div class="col-md-6 mb-3">
                        <label for="addIdNumber" class="form-label">
                            <i class="fa fa-id-card me-1"></i>ID Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="addIdNumber" name="id_number" placeholder="Enter ID Number" autocomplete="off" onkeypress="return (event.keyCode >= 48 && event.keyCode <= 57) || (event.keyCode == 46 && event.keyCode == 18 );" required>
                        <div class="invalid-feedback">
                            Please provide a valid ID Number.
                        </div>
                        </div>
                        <!-- Password -->
                        <div class="col-md-6 mb-3">
                        <label for="addPassword" class="form-label">
                            Password
                        </label>
                        <input type="text" class="form-control" id="addPassword" name="password" placeholder="Password" value="Mlinc1234" disabled>
                        </div>
                    </div>

                    <div class="row">
                        <!-- First Name -->
                        <div class="col-md-6 mb-3">
                        <label for="addFirstName" class="form-label">
                            <i class="fa fa-user me-1"></i>First Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="addFirstName" name="first_name" placeholder="Enter First Name" autocomplete="off" required>
                        <div class="invalid-feedback">
                            Please provide a valid first name.
                        </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Middle Name -->
                        <div class="col-md-6 mb-3">
                        <label for="addMiddleName" class="form-label">
                            <i class="fa fa-user me-1"></i>Middle Name
                        </label>
                        <input type="text" class="form-control" id="addMiddleName" name="middle_name" placeholder="Enter Middle Name (Optional)" autocomplete="off">
                        </div>
                    </div>

                    <div class="row">
                        <!-- Last Name -->
                        <div class="col-md-6 mb-3">
                        <label for="addLastName" class="form-label">
                            <i class="fa fa-user me-1"></i>Last Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="addLastName" name="last_name" placeholder="Enter Last Name" autocomplete="off" required>
                        <div class="invalid-feedback">
                            Please provide a valid last name.
                        </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                        <small class="text-muted">
                            <i class="fa fa-info-circle me-1"></i>
                            Fields marked with <span class="text-danger">*</span> are required.
                        </small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fa fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="saveUserBtn">
                    <i class="fa fa-save me-1"></i>Save User
                </button>
            </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="editUserModalLabel">
                        <i class="fa fa-edit me-2"></i>Edit User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editUserForm">
                        <div class="row">
                            <!-- User Type Dropdown -->
                            <div class="col-md-6 mb-3">
                            <label for="editUserType" class="form-label">
                                <i class="fa fa-user-tag me-1"></i>User Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="editUserType" name="user_type" required>
                                <option value="">Select User Type</option>
                                <?php
                                // Reset the result pointer for user types
                                if ($user_type_result) {
                                    mysqli_data_seek($user_type_result, 0);
                                    while ($row = mysqli_fetch_assoc($user_type_result)) {
                                        $user_type = htmlspecialchars($row['user_type']);
                                        echo "<option value='$user_type'>" . ucfirst($user_type) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                            </div>
                            <!-- Username -->
                            <div class="col-md-6 mb-3">
                            <label for="editUsername" class="form-label">
                                Username
                            </label>
                            <input type="text" class="form-control" id="editUsername" name="username" placeholder="Username" disabled>
                            </div>
                        </div>

                        <div class="row">
                            <!-- ID Number -->
                            <div class="col-md-6 mb-3">
                            <label for="editIdNumber" class="form-label">
                                <i class="fa fa-id-card me-1"></i>ID Number <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="editIdNumber" name="id_number" placeholder="Enter ID Number" autocomplete="off" onkeypress="return (event.keyCode >= 48 && event.keyCode <= 57) || (event.keyCode == 46 && event.keyCode == 18 );" required>
                            <div class="invalid-feedback">
                                Please provide a valid ID Number.
                            </div>
                            </div>
                            <!-- Empty space (no password field) -->
                            <div class="col-md-6 mb-3">
                            </div>
                        </div>

                        <div class="row">
                            <!-- First Name -->
                            <div class="col-md-6 mb-3">
                            <label for="editFirstName" class="form-label">
                                <i class="fa fa-user me-1"></i>First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="editFirstName" name="first_name" placeholder="Enter First Name" autocomplete="off" required>
                            <div class="invalid-feedback">
                                Please provide a valid first name.
                            </div>
                            </div>
                            <!-- Empty space -->
                            <div class="col-md-6 mb-3">
                            </div>
                        </div>
                        <div class="row">
                            <!-- Middle Name -->
                            <div class="col-md-6 mb-3">
                            <label for="editMiddleName" class="form-label">
                                <i class="fa fa-user me-1"></i>Middle Name
                            </label>
                            <input type="text" class="form-control" id="editMiddleName" name="middle_name" placeholder="Enter Middle Name (Optional)" autocomplete="off">
                            </div>
                        </div>
                        <div class="row">
                            <!-- Last Name -->
                            <div class="col-md-6 mb-3">
                            <label for="editLastName" class="form-label">
                                <i class="fa fa-user me-1"></i>Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="editLastName" name="last_name" placeholder="Enter Last Name" autocomplete="off" required>
                            <div class="invalid-feedback">
                                Please provide a valid last name.
                            </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12">
                            <small class="text-muted">
                                <i class="fa fa-info-circle me-1"></i>
                                Fields marked with <span class="text-danger">*</span> are required.
                            </small>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fa fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="updateUserBtn">
                        <i class="fa fa-save me-1"></i>Update User
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="resetPasswordModalLabel">
                        <i class="fa fa-key me-2"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-4">
                        <i class="fa fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h5>Are you sure you want to reset the password for:</h5>
                        <h4 class="text-danger fw-bold" id="resetPasswordUsername"></h4>
                    </div>
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle me-2"></i>
                        The password will be reset to the default: <strong>Mlinc1234</strong>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fa fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmResetPasswordBtn">
                        <i class="fa fa-check me-1"></i>Yes, Proceed
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Status Modal -->
    <div class="modal fade" id="changeStatusModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="changeStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="changeStatusModalLabel">
                        <i class="fa fa-exchange me-2"></i>Change Status
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-4">
                        <i class="fa fa-question-circle fa-3x text-warning mb-3"></i>
                        <h5 id="changeStatusMessage">Current status: <span id="currentStatusText" class="fw-bold"></span></h5>
                        <h6>Do you want to change the status to <span id="newStatusText" class="fw-bold text-primary"></span>?</h6>
                        <div class="mt-3">
                            <strong>User: </strong><span id="changeStatusUsername" class="text-danger"></span>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle me-2"></i>
                        This action will immediately update the user's access level.
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fa fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmChangeStatusBtn">
                        <i class="fa fa-check me-1"></i>Yes, Proceed
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>

<script>
    $(document).ready(function() {
        // Global variables to store current filters
        let currentUserTypeFilter = '';
        let currentStatusFilter = '';
        let currentSearchFilter = '';

        // Search input filter
        $('#searchInput').on('keyup', function() {
            currentSearchFilter = $(this).val().toLowerCase();
            filterTable();
        });

        // User Type dropdown filter
        $('#dropdownMenuButton1').next('.dropdown-menu').on('click', 'a', function(e) {
            e.preventDefault();
            currentUserTypeFilter = $(this).data('value');
            $('#userTypeText').text($(this).text());
            filterTable();
        });

        // Status dropdown filter
        $('#dropdownMenuButton2').next('.dropdown-menu').on('click', 'a', function(e) {
            e.preventDefault();
            currentStatusFilter = $(this).data('value');
            $('#statusText').text($(this).text());
            filterTable();
        });

        // Clear filters button
        $('#clearFilters').on('click', function() {
            currentSearchFilter = '';
            currentUserTypeFilter = '';
            currentStatusFilter = '';
            $('#searchInput').val('');
            $('#userTypeText').text('User Type');
            $('#statusText').text('Status');

            // Clear table row selection
            $('#users-table tbody tr').removeClass('selected');

            // Reset button states
            updateButtonStates(false);

            filterTable();
        });

        // Modal User Type selection handler
        $('#addUserType').on('change', function() {
            const selectedUserType = $(this).val();

            if (selectedUserType) {
                // Enable ID Number field when User Type is selected
                $('#addIdNumber').prop('disabled', false);

                // Disable name fields initially
                $('#addFirstName').prop('disabled', true);
                $('#addMiddleName').prop('disabled', true);
                $('#addLastName').prop('disabled', true);

                // Clear ID Number and name fields
                $('#addIdNumber').val('');
                $('#addFirstName').val('');
                $('#addMiddleName').val('');
                $('#addLastName').val('');

                // Remove any validation classes
                $('#addIdNumber').removeClass('is-valid is-invalid');
                $('#addFirstName').removeClass('is-valid is-invalid');
                $('#addMiddleName').removeClass('is-valid is-invalid');
                $('#addLastName').removeClass('is-valid is-invalid');

                // Hide any existing error messages
                $('.id-error-message').remove();
            } else {
                // Disable all fields if no User Type selected
                $('#addIdNumber').prop('disabled', true);
                $('#addFirstName').prop('disabled', true);
                $('#addMiddleName').prop('disabled', true);
                $('#addLastName').prop('disabled', true);

                // Clear all fields
                $('#addIdNumber').val('');
                $('#addFirstName').val('');
                $('#addMiddleName').val('');
                $('#addLastName').val('');
                $('#addUsername').val('');

                // Remove validation classes and error messages
                $('#addIdNumber').removeClass('is-valid is-invalid');
                $('#addFirstName').removeClass('is-valid is-invalid');
                $('#addMiddleName').removeClass('is-valid is-invalid');
                $('#addLastName').removeClass('is-valid is-invalid');
                $('.id-error-message').remove();
            }
        });

        // ID Number input handler
        $('#addIdNumber').on('input', function() {
            const idNumber = $(this).val().trim();
            const userType = $('#addUserType').val();

            // Remove existing error messages
            $('.id-error-message').remove();
            $(this).removeClass('is-valid is-invalid');

            if (idNumber && userType) {
                // Check if ID Number already exists in the table
                let idExists = false;
                $('#users-table tbody tr').each(function() {
                    const existingId = $(this).find('td:nth-child(2)').text().trim();
                    if (existingId === idNumber) {
                        idExists = true;
                        return false; // Break the loop
                    }
                });

                if (idExists) {
                    // ID Number already exists
                    $(this).addClass('is-invalid');

                    // Add error message
                    const errorMessage = $('<div class="text-danger mt-1 id-error-message" style="font-size: 0.875em;"><i class="fa fa-exclamation-circle me-1"></i>The ID Number has been already used</div>');
                    $(this).parent().append(errorMessage);

                    // Disable name fields
                    $('#addFirstName').prop('disabled', true).val('');
                    $('#addMiddleName').prop('disabled', true).val('');
                    $('#addLastName').prop('disabled', true).val('');

                    // Remove validation from name fields
                    $('#addFirstName').removeClass('is-valid is-invalid');
                    $('#addMiddleName').removeClass('is-valid is-invalid');
                    $('#addLastName').removeClass('is-valid is-invalid');

                    // Clear username
                    $('#addUsername').val('');
                } else {
                    // ID Number is valid and unique
                    $(this).addClass('is-valid');

                    // Enable name fields
                    $('#addFirstName').prop('disabled', false);
                    $('#addMiddleName').prop('disabled', false);
                    $('#addLastName').prop('disabled', false);

                    // Generate username when both ID Number and Last Name are available
                    generateUsername();
                }
            } else {
                // Empty ID Number
                $('#addFirstName').prop('disabled', true).val('');
                $('#addMiddleName').prop('disabled', true).val('');
                $('#addLastName').prop('disabled', true).val('');
                $('#addUsername').val('');

                // Remove validation from name fields
                $('#addFirstName').removeClass('is-valid is-invalid');
                $('#addMiddleName').removeClass('is-valid is-invalid');
                $('#addLastName').removeClass('is-valid is-invalid');
            }
        });

        // First Name input handler to convert to uppercase
        $('#addFirstName').on('input', function() {
            const currentValue = $(this).val();
            $(this).val(currentValue.toUpperCase());
        });

        // Middle Name input handler to convert to uppercase
        $('#addMiddleName').on('input', function() {
            const currentValue = $(this).val();
            $(this).val(currentValue.toUpperCase());
        });

        // Last Name input handler to convert to uppercase and generate username
        $('#addLastName').on('input', function() {
            const currentValue = $(this).val();
            $(this).val(currentValue.toUpperCase());
            generateUsername();
        });

        // Function to generate username based on Last Name + ID Number
        function generateUsername() {
            const lastName = $('#addLastName').val().trim();
            const idNumber = $('#addIdNumber').val().trim();

            if (lastName && idNumber) {
                // Get first 4 characters of Last Name (already uppercase from input handler)
                const lastNamePrefix = lastName.substring(0, 4);

                // Combine Last Name prefix + ID Number
                const generatedUsername = lastNamePrefix + idNumber;
                $('#addUsername').val(generatedUsername);
            } else {
                $('#addUsername').val('');
            }
        }

        // Modal reset when opened
        $('#addUserModal').on('show.bs.modal', function() {
            // Reset form
            $('#addUserForm')[0].reset();

            // Disable all fields except User Type
            $('#addIdNumber').prop('disabled', true);
            $('#addFirstName').prop('disabled', true);
            $('#addMiddleName').prop('disabled', true);
            $('#addLastName').prop('disabled', true);

            // Clear validation classes and error messages
            $('#addUserForm input, #addUserForm select').removeClass('is-valid is-invalid');
            $('.id-error-message').remove();

            // Clear username field
            $('#addUsername').val('');
        });

        // Save User button validation - FIXED AJAX CALL
        $('#saveUserBtn').on('click', function() {
            const userType = $('#addUserType').val();
            const idNumber = $('#addIdNumber').val().trim();
            const firstName = $('#addFirstName').val().trim();
            const lastName = $('#addLastName').val().trim();

            // Check if ID Number exists
            const hasIdError = $('.id-error-message').length > 0;

            if (!userType || !idNumber || !firstName || !lastName || hasIdError) {
                // Show validation errors
                if (!userType) $('#addUserType').addClass('is-invalid');
                if (!idNumber) $('#addIdNumber').addClass('is-invalid');
                if (!firstName) $('#addFirstName').addClass('is-invalid');
                if (!lastName) $('#addLastName').addClass('is-invalid');

                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error!',
                    text: 'Please fill in all required fields correctly.',
                    confirmButtonColor: '#dc3545'
                });
                return false;
            }

            // Prepare form data
            const formData = {
                user_type: userType,
                id_number: idNumber,
                first_name: firstName,
                middle_name: $('#addMiddleName').val().trim(),
                last_name: lastName,
                username: $('#addUsername').val()
            };

            // Disable the save button to prevent double submission
            $('#saveUserBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i>Saving...');

            // AJAX call to save the user - FIXED URL PATH
            $.ajax({
                url: '../../../models/saved/saved-user.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(formData),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            confirmButtonColor: '#dc3545',
                            timer: 2000,
                            showConfirmButton: false,
                            allowOutsideClick: false
                        }).then(() => {
                            // Close modal
                            $('#addUserModal').modal('hide');

                            // Add new row to table
                            addNewRowToTable(response.user_data);

                            // Reset form
                            $('#addUserForm')[0].reset();
                            $('#addIdNumber').prop('disabled', true);
                            $('#addFirstName').prop('disabled', true);
                            $('#addMiddleName').prop('disabled', true);
                            $('#addLastName').prop('disabled', true);
                            $('#addUsername').val('');

                            // Clear validation classes
                            $('#addUserForm input, #addUserForm select').removeClass('is-valid is-invalid');
                            $('.id-error-message').remove();
                        });
                    } else {
                        // Show error message
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message,
                            confirmButtonColor: '#dc3545'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'An error occurred while saving the user. Please try again.',
                        confirmButtonColor: '#dc3545'
                    });
                },
                complete: function() {
                    // Re-enable the save button
                    $('#saveUserBtn').prop('disabled', false).html('<i class="fa fa-save me-1"></i>Save User');
                }
            });
        });

        // Function to add new row to table
        function addNewRowToTable(userData) {
            // Remove "no users found" row if it exists
            $('#users-table tbody tr').each(function() {
                if ($(this).find('td[colspan]').length > 0) {
                    $(this).remove();
                }
            });

            // Get the current number of rows
            const rowCount = $('#users-table tbody tr').length + 1;

            // Format date
            const dateCreated = userData.date_created ? new Date(userData.date_created).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }) : '-';

            // Create new row HTML
            const newRowHtml = `
                <tr data-user-id="${userData.id_number}" 
                    data-user-data='${JSON.stringify(userData)}' 
                    style="cursor: pointer;">
                    <td>${rowCount}</td>
                    <td>${userData.id_number}</td>
                    <td>${userData.username}</td>
                    <td>${userData.first_name}</td>
                    <td>${userData.middle_name || ''}</td>
                    <td>${userData.last_name}</td>
                    <td>-</td>
                    <td>${dateCreated}</td>
                    <td>-</td>
                    <td>
                        <span class="badge text-${userData.user_type === 'admin' ? 'bg-danger' : 'bg-primary'}">
                            ${userData.user_type.charAt(0).toUpperCase() + userData.user_type.slice(1)}
                        </span>
                    </td>
                    <td>
                        <span class="${userData.status === 'Active' ? 'text-success' : 'text-danger'}">
                            ${userData.status}
                        </span>
                    </td>
                </tr>
            `;

            // Prepend new row to table (since we order by date_created DESC)
            $('#users-table tbody').prepend(newRowHtml);

            // Update row numbers
            updateRowNumbers();
        }

        // Main filter function
        function filterTable() {
            let visibleRows = 0;

            $('#users-table tbody tr').each(function() {
                let row = $(this);
                let showRow = true;

                // Skip the "no users found" row
                if (row.find('td[colspan]').length > 0) {
                    return;
                }

                // Get row data
                let rowText = row.text().toLowerCase();
                let userType = row.find('td:nth-child(10)').text().toLowerCase().trim();
                let status = row.find('td:nth-child(11)').text().toLowerCase().trim();

                // Apply search filter
                if (currentSearchFilter && !rowText.includes(currentSearchFilter)) {
                    showRow = false;
                }

                // Apply user type filter
                if (currentUserTypeFilter && userType !== currentUserTypeFilter.toLowerCase()) {
                    showRow = false;
                }

                // Apply status filter
                if (currentStatusFilter && status !== currentStatusFilter.toLowerCase()) {
                    showRow = false;
                }

                // Show/hide row
                if (showRow) {
                    row.show();
                    visibleRows++;
                } else {
                    row.hide();
                }
            });

            // Update row numbers for visible rows
            updateRowNumbers();

            // Show/hide "no results" message
            toggleNoResultsMessage(visibleRows);
        }

        // Update row numbers for visible rows
        function updateRowNumbers() {
            let counter = 1;
            $('#users-table tbody tr:visible').each(function() {
                // Skip the "no users found" row
                if ($(this).find('td[colspan]').length === 0) {
                    $(this).find('td:first-child').text(counter++);
                }
            });
        }

        // Show/hide no results message
        function toggleNoResultsMessage(visibleRows) {
            let noResultsRow = $('#users-table tbody tr.no-results');

            if (visibleRows === 0) {
                if (noResultsRow.length === 0) {
                    let noResultsHtml = `
                        <tr class="no-results">
                            <td colspan="11" class="text-center text-muted py-4">
                                <i class="fa fa-search fa-2x mb-2"></i><br>
                                No users match your search criteria
                            </td>
                        </tr>
                    `;
                    $('#users-table tbody').append(noResultsHtml);
                } else {
                    noResultsRow.show();
                }
            } else {
                noResultsRow.hide();
            }
        }

        // Update button states based on row selection
        function updateButtonStates(isRowSelected) {
            if (isRowSelected) {
                // When row is selected: disable Add, enable Edit/Reset Password/Change Status
                $('.btn:contains("Add")').prop('disabled', true);
                $('.btn:contains("Edit")').prop('disabled', false);
                $('.btn:contains("Reset Password")').prop('disabled', false);
                $('.btn:contains("Change Status")').prop('disabled', false);
            } else {
                // When no row selected: enable Add, disable Edit/Reset Password/Change Status
                $('.btn:contains("Add")').prop('disabled', false);
                $('.btn:contains("Edit")').prop('disabled', true);
                $('.btn:contains("Reset Password")').prop('disabled', true);
                $('.btn:contains("Change Status")').prop('disabled', true);
            }
        }

        // Row selection functionality - single click only, no toggle
        $('#users-table tbody').on('click', 'tr', function() {
            // Skip the "no users found" and "no results" rows
            if ($(this).find('td[colspan]').length > 0) {
                return;
            }

            // Remove selection from all other rows
            $('#users-table tbody tr').removeClass('selected');

            // Add selection to clicked row only
            $(this).addClass('selected');

            // Update button states - row is selected
            updateButtonStates(true);
        });

        // Initialize button states on page load
        updateButtonStates(false);

        // Edit button click event
        $('.btn:contains("Edit")').on('click', function() {
            const selectedRow = $('#users-table tbody tr.selected');

            if (selectedRow.length > 0) {
                const userData = JSON.parse(selectedRow.attr('data-user-data'));

                // Populate the edit form with existing data
                $('#editUserType').val(userData.user_type);
                $('#editIdNumber').val(userData.id_number);
                $('#editFirstName').val(userData.first_name || '');
                $('#editMiddleName').val(userData.middle_name || '');
                $('#editLastName').val(userData.last_name || '');
                $('#editUsername').val(userData.username || '');

                // Store original ID for comparison
                $('#editUserModal').data('original-id', userData.id_number);

                // Clear validation classes
                $('#editUserForm input, #editUserForm select').removeClass('is-valid is-invalid');
                $('.id-error-message').remove();

                // Show the modal
                $('#editUserModal').modal('show');
            }
        });

        // Edit First Name input handler to convert to uppercase
        $('#editFirstName').on('input', function() {
            const currentValue = $(this).val();
            $(this).val(currentValue.toUpperCase());
            generateEditUsername();
        });

        // Edit Middle Name input handler to convert to uppercase
        $('#editMiddleName').on('input', function() {
            const currentValue = $(this).val();
            $(this).val(currentValue.toUpperCase());
        });

        // Edit Last Name input handler to convert to uppercase and generate username
        $('#editLastName').on('input', function() {
            const currentValue = $(this).val();
            $(this).val(currentValue.toUpperCase());
            generateEditUsername();
        });

        // Function to generate username for edit form
        function generateEditUsername() {
            const lastName = $('#editLastName').val().trim();
            const idNumber = $('#editIdNumber').val().trim();

            if (lastName && idNumber) {
                // Get first 4 characters of Last Name (already uppercase from input handler)
                const lastNamePrefix = lastName.substring(0, 4);
                
                // Combine Last Name prefix + ID Number
                const generatedUsername = lastNamePrefix + idNumber;
                $('#editUsername').val(generatedUsername);
            } else {
                $('#editUsername').val('');
            }
        }

        // Edit ID Number input handler
        $('#editIdNumber').on('input', function() {
            const idNumber = $(this).val().trim();
            const originalId = $('#editUserModal').data('original-id');

            // Remove existing error messages
            $('.edit-id-error-message').remove();
            $(this).removeClass('is-valid is-invalid');

            if (idNumber) {
                // Only check for duplicates if ID Number has changed
                if (idNumber !== originalId) {
                    // Check if ID Number already exists in the table (excluding current user)
                    let idExists = false;
                    $('#users-table tbody tr').each(function() {
                        const existingId = $(this).find('td:nth-child(2)').text().trim();
                        const currentRowId = $(this).data('user-id');
                        
                        if (existingId === idNumber && currentRowId !== originalId) {
                            idExists = true;
                            return false; // Break the loop
                        }
                    });

                    if (idExists) {
                        // ID Number already exists
                        $(this).addClass('is-invalid');

                        // Add error message
                        const errorMessage = $('<div class="text-danger mt-1 edit-id-error-message" style="font-size: 0.875em;"><i class="fa fa-exclamation-circle me-1"></i>The ID Number has been already used</div>');
                        $(this).parent().append(errorMessage);
                    } else {
                        // ID Number is valid and unique
                        $(this).addClass('is-valid');
                    }
                } else {
                    // Same as original, so it's valid
                    $(this).addClass('is-valid');
                }

                // Generate username when ID Number changes
                generateEditUsername();
            }
        });

        // Update the Edit User Type selection handler
        $('#editUserType').on('change', function() {
            // Regenerate username when user type changes
            generateEditUsername();
        });

        // Update User button validation
        $('#updateUserBtn').on('click', function() {
            const userType = $('#editUserType').val();
            const idNumber = $('#editIdNumber').val().trim();
            const firstName = $('#editFirstName').val().trim();
            const lastName = $('#editLastName').val().trim();
            const originalId = $('#editUserModal').data('original-id');

            // Check if ID Number has errors
            const hasIdError = $('.edit-id-error-message').length > 0;

            if (!userType || !idNumber || !firstName || !lastName || hasIdError) {
                // Show validation errors
                if (!userType) $('#editUserType').addClass('is-invalid');
                if (!idNumber) $('#editIdNumber').addClass('is-invalid');
                if (!firstName) $('#editFirstName').addClass('is-invalid');
                if (!lastName) $('#editLastName').addClass('is-invalid');

                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error!',
                    text: 'Please fill in all required fields correctly.',
                    confirmButtonColor: '#dc3545'
                });
                return false;
            }

            // Prepare form data
            const formData = {
                original_id_number: originalId,
                user_type: userType,
                id_number: idNumber,
                first_name: firstName,
                middle_name: $('#editMiddleName').val().trim(),
                last_name: lastName,
                username: $('#editUsername').val()
            };

            // Disable the update button to prevent double submission
            $('#updateUserBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i>Updating...');

            // AJAX call to update the user
            $.ajax({
                url: '../../../models/updated/updated-user.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(formData),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            confirmButtonColor: '#dc3545',
                            timer: 2000,
                            showConfirmButton: false,
                            allowOutsideClick: false
                        }).then(() => {
                            // Close modal
                            $('#editUserModal').modal('hide');

                            // Update table row with new data
                            updateTableRowWithNewData(response.user_data);

                            // Clear selection
                            $('#users-table tbody tr').removeClass('selected');
                            updateButtonStates(false);
                        });
                    } else {
                        // Show error message
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message,
                            confirmButtonColor: '#dc3545'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'An error occurred while updating the user. Please try again.',
                        confirmButtonColor: '#dc3545'
                    });
                },
                complete: function() {
                    // Re-enable the update button
                    $('#updateUserBtn').prop('disabled', false).html('<i class="fa fa-save me-1"></i>Update User');
                }
            });
        });

        // Function to update table row with new data from response
        function updateTableRowWithNewData(userData) {
            const selectedRow = $('#users-table tbody tr.selected');

            if (selectedRow.length > 0) {
                // Format date
                const dateCreated = userData.date_created ? new Date(userData.date_created).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }) : '-';

                const modifiedDate = userData.modified_date ? new Date(userData.modified_date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }) : '-';

                // Update row data
                selectedRow.attr('data-user-id', userData.id_number);
                selectedRow.attr('data-user-data', JSON.stringify(userData));

                // Update row cells
                selectedRow.find('td:nth-child(2)').text(userData.id_number);
                selectedRow.find('td:nth-child(3)').text(userData.username);
                selectedRow.find('td:nth-child(4)').text(userData.first_name);
                selectedRow.find('td:nth-child(5)').text(userData.middle_name || '');
                selectedRow.find('td:nth-child(6)').text(userData.last_name);
                selectedRow.find('td:nth-child(8)').text(dateCreated);
                selectedRow.find('td:nth-child(9)').text(modifiedDate);

                // Update user type badge
                const userTypeBadge = `
                    <span class="badge ${userData.user_type === 'admin' ? 'bg-danger' : 'bg-primary'}">
                        ${userData.user_type.charAt(0).toUpperCase() + userData.user_type.slice(1)}
                    </span>
                `;
                selectedRow.find('td:nth-child(10)').html(userTypeBadge);

                // Update status
                const statusClass = userData.status === 'Active' ? 'text-success' : 'text-danger';
                selectedRow.find('td:nth-child(11)').html(`<span class="${statusClass}">${userData.status}</span>`);

                // Show a brief highlight effect
                selectedRow.css('background-color', '#d4edda');
                setTimeout(() => {
                    selectedRow.css('background-color', '');
                }, 2000);
            }
        }

        // Modal reset when edit modal is opened
        $('#editUserModal').on('show.bs.modal', function() {
            // Clear validation classes and error messages
            $('#editUserForm input, #editUserForm select').removeClass('is-valid is-invalid');
            $('.edit-id-error-message').remove();
        });

        // Reset Password button click event
        $('.btn:contains("Reset Password")').on('click', function() {
            const selectedRow = $('#users-table tbody tr.selected');

            if (selectedRow.length > 0) {
                const userData = JSON.parse(selectedRow.attr('data-user-data'));

                // Set the username in the reset password modal
                $('#resetPasswordUsername').text(userData.username);

                // Store user data in the modal for later use
                $('#resetPasswordModal').data('user-data', userData);

                // Show the reset password modal
                $('#resetPasswordModal').modal('show');
            }
        });

        // Confirm Reset Password button click event
        $('#confirmResetPasswordBtn').on('click', function() {
            const selectedRow = $('#users-table tbody tr.selected');

            if (selectedRow.length > 0) {
                const userData = JSON.parse(selectedRow.attr('data-user-data'));

                // Prepare form data for password reset
                const resetData = {
                    id_number: userData.id_number,
                    username: userData.username
                };

                // Disable the button to prevent double submission
                $('#confirmResetPasswordBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i>Resetting...');

                // AJAX call to reset the password
                $.ajax({
                    url: '../../../models/updated/updated-reset-user-password.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(resetData),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Show success message
                            Swal.fire({
                                icon: 'success',
                                title: 'Password Reset Successful!',
                                text: response.message,
                                confirmButtonColor: '#dc3545',
                                showConfirmButton: true,
                                allowEscapeKey: false,
                                allowEnterKey: false,
                                allowOutsideClick: false   // Prevent clicking outside to close
                            }).then(() => {
                                // Close the modal
                                $('#resetPasswordModal').modal('hide');

                                // Clear row selection
                                selectedRow.removeClass('selected');

                                // Update button states
                                updateButtonStates(false);
                            });
                        } else {
                            // Show error message
                            Swal.fire({
                                icon: 'error',
                                title: 'Reset Failed!',
                                text: response.message,
                                confirmButtonColor: '#dc3545',
                                timer: 2000,
                                showConfirmButton: false,
                                allowOutsideClick: false   // Prevent clicking outside to close
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'An error occurred while resetting the password. Please try again.',
                            confirmButtonColor: '#dc3545',
                            timer: 2000,
                            showConfirmButton: false,
                            allowOutsideClick: false   // Prevent clicking outside to close
                        });
                    },
                    complete: function() {
                        // Re-enable the button
                        $('#confirmResetPasswordBtn').prop('disabled', false).html('Yes, Proceed');
                    }
                });
            }
        });

        // Reset modal data when modal is hidden
        $('#resetPasswordModal').on('hidden.bs.modal', function() {
            $(this).removeData('user-data');
        });

        // Change Status button click event
        $('.btn:contains("Change Status")').on('click', function() {
            const selectedRow = $('#users-table tbody tr.selected');

            if (selectedRow.length > 0) {
                const userData = JSON.parse(selectedRow.attr('data-user-data'));
                const currentStatus = userData.status;

                // Determine new status (toggle between Active and Inactive)
                const newStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';

                // Set the modal content
                $('#currentStatusText').text(currentStatus);
                $('#newStatusText').text(newStatus);
                $('#changeStatusUsername').text(userData.username);

                // Style the current status text
                if (currentStatus === 'Active') {
                    $('#currentStatusText').removeClass('text-danger').addClass('text-success');
                } else {
                    $('#currentStatusText').removeClass('text-success').addClass('text-danger');
                }

                // Style the new status text
                if (newStatus === 'Active') {
                    $('#newStatusText').removeClass('text-danger').addClass('text-success');
                } else {
                    $('#newStatusText').removeClass('text-success').addClass('text-danger');
                }

                // Store user data and status info in the modal for later use
                $('#changeStatusModal').data('user-data', userData);
                $('#changeStatusModal').data('current-status', currentStatus);
                $('#changeStatusModal').data('new-status', newStatus);

                // Show the change status modal
                $('#changeStatusModal').modal('show');
            }
        });

        // Confirm Change Status button click event
        $('#confirmChangeStatusBtn').on('click', function() {
            const userData = $('#changeStatusModal').data('user-data');
            const currentStatus = $('#changeStatusModal').data('current-status');
            const newStatus = $('#changeStatusModal').data('new-status');

            if (userData && currentStatus && newStatus) {
                // Prepare form data for status change
                const statusData = {
                    id_number: userData.id_number,
                    username: userData.username,
                    current_status: currentStatus,
                    new_status: newStatus
                };

                // Disable the button to prevent double submission
                $('#confirmChangeStatusBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i>Changing...');

                // AJAX call to change the status
                $.ajax({
                    url: '../../../models/updated/updated-user-change-status.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(statusData),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Show success message
                            Swal.fire({
                                icon: 'success',
                                title: 'Status Changed Successfully!',
                                text: response.message,
                                confirmButtonColor: '#dc3545',
                                timer: 3000,
                                showConfirmButton: false,
                                allowOutsideClick: false   // Prevent clicking outside to close
                            }).then(() => {
                                // Close the modal
                                $('#changeStatusModal').modal('hide');

                                // Update the table row with new status
                                updateTableRowStatus(response.new_status);

                                // Clear row selection
                                $('#users-table tbody tr.selected').removeClass('selected');

                                // Update button states
                                updateButtonStates(false);
                            });
                        } else {
                            // Show error message
                            Swal.fire({
                                icon: 'error',
                                title: 'Status Change Failed!',
                                text: response.message,
                                confirmButtonColor: '#dc3545',
                                timer: 2000,
                                showConfirmButton: false,
                                allowOutsideClick: false   // Prevent clicking outside to close
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'An error occurred while changing the status. Please try again.',
                            confirmButtonColor: '#dc3545',
                            timer: 2000,
                            showConfirmButton: false,
                            allowOutsideClick: false   // Prevent clicking outside to close
                        });
                    },
                    complete: function() {
                        // Re-enable the button
                        $('#confirmChangeStatusBtn').prop('disabled', false).html('<i class="fa fa-check me-1"></i>Yes, Proceed');
                    }
                });
            }
        });

        // Function to update table row status
        function updateTableRowStatus(newStatus) {
            const selectedRow = $('#users-table tbody tr.selected');

            if (selectedRow.length > 0) {
                // Update the status column
                const statusCell = selectedRow.find('td:nth-child(11) span');
                statusCell.text(newStatus);

                // Update the status color class
                if (newStatus === 'Active') {
                    statusCell.removeClass('text-danger').addClass('text-success');
                } else {
                    statusCell.removeClass('text-success').addClass('text-danger');
                }

                // Update the data attribute with new status
                const currentData = JSON.parse(selectedRow.attr('data-user-data'));
                currentData.status = newStatus;
                currentData.date_modified = new Date().toISOString().slice(0, 19).replace('T', ' ');
                selectedRow.attr('data-user-data', JSON.stringify(currentData));
            }
        }

        // Reset modal data when change status modal is hidden
        $('#changeStatusModal').on('hidden.bs.modal', function() {
            $(this).removeData('user-data');
            $(this).removeData('current-status');
            $(this).removeData('new-status');
        });
    });
</script>

<?php include '../../../templates/footer.php'; ?>
</html>



