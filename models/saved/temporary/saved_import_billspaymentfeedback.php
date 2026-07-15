<?php 
session_start();
include '../../config/config.php';
require '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

function formatCurrency($amount) {
    return '₱ ' . number_format((float)$amount, 2);
}

/* new helper: parse YYYYMMDD -> YYYY-MM-DD (returns '' on invalid) */ 
function formatDateFromYYYYMMDD($raw) {
    $digits = preg_replace('/\D/', '', (string)$raw);
    if (strlen($digits) !== 8) return '';
    $y = substr($digits, 0, 4);
    $m = substr($digits, 4, 2);
    $d = substr($digits, 6, 2);
    if (!checkdate((int)$m, (int)$d, (int)$y)) return '';
    return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
}

// function checkDuplicateData($conn,$account_no, $date, $reference_number, $partner_id){
//     $duplicateData = false;
//     $sql = "SELECT COUNT(*) as count FROM mldb.billspayment_feedback WHERE account_no = ? AND date = ? AND reference_no = ? AND kp7partner_id = ? LIMIT 1";
//     $stmt = $conn->prepare($sql);
//     $stmt->bind_param("ssss", $reference_number, $date, $reference_number, $partner_id);
//     $stmt->execute();
//     $result = $stmt->get_result();
//     if ($result) {
//             $row = $result->fetch_assoc();
//             if ($row && $row['count'] > 0) {
//                 $duplicateData = true;
//             }
//         }
//     $stmt->close();
//     return $duplicateData;
// }

// function FeedbacknotfoundatExcel($conn, $account_no, $date, $reference_number, $partner_id){
//     $NotfoundData = false;
//     $sql = "SELECT COUNT(*) as count FROM mldb.billspayment_transaction WHERE account_no = ? AND reference_no = ? AND (DATE(datetime) = ? OR DATE(cancellation_date) = ?) AND partner_id = ? LIMIT 1";
//     $stmt = $conn->prepare($sql);
//     $stmt->bind_param("ssssss", $account_no, $reference_number, $date, $date, $partner_id);
//     $stmt->execute();
//     $result = $stmt->get_result();
//     if ($result) {
//             $row = $result->fetch_assoc();
//             if ($row && $row['count'] > 0) {
//                 $NotfoundData = true;
//             }
//         }
//     $stmt->close();
//     return $NotfoundData;
// }

