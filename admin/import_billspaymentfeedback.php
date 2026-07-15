<?php
session_start();

require_once __DIR__ . '/../config/config.php';
require '../vendor/autoload.php';

if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
    exit();
}

// Fetch partner options from the database
$stmt = $conn->prepare("SELECT partner_id, partner_name FROM masterdata.partner_masterfile where transaction_path='feedback'");
$stmt->execute();
$result = $stmt->get_result();
$partners = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import File | FEEDBACK</title>
    <link rel="stylesheet" href="../assets/css/billspaymentFeedbackFile.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="../node_modules/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../node_modules/bootstrap/dist/css/bootstrap.css">
    <script src="../node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../node_modules/bootstrap/dist/js/bootstrap.bundle.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Include SweetAlert JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


    <link rel="icon" href="../images/MLW logo.png" type="image/png">
    <style>
        /* for table */
        .table-container {
            position: relative;
            max-width: 70%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 215px);
            margin: 20px; 
        }
        .table-container1 {
            position: relative;
            max-width: 60%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 500px);
            margin: 20px; 
        }

        .tabcont {
            position: relative;
            max-width: 76%;
            max-height: calc(100vh - 500px);
            margin: 20px; 
        }

        .table-container-showcadno {
            left: 25%;
            position: relative;
            height: 200px;
            max-width: 50%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 200px);
            margin: 3px; 
        }

        .table-container-error {
            position: relative;
            max-width: 100%;
            overflow-x: auto; /* Enable horizontal scrolling */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: calc(100vh - 200px); 
            margin: 20px; 
        }
        .file-table {
            width: 100%;
            border-collapse: collapse;
            overflow: auto;
            max-height: 855px;
        }
        .file-table th, .file-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .file-table th {
            background-color: #f2f2f2;
        }
        thead th {
            top: 0;
            position: sticky;
            z-index: 20;

        }
        .error-row {
            background-color: #ed968c;
        }
        #showEP {
            display: none;
        }
        .custom-select-wrapper {
            position: relative;
            display: inline-block;
            margin-left: 20px;
        }
        select {
            width: 200px;
            padding: 10px;
            font-size: 16px;
            border: 2px solid #ccc;
            border-radius: 15px;
            background-color: #f9f9f9;
            -webkit-appearance: none; /* Remove default arrow in WebKit browsers */
            -moz-appearance: none; /* Remove default arrow in Firefox */
            appearance: none; /* Remove default arrow in most modern browsers */
        }
        .custom-arrow {
            position: absolute;
            top: 50%;
            right: 10px;
            width: 0;
            height: 0;
            padding: 0;
            margin-top: -2px;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 5px solid #333;
            pointer-events: none;
        }
        .import-file {
            /* background-color: #3262e6; */
            height: 70px;
            width: auto;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        input[type="file"]::file-selector-button {
            border-radius: 15px;
            padding: 0 16px;
            height: 35px;
            cursor: pointer;
            background-color: white;
            border: 1px solid rgba(0, 0, 0, 0.16);
            box-shadow: 0px 1px 0px rgba(0, 0, 0, 0.05);
            margin-right: 16px;
            transition: background-color 200ms;
        }

        input[type="file"]::file-selector-button:hover {
            background-color: #f3f4f6;
        }

        input[type="file"]::file-selector-button:active {
            background-color: #e5e7eb;
        }

        .upload-btn {
            background-color: #db120b; 
            border: none;
            color: white;
            padding: 9px 15px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            border-radius: 20px;
            cursor: pointer;
        }
        /* loading screen */
        #loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            z-index: 9999;
        }

        .loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .usernav {
            display: flex;
            justify-content: left;
            align-items: center;
            font-size: 10px;
            font-weight: bold;
            margin: 0;
        }

        .nav-list {
            list-style: none;
            display: flex;
        }
        
        .nav-list li {
            margin-right: 20px;
        }
        
        .nav-list li a {
            text-decoration: none;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
            padding: 5px 20px 5px 20px;
        }
        
        .nav-list li #user {
            text-decoration: none;
            color: #d70c0c;
            font-size: 12px;
            font-weight: bold;
            padding: 5px 20px 5px 20px;
        }
        
        .nav-list li a:hover {
            color: #d70c0c;
            background-color: whitesmoke;
        }
        
        .nav-list li #user:hover {
            color: #d70c0c;
        }

        .dropdown-btn {
            position: relative;
            display: inline-block;
            background-color: transparent;
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            width: 150px;
            padding: 5px 20px 5px 20px;
            transition: background-color 0.3s ease;
        }
        
        .dropdown-btn:hover {
            position: relative;
            display: inline-block;
            background-color: whitesmoke;
            border: none;
            color: #d70c0c;
            width: 150px;
            font-weight: 700;
            font-size: 12px;
            padding: 5px;
            transition: background-color 0.3s ease;
        }
        .dropdown:hover .dropdown-content {
            display: block;
            z-index: 1;
            text-align: center;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
        }
        
        .logout a {
            text-decoration: none;
            background-color: transparent;
            padding: 5px 10px 5px 10px;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            transition: background-color 0.3s ease;
        }
        
        
        .logout a:hover {
            text-decoration: none;
            background-color: black;
            padding: 5px 10px 5px 10px;
            color: #d70c0c;
            transition: background-color 0.3s ease;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 150px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            text-align: center;
        }
        
        .dropdown-content a {
            color: black;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 12px;
            text-align: left;
            font-weight: bold;
        }
        
        .dropdown-content a:hover {
            background-color: #d70c0c;
            color: white;
        }
        body {
            font-family: "Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Helvetica, Arial, sans-serif; 
        }
        .row{
            margin-top: calc(5* var(--bs-gutter-y));
            --bs-gutter-x: 0;
            --bs-gutter-y: 0;
            display: flex;
            margin-right: calc(-0.5* var(--bs-gutter-x));
            margin-left: calc(0* var(--bs-gutter-x));
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>
    <script src="../node_modules/jspdf/dist/jspdf.umd.min.js"></script>
    <script src="../node_modules/jspdf/dist/jspdf.umd.js"></script>
    
</head>
<body>
    <div>
        <div class="top-content">
            <div class="usernav">
                <h4 style="margin-right: 0.5rem; font-size: 1rem;"><?php echo $_SESSION['admin_name'] ?></h4>
                <h5 style="font-size: 1rem;"><?php echo "- ".$_SESSION['admin_email']."" ?></h5>
            </div>
            <?php include '../templates/admin/sidebar.php'; ?>
        </div>
    </div>
    <center><h2>Billspayment Feedback<span style="font-size: 22px; color: red;">[Import]</span></h2></center>
    <div id="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
    </div>
    <div class="import-file">
        <form id="uploadForm" action="../models/saved/saved_import_billspaymentfeedback.php" method="post" enctype="multipart/form-data">
            <div class="custom-select-wrapper">
                <label for="option1">PARTNER'S: </label>
                <select name="option1" id="option1" required>
                    <option value="">Select Partner's</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?php echo $partner['partner_id']; ?>"><?php echo $partner['partner_name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="custom-arrow"></div>
            </div>
            <div class="custom-select-wrapper">
                <input type="file" id="anyFile" name="anyFile" required />
                <input type="submit" class="upload-btn" name="upload" value="Upload">
            </div>
        </form>
    </div>

    <script>
        document.getElementById('uploadForm').addEventListener('submit', function() {
            // Show loading overlay when form is submitted
            document.getElementById('loading-overlay').style.display = 'block';
        });
    </script>

</body>
</html>
