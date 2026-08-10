<?php
// billspay-transaction_monthly.php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Import Transaction','Bills Payment'])) { header('Location: ../../home.php'); exit; }

// Get current user info
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
$imported_by = $_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'System';

// ===== Session timeout after 30 minutes =====
$inactivity_timeout = 1800;
if (isset($_SESSION['bp_last_activity']) && (time() - $_SESSION['bp_last_activity']) > $inactivity_timeout) {
    $keys = ['bp_success_message', 'bp_error_message', 'bp_last_activity', 'import_progress', 'import_stats', 'preview_data', 'preview_page'];
    foreach ($keys as $k) unset($_SESSION[$k]);
}
$_SESSION['bp_last_activity'] = time();

// ===== Reset handler =====
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    $keys = ['bp_success_message', 'bp_error_message', 'bp_last_activity', 'import_progress', 'import_stats', 'preview_data', 'preview_page'];
    foreach ($keys as $k) unset($_SESSION[$k]);
    header("Location: billspay-transaction_monthly.php");
    exit;
}

// ===== Progress check endpoint =====
if (isset($_GET['get_progress'])) {
    header('Content-Type: application/json');
    echo json_encode($_SESSION['import_progress'] ?? ['processed_rows' => 0, 'total_rows' => 0]);
    exit;
}

// ===== Preview pagination endpoint =====
if (isset($_GET['get_preview_page'])) {
    header('Content-Type: application/json');
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = 1000;
    
    $previewData = $_SESSION['preview_data'] ?? null;
    if (!$previewData) {
        echo json_encode(['error' => 'No preview data found']);
        exit;
    }
    
    // Get preview rows from the CSV file
    $csvFile = $_SESSION['temp_csv_path'] ?? null;
    $rows = [];
    $currentRow = 0;
    $offset = ($page - 1) * $perPage;
    $rowsFetched = 0;
    
    // Calculate totals for ALL rows (overall totals)
    $overallTotals = [
        'col_h' => 0, // amount_paid
        'col_i' => 0, // charge_to_customer
        'col_j' => 0  // charge_to_partner
    ];
    
    // Track rows with empty branch_id (\N) - only count, not store details
    $emptyBranchIdCount = 0;
    $totalValidRows = 0;
    
    if ($csvFile && file_exists($csvFile)) {
        $fp = fopen($csvFile, 'r');
        if ($fp) {
            fgetcsv($fp); // Skip header
            $headerCount = count($previewData['headers']);
            
            while (($rowData = fgetcsv($fp)) !== false) {
                // Ensure correct column count
                while (count($rowData) < $headerCount) $rowData[] = '';
                if (count($rowData) > $headerCount) $rowData = array_slice($rowData, 0, $headerCount);
                
                // Check if column A (index 0) has a value
                $hasValueInColumnA = !empty(trim($rowData[0] ?? ''));
                
                // Only count rows where column A has a value
                if ($hasValueInColumnA) {
                    $totalValidRows++;
                    
                    // Add to overall totals for columns H, I, J (indices 7, 8, 9)
                    $overallTotals['col_h'] += parseAmount($rowData[7] ?? '0');
                    $overallTotals['col_i'] += parseAmount($rowData[8] ?? '0');
                    $overallTotals['col_j'] += parseAmount($rowData[9] ?? '0');
                    
                    // Check if branch_id (index 11) is \N or empty
                    $branchId = trim($rowData[11] ?? '');
                    if ($branchId === '\\N' || $branchId === '' || $branchId === 'NULL') {
                        $emptyBranchIdCount++;
                    }
                }
            }
            
            // Reset and read again for pagination
            rewind($fp);
            fgetcsv($fp); // Skip header
            $currentRow = 0;
            
            while (($rowData = fgetcsv($fp)) !== false && $rowsFetched < $perPage) {
                // Ensure correct column count
                while (count($rowData) < $headerCount) $rowData[] = '';
                if (count($rowData) > $headerCount) $rowData = array_slice($rowData, 0, $headerCount);
                
                // Check if column A (index 0) has a value
                $hasValueInColumnA = !empty(trim($rowData[0] ?? ''));
                
                // Only count and include rows where column A has a value
                if ($hasValueInColumnA) {
                    if ($currentRow >= $offset && $rowsFetched < $perPage) {
                        $rows[] = $rowData;
                        $rowsFetched++;
                    }
                    $currentRow++;
                }
                // Skip rows where column A is empty (don't count them)
            }
            fclose($fp);
        }
    }
    
    // Recalculate total pages based on valid rows
    $totalPages = ceil($totalValidRows / $perPage);
    
    echo json_encode([
        'rows' => $rows,
        'current_page' => $page,
        'total_pages' => $totalPages,
        'total_rows' => $totalValidRows,
        'per_page' => $perPage,
        'overall_totals' => $overallTotals,
        'empty_branch_id_count' => $emptyBranchIdCount
    ]);
    exit;
}

// Column index constants (0-based)
define('COL_DATE', 0);
define('COL_CONTROL_NO', 1);
define('COL_REFERENCE_NO', 2);
define('COL_PAYOR_NAME', 3);
define('COL_ADDRESS', 4);
define('COL_ACCOUNT_NO', 5);
define('COL_ACCOUNT_NAME', 6);
define('COL_AMOUNT_PAID', 7);
define('COL_CHARGE_CUSTOMER', 8);
define('COL_CHARGE_PARTNER', 9);
define('COL_OTHER_DETAILS', 10);
define('COL_BRANCH_ID', 11);
define('COL_ML_OUTLET', 12);
define('COL_REGION_CODE', 13);
define('COL_REGION_NAME', 14);
define('COL_OPERATOR', 15);
define('COL_REMOTE_BRANCH', 16);
define('COL_REMOTE_OPERATOR', 17);
define('COL_2ND_APPROVER', 18);
define('COL_PARTNER_ID', 19);
define('COL_PARTNER_NAME', 20);
define('COL_STATUS', 21);