if (isset($_POST['upload'])) {
    // determine uploaded file field (form uses name="anyFile")
    $file_field = null;
    if (isset($_FILES['import_file'])) {
        $file_field = 'import_file';
    } elseif (isset($_FILES['anyFile'])) {
        $file_field = 'anyFile';
    }

    if ($file_field && isset($_FILES[$file_field]) && $_FILES[$file_field]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$file_field]['tmp_name'];
        $file_name = $_FILES[$file_field]['name'];
        $file_name_array = explode('.', $file_name);
        $extension = strtolower(end($file_name_array));

        $partner = $_POST['option1'] ?? '';

        $rawfeedbackdata = [];
        $feedbackdatanotfound = [];

        $allowed_extension = array('mcl'); // Allowed file extensions

        if (in_array($extension, $allowed_extension)) {
            if (is_readable($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                // Prepare partner name lookup once
                $partner_id = $partner;
                $partner_name = null;
                if (!empty($partner_id)) {
                    $getpartnernameQuery = "SELECT partner_name FROM masterdata.partner_masterfile WHERE partner_id = ? LIMIT 1";
                    if ($stmt = $conn2->prepare($getpartnernameQuery)) {
                        $stmt->bind_param("s", $partner_id);
                        $stmt->execute();
                        $getpartnernameResult = $stmt->get_result();
                        if ($getpartnernameResult && $getpartnernameResult->num_rows > 0) {
                            $partner_row = $getpartnernameResult->fetch_assoc();
                            $partner_name = htmlspecialchars($partner_row['partner_name']);
                        }
                        $stmt->close();
                    }
                }

                // Summary counters
                $rows_imported = 0;
                $matched_count = 0;
                $unmatched_count = 0;
                $matched_total_principal = 0.0;
                $matched_total_amount_paid = 0.0;
                $unmatched_total_principal = 0.0;

                // initialize transaction placeholders so they're always defined for each row
                $bt_reference_no = '';
                $bt_account_no = '';
                $bt_amount_paid = '';

                foreach ($lines as $line) {
                    $rows_imported++;

                    // Parse fixed-width positions (trim to remove padding)
                    $source_file = $extension;
                    $account_no = htmlspecialchars(trim(substr($line, 0, 20)));
                    $lastname = htmlspecialchars(trim(substr($line, 20, 30)));
                    $firstname = htmlspecialchars(trim(substr($line, 50, 30)));
                    $middlename = htmlspecialchars(trim(substr($line, 80, 30)));
                    $loan_type = htmlspecialchars(trim(substr($line, 110, 15)));
                    $principal_amount_raw = trim(substr($line, 125, 13));

                    // normalize principal to numeric (remove non-numeric)
                    $principal_amount = floatval(preg_replace('/[^0-9\.\-]/', '', $principal_amount_raw));

                    // Determine offsets depending on presence of special char in names
                    if (strpos($lastname, 'Ñ') !== false || strpos($firstname, 'Ñ') !== false || strpos($middlename, 'Ñ') !== false) {
                        $rawdate = htmlspecialchars(trim(substr($line, 139, 8)));
                        $date = formatDateFromYYYYMMDD($rawdate);
                        $timestamp = htmlspecialchars(trim(substr($line, 147, 15)));
                        $reference_number = htmlspecialchars(trim(substr($line, 162, 11)));
                        $additional_ref_code = htmlspecialchars(trim(substr($line, 173, 19)));
                        $phone_number = htmlspecialchars(trim(substr($line, 192, 20)));
                        $status1 = htmlspecialchars(trim(substr($line, 212, 1)));
                        $branch_name = htmlspecialchars(trim(substr($line, 213, 20)));
                        $status2 = htmlspecialchars(trim(substr($line, 233, 11)));
                    } else {
                        $rawdate = htmlspecialchars(trim(substr($line, 138, 8)));
                        $date = formatDateFromYYYYMMDD($rawdate);
                        $timestamp = htmlspecialchars(trim(substr($line, 146, 15)));

                        if (substr($line, 161, 3) === 'BPX') {
                            $reference_number = htmlspecialchars(trim(substr($line, 161, 30)));
                            $additional_ref_code = htmlspecialchars(trim(substr($line, 191, 1)));
                        } else {
                            $reference_number = htmlspecialchars(trim(substr($line, 161, 11)));
                            $additional_ref_code = htmlspecialchars(trim(substr($line, 172, 19)));
                        }

                        $phone_number = htmlspecialchars(trim(substr($line, 191, 20)));
                        $status1 = htmlspecialchars(trim(substr($line, 211, 1)));
                        $branch_name = htmlspecialchars(trim(substr($line, 212, 20)));
                        $status2 = htmlspecialchars(trim(substr($line, 232, 11)));
                    }

                    // Lookup branch/transaction to mark matched/unmatched
                    $branch_id = null;
                    $zone_code = null;
                    $region_code = null;
                    $mlmatic_region_name = null;
                    $is_matched = false;
                    $bt_branch_id = null;
                    $bt_zone_code = null;
                    $bt_region_code = null;
                    $bt_mlmatic_region_name = null;
                    $region_name = null;

                    $getbranchQuery = "SELECT bt.reference_no, bt.account_no, bt.amount_paid, bt.branch_id, bt.zone_code, bt.region_code FROM mldb.billspayment_transaction AS bt 
                                        WHERE bt.account_no = ? AND bt.reference_no = ? AND partner_id = ? AND (DATE(bt.datetime) = ? OR DATE(bt.cancellation_date) = ?) LIMIT 1";
                    if ($stmt = $conn->prepare($getbranchQuery)) {
                        $stmt->bind_param("sssss", $account_no, $reference_number, $partner_id, $date, $date);
                        $stmt->execute();
                        $getbranchResult = $stmt->get_result();
                        if ($getbranchResult && $getbranchResult->num_rows > 0) {
                            $branch_row = $getbranchResult->fetch_assoc();

                            $bt_reference_no = htmlspecialchars($branch_row['reference_no']);
                            $bt_account_no = htmlspecialchars($branch_row['account_no']);
                            $bt_amount_paid = htmlspecialchars($branch_row['amount_paid']);

                            $branch_id = htmlspecialchars($branch_row['branch_id']);
                            $zone_code = htmlspecialchars($branch_row['zone_code']);
                            $region_code = htmlspecialchars($branch_row['region_code']);
                            $is_matched = true;
                        }
                        $stmt->close();
                    }

                    if ($branch_id) {
                        $getmlmaticregionQuery = "SELECT mbp.region, mbp.ml_matic_region FROM masterdata.branch_profile AS mbp
                                                    WHERE mbp.branch_id = ? LIMIT 1";
                        if ($stmt = $conn2->prepare($getmlmaticregionQuery)) {
                            $stmt->bind_param("s", $branch_id);
                            $stmt->execute();
                            $getmlmaticregionResult = $stmt->get_result();
                            if ($getmlmaticregionResult && $getmlmaticregionResult->num_rows > 0) {
                                $mlmaticregion_row = $getmlmaticregionResult->fetch_assoc();
                                $mlmatic_region_name = htmlspecialchars($mlmaticregion_row['ml_matic_region']);
                                $region_name = htmlspecialchars($mlmaticregion_row['region']);
                            }
                            $stmt->close();
                        }
                    }

                    // Accumulate matched/unmatched counts and totals
                    if ($is_matched) {
                        $matched_count++;
                        $matched_total_principal += $principal_amount;
                        $matched_total_amount_paid += $bt_amount_paid;
                    } else {
                        $unmatched_count++;
                        $unmatched_total_principal += $principal_amount;
                    }
                    $rawfeedbackdata[] = [
                        'source_file' => $source_file,
                        'account_number' => $account_no,
                        'lastname' => $lastname,
                        'firstname' => $firstname,
                        'middlename' => $middlename,
                        'loan_type' => $loan_type,
                        'principal_amount' => $principal_amount,
                        'date' => $date,
                        'timestamp' => $timestamp,
                        'reference_number' => $reference_number,
                        'additional_ref_code' => $additional_ref_code,
                        'phone_number' => $phone_number,
                        'status1' => $status1,
                        'branch_name' => $branch_name,
                        'status2' => $status2,
                        'partner_name' => $partner_name,
                        'partner_id' => $partner_id,
                        'branch_id' => $branch_id,
                        'zone_code' => $zone_code,
                        'region_code' => $region_code,
                        'region_name' => $region_name,
                        'mlmatic_region_name' => $mlmatic_region_name,

                        'bt_reference_no' => $bt_reference_no,
                        'bt_account_no' => $bt_account_no,
                        'bt_amount_paid' => $bt_amount_paid,

                        'is_matched' => $is_matched
                    ];
                } // end foreach lines

                // Save parsed rows and summary to session
                $_SESSION['matched_feedback_data'] = $rawfeedbackdata;
                $_SESSION['matched_feedback_summary'] = [
                    'rows_imported' => $rows_imported,
                    'matched_count' => $matched_count,
                    'unmatched_count' => $unmatched_count,
                    'matched_total_principal' => $matched_total_principal,
                    'matched_total_amount_paid' => $matched_total_amount_paid,
                    'unmatched_total_principal' => $unmatched_total_principal,
                    'partner_id' => $partner_id,
                    'partner_name' => $partner_name,
                    'source_file' => $extension,

                    'import_date' => date('F d, Y')
                ];

                // --- new: find unmatched Bills Pay transactions (transactions without corresponding feedback) ---
                $unmatched_transactions = [];
                if (!empty($partner_id) && !empty($rawfeedbackdata)) {
                    // build a set of matched transaction keys (account|reference|date)
                    $matchedKeys = [];
                    foreach ($rawfeedbackdata as $r) {
                        if (!empty($r['bt_reference_no']) || !empty($r['bt_account_no'])) {
                            $k = ($r['bt_account_no'] ?? '') . '|' . ($r['bt_reference_no'] ?? '') . '|' . ($r['date'] ?? '');
                            $matchedKeys[$k] = true;
                        }
                    }

                    // collect unique dates from parsed feedback to limit queries
                    $dates = array_values(array_unique(array_filter(array_column($rawfeedbackdata, 'date'))));

                    if (!empty($dates)) {
                        // prepare statement to fetch transactions for each date (includes cancellation_date)
                        $sqlTrx = "SELECT reference_no, account_no, amount_paid, partner_id, branch_id, DATE(datetime) AS trx_date, DATE(cancellation_date) AS cancel_date
                                FROM mldb.billspayment_transaction
                                WHERE partner_id = ? AND (DATE(datetime) = ? OR DATE(cancellation_date) = ?)";

                        if ($stmtTrx = $conn->prepare($sqlTrx)) {
                            foreach ($dates as $d) {
                                $stmtTrx->bind_param('sss', $partner_id, $d, $d);
                                $stmtTrx->execute();
                                $res = $stmtTrx->get_result();
                                if ($res) {
                                    while ($rowTrx = $res->fetch_assoc()) {
                                        $acct = htmlspecialchars($rowTrx['account_no']);
                                        $ref = htmlspecialchars($rowTrx['reference_no']);
                                        $trxDate = $rowTrx['trx_date'] ?: $rowTrx['cancel_date'];
                                        $key = $acct . '|' . $ref . '|' . $trxDate;

                                        // if this transaction was NOT matched to any feedback row, include it
                                        if (!isset($matchedKeys[$key])) {
                                            $unmatched_transactions[] = [
                                                'trx_date' => $trxDate,
                                                'account_no' => $acct,
                                                'reference_no' => $ref,
                                                'amount_paid' => (float)$rowTrx['amount_paid'],
                                                'partner_id' => htmlspecialchars($rowTrx['partner_id']),
                                                'branch_id' => htmlspecialchars($rowTrx['branch_id'])
                                            ];
                                        }
                                    }
                                }
                            }
                            $stmtTrx->close();
                        }
                    }
                }

                // store unmatched transactions in session for UI and export
                $_SESSION['unmatched_transactions'] = $unmatched_transactions;
            }
        }
    }
}

