<?php
    session_start();
    require_once __DIR__ . '/../config/config.php';
    require '../vendor/autoload.php';

    if (!isset($_SESSION['admin_name'])) {
        header('location:../login_form.php');
        exit();
    }

    // Initialize variables
    $ref_options = [];
    $branch_options = [];
    $region_options = [];
    $zone_options = [];
    $partner_options = [];
    $search_data = [];

    try {
        // Get distinct values for autocomplete with error handling
        $ref_query = "SELECT DISTINCT reference_no FROM mldb.billspayment_transaction WHERE reference_no IS NOT NULL AND reference_no != '' AND status = '*'";
        $ref_result = mysqli_query($conn, $ref_query);
        if ($ref_result) {
            $ref_data = mysqli_fetch_all($ref_result, MYSQLI_ASSOC);
            $ref_options = array_column($ref_data, 'reference_no');
        }

        // Get distinct branch data
        $branch_query = "SELECT DISTINCT outlet, branch_id, branch_code FROM mldb.billspayment_transaction WHERE outlet IS NOT NULL OR branch_id IS NOT NULL OR branch_code IS NOT NULL AND status = '*'";
        $branch_result = mysqli_query($conn, $branch_query);
        if ($branch_result) {
            $branch_data = mysqli_fetch_all($branch_result, MYSQLI_ASSOC);
            foreach($branch_data as $branch) {
                if (!empty($branch['outlet'])) $branch_options[] = $branch['outlet'];
                if (!empty($branch['branch_id'])) $branch_options[] = $branch['branch_id'];
                if (!empty($branch['branch_code'])) $branch_options[] = $branch['branch_code'];
            }
            $branch_options = array_unique($branch_options);
            $branch_options = array_values($branch_options); // Re-index array
        }

        // Get distinct regions
        $region_query = "SELECT DISTINCT region FROM mldb.billspayment_transaction WHERE region IS NOT NULL AND region != '' AND status = '*'";
        $region_result = mysqli_query($conn, $region_query);
        if ($region_result) {
            $region_data = mysqli_fetch_all($region_result, MYSQLI_ASSOC);
            $region_options = array_column($region_data, 'region');
        }

        // Get distinct zones - FIX: Better error handling and null checks
        $zone_query = "SELECT DISTINCT zone_code FROM mldb.billspayment_transaction WHERE zone_code IS NOT NULL AND zone_code != '' AND status = '*'";
        $zone_result = mysqli_query($conn, $zone_query);
        if ($zone_result) {
            $zone_data = mysqli_fetch_all($zone_result, MYSQLI_ASSOC);
            $zone_options = array_column($zone_data, 'zone_code');
            $zone_options = array_filter($zone_options); // Remove empty values
            $zone_options = array_values($zone_options); // Re-index array
        }

        // Get distinct partners
        $partner_query = "SELECT DISTINCT partner_id, partner_name FROM mldb.billspayment_transaction WHERE partner_id IS NOT NULL OR partner_name IS NOT NULL AND status = '*'";
        $partner_result = mysqli_query($conn, $partner_query);
        if ($partner_result) {
            $partner_data = mysqli_fetch_all($partner_result, MYSQLI_ASSOC);
            foreach($partner_data as $partner) {
                if (!empty($partner['partner_id'])) $partner_options[] = $partner['partner_id'];
                if (!empty($partner['partner_name'])) $partner_options[] = $partner['partner_name'];
            }
            $partner_options = array_unique($partner_options);
            $partner_options = array_values($partner_options); // Re-index array
        }

    } catch (Exception $e) {
        error_log("Database error in billspay_cancellation.php: " . $e->getMessage());
        // Continue with empty arrays
    }

    // Search functionality
    if (isset($_POST['search_submit'])) {
        try {
            // Get form data with better sanitization
            $ref_number = trim($_POST['ref_number'] ?? '');
            $branch_field = trim($_POST['branch_field'] ?? '');
            $region_field = trim($_POST['region_field'] ?? '');
            $zone_field = trim($_POST['zone_field'] ?? '');
            $partner_field = trim($_POST['partner_field'] ?? '');
            $date_start = trim($_POST['date_start'] ?? '');
            $date_end = trim($_POST['date_end'] ?? '');
            
            // Build search query with proper escaping
            $search_query = "SELECT * FROM mldb.billspayment_transaction WHERE 1=1 AND status = '*'";
            $params = [];
            $types = "";
            
            if (!empty($ref_number)) {
                $search_query .= " AND reference_no = ?";
                $params[] = $ref_number;
                $types .= "s";
            }
            
            if (!empty($branch_field)) {
                $search_query .= " AND (outlet LIKE ? OR branch_id LIKE ? OR branch_code LIKE ?)";
                $branch_search = "%$branch_field%";
                $params[] = $branch_search;
                $params[] = $branch_search;
                $params[] = $branch_search;
                $types .= "sss";
            }
            
            if (!empty($region_field)) {
                $search_query .= " AND region LIKE ?";
                $params[] = "%$region_field%";
                $types .= "s";
            }
            
            // FIX: Better zone field handling
            if (!empty($zone_field)) {
                $search_query .= " AND (zone_code = ? OR zone_code LIKE ?)";
                $params[] = $zone_field;
                $params[] = "%$zone_field%";
                $types .= "ss";
            }
            
            if (!empty($partner_field)) {
                $search_query .= " AND (partner_id LIKE ? OR partner_name LIKE ?)";
                $partner_search = "%$partner_field%";
                $params[] = $partner_search;
                $params[] = $partner_search;
                $types .= "ss";
            }
            
            // Add date range filtering
            if (!empty($date_start) && !empty($date_end)) {
                $search_query .= " AND (DATE(datetime) BETWEEN ? AND ?) OR (DATE(cancellation_date) BETWEEN ? AND ?)";
                $params[] = $date_start;
                $params[] = $date_end;
                $params[] = $date_start;
                $params[] = $date_end;
                $types .= "ssss";
            } elseif (!empty($date_start)) {
                $search_query .= " AND DATE(datetime) = ? OR DATE(cancellation_date) = ?";
                $params[] = $date_start;
                $params[] = $date_start;
                $types .= "ss";
            } elseif (!empty($date_end)) {
                $search_query .= " AND (DATE(datetime) = ?) OR (DATE(cancellation_date) = ?)";
                $params[] = $date_end;
                $params[] = $date_end;
                $types .= "ss";
            }
            
            // Add ORDER BY and LIMIT for performance
            $search_query .= " ORDER BY datetime, cancellation_date ASC";
            
            // Execute search query with better error handling
            if (!empty($params)) {
                $stmt = mysqli_prepare($conn, $search_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                    if (mysqli_stmt_execute($stmt)) {
                        $search_result = mysqli_stmt_get_result($stmt);
                        if ($search_result) {
                            $search_data = mysqli_fetch_all($search_result, MYSQLI_ASSOC);
                        }
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    throw new Exception("Failed to prepare statement: " . mysqli_error($conn));
                }
            } else {
                $search_result = mysqli_query($conn, $search_query);
                if ($search_result) {
                    $search_data = mysqli_fetch_all($search_result, MYSQLI_ASSOC);
                } else {
                    throw new Exception("Query failed: " . mysqli_error($conn));
                }
            }
            
        } catch (Exception $e) {
            error_log("Search error in billspay_cancellation.php: " . $e->getMessage());
            $search_data = [];
            $error_message = "Search failed. Please try again.";
        }
    }

    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
    use PhpOffice\PhpSpreadsheet\Style\Border;
    use PhpOffice\PhpSpreadsheet\Style\Fill;
    
    // Excel Export functionality - Updated for large datasets
    if (isset($_POST['export_to_excel'])) {
        // Get form data for export
        $ref_number = trim($_POST['ref_number'] ?? '');
        $branch_field = trim($_POST['branch_field'] ?? '');
        $region_field = trim($_POST['region_field'] ?? '');
        $zone_field = trim($_POST['zone_field'] ?? '');
        $partner_field = trim($_POST['partner_field'] ?? '');
        $date_start = trim($_POST['date_start'] ?? '');
        $date_end = trim($_POST['date_end'] ?? '');
        
        try {
            // Set memory and time limits for large exports
            ini_set('memory_limit', '100000M');
            ini_set('max_execution_time', 300); // 5 minutes
            
            // Create new Spreadsheet object
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Set document properties
            $spreadsheet->getProperties()
                ->setCreator('Bills Payment System')
                ->setTitle('Bills Payment Cancellation Report')
                ->setSubject('Bills Payment Records')
                ->setDescription('Exported bills payment cancellation data');
            
            if($date_start === $date_end){
                $headers['B5'] = !empty($date_start) ? $date_start : $date_end;
            }elseif(!empty($date_start) && !empty($date_end)){
                $headers['B5'] = $date_start . ' to ' . $date_end;
            }

            // Set headers
            $headers = [
                'A1' => 'M Lhuillier Philippines, Inc.',
                'A2' => 'Bills Payment Cancellation Report',
                'A3' => 'Generated Date: ',
                'B3' => date('Y-m-d' ),
                'A5' => 'Report Date:',
                'B5' => $headers['B5'] ?? '',
                'A6' => 'Run Date:',
                'B6' => '',
                'A7' => 'Printed by: ',
                'B7' => ($_SESSION['admin_name'] ?? $_SESSION['user_name']),
                'A9' => 'Transaction Date',
                'B9' => 'Cancellation Date',
                'C9' => 'Reference Number', 
                'D9' => 'Branch Name',
                'E9' => 'BOS Code',
                'F9' => 'Branch ID',
                'G9' => 'Zone',
                'H9' => 'Region',
                'I9' => 'Principal',
                'J9' => 'Charge to Partner',
                'K9' => 'Charge to Customer',
                'L9' => 'Partner ID',
                'M9' => 'Partner'
            ];
            
            // Apply headers
            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }
            
            // Style the header row
            $headerStyle = [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 12
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'EA6666']
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
            
            $sheet->getStyle('A9:M9')->applyFromArray($headerStyle);
            
            // Set column widths
            $columnWidths = [
                'A' => 20, 'B' => 20, 'C' => 18, 'D' => 25, 'E' => 12, 'F' => 12,
                'G' => 10, 'H' => 15, 'I' => 15, 'J' => 18, 'K' => 18, 'L' => 18, 'M' => 25
            ];
            
            foreach ($columnWidths as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }
            
            // REBUILT QUERY FOR EXPORT - Don't use $search_data array to avoid memory issues
            // Build the same search query as used in search functionality
            $export_query = "SELECT * FROM mldb.billspayment_transaction WHERE 1=1 AND status = '*'";
            $export_params = [];
            $export_types = "";
            
            
            
            // Apply same filters as search
            if (!empty($ref_number)) {
                $export_query .= " AND reference_no = ?";
                $export_params[] = $ref_number;
                $export_types .= "s";
            }
            
            if (!empty($branch_field)) {
                $export_query .= " AND (outlet LIKE ? OR branch_id LIKE ? OR branch_code LIKE ?)";
                $branch_search = "%$branch_field%";
                $export_params[] = $branch_search;
                $export_params[] = $branch_search;
                $export_params[] = $branch_search;
                $export_types .= "sss";
            }
            
            if (!empty($region_field)) {
                $export_query .= " AND region LIKE ?";
                $export_params[] = "%$region_field%";
                $export_types .= "s";
            }
            
            if (!empty($zone_field)) {
                $export_query .= " AND (zone_code = ? OR zone_code LIKE ?)";
                $export_params[] = $zone_field;
                $export_params[] = "%$zone_field%";
                $export_types .= "ss";
            }
            
            if (!empty($partner_field)) {
                $export_query .= " AND (partner_id LIKE ? OR partner_name LIKE ?)";
                $partner_search = "%$partner_field%";
                $export_params[] = $partner_search;
                $export_params[] = $partner_search;
                $export_types .= "ss";
            }
            
            // Add date range filtering
            if (!empty($date_start) && !empty($date_end)) {
                $export_query .= " AND (DATE(datetime) BETWEEN ? AND ?) OR (DATE(cancellation_date) BETWEEN ? AND ?)";
                $export_params[] = $date_start;
                $export_params[] = $date_end;
                $export_types .= "ss";
            } elseif (!empty($date_start)) {
                $export_query .= " AND DATE(datetime) = ? OR DATE(cancellation_date) = ?";
                $export_params[] = $date_start;
                $export_types .= "s";
            } elseif (!empty($date_end)) {
                $export_query .= " AND (DATE(datetime) = ?) OR (DATE(cancellation_date) = ?)";
                $export_params[] = $date_end;
                $export_types .= "s";
            }
            
            $export_query .= " ORDER BY datetime, cancellation_date ASC";
            
            // Execute export query with streaming
            if (!empty($export_params)) {
                $export_stmt = mysqli_prepare($conn, $export_query);
                if ($export_stmt) {
                    mysqli_stmt_bind_param($export_stmt, $export_types, ...$export_params);
                    mysqli_stmt_execute($export_stmt);
                    $export_result = mysqli_stmt_get_result($export_stmt);
                } else {
                    throw new Exception("Failed to prepare export statement: " . mysqli_error($conn));
                }
            } else {
                $export_result = mysqli_query($conn, $export_query);
                if (!$export_result) {
                    throw new Exception("Export query failed: " . mysqli_error($conn));
                }
            }
            
            // Process data in chunks to avoid memory issues
            $row = 10;
            $totalPrincipal = 0;
            $totalPartner = 0;
            $totalCustomer = 0;
            $chunkSize = 1000; // Process 1000 rows at a time
            $processedRows = 0;
            
            // Style templates for rows
            $normalRowStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ]
            ];
            
            $cancelledRowStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F8D7DA']
                ]
            ];
            
            // Stream data row by row
            while ($data = mysqli_fetch_assoc($export_result)) {
                // Set cell values
                $sheet->setCellValue('A' . $row, !empty($data['datetime']) ? date('Y-m-d H:i:s', strtotime($data['datetime'])) : '');
                $sheet->setCellValue('B' . $row, !empty($data['cancellation_date']) ? date('Y-m-d H:i:s', strtotime($data['cancellation_date'])) : '');
                $sheet->setCellValue('C' . $row, $data['reference_no'] ?? '');
                $sheet->setCellValue('D' . $row, $data['outlet'] ?? '');
                $sheet->setCellValue('E' . $row, $data['branch_code'] ?? '');
                $sheet->setCellValue('F' . $row, $data['branch_id'] ?? '');
                $sheet->setCellValue('G' . $row, $data['zone_code'] ?? '');
                $sheet->setCellValue('H' . $row, $data['region'] ?? '');
                
                // Handle numeric values
                $amountPaid = floatval($data['amount_paid'] ?? 0);
                $chargePartner = floatval($data['charge_to_partner'] ?? 0);
                $chargeCustomer = floatval($data['charge_to_customer'] ?? 0);
                
                $sheet->setCellValue('I' . $row, $amountPaid);
                $sheet->setCellValue('J' . $row, $chargePartner);
                $sheet->setCellValue('K' . $row, $chargeCustomer);
                $sheet->setCellValue('L' . $row, $data['partner_id'] ?? '');
                $sheet->setCellValue('M' . $row, $data['partner_name'] ?? '');

                // Calculate totals
                $totalPrincipal += $amountPaid;
                $totalPartner += $chargePartner;
                $totalCustomer += $chargeCustomer;
                
                // Apply styling based on status
                $rowStyle = (($data['status'] ?? '') == '*') ? $cancelledRowStyle : $normalRowStyle;
                $sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray($rowStyle);
                
                // Format currency columns
                $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                
                // Right align currency columns
                $sheet->getStyle('I' . $row . ':K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                
                $row++;
                $processedRows++;
                
                // Clear memory every chunk
                if ($processedRows % $chunkSize == 0) {
                    // Force garbage collection
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
            
            // Close result and statement
            mysqli_free_result($export_result);
            if (isset($export_stmt)) {
                mysqli_stmt_close($export_stmt);
            }
            
            // Add totals row if we have data
            if ($processedRows > 0) {
                $totalRow = $row + 1;
                $sheet->setCellValue('H' . $totalRow, 'TOTAL AMOUNT:');
                $sheet->setCellValue('I' . $totalRow, $totalPrincipal);
                $sheet->setCellValue('J' . $totalRow, $totalPartner);
                $sheet->setCellValue('K' . $totalRow, $totalCustomer);
                
                // Style totals row
                $totalStyle = [
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E8F5E8']
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THICK,
                            'color' => ['rgb' => '28A745']
                        ]
                    ]
                ];
                
                $sheet->getStyle('I' . $totalRow . ':K' . $totalRow)->applyFromArray($totalStyle);
                $sheet->getStyle('I' . $totalRow . ':K' . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('I' . $totalRow . ':K' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            
            // Set worksheet title
            $sheet->setTitle('Bills Payment Records');
            
            // Generate filename with timestamp and row count
            $filename = 'Bills_Payment_Cancellation_' . number_format($processedRows) . '_Records_' . date('Y-m-d_H-i-s') . '.xlsx';
            
            // Create Excel writer with optimizations
            $writer = new Xlsx($spreadsheet);
            
            // Optimize for large files
            $writer->setPreCalculateFormulas(false);
            
            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            
            // Clear any output buffer
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Save to output
            $writer->save('php://output');
            
            // Clean up memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            
            exit;
            
        } catch (Exception $e) {
            // Clean up on error
            if (isset($export_result)) {
                mysqli_free_result($export_result);
            }
            if (isset($export_stmt)) {
                mysqli_stmt_close($export_stmt);
            }
            
            $error_message = "Excel export failed: " . $e->getMessage();
            error_log("Excel export error: " . $e->getMessage());
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bills Payment Cancellation</title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../assets/css/billspaymentImportFile.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../images/MLW logo.png" type="image/png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        /* Custom scrollbar for body tag */
        body {
            overflow-y: scroll; /* Always show vertical scrollbar */
            overflow-x: hidden; /* Hide horizontal scrollbar */
            height: 100vh; /* Set body height to viewport height */
        }

        /* Custom scrollbar styling for body */
        body::-webkit-scrollbar {
            width: 16px; /* Width of the scrollbar */
        }

        body::-webkit-scrollbar-track {
            background: #f1f3f4;
            border-radius: 12px;
            margin: 10px 0;
            border: 1px solid #e0e0e0;
            box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.1);
        }

        body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #ea6666ff 0%, #a24b4bff 100%);
            border-radius: 12px;
            border: 2px solid #f1f3f4;
            min-height: 40px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #a24b4bff 0%, #ea6666ff 100%);
            border: 2px solid #e9ecef;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            transform: scale(1.05);
        }

        body::-webkit-scrollbar-thumb:active {
            background: linear-gradient(135deg, #d85a5aff 0%, #c14646ff 100%);
            box-shadow: 0 2px 8px rgba(90, 103, 216, 0.4);
        }

        body::-webkit-scrollbar-corner {
            background: #f1f3f4;
            border-radius: 8px;
        }

        /* Firefox scrollbar for body */
        html {
            scrollbar-width: auto;
            scrollbar-color: #ea6666ff #f1f3f4;
        }

        /* For Internet Explorer and Edge */
        body {
            -ms-overflow-style: scrollbar;
            scrollbar-face-color: #ea6666ff;
            scrollbar-track-color: #f1f3f4;
            scrollbar-arrow-color: #a24b4bff;
            scrollbar-shadow-color: rgba(234, 102, 102, 0.3);
        }

        /* Ensure smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Optional: Add padding to prevent content from hiding behind scrollbar */
        body {
            padding-right: 0; /* Remove any existing padding */
        }

        /* Enhanced scrollbar animation */
        body::-webkit-scrollbar-thumb {
            transition: all 0.3s ease;
        }

        body::-webkit-scrollbar-track {
            transition: all 0.3s ease;
        }

        /* Make scrollbar always visible even when not needed */
        body::-webkit-scrollbar-track {
            background: linear-gradient(to bottom, #f8f9fa 0%, #e9ecef 50%, #f8f9fa 100%);
        }

        /* Add subtle glow effect when scrolling */
        body::-webkit-scrollbar-thumb:hover {
            box-shadow: 0 0 20px rgba(234, 102, 102, 0.6);
        }

        /* Responsive scrollbar for smaller screens */
        @media (max-width: 768px) {
            body::-webkit-scrollbar {
                width: 12px;
            }
            
            body::-webkit-scrollbar-thumb {
                border-radius: 10px;
                min-height: 30px;
            }
            
            body::-webkit-scrollbar-track {
                border-radius: 10px;
                margin: 5px 0;
            }
        }

        @media (max-width: 480px) {
            body::-webkit-scrollbar {
                width: 8px;
            }
            
            body::-webkit-scrollbar-thumb {
                border-radius: 8px;
                min-height: 25px;
                border: 1px solid #f1f3f4;
            }
            
            body::-webkit-scrollbar-track {
                border-radius: 8px;
                margin: 3px 0;
            }
        }

        /* Add marquee animation for partner names */
        .marquee-container {
            width: 150px;
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            background: transparent;
        }
        
        .marquee-text {
            display: inline-block;
            animation: marquee 10s linear infinite;
            padding-left: 100%;
        }
        
        .marquee-text:hover {
            animation-play-state: paused;
        }
        
        @keyframes marquee {
            0% {
                transform: translateX(0%);
            }
            100% {
                transform: translateX(-100%);
            }
        }
        
        /* Only apply marquee when text is longer than container */
        .marquee-container.long-text .marquee-text {
            animation: marquee 15s linear infinite;
        }
        
        .marquee-container.short-text .marquee-text {
            animation: none;
            padding-left: 0;
        }
        
        /* Add autocomplete styling */
        .autocomplete-wrapper {
            position: relative;
        }
        
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 2px solid #e3e6f0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
        }
        
        .suggestion-item:hover,
        .suggestion-item.highlighted {
            background-color: #f8f9fc;
            color: #df4e4eff;
            transform: translateX(5px);
        }
        
        .suggestion-item .ref-highlight {
            font-weight: 600;
            color: #ea6666ff;
        }
        
        .no-suggestions {
            padding: 0.75rem 1rem;
            color: #6c757d;
            font-style: italic;
            text-align: center;
        }
        
        /* Enhanced input styling for autocomplete */
        .autocomplete-input {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m11.25 11.25 2.5 2.5m-2.5-5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }
        
        .autocomplete-input:focus {
            background-image: none;
        }
        
        .form-container {
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 200px);
            padding: 2rem 0;
        }
        
        .form-wrapper {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            border: 1px solid #e0e6ed;
            max-width: 1100px;
            width: 100%;
        }
        
        .form-title {
            text-align: center;
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 2rem;
            font-size: 1.5rem;
        }
        
        .horizontal-form-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1.2rem;
            align-items: end;
            padding: 1.5rem;
            background: #f8f9fc;
            border-radius: 15px;
            border: 1px solid #e3e6f0;
        }
        
        .form-group-inline {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #5a6c7d;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            padding: 0.75rem 1rem;
            border: 2px solid #e3e6f0;
            border-radius: 10px;
            font-size: 0.9rem;
            background-color: #ffffff;
            transition: all 0.3s ease;
            height: 45px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #df4e4eff;
            box-shadow: 0 0 0 0.2rem rgba(223, 78, 78, 0.15);
            background-color: #ffffff;
            outline: none;
            transform: translateY(-1px);
        }
        
        .form-control:hover, .form-select:hover {
            border-color: #858796;
        }
        
        .btn-clear {
            background: linear-gradient(135deg, #ea6666ff 0%, #a24b4bff 100%);
            color: #ffffff;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            height: 45px;
            min-width: 120px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-clear:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 192, 234, 0.4);
            background: linear-gradient(135deg, #a24b4bff 0%, #ea6666ff 100%);
        }
        
        .btn-clear:active {
            transform: translateY(0);
        }
        .btn-proceed {
            background: linear-gradient(135deg, #ea6666ff 0%, #a24b4bff 100%);
            color: #ffffff;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            height: 45px;
            min-width: 120px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-proceed:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 192, 234, 0.4);
            background: linear-gradient(135deg, #a24b4bff 0%, #ea6666ff 100%);
        }
        
        .btn-proceed:active {
            transform: translateY(0);
        }
        
        /* Table Styling */
        .data-table-container {
            margin-top: 2rem;
            background: #ffffff;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }
        
        .table-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
        }
        
        /* Table Actions */
        .table-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn-excel {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: #ffffff;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(40, 167, 69, 0.3);
        }
        
        .btn-excel:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(40, 167, 69, 0.4);
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
            color: #ffffff;
        }
        
        .btn-excel:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        
        .btn-excel i {
            margin-right: 0.5rem;
            font-size: 1rem;
        }
        
        /* Enhanced Table with Fixed Header and Scrollable Body */
        .table-wrapper {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        
        .custom-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            font-size: 0.9rem;
            background-color: #ffffff;
            table-layout: fixed;
        }
        
        .custom-table thead {
            background: linear-gradient(135deg, #ea6666ff 0%, #a24b4bff 100%);
            color: #ffffff;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .custom-table thead th {
            padding: 1rem 0.75rem;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
            border: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        
        /* Define exact column widths for perfect alignment */
        .custom-table th:nth-child(1) {
            width: 140px; 
            min-width: 140px; 
            max-width: 140px; 
            text-align: center;
        } /* Transact Date */
        .custom-table td:nth-child(1) { 
            width: 140px; 
            min-width: 140px; 
            max-width: 140px; 
        } /* Transact Date */
        .custom-table th:nth-child(2) {
            width: 140px; 
            min-width: 140px; 
            max-width: 140px; 
            text-align: center;
        } /* Cancellation Date */
        .custom-table td:nth-child(2) { 
            width: 140px; 
            min-width: 140px; 
            max-width: 140px; 
        } /* Cancellation Date */
        
        .custom-table th:nth-child(3),
        .custom-table td:nth-child(3) { 
            width: 130px; 
            min-width: 130px; 
            max-width: 130px; 
            text-align: center;
        } /* Reference Number */
        
        .custom-table th:nth-child(4){
            width: 140px; 
            min-width: 140px; 
            max-width: 140px;
            text-align: center;
        }
        .custom-table td:nth-child(4) { 
            width: 140px; 
            min-width: 140px; 
            max-width: 140px; 
        } /* Branch Name */
        
        .custom-table th:nth-child(5),
        .custom-table td:nth-child(5) { 
            width: 90px; 
            min-width: 90px; 
            max-width: 90px; 
            text-align: center;
        } /* BOS Code */
        
        .custom-table th:nth-child(6),
        .custom-table td:nth-child(6) { 
            width: 90px; 
            min-width: 90px; 
            max-width: 90px; 
            text-align: center;
        } /* Branch ID */
        
        .custom-table th:nth-child(7),
        .custom-table td:nth-child(7) { 
            width: 80px; 
            min-width: 80px; 
            max-width: 80px; 
            text-align: center;
        } /* Zone */
        
        .custom-table th:nth-child(8) {
            width: 100px; 
            min-width: 100px; 
            max-width: 100px;
            text-align: center;
        }
        .custom-table td:nth-child(8) { 
            width: 100px; 
            min-width: 100px; 
            max-width: 100px; 
        } /* Region */
        
        .custom-table th:nth-child(9) {
            width: 110px; 
            min-width: 110px; 
            max-width: 110px;
            text-align: center;
        }
        .custom-table td:nth-child(9) { 
            width: 110px; 
            min-width: 110px; 
            max-width: 110px; 
            text-align: right;
        } /* Principal */
        
        .custom-table th:nth-child(10) {
            width: 130px; 
            min-width: 130px; 
            max-width: 130px;
            text-align: center;
        }
        .custom-table td:nth-child(10) { 
            width: 130px; 
            min-width: 130px; 
            max-width: 130px; 
            text-align: right;
        } /* Charge to Partner */
        
        .custom-table th:nth-child(11) {
            width: 140px; 
            min-width: 140px; 
            max-width: 140px;
            text-align: center;
        }
        .custom-table td:nth-child(11) { 
            width: 140px; 
            min-width: 140px; 
            max-width: 140px; 
            text-align: right;
        } /* Charge to Customer */
        
        .custom-table th:nth-child(12){
            width: 150px; 
            min-width: 150px; 
            max-width: 150px;
            text-align: center;
        }
        .custom-table td:nth-child(12) { 
            width: 150px; 
            min-width: 150px; 
            max-width: 150px; 
            text-align: center;
        } /* Partner */
        
        .custom-table th:nth-child(13){
            width: 100px; 
            min-width: 100px; 
            max-width: 100px;
            text-align: center;
        }
        .custom-table td:nth-child(13) { 
            width: 100px; 
            min-width: 100px; 
            max-width: 100px; 
            text-align: center;
        } /* Status */
        
        .custom-table tbody {
            display: block;
            max-height: 400px;
            overflow-y: scroll;
            overflow-x: hidden;
            width: 100%;
        }
        
        .custom-table thead,
        .custom-table tbody tr {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        
        .custom-table tbody tr {
            border-bottom: 1px solid #e9ecef;
            transition: all 0.3s ease;
            height: 50px;
        }
        
        .custom-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .custom-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .custom-table tbody td {
            padding: 0.75rem;
            vertical-align: middle;
            border: none;
            color: #495057;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .custom-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .custom-table tbody tr:nth-child(even):hover {
            background-color: #e9ecef;
        }
        
        /* Currency formatting */
        .custom-table tbody td:nth-child(9),
        .custom-table tbody td:nth-child(10),
        .custom-table tbody td:nth-child(11) {
            padding-right: 1rem;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .custom-table tbody td:nth-child(9) .fas.fa-peso-sign,
        .custom-table tbody td:nth-child(10) .fas.fa-peso-sign,
        .custom-table tbody td:nth-child(11) .fas.fa-peso-sign {
            margin-right: 0.25rem;
            color: #28a745;
            font-size: 0.85rem;
        }
        
        /* Text truncation with tooltip */
        .text-truncate-custom {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
            position: relative;
        }
        
        .text-truncate-custom.partner-name {
            max-width: 150px;
        }
        
        .text-truncate-custom.branch-name {
            max-width: 140px;
        }
        
        .text-truncate-custom.reference-number {
            max-width: 130px;
        }
        
        .custom-tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: #ffffff;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 8px;
            max-width: 300px;
            word-wrap: break-word;
            white-space: normal;
        }
        
        .custom-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #2c3e50;
        }
        
        .text-truncate-custom:hover .custom-tooltip {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(-5px);
        }
        
        .text-truncate-custom:hover {
            background-color: rgba(102, 126, 234, 0.1);
            border-radius: 4px;
            color: #667eea;
            font-weight: 600;
        }
        
        /* Custom Scrollbar */
        .custom-table tbody::-webkit-scrollbar {
            width: 14px;
        }
        
        .custom-table tbody::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
            margin: 5px 0;
            border: 1px solid #e0e0e0;
        }
        
        .custom-table tbody::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #ea6666ff 0%, #a24b4bff 100%);
            border-radius: 10px;
            border: 2px solid #f1f1f1;
            min-height: 30px;
        }
        
        .custom-table tbody::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #a24b4bff 0%, #ea6666ff 100%);
            border: 2px solid #e9ecef;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .custom-table tbody::-webkit-scrollbar-thumb:active {
            background: linear-gradient(135deg, #d85a5aff 0%, #c14646ff 100%);
        }
        
        .custom-table tbody {
            scrollbar-width: auto;
            scrollbar-color: #ea6666ff #f1f1f1;
            -ms-overflow-style: scrollbar;
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-unposted {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-posted {
            background-color: #96d889ff;
            color: #3a7e45ff;
        }
        
        .status-completed {
            background-color: #d1edff;
            color: #0c5460;
        }
        
        .status-failed {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .main-content {
            padding: 20px;
        }
        
        /* Error message styling */
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 10px;
            margin: 1rem 0;
            border: 1px solid #f5c6cb;
        }
        
        @media (max-width: 992px) {
            .horizontal-form-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }
            
            .custom-table {
                font-size: 0.8rem;
            }
            
            .custom-table thead th, .custom-table tbody td {
                padding: 0.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .form-wrapper {
                margin: 1rem;
                padding: 2rem;
            }
            
            .horizontal-form-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 1rem;
            }
            
            .btn-proceed {
                width: 100%;
                margin-top: 1rem;
            }
            
            .data-table-container {
                padding: 1rem;
                overflow-x: auto;
            }
            
            .table-wrapper {
                min-width: 800px;
            }
        }

        /* Legend styling - add this to your existing CSS */
        .legend-container {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: #f8f9fc;
            border-radius: 10px;
            border: 1px solid #e3e6f0;
        }

        .legend-title {
            font-weight: 600;
            color: #495057;
            margin: 0;
            font-size: 0.9rem;
        }

        .legend-items {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 500;
        }

        .legend-icon {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
        }

        .legend-icon.cancelled {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .legend-icon.success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }

        .legend-icon.pending {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
        }

        .legend-icon.failed {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        /* Update table-actions to accommodate legend and total amount alignment */
        .table-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Style for total amount container - align to right */
        .total-amount-container {
            margin-left: auto;
            background: #e8f5e8;
            border: 1px solid #c3e6cb;
        }

        .total-amount-container .legend-title {
            color: #155724;
            font-weight: 700;
        }

        .total-amount-container .legend-item {
            color: #155724;
            font-weight: 600;
        }

        .total-amount-container .fas.fa-peso-sign {
            color: #28a745;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .table-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .total-amount-container {
                margin-left: 0;
                margin-top: 1rem;
            }
            
            .legend-container {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .legend-items {
                gap: 1rem;
            }
        }

        /* Table Header Section - Add this to your existing CSS */
        .table-header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #ff0000ff;
        }

        .table-title {
            color: #495057;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            flex: 1;
        }

        .table-title .badge {
            margin-left: 0.5rem;
        }

        /* Update the existing btn-excel to work in the header */
        .table-header-section .btn-excel {
            background: linear-gradient(135deg, #a72828ff 0%, #c92020ff 100%);
            color: #ffffff;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(40, 167, 69, 0.3);
            margin-left: auto;
        }

        .table-header-section .btn-excel:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(40, 167, 69, 0.4);
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
            color: #ffffff;
        }

        .table-header-section .btn-excel:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }

        .table-header-section .btn-excel i {
            margin-right: 0.5rem;
            font-size: 1rem;
        }

        /* Update table-actions to only handle legend */
        .table-actions {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .table-header-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .table-header-section .btn-excel {
                width: 100%;
                margin-left: 0;
            }
            
            .table-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .legend-container {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .legend-items {
                gap: 1rem;
            }
        }

        /* Add styling for cancelled rows */
        .custom-table tbody tr.legend-icon.cancelled {
            background-color: #f8d7da !important;
            border-left: 4px solid #dc3545;
        }

        .custom-table tbody tr.legend-icon.cancelled:hover {
            background-color: #f5c6cb !important;
        }

        .custom-table tbody tr.legend-icon.cancelled td {
            color: #721c24;
        }
    </style>
</head>

<body>
    <div>
        <div class="top-content">
            <div class="usernav">
                <h4 style="margin-right: 0.5rem; font-size: 1rem;"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? ''); ?></h4>
                <h5 style="font-size: 1rem;"><?php echo "- " . htmlspecialchars($_SESSION['admin_email'] ?? ''); ?></h5>
            </div>
            <?php include '../templates/admin/sidebar.php'; ?>
        </div>
    </div>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-12">
                    <h2 class="text-danger"><i class="fas fa-file-import me-2"></i>Bills Payment Cancellation</h2>
                </div>
            </div>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="form-container">
                <form action="" method="post">
                    <div class="horizontal-form-container">
                        <!-- Reference Number Autocomplete Search -->
                        <div class="form-group-inline">
                            <label for="ref_number" class="form-label" style="font-size: 0.8rem;">Reference Number [KP7 / KPX]</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="form-control autocomplete-input" id="ref_number" name="ref_number" 
                                    value="<?php echo htmlspecialchars($_POST['ref_number'] ?? ''); ?>" 
                                    placeholder="Type to search reference number..." autocomplete="off">
                                <div id="ref_suggestions" class="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        
                        <!-- Branch Autocomplete Search -->
                        <div class="form-group-inline">
                            <label for="branch_field" class="form-label" style="font-size: 0.8rem;">Branch [Name / ID / BOS Code]</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="form-control autocomplete-input" id="branch_field" name="branch_field" 
                                    value="<?php echo htmlspecialchars($_POST['branch_field'] ?? ''); ?>" 
                                    placeholder="Type to search branch..." autocomplete="off">
                                <div id="branch_suggestions" class="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        
                        <!-- Region Autocomplete Search -->
                        <div class="form-group-inline">
                            <label for="region_field" class="form-label">Region</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="form-control autocomplete-input" id="region_field" name="region_field" 
                                    value="<?php echo htmlspecialchars($_POST['region_field'] ?? ''); ?>" 
                                    placeholder="Type to search region..." autocomplete="off">
                                <div id="region_suggestions" class="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        
                        <!-- Zone Autocomplete Search -->
                        <div class="form-group-inline">
                            <label for="zone_field" class="form-label">Zone</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="form-control autocomplete-input" id="zone_field" name="zone_field" 
                                    value="<?php echo htmlspecialchars($_POST['zone_field'] ?? ''); ?>" 
                                    placeholder="Type to search zone..." autocomplete="off">
                                <div id="zone_suggestions" class="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        
                        <!-- Partner Autocomplete Search -->
                        <div class="form-group-inline">
                            <label for="partner_field" class="form-label">Partner [ID / Name]</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="form-control autocomplete-input" id="partner_field" name="partner_field" 
                                    value="<?php echo htmlspecialchars($_POST['partner_field'] ?? ''); ?>" 
                                    placeholder="Type to search partner..." autocomplete="off">
                                <div id="partner_suggestions" class="autocomplete-suggestions"></div>
                            </div>
                        </div>
                        
                        <!-- Date Start -->
                        <div class="form-group-inline">
                            <label for="date_start" class="form-label">Date Start</label>
                            <div class="autocomplete-wrapper">
                                <input type="date" class="form-control" id="date_start" name="date_start" 
                                    value="<?php echo htmlspecialchars($_POST['date_start'] ?? ''); ?>" autocomplete="off">
                            </div>
                        </div>
                        
                        <!-- Date End -->
                        <div class="form-group-inline">
                            <label for="date_end" class="form-label">Date End</label>
                            <div class="autocomplete-wrapper">
                                <input type="date" class="form-control" id="date_end" name="date_end" 
                                    value="<?php echo htmlspecialchars($_POST['date_end'] ?? ''); ?>" autocomplete="off">
                            </div>
                        </div>
                        
                        <!-- Search Button -->
                        <div class="form-group-inline">
                            <?php if (isset($_POST['search_submit'])): ?>
                                <button type="button" class="btn btn-clear" onclick="clearForm()">
                                    <i class="fas fa-circle-xmark me-2"></i>CLEAR
                                </button>
                            <?php endif; ?>
                            <button type="submit" name="search_submit" class="btn btn-proceed">
                                <i class="fas fa-search me-2"></i>SEARCH
                            </button>
                        </div>
                    </div>
                </form>
                
                <?php if (isset($_POST['search_submit'])): ?>
                    <div class="data-table-container">
                        <div class="table-header-section">
                            <h5 class="table-title">
                                <i class="fas fa-table me-2"></i>Bills Payment Records 
                                <span class="badge bg-danger ms-2"><?php $count = count($search_data);
                                    echo number_format($count) . ' ' . ($count == 1 ? 'Result' : 'Results'); ?></span>
                            </h5>
                            <?php if (count($search_data) > 0): ?>
                            <button type="button" class="btn-excel" name="export_to_excel" onclick="exportToExcel()">
                                <i class="fas fa-file-excel"></i>Export to Excel
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="table-actions">
                            <div class="legend-container">
                                <h6 class="legend-title">Legend:</h6>
                                <div class="legend-items">
                                    <div class="legend-item">
                                        <span class="legend-icon cancelled"></span>
                                        <span>Cancelled</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-icon success"></span>
                                        <span>Posted</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-icon pending"></span>
                                        <span>Unposted</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="legend-container total-amount-container">
                                <h6 class="legend-title">Total Amount:</h6>
                                <div class="legend-items">
                                    <div class="legend-item">
                                        <span>Principal:</span>
                                        <span><i class="fas fa-peso-sign me-1"></i><?php 
                                            $totalPrincipal = 0;
                                            foreach($search_data as $row) {
                                                $totalPrincipal += floatval($row['amount_paid'] ?? 0);
                                            }
                                            echo number_format($totalPrincipal, 2);
                                        ?></span>
                                    </div>
                                    <div class="legend-item">
                                        <span>Charge to Partner:</span>
                                        <span><i class="fas fa-peso-sign me-1"></i><?php 
                                            $totalPartner = 0;
                                            foreach($search_data as $row) {
                                                $totalPartner += floatval($row['charge_to_partner'] ?? 0);
                                            }
                                            echo number_format($totalPartner, 2);
                                        ?></span>
                                    </div>
                                    <div class="legend-item">
                                        <span>Charge to Customer:</span>
                                        <span><i class="fas fa-peso-sign me-1"></i><?php 
                                            $totalCustomer = 0;
                                            foreach($search_data as $row) {
                                                $totalCustomer += floatval($row['charge_to_customer'] ?? 0);
                                            }
                                            echo number_format($totalCustomer, 2);
                                        ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <div class="table-wrapper">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-calendar me-1"></i>Transact Date</th>
                                            <th><i class="fas fa-calendar me-1"></i>Cancellation Date</th>
                                            <th><i class="fas fa-hashtag me-1"></i>Reference Number</th>
                                            <th><i class="fas fa-building me-1"></i>Branch Name</th>
                                            <th><i class="fas fa-house me-1"></i>BOS Code</th>
                                            <th><i class="fas fa-house me-1"></i>Branch ID</th>
                                            <th><i class="fas fa-globe me-1"></i>Zone</th>
                                            <th><i class="fas fa-globe me-1"></i>Region</th>
                                            <th><i class="fas fa-peso-sign me-1"></i>Principal</th>
                                            <th><i class="fas fa-peso-sign me-1"></i>Charge to Partner</th>
                                            <th><i class="fas fa-peso-sign me-1"></i>Charge to Customer</th>
                                            <th><i class="fas fa-building me-1"></i>Partner</th>
                                            <th><i class="fas fa-info-circle me-1"></i>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="table-body-wrapper">
                                        <?php if (count($search_data) > 0): ?>
                                            <?php foreach($search_data as $row): ?>
                                                <tr <?php echo (($row['status'] ?? '') == '*') ? ' class="legend-icon cancelled"' : ''; ?>>
                                                    <td>
                                                        <div class="text-truncate-custom">
                                                            <strong><?php echo !empty($row['datetime']) ? date('Y-m-d H:i:s', strtotime($row['datetime'])) : ''; ?></strong>
                                                            <div class="custom-tooltip"><?php echo !empty($row['datetime']) ? date('Y-m-d H:i:s', strtotime($row['datetime'])) : ''; ?></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-truncate-custom">
                                                            <strong><?php echo !empty($row['cancellation_date']) ? date('Y-m-d H:i:s', strtotime($row['cancellation_date'])) : ''; ?></strong>
                                                            <div class="custom-tooltip"><?php echo !empty($row['cancellation_date']) ? date('Y-m-d H:i:s', strtotime($row['cancellation_date'])) : ''; ?></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-truncate-custom reference-number">
                                                            <strong><?php echo htmlspecialchars($row['reference_no'] ?? ''); ?></strong>
                                                            <div class="custom-tooltip"><?php echo htmlspecialchars($row['reference_no'] ?? ''); ?></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                            $outletName = htmlspecialchars($row['outlet'] ?? '');
                                                            $isLongText = strlen($outletName) > 20;
                                                        ?>
                                                        <div class="marquee-container <?php echo $isLongText ? 'long-text' : 'short-text'; ?>">
                                                            <div class="marquee-text"><?php echo $outletName; ?></div>
                                                        </div>
                                                    </td>
                                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['branch_code'] ?? ''); ?></span></td>
                                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['branch_id'] ?? ''); ?></span></td>
                                                    <td><?php echo htmlspecialchars($row['zone_code'] ?? ''); ?></td>
                                                    <td>
                                                        <?php 
                                                            $regionName = htmlspecialchars($row['region'] ?? '');
                                                            $isLongText = strlen($regionName) > 15;
                                                        ?>
                                                        <div class="marquee-container <?php echo $isLongText ? 'long-text' : 'short-text'; ?>">
                                                            <div class="marquee-text"><?php echo $regionName; ?></div>
                                                        </div>
                                                    </td>
                                                    <td><i class="fas fa-peso-sign me-1"></i><?php echo number_format(floatval($row['amount_paid'] ?? 0), 2); ?></td>
                                                    <td><i class="fas fa-peso-sign me-1"></i><?php echo number_format(floatval($row['charge_to_partner'] ?? 0), 2); ?></td>
                                                    <td><i class="fas fa-peso-sign me-1"></i><?php echo number_format(floatval($row['charge_to_customer'] ?? 0), 2); ?></td>
                                                    <td>
                                                        <?php 
                                                            $partnerName = htmlspecialchars($row['partner_name'] ?? '');
                                                            $isLongText = strlen($partnerName) > 20;
                                                        ?>
                                                        <div class="marquee-container <?php echo $isLongText ? 'long-text' : 'short-text'; ?>">
                                                            <div class="marquee-text"><?php echo $partnerName; ?></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $status = $row['post_transaction'] ?? '';
                                                        $status_class = 'status-' . strtolower($status);
                                                        ?>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo ucfirst($status); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="13" class="text-center text-muted">
                                                    <i class="fas fa-search me-2"></i>No records found matching your search criteria.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <?php if (count($search_data) > 8): ?>
                                    <div class="row-counter">
                                        Showing <?php echo number_format(count($search_data)); ?> records
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    
    <script>
        // Autocomplete data from PHP with error handling
        const autocompleteData = {
            ref_number: <?php echo json_encode($ref_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            branch_field: <?php echo json_encode(array_values($branch_options), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            region_field: <?php echo json_encode($region_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            zone_field: <?php echo json_encode($zone_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            partner_field: <?php echo json_encode(array_values($partner_options), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
        };
        
        // Initialize marquee effect based on text length
        $(document).ready(function() {
            $('.marquee-container').each(function() {
                const container = $(this);
                const text = container.find('.marquee-text');
                const containerWidth = container.width();
                const textWidth = text[0].scrollWidth;
                
                if (textWidth > containerWidth) {
                    container.removeClass('short-text').addClass('long-text');
                } else {
                    container.removeClass('long-text').addClass('short-text');
                }
            });
        });
        
        // Generic autocomplete functionality with error handling
        function initializeAutocomplete(inputId, suggestionId, dataKey) {
            const $input = $(`#${inputId}`);
            const $suggestions = $(`#${suggestionId}`);
            const data = autocompleteData[dataKey] || [];
            let currentHighlight = -1;
            
            $input.on('input', function() {
                const query = $(this).val().toLowerCase().trim();
                
                if (query.length === 0) {
                    hideSuggestions();
                    return;
                }
                
                try {
                    const filtered = data.filter(item => 
                        item && item.toString().toLowerCase().includes(query)
                    ).slice(0, 10);
                    
                    showSuggestions(filtered, query);
                } catch (error) {
                    console.error('Autocomplete error:', error);
                    hideSuggestions();
                }
            });
            
            $input.on('keydown', function(e) {
                const $items = $suggestions.find('.suggestion-item');
                
                switch(e.keyCode) {
                    case 40: // Arrow Down
                        e.preventDefault();
                        currentHighlight = Math.min(currentHighlight + 1, $items.length - 1);
                        updateHighlight($items);
                        break;
                        
                    case 38: // Arrow Up
                        e.preventDefault();
                        currentHighlight = Math.max(currentHighlight - 1, -1);
                        updateHighlight($items);
                        break;
                        
                    case 13: // Enter
                        if (currentHighlight >= 0 && $items.length > 0) {
                            e.preventDefault();
                            selectSuggestion($items.eq(currentHighlight).text());
                        }
                        break;
                        
                    case 27: // Escape
                        hideSuggestions();
                        break;
                }
            });
            
            $(document).on('click', function(e) {
                if (!$(e.target).closest($input.parent()).length) {
                    hideSuggestions();
                }
            });
            
            function showSuggestions(suggestions, query) {
                currentHighlight = -1;
                
                if (suggestions.length === 0) {
                    $suggestions.html(`<div class="no-suggestions">No ${dataKey.replace('_', ' ')} found</div>`).show();
                    return;
                }
                
                let html = '';
                suggestions.forEach(item => {
                    const highlighted = highlightMatch(item.toString(), query);
                    html += `<div class="suggestion-item" data-value="${item}">${highlighted}</div>`;
                });
                
                $suggestions.html(html).show();
                
                $suggestions.find('.suggestion-item').on('click', function() {
                    selectSuggestion($(this).data('value'));
               
                });
            }
            
            function hideSuggestions() {
                $suggestions.hide();
                currentHighlight = -1;
            }
            
            function updateHighlight($items) {
                $items.removeClass('highlighted');
                if (currentHighlight >= 0) {
                    $items.eq(currentHighlight).addClass('highlighted');
                }
            }
            
            function selectSuggestion(value) {
                $input.val(value);
                hideSuggestions();
                $input.focus();
            }
            
            function highlightMatch(text, query) {
                const index = text.toLowerCase().indexOf(query.toLowerCase());
                if (index === -1) return text;
                
                const before = text.substring(0, index);
                const match = text.substring(index, index + query.length);
                const after = text.substring(index + query.length);
                
                return `${before}<span class="ref-highlight">${match}</span>${after}`;
            }
        }
        
        // Initialize all autocomplete fields
        $(document).ready(function() {
            try {
                initializeAutocomplete('ref_number', 'ref_suggestions', 'ref_number');
                initializeAutocomplete('branch_field', 'branch_suggestions', 'branch_field');
                initializeAutocomplete('region_field', 'region_suggestions', 'region_field');
                initializeAutocomplete('zone_field', 'zone_suggestions', 'zone_field');
                initializeAutocomplete('partner_field', 'partner_suggestions', 'partner_field');
            } catch (error) {
                console.error('Failed to initialize autocomplete:', error);
            }
        });
        
        // Export to Excel function - Updated for large datasets
        function exportToExcel() {
            // Check if there are results to export
            const resultsCount = <?php echo isset($search_data) ? count($search_data) : 0; ?>;
            
            if (resultsCount === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data to Export',
                    text: 'Please perform a search first to generate data for export.',
                    confirmButtonColor: '#ea6666ff'
                });
                return;
            }
            
            // Show loading modal with progress indication
            Swal.fire({
                title: 'Generating Excel File...',
                html: `
                    <div style="margin: 20px 0;">
                        <div class="progress" style="height: 20px; background-color: #f8f9fa;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                style="width: 0%; background-color: #ea6666ff; transition: width 0.3s ease;"></div>
                        </div>
                        <p style="margin-top: 15px; color: #6c757d;">
                            Processing ${resultsCount.toLocaleString()} records...<br>
                            <small>This may take a few moments for large datasets.</small>
                        </p>
                    </div>
                `,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    // Simulate progress
                    let progress = 0;
                    const progressBar = document.querySelector('.progress-bar');
                    const interval = setInterval(() => {
                        progress += Math.random() * 15;
                        if (progress > 90) progress = 90;
                        progressBar.style.width = progress + '%';
                    }, 200);
                    
                    // Store interval for cleanup
                    Swal.getPopup().progressInterval = interval;
                }
            });
            
            // Create a hidden form to submit the export request
            const form = $('<form>', {
                method: 'POST',
                action: window.location.href
            });
            
            // Add all current search parameters to maintain the same data
            $('input[type="text"], input[type="date"]').each(function() {
                if ($(this).val()) {
                    form.append($('<input>', {
                        type: 'hidden',
                        name: $(this).attr('name'),
                        value: $(this).val()
                    }));
                }
            });
            
            // Add the export trigger
            form.append($('<input>', {
                type: 'hidden',
                name: 'export_to_excel',
                value: '1'
            }));
            
            // Add search submit to ensure we get the same data
            form.append($('<input>', {
                type: 'hidden',
                name: 'search_submit',
                value: '1'
            }));
            
            // Append form to body and submit
            $('body').append(form);
            form.submit();
            
            // Clean up progress and close modal after delay
            setTimeout(() => {
                const popup = Swal.getPopup();
                if (popup && popup.progressInterval) {
                    clearInterval(popup.progressInterval);
                }
                
                // Update progress to 100%
                const progressBar = document.querySelector('.progress-bar');
                if (progressBar) {
                    progressBar.style.width = '100%';
                }
                
                setTimeout(() => {
                    Swal.close();
                }, 500);
            }, 2000);
        }

        // Add this to your existing script section
        function clearForm() {
            // Clear all text inputs and date inputs
            $('input[type="text"], input[type="date"]').val('');
            
            // Hide any open autocomplete suggestions
            $('.autocomplete-suggestions').hide();
            
            // Clear the search results table if it exists
            $('.data-table-container').remove();
            
            // Optional: Show confirmation
            Swal.fire({
                icon: 'success',
                title: 'Form Cleared',
                text: 'All search filters have been cleared.',
                timer: 1500,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        }
    </script>
</body>
</html>