const EXPECTED_COLUMNS = 22;
const PREVIEW_PER_PAGE = 1000;

// ===== Performance optimization constants =====
define('PROGRESS_UPDATE_INTERVAL', 100);  // Update progress every 100 rows
define('MEMORY_LIMIT', '4096M');
define('MAX_EXECUTION_TIME', 7200);
define('MAX_FILE_SIZE', 500 * 1024 * 1024);

// ===== Helpers =====

/**
 * Parse amount string to float
 * 
 * @param string|null $amount_str The amount string to parse
 * @return float The parsed amount
 */
function parseAmount(?string $amount_str): float {
    $amount_str = trim($amount_str ?? '0');
    $amount_str = str_replace(['₱', 'PHP', '$', ',', ' ', '"'], '', $amount_str);
    return round(floatval($amount_str), 2);
}

/**
 * Clean a cell value
 * 
 * @param string|null $value The value to clean
 * @return string The cleaned value
 */
function cleanCellValue(?string $value): string {
    if ($value === null) return '';
    if (is_numeric($value)) return trim((string)$value);
    return trim((string)$value);
}

/**
 * Convert a CSV cell to a nullable UTF-8 string suitable for DB insert.
 * Treats empty, \N and NULL as null. Preserves legitimate "0".
 * Converts common Latin-1 / Windows-1252 bytes (e.g. Ñ = \xD1) to UTF-8.
 *
 * @param string|null $value
 * @return string|null
 */
function toNullableString($value): ?string {
    if ($value === null) {
        return null;
    }
    $val = trim((string)$value);
    if ($val === '' || $val === '\\N' || strtoupper($val) === 'NULL') {
        return null;
    }

    // Fix encoding: many Philippine CSVs contain Latin-1 / Windows-1252 characters
    // (Ñ, ñ, accented letters). Convert to UTF-8 so MySQL utf8mb4 accepts them.
    if (!mb_check_encoding($val, 'UTF-8')) {
        $converted = @mb_convert_encoding($val, 'UTF-8', 'Windows-1252');
        if ($converted !== false) {
            $val = $converted;
        } else {
            $converted = @mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1');
            if ($converted !== false) {
                $val = $converted;
            }
        }
    }

    // Final safety: strip any remaining invalid UTF-8 sequences
    $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');

    return $val;
}

/**
 * Parse datetime string to MySQL datetime format
 * 
 * @param string|float|null $dateValue The date value to parse
 * @return string|null The formatted date string or null if parsing fails
 */
