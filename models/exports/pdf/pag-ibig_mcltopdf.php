<?php
require '../../../vendor/autoload.php'; // Autoload Dompdf library
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
        if($_SESSION['user_email'] === 'balb01013333' || $_SESSION['user_email'] === 'pera94005055'){
            header("Location:../../../index.php");
            session_destroy();
            exit();
        }
    }else{
        header("Location:../../../index.php");
        session_destroy();
        exit();
    }
}

use Dompdf\Dompdf;
use Dompdf\Options;


// Clean output buffer to avoid unwanted characters
if (ob_get_length()) {
    ob_clean();
}

if (!isset($_SESSION['validRows']) && !isset($_SESSION['invalidRows'])) {
    die('No data available for export.');
}

// Retrieve valid and invalid rows
$validRows = $_SESSION['validRows'] ?? [];
$invalidRows = $_SESSION['invalidRows'] ?? [];

// Configure Dompdf options
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// Clear previous headers
header_remove();

// Set content-type to PDF
header('Content-Type: application/pdf');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');


// Generate the HTML content
$html = '<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table, th, td { border: 1px solid black; }
        th, td { padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .valid { background-color: #d4edda; }
        .invalid { background-color: #f8d7da; }
        h3 { margin-top: 20px; }
    </style>
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
</head>
<body>
    <h1>Exported Data</h1>
    <h3>Valid Rows</h3>
    <table>
        <thead>
            <tr>
                <th>Account Number</th>
                <th>Loan Type</th>
                <th>Total Amount</th>
                <th>Transaction Date</th>
                <th>Reference No.</th>
                <th>Branch Name</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

// Add valid rows
foreach ($validRows as $row) {
    $html .= '<tr class="valid">
        <td>' . htmlspecialchars($row['Account Number']) . '</td>
        <td>' . htmlspecialchars($row['Loan Type']) . '</td>
        <td>' . htmlspecialchars($row['Total Amount']) . '</td>
        <td>' . htmlspecialchars($row['Feedback Date']) . '</td>
        <td>' . htmlspecialchars($row['Feedback Ref. Code.']) . '</td>
        <td>' . htmlspecialchars($row['Branch Name']) . '</td>
        <td>Passed</td>
    </tr>';
}

$html .= '</tbody></table>';

// Add invalid rows
if (!empty($invalidRows)) {
    $html .= '<h3>Invalid Rows</h3>
    <table>
        <thead>
            <tr>
                <th>Account Number</th>
                <th>Loan Type</th>
                <th>Total Amount</th>
                <th>Transaction Date</th>
                <th>Reference No.</th>
                <th>Branch Name</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($invalidRows as $row) {
        $html .= '<tr class="invalid">
            <td>' . htmlspecialchars($row['Account Number']) . '</td>
            <td>' . htmlspecialchars($row['Loan Type']) . '</td>
            <td>' . htmlspecialchars($row['Total Amount']) . '</td>
            <td>' . htmlspecialchars($row['Feedback Date']) . '</td>
            <td>' . htmlspecialchars($row['Feedback Ref. Code.']) . '</td>
            <td>' . htmlspecialchars($row['Branch Name']) . '</td>
            <td>' . htmlspecialchars($row['error_description']) . '</td>
        </tr>';
    }

    $html .= '</tbody></table>';
}

$html .= '</body></html>';

// Load the HTML content into Dompdf
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'portrait');

// Render the HTML to PDF
$dompdf->render();

// Output the generated PDF to the browser
$dompdf->stream('exported_data.pdf', ['Attachment' => false]); // Set 'Attachment' => true to force download
exit;
?>
