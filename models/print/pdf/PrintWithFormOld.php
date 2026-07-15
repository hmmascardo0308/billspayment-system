<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';

// Start the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_type'])) {
    header("Location:../../../index.php");
    exit();
}

// Get the reference number from the URL parameter
$referenceNumber = isset($_GET['reference']) ? $_GET['reference'] : '';

if (empty($referenceNumber)) {
    echo "No reference number provided.";
    exit();
}

// Fetch the specific transaction data
$query = "SELECT * FROM soa_transaction WHERE reference_number = '$referenceNumber'";
$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) == 0) {
    echo "Transaction not found.";
    exit();
}

$row = mysqli_fetch_assoc($result);

// try to render signature image for prepared_by
function render_signature_img($conn, $id_number = null, $full_name = '') {
    // prefer lookup by id_number
    if ($id_number) {
        $sql = "SELECT signature FROM mldb.user_sig WHERE id_number = ? LIMIT 1";
        if ($s = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($s, 's', $id_number);
            mysqli_stmt_execute($s);
            $res = mysqli_stmt_get_result($s);
            $r = mysqli_fetch_assoc($res);
            mysqli_stmt_close($s);
            if ($r && !empty($r['signature'])) {
                $b64 = base64_encode($r['signature']);
                return '<img src="data:image/png;base64,' . $b64 . '" class="print-signature-img" alt="signature" />';
            }
        }
    }
    // fallback: try by name
    if ($full_name) {
        $nameParam = preg_replace('/\s+/', ' ', trim($full_name));
        $sql = "SELECT mus.signature FROM mldb.user_form muf LEFT JOIN mldb.user_sig mus ON muf.id_number = mus.id_number WHERE TRIM(CONCAT_WS(' ', muf.first_name, muf.middle_name, muf.last_name)) = ? LIMIT 1";
        if ($s = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($s, 's', $nameParam);
            mysqli_stmt_execute($s);
            $res = mysqli_stmt_get_result($s);
            $r = mysqli_fetch_assoc($res);
            mysqli_stmt_close($s);
            if ($r && !empty($r['signature'])) {
                $b64 = base64_encode($r['signature']);
                return '<img src="data:image/png;base64,' . $b64 . '" class="print-signature-img" alt="signature" />';
            }
        }
    }
    // nothing found -> friendly text
    return 'electronically signed';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print SOA - <?php echo $row['reference_number']; ?></title>
    <style>
        /* Page setup for printing */
        @page {
            size: 5.52in 8.75in;
            margin: 0 0 0 0;
        }
        
        /* Global reset and body styling */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        /* Bond paper card container */
        .bond-paper-card {
            width: 5.52in;
            height: 8.75in;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2), 
                        0 6px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            position: relative;
            overflow: hidden;
            transform: perspective(1000px) rotateX(2deg) rotateY(-1deg);
            transition: all 0.3s ease;
        }
        
        .bond-paper-card:hover {
            transform: perspective(1000px) rotateX(0deg) rotateY(0deg);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25), 
                        0 8px 15px rgba(0, 0, 0, 0.15);
        }
        
        /* Paper texture overlay */
        .bond-paper-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(120, 119, 198, 0.02) 0%, transparent 50%);
            pointer-events: none;
        }
        
        /* Paper binding holes */
        .paper-holes {
            position: absolute;
            left: 0.3in;
            top: 1in;
            bottom: 1in;
            width: 0.1in;
            background: repeating-linear-gradient(
                to bottom,
                transparent 0,
                transparent 0.4in,
                rgba(0, 0, 0, 0.1) 0.4in,
                rgba(0, 0, 0, 0.1) 0.45in,
                transparent 0.45in,
                transparent 0.85in
            );
        }
        
        /* Content area with padding */
        .paper-content {
            padding: 0.4in 0.3in;
            width: 100%;
            height: 100%;
            position: relative;
            z-index: 2;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.95);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            font-size: 16px;
            color: #333;
        }
        
        /* Header styling */
        .header {
            text-align: center;
            font-size: 1px;
            /* margin-bottom: 0.1in; */
            font-weight: bold;
            color: #2c3e50;
        }
        
        .header .company-info {
            font-size: 5px;
            font-weight: normal;
            line-height: 1.2;
            color: #555;
        }
        
        .header .title {
            font-size: 10px;
            font-weight: bold;
            margin-top: 0.1in;
            color: #2c3e50;
            text-decoration: underline;
        }
        
        /* Content styling */
        .print-content {
            width: 100%;
            height: auto;
            font-size: 11px;
            line-height: 1.3;
            color: #333;
        }
        
        /* Table styles */
        .header-info-table,
        .customer-info-table,
        .main-content-table,
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            /* margin-bottom: 0.15in; */
        }
        
        .customer-info-table td {
            padding: 2px 4px;
            vertical-align: top;
            /* border-bottom: 1px solid #eee; */
        }

        .customer-info-table{
            margin-bottom: 0.15in;
        }
        
        /* .main-content-table {
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 0.15in;
        } */
        
        .main-content-table td {
            padding: 8px;
            vertical-align: top;
            /* border-bottom: 1px solid #eee; */
        }
        
        /* Text wrapping for formulaInc_Exc content */
        .formula-content {
            word-wrap: break-word;
            word-break: break-word;
            /* white-space: pre-wrap; */
            overflow-wrap: break-word;
            max-width: 100%;
            hyphens: auto;
            -webkit-hyphens: auto;
            -moz-hyphens: auto;
            -ms-hyphens: auto;
        }
        
        /* Ensure table cells don't overflow */
        .main-content-table .particulars-cell {
            width: 60%;
            max-width: 0;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .main-content-table .amount-cell {
            width: 40%;
            max-width: 0;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        /* Signature section */
        .signature-table {
            /* margin-top: 0.3in; */
            /* border-top: 2px solid #2c3e50; */
            padding-top: 0.1in;
        }
        
        .signature-table td {
            text-align: center;
            vertical-align: top;
            padding: 8px;
            font-size: 7px;
        }
        
        /* Print button styling */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
        }
        
        .print-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        /* Amount styling */
        .peso-sign {
            color: #27ae60;
            font-weight: bold;
        }
        
        .amount-value {
            font-weight: 600;
            color: #2c3e50;
        }
        
        /* Labels */
        .print-labels {
            font-weight: 600;
            color: #34495e;
        }
        
        .print-values {
            color: #2c3e50;
        }
        
        /* Responsive adjustments */
        @media screen and (max-width: 768px) {
            .bond-paper-card {
                transform: none;
                width: 90vw;
                height: auto;
                min-height: 90vh;
            }
            
            .paper-content {
                padding: 20px;
            }
        }
        
        /* Print media query */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
                display: block;
            }
            
            .bond-paper-card {
                width: 5.52in;
                height: 8.75in;
                box-shadow: none;
                border-radius: 0;
                transform: none;
                margin: 0;
                page-break-inside: avoid;
            }
            
            .print-button,
            .loading-overlay,
            .paper-holes {
                display: none !important;
            }
            
            .bond-paper-card::before {
                display: none;
            }
            
            .paper-content {
                padding: 0.2in;
            }
        }
        
        /* Animation for card appearance */
        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: perspective(1000px) rotateX(10deg) rotateY(-5deg) translateY(50px);
            }
            to {
                opacity: 1;
                transform: perspective(1000px) rotateX(2deg) rotateY(-1deg) translateY(0);
            }
        }
        
        .bond-paper-card {
            animation: cardAppear 0.8s ease-out;
        }

        /* Signature image for print: positioned relative inside cell */
        .print-signature-wrap {
            width: 100%;
            height: 48px; /* slightly larger to preserve detail */
            max-height: 48px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
        }

        /*
         * Use a responsive approach so signatures keep aspect ratio.
         * - `object-fit: cover` will crop and fill the box (good for tall/wide signatures).
         * - `object-fit: contain` will fit whole signature inside box without cropping.
         * We set sensible fallbacks and ensure the image cannot overflow.
         */
        .print-signature-img {
            display: block;
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: 100%;
            object-fit: cover;
            object-position: center center;
        }
        
        /* Subtle grid background for paper effect */
        .paper-content::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(rgba(0,0,0,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,0,0,0.02) 1px, transparent 1px);
            background-size: 20px 20px;
            pointer-events: none;
            z-index: -1;
        }
    </style>
