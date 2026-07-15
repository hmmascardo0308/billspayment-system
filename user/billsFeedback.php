<?php
// Start the session
session_start();
// error_reporting(0);

// Connect to the database
include '../config/config.php';
require '../vendor/autoload.php';

use League\Csv\Reader;
use PhpParser\ParserFactory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Redirect to login page if the user is not logged in
if (!isset($_SESSION['user_name'])) {
    header('Location: login_form.php');
    exit();
}

include '../models/phpfunctions/functions.php';
include '../models/user/save_userbillsfeedback.php';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M Lhuillier Bills Payment Feedback</title>
    <!-- Link CSS and JS libraries -->
    <link href="../assets/css/import_billsPayment.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/jquery.dataTables.min.css" />
    <script type="text/javascript" src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/scroller/2.1.1/js/dataTables.scroller.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <!-- Include SweetAlert JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php include '../templates/user/header.php'; ?>
</head>

<body>
    <div class="container">
        <div class="top-content">
            <div class="nav-container">
                <i id="menu-btn" class="fa-solid fa-bars"></i>
                <div class="usernav">
                    <h4><?php echo $_SESSION['user_name']; ?></h4>
                    <h4 style="margin-left:5px;"><?php echo "(" . $_SESSION['user_email'] . ")"; ?></h4>
                </div>
            </div>
        </div>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../templates/user/sidebar.php'; ?>
        <div class="card">
            <div class="card-body">
                <form action="" method="POST" enctype="multipart/form-data" class="form">
                    <div class="cancel_date">
                        <label for="">Select Partner's:</label>
                        <select class="cdate" name="option1" id="option1" required>
                            <option value="">Select Partner's</option>
                            <option value="PAGIBIG">PAG-IBIG</option>
                        </select>
                        <div class="custom-arrow"></div>
                    </div>
                    <div class="cancel_date">
                        <label for="">Select Extension :</label>
                        <select class="cdate" name="option2" id="option2" required>
                            <option value="">Select Extension File</option>
                            <option value=".mcl">.mcl</option>
                            <option value=".xls">.xls, .xlsx</option>
                        </select>
                        <div class="custom-arrow"></div>
                    </div>
                    <div class="choose-file">
                        <div class="import-file">
                            <input type="file" name="anyFile" class="form-control" required />
                            <input type="submit" class="upload-btn" name="upload" value="Upload">
                        </div>
                    </div>
                </form>
                <div class="display_data">
                    
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="table-container">
                <div class="leg_wrap2">
                    <table class="file-table hover">
                        <thead>
                            <?php
                                if (isset($_POST['upload']) && $_POST['option1'] === 'PAGIBIG' && $_POST['option2'] === '.mcl') {
                                    echo "<tr style='white-space: nowrap !important;'>
                                        <th>Account Number</th>
                                        <th>Loan Type</th>
                                        <th>Amount Paid</th>
                                        <th>Transaction Date</th>
                                        <th>Reference No.</th>
                                        <th></th>
                                        <th>Branch Name</th>
                                        <th>Remarks</th>
                                    </tr>";
                                } elseif (isset($_POST['upload']) && $_POST['option1'] === 'PAGIBIG' && $_POST['option2'] === '.xls') {
                                    echo '<tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Control No.</th>
                                        <th>Reference No.</th>
                                        <th>Account No.</th>
                                        <th>Amount Paid</th>
                                        <th>Charge to Customer</th>
                                        <th>Charge to Partner</th>
                                        <th>ML Branch Outlet</th>
                                        <th>Remarks</th>
                                    </tr>';
                                } else{
                                    echo "<tr style='white-space: nowrap !important;'>
                                        <th>Account Number</th>
                                        <th>Loan Type</th>
                                        <th>Amount Paid</th>
                                        <th>Transaction Date</th>
                                        <th>Reference No.</th>
                                        <th></th>
                                        <th>Branch Name</th>
                                        <th>Remarks</th>
                                    </tr>";
                                }
                            ?>
                        </thead>
                        <tbody>
                            <?php

                            if (isset($_POST['upload'])) {
                                $file = $_FILES['anyFile']['tmp_name'];
                                $file_name = $_FILES['anyFile']['name'];
                                $file_name_array = explode('.', $file_name);
                                $extension = end($file_name_array);
                                $_SESSION['option2'] = $_POST['option2']; // Persist selected file type
                                $_SESSION['option1'] = $_POST['option1']; // Persist selected file type

                                // Reset previous valid rows
                                $_SESSION['validRows'] = [];
                                $messages = [];
                                $validRows = [];
                                $recordDetails = [];
                                $error_description = '';


                                if ($_SESSION['option1'] === 'PAGIBIG' && $_SESSION['option2'] === '.mcl') {
                                    $allowed_extension = array('mcl', 'txt');

                                    if (in_array($extension, $allowed_extension)) {
                                        if (is_readable($file)) {
                                            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                                            $_SESSION['lines'] = $lines;

                                            if ($lines) {
                                                $totalAmountHeadOffice = 0;
                                                $branchTotals = [];
                                                $grandtotal = 0;

                                                foreach ($lines as $line) {
                                                    $error_description = '';
                                                    $row = explode('|', $line);

                                                    // Extracting fields from the row
                                                    $account_number = htmlspecialchars(strval(substr($line, 0, 20)));
                                                    $last_name = htmlspecialchars(strval(substr($line, 20, 30)));
                                                    $first_name = htmlspecialchars(strval(substr($line, 50, 30)));
                                                    $middle_name = htmlspecialchars(strval(substr($line, 80, 30)));
                                                    $loan_type = htmlspecialchars(strval(substr($line, 110, 15)));
                                                    $total_amount = htmlspecialchars(strval(substr($line, 125, 13)));

                                                    if (strpos($last_name, 'Ñ') !== false || strpos($first_name, 'Ñ') !== false || strpos($middle_name, 'Ñ') !== false) {
                                                        $date = htmlspecialchars(strval(substr($line, 139, 8)));
                                                        $timestamp = htmlspecialchars(strval(substr($line, 147, 15)));
                                                        $ref_code = htmlspecialchars(strval(substr($line, 162, 11)));
                                                        $unknown_code = htmlspecialchars(strval(substr($line, 173, 19)));
                                                        $phone_number = htmlspecialchars(strval(substr($line, 192, 20)));
                                                        $status1 = htmlspecialchars(strval(substr($line, 212, 1)));
                                                        $branch_name = htmlspecialchars(strval(substr($line, 213, 20)));
                                                        $status2 = htmlspecialchars(strval(substr($line, 233, 11)));
                                                    } else {
                                                        $date = htmlspecialchars(strval(substr($line, 138, 8)));
                                                        $timestamp = htmlspecialchars(strval(substr($line, 146, 15)));
                                                        if (substr($line, 161, 3) === 'BPX') {
                                                            $ref_code = htmlspecialchars(strval(substr($line, 161, 30)));
                                                        } else {
                                                            $ref_code = htmlspecialchars(strval(substr($line, 161, 11)));
                                                        }
                                                        if (substr($line, 161, 3) === 'BPX') {
                                                            $unknown_code = htmlspecialchars(strval(substr($line, 178, 1)));
                                                        } else {
                                                            $unknown_code = htmlspecialchars(strval(substr($line, 172, 19)));
                                                        }
                                                        $phone_number = htmlspecialchars(strval(substr($line, 191, 20)));
                                                        $status1 = htmlspecialchars(strval(substr($line, 211, 1)));
                                                        $branch_name = htmlspecialchars(strval(substr($line, 212, 20)));
                                                        $status2 = htmlspecialchars(strval(substr($line, 232, 11)));
                                                    }

                                                    // Check for empty fields
                                                    $fields = [
                                                        'Account Number' => $account_number,
                                                        'Loan Type' => $loan_type,
                                                        'Amount Paid' => $total_amount,
                                                        'Reference Number' => $ref_code,
                                                    ];

                                                    foreach ($fields as $field => $value) {
                                                        if (empty($value) || trim($value) === '') {
                                                            $error_description .= "$field is empty. ";
                                                        }
                                                    }

                                                    // Check for duplicate account number with same details
                                                    $recordKey = $ref_code . '|' . $loan_type . '|' . $total_amount . '|' . $account_number;
                                                    if (in_array($recordKey, $recordDetails)) {
                                                        $error_description .= "Duplicate Reference Number with same details. ";
                                                    } else {
                                                        $recordDetails[] = $recordKey;
                                                    }

                                                    $error_description = trim($error_description);
                                                
                                                    // Validate Lastname
                                                    if (empty($last_name)) {
                                                        $error_description .= 'Last name is empty. ';
                                                    }

                                                    // Validate Firstname
                                                    if (empty($first_name)) {
                                                        $error_description .= 'First name is empty. ';
                                                    }

                                                    // Validate Middlename
                                                    if (empty($middle_name)) {
                                                        $error_description .= 'Middle name is empty. ';
                                                    }
                                                    
                                                    // Validate date
                                                    if (empty($date)) {
                                                        $error_description .= 'Date is empty. ';
                                                    }  elseif (!preg_match('/^\d{8}$/', $date)) {
                                                        $error_description .= 'Date format is incorrect. ';
                                                        $date = convertToMySQLDate($date);
                                                    } 
                                                    else {
                                                        $date = convertToMySQLDate($date);
                                                    }

                                                    // Validate timestamp
                                                    // if (empty($timestamp)) {
                                                    //     $error_description .= 'Timestamp is empty. ';
                                                    // } elseif (!preg_match('/^\d{15}$/', $timestamp)) {
                                                    //     $error_description .= 'Timestamp format is incorrect. ';
                                                    //     $timestamp = convertToMySQLTime($timestamp);
                                                    // } else {
                                                    //     $timestamp = convertToMySQLTime($timestamp);
                                                    // }

                                                    if (empty($error_description)) {
                                                        $validRows[] = [
                                                            'Account Number' => $account_number,
                                                            'Last Name' => $last_name,
                                                            'First Name' => $first_name,
                                                            'Middle Name' => $middle_name,
                                                            'Loan Type' => $loan_type,
                                                            'Total Amount' => $total_amount,
                                                            'Feedback Date' => $date,
                                                            'Timestamp' => $timestamp,
                                                            'Feedback Ref. Code.' => $ref_code,
                                                            'Unknown Code' => $unknown_code,
                                                            'Phone Number' => $phone_number,
                                                            'Status 1' => $status1,
                                                            'Branch Name' => $branch_name,
                                                            'Status 2' => $status2
                                                        ];

                                                        if (strtoupper($branch_name) === $branch_name) {
                                                            $totalAmountHeadOffice += floatval($total_amount);
                                                        }
                            
                                                        if (!isset($branchTotals[$branch_name])) {
                                                            $branchTotals[$branch_name] = 0;
                                                        }
                                                        $branchTotals[$branch_name] += floatval($total_amount);

                                                        $_SESSION['validRows'] = $validRows;

                                                    } else {
                                                        $invalidRows[] = [
                                                            'Account Number' => $account_number,
                                                            'Last Name' => $last_name,
                                                            'First Name' => $first_name,
                                                            'Middle Name' => $middle_name,
                                                            'Loan Type' => $loan_type,
                                                            'Total Amount' => $total_amount,
                                                            'Feedback Date' => $date,
                                                            'Timestamp' => $timestamp,
                                                            'Feedback Ref. Code.' => $ref_code,
                                                            'Unknown Code' => $unknown_code,
                                                            'Phone Number' => $phone_number,
                                                            'Status 1' => $status1,
                                                            'Branch Name' => $branch_name,
                                                            'Status 2' => $status2,
                                                            'error_description' => $error_description,
                                                        ];
                                                    }
                                                    
                                                }
                                                if(empty($error_description)) {
                                                    foreach ($validRows as $row) {
                                                        echo "<tr style='whitespace: nowrap !important;'>";
                                                        echo "<td>" . htmlspecialchars($row['Account Number']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Loan Type']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Total Amount']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Feedback Date']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Feedback Ref. Code.']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Unknown Code']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Branch Name']) . "</td>";
                                                        echo "<td>Valid</td>";
                                                        echo "</tr>";

                                                    }
                                                }else{
                                                    foreach ($invalidRows as $row) {
                                                        echo "<tr style='whitespace: nowrap !important;'>";
                                                        echo "<td>" . htmlspecialchars($row['Account Number']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Loan Type']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Total Amount']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Feedback Date']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Feedback Ref. Code.']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Unknown Code']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['Branch Name']) . "</td>";
                                                        echo "<td>" . htmlspecialchars($row['error_description']) . "</td>";
                                                        echo "</tr>";
                                                    }
                                                }
                                            }
                                        } else {
                                            echo "<script>Swal.fire('Error', 'The file is not readable.', 'error');</script>";
                                        }
                                    } else {
                                        //echo "<script>Swal.fire('Error', 'Invalid file extension.', 'error');</script>";
                                        echo "<script>
                                            Swal.fire({
                                                title: 'Error!',
                                                text: 'Invalid selection file.',
                                                icon: 'error',
                                                confirmButtonText: 'OK',
                                                allowOutsideClick: false
                                            }).then(() => {
                                                window.location.href = 'billsFeedback.php';
                                            });
                                        </script>";
                                    }

                                } elseif ($_SESSION['option1'] === 'PAGIBIG' && $_SESSION['option2'] === '.xls') {
                                    $allowed_extension = array('xls', 'xlsx');

                                    if (in_array($extension, $allowed_extension)) {
                                        $reader = IOFactory::createReaderForFile($file);
                                        $spreadsheet = $reader->load($file);
                                        $sheet = $spreadsheet->getActiveSheet();
                                        $highestRow = $sheet->getHighestRow();

                                        $totalAmountHeadOffice = 0;
                                        $branchTotals = [];
                                        $grandtotal = 0;

                                        for ($row = 10; $row <= $highestRow; $row++) {
                                            $cells = [];
                                            foreach (range('B', 'R') as $col) {
                                                $cells[$col] = $conn->real_escape_string(strval($sheet->getCell($col . $row)->getValue()));
                                            }

                                            if (array_filter($cells, 'strlen') === []) {
                                                break;
                                            }

                                            $date = substr($cells['B'], 0, 10);
                                            $time = substr($cells['B'], 10, 12);

                                            $fields = [
                                                // 'Control No.' => $cells['C'],
                                                'Reference No.' => $cells['D'],
                                                'Account No.' => $cells['G'],
                                                'Amount Paid' => $cells['I'],
                                                'Charge to Customer' => $cells['J'],
                                                'Charge to Partner' => $cells['K']
                                            ];

                                            $error_description = '';
                                            foreach ($fields as $field => $value) {
                                                if (empty($value)) {
                                                    $error_description .= "$field is empty. ";
                                                }
                                            }

                                            // Generate a unique key for the current record
                                            $recordKey = implode('|', [$cells['D'], $cells['G'], $cells['I'], $cells['J'], $cells['K']]);
                                            
                                            // Check for duplicate Reference No. with same details
                                            if (isset($recordDetails[$recordKey])) {
                                                $error_description .= "Duplicate Reference No. with same details. ";
                                            } else {
                                                $recordDetails[$recordKey] = true;
                                            }

                                            $row_data = [
                                                'Date' => $date,
                                                'Timestamp' => $time,
                                                'Control No.' => $cells['C'],
                                                'Reference No.' => $cells['D'],
                                                'Payor Name' => $cells['E'],
                                                'Address' => $cells['F'],
                                                'Account No.' => $cells['G'],
                                                'Account Name' => $cells['H'],
                                                'Amount Paid' => $cells['I'],
                                                'Charge to Customer' => $cells['J'],
                                                'Charge to Partner' => $cells['K'],
                                                'Contact No.' => $cells['L'],
                                                'Other Details' => $cells['M'],
                                                'ML Branch Outlet' => $cells['N'],
                                                'Region' => $cells['O'],
                                                'Operator' => $cells['P'],
                                                'Remote Branch' => $cells['Q'],
                                                'Remote Operator' => $cells['R']
                                            ];

                                            if (empty($error_description)) {
                                                $validRows[] = $row_data;
                                                
                                                $total_amount = floatval(str_replace(',', '', $cells['I'])); // Remove commas for float conversion
                                                if (strtoupper($cells['N']) === $cells['N']) {
                                                    $totalAmountHeadOffice += $total_amount;
                                                }

                                                if (!isset($branchTotals[$cells['N']])) {
                                                    $branchTotals[$cells['N']] = 0;
                                                }
                                                $branchTotals[$cells['N']] += $total_amount;

                                                // Save valid rows in session
                                                $_SESSION['validRows'] = $validRows;
                                                
                                            } else {
                                                $row_data['error_description'] = $error_description;
                                                $invalidRows[] = $row_data;
                                            }
                                        }

                                        if (empty($error_description)) {
                                            foreach ($validRows as $row) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['Date']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Timestamp']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Control No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Reference No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Account No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Amount Paid']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Charge to Customer']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Charge to Partner']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['ML Branch Outlet']) . '</td>';
                                                echo '<td>Valid</td>';
                                                echo '</tr>';
                                            }
                                        }else{
                                            foreach ($validRows as $row) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['Date']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Timestamp']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Control No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Reference No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Account No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Amount Paid']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Charge to Customer']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Charge to Partner']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['ML Branch Outlet']) . '</td>';
                                                echo '<td>Valid</td>';
                                                echo '</tr>';
                                            }
    
                                            foreach ($invalidRows as $row) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['Date']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Timestamp']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Control No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Reference No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Account No.']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Amount Paid']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Charge to Customer']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['Charge to Partner']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['ML Branch Outlet']) . '</td>';
                                                echo "<td>" . htmlspecialchars($row['error_description']) . "</td>";
                                                echo '</tr>';
                                            }
                                        }
                                    } else {
                                        echo "<script>
                                            Swal.fire({
                                                title: 'Error!',
                                                text: 'Invalid selection file.',
                                                icon: 'error',
                                                confirmButtonText: 'OK',
                                                allowOutsideClick: false
                                            }).then(() => {
                                                window.location.href = 'billsFeedback.php';
                                            });
                                        </script>";
                                    }

                                } else {
                                    echo "<script>Swal.fire('Error', 'Please select a valid Partner and Extension.', 'error');</script>";
                                }
                            }else{
                                if(empty($error_description)){

                                    if (isset($_POST['upload']) && $_POST['option1'] === 'PAGIBIG' && $_POST['option2'] === '.mcl') {
                                        echo '<tr>';
                                            echo '<td colspan="8" style="text-align:center;"><i>All data(s) are verified.</i></td>';
                                        echo '</tr>';
                                    }elseif (isset($_POST['upload']) && $_POST['option1'] === 'PAGIBIG' && $_POST['option2'] === '.xls') {
                                        echo '<tr>';
                                            echo '<td colspan="10" style="text-align:center;"><i>All data(s) are verified.</i></td>';
                                        echo '</tr>';
                                    }else{
                                        echo '<tr>';
                                            echo '<td colspan="8" style="text-align:center;"><i>Please Upload a valid file.</i></td>';
                                        echo '</tr>';
                                    }

                                }
                            }
                            
                            ?>
                        </tbody>
                    </table>
                    <!-- LEGEND -->
                    <div class="lg-div2">
                        <?php
                            if (isset($_POST['upload'])) {
                                if (empty($error_description)) {
                                    echo '<form method="post" enctype="multipart/form-data">
                                        <input type="button" name="proceed" class="post-btn" value="POST" onclick="showConfirmationDialog()">
                                    </form>';
                                }else{
                                    echo '<button type="button" id="export-pdf" class="export-btn">Export to PDF</button>';
                                }
                            }
                        ?>
                        <div class="lg-container">
                            <div class="legend">
                                <h3 class="legend-title">PARTNER : ( <?php echo isset($_POST['option1']) ? $_POST['option1'] : 'UNKNOWN'; ?> )</h3>
                            </div>
                            <div class="lg-label">
                                <div class="legend-guide">
                                    <div class="legend-title">File Type : </div>
                                    <div class="lg-name">( <b><?php echo isset($_POST['option2']) ? $_POST['option2'] : 'UNKNOWN'; ?></b> )</div>
                                </div>
                                <div class="legend-guide">
                                    <div class="legend-title">Date Uploaded : </div>
                                    <div class="lg-name">( <b><?php echo isset($_POST['upload']) ? date("m-d-Y") : '--,--,----'; ?></b> )</div>
                                </div>
                            </div>
                        </div>
                        <!-- Total Count -->
                        <div class="file-table2">
                            <table class="table-container2">
                                <thead>
                                    <tr>
                                        <th style="border: none;">Branch Outlet</th>
                                        <th style="border: none; text-align: right;"> Sub-Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (isset($_POST['upload'])) {
                                        if(empty($error_description)){
                                            foreach ($branchTotals as $branch => $total) {
                                                echo '<tr>';
                                                echo '<td>' . $branch . '</td>';
                                                echo '<td style="text-align: right;">' . number_format($total, 2) . '</td>';
                                                echo '</tr>';
                                                $grandtotal += $total;
                                            }
                                        }//else{
                                        //     foreach ($branchTotals as $branch => $total) {
                                        //         echo '<tr>';
                                        //         echo '<td>' . $branch . '</td>';
                                        //         echo '<td style="text-align: right;">' . number_format($total, 2) . '</td>';
                                        //         echo '</tr>';
                                        //         $grandtotal += $total;
                                        //     } 
                                        // }
                                    }else{
                                        echo '<tr>';
                                        echo '<td colspan="2" style="text-align:center;"><i>Please Upload a valid file.</i></td>';
                                        echo '</tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="total-container">
                            <div class="total-condition2">
                                <b>Total Amount</b>
                            </div>
                            <div class="total-value2">
                                <b>
                                    <?php 
                                        if (isset($_POST['upload'])) {
                                            echo number_format($grandtotal, 2);
                                        }else{
                                            echo '0.00';
                                        }
                                    ?>
                                </b>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toggle Side Nav Script -->
    <script src="../assets/js/script.js"></script>
    <script>
        showSuccessMessage(<?= $insertedCount; ?>);
    </script>
    <!-- Handle Datatables Initialization -->
    <script>
        $(document).ready(function () {
            $('.file-table').DataTable({
                "paging": true,
                "lengthChange": true,
                "searching": true,
                "ordering": true,
                "info": true,
                "autoWidth": true,
            });
        });
    </script>
</body>
<?php include '../templates/user/footer.php'; ?>
</html>
