<?php
require_once __DIR__ . '/../../../config/config.php';
header('Content-Type: application/json');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/import_errors.log');

// Check authentication configuration safely
session_start();
if (!isset($_SESSION['user_type'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

// Check database connection
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Helper function to lookup branch by ID
function lookupBranchById($conn, $branchId) {
    try {
        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                               FROM masterdata.branch_profile WHERE branch_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $branchId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($data = $res->fetch_assoc()) {
                $stmt->close();
                return $data;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Branch lookup error: " . $e->getMessage());
    }
    return null;
}

// Helper function to lookup branch by name
function lookupBranchByName($conn, $name) {
    try {
        $normalized_name = strtoupper(trim($name));
        $normalized_name = preg_replace('/\s+/', ' ', $normalized_name);
        
        // First try exact match
        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                               FROM masterdata.branch_profile 
                               WHERE REPLACE(UPPER(ml_matic_branch_name), '  ', ' ') = ? 
                               LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $normalized_name);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($data = $res->fetch_assoc()) {
                $stmt->close();
                return $data;
            }
            $stmt->close();
        }
        
        // Try partial match
        $search_name = '%' . $normalized_name . '%';
        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                               FROM masterdata.branch_profile 
                               WHERE UPPER(ml_matic_branch_name) LIKE ? 
                               LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $search_name);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($data = $res->fetch_assoc()) {
                $stmt->close();
                return $data;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Branch lookup by name error: " . $e->getMessage());
    }
    return null;
}

// Helper function to lookup branch by region
function lookupBranchByRegion($conn, $region) {
    try {
        $normalized_region = strtoupper(trim($region));
        $normalized_region = preg_replace('/\s+/', ' ', $normalized_region);
        
        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                               FROM masterdata.branch_profile 
                               WHERE REPLACE(UPPER(gl_region), '  ', ' ') = ? 
                               LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $normalized_region);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($data = $res->fetch_assoc()) {
                $stmt->close();
                return $data;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Branch lookup by region error: " . $e->getMessage());
    }
    return null;
}

// Helper function to get branch 581 data (fallback for region 32)
function getBranch581($conn) {
    static $branch581 = null;
    if ($branch581 !== null) {
        return $branch581;
    }
    
    try {
        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                               FROM masterdata.branch_profile 
                               WHERE branch_id = '581' 
                               LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            if ($data = $res->fetch_assoc()) {
                $stmt->close();
                $branch581 = $data;
                return $data;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Branch 581 lookup error: " . $e->getMessage());
    }
    return null;
}

// Helper function to get Not Found values
function getNotFoundBranchData() {
    return [
        'branch_id' => 'Not Found',
        'code' => 'Not Found',
        'ml_matic_branch_name' => 'Not Found',
        'zone' => 'Not Found',
        'region_code' => 'Not Found',
        'gl_region' => 'Not Found'
    ];
}

// Helper function to resolve branch for a row
function resolveBranch($conn, $row, $isKP7) {
    $defaultNotFound = getNotFoundBranchData();
    $branchData = $defaultNotFound;
    
    // First check if region_code_tg is '32' - this overrides everything
    $regionCodeTg = isset($row['region_code_tg']) ? trim($row['region_code_tg']) : '';
    if ($regionCodeTg === '32') {
        $branchData = getBranch581($conn);
        if ($branchData) {
            return [
                'branch_id' => $branchData['branch_id'],
                'branch_code' => $branchData['code'],
                'outlet' => $branchData['ml_matic_branch_name'],
                'zone_code' => $branchData['zone'],
                'region_code' => $branchData['region_code'],
                'region' => $branchData['gl_region']
            ];
        }
        // If branch 581 not found, use Not Found
        return [
            'branch_id' => 'Not Found',
            'branch_code' => 'Not Found',
            'outlet' => 'Not Found',
            'zone_code' => 'Not Found',
            'region_code' => 'Not Found',
            'region' => 'Not Found'
        ];
    }
    
    if ($isKP7) {
        // KP7: Use ml_matic_branch_name and region_value
        $ml_matic_branch_name = isset($row['ml_matic_branch_name']) ? trim($row['ml_matic_branch_name']) : '';
        $region_value = isset($row['region_value']) ? trim($row['region_value']) : '';
        
        if (!empty($ml_matic_branch_name)) {
            $data = lookupBranchByName($conn, $ml_matic_branch_name);
            if ($data) {
                $branchData = $data;
            }
        }
        
        // If not found by name, try by region
        if ($branchData === $defaultNotFound && !empty($region_value)) {
            $data = lookupBranchByRegion($conn, $region_value);
            if ($data) {
                $branchData = $data;
            }
        }
    } else {
        // KPX: Use branch_id directly
        $branch_id = isset($row['branch_id']) ? trim($row['branch_id']) : '';
        if (!empty($branch_id) && $branch_id !== 'Not Found' && $branch_id !== '') {
            $data = lookupBranchById($conn, $branch_id);
            if ($data) {
                $branchData = $data;
            }
        }
    }
    
    return [
        'branch_id' => $branchData['branch_id'],
        'branch_code' => $branchData['code'],
        'outlet' => $branchData['ml_matic_branch_name'],
        'zone_code' => $branchData['zone'],
        'region_code' => $branchData['region_code'],
        'region' => $branchData['gl_region']
    ];
}

// Helper function to resolve partner for a row
function resolvePartner($conn, $row, $isKP7) {
    $result = [
        'partner_id' => 'Not Found',
        'partner_id_kpx' => 'Not Found',
        'partner_name' => 'Not Found',
        'mpm_gl_code' => 'Not Found'
    ];
    
    try {
        if ($isKP7) {
            // KP7: Use partner_id
            $partner_id = isset($row['partner_id']) ? trim($row['partner_id']) : '';
            if (!empty($partner_id) && $partner_id !== 'Not Found' && $partner_id !== '') {
                $stmt = $conn->prepare("SELECT partner_id, partner_id_kpx, partner_name, gl_code 
                                       FROM masterdata.partner_masterfile 
                                       WHERE partner_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $partner_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($pData = $res->fetch_assoc()) {
                        $result = [
                            'partner_id' => $pData['partner_id'],
                            'partner_id_kpx' => $pData['partner_id_kpx'],
                            'partner_name' => $pData['partner_name'],
                            'mpm_gl_code' => $pData['gl_code']
                        ];
                    } else {
                        $result['partner_id'] = $partner_id;
                    }
                    $stmt->close();
                }
            }
        } else {
            // KPX: Use partner_id_kpx
            $partner_id_kpx = isset($row['partner_id_kpx']) ? trim($row['partner_id_kpx']) : '';
            if (!empty($partner_id_kpx) && $partner_id_kpx !== 'Not Found' && $partner_id_kpx !== '') {
                $stmt = $conn->prepare("SELECT partner_id, partner_id_kpx, partner_name, gl_code 
                                       FROM masterdata.partner_masterfile 
                                       WHERE partner_id_kpx = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $partner_id_kpx);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($pData = $res->fetch_assoc()) {
                        $result = [
                            'partner_id' => $pData['partner_id'],
                            'partner_id_kpx' => $pData['partner_id_kpx'],
                            'partner_name' => $pData['partner_name'],
                            'mpm_gl_code' => $pData['gl_code']
                        ];
                    } else {
                        $result['partner_id_kpx'] = $partner_id_kpx;
                    }
                    $stmt->close();
                }
            }
        }
    } catch (Exception $e) {
        error_log("Partner lookup error: " . $e->getMessage());
    }
    
    return $result;
}

// Helper function to normalize values for database
function bp_null_if_empty($value) {
    if ($value === null) return null;
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '' || $value === 'NULL' || $value === 'null' || $value === 'Not Found') {
            return null;
        }
        return $value;
    }
    return $value;
}