// export handler: export matched/unmatched rows from session to Excel
if (!empty($_POST['export_type'])) {
    $exportType = $_POST['export_type'] === 'matched' ? 'matched' : ($_POST['export_type'] === 'unmatched_trx' ? 'unmatched_trx' : 'unmatched');

    // for unmatched_trx we export transaction rows collected in session
    if ($exportType === 'unmatched_trx') {
        $rows = $_SESSION['unmatched_transactions'] ?? [];
    } else {
        $data = $_SESSION['matched_feedback_data'] ?? [];
        // ensure indexed array of filtered rows
        $rows = array_values(array_filter($data, function($r) use ($exportType) {
            return $exportType === 'matched' ? (!empty($r['is_matched'])) : empty($r['is_matched']);
        }));
    }
 
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(ucfirst($exportType) . ' Transactions');
 
    // helper to get cell address (define before using it)
    $cell = function($colIdx, $rowIdx) {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx) . $rowIdx;
    };
    $sheet->setCellValue($cell(1,1), 'M Lhuillier Philippines, Inc.');   
    $sheet->setCellValue($cell(1,2), 'Bills Payment Feedback Report');   
    $sheet->setCellValue($cell(1,3), 'Generated on : ');   
    $sheet->setCellValue($cell(2,3), date('F d, Y'));   
    $sheet->setCellValue($cell(1,4), 'Printed By : ');   
    $sheet->setCellValue($cell(2,4), $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'system');   

    // If exporting unmatched transactions, use a simpler header (Transaction file)
    if ($exportType === 'unmatched_trx') {
        $sheet->setCellValue($cell(1,6), 'Date');
        $sheet->setCellValue($cell(2,6), 'Account Number');
        $sheet->setCellValue($cell(3,6), 'Reference Number');
        $sheet->setCellValue($cell(4,6), 'Principal Amount');
        $sheet->setCellValue($cell(5,6), 'Branch ID');
        $sheet->setCellValue($cell(6,6), 'Partner ID');

        // header styling
        $sheet->getStyle('A6:F6')->getFont()->setBold(true);
        $sheet->getStyle('A6:F6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $r = 7;
        foreach ($rows as $row) {
            $dateVal = $row['trx_date'] ?? '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateVal)) {
                $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($dateVal);
                $sheet->setCellValue($cell(1, $r), $excelDate);
                $sheet->getStyle($cell(1, $r))->getNumberFormat()->setFormatCode('mm-dd-yyyy');
            } else {
                $sheet->setCellValue($cell(1, $r), $dateVal);
            }
            $sheet->setCellValueExplicit($cell(2,$r), $row['account_no'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue($cell(3,$r), $row['reference_no'] ?? '');
            $sheet->setCellValue($cell(4,$r), isset($row['amount_paid']) ? (float)$row['amount_paid'] : 0.0);
            $sheet->getStyle($cell(4,$r))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->setCellValue($cell(5,$r), $row['branch_id'] ?? '');
            $sheet->setCellValue($cell(6,$r), $row['partner_id'] ?? '');
            $r++;
        }

        // compute totals for unmatched_trx
        $totalCount = count($rows);
        $totalAmount = 0.0;
        foreach ($rows as $rr) {
            $totalAmount += isset($rr['amount_paid']) ? (float)$rr['amount_paid'] : 0.0;
        }

        // autosize and output (same as below)
        $highestColumn = $sheet->getHighestColumn();
        foreach (range('A', $highestColumn) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        if ($r > 0) {
            $totalRow = $r + 1;
            
            // Title row merged across all used columns
            $sheet->mergeCells($cell(1, $totalRow) . ':' . $cell(2, $totalRow));
            $sheet->setCellValue($cell(1, $totalRow), 'TOTAL AMOUNT');
            $sheet->getStyle($cell(1, $totalRow))->getFont()->setBold(true);
            $sheet->getStyle($cell(1, $totalRow))->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Write labels and numeric values (adjust columns as needed)
            $labelCol = 1;   // column A
            $valueCol = 2;   // column B

            $sheet->setCellValue($cell($labelCol, $totalRow + 1), 'Total Counts :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 1), $totalCount);
            $sheet->getStyle($cell($valueCol, $totalRow + 1))->getNumberFormat()->setFormatCode('#,##0');

            $sheet->setCellValue($cell($labelCol, $totalRow + 2), 'Principal Amount :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 2), $totalAmount);
            $sheet->getStyle($cell($valueCol, $totalRow + 2))->getNumberFormat()->setFormatCode('#,##0.00');

            // bold the labels
            $sheet->getStyle($cell($labelCol, $totalRow + 1) . ':' . $cell($labelCol, $totalRow + 3))->getFont()->setBold(true);
        }
        // $filename = "billspayment_unmatched_trx_" . date('Ymd_His') . ".xlsx";
        // header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        // header("Content-Disposition: attachment; filename=\"{$filename}\"");
        // header('Cache-Control: max-age=0');
        // $writer = new Xlsx($spreadsheet);
        // $writer->save('php://output');
        // exit();
    }elseif ($exportType === 'unmatched') {
        $sheet->setCellValue($cell(1,6), 'Date');
        $sheet->setCellValue($cell(2,6), 'Account Number');
        $sheet->setCellValue($cell(3,6), 'Reference Number');
        $sheet->setCellValue($cell(4,6), 'Loan Type');
        $sheet->setCellValue($cell(5,6), 'Principal Amount');

        // header styling
        $sheet->getStyle('A6:F6')->getFont()->setBold(true);
        $sheet->getStyle('A6:F6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $r = 7;
        foreach ($rows as $row) {
            // Date: if YYYY-MM-DD convert to Excel date, otherwise write text
            $dateVal = $row['date'] ?? '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateVal)) {
                $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($dateVal);
                $sheet->setCellValue($cell(1, $r), $excelDate);
                $sheet->getStyle($cell(1, $r))->getNumberFormat()->setFormatCode('mm-dd-yyyy');
            } else {
                $sheet->setCellValue($cell(1, $r), $dateVal);
            }

            // Account number
            $sheet->setCellValueExplicit($cell(2,$r), $row['account_number'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $sheet->setCellValue($cell(3, $r), $row['reference_number'] ?? '');
            $sheet->setCellValue($cell(4, $r), $row['loan_type'] ?? '');
            $sheet->setCellValue($cell(5, $r), $row['principal_amount'] ?? '');
            $sheet->getStyle($cell(5, $r))->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
        }

        // compute totals for unmatched_trx
        $totalCount = count($rows);
        $totalAmount = 0.0;

        foreach ($rows as $row) {
            $totalAmount += (float)($row['principal_amount'] ?? 0);
        }

        // autosize and output (same as below)
        $highestColumn = $sheet->getHighestColumn();
        foreach (range('A', $highestColumn) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        if ($r > 0) {
            $totalRow = $r + 1;
            
            // Title row merged across all used columns
            $sheet->mergeCells($cell(1, $totalRow) . ':' . $cell(2, $totalRow));
            $sheet->setCellValue($cell(1, $totalRow), 'TOTAL AMOUNT');
            $sheet->getStyle($cell(1, $totalRow))->getFont()->setBold(true);
            $sheet->getStyle($cell(1, $totalRow))->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Write labels and numeric values (adjust columns as needed)
            $labelCol = 1;   // column A
            $valueCol = 2;   // column B

            $sheet->setCellValue($cell($labelCol, $totalRow + 1), 'Total Counts :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 1), $totalCount);
            $sheet->getStyle($cell($valueCol, $totalRow + 1))->getNumberFormat()->setFormatCode('#,##0');

            $sheet->setCellValue($cell($labelCol, $totalRow + 2), 'Principal Amount :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 2), $totalAmount);
            $sheet->getStyle($cell($valueCol, $totalRow + 2))->getNumberFormat()->setFormatCode('#,##0.00');

            // bold the labels
            $sheet->getStyle($cell($labelCol, $totalRow + 1) . ':' . $cell($labelCol, $totalRow + 3))->getFont()->setBold(true);
        }
    }else{
         // build two-row header to match screenshot:
        // Row 1: Date | FEEDBACK FILE (merged over 4 cols) | TRANSACTION FILE (merged over 4 cols) | Branch ID | Branch Name | Region Code | Region Name | MLMatic Region Name | KP7 Partner ID | KPX Partner ID | Partner Name
        // Row 2: (under FEEDBACK and TRANSACTION) Account Number, Reference Number, Loan Type, Principal Amount (repeated)
        $headers2 = ['Account Number', 'Reference Number', 'Loan Type', 'Principal Amount'];
        $headers2_2 = ['Account Number', 'Reference Number', 'Principal Amount'];

        // Row 1
        $sheet->setCellValue($cell(1, 6), 'Date');
        // FEEDBACK FILE spans B1:E1 (cols 2..5)
        $sheet->mergeCells($cell(2,6).':'.$cell(5,6));
        $sheet->setCellValue($cell(2,6), 'FEEDBACK FILE');
        // TRANSACTION FILE spans F1:I1 (cols 6..9)
        $sheet->mergeCells($cell(6,6).':'.$cell(8,6));
        $sheet->setCellValue($cell(6,6), 'TRANSACTION FILE');

        // remaining single columns after transaction block
        $singleHeaders = ['Branch Name','Region Name', 'Branch Type','KP7 Partner ID','KPX Partner ID','Partner Name'];
        // $singleHeaders = ['Branch Name','Region Name','MLMatic Region Name','KP7 Partner ID','KPX Partner ID','Partner Name'];
        $col = 9; // starts at column J (9)
        foreach ($singleHeaders as $h) {
            // merge the header cell across row 1 and 2, then set its value
            $sheet->mergeCells($cell($col,6).':'.$cell($col,7));
            $sheet->setCellValue($cell($col,6), $h);
            $col++;
        }

        // Row 2 - sub-headers for FEEDBACK FILE (B-E) and TRANSACTION FILE (F-I)
        $col = 2;
        foreach ($headers2 as $h) {
            $sheet->setCellValue($cell($col++, 7), $h);
        }
        $col = 6;
        foreach ($headers2_2 as $h) {
            $sheet->setCellValue($cell($col++, 7), $h);
        }

        // make Date cell span two rows (A1:A2)
        $sheet->mergeCells($cell(1,6).':'.$cell(1,7));

        // styling: bold + center for header rows
        $sheet->getStyle('A6:'.$sheet->getHighestColumn().'7')->getFont()->setBold(true);
        $sheet->getStyle('A6:'.$sheet->getHighestColumn().'7')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(6)->setRowHeight(20);
        $sheet->getRowDimension(7)->setRowHeight(18);

        // data rows
        $r = 8;
        foreach ($rows as $row) {
            $c = 1;

            // Date: if YYYY-MM-DD convert to Excel date, otherwise write text
            $dateVal = $row['date'] ?? '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateVal)) {
                $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($dateVal);
                $sheet->setCellValue($cell($c, $r), $excelDate);
                $sheet->getStyle($cell($c, $r))->getNumberFormat()->setFormatCode('mm-dd-yyyy');
            } else {
                $sheet->setCellValue($cell($c, $r), $dateVal);
            }
            $c++;

            // Account number
            $sheet->setCellValueExplicit(
                $cell($c++, $r),
                $row['account_number'] ?? '',
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            // Reference number
            $sheet->setCellValue($cell($c++, $r), $row['reference_number'] ?? '');

            $sheet->setCellValue($cell($c++, $r), $row['loan_type'] ?? '');

            // Principal (numeric)
            $sheet->setCellValue($cell($c, $r), isset($row['principal_amount']) ? (float)$row['principal_amount'] : 0.0);
            $sheet->getStyle($cell($c, $r))->getNumberFormat()->setFormatCode('#,##0.00');
            $c++;

            // Branch id, partner id, partner name
            $sheet->setCellValueExplicit(
                $cell($c++, $r),
                $row['bt_account_no'] ?? '',
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $sheet->setCellValue($cell($c++, $r), $row['bt_reference_no'] ?? '');

            $sheet->setCellValue($cell($c, $r), isset($row['bt_amount_paid']) && $row['bt_amount_paid'] !== '' ? (float)$row['bt_amount_paid'] : '');
            $sheet->getStyle($cell($c, $r))->getNumberFormat()->setFormatCode('#,##0.00');
            $c++;
            
            $sheet->setCellValue($cell($c++, $r), $row['branch_name'] ?? '');
            $sheet->setCellValue($cell($c++, $r), $row['region_name'] ?? '');
            // $sheet->setCellValue($cell($c++, $r), $row['mlmatic_region_name'] ?? '');
            $sheet->setCellValue($cell($c++, $r), '');
            $sheet->setCellValue($cell($c++, $r), $row['partner_id'] ?? '');
            $sheet->setCellValue($cell($c++, $r), '');
            $sheet->setCellValue($cell($c++, $r), $row['partner_name'] ?? '');

            $r++;
        }

        // autosize columns (works with single-letter highest columns)
        $highestColumn = $sheet->getHighestColumn();
        foreach (range('A', $highestColumn) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        if ($r > 0) {
            $totalRow = $r + 1;

            // determine last column index
            $highestCol = $sheet->getHighestColumn();
            $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

            // compute totals from exported rows
            $sumFeedback = 0.0;
            $sumTransaction = 0.0;
            foreach ($rows as $rr) {
                $sumFeedback += isset($rr['principal_amount']) ? (float)$rr['principal_amount'] : 0.0;
                $sumTransaction += isset($rr['bt_amount_paid']) && $rr['bt_amount_paid'] !== '' ? (float)$rr['bt_amount_paid'] : 0.0;
            }

            // Title row merged across all used columns
            $sheet->mergeCells($cell(2, $totalRow) . ':' . $cell(3, $totalRow));
            $sheet->setCellValue($cell(2, $totalRow), 'VARIANCE RESULT');
            $sheet->getStyle($cell(2, $totalRow))->getFont()->setBold(true);
            $sheet->getStyle($cell(2, $totalRow))->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Write labels and numeric values (adjust columns as needed)
            $labelCol = 2;   // column B
            $valueCol = 3;   // column C

            $sheet->setCellValue($cell($labelCol, $totalRow + 1), 'Principal Amount ( FEEDBACK FILE ) :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 1), $sumFeedback);
            $sheet->getStyle($cell($valueCol, $totalRow + 1))->getNumberFormat()->setFormatCode('#,##0.00');

            $sheet->setCellValue($cell($labelCol, $totalRow + 2), 'Principal Amount ( TRANSACTION FILE ) :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 2), $sumTransaction);
            $sheet->getStyle($cell($valueCol, $totalRow + 2))->getNumberFormat()->setFormatCode('#,##0.00');

            $sheet->setCellValue($cell($labelCol, $totalRow + 3), 'Variance :');
            $sheet->setCellValue($cell($valueCol, $totalRow + 3), $sumTransaction - $sumFeedback);
            $sheet->getStyle($cell($valueCol, $totalRow + 3))->getNumberFormat()->setFormatCode('#,##0.00');

            // bold the labels
            $sheet->getStyle($cell($labelCol, $totalRow + 1) . ':' . $cell($labelCol, $totalRow + 3))->getFont()->setBold(true);
        }
    }
 
    

    $filename = "billspayment_{$exportType}_" . date('Ymd_His') . ".xlsx";

    // make sure no output has been sent before these headers
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

if (isset($_POST['confirm_import'])) {
    // Handle confirmed import
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $matched_feedback_data = $_SESSION['matched_feedback_data'] ?? [];
    $summary = $_SESSION['matched_feedback_summary'] ?? [];

    // basic metadata
    $uploaded_date = date('Y-m-d H:i:s');
    $uploaded_by = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'system';
    $user_type = $_SESSION['user_type'] ?? 'admin';

    if (empty($matched_feedback_data)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'No data to import', 'inserted' => 0, 'failed' => 0]);
            exit();
        } else {
            header('Location: ../../admin/import_billspaymentfeedback.php?imported=0&reason=empty');
            exit();
        }
    }

    // prepare insert (24 columns)
    $sql = "INSERT INTO mldb.billspayment_feedback
        (certified_datetime, source_file, account_no, lastname, firstname, middlename, type_of_loan, principal_amount, `date`, `timestamp`,
        reference_no, additional_ref_code, phone_no, status1, branch_name, status2, partner_name, kp7partner_id,
        mbp_branch_id, mrm_region_code, mrm_zone_code, mbp_mlmatic_region_name, uploaded_date, uploaded_by, user_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    if (! $stmt = $conn->prepare($sql)) {
        error_log('Prepare failed (confirm_import): ' . $conn->error);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Prepare failed', 'error' => $conn->error]);
            exit();
        } else {
            header('Location: ../../admin/import_billspaymentfeedback.php?imported=0&reason=prepare_fail');
            exit();
        }
    }

    // types: 1..6 strings, 7 principal_amount double, rest strings (total 24 params)
    $types = 'sssssssd' . str_repeat('s', 17);

    // Begin transaction
    $conn->begin_transaction();

    $inserted = 0;
    $failed = 0;

    foreach ($matched_feedback_data as $row) {
        // only insert matched rows (UI only allows confirm when unmatched_count === 0 but keep safe)
        if (empty($row['is_matched'])) {
            continue;
        }

        // map row fields with safe defaults
        $source_file = $row['source_file'] ?? $summary['source_file'] ?? '';
        $account_no = $row['account_number'] ?? '';
        $lastname = $row['lastname'] ?? '';
        $firstname = $row['firstname'] ?? '';
        $middlename = $row['middlename'] ?? '';
        $type_of_loan = $row['loan_type'] ?? '';
        $principal_amount = isset($row['principal_amount']) ? (float)$row['principal_amount'] : 0.0;
        $date = $row['date'] ?? null;
        $timestamp = $row['timestamp'] ?? null;
        $reference_no = $row['reference_number'] ?? '';
        $additional_ref_code = $row['additional_ref_code'] ?? '';
        $phone_no = $row['phone_number'] ?? '';
        $status1 = $row['status1'] ?? '';
        $branch_name = $row['branch_name'] ?? '';
        $status2 = $row['status2'] ?? '';
        $partner_name = $row['partner_name'] ?? $summary['partner_name'] ?? '';
        $partner_id = $row['partner_id'] ?? $summary['partner_id'] ?? '';
        $mbp_branch_id = $row['branch_id'] ?? '';
        $mrm_region_code = $row['region_code'] ?? '';
        $mrm_zone_code = $row['zone_code'] ?? '';
        $mbp_mlmatic_region_name = $row['mlmatic_region_name'] ?? '';

        // bind and execute
        if (! $stmt->bind_param(
            $types,
            $uploaded_date,  // certified_datetime
            $source_file,
            $account_no,
            $lastname,
            $firstname,
            $middlename,
            $type_of_loan,
            $principal_amount,
            $date,
            $timestamp,
            $reference_no,
            $additional_ref_code,
            $phone_no,
            $status1,
            $branch_name,
            $status2,
            $partner_name,
            $partner_id,
            $mbp_branch_id,
            $mrm_region_code,
            $mrm_zone_code,
            $mbp_mlmatic_region_name,
            $uploaded_date,
            $uploaded_by,
            $user_type
        )) {
            error_log('Bind failed (confirm_import): ' . $stmt->error);
            $failed++;
            continue;
        }

        if ($stmt->execute()) {
            $inserted++;
        } else {
            error_log('Execute failed (confirm_import): ' . $stmt->error);
            $failed++;
        }
    }

    // finalize
    if ($failed === 0) {
        $conn->commit();
        // clear session data after successful insert
        unset($_SESSION['matched_feedback_data'], $_SESSION['matched_feedback_summary']);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => 'Data Successfully Imported',
                'inserted' => $inserted,
                'failed' => $failed,
            ]);
            exit();
        } else {
            header('Location: ../../admin/import_billspaymentfeedback.php?imported=1&inserted=' . $inserted);
            exit();
        }
    } else {
        $conn->rollback();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Import failed',
                'inserted' => $inserted,
                'failed' => $failed
            ]);
            exit();
        } else {
            header('Location: ../../admin/import_billspaymentfeedback.php?imported=0&inserted=' . $inserted . '&failed=' . $failed);
            exit();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <title>Import Result</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>
    <style>

        /* new: scrollable wrapper for matched / unmatched samples */
        .records-scroll {
            max-height: 320px;   /* adjust height as needed */
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding-right: 8px;  /* prevent horizontal scrollbar overlap */
        }

        /* keep table header sticky inside the scroll area */
        .records-scroll thead th {
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 5;
        }
    </style>
</head>
<body>
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>
    <?php 
    if (isset($_POST['upload'])):
        $matchedfeedbackdata = $_SESSION['matched_feedback_data'] ?? [];
        $summary = $_SESSION['matched_feedback_summary'] ?? [];
        $rows_imported = $summary['rows_imported'] ?? 0;
        $matched_count = $summary['matched_count'] ?? 0;
        $unmatched_count = $summary['unmatched_count'] ?? 0;
        $matched_total_principal = $summary['matched_total_principal'] ?? 0.0;
        $matched_total_amount_paid = $summary['matched_total_amount_paid'] ?? 0.0;
        $unmatched_total_principal = $summary['unmatched_total_principal'] ?? 0.0;
        // unmatched transactions from session
        $unmatched_trx = $_SESSION['unmatched_transactions'] ?? [];
        $unmatched_trx_count = count($unmatched_trx);
        $unmatched_trx_total = 0.0;
        foreach ($unmatched_trx as $t) { $unmatched_trx_total += isset($t['amount_paid']) ? (float)$t['amount_paid'] : 0.0; }
        $net_count = $matched_count + $unmatched_count;
        $net_total_principal = $matched_total_amount_paid - $matched_total_principal;
        $net_total_principal_unmatched = $unmatched_total_principal;
 
        $partner_id = htmlspecialchars($summary['partner_id'] ?? '');
        $partner_name = htmlspecialchars($summary['partner_name'] ?? '');
        $source_file = htmlspecialchars($summary['source_file'] ?? '');
        $import_date = htmlspecialchars($summary['import_date'] ?? '');
        $uploaded_by = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'system';
        
    ?>
        <div id="summary-section">
            <div id="upload-success" class="container-fluid py-4" style="margin-top: 20px;">
                <div class="text-center mb-4">
                    <div class="card shadow-sm border-0 bg-light py-4">
                        <h3 class="text-center fw-bold text-primary">Would you like to proceed inserting the data?</h3>
                        <div class="card-body">
                            <?php if ($unmatched_count === 0) : ?>
                            <form method="post" id="confirmImportForm" class="d-inline">
                                <input type="hidden" name="post" value="1">
                                <button type="submit" class="btn btn-success btn-lg me-3 shadow-sm">
                                    <i class="fas fa-check-circle me-2"></i>Post
                                </button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-danger btn-lg shadow-sm" onclick="confirmCancel()">
                                <i class="fas fa-times-circle me-2"></i>Cancel
                            </button>
                        </div>
                    </div>
                </div>
                <div class="row mt-4 gx-4">
                    <!-- Import Details Card -->
                    <div class="col-md-3">
                        <div class="card shadow border-0 h-100">
                            <div class="card-header bg-success text-white py-3">
                                <h4 class="mb-0 text-center"><i class="fas fa-info-circle me-2"></i>Import Details</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle">
                                        <thead>
                                            <tr class="table-secondary">
                                                <th>Property</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><i class="fas fa-id-card text-primary me-2"></i>KP7 Partner ID</td>
                                                <td class="fw-semibold"><?php echo $partner_id; ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-id-card text-primary me-2"></i>KPX Partner ID</td>
                                                <td class="fw-semibold"><?php echo '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-building text-primary me-2"></i>Partner Name</td>
                                                <td class="fw-semibold"><?php echo $partner_name; ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-list-ol text-primary me-2"></i>Row Imported</td>
                                                <td class="fw-semibold"><?php echo number_format($rows_imported); ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-file-import text-primary me-2"></i>Extension File</td>
                                                <td class="fw-semibold"><?php echo '.' . strtoupper($source_file); ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-calendar-alt text-primary me-2"></i>Uploaded Date</td>
                                                <td class="fw-semibold"><?php echo $import_date; ?></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-calendar-alt text-primary me-2"></i>Uploaded By</td>
                                                <td class="fw-semibold"><?php echo $uploaded_by; ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Transaction Summary Table -->
                    <div class="col-md-9">
                        <div class="card shadow border-0">
                            <div class="card-header bg-danger text-white py-3">
                                <h4 class="mb-0 text-center"><i class="fas fa-chart-line me-2"></i>Feedback Summary</h4>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead>
                                        <tr class="bg-danger text-white text-center fw-bold">
                                            <th class="text-center" style="width: 33%">Matched Transaction</th>
                                            <th class="text-center" style="width: 33%">Unmatched Feedback File</th>
                                            <th class="text-center" style="width: 33%">Unmatched Bills Pay Trx File</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-calculator text-secondary me-2"></i>TOTAL COUNT</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo number_format($matched_count); ?></div>
                                                </div>
                                            </td>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-calculator text-secondary me-2"></i>TOTAL COUNT</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo number_format($unmatched_count); ?></div>
                                                </div>
                                            </td>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-calculator text-secondary me-2"></i>TOTAL COUNT</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo number_format($unmatched_trx_count); ?></div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-money-bill-wave text-success me-2"></i>TOTAL PRINCIPAL</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo formatCurrency($matched_total_principal); ?></div>
                                                </div>
                                            </td>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-money-bill-wave text-success me-2"></i>TOTAL PRINCIPAL</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo formatCurrency($unmatched_total_principal); ?></div>
                                                </div>
                                            </td>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-money-bill-wave text-success me-2"></i>TOTAL PRINCIPAL</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo formatCurrency($unmatched_trx_total); ?></div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-info-circle text-secondary me-2"></i>VIEW RESULTS</div>
                                                    <div class="col-6 text-end fw-bold">
                                                        <?php if ($matched_count > 0) :?>
                                                        <button type="button" class="btn btn-danger btn-sm shadow-sm me-2" title="Export matched to Excel" onclick="exportData('matched')">
                                                            <i class="fas fa-download"></i>
                                                        </button>
                                                        <?php else: ?>
                                                            <span class="text-muted"> - </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-info-circle text-secondary me-2"></i>VIEW RESULTS</div>
                                                    <div class="col-6 text-end fw-bold">
                                                        <?php if ($unmatched_count > 0) : ?>
                                                        <button type="button" class="btn btn-danger btn-sm shadow-sm me-2" title="Export unmatched to Excel" onclick="exportData('unmatched')">
                                                            <i class="fas fa-download"></i>
                                                        </button>
                                                        <?php else: ?>
                                                            <span class="text-muted"> - </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                            <td class="border-end">
                                                <div class="row">
                                                    <div class="col-6 fw-semibold"><i class="fas fa-info-circle text-secondary me-2"></i>VIEW RESULTS</div>
                                                    <div class="col-6 text-end fw-bold">
                                                        <?php if ($unmatched_trx_count > 0) : ?>
                                                        <button type="button" class="btn btn-danger btn-sm shadow-sm me-2" title="Export unmatched Bills Pay Trx to Excel" onclick="exportData('unmatched_trx')">
                                                            <i class="fas fa-download"></i>
                                                        </button>
                                                        <?php else: ?>
                                                            <span class="text-muted"> - </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    </tbody>
                                </table>

                                <!-- Optional: quick samples of matched/unmatched lists -->
                                <div class="mt-4">
                                    <h5 id="matched">Matched Transaction Records</h5>
                                    <div class="table-responsive records-scroll">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th>Account</th><th>Reference</th><th>Principal</th><th>Branch ID</th></tr></thead>
                                            <tbody>
                                                <?php
                                                $count = 0;
                                                foreach ($matchedfeedbackdata as $row) {
                                                    if ($row['is_matched']) {
                                                        echo '<tr><td>'.htmlspecialchars($row['account_number']).'</td><td>'.htmlspecialchars($row['reference_number']).'</td><td>'.formatCurrency($row['principal_amount']).'</td><td>'.htmlspecialchars($row['branch_id'] ?? '').'</td></tr>';
                                                        ++$count;
                                                    }
                                                }
                                                if ($count === 0) {
                                                    echo '<tr><td colspan="4">No matched records</td></tr>';
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h5 id="unmatched" class="mt-3">Unmatched Feedback Records</h5>
                                    <div class="table-responsive records-scroll">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th>Account</th><th>Reference</th><th>Principal</th></tr></thead>
                                            <tbody>
                                                <?php
                                                $count = 0;
                                                foreach ($matchedfeedbackdata as $row) {
                                                    if (!$row['is_matched']) {
                                                        echo '<tr><td>'.htmlspecialchars($row['account_number']).'</td><td>'.htmlspecialchars($row['reference_number']).'</td><td>'.formatCurrency($row['principal_amount']).'</td></tr>';
                                                        ++$count;
                                                    }
                                                }
                                                if ($count === 0) {
                                                    echo '<tr><td colspan="3">No unmatched records</td></tr>';
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h5 id="unmatched" class="mt-3">Unmatched Bills Pay Trx Records</h5>
                                    <div class="table-responsive records-scroll">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th>Account</th><th>Reference</th><th>Amount</th><th>Branch ID</th></tr></thead>
                                            <tbody>
                                                <?php
                                                $count = 0;
                                                foreach ($unmatched_trx as $trx) {
                                                    echo '<tr>';
                                                    echo '<td>'.htmlspecialchars($trx['account_no'] ?? $trx['account_number'] ?? '').'</td>';
                                                    echo '<td>'.htmlspecialchars($trx['reference_no'] ?? $trx['reference_number'] ?? '').'</td>';
                                                    echo '<td>'.formatCurrency(isset($trx['amount_paid']) ? $trx['amount_paid'] : 0).'</td>';
                                                    echo '<td>'.htmlspecialchars($trx['branch_id'] ?? '').'</td>';
                                                    echo '</tr>';
                                                    ++$count;
                                                }
                                                if ($count === 0) {
                                                    echo '<tr><td colspan="4">No unmatched transactions</td></tr>';
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" role="alert">
            No results found.
        </div>
    <?php endif; ?>

    <script>
        // formatted strings for display (safe JSON-encoded)
        var unmatchedFormatted = <?php echo json_encode(number_format($unmatched_count ?? 0)); ?>;
        var matchedFormatted = <?php echo json_encode(number_format($matched_count ?? 0)); ?>;


        // existing functions
        function confirmCancel(){
            Swal.fire({
                title: 'Cancel ?',
                html: 'Please confirm if you want to cancel the import process.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    // proceed to cancel and return to import page
                    window.location.href = '../../admin/import_billspaymentfeedback.php';
                }
                // if dismissed or cancelled, do nothing (stay on page)
            });
        }

        function exportData(type) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = ''; // submit to the same page

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'export_type';
            input.value = type;
            form.appendChild(input);

            // required by PHP handler (keeps backward compatibility)
            var flag = document.createElement('input');
            flag.type = 'hidden';
            flag.name = 'export';
            flag.value = '1';
            form.appendChild(flag);

            document.body.appendChild(form);
            form.submit();
        }

        // Intercept the confirm import form submit and do AJAX
        (function(){
            var form = document.getElementById('confirmImportForm');
            if (!form) return;

            function performImport(){
                // show processing modal
                Swal.fire({
                    title: 'Processing import',
                    html: 'Please wait while the data is being inserted...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                var fd = new FormData(form);

                // IMPORTANT: tell the server this is the confirm-import action
                fd.append('confirm_import', '1');

                // Ensure X-Requested-With header to let PHP detect AJAX
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin'
                }).then(function(resp){
                    return resp.json();
                }).then(function(json){
                    Swal.close();

                    if (json.status === 'success') {
                        // Build HTML similar to your pasted image
                        var html = '<div style="text-align:center;padding:10px;">' +
                            '<div style="background:#d1e7dd;color:#0f5132;padding:12px;border-radius:6px;margin:12px 0;">' +
                            'Matched <strong>' + Number(json.inserted || 0).toLocaleString() + ' records</strong>' +
                            '</div>' +
                            '<div style="color:#6c757d;font-size:14px;">' + (json.note ? json.note : '') + '</div>' +
                            '</div>';

                        Swal.fire({
                            title: 'Data Successfully Imported',
                            html: html,
                            icon: 'success',
                            showCancelButton: false,
                            confirmButtonText: 'Close',
                            confirmButtonColor: "#28a745",
                            allowOutsideClick: false,
                            customClass: {
                                popup: 'swal2-border'
                            }
                        }).then(function(){
                            // redirect back to import page (you can change target)
                            window.location.href = '../../admin/import_billspaymentfeedback.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Import Failed',
                            html: '<p>' + (json.message || 'An error occurred') + '</p>' +
                                (json.failed !== undefined ? '<p>Failed: ' + json.failed + '</p>' : ''),
                            confirmButtonText: 'OK'
                        });
                    }
                }).catch(function(err){
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Network/Error',
                        text: 'Unable to complete the import. Check console for details.',
                        confirmButtonText: 'OK'
                    });
                    console.error(err);
                });
            }

            form.addEventListener('submit', function(e){
                e.preventDefault();

                

                // FIRST confirmation dialog before proceeding
                var confirmHtml = '<div style="text-align:center;padding:10px;">' +
                    '<i class="fas fa-check-circle text-success mb-3" style="font-size: 3rem;"></i>' +
                    '<h3 style="color:#0f5132;margin:0 0 6px 0;">Ready to Import</h3>' +
                    '<div style="background:#d1e7dd;color:#0f5132;padding:12px;border-radius:6px;margin:12px 0;">' +
                    'Matched <strong>' + matchedFormatted + ' records</strong>' +
                    '</div>' +
                    '<div style="color:#6c757d;font-size:14px;">' + '<strong>Partner: </strong><?php echo addslashes($summary['partner_name'] ?? ''); ?>' + '</div>' +
                    // checkbox block (left-aligned)
                    '<div style="text-align:left;margin-top:12px;">' +
                    '<label style="cursor:pointer;"><input type="checkbox" id="confirmProceedCheckbox" style="margin-right:8px;"> I certify all data are correct and balanced.</label>' +
                    '</div>' +
                    '</div>';

                Swal.fire({
                    title: 'Notice',
                    html: confirmHtml + '<p style="margin-top:8px;">Are you sure to proceed this data?</p>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Proceed',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: "#28a745",
                    cancelButtonColor: '#d33',
                    allowOutsideClick: false,
                    didOpen: function() {
                        // disable confirm button until checkbox is checked
                        var popup = Swal.getPopup();
                        var checkbox = popup.querySelector('#confirmProceedCheckbox');
                        var confirmBtn = Swal.getConfirmButton();

                        if (confirmBtn) confirmBtn.disabled = true;

                        if (checkbox) {
                            checkbox.addEventListener('change', function() {
                                if (confirmBtn) confirmBtn.disabled = !this.checked;
                            });
                        }
                    }
                }).then(function(result){
                    if (result.isConfirmed) {
                        performImport();
                    }
                    // if cancelled, do nothing
                });
            });
        })();
    </script>

    <script>
        // Show override dialog when there are unmatched rows after upload
        document.addEventListener('DOMContentLoaded', function () {
            var unmatched = <?php echo (int)($unmatched_count ?? 0); ?>;
            var matched = <?php echo (int)($matched_count ?? 0); ?>;
            var unmatched_trx = <?php echo (int)($unmatched_trx_count ?? 0); ?>;
            var matchedFormatted = <?php echo json_encode(number_format($matched_count ?? 0)); ?>;
            var unmatchedFormatted = <?php echo json_encode(number_format($unmatched_count ?? 0)); ?>;
            var unmatchedTrxFormatted = <?php echo json_encode(number_format($unmatched_trx_count ?? 0)); ?>;
            var unmatchedTrxAmountFormatted = <?php echo json_encode(number_format($unmatched_trx_total ?? 0, 2)); ?>;

            if (unmatched > 0 || unmatched_trx > 0) {
                var html = ''
                    + '<div class="text-center">'
                    + '<i class="fas fa-exclamation-triangle text-warning mb-3" style="font-size: 3rem;"></i>'
                    + '<h4 class="text-warning mb-3">Warning</h4>'
                    + '<div class="alert alert-info">Matched - <strong>' + matchedFormatted +'</strong></div>'
                    + '<div class="alert alert-info">Unmatched (Feedback) - <strong>' + unmatchedFormatted + '</strong></div>'
                    + '<div class="alert alert-info">Unmatched Bills Pay Trx - <strong>' + unmatchedTrxFormatted + '</strong></div>'
                    + '</div>';

                Swal.fire({
                    title: '',
                    html: html,
                    icon: 'warning',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#f0ad4e',
                    allowOutsideClick: false
                });
            }
        });
    </script>
</body>
</html>