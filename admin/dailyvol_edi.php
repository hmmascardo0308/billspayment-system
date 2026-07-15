<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_name'])) {
    header('location:../login_form.php');
    exit;
}

// Include database connection
require_once __DIR__ . '/../config/config.php';

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Get partner ID and date range from URL parameters
$partnerID = isset($_GET['partnerID']) ? $_GET['partnerID'] : '';
$fromDate = isset($_GET['fromDate']) ? $_GET['fromDate'] : date('Y-m-01');
$toDate = isset($_GET['toDate']) ? $_GET['toDate'] : date('Y-m-d');

// Get month and year
$Month = date('F', strtotime($fromDate));
$Year = date('Y', strtotime($fromDate));

$partnerQuery = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id = '" . $conn->real_escape_string($partnerID) . "'";
$partnerResult = $conn->query($partnerQuery);
if ($partnerResult && $partnerResult->num_rows > 0) {
    $partnerRow = $partnerResult->fetch_assoc();
    $partnerName = $partnerRow['partner_name'];
}


// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Validate date values
    $validFromDate = !empty($fromDate) ? $fromDate : date('Y-m-01');
    $validToDate = !empty($toDate) ? $toDate : date('Y-m-d');
    
    if (strtotime($validFromDate) && strtotime($validToDate)) {
        // Create new Spreadsheet object
        $spreadsheet = new Spreadsheet();
        
        // Define zones and their corresponding conditions
        $zones = [
            'LUZON' => "mrm.zone_code = 'LZN'",
            'NCR' => "mrm.zone_code = 'NCR'",
            'VISAYAS' => "mrm.zone_code = 'VIS'",
            'MINDANAO' => "mrm.zone_code = 'MIN'",
            'SHOWROOM' => "(mbp.ml_matic_region = 'VISMIN Showroom' OR mbp.ml_matic_region = 'LNCR Showroom')"
        ];
        
        $sheetIndex = 0;
        
        foreach ($zones as $zoneName => $zoneCondition) {
            // Create or get worksheet
            if ($sheetIndex === 0) {
                $worksheet = $spreadsheet->getActiveSheet();
            } else {
                $worksheet = $spreadsheet->createSheet();
            }
            
            $worksheet->setTitle($zoneName);
            
            // Set headers
            $worksheet->setCellValue('A2', 'ZONE');
            $worksheet->setCellValue('B2', 'REGION');
            $worksheet->setCellValue('C2', 'KPCODE');
            $worksheet->setCellValue('D2', 'CHARGE');
            
            // Style headers
            $headerRange = 'A2:D2';
            $worksheet->getStyle($headerRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF']
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ]
            ]);
            
            // Build SQL query for current zone
            $sql = "SELECT
                        mrm.zone_code,
                        mbp.ml_matic_region,
                        mbp.kp_code,
                        (SUM(bt.charge_to_partner) + SUM(bt.charge_to_customer)) AS total_charge
                    FROM 
                        mldb.billspayment_transaction AS bt
                    JOIN 
                        masterdata.branch_profile AS mbp
                        ON bt.branch_id = mbp.branch_id
                        AND NOT bt.branch_id = 2607
                    JOIN
                        masterdata.region_masterfile AS mrm
                        ON bt.region_code = mrm.region_code
                        AND NOT bt.region_code = 'HEADOFFICE1'
                    WHERE (DATE(bt.datetime) BETWEEN '" . $conn->real_escape_string($validFromDate) . "' 
                    AND '" . $conn->real_escape_string($validToDate) . "' OR DATE(bt.cancellation_date) BETWEEN '" . $conn->real_escape_string($validFromDate) . "' 
                    AND '" . $conn->real_escape_string($validToDate) . "')
                    AND (" . $zoneCondition . ")";

            // Add partner filter if not 'All'
            if (!empty($partnerID) && $partnerID !== 'All') {
                $sql .= " AND bt.partner_id = '" . $conn->real_escape_string($partnerID) . "'";
            }

            $sql .= " GROUP BY mrm.zone_code, mbp.ml_matic_region, mbp.kp_code 
                    ORDER BY mbp.ml_matic_region, mbp.kp_code";

            $result = $conn->query($sql);
            $row_num = 3;
            $totalCharge = 0;
            
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    // Apply zone logic for display
                    if ($zoneName === 'SHOWROOM') {
                        $zone = htmlspecialchars($row['ml_matic_region']);
                    } else {
                        $zone = htmlspecialchars($row['zone_code']);
                    }
                    
                    $region = htmlspecialchars($row['ml_matic_region']);
                    $kpcode = htmlspecialchars($row['kp_code']);
                    $charge = $row['total_charge'];
                    $totalCharge += $charge;
                    
                    // Set cell values
                    $worksheet->setCellValue('A' . $row_num, $zone);
                    $worksheet->setCellValue('B' . $row_num, $region);
                    $worksheet->setCellValue('C' . $row_num, $kpcode);
                    $worksheet->setCellValue('D' . $row_num, $charge);
                    
                    // Format the charge cell to display with 2 decimal places
                    $worksheet->getStyle('D' . $row_num)->getNumberFormat()->setFormatCode('#,##0.00');
                    
                    // Style data rows
                    $dataRange = 'A' . $row_num . ':D' . $row_num;
                    $worksheet->getStyle($dataRange)->applyFromArray([
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => '000000']
                            ]
                        ],
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER
                        ]
                    ]);
                    
                    // Override alignment for charge column to be right-aligned (AFTER general styling)
                    $worksheet->getStyle('D' . $row_num)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    
                    $row_num++;
                }
            }
            
            // Add total row
            $totalRowNum = $row_num + 1;
            $worksheet->mergeCells('A' . $totalRowNum . ':C' . $totalRowNum);
            $worksheet->setCellValue('A' . $totalRowNum, 'TOTAL');
            $worksheet->setCellValue('D' . $totalRowNum, $totalCharge);
            
            // Format the total charge cell
            $worksheet->getStyle('D' . $totalRowNum)->getNumberFormat()->setFormatCode('#,##0.00');
            
            // Style total row
            $totalRange = 'A' . $totalRowNum . ':D' . $totalRowNum;
            $worksheet->getStyle($totalRange)->applyFromArray([
                'font' => [
                    'bold' => true
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFEAEA']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ]
            ]);
            
            // Set specific alignments after general styling
            $worksheet->getStyle('A' . $totalRowNum . ':C' . $totalRowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $worksheet->getStyle('D' . $totalRowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            
            // Auto-resize columns
            foreach (range('A', 'D') as $column) {
                $worksheet->getColumnDimension($column)->setAutoSize(true);
            }
            
            $sheetIndex++;
        }
        
        // Set active sheet to first sheet
        $spreadsheet->setActiveSheetIndex(0);
        
        // Create writer and output file
        $writer = new Xlsx($spreadsheet);
        
        // Set headers for download
        if (!empty($partnerID) && $partnerID === 'All'){
                $filename = 'EDI_Bills-Payment_Report_' . $Month . '_' . $Year . '.xlsx';
        }else {
            $filename = 'EDI_Bills-Payment_Report_' . $partnerName . '_' . $Month . '_' . $Year . '.xlsx';
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
}

// Format date range for display
$formattedFromDate = date('M d, Y', strtotime($fromDate));
$formattedToDate = date('M d, Y', strtotime($toDate));
$formattedDateRange = $formattedFromDate . " to " . $formattedToDate;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EDI TOTAL</title>
    <link rel="stylesheet" href="../assets/css/billspaymentSettlement.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../assets/css/edi_styles.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
    <style>
        .edi-title-text{
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="main-content edi-container" style="max-width: 1000px; margin: 34px auto 40px; background:rgb(246, 234, 234); border-radius: 8px; padding: 30px;">
        <div class="button-container">
            <a class="back-button-3d" href="dailyvolume.php">&larr; Back</a>
            <button id="exportExcel" class="export-button-3d">EDI Loading .xlsx</button>
        </div>
        <div class="edi-header">
            <div class="edi-header-content" style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; padding-left:10px;">
                    <img src="../images/ml.png" alt="MLHUILLIER Logo" class="edi-logo" style="height:40px; width:auto; margin-right:15px;">
                    <span class="edi-title-text">ELECTRONIC DATA INTERCHANGE</span>
                </div>
                <div style="padding-right:10px;">
                    <p class="period-text" style="margin:0;"><strong>Period:</strong> <?php echo $formattedDateRange; ?></p>
                </div>
            </div>
        </div>
        <div class="table-container">
            <table class="edi-table" id="ediTable">
                <thead>
                    <tr>
                        <th>ZONE</th>
                        <th>REGION</th>
                        <th>KPCODE</th>
                        <th>CHARGE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Initialize total charge
                    $totalCharge = 0;
                    
                    // Only execute code if not exporting to Excel
                    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET) && !isset($_GET['export'])) {
                        // Validate date values
                        $validFromDate = !empty($fromDate) ? $fromDate : date('Y-m-01');
                        $validToDate = !empty($toDate) ? $toDate : date('Y-m-d');
                        
                        // Check if dates are valid format
                        if (strtotime($validFromDate) && strtotime($validToDate)) {
                            // Build SQL query to fetch data
                            $sql = "SELECT
                                        mbp.zone,
                                        mbp.ml_matic_region,
                                        mbp.kp_code,
                                        (SUM(bt.charge_to_partner) + SUM(bt.charge_to_customer)) AS total_charge
                                    FROM 
                                        mldb.billspayment_transaction AS bt
                                    JOIN 
                                        masterdata.branch_profile AS mbp
                                        ON bt.branch_id = mbp.branch_id
                                        AND NOT bt.branch_id =2607
                                    JOIN
                                        masterdata.region_masterfile as mrm
                                        ON mrm.region_code = bt.region_code
                                        AND NOT bt.region_code = 'HEADOFFICE1'
                                    WHERE (DATE(bt.datetime) BETWEEN '" . $conn->real_escape_string($validFromDate) . "' 
                                    AND '" . $conn->real_escape_string($validToDate) . "' OR DATE(bt.cancellation_date) BETWEEN '" . $conn->real_escape_string($validFromDate) . "' 
                                    AND '" . $conn->real_escape_string($validToDate) . "')";

                            // Add partner filter if not 'All'
                            if (!empty($partnerID) && $partnerID !== 'All') {
                                $sql .= " AND bt.partner_id = '" . $conn->real_escape_string($partnerID) . "'";
                            }

                            $sql .= " GROUP BY mbp.zone, mbp.ml_matic_region, mbp.kp_code 
                                      ORDER BY mbp.ml_matic_region, mbp.kp_code";

                            // Execute query only if we have valid dates
                            $result = $conn->query($sql);
                            
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    if ($row['ml_matic_region'] === 'VISMIN Showroom' || $row['ml_matic_region'] === 'LNCR Showroom') {
                                        $zone = htmlspecialchars($row['ml_matic_region']);
                                    }else{
                                        $zone = htmlspecialchars($row['zone']);
                                    }
                                    $region = htmlspecialchars($row['ml_matic_region']);
                                    $kpcode = htmlspecialchars($row['kp_code']);
                                    $charge = $row['total_charge'];
                                    $totalCharge += $row['total_charge'];
                            ?>
                                    <tr>
                                        <td><?php echo $zone; ?></td>
                                        <td><?php echo $region; ?></td>
                                        <td><?php echo $kpcode; ?></td>
                                        <td><?php echo number_format($charge, 2); ?></td>
                                    </tr>
                            <?php
                                }
                                    
                                
                            } else {
                                echo "<tr><td colspan='4'>No records found for the selected filters.</td></tr>";
                            }
                        } else {
                            echo "<tr><td colspan='4'>Please apply filters to view data.</td></tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4'>Please apply filters to view data.</td></tr>";
                    }
                    ?>
                    <tr style="font-weight:bold; background:#ffeaea;">
                        <td colspan="3" style="text-align:right;">TOTAL</td>
                        <td><?php echo number_format($totalCharge, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <script>
    // PhpSpreadsheet Excel export
    document.getElementById('exportExcel').addEventListener('click', function () {
        // Get current URL parameters
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('export', 'excel');
        
        // Redirect to trigger export
        window.location.href = currentUrl.toString();
    });
    </script>
</body>
</html>