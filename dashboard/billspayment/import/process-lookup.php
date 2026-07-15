<?php
require_once __DIR__ . '/../../../config/config.php';
header('Content-Type: application/json');

// Check authentication configuration safely
session_start();
if (!isset($_SESSION['user_type'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

if (isset($_POST['rows'])) {
    // Decode incoming rows
    $rows = json_decode($_POST['rows'], true);
    if (!is_array($rows)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid transaction items format']);
        exit;
    }

function bp_null_if_empty(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

   function bp_normalize_date(mixed $value): ?string
{
    $value = bp_null_if_empty($value);
    if ($value === null) {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

    function bp_normalize_datetime(mixed $value): ?string {
        $value = bp_null_if_empty($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    function bp_normalize_decimal(mixed $value): ?string {
        $value = bp_null_if_empty($value);
        if ($value === null) {
            return null;
        }

        $clean = str_replace([',', '₱', '$', ' '], '', $value);
        if (preg_match('/^\((.*)\)$/', $clean, $matches)) {
            $clean = '-' . $matches[1];
        }

        return is_numeric($clean) ? number_format((float)$clean, 2, '.', '') : null;
    }

    if (($_POST['action'] ?? '') === 'import') {
        $columns = [
            'status', 'billing_invoice', 'report_date', 'settlement_date', 'datetime', 'cancellation_date',
            'source_file', 'run_date', 'control_no', 'reference_no', 'payor', 'address', 'account_no', 'account_name',
            'amount_paid', 'charge_to_customer', 'charge_to_partner', 'contact_no', 'other_details',
            'branch_id', 'branch_code', 'outlet', 'zone_code', 'region_code', 'region_code_tg', 'region',
            'region_tg', 'operator', 'remote_branch', 'remote_operator', '2nd_approver', 'sub_billers_id',
            'sub_billers_name', 'partner_name', 'partner_id', 'partner_id_kpx', 'mpm_gl_code',
            'reason_for_adjustment', 'new_amount', 'deducted_amount', 'settle_unsettle', 'claim_unclaim',
            'imported_by', 'imported_date', 'rfp_no', 'cad_no', 'hold_status', 'post_transaction'
        ];

        $duplicateSql = "SELECT id FROM mldb.billspayment_transaction
            WHERE report_date <=> ?
              AND reference_no <=> ?
              AND cancellation_date <=> ?
              AND status <=> ?
              AND `datetime` <=> ?
              AND run_date <=> ?
            LIMIT 1";
        $duplicateStmt = $conn->prepare($duplicateSql);

        $columnList = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insertStmt = $conn->prepare("INSERT INTO mldb.billspayment_transaction ($columnList) VALUES ($placeholders)");

        if (!$duplicateStmt || !$insertStmt) {
            echo json_encode(['status' => 'error', 'message' => 'Unable to prepare import statements.']);
            exit;
        }

        $inserted = 0;
        $duplicates = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $reportDate = bp_normalize_date($row['report_date'] ?? null);
            $referenceNo = bp_null_if_empty($row['reference_no'] ?? null);
            $cancellationDate = bp_normalize_datetime($row['cancellation_date'] ?? null);
            $statusValue = bp_null_if_empty($row['status'] ?? null);
            $datetimeValue = bp_normalize_datetime($row['datetime'] ?? null);
            $runDateValue = bp_normalize_datetime($row['run_date'] ?? null);

            $duplicateKey = implode('|', [
                $reportDate ?? 'NULL',
                $referenceNo ?? 'NULL',
                $cancellationDate ?? 'NULL',
                $statusValue ?? 'NULL',
                $datetimeValue ?? 'NULL',
                $runDateValue ?? 'NULL'
            ]);

            if (isset($seen[$duplicateKey])) {
                $duplicates[] = ['row' => $index + 1, 'reference_no' => $referenceNo, 'type' => 'file'];
                continue;
            }
            $seen[$duplicateKey] = true;

            $duplicateStmt->bind_param('ssssss', $reportDate, $referenceNo, $cancellationDate, $statusValue, $datetimeValue, $runDateValue);
            $duplicateStmt->execute();
            $duplicateResult = $duplicateStmt->get_result();
            if ($duplicateResult && $duplicateResult->num_rows > 0) {
                $duplicates[] = ['row' => $index + 1, 'reference_no' => $referenceNo, 'type' => 'database'];
                continue;
            }

            $values = [];
            foreach ($columns as $column) {
                $key = $column === '2nd_approver' ? 'second_approver' : $column;
                $value = $row[$key] ?? null;

                if (in_array($column, ['report_date', 'settlement_date'], true)) {
                    $value = bp_normalize_date($value);
                } elseif (in_array($column, ['datetime', 'cancellation_date', 'run_date'], true)) {
                    $value = bp_normalize_datetime($value);
                } elseif (in_array($column, ['amount_paid', 'charge_to_customer', 'charge_to_partner', 'new_amount', 'deducted_amount'], true)) {
                    $value = bp_normalize_decimal($value);
                } else {
                    $value = bp_null_if_empty($value);
                }

                $values[] = $value;
            }

            $types = str_repeat('s', count($values));
            $bindValues = [$types];
            foreach ($values as $valueIndex => &$valueRef) {
                $bindValues[] = &$valueRef;
            }
            call_user_func_array([$insertStmt, 'bind_param'], $bindValues);
            unset($valueRef);

            if ($insertStmt->execute()) {
                $inserted++;
            } else {
                $errors[] = ['row' => $index + 1, 'reference_no' => $referenceNo, 'message' => $insertStmt->error];
            }
        }

        $duplicateStmt->close();
        $insertStmt->close();

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
    $isKP7 = isset($_POST['is_kp7']) && $_POST['is_kp7'] == 1;
    $processedRows = [];

    foreach ($rows as $row) {
        $isKP7 = strtoupper(trim((string)($row['source_file'] ?? ''))) === 'KP7';
        $branch_id = $row['branch_id'] ?? '';
        $ml_matic_branch_name = $row['ml_matic_branch_name'] ?? '';
        $region_value = $row['region_value'] ?? '';
        $partner_id = $row['partner_id'] ?? '';
        $partner_id_kpx = $row['partner_id_kpx'] ?? '';
        $region_code_tg = $row['region_code_tg'] ?? '';

        // Initialize with default values
        $branch_code = null;
        $outlet = null;
        $zone_code = null;
        $region_code = null;
        $region = null;
        $resolved_branch_id = null;

        // 1. Fetch values from masterdata.branch_profile
        if ($isKP7) {
            // KP7: Find branch_id using ml_matic_branch_name and region
            if (!empty($ml_matic_branch_name) || !empty($region_value)) {
                try {
                    $found = false;
                    
                    if (!empty($ml_matic_branch_name)) {
                        $normalized_name = strtoupper(trim($ml_matic_branch_name));
                        $normalized_name = preg_replace('/\s+/', ' ', $normalized_name);
                        
                        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                               FROM masterdata.branch_profile 
                                               WHERE REPLACE(UPPER(ml_matic_branch_name), '  ', ' ') = ? 
                                               LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("s", $normalized_name);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            if ($data = $res->fetch_assoc()) {
                                $resolved_branch_id = $data['branch_id'];
                                $branch_code = $data['code'];
                                $outlet = $data['ml_matic_branch_name'];
                                $zone_code = $data['zone'];
                                $region_code = $data['region_code'];
                                $region = $data['gl_region'];
                                $found = true;
                            }
                            $stmt->close();
                        }
                    }
                    
                    if (!$found && !empty($region_value)) {
                        $normalized_region = strtoupper(trim($region_value));
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
                                $resolved_branch_id = $data['branch_id'];
                                $branch_code = $data['code'];
                                $outlet = $data['ml_matic_branch_name'];
                                $zone_code = $data['zone'];
                                $region_code = $data['region_code'];
                                $region = $data['gl_region'];
                                $found = true;
                            }
                            $stmt->close();
                        }
                    }
                    
                    if (!$found && !empty($ml_matic_branch_name)) {
                        $search_name = '%' . strtoupper(trim($ml_matic_branch_name)) . '%';
                        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                               FROM masterdata.branch_profile 
                                               WHERE UPPER(ml_matic_branch_name) LIKE ? 
                                               LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("s", $search_name);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            if ($data = $res->fetch_assoc()) {
                                $resolved_branch_id = $data['branch_id'];
                                $branch_code = $data['code'];
                                $outlet = $data['ml_matic_branch_name'];
                                $zone_code = $data['zone'];
                                $region_code = $data['region_code'];
                                $region = $data['gl_region'];
                                $found = true;
                            }
                            $stmt->close();
                        }
                    }
                    
                    if (!$found) {
                        // Check if region_code_tg is '32', then use branch_id '581'
                        if (!empty($region_code_tg) && trim($region_code_tg) === '32') {
                            try {
                                $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                                       FROM masterdata.branch_profile 
                                                       WHERE branch_id = '581' 
                                                       LIMIT 1");
                                if ($stmt) {
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    if ($data = $res->fetch_assoc()) {
                                        $resolved_branch_id = $data['branch_id'];
                                        $branch_code = $data['code'];
                                        $outlet = $data['ml_matic_branch_name'];
                                        $zone_code = $data['zone'];
                                        $region_code = $data['region_code'];
                                        $region = $data['gl_region'];
                                        $found = true;
                                    }
                                    $stmt->close();
                                }
                            } catch (Exception $e) {
                                error_log("Branch lookup for region 32 error: " . $e->getMessage());
                            }
                        }
                        
                        if (!$found) {
                            $resolved_branch_id = 'Not Found';
                            $branch_code = 'Not Found';
                            $outlet = 'Not Found';
                            $zone_code = 'Not Found';
                            $region_code = 'Not Found';
                            $region = 'Not Found';
                        }
                    }
                    
                } catch (Exception $e) {
                    error_log("Branch lookup error: " . $e->getMessage());
                    $resolved_branch_id = 'Not Found';
                    $branch_code = 'Not Found';
                    $outlet = 'Not Found';
                    $zone_code = 'Not Found';
                    $region_code = 'Not Found';
                    $region = 'Not Found';
                }
            } else {
                // No ml_matic_branch_name or region_value provided
                // Check if region_code_tg is '32', then use branch_id '581'
                if (!empty($region_code_tg) && trim($region_code_tg) === '32') {
                    try {
                        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                               FROM masterdata.branch_profile 
                                               WHERE branch_id = '581' 
                                               LIMIT 1");
                        if ($stmt) {
                            $stmt->execute();
                            $res = $stmt->get_result();
                            if ($data = $res->fetch_assoc()) {
                                $resolved_branch_id = $data['branch_id'];
                                $branch_code = $data['code'];
                                $outlet = $data['ml_matic_branch_name'];
                                $zone_code = $data['zone'];
                                $region_code = $data['region_code'];
                                $region = $data['gl_region'];
                            } else {
                                $resolved_branch_id = 'Not Found';
                                $branch_code = 'Not Found';
                                $outlet = 'Not Found';
                                $zone_code = 'Not Found';
                                $region_code = 'Not Found';
                                $region = 'Not Found';
                            }
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        error_log("Branch lookup for region 32 error: " . $e->getMessage());
                        $resolved_branch_id = 'Not Found';
                        $branch_code = 'Not Found';
                        $outlet = 'Not Found';
                        $zone_code = 'Not Found';
                        $region_code = 'Not Found';
                        $region = 'Not Found';
                    }
                } else {
                    $resolved_branch_id = 'Not Found';
                    $branch_code = 'Not Found';
                    $outlet = 'Not Found';
                    $zone_code = 'Not Found';
                    $region_code = 'Not Found';
                    $region = 'Not Found';
                }
            }
        } else {
            // KPX: Use branch_id directly
            if (!empty($branch_id) && $branch_id !== 'Not Found' && $branch_id !== '') {
                $clean_branch_id = trim($branch_id);
                
                if (!empty($clean_branch_id)) {
                    try {
                        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                               FROM masterdata.branch_profile WHERE branch_id = ? LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("s", $clean_branch_id);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            if ($data = $res->fetch_assoc()) {
                                $resolved_branch_id = $data['branch_id'];
                                $branch_code = $data['code'];
                                $outlet = $data['ml_matic_branch_name'];
                                $zone_code = $data['zone'];
                                $region_code = $data['region_code'];
                                $region = $data['gl_region'];
                            } else {
                                // Check if region_code_tg is '32', then use branch_id '581'
                                if (!empty($region_code_tg) && trim($region_code_tg) === '32') {
                                    try {
                                        $stmt2 = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                                               FROM masterdata.branch_profile 
                                                               WHERE branch_id = '581' 
                                                               LIMIT 1");
                                        if ($stmt2) {
                                            $stmt2->execute();
                                            $res2 = $stmt2->get_result();
                                            if ($data2 = $res2->fetch_assoc()) {
                                                $resolved_branch_id = $data2['branch_id'];
                                                $branch_code = $data2['code'];
                                                $outlet = $data2['ml_matic_branch_name'];
                                                $zone_code = $data2['zone'];
                                                $region_code = $data2['region_code'];
                                                $region = $data2['gl_region'];
                                            } else {
                                                $resolved_branch_id = 'Not Found';
                                                $branch_code = 'Not Found';
                                                $outlet = 'Not Found';
                                                $zone_code = 'Not Found';
                                                $region_code = 'Not Found';
                                                $region = 'Not Found';
                                            }
                                            $stmt2->close();
                                        }
                                    } catch (Exception $e) {
                                        error_log("Branch lookup for region 32 error: " . $e->getMessage());
                                        $resolved_branch_id = 'Not Found';
                                        $branch_code = 'Not Found';
                                        $outlet = 'Not Found';
                                        $zone_code = 'Not Found';
                                        $region_code = 'Not Found';
                                        $region = 'Not Found';
                                    }
                                } else {
                                    $resolved_branch_id = 'Not Found';
                                    $branch_code = 'Not Found';
                                    $outlet = 'Not Found';
                                    $zone_code = 'Not Found';
                                    $region_code = 'Not Found';
                                    $region = 'Not Found';
                                }
                            }
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        error_log("Branch lookup error: " . $e->getMessage());
                        $resolved_branch_id = 'Not Found';
                        $branch_code = 'Not Found';
                        $outlet = 'Not Found';
                        $zone_code = 'Not Found';
                        $region_code = 'Not Found';
                        $region = 'Not Found';
                    }
                }
            } else {
                // Empty branch_id - check if region_code_tg is '32'
                if (!empty($region_code_tg) && trim($region_code_tg) === '32') {
                    try {
                        $stmt = $conn->prepare("SELECT branch_id, code, ml_matic_branch_name, zone, region_code, gl_region 
                                               FROM masterdata.branch_profile 
                                               WHERE branch_id = '581' 
                                               LIMIT 1");
                        if ($stmt) {
                            $stmt->execute();
                            $res = $stmt->get_result();
                            if ($data = $res->fetch_assoc()) {
                                $resolved_branch_id = $data['branch_id'];
                                $branch_code = $data['code'];
                                $outlet = $data['ml_matic_branch_name'];
                                $zone_code = $data['zone'];
                                $region_code = $data['region_code'];
                                $region = $data['gl_region'];
                            } else {
                                $resolved_branch_id = 'Not Found';
                                $branch_code = 'Not Found';
                                $outlet = 'Not Found';
                                $zone_code = 'Not Found';
                                $region_code = 'Not Found';
                                $region = 'Not Found';
                            }
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        error_log("Branch lookup for region 32 error: " . $e->getMessage());
                        $resolved_branch_id = 'Not Found';
                        $branch_code = 'Not Found';
                        $outlet = 'Not Found';
                        $zone_code = 'Not Found';
                        $region_code = 'Not Found';
                        $region = 'Not Found';
                    }
                } else {
                    $resolved_branch_id = 'Not Found';
                    $branch_code = 'Not Found';
                    $outlet = 'Not Found';
                    $zone_code = 'Not Found';
                    $region_code = 'Not Found';
                    $region = 'Not Found';
                }
            }
        }

        // 2. Fetch values from masterdata.partner_masterfile
        $partner_name = null;
        $resolved_partner_id = null;
        $resolved_partner_id_kpx = null;
        $mpm_gl_code = null;

        if ($isKP7) {
            // KP7: Use partner_id from column S
            if (!empty($partner_id) && $partner_id !== 'Not Found' && $partner_id !== '') {
                $clean_partner_id = trim($partner_id);
                
                if (!empty($clean_partner_id)) {
                    try {
                        $stmt2 = $conn->prepare("SELECT partner_id, partner_id_kpx, partner_name, gl_code 
                                                FROM masterdata.partner_masterfile 
                                                WHERE partner_id = ? LIMIT 1");
                        if ($stmt2) {
                            $stmt2->bind_param("s", $clean_partner_id);
                            $stmt2->execute();
                            $res2 = $stmt2->get_result();
                            if ($pData = $res2->fetch_assoc()) {
                                $resolved_partner_id = $pData['partner_id'];
                                $resolved_partner_id_kpx = $pData['partner_id_kpx'];
                                $partner_name = $pData['partner_name'];
                                $mpm_gl_code = $pData['gl_code'];
                            } else {
                                $resolved_partner_id = $clean_partner_id;
                                $resolved_partner_id_kpx = 'Not Found';
                                $partner_name = 'Not Found';
                                $mpm_gl_code = 'Not Found';
                            }
                            $stmt2->close();
                        }
                    } catch (Exception $e) {
                        error_log("Partner lookup error: " . $e->getMessage());
                        $resolved_partner_id = $partner_id;
                        $resolved_partner_id_kpx = 'Not Found';
                        $partner_name = 'Not Found';
                        $mpm_gl_code = 'Not Found';
                    }
                }
            } else {
                $resolved_partner_id = 'Not Found';
                $resolved_partner_id_kpx = 'Not Found';
                $partner_name = 'Not Found';
                $mpm_gl_code = 'Not Found';
            }
        } else {
            // KPX: Use partner_id_kpx
            if (!empty($partner_id_kpx) && $partner_id_kpx !== 'Not Found' && $partner_id_kpx !== '') {
                $clean_partner_id_kpx = trim($partner_id_kpx);
                
                if (!empty($clean_partner_id_kpx)) {
                    try {
                        $stmt2 = $conn->prepare("SELECT partner_id, partner_id_kpx, partner_name, gl_code 
                                                FROM masterdata.partner_masterfile 
                                                WHERE partner_id_kpx = ? LIMIT 1");
                        if ($stmt2) {
                            $stmt2->bind_param("s", $clean_partner_id_kpx);
                            $stmt2->execute();
                            $res2 = $stmt2->get_result();
                            if ($pData = $res2->fetch_assoc()) {
                                $resolved_partner_id = $pData['partner_id'];
                                $resolved_partner_id_kpx = $pData['partner_id_kpx'];
                                $partner_name = $pData['partner_name'];
                                $mpm_gl_code = $pData['gl_code'];
                            } else {
                                // Partner not found in database - set all to Not Found
                                $resolved_partner_id = 'Not Found';
                                $resolved_partner_id_kpx = $clean_partner_id_kpx; // Keep original value
                                $partner_name = 'Not Found';
                                $mpm_gl_code = 'Not Found';
                            }
                            $stmt2->close();
                        }
                    } catch (Exception $e) {
                        error_log("Partner lookup error: " . $e->getMessage());
                        $resolved_partner_id = 'Not Found';
                        $resolved_partner_id_kpx = $partner_id_kpx;
                        $partner_name = 'Not Found';
                        $mpm_gl_code = 'Not Found';
                    }
                }
            } else {
                // Empty partner_id_kpx
                $resolved_partner_id = 'Not Found';
                $resolved_partner_id_kpx = $partner_id_kpx ?: 'Not Found';
                $partner_name = 'Not Found';
                $mpm_gl_code = 'Not Found';
            }
        }

        // Apply dynamic query matches to the existing row structure
        $row['branch_id'] = $resolved_branch_id;
        $row['branch_code'] = $branch_code;
        $row['outlet'] = $outlet;
        $row['zone_code'] = $zone_code;
        $row['region_code'] = $region_code;
        $row['region'] = $region;
        
        $row['partner_id'] = $resolved_partner_id;
        $row['partner_id_kpx'] = $resolved_partner_id_kpx;
        $row['partner_name'] = $partner_name;
        $row['mpm_gl_code'] = $mpm_gl_code;

        // Preserve run_date if it exists
        $row['run_date'] = $row['run_date'] ?? null;

        // For KP7, clear the region_value and ml_matic_branch_name fields as they're only used for lookup
        if ($isKP7) {
            unset($row['ml_matic_branch_name']);
            unset($row['region_value']);
        }

        $processedRows[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $processedRows]);
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => 'No dataset provided.']);
    exit;
}
?>