function bp_normalize_date($value) {
    $value = bp_null_if_empty($value);
    if ($value === null) return null;
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function bp_normalize_datetime($value) {
    $value = bp_null_if_empty($value);
    if ($value === null) return null;
    
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return $value;
    }
    
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function bp_normalize_decimal($value) {
    $value = bp_null_if_empty($value);
    if ($value === null) return null;
    
    if (is_numeric($value)) {
        return number_format((float)$value, 2, '.', '');
    }
    
    if (is_string($value)) {
        $clean = str_replace([',', '₱', '$', ' ', 'PHP'], '', $value);
        if (preg_match('/^\((.*)\)$/', $clean, $matches)) {
            $clean = '-' . $matches[1];
        }
        if (is_numeric($clean)) {
            return number_format((float)$clean, 2, '.', '');
        }
    }
    
    return null;
}

// Main processing
if (isset($_POST['rows'])) {
    // Decode incoming rows
    $rows = json_decode($_POST['rows'], true);
    if (!is_array($rows)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid transaction items format']);
        exit;
    }

    // Handle import action
    if (($_POST['action'] ?? '') === 'import') {
        error_log("=== STARTING IMPORT ===");
        error_log("Total rows to import: " . count($rows));
        
        // REMOVED: reason_for_adjustment, new_amount, deducted_amount - these columns don't exist
        $columns = [
            'status', 'billing_invoice', 'report_date', 'settlement_date', 'datetime', 'cancellation_date',
            'source_file', 'run_date', 'control_no', 'reference_no', 'payor', 'address', 'account_no', 'account_name',
            'amount_paid', 'charge_to_customer', 'charge_to_partner', 'contact_no', 'other_details',
            'branch_id', 'branch_code', 'outlet', 'zone_code', 'region_code', 'region_code_tg', 'region',
            'region_tg', 'operator', 'remote_branch', 'remote_operator', '2nd_approver', 'sub_billers_id',
            'sub_billers_name', 'partner_name', 'partner_id', 'partner_id_kpx', 'mpm_gl_code',
            'settle_unsettle', 'claim_unclaim',
            'imported_by', 'imported_date', 'rfp_no', 'cad_no', 'hold_status', 'post_transaction'
        ];

        // Prepare duplicate check statement
        $duplicateSql = "SELECT id FROM mldb.billspayment_transaction 
            WHERE report_date = ? 
              AND reference_no = ? 
              AND (cancellation_date = ? OR (cancellation_date IS NULL AND ? IS NULL))
              AND (status = ? OR (status IS NULL AND ? IS NULL))
              AND datetime = ? 
              AND (run_date = ? OR (run_date IS NULL AND ? IS NULL))
            LIMIT 1";
        
        $duplicateStmt = $conn->prepare($duplicateSql);
        if (!$duplicateStmt) {
            error_log("Failed to prepare duplicate statement: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            exit;
        }

        // Prepare insert statement
        $columnList = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insertSql = "INSERT INTO mldb.billspayment_transaction ($columnList) VALUES ($placeholders)";
        $insertStmt = $conn->prepare($insertSql);
        
        if (!$insertStmt) {
            error_log("Failed to prepare insert statement: " . $conn->error);
            error_log("SQL: " . $insertSql);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            exit;
        }

        $inserted = 0;
        $duplicates = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            // Normalize values
            $reportDate = bp_normalize_date($row['report_date'] ?? null);
            $referenceNo = bp_null_if_empty($row['reference_no'] ?? null);
            $cancellationDate = bp_normalize_datetime($row['cancellation_date'] ?? null);
            $statusValue = bp_null_if_empty($row['status'] ?? null);
            $datetimeValue = bp_normalize_datetime($row['datetime'] ?? null);
            $runDateValue = bp_normalize_datetime($row['run_date'] ?? null);

            // Create duplicate key for file-level checking
            $duplicateKey = implode('|', [
                $reportDate ?? 'NULL',
                $referenceNo ?? 'NULL',
                $cancellationDate ?? 'NULL',
                $statusValue ?? 'NULL',
                $datetimeValue ?? 'NULL',
                $runDateValue ?? 'NULL'
            ]);

            // Check file-level duplicates
            if (isset($seen[$duplicateKey])) {
                $duplicates[] = ['row' => $index + 1, 'reference_no' => $referenceNo, 'type' => 'file'];
                continue;
            }
            $seen[$duplicateKey] = true;

            // Check database duplicates
            $dupReportDate = $reportDate ?? '';
            $dupReferenceNo = $referenceNo ?? '';
            $dupCancellationDate = $cancellationDate ?? '';
            $dupCancellationDateNull = $cancellationDate === null ? null : '';
            $dupStatus = $statusValue ?? '';
            $dupStatusNull = $statusValue === null ? null : '';
            $dupDatetime = $datetimeValue ?? '';
            $dupRunDate = $runDateValue ?? '';
            $dupRunDateNull = $runDateValue === null ? null : '';

            $duplicateStmt->bind_param(
                'sssssssss',
                $dupReportDate,
                $dupReferenceNo,
                $dupCancellationDate,
                $dupCancellationDateNull,
                $dupStatus,
                $dupStatusNull,
                $dupDatetime,
                $dupRunDate,
                $dupRunDateNull
            );

            if (!$duplicateStmt->execute()) {
                error_log("Duplicate check failed for row " . ($index + 1) . ": " . $duplicateStmt->error);
                $errors[] = ['row' => $index + 1, 'reference_no' => $referenceNo, 'message' => 'Duplicate check failed'];
                continue;
            }

            $duplicateResult = $duplicateStmt->get_result();
            if ($duplicateResult && $duplicateResult->num_rows > 0) {
                $duplicates[] = ['row' => $index + 1, 'reference_no' => $referenceNo, 'type' => 'database'];
                continue;
            }

            // Build values for insert
            $values = [];
            foreach ($columns as $column) {
                $key = $column === '2nd_approver' ? 'second_approver' : $column;
                $value = $row[$key] ?? null;

                if (in_array($column, ['report_date', 'settlement_date'], true)) {
                    $value = bp_normalize_date($value);
                } elseif (in_array($column, ['datetime', 'cancellation_date', 'run_date'], true)) {
                    $value = bp_normalize_datetime($value);
                } elseif (in_array($column, ['amount_paid', 'charge_to_customer', 'charge_to_partner'], true)) {
                    $value = bp_normalize_decimal($value);
                } else {
                    $value = bp_null_if_empty($value);
                }

                $values[] = $value;
            }

            // Bind parameters for insert
            $types = str_repeat('s', count($values));
            $bindParams = [$types];
            foreach ($values as $key => &$valueRef) {
                $bindParams[] = &$valueRef;
            }
            call_user_func_array([$insertStmt, 'bind_param'], $bindParams);
            unset($valueRef);

            // Execute insert
            if ($insertStmt->execute()) {
                $inserted++;
            } else {
                error_log("Insert failed for row " . ($index + 1) . ": " . $insertStmt->error);
                error_log("Row data: " . json_encode($values));
                $errors[] = [
                    'row' => $index + 1, 
                    'reference_no' => $referenceNo, 
                    'message' => $insertStmt->error
                ];
            }
        }

        $duplicateStmt->close();
        $insertStmt->close();

        error_log("Import completed. Inserted: $inserted, Duplicates: " . count($duplicates) . ", Errors: " . count($errors));

        echo json_encode([
            'status' => empty($errors) ? 'success' : 'partial',
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'duplicate_count' => count($duplicates),
            'errors' => $errors,
            'error_count' => count($errors)
        ]);
        exit;
    }
    
    // Handle lookup action (preview)
    error_log("=== STARTING LOOKUP ===");
    error_log("Total rows to lookup: " . count($rows));
    
    $processedRows = [];

    foreach ($rows as $index => $row) {
        $isKP7 = strtoupper(trim((string)($row['source_file'] ?? ''))) === 'KP7';
        
        // Resolve branch
        $branchData = resolveBranch($conn, $row, $isKP7);
        
        // Resolve partner
        $partnerData = resolvePartner($conn, $row, $isKP7);
        
        // Apply resolved data to row
        $row['branch_id'] = $branchData['branch_id'];
        $row['branch_code'] = $branchData['branch_code'];
        $row['outlet'] = $branchData['outlet'];
        $row['zone_code'] = $branchData['zone_code'];
        $row['region_code'] = $branchData['region_code'];
        $row['region'] = $branchData['region'];
        
        $row['partner_id'] = $partnerData['partner_id'];
        $row['partner_id_kpx'] = $partnerData['partner_id_kpx'];
        $row['partner_name'] = $partnerData['partner_name'];
        $row['mpm_gl_code'] = $partnerData['mpm_gl_code'];
        
        // Preserve run_date if it exists
        $row['run_date'] = $row['run_date'] ?? null;
        
        // For KP7, clear the lookup-only fields
        if ($isKP7) {
            unset($row['ml_matic_branch_name']);
            unset($row['region_value']);
        }

        $processedRows[] = $row;
    }

    error_log("Lookup completed. Processed: " . count($processedRows) . " rows");

    echo json_encode(['status' => 'success', 'data' => $processedRows]);
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => 'No dataset provided.']);
    exit;
}
?>