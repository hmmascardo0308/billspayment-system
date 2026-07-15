<?php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Post Transaction','Bills Payment'])) { header('Location: ../../home.php'); exit; }


// prefer explicit session values for current user email
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Transactions | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
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
                <i class="fa-solid fa-check-to-slot" aria-hidden="true"></i>
                <div>
                    <h2>Post Transactions</h2>
                    <p class="bp-section-sub">Post unposted transactions for the selected month.</p>
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-end">
                                <!-- Date filter -->
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label">Date:</label>
                                    <input type="month" class="form-control" name="monthDate" required>
                                </div>
                                <!-- Action Buttons -->
                                <div class="col-md-auto col-sm-12">
                                    <div class="d-flex align-items-end flex-wrap" style="gap:8px;">
                                        <button type="button" class="btn btn-secondary" id="proceedButton" disabled>Proceed</button>
                                        <button type="button" class="btn btn-success" id="postButton">POST</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive" id="tableContainer" style="overflow-y: auto;">
                                <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>Branch ID</th>
                                            <th>Branch Outlet</th>
                                            <th>Region</th>
                                            <th>Reference Number</th>
                                            <th>Principal Amount</th>
                                            <th>Charge To</th>
                                            <th>Settlement Status</th>
                                        </tr>
                                        <tr>
                                            <th>Partner</th>
                                            <th>Customer</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                        <div class="container-fluid">

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>
</html>