</head>
<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div style="text-align: center;">
            <div style="font-size: 18px; margin-bottom: 10px;">📄</div>
            Preparing document for printing...
        </div>
    </div>
    
    <button class="print-button" onclick="printPage()" id="printButton" style="display: none;">🖨️ Print Document</button>
    
    <!-- Bond Paper Card Container -->
    <div class="bond-paper-card">
        <!-- Paper holes effect -->
        <div class="paper-holes"></div>
        
        <!-- Main content area -->
        <div class="paper-content">
            <div class="header" style="visibility:hidden;">
                MICHEL J. LHUILLIER FINANCIAL <br>
                SERVICES (PAWNSHOPS), INC. <br>
                <div class="company-info">
                    58 Colon St., Sto. Niño, Cebu City North, Cebu City 6000<br>
                    Telephone No. (032) 416-6656 and (032) 232-5681<br>
                    VAT REG. TIN: 002-394-238-000
                </div>
                <div class="title">STATEMENT OF ACCOUNT</div>
            </div>

            <div class="print-content">
                <!-- <table class="header-info-table">
                    
                </table> -->
                
                <table class="customer-info-table">
                    <tr>
                        <td style=" vertical-align: top;"></td>
                        <td style="width: 30%; text-align: right; vertical-align: top;">
                            <div>
                                <span class="print-labels" style="visibility:hidden;">Date: </span>
                                <span class="print-values"><?php echo date('F j, Y', strtotime($row['date'])); ?></span>
                            </div>
                            <div>
                                <span class="print-labels" style="visibility:hidden;">Reference No: </span>
                                <span class="print-values"><?php echo $row['reference_number']; ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 25%;">
                            <span class="print-labels" style="visibility:hidden;">Customer Name:</span>
                        </td>
                        <td style="width: 75%;">
                            <span class="print-values"><?php echo $row['partner_Name']; ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td><span class="print-labels" style="visibility:hidden;">Customer TIN:</span></td>
                        <td><span class="print-values"><?php echo $row['partner_Tin']; ?></span></td>
                    </tr>
                    <tr>
                        <td><span class="print-labels" style="visibility:hidden;">Address:</span></td>
                        <td><span class="print-values"><?php echo $row['address']; ?></span></td>
                    </tr>
                    <tr>
                        <td><span class="print-labels" style="visibility:hidden;">Business Style:</span></td>
                        <td><span class="print-values"><?php echo $row['business_style']; ?></span></td>
                    </tr>
                </table>
                
                <table class="main-content-table">
                    <thead>
                        <tr>
                            <th style="text-align: center; padding: 8px; font-size: 9px; visibility:hidden;">PARTICULARS</th>
                            <th style="text-align: center; padding: 8px; font-size: 9px; visibility:hidden;">AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php if(!empty($row['amount_add']) && !empty($row['numberOf_days']) && !empty($row['add_amount'])){ $amount_Due_and_amount_size = '6px'; $particular_and_amount_size = '15px, 15px, 15px, 15px'; }?>
                            <td class="particulars-cell" style="vertical-align: top; padding: <?php echo  $particular_and_amount_size ?? '15px' ?>;">
                                <!-- <div style="margin-bottom: 8px;">
                                    <strong style="color:#dc3545;"><?php //echo $row['formula']; ?></strong>
                                </div> -->
                                <div style="margin-bottom: 8px; margin-left: 8px;">
                                    <?php echo $row['service_charge']; ?>
                                </div>
                                <?php if ($row['partner_Tin'] === '005-519-158-000'): ?>
                                <div style="margin-bottom: 8px; margin-left: 8px;">
                                    <span class="print-labels">Number of Transactions: </span>
                                    <span><?php echo number_format($row['number_of_transactions']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div style="margin-bottom: 8px; margin-left: 8px;">
                                    <span>From: <?php echo date('m/d/Y', strtotime($row['from_date'])); ?></span><br>
                                    <span>To: <?php echo date('m/d/Y', strtotime($row['to_date'])); ?></span>
                                </div>
                                <div class="formula-content" style="margin-left: 8px;">
                                    <?php 
                                        $data = $row['formulaInc_Exc'];
                                        $datachar = ';';
                                    if (strpos($data, $datachar) !== false) :?>
                                        <?php 
                                        $lines = explode(";", $data);
                                        foreach ($lines as $line) : ?>
                                            <?php echo nl2br(trim($line)); ?><br>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php echo nl2br($row['formulaInc_Exc']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($row['amount_add']) && !empty($row['numberOf_days']) && !empty($row['add_amount'])): ?>
                                <div class="addAmount-div" style="margin-left: 8px; margin-top: 8px;">
                                    <span>Add Bank Charges: </span>
                                    <span><?php echo $row['amount_add']; ?></span>
                                    <span> × </span>
                                    <span><?php echo $row['numberOf_days']; ?></span>
                                    <!-- <span><?php //echo $row['add_amount']; ?></span> -->
                                    <span style="font-size:8px; color:#d70c0c; margin-left:5px; font-weight:700; font-style:italic;">
                                        (Added in NET AMOUNT DUE)
                                    </span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="amount-cell" style="text-align: right; vertical-align: top; padding: <?php echo  $particular_and_amount_size ?? '15px' ?>;">
                                <div style="margin-bottom: 8px; margin-right: 8px;">
                                    <span class="peso-sign">₱</span>
                                    <span class="amount-value"><?php echo number_format($row['amount'], 2); ?></span>
                                </div>
                                <?php if ($row['formula'] !== 'NON-VAT'): ?>
                                <div style="margin-bottom: 8px; margin-right: 8px; font-size: 11px;">
                                    <span>VAT Amount: </span>
                                    <span class="peso-sign">₱</span>
                                    <span><?php echo $row['vat_amount']; ?></span>
                                </div>
                                <div style="margin-bottom: 8px; margin-right: 8px; font-size: 11px;">
                                    <span>Net of VAT: </span>
                                    <span class="peso-sign">₱</span>
                                    <span><?php echo $row['net_of_vat']; ?></span>
                                </div>
                                <?php if ($row['formula_withheld'] !== 'No'): ?>
                                <div style="margin-bottom: 8px; margin-right: 8px; font-size: 11px;">
                                    <span>Withholding Tax: </span>
                                    <span class="peso-sign">₱</span>
                                    <span><?php echo $row['withholding_tax']; ?></span>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($row['add_amount'])): ?>
                                <div style="margin-bottom: 8px; margin-right: 8px; font-size: 11px;">
                                    <span>Add Amount: </span>
                                    <span class="peso-sign">₱</span>
                                    <span><?php echo $row['add_amount'];?></span>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: right; font-weight: bold; padding-top: <?php echo $amount_Due_and_amount_size ?? '50px' ?>;">
                                <div style="visibility:hidden;">TOTAL AMOUNT DUE</div>
                                <?php if ($row['formula_withheld'] !== 'No'): ?>
                                <div style="visibility:hidden;">LESS WITHHOLDING TAX</div>
                                <div style="visibility:hidden;">NET AMOUNT DUE</div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: bold; padding-top: <?php echo $amount_Due_and_amount_size ?? '50px' ?>; padding-right: 22px;">
                                <div>
                                    <span class="peso-sign">₱</span>
                                    <span class="amount-value"><?php echo ($row['formula'] === 'INCLUSIVE') ? number_format($row['amount'], 2) : $row['totalAmountDue']; ?></span>
                                </div>
                                <?php if ($row['formula_withheld'] !== 'No'): ?>
                                <div>
                                    <span class="peso-sign">₱</span>
                                    <span class="amount-value"><?php echo $row['withholding_tax']; ?></span>
                                </div>
                                <div>
                                    <span class="peso-sign">₱</span>
                                    <span class="amount-value"><?php echo $row['net_amount_due']; ?></span>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <table class="signature-table">
                    <tr>
                        <td style="width: 33%;">
                            <div style="font-weight: bold; visibility:hidden;">Prepared by:</div>
                            <div style="font-size: 10px; margin-bottom: 5px; padding-bottom: 2px; min-height: 20px;">
                                <!-- put signature here for prepared -->
                                <?php
                                    // attempt using reviewedFix_signature or prepared_by as id/fullname
                                    // If soa stores id in reviewedFix_signature or similar, pass it; otherwise pass name
                                    $sig = render_signature_img($conn, $row['prepared_signature'] ?? null, $row['prepared_by'] ?? '');
                                    if (is_string($sig) && strpos($sig, 'data:image') !== false) {
                                        // wrap the returned <img ...> HTML to preserve classes and attributes
                                        echo '<div class="print-signature-wrap">' . $sig . '</div>';
                                    }
                                ?>

                                <?php echo strtoupper($row['prepared_by']); ?>
                            </div>
                            <div style="font-size: 6px; font-style: italic; visibility:hidden;">Accounting Staff</div>
                        </td>
                        <td style="width: 33%;">
                            <!-- <div style="font-weight: bold; visibility:hidden;">Reviewed by:</div> -->
                            <div style="font-size: 10px; margin-bottom: 5px; padding-bottom: 2px; min-height: 20px;">
                                <!-- put signature here for reviewed -->
                                <?php
                                    // show 'for: NAME' when reviewedFix_signature differs
                                    if (strtolower(str_replace(' ', '', $row['reviewed_by'])) !== strtolower(str_replace(' ', '', $row['reviewedFix_signature']))) {
                                        echo 'for: ' . strtoupper($row['reviewed_by']) . '<br>';
                                    }
                                    // attempt to render signature image; use reviewed_signature id if present
                                    $sig = render_signature_img($conn, $row['reviewed_signature'] ?? null, $row['reviewed_by'] ?? $row['reviewedFix_signature']);
                                    if (is_string($sig) && strpos($sig, 'data:image') !== false) {
                                        echo '<div class="print-signature-wrap">' . $sig . '</div>';
                                    }
                                ?>
                                
                                <?php 
                                if (strtolower(str_replace(' ', '', $row['reviewed_by'])) !== strtolower(str_replace(' ', '', $row['reviewedFix_signature']))) {
                                    echo "for: " . strtoupper($row['reviewed_by']) . "<br>";
                                }
                                echo strtoupper($row['reviewedFix_signature']);
                                ?>
                            </div>
                            <div style="font-size: 6px; font-style: italic; visibility:hidden;">Department Manager</div>
                        </td>
                        <td style="width: 33%;">
                            <div style="font-weight: bold; visibility:hidden;">Noted by:</div>
                            <div style="font-size: 10px; margin-bottom: 5px; padding-bottom: 2px; min-height: 20px;">
                                <!-- put signature here for noted/approved -->
                                <?php
                                    $sig = render_signature_img($conn, $row['noted_signature'] ?? null, $row['noted_by'] ?? $row['notedFix_signature']);
                                    if (is_string($sig) && strpos($sig, 'data:image') !== false) {
                                        echo '<div class="print-signature-wrap">' . $sig . '</div>';
                                    }
                                ?>

                                <?php 
                                if (strtolower(str_replace(' ', '', $row['noted_by'])) !== strtolower(str_replace(' ', '', $row['notedFix_signature']))) {
                                    echo "for: " . strtoupper($row['noted_by']) . "<br>";
                                }
                                echo strtoupper($row['notedFix_signature']);
                                ?>
                            </div>
                            <div style="font-size: 6px; font-style: italic; visibility:hidden;">Division Head</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Wait for page to fully load, then automatically trigger print
        window.addEventListener('load', function() {
            // Hide loading overlay
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'none';
            
            // Show print button as fallback
            const printButton = document.getElementById('printButton');
            printButton.style.display = 'block';
            
            // Small delay to ensure page is fully rendered
            setTimeout(function() {
                // Automatically trigger print dialog
                printPage();
            }, 800);
        });
        
        // Print function with error handling
        function printPage() {
            try {
                if (window.print) {
                    window.print();
                } else {
                    alert('Your browser does not support automatic printing. Please use Ctrl+P (Windows) or Cmd+P (Mac) to print.');
                }
            } catch (error) {
                console.error('Print error:', error);
                alert('Unable to open print dialog. Please use Ctrl+P (Windows) or Cmd+P (Mac) to print.');
            }
        }
        
        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            if (e.key === 'Escape') {
                window.close();
            }
        });
        
        // Handle print dialog events
        window.addEventListener('beforeprint', function() {
            const printButton = document.getElementById('printButton');
            if (printButton) {
                printButton.style.display = 'none';
            }
        });
        
        window.addEventListener('afterprint', function() {
            const printButton = document.getElementById('printButton');
            if (printButton) {
                printButton.style.display = 'block';
            }
        });
        
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                const printButton = document.getElementById('printButton');
                if (printButton) {
                    printButton.style.display = 'block';
                }
            }
        });
    </script>
</body>
</html>