function parseDateTime($dateValue): ?string {
    if (empty($dateValue)) return null;
    
    try {
        if (is_numeric($dateValue)) {
            $timestamp = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($dateValue);
            return date('Y-m-d H:i:s', $timestamp);
        }
        $date = new DateTime($dateValue);
        return $date->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Preview CSV data - reads first N rows for preview
 * Only counts rows where column A (index 0) has a value
 * 
 * @param string $csvFile Path to the CSV file
 * @param int $previewRows Number of rows to preview
 * @return array Preview data including headers, rows, and metadata
 */
function previewCSV(string $csvFile, int $previewRows = 10): array {
    $result = [
        'headers' => [],
        'rows' => [],
        'total_rows' => 0,
        'file_name' => '',
        'column_count' => 0,
        'errors' => []
    ];

    if (!file_exists($csvFile)) {
        $result['errors'][] = 'CSV file not found';
        return $result;
    }
    
    $fp = fopen($csvFile, 'r');
    if (!$fp) {
        $result['errors'][] = 'Could not open CSV file';
        return $result;
    }
    
    // Read header
    $header = fgetcsv($fp);
    if (!$header) {
        $result['errors'][] = 'CSV file is empty or has no header';
        fclose($fp);
        return $result;
    }
    
    $result['headers'] = $header;
    $result['column_count'] = count($header);
    
    // Count total rows (excluding header) - ONLY rows where column A has a value
    $rowCount = 0;
    $previewCount = 0;
    $rows = [];
    
    while (($rowData = fgetcsv($fp)) !== false) {
        // Ensure correct column count
        while (count($rowData) < count($header)) $rowData[] = '';
        if (count($rowData) > count($header)) $rowData = array_slice($rowData, 0, count($header));
        
        // Check if column A (index 0) has a value
        $hasValueInColumnA = !empty(trim($rowData[0] ?? ''));
        
        // Only count rows where column A has a value
        if ($hasValueInColumnA) {
            $rowCount++;
            if ($previewCount < $previewRows) {
                $rows[] = $rowData;
                $previewCount++;
            }
        }
        // Skip rows where column A is empty (don't count them)
    }
    
    $result['rows'] = $rows;
    $result['total_rows'] = $rowCount;
    
    fclose($fp);
    return $result;
}

/**
 * Import from CSV file – row-by-row continuous processing
 * Only imports rows where column A (index 0) has a value.
 * One bad row no longer kills thousands of good rows.
 * Progress updates happen continuously every N rows.
 *
 * @param string $csvFile Path to the CSV file
 * @param string $imported_by Name of the person importing
 * @param int $updateInterval How often to update progress (in rows)
 * @return array Import results with success, failure, and error counts
 */
function importCSVInChunks(string $csvFile, string $imported_by, int $updateInterval = PROGRESS_UPDATE_INTERVAL): array {
    global $conn;

    $result = [
        'success'           => 0,
        'failed'            => 0,
        'errors'            => [],
        'file_name'         => '',
        'total_rows'        => 0
    ];

    if (!$conn || $conn->connect_error) {
        $result['errors'][] = 'Database connection failed: ' . ($conn ? $conn->connect_error : 'Connection object is null');
        return $result;
    }

    ini_set('memory_limit', MEMORY_LIMIT);
    ini_set('max_execution_time', MAX_EXECUTION_TIME);
    ini_set('max_input_time', MAX_EXECUTION_TIME);

    // Force UTF-8 connection so Ñ / ñ / accented characters are accepted
    $conn->set_charset('utf8mb4');
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->query("SET CHARACTER SET utf8mb4");
    $conn->query("SET unique_checks = 0");
    $conn->query("SET foreign_key_checks = 0");

    $stmt = null;
    $fp   = null;

    try {
        if (!file_exists($csvFile)) {
            $result['errors'][] = 'CSV file not found: ' . $csvFile;
            return $result;
        }

        $fp = fopen($csvFile, 'r');
        if (!$fp) {
            $result['errors'][] = 'Could not open CSV file: ' . $csvFile;
            return $result;
        }

        $header = fgetcsv($fp);
        if (!$header) {
            $result['errors'][] = 'CSV file is empty or has no header';
            fclose($fp);
            return $result;
        }

        if (count($header) < EXPECTED_COLUMNS) {
            $result['errors'][] = "CSV has " . count($header) . " columns, expected " . EXPECTED_COLUMNS . " columns";
            fclose($fp);
            return $result;
        }

        // Prepare statement once
        $sql = "INSERT INTO billspayment_transaction_per_month (
                    datetime, control_no, reference_no, payor, address, account_no,
                    account_name, amount_paid, charge_to_customer, charge_to_partner,
                    other_details, branch_id, outlet, region_code_tg, region_tg,
                    operator, remote_branch, remote_operator, `2nd_approver`,
                    partner_id, partner_name, status, imported_by, imported_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $result['errors'][] = 'Prepare failed: ' . $conn->error;
            fclose($fp);
            return $result;
        }

        $types = 'sssssssdddssssssssssssss'; // 7s + 3d + 14s

        date_default_timezone_set('Asia/Manila');
        $imported_date = date('Y-m-d H:i:s');

        // Count total valid rows for progress
        $totalRows = 0;
        rewind($fp);
        fgetcsv($fp); // skip header
        while (($rowData = fgetcsv($fp)) !== false) {
            if (!empty(trim($rowData[0] ?? ''))) {
                $totalRows++;
            }
        }
        rewind($fp);
        fgetcsv($fp); // skip header again

        $_SESSION['import_progress'] = [
            'processed_rows'    => 0,
            'total_rows'        => $totalRows,
            'status'            => 'processing'
        ];

        $totalSuccess  = 0;
        $totalFailed   = 0;
        $rowsProcessed = 0;
        $progressTick  = 0;

        while (($rowData = fgetcsv($fp)) !== false) {
            // Skip rows where column A is empty
            if (empty(trim($rowData[0] ?? ''))) {
                continue;
            }

            while (count($rowData) < EXPECTED_COLUMNS) $rowData[] = '';
            if (count($rowData) > EXPECTED_COLUMNS) $rowData = array_slice($rowData, 0, EXPECTED_COLUMNS);

            $datetime = parseDateTime($rowData[COL_DATE] ?? null);
            if ($datetime === null) {
                $datetime = date('Y-m-d H:i:s');
            }

            $data = [
                $datetime,
                toNullableString($rowData[COL_CONTROL_NO] ?? null),
                toNullableString($rowData[COL_REFERENCE_NO] ?? null),
                toNullableString($rowData[COL_PAYOR_NAME] ?? null),
                toNullableString($rowData[COL_ADDRESS] ?? null),
                toNullableString($rowData[COL_ACCOUNT_NO] ?? null),
                toNullableString($rowData[COL_ACCOUNT_NAME] ?? null),
                parseAmount($rowData[COL_AMOUNT_PAID] ?? '0'),
                parseAmount($rowData[COL_CHARGE_CUSTOMER] ?? '0'),
                parseAmount($rowData[COL_CHARGE_PARTNER] ?? '0'),
                toNullableString($rowData[COL_OTHER_DETAILS] ?? null),
                toNullableString($rowData[COL_BRANCH_ID] ?? null),
                toNullableString($rowData[COL_ML_OUTLET] ?? null),
                toNullableString($rowData[COL_REGION_CODE] ?? null),
                toNullableString($rowData[COL_REGION_NAME] ?? null),
                toNullableString($rowData[COL_OPERATOR] ?? null),
                toNullableString($rowData[COL_REMOTE_BRANCH] ?? null),
                toNullableString($rowData[COL_REMOTE_OPERATOR] ?? null),
                toNullableString($rowData[COL_2ND_APPROVER] ?? null),
                toNullableString($rowData[COL_PARTNER_ID] ?? null),
                toNullableString($rowData[COL_PARTNER_NAME] ?? null),
                toNullableString($rowData[COL_STATUS] ?? null),
                $imported_by,
                $imported_date
            ];

            $rowsProcessed++;
            $progressTick++;

            // Bind + execute this single row
            $params = array_merge([$types], $data);
            $refs   = [];
            foreach ($params as $key => $value) {
                $refs[$key] = &$params[$key];
            }

            $ok = false;
            if (call_user_func_array([$stmt, 'bind_param'], $refs)) {
                $ok = $stmt->execute();
            }

            if ($ok) {
                $totalSuccess++;
            } else {
                $totalFailed++;
                $errMsg = $stmt->error ?: $conn->error ?: 'Unknown execute error';
                if (count($result['errors']) < 50) {
                    $result['errors'][] = "Row $rowsProcessed: " . $errMsg;
                }
            }

            // Update progress continuously every N rows
            if ($progressTick >= $updateInterval) {
                $progressTick = 0;
                $_SESSION['import_progress'] = [
                    'processed_rows'    => $rowsProcessed,
                    'total_rows'        => $totalRows,
                    'status'            => 'processing'
                ];
                // Allow session to be written so the progress endpoint can read it
                session_write_close();
                session_start();
            }
        }

        fclose($fp);
        $fp = null;
        $stmt->close();
        $stmt = null;

        $conn->query("SET unique_checks = 1");
        $conn->query("SET foreign_key_checks = 1");

        $result['success']    = $totalSuccess;
        $result['failed']     = $totalFailed;
        $result['total_rows'] = $rowsProcessed;

        $_SESSION['import_progress'] = [
            'processed_rows'    => $rowsProcessed,
            'total_rows'        => $totalRows,
            'status'            => 'completed'
        ];

        if (file_exists($csvFile)) {
            unlink($csvFile);
        }

        if ($rowsProcessed === 0) {
            $result['errors'][] = 'No data rows found with values in column A. Please check the file content.';
        }

    } catch (\Exception $e) {
        if ($stmt) {
            @$stmt->close();
        }
        if ($fp) {
            @fclose($fp);
        }
        if (isset($conn) && $conn->connect_errno === 0) {
            $conn->query("SET unique_checks = 1");
            $conn->query("SET foreign_key_checks = 1");
        }
        $result['errors'][] = 'Error processing file: ' . $e->getMessage();
        error_log("CSV import error: " . $e->getMessage());

        $_SESSION['import_progress'] = [
            'processed_rows'    => $rowsProcessed ?? 0,
            'total_rows'        => $totalRows ?? 0,
            'status'            => 'error',
            'error'             => $e->getMessage()
        ];

        if (isset($csvFile) && file_exists($csvFile)) {
            unlink($csvFile);
        }
    }

    return $result;
}

// ===== Handle file upload for preview =====
$preview_data = null;
$show_preview = false;
$uploaded_csv_path = null;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload']) && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'preview' || $action === 'import') {
        $files = $_FILES['file_upload'];
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmps = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errs = is_array($files['error']) ? $files['error'] : [$files['error']];
        $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
        
        $all_errors = [];
        $processed_files = [];
        
        for ($i = 0; $i < count($names); $i++) {
            if ($errs[$i] === UPLOAD_ERR_OK) {
                $file_tmp = $tmps[$i];
                $file_name = $names[$i];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $file_size = $sizes[$i];

                if ($file_size > MAX_FILE_SIZE) {
                    $all_errors[] = "File '$file_name' exceeds the " . (MAX_FILE_SIZE / 1024 / 1024) . "MB limit.";
                    continue;
                }

                if ($file_ext === 'csv') {
                    try {
                        $csvFile = sys_get_temp_dir() . '/' . uniqid('import_') . '.csv';
                        
                        if (move_uploaded_file($file_tmp, $csvFile)) {
                            $uploaded_csv_path = $csvFile;
                            
                            if ($action === 'preview') {
                                $preview_data = previewCSV($csvFile, 10);
                                $preview_data['file_name'] = $file_name;
                                $show_preview = true;
                                $_SESSION['preview_data'] = $preview_data;
                                $_SESSION['temp_csv_path'] = $csvFile;
                                $_SESSION['preview_page'] = 1;
                            } elseif ($action === 'import') {
                                $result = importCSVInChunks($csvFile, $imported_by);
                                $result['file_name'] = $file_name;
                                $total_success = $result['success'];
                                $total_failed = $result['failed'];
                                $total_rows = $result['total_rows'] ?? 0;
                                
                                if (!empty($result['errors'])) {
                                    $all_errors = array_merge($all_errors, $result['errors']);
                                }
                                if ($result['success'] > 0 || $result['failed'] > 0) {
                                    $processed_files[] = $file_name;
                                }
                                
                                $_SESSION['import_stats'] = [
                                    'total_rows' => $total_rows,
                                    'success' => $total_success,
                                    'failed' => $total_failed,
                                    'files_processed' => count($processed_files)
                                ];

                                if ($total_success > 0 && $total_failed == 0) {
                                    $success_message = "Successfully imported <strong>" . number_format($total_success) . "</strong> rows from " . count($processed_files) . " file(s) into the database!";
                                    $_SESSION['bp_success_message'] = $success_message;
                                } elseif ($total_success > 0 && $total_failed > 0) {
                                    $error_message = "Import completed with partial success. Success: " . number_format($total_success) . ", Failed: " . number_format($total_failed) . ". ";
                                    if (!empty($all_errors)) {
                                        $error_message .= "First few errors: " . implode('; ', array_slice($all_errors, 0, 3));
                                    }
                                    $_SESSION['bp_error_message'] = $error_message;
                                } else {
                                    $error_message = "Import failed. No rows were imported. ";
                                    if (!empty($all_errors)) {
                                        $error_message .= implode(' ', array_slice($all_errors, 0, 3));
                                    }
                                    $_SESSION['bp_error_message'] = $error_message;
                                }
                                
                                header("Location: billspay-transaction_monthly.php");
                                exit;
                            }
                        } else {
                            $all_errors[] = "Failed to move uploaded CSV file '$file_name'.";
                        }
                    } catch (\Exception $e) {
                        $all_errors[] = "Error processing '$file_name': " . $e->getMessage();
                        error_log("File processing error: " . $e->getMessage());
                    }
                } else {
                    $all_errors[] = "Invalid file type: '$file_name'. Please upload CSV files only.";
                }
            }
        }
        
        if (!empty($all_errors)) {
            $_SESSION['bp_error_message'] = implode('; ', array_slice($all_errors, 0, 3));
        }
    }
}

// ===== Load messages from session =====
if (isset($_SESSION['bp_success_message'])) {
    $success_message = $_SESSION['bp_success_message'];
    unset($_SESSION['bp_success_message']);
}
if (isset($_SESSION['bp_error_message'])) {
    $error_message = $_SESSION['bp_error_message'];
    unset($_SESSION['bp_error_message']);
}

// Load preview data from session if available
if (isset($_SESSION['preview_data']) && !$show_preview) {
    $preview_data = $_SESSION['preview_data'];
    $show_preview = true;
}

// Load current page from session
if (isset($_SESSION['preview_page'])) {
    $current_page = $_SESSION['preview_page'];
}

// Load import stats from session
$import_stats = $_SESSION['import_stats'] ?? [];
$import_progress = $_SESSION['import_progress'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Monthly Transaction</title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/billspay_transaction_monthly.css?v=<?= time(); ?>">

</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="container-fluid import-container">
            <div class="upload-card">
                <h2><i class="fa-solid fa-file-csv me-2"></i>Import Monthly Bills Payment Transactions</h2>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-circle-check"></i> <?= $success_message ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($import_progress) && isset($import_progress['status']) && $import_progress['status'] === 'processing'): ?>
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-spinner fa-spin"></i> 
                        An import is currently in progress. Please wait for it to complete.
                    </div>
                <?php endif; ?>

                <!-- Progress Display - Continuous Import -->
                <div id="importProgress" class="progress-container" style="<?= (!empty($import_progress) && isset($import_progress['status']) && $import_progress['status'] === 'processing') ? 'display:block;' : 'display:none;' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold">Import Progress</span>
                        <span class="batch-info">
                            Status: <span id="statusLabel">Processing...</span>
                        </span>
                    </div>
                    <div class="progress">
                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: <?= isset($import_progress['total_rows']) && $import_progress['total_rows'] > 0 ? min(100, ($import_progress['processed_rows'] / $import_progress['total_rows']) * 100) : 0 ?>%;">
                            <?= isset($import_progress['total_rows']) && $import_progress['total_rows'] > 0 ? round(min(100, ($import_progress['processed_rows'] / $import_progress['total_rows']) * 100)) : 0 ?>%
                        </div>
                    </div>
                    <div class="progress-status" id="progressStatus">
                        <span id="processedRows"><?= number_format($import_progress['processed_rows'] ?? 0) ?></span>
                        of 
                        <span id="totalRows"><?= number_format($import_progress['total_rows'] ?? 0) ?></span>
                        rows processed
                        <span class="text-muted">(Continuous Import)</span>
                    </div>
                </div>

                <!-- Preview Section -->
                <?php if ($show_preview && $preview_data): ?>
                <div class="alert alert-info">
                    <i class="fa-solid fa-eye"></i> 
                    <strong>Preview Mode:</strong> <strong><?= htmlspecialchars($preview_data['file_name']) ?></strong>
                    <?php if (!empty($preview_data['errors'])): ?>
                        <br><span class="text-danger">⚠️ <?= implode('; ', $preview_data['errors']) ?></span>
                    <?php endif; ?>
                </div>

                <!-- Empty Branch ID Warning - Simplified (only count) -->
                <div id="emptyBranchIdWarning" style="display:none;" class="alert alert-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <strong>Notice:</strong> <span id="emptyBranchIdMessage">0</span> row(s) have empty or <code>\N</code> values in the <strong>Branch ID</strong> column. <small>These rows will be imported with <strong>NULL</strong> branch_id values.</small>
                </div>

                <div class="preview-info">
                    <div>
                        <span class="info-item"><strong>Total Rows:</strong> <?= number_format($preview_data['total_rows']) ?></span>
                        <span class="info-item ms-3"><strong>Columns:</strong> <?= $preview_data['column_count'] ?></span>
                        <span class="info-item ms-3">
                            <strong>Column Match:</strong>
                            <?php if ($preview_data['column_count'] >= EXPECTED_COLUMNS): ?>
                                <span class="badge-column-match">✓ <?= $preview_data['column_count'] ?> columns (expected <?= EXPECTED_COLUMNS ?>)</span>
                            <?php else: ?>
                                <span class="badge-column-mismatch">✗ <?= $preview_data['column_count'] ?> columns (expected <?= EXPECTED_COLUMNS ?>)</span>
                            <?php endif; ?>
                        </span>
                        <span class="info-item ms-3"><strong>Rows/Page:</strong> <?= number_format(PREVIEW_PER_PAGE) ?></span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="scrollToTop()">
                            <i class="fa-solid fa-arrow-up"></i> Top
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="refreshPreview()">
                            <i class="fa-solid fa-rotate"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing page <strong id="currentPageDisplay"><?= $current_page ?></strong> of 
                        <strong id="totalPagesDisplay"><?= ceil($preview_data['total_rows'] / PREVIEW_PER_PAGE) ?></strong>
                        (<span id="rowRangeDisplay">0</span> rows)
                    </div>
                    <div class="pagination-controls" id="paginationControls">
                        <button class="btn-page" id="firstPage" onclick="goToPage(1)" <?= $current_page <= 1 ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-angles-left"></i>
                        </button>
                        <button class="btn-page" id="prevPage" onclick="goToPage(<?= $current_page - 1 ?>)" <?= $current_page <= 1 ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-angle-left"></i>
                        </button>
                        <span id="pageNumbers"></span>
                        <button class="btn-page" id="nextPage" onclick="goToPage(<?= $current_page + 1 ?>)" <?= $current_page >= ceil($preview_data['total_rows'] / PREVIEW_PER_PAGE) ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-angle-right"></i>
                        </button>
                        <button class="btn-page" id="lastPage" onclick="goToPage(<?= ceil($preview_data['total_rows'] / PREVIEW_PER_PAGE) ?>)" <?= $current_page >= ceil($preview_data['total_rows'] / PREVIEW_PER_PAGE) ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-angles-right"></i>
                        </button>
                    </div>
                </div>

                <div class="preview-table-container" id="previewTableContainer">
                    <div class="text-center py-4" id="loadingIndicator">
                        <div class="loading-spinner"></div>
                        <p class="mt-2 text-muted">Loading preview data...</p>
                    </div>
                    <table class="preview-table" id="previewTable" style="display:none;">
                        <thead>
                            <tr>
                                <th class="row-number">#</th>
                                <?php 
                                $headerIndex = 0;
                                foreach ($preview_data['headers'] as $header): 
                                    $headerIndex++;
                                ?>
                                    <th><?= htmlspecialchars(trim($header ?: "Column " . $headerIndex)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                        </tbody>
                        <tfoot id="previewTableFooter" style="display:none;">
                            <tr>
                                <td class="row-number" style="background: #f1f5f9;">
                                    <i class="fa-solid fa-calculator" title="Overall Totals"></i>
                                </td>
                                <?php 
                                $colIndex = 0;
                                foreach ($preview_data['headers'] as $header): 
                                    $colIndex++;
                                    $isTotalColumn = in_array($colIndex, [8, 9, 10]); // H, I, J (1-based)
                                ?>
                                    <td id="total_<?= $colIndex ?>" style="background: #f1f5f9; <?= $isTotalColumn ? 'color: #dc2626;' : '' ?>">
                                        <?= $isTotalColumn ? '<span class="total-value">0.00</span>' : '' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="action-buttons mt-3">
                    <form action="" method="POST" enctype="multipart/form-data" style="display:inline;">
                        <input type="hidden" name="action" value="import">
                        <input type="file" name="file_upload[]" accept=".csv" style="display:none;" 
                               id="hiddenFileInput" required>
                        <button type="submit" class="btn-confirm-import" id="confirmImportBtn">
                            <i class="fa-solid fa-check-circle"></i> Confirm Import
                        </button>
                    </form>
                    <a href="?reset=1" class="btn-cancel-preview">
                        <i class="fa-solid fa-arrow-left"></i> Back to Upload
                    </a>
                </div>
                <?php endif; ?>

                <!-- Upload Form - Hidden when preview is shown -->
                <div id="uploadFormWrapper" class="<?= $show_preview ? 'upload-form-hidden' : '' ?>">
                    <form id="uploadForm" action="" method="POST" enctype="multipart/form-data" 
                          onsubmit="return handleFormSubmit(event)">
                        <input type="hidden" name="action" value="preview" id="actionInput">
                        <div class="drop-zone" id="dropZone">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p class="mb-1"><strong>Drag & drop your CSV file(s) here</strong></p>
                            <p class="mb-0" style="font-size:13px;color:#94a3b8;">or click to browse (.csv only, multiple allowed)</p>
                            <p class="file-info">Max file size: <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB per file</p>
                            <div id="file-list-display"></div>
                            <input type="file" name="file_upload[]" id="fileInput" accept=".csv" multiple required>
                        </div>
                        <div class="action-buttons">
                            <button type="button" class="btn-preview" id="previewBtn" onclick="setActionAndSubmit('preview')">
                                <i class="fa-solid fa-eye"></i> Preview
                            </button>
                            <button type="button" class="btn-import" id="importBtn" onclick="setActionAndSubmit('import')">
                                <i class="fa-solid fa-upload"></i> Upload & Import
                            </button>
                            <a href="?reset=1" class="btn-reset">
                                <i class="fa-solid fa-rotate"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($success_message) && isset($import_stats['success']) && $import_stats['success'] > 0): ?>
            <div class="upload-card">
                <h3><i class="fa-solid fa-chart-simple me-2"></i>Import Summary</h3>
                <div class="stats-box">
                    <div class="stat-item">
                        <span class="stat-label">Total Rows Imported</span>
                        <span class="stat-value success"><?= number_format($import_stats['success'] ?? 0) ?></span>
                    </div>
                    <?php if (!empty($import_stats['total_rows'])): ?>
                    <div class="stat-item">
                        <span class="stat-label">Total Rows Processed</span>
                        <span class="stat-value"><?= number_format($import_stats['total_rows']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="stat-item">
                        <span class="stat-label">Files Processed</span>
                        <span class="stat-value"><?= number_format($import_stats['files_processed'] ?? 0) ?></span>
                    </div>
                    <?php if (($import_stats['failed'] ?? 0) > 0): ?>
                    <div class="stat-item">
                        <span class="stat-label">Failed Rows</span>
                        <span class="stat-value danger"><?= number_format($import_stats['failed']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>


        </div>
    </div>

    <?php include '../../../templates/footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileListDisplay = document.getElementById('file-list-display');
        const previewBtn = document.getElementById('previewBtn');
        const importBtn = document.getElementById('importBtn');
        const importProgress = document.getElementById('importProgress');
        const progressBar = document.getElementById('progressBar');
        const progressStatus = document.getElementById('progressStatus');
        const processedRows = document.getElementById('processedRows');
        const totalRows = document.getElementById('totalRows');
        const statusLabel = document.getElementById('statusLabel');
        const uploadFormWrapper = document.getElementById('uploadFormWrapper');

        let isImporting = false;
        let progressInterval = null;
        let currentPage = <?= $current_page ?>;
        const totalPages = <?= ceil(($preview_data['total_rows'] ?? 0) / PREVIEW_PER_PAGE) ?>;
        const perPage = <?= PREVIEW_PER_PAGE ?>;

        // Check if there's an ongoing import
        <?php if (!empty($import_progress) && isset($import_progress['status']) && $import_progress['status'] === 'processing'): ?>
            isImporting = true;
            previewBtn.disabled = true;
            importBtn.disabled = true;
            startProgressTracking();
        <?php endif; ?>

        // Load preview if showing
        <?php if ($show_preview && $preview_data): ?>
            loadPreviewPage(currentPage);
            // Hide upload form when preview is shown
            if (uploadFormWrapper) {
                uploadFormWrapper.classList.add('upload-form-hidden');
            }
        <?php endif; ?>

        // Drag & drop events
        ['dragenter', 'dragover'].forEach(evt => {
            dropZone.addEventListener(evt, e => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, e => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
            });
        });

        dropZone.addEventListener('drop', e => {
            if (isImporting) {
                alert('An import is already in progress. Please wait for it to complete.');
                return;
            }
            
            const files = e.dataTransfer.files;
            if (!files.length) return;
            
            let valid = true;
            for (let f of files) {
                const ext = f.name.split('.').pop().toLowerCase();
                if (ext !== 'csv') {
                    alert('Please upload CSV files only.\nInvalid: ' + f.name);
                    valid = false;
                    break;
                }
                if (f.size > <?= MAX_FILE_SIZE ?>) {
                    alert('File "' + f.name + '" exceeds the <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB limit.');
                    valid = false;
                    break;
                }
            }
            
            if (valid) {
                fileInput.files = files;
                updateFileList(files);
            }
        });

        fileInput.addEventListener('change', () => {
            if (isImporting) {
                alert('An import is already in progress. Please wait for it to complete.');
                fileInput.value = '';
                return;
            }
            
            if (fileInput.files.length) {
                let valid = true;
                for (let f of fileInput.files) {
                    const ext = f.name.split('.').pop().toLowerCase();
                    if (ext !== 'csv') {
                        alert('Please upload CSV files only.\nInvalid: ' + f.name);
                        valid = false;
                        break;
                    }
                    if (f.size > <?= MAX_FILE_SIZE ?>) {
                        alert('File "' + f.name + '" exceeds the <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB limit.');
                        valid = false;
                        break;
                    }
                }
                if (valid) {
                    updateFileList(fileInput.files);
                } else {
                    fileInput.value = '';
                    fileListDisplay.textContent = '';
                }
            } else {
                fileListDisplay.textContent = '';
            }
        });

        function updateFileList(files) {
            if (!files.length) {
                fileListDisplay.textContent = '';
                return;
            }
            const names = Array.from(files).map(f => f.name + ' (' + formatFileSize(f.size) + ')');
            fileListDisplay.innerHTML = `<i class="fa-solid fa-check-circle" style="color:#16a34a;"></i> ${files.length} file(s): ${names.join(', ')}`;
        }

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        }

        window.setActionAndSubmit = function(action) {
            if (isImporting) {
                alert('An import is already in progress. Please wait for it to complete.');
                return;
            }
            
            const files = document.getElementById('fileInput').files;
            if (!files.length) {
                alert('Please select files to upload.');
                return;
            }
            
            for (let f of files) {
                const ext = f.name.split('.').pop().toLowerCase();
                if (ext !== 'csv') {
                    alert('Please upload CSV files only.\nInvalid: ' + f.name);
                    return;
                }
                if (f.size > <?= MAX_FILE_SIZE ?>) {
                    alert('File "' + f.name + '" exceeds the <?= MAX_FILE_SIZE / 1024 / 1024 ?>MB limit.');
                    return;
                }
            }
            
            document.getElementById('actionInput').value = action;
            
            if (action === 'import') {
                isImporting = true;
                previewBtn.disabled = true;
                importBtn.disabled = true;
                importBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing...';
                importProgress.style.display = 'block';
                progressBar.style.width = '0%';
                progressBar.textContent = '0%';
                progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated';
                progressStatus.innerHTML = 'Preparing CSV import...';
                if (statusLabel) statusLabel.textContent = 'Preparing...';
                startProgressTracking();
            }
            
            document.getElementById('uploadForm').submit();
        };

        function startProgressTracking() {
            if (progressInterval) {
                clearInterval(progressInterval);
            }
            
            progressInterval = setInterval(() => {
                fetch('billspay-transaction_monthly.php?get_progress=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.total_rows > 0) {
                            const percent = Math.min(100, (data.processed_rows / data.total_rows) * 100);
                            progressBar.style.width = percent + '%';
                            progressBar.textContent = Math.round(percent) + '%';
                            
                            if (processedRows) processedRows.textContent = numberFormat(data.processed_rows);
                            if (totalRows) totalRows.textContent = numberFormat(data.total_rows);
                            if (statusLabel) statusLabel.textContent = 'Processing...';
                            
                            progressStatus.innerHTML = 
                                numberFormat(data.processed_rows) + ' of ' + 
                                numberFormat(data.total_rows) + ' rows processed ' +
                                '<span class="text-muted">(Continuous Import)</span>';
                            
                            if (data.status === 'completed') {
                                clearInterval(progressInterval);
                                progressInterval = null;
                                progressBar.className = 'progress-bar progress-bar-striped success';
                                if (statusLabel) statusLabel.textContent = '✅ Completed';
                                progressStatus.innerHTML = '✅ Import completed successfully!';
                                isImporting = false;
                                previewBtn.disabled = false;
                                importBtn.disabled = false;
                                importBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Import';
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            } else if (data.status === 'error') {
                                clearInterval(progressInterval);
                                progressInterval = null;
                                progressBar.className = 'progress-bar progress-bar-striped error';
                                if (statusLabel) statusLabel.textContent = '❌ Failed';
                                progressStatus.innerHTML = '❌ Import failed: ' + (data.error || 'Unknown error');
                                isImporting = false;
                                previewBtn.disabled = false;
                                importBtn.disabled = false;
                                importBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Import';
                            }
                        } else if (data && data.total_rows === 0) {
                            // No rows to import
                            clearInterval(progressInterval);
                            progressInterval = null;
                            progressBar.style.width = '100%';
                            progressBar.textContent = '100%';
                            if (statusLabel) statusLabel.textContent = '⚠️ No Data';
                            progressStatus.innerHTML = '⚠️ No valid rows found in the CSV file.';
                            isImporting = false;
                            previewBtn.disabled = false;
                            importBtn.disabled = false;
                            importBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Import';
                            progressBar.className = 'progress-bar progress-bar-striped warning';
                        }
                    })
                    .catch(error => {
                        console.log('Progress check error:', error);
                    });
            }, 2000);
        }

        function numberFormat(num) {
            if (!num) return '0';
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // ===== Preview Pagination Functions =====
        window.goToPage = function(page) {
            if (page < 1 || page > totalPages || page === currentPage) return;
            currentPage = page;
            loadPreviewPage(page);
            updatePaginationButtons(page);
        };

        function loadPreviewPage(page) {
            const loadingIndicator = document.getElementById('loadingIndicator');
            const previewTable = document.getElementById('previewTable');
            const tableBody = document.getElementById('previewTableBody');
            const tableFooter = document.getElementById('previewTableFooter');
            const emptyBranchIdWarning = document.getElementById('emptyBranchIdWarning');
            const emptyBranchIdMessage = document.getElementById('emptyBranchIdMessage');
            
            // Show loading
            loadingIndicator.style.display = 'block';
            previewTable.style.display = 'none';
            tableFooter.style.display = 'none';
            emptyBranchIdWarning.style.display = 'none';
            
            // Update page display
            document.getElementById('currentPageDisplay').textContent = page;
            document.getElementById('totalPagesDisplay').textContent = totalPages;
            
            fetch(`billspay-transaction_monthly.php?get_preview_page=1&page=${page}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error loading preview: ' + data.error);
                        return;
                    }
                    
                    // Update row range
                    const startRow = (page - 1) * perPage + 1;
                    const endRow = Math.min(page * perPage, data.total_rows);
                    document.getElementById('rowRangeDisplay').textContent = 
                        `${numberFormat(startRow)} - ${numberFormat(endRow)} of ${numberFormat(data.total_rows)}`;
                    
                    // Build table rows
                    let html = '';
                    const globalStart = (page - 1) * perPage;
                    
                    data.rows.forEach((row, index) => {
                        const rowNum = globalStart + index + 1;
                        html += '<tr>';
                        html += `<td class="row-number">${rowNum}</td>`;
                        row.forEach(cell => {
                            const value = cell || '';
                            html += `<td title="${escapeHtml(value)}">${escapeHtml(value.substring(0, 100))}</td>`;
                        });
                        html += '</tr>';
                    });
                    
                    if (data.rows.length === 0) {
                        const colCount = <?= $preview_data['column_count'] ?? 22 ?> + 1;
                        html = `<tr>
                            <td colspan="${colCount}" class="text-center py-4 text-muted">
                                <i class="fa-solid fa-inbox me-2"></i> No data rows found on this page
                            </td>
                        </tr>`;
                        tableFooter.style.display = 'none';
                    } else {
                        // Update overall totals in footer
                        if (data.overall_totals) {
                            // Column H (index 7) - amount_paid - shows as 8th column (1-based)
                            const totalH = document.getElementById('total_8');
                            if (totalH) totalH.innerHTML = `<span class="total-value">₱${numberFormat(data.overall_totals.col_h.toFixed(2))}</span>`;
                            
                            // Column I (index 8) - charge_to_customer - shows as 9th column (1-based)
                            const totalI = document.getElementById('total_9');
                            if (totalI) totalI.innerHTML = `<span class="total-value">₱${numberFormat(data.overall_totals.col_i.toFixed(2))}</span>`;
                            
                            // Column J (index 9) - charge_to_partner - shows as 10th column (1-based)
                            const totalJ = document.getElementById('total_10');
                            if (totalJ) totalJ.innerHTML = `<span class="total-value">₱${numberFormat(data.overall_totals.col_j.toFixed(2))}</span>`;
                            
                            tableFooter.style.display = 'table-footer-group';
                        }
                        
                        // Show empty branch ID warning with count only
                        if (data.empty_branch_id_count > 0) {
                            emptyBranchIdWarning.style.display = 'block';
                            emptyBranchIdMessage.textContent = data.empty_branch_id_count;
                        } else {
                            emptyBranchIdWarning.style.display = 'none';
                        }
                    }
                    
                    tableBody.innerHTML = html;
                    
                    // Show table
                    loadingIndicator.style.display = 'none';
                    previewTable.style.display = 'table';
                    
                    // Update pagination buttons
                    updatePaginationButtons(page);
                    
                    // Scroll to top of table
                    document.getElementById('previewTableContainer').scrollTop = 0;
                })
                .catch(error => {
                    console.error('Error loading preview page:', error);
                    loadingIndicator.innerHTML = '<p class="text-danger">Error loading preview data. Please try refreshing.</p>';
                });
        }

        function updatePaginationButtons(page) {
            const totalPages = <?= ceil(($preview_data['total_rows'] ?? 0) / PREVIEW_PER_PAGE) ?>;
            
            document.getElementById('firstPage').disabled = (page <= 1);
            document.getElementById('prevPage').disabled = (page <= 1);
            document.getElementById('nextPage').disabled = (page >= totalPages);
            document.getElementById('lastPage').disabled = (page >= totalPages);
            
            // Generate page number buttons
            const pageNumbersContainer = document.getElementById('pageNumbers');
            let html = '';
            
            // Show limited page numbers with ellipsis
            let startPage = Math.max(1, page - 2);
            let endPage = Math.min(totalPages, page + 2);
            
            if (startPage > 1) {
                html += `<button class="btn-page" onclick="goToPage(1)">1</button>`;
                if (startPage > 2) {
                    html += `<span class="btn-page disabled">...</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="btn-page ${i === page ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    html += `<span class="btn-page disabled">...</span>`;
                }
                html += `<button class="btn-page" onclick="goToPage(${totalPages})">${totalPages}</button>`;
            }
            
            pageNumbersContainer.innerHTML = html;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        window.scrollToTop = function() {
            document.getElementById('previewTableContainer').scrollTop = 0;
        };

        window.refreshPreview = function() {
            loadPreviewPage(currentPage);
        };

        // Auto-hide alerts after 10 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert:not(.alert-warning):not(.alert-info)').forEach(el => {
                el.style.transition = 'opacity .5s';
                el.style.opacity = '0';
                setTimeout(() => el.style.display = 'none', 500);
            });
        }, 10000);
    });
    </script>
</body>
</html>