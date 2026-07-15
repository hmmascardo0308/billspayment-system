<?php
session_start();
require '../vendor/autoload.php';

// Check if user is logged in
if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
    exit;
}

// Check if errors data was submitted
if (!isset($_POST['errors'])) {
    header('location:billspaymentImportFile.php');
    exit;
}

// Get the errors data
$errors = json_decode($_POST['errors'], true);
$filename = isset($_POST['filename']) ? $_POST['filename'] : 'reference_number_errors';

// Get source file type and transaction date from session rather than POST
$sourceFileType = isset($_SESSION['source_file_type']) ? $_SESSION['source_file_type'] : '';
$transactionDate = isset($_SESSION['transactionDate']) ? $_SESSION['transactionDate'] : date('Y-m-d');

// Ensure the transaction date is properly formatted
if ($transactionDate) {
    // Format the date to ensure consistency (if it's a valid date)
    $dateObj = DateTime::createFromFormat('Y-m-d', $transactionDate);
    if ($dateObj !== false) {
        $transactionDate = $dateObj->format('F j, Y');
    }
}

// Create PDF using TCPDF
use TCPDF as TCPDF;

// Create new PDF document
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');

// Set document information
$pdf->SetCreator('ML Wallet System');
$pdf->SetAuthor('Admin User');
$pdf->SetTitle('Duplicate Reference Number Report');
$pdf->SetSubject('Reference Number Errors');
$pdf->SetKeywords('Reference, Number, Duplicate, Error, Report');

// Remove header and footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(15, 15, 15);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set some language-dependent strings
$pdf->setLanguageArray([
    'a_meta_charset' => 'UTF-8',
    'a_meta_dir' => 'ltr',
    'a_meta_language' => 'en',
]);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 14);

// Title
$pdf->Cell(0, 10, 'Duplicate Reference Number Report', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 8, 'File: ' . $filename, 0, 1, 'C');
$pdf->Cell(0, 8, 'Source File Type: ' . $sourceFileType, 0, 1, 'C');
$pdf->Cell(0, 8, 'Transaction Date: ' . $transactionDate, 0, 1, 'C');
$pdf->Cell(0, 8, 'Date: ' . date('F j, Y'), 0, 1, 'C');

$pdf->Ln(10);

// Error message with justified text
$pdf->SetFont('helvetica', 'B', 12);
$pdf->MultiCell(0, 10, 'The following reference numbers appear more than once in the file:', 0, 'L', 0);

// Table header
$pdf->Ln(5);
$pdf->SetFillColor(44, 62, 80);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(15, 8, 'No.', 1, 0, 'C', 1);
$pdf->Cell(45, 8, 'Reference Number', 1, 0, 'C', 1);
$pdf->Cell(60, 8, 'Payor', 1, 0, 'C', 1);
$pdf->Cell(30, 8, 'Amount', 1, 0, 'C', 1);
$pdf->Cell(30, 8, 'Row in Excel', 1, 1, 'C', 1);

// Table content
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetFillColor(245, 245, 245);
$fill = true;

foreach ($errors as $index => $error) {
    $pdf->Cell(15, 8, ($index + 1), 1, 0, 'C', $fill);
    $pdf->Cell(45, 8, $error['reference_no'], 1, 0, 'L', $fill);
    $pdf->Cell(60, 8, $error['payor'], 1, 0, 'L', $fill);
    $pdf->Cell(30, 8, $error['amount'], 1, 0, 'R', $fill);
    $pdf->Cell(30, 8, $error['row'], 1, 1, 'C', $fill);
    $fill = !$fill;
}

// Add note at the bottom
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->MultiCell(0, 8, 'Please remove duplicate reference numbers in your file before importing. Duplicate references can cause transaction processing issues.', 0, 'L', 0);

// Output the PDF
$pdf->Output('duplicate_reference_numbers.pdf', 'D');
exit;
?>
