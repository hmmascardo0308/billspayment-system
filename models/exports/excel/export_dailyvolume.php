<?php
require_once __DIR__ . '/../../../config/config.php';
require_once '../../../vendor/autoload.php';
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

// Check if the required parameters are set

    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
    use PhpOffice\PhpSpreadsheet\Style\Border;
    use PhpOffice\PhpSpreadsheet\Style\Fill;

if (isset($_POST['partnerName']) && isset($_POST['fromDate']) && isset($_POST['toDate'])) {
    $partnerName = $_POST['partnerName'];
    $fromDate = $_POST['fromDate'];
    $toDate = $_POST['toDate'];
    
    $partnerID = null;
    $partnerID_kpx = null;
    $partnersName = $partnerName;
    
    if ($partnerName !== 'All') {
        $get_kp7_kpx_partner_id = "SELECT partner_id, partner_id_kpx, partner_name FROM masterdata.partner_masterfile WHERE partner_name = ? LIMIT 1";
        $stmt = $conn->prepare($get_kp7_kpx_partner_id);
        $stmt->bind_param("s", $partnerName);
        $stmt->execute();
        $kp7_kpx_result = $stmt->get_result();
        
        if ($kp7_kpx_result && $kp7_kpx_result->num_rows > 0) {
            $kp7_kpx_row = $kp7_kpx_result->fetch_assoc();
            $partnerID = $kp7_kpx_row['partner_id'];
            $partnerID_kpx = $kp7_kpx_row['partner_id_kpx'];
            $partnersName = $kp7_kpx_row['partner_name'];
        }
        $stmt->close();
    }
    
    // Format the date range for the export filename and header
    $fromDateObj = new DateTime($fromDate);
    $toDateObj = new DateTime($toDate);
    
    if ($fromDateObj->format('Y-m-d') == $toDateObj->format('Y-m-d')) {
        $formattedDateRange = $fromDateObj->format('F d, Y');
    } else if ($fromDateObj->format('Y-m') == $toDateObj->format('Y-m')) {
        $formattedDateRange = $fromDateObj->format('F d') . '-' . $toDateObj->format('d, Y');
    } else {
        $formattedDateRange = $fromDateObj->format('F d') . '-' . $toDateObj->format('F d, Y');
    }
    $formattedDateRange = strtoupper($formattedDateRange);
    
    // Modified query to only count transactions with no '*' in status for MLKP7/KPX columns
    // This matches the query in dailyvolume.php
    $total_query = "SELECT bt.partner_name,
                     bt.partner_id,
                     bt.partner_id_kpx,
                     COUNT(DISTINCT bt.reference_no) AS total_volume,
                     SUM(bt.amount_paid) AS total_principal,
                     SUM(bt.charge_to_customer + bt.charge_to_partner) AS total_charge
              FROM billspayment_transaction bt
              WHERE bt.datetime BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
              AND (bt.status IS NULL OR bt.status = '' OR bt.status NOT LIKE '%*%')";

    if ($partnerName !== 'All') {
        if (!empty($partnerID)) {
            $total_query .= " AND bt.partner_id = '" . mysqli_real_escape_string($conn, $partnerID) . "'";
        } elseif (!empty($partnerID_kpx)) {
            $total_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partnerID_kpx) . "'";
        } else {
            // Fallback to partner name if neither ID is available
            $total_query .= " AND bt.partner_name = '" . mysqli_real_escape_string($conn, $partnerName) . "'";
        }
    }

    $total_query .= " GROUP BY bt.partner_name, bt.partner_id, bt.partner_id_kpx ORDER BY bt.partner_name ASC";
    
    // Adjustment query to calculate adjustment values for status containing '*'
    // This matches the query in dailyvolume.php
    $adj_query = "SELECT 
                    bt.partner_name,
                    bt.partner_id,
                    bt.partner_id_kpx,
                    COUNT(DISTINCT bt.reference_no) AS adjustment_volume,
                    SUM(bt.amount_paid) AS adjustment_principal,
                    SUM(bt.charge_to_customer + bt.charge_to_partner) AS adjustment_charge
                  FROM billspayment_transaction bt
                  WHERE bt.cancellation_date BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                  AND (bt.status = '*' OR bt.status LIKE '%*%')";

    if ($partnerName !== 'All') {
        if (!empty($partnerID)) {
            $adj_query .= " AND bt.partner_id = '" . mysqli_real_escape_string($conn, $partnerID) . "'";
        } elseif (!empty($partnerID_kpx)) {
            $adj_query .= " AND bt.partner_id_kpx = '" . mysqli_real_escape_string($conn, $partnerID_kpx) . "'";
        } else {
            // Fallback to partner name if neither ID is available
            $adj_query .= " AND bt.partner_name = '" . mysqli_real_escape_string($conn, $partnerName) . "'";
        }
    }

    $adj_query .= " GROUP BY bt.partner_name, bt.partner_id, bt.partner_id_kpx ORDER BY bt.partner_name ASC";
    
    // Execute queries
    $total_result = $conn->query($total_query);
    $adj_result = $conn->query($adj_query);
    
    $result_data = [];
    if ($total_result && $total_result->num_rows > 0) {
        // Create a lookup array for adjustment data 
        $adj_data = [];
        if ($adj_result && $adj_result->num_rows > 0) {
            while ($adj_row = $adj_result->fetch_assoc()) {
                $key = $adj_row['partner_name'] . '-' . $adj_row['partner_id'];
                $adj_data[$key] = $adj_row;
            }
        }
        
        // Process results
        while ($row = $total_result->fetch_assoc()) {
            $partner_id = $row['partner_id'];
            $partner_name = $row['partner_name'];
            $key = $partner_name . '-' . $partner_id;
            
            // Get bank names for this partner
            $biller_query = "SELECT GROUP_CONCAT(DISTINCT bank ORDER BY bank SEPARATOR ', ') AS bank 
                             FROM partner_bank 
                             WHERE partner_id = '$partner_id'";
            $biller_result = $conn->query($biller_query);
            
            if ($biller_result && $biller_row = $biller_result->fetch_assoc()) {
                $row['bank'] = $biller_row['bank'];
            } else {
                $row['bank'] = '';
            }
            
            // Add adjustment data if it exists
            if (isset($adj_data[$key])) {
                $row['adjustment_volume'] = abs($adj_data[$key]['adjustment_volume']);
                $row['adjustment_principal'] = abs($adj_data[$key]['adjustment_principal']);
                $row['adjustment_charge'] = abs($adj_data[$key]['adjustment_charge']);
            } else {
                $row['adjustment_volume'] = 0;
                $row['adjustment_principal'] = 0;
                $row['adjustment_charge'] = 0;
            }
            
            // Calculate net totals - using the same calculation as dailyvolume.php
            $row['net_volume'] = $row['total_volume'] - $row['adjustment_volume'];
            $row['net_principal'] = $row['total_principal'] - $row['adjustment_principal'];
            $row['net_charge'] = $row['total_charge'] - $row['adjustment_charge'];
            
            $result_data[] = $row;
        }
    }
    
    // Create new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('M.LHUILLIER PHILIPPINES')
        ->setTitle('Bills Payment Report')
        ->setDescription('Daily Volume Report for ' . $formattedDateRange);
    
    // Set headers
    $sheet->setCellValue('A1', 'M.LHUILLIER PHILIPPINES');
    $sheet->setCellValue('A2', 'BILLS PAYMENT REPORT');
    $sheet->setCellValue('A3', 'Report Date: ' . $formattedDateRange);
    
    // Style headers
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('dc3545');
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('ad12cc');
    
    // Set column headers
    $sheet->setCellValue('A5', 'No.');
    $sheet->setCellValue('B5', 'Partner\'s Name');
    $sheet->setCellValue('C5', 'Bank');
    $sheet->setCellValue('D5', 'Biller\'s Name');
    $sheet->setCellValue('E5', 'MLKP7 / KPX');
    $sheet->setCellValue('H5', 'Adjustments');
    $sheet->setCellValue('K5', 'Net Total Trans');
    
    // Merge cells for group headers
    $sheet->mergeCells('E5:G5');
    $sheet->mergeCells('H5:J5');
    $sheet->mergeCells('K5:M5');
    
    // Set sub-headers
    $sheet->setCellValue('E6', 'Vol');
    $sheet->setCellValue('F6', 'Principal');
    $sheet->setCellValue('G6', 'Charge');
    $sheet->setCellValue('H6', 'Vol');
    $sheet->setCellValue('I6', 'Principal');
    $sheet->setCellValue('J6', 'Charge');
    $sheet->setCellValue('K6', 'Vol');
    $sheet->setCellValue('L6', 'Principal');
    $sheet->setCellValue('M6', 'Charge');
    
    // Style headers
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FF0000']
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
    ];
    
    $sheet->getStyle('A5:M6')->applyFromArray($headerStyle);
    
    // Add data rows
    $row = 7;
    $counter = 1;
    $totals = [
        'volume' => 0,
        'principal' => 0,
        'charge' => 0,
        'adj_volume' => 0,
        'adj_principal' => 0,
        'adj_charge' => 0,
        'net_volume' => 0,
        'net_principal' => 0,
        'net_charge' => 0
    ];
    
    foreach ($result_data as $data) {
        $sheet->setCellValue('A' . $row, $counter++);
        $sheet->setCellValue('B' . $row, $data['partner_name']);
        $sheet->setCellValue('C' . $row, $data['bank'] ? $data['bank'] : '');
        $sheet->setCellValue('D' . $row, '');
        $sheet->setCellValue('E' . $row, $data['total_volume']);
        $sheet->setCellValue('F' . $row, $data['total_principal']);
        $sheet->setCellValue('G' . $row, $data['total_charge']);
        $sheet->setCellValue('H' . $row, $data['adjustment_volume']);
        $sheet->setCellValue('I' . $row, $data['adjustment_principal']);
        $sheet->setCellValue('J' . $row, $data['adjustment_charge']);
        $sheet->setCellValue('K' . $row, $data['net_volume']);
        $sheet->setCellValue('L' . $row, $data['net_principal']);
        $sheet->setCellValue('M' . $row, $data['net_charge']);
        
        // Update totals
        $totals['volume'] += $data['total_volume'];
        $totals['principal'] += $data['total_principal'];
        $totals['charge'] += $data['total_charge'];
        $totals['adj_volume'] += $data['adjustment_volume'];
        $totals['adj_principal'] += $data['adjustment_principal'];
        $totals['adj_charge'] += $data['adjustment_charge'];
        $totals['net_volume'] += $data['net_volume'];
        $totals['net_principal'] += $data['net_principal'];
        $totals['net_charge'] += $data['net_charge'];
        
        $row++;
    }
    
    // Add totals row
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->mergeCells('A' . $row . ':D' . $row);
    $sheet->setCellValue('E' . $row, $totals['volume']);
    $sheet->setCellValue('F' . $row, $totals['principal']);
    $sheet->setCellValue('G' . $row, $totals['charge']);
    $sheet->setCellValue('H' . $row, $totals['adj_volume']);
    $sheet->setCellValue('I' . $row, $totals['adj_principal']);
    $sheet->setCellValue('J' . $row, $totals['adj_charge']);
    $sheet->setCellValue('K' . $row, $totals['net_volume']);
    $sheet->setCellValue('L' . $row, $totals['net_principal']);
    $sheet->setCellValue('M' . $row, $totals['net_charge']);
    
    // Style totals row
    $totalStyle = [
        'font' => ['bold' => true],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ];
    $sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray($totalStyle);
    
    // Style data rows
    $dataStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ];
    $sheet->getStyle('A7:M' . $row)->applyFromArray($dataStyle);
    
    // Right align numeric columns
    $sheet->getStyle('E7:M' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    // Set number format for currency columns
    $sheet->getStyle('F7:G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('I7:J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('L7:M' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    // Set number format for volume columns
    $sheet->getStyle('E7:E' . $row)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('H7:H' . $row)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('K7:K' . $row)->getNumberFormat()->setFormatCode('#,##0');
    
    // Auto-size columns
    foreach (range('A', 'M') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if ($partnerName !== 'All') {
        if (!empty($partnerID) || !empty($partnerID_kpx)) {
            $safePartnerName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partnersName);
            $filename = "Daily-Volume_" . $safePartnerName . "_" . $fromDateObj->format('Ymd') . "_to_" . $toDateObj->format('Ymd') . ".xlsx";
        } else {
            // Fallback if neither partnerID nor partnerID_kpx is set
            $safePartnerName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partnerName);
            $filename = "Daily-Volume_" . $safePartnerName . "_" . $fromDateObj->format('Ymd') . "_to_" . $toDateObj->format('Ymd') . ".xlsx";
        }
    } else {
        $filename = "Daily-Volume_All_Partners_" . $fromDateObj->format('Ymd') . "_to_" . $toDateObj->format('Ymd') . ".xlsx";
    }

    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Write file
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
    // Clean up
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    
} else {
    echo "Go to Main Menu, ayaw dri";
}
?>