<?php
// export_bank_settlement_pdf.php
// Add cache control headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Settlement Per Bank','Bills Payment'])) { header('Location: ../../home.php'); exit; }

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// FUNCTION: Get bank abbreviation from database only
// ============================================
function getBankAbbreviation(mysqli $conn, string $bank_name): string {
    if (empty($bank_name)) {
        return '';
    }

    $bank_name = trim($bank_name);
    $bank_name_upper = strtoupper($bank_name);

    // Try 1: exact match in mldb.bank_table
    $query = "SELECT bank_abbreviation FROM mldb.bank_table WHERE bank_name = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['bank_abbreviation'])) {
                $stmt->close();
                return strtoupper(trim($row['bank_abbreviation']));
            }
        }
        $stmt->close();
    }

    // Try 2: LIKE match in mldb.bank_table (more tolerant)
    $query = "SELECT bank_abbreviation FROM mldb.bank_table 
              WHERE UPPER(bank_name) LIKE CONCAT('%', UPPER(?), '%') 
                 OR UPPER(?) LIKE CONCAT('%', UPPER(bank_name), '%')
              LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ss", $bank_name, $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['bank_abbreviation'])) {
                $stmt->close();
                return strtoupper(trim($row['bank_abbreviation']));
            }
        }
        $stmt->close();
    }

    // Try 3: partner_masterfile exact + LIKE
    $query2 = "SELECT DISTINCT bank_abbreviation FROM masterdata.partner_masterfile 
               WHERE (bank = ? OR UPPER(bank) LIKE CONCAT('%', UPPER(?), '%'))
                 AND bank_abbreviation IS NOT NULL AND bank_abbreviation != '' 
               LIMIT 1";
    $stmt2 = $conn->prepare($query2);
    if ($stmt2) {
        $stmt2->bind_param("ss", $bank_name, $bank_name);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($row2 = $result2->fetch_assoc()) {
            if (!empty($row2['bank_abbreviation'])) {
                $stmt2->close();
                return strtoupper(trim($row2['bank_abbreviation']));
            }
        }
        $stmt2->close();
    }

    // If no abbreviation found, return empty string
    return '';
}

// ============================================
// FUNCTION: Get bank for a partner
// ============================================
function getPartnerBank(mysqli $conn, string $partner_id): string {
    if (empty($partner_id)) {
        return '';
    }
    
    $query = "SELECT bank FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $partner_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return trim($row['bank'] ?? '');
        }
        $stmt->close();
    }
    return '';
}

// ============================================
// FUNCTION: Get settlement type abbreviation
// ============================================
function getSettlementAbbreviation(string $settlement_type): string {
    if (empty($settlement_type)) return '';
    $type = strtoupper(trim($settlement_type));
    if ($type === 'CHECK' || $type === 'CHEQUE') return 'CHK';
    if ($type === 'ONLINE' || $type === 'ONL') return 'ONL';
    return strtoupper(substr($type, 0, 3));
}

// ============================================
// FUNCTION: Normalize charge_sched value
// DAILY and PER TRANSACTION are treated the same (DAILY)
// Empty / NOT APPLICABLE / NO-BANK-SETTLEMENT => '' (uncategorized)
// ============================================
function normalizeChargeSched(?string $charge_sched): string {
    $value = strtoupper(trim((string)$charge_sched));

    if ($value === '' || $value === 'NOT APPLICABLE' || $value === 'NO-BANK-SETTLEMENT') {
        return '';
    }
    if ($value === 'PER TRANSACTION') {
        return 'DAILY';
    }
    return $value; // DAILY, WEEKLY, MONTHLY, SEMI-MONTHLY
}

// ============================================
// FUNCTION: Format date for CAD number (YYYY-MM-000DD)
// ============================================
function formatCADDate(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    }

    $date = !empty($date_to) ? $date_to : $date_from;
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    }
    return date('Y-m', $timestamp) . '-' . sprintf('%05d', (int)date('d', $timestamp));
}

// ============================================
// FUNCTION: Calculate settlement amount based on charge type
// NOTE: The 2nd parameter is now $charge_sched (previously $service_charge).
// Uses normalizeChargeSched() so DAILY and PER TRANSACTION behave identically.
// ============================================
function calculateSettlementAmount($charge_to, $charge_sched, $principal, $charge_to_customer, $charge_to_partner, $adjustment, $partner_id = '', $txn_count = 0) {
    // Special case for partner_id_kpx = 34: Amount for Settlement = Volume Count + Principal
    if ((string)$partner_id === '34') {
        return (float)$txn_count + (float)$principal;
    }

    $charge_to_upper = strtoupper(trim($charge_to));
    $charge_sched_upper = normalizeChargeSched($charge_sched);

    // UNCATEGORIZED: If charge_to is empty, use Principal + Adjustment (without any charges)
    if (empty($charge_to_upper)) {
        return (float)$principal + (float)$adjustment;
    }

    // For WEEKLY, MONTHLY, SEMI-MONTHLY: Amount = Principal + Adjustment (no charge deduction)
    // This applies to both PARTNER and CUSTOMER charge types
    if (($charge_to_upper === 'PARTNER' || $charge_to_upper === 'CUSTOMER') &&
        in_array($charge_sched_upper, ['WEEKLY', 'MONTHLY', 'SEMI-MONTHLY'])) {
        return $principal + $adjustment;
    }

    // For DAILY (CUSTOMER, PARTNER, or BOTH): Amount = Principal - Charge to Partner + Adjustment
    // (PER TRANSACTION is normalized to DAILY above)
    if (($charge_to_upper === 'CUSTOMER' || $charge_to_upper === 'PARTNER' || $charge_to_upper === 'BOTH')
        && $charge_sched_upper === 'DAILY') {
        return $principal - $charge_to_partner + $adjustment;
    }

    // For BOTH charge types (WEEKLY/MONTHLY): Use the original calculation (Principal + both charges + adjustment)
    if ($charge_to_upper === 'BOTH') {
        return $principal + $charge_to_customer + $charge_to_partner + $adjustment;
    }

    // Default fallback for any other case: Principal + Adjustment only (no charges)
    return (float)$principal + (float)$adjustment;
}

// ============================================
// FUNCTION: Check if a date is Tuesday
// ============================================
function isTuesday(?string $date): bool {
    if (empty($date)) return false;
    $timestamp = strtotime($date);
    if ($timestamp === false) return false;
    return date('N', $timestamp) == 2; // 2 = Tuesday
}

// ============================================
// FUNCTION: Get Wednesday-to-Tuesday range
// ============================================
function getWednesdayToTuesdayRange(string $tuesday_date): array {
    $timestamp = strtotime($tuesday_date);
    if ($timestamp === false) {
        return [$tuesday_date, $tuesday_date];
    }
    $wednesday = date('Y-m-d', strtotime('-6 days', $timestamp));
    $tuesday = date('Y-m-d', $timestamp);
    return [$wednesday, $tuesday];
}

// ============================================
// FUNCTION: Get Monday-to-Sunday range (week BEFORE a given Tuesday)
// ============================================
function getMondayToSundayRange(string $tuesday_date): array {
    $timestamp = strtotime($tuesday_date);
    if ($timestamp === false) {
        return [$tuesday_date, $tuesday_date];
    }
    $sunday = date('Y-m-d', strtotime('-2 days', $timestamp));
    $monday = date('Y-m-d', strtotime('-6 days', strtotime($sunday)));
    return [$monday, $sunday];
}

// ============================================
// FUNCTION: Get special weekly partners list
// ============================================
function getSpecialWeeklyPartners(): array {
    return ['457', '458', '459', '460'];
}

// ============================================
// FUNCTION: Check if partner is a special weekly partner
// ============================================
function isSpecialWeeklyPartner(string $partner_id, string $bank, string $settlement_type): bool {
    $partner_id = trim($partner_id);
    $bank_upper = strtoupper(trim($bank));
    $type_upper = strtoupper(trim($settlement_type));
    
    if (in_array($partner_id, ['457', '458', '459'])) {
        if (strpos($bank_upper, 'CHINA') !== false && $type_upper === 'CHECK') {
            return true;
        }
    }
    
    if ($partner_id === '460') {
        if (strpos($bank_upper, 'BDO') !== false && $type_upper === 'ONLINE') {
            return true;
        }
    }
    
    return false;
}

// ============================================
// FUNCTION: Get week-before partners list
// ============================================
function getSpecialWeekBeforePartners(): array {
    return ['1005'];
}

// ============================================
// FUNCTION: Check if partner is a week-before partner
// ============================================
function isSpecialWeekBeforePartner(string $partner_id, string $bank, string $settlement_type): bool {
    $partner_id = trim($partner_id);
    if ($partner_id !== '1005') {
        return false;
    }
    $bank_upper = strtoupper(trim($bank));
    $type_upper = strtoupper(trim($settlement_type));
    
    if (strpos($bank_upper, 'BDO') !== false && $type_upper === 'ONLINE') {
        return true;
    }
    return false;
}

// ============================================
// FDC Mindanao (partner 256) region split definitions
// ============================================
function getFdcMindanaoRegionSets(): array {
    return [
        'GENSAN' => [
            'suffix' => 'GENSAN',
            'regions' => ['R24 SOCSK REGION', 'R16 SARGEN REGION'],
            'account_name' => 'FAST DISTRIBUTION CORP.',
            'account_number' => '158-702-000-915',
        ],
        'CDO' => [
            'suffix' => 'CDO',
            'regions' => ['R18 CAGAYAN DE ORO REGION', 'R19 LANAO REGION', 'R30 BUKIDNON REGION', 'R14 DAVAO REGION'],
            'account_name' => 'FAST DISTRIBUTION CORP.',
            'account_number' => '158-702-000-923',
        ],
    ];
}

// ============================================
// FAST UNIMERCHANTS INC. (partner 259) region/bank split definitions
// ============================================
function getFuiUnimerchantsRegionSets(): array {
    return [
        'BPI' => [
            'suffix' => '',
            'bank_key' => 'BPI',
            'regions' => [
                'R21 ZANORTE REGION',
                'R20 ZASURMIS REGION',
                'R19 LANAO REGION',
                'R22 ZAMSIBUGAY REGION',
            ],
            'partner_name' => 'FAST UNIMERCHANTS INC.',
            'account_name' => 'FAST UNIMERCHANT, INC.',
            'account_number' => '9363-1034-37',
        ],
        'BDO_NEGROS' => [
            'suffix' => 'NEGROS',
            'bank_key' => 'BDO',
            'regions' => [
                'R04 NEG.OR.-SIQ. REGION',
                'R08 NEG OCC A REGION',
                'R29 NEG OCC B REGION',
            ],
            'partner_name' => 'FAST DISTRIBUTION CORPORATIONS NEGROS',
            'account_name' => 'FAST UNIMERCHANT, INC.',
            'account_number' => '000820553158',
        ],
        'BDO_CEBU' => [
            'suffix' => 'CEBU/BOHOL',
            'bank_key' => 'BDO',
            'regions' => [
                'R02 CEBU NORTH A REGION',
                'R03 CEBU SOUTH REGION',
                'R05 BOHOL REGION',
                'R26 CEBU NORTH B REGION',
                'R01 CEBU CENTRAL A REGION',
                'R27 CEBU CENTRAL B REGION',
            ],
            'partner_name' => 'FUI-SHELL-BOHOL AND CEBU',
            'account_name' => 'FAST UNIMERCHANTS INCORPORATED',
            'account_number' => '0103 2006 0721',
        ],
    ];
}

// ============================================
// Partner 257 region/bank split definitions
// ============================================
function getPartner257Sets(): array {
    return [
        'BDO_PANAY' => [
            'suffix' => '',
            'bank_key' => 'BDO',
            'regions' => ['R10 PANAY NORTH REGION', 'R11 PANAY CENTRAL REGION'],
            'partner_name' => 'FAST DISTRIBUTION CORPORATION (VISAYAS)',
            'account_name' => null,
            'account_number' => null,
            'extra_where' => null,
        ],
        'CHINABANK_BOHOL' => [
            'suffix' => 'BOHOL',
            'bank_key' => 'CHINABANK',
            'regions' => ['R05 BOHOL REGION'],
            'partner_name' => 'FDC BOHOL',
            'account_name' => 'FAST DISTRIBUTION CORP.',
            'account_number' => '107-102-003-788',
            'extra_where' => null,
        ],
        'CHINABANK_ORMOC' => [
            'suffix' => 'ORMOC',
            'bank_key' => 'CHINABANK',
            'regions' => [],
            'partner_name' => 'FDC ORMOC',
            'account_name' => 'FAST DISTRIBUTION CORP.',
            'account_number' => '107-102-003-755',
            'extra_where' => "(bt.account_no LIKE '%orm%' OR bt.address LIKE '%orm%' OR bt.account_no LIKE '%sog%' OR bt.address LIKE '%sog%')",
        ],
        'CHINABANK_SAMAR' => [
            'suffix' => 'SAMAR',
            'bank_key' => 'CHINABANK',
            'regions' => ['R07 SAMAR REGION'],
            'partner_name' => 'FDC SAMAR',
            'account_name' => 'FAST DISTRIBUTION CORP.',
            'account_number' => '107-102-003-763',
            'extra_where' => null,
        ],
        'CHINABANK_TACLOBAN' => [
            'suffix' => 'TACLOBAN',
            'bank_key' => 'CHINABANK',
            'regions' => [],
            'partner_name' => 'FDC TACLOBAN',
            'account_name' => 'FAST DISTRIBUTION CORP.',
            'account_number' => '107-102-003-771',
            'extra_where' => "(bt.account_no LIKE '%tac%' OR bt.address LIKE '%tac%')",
        ],
    ];
}

// ============================================
// LANDBANK (PCSO) region split definitions
// ============================================
function getPcsoLandbankSets(): array {
    return [
        'NCR' => [
            'suffix' => 'NCR',
            'partner_ids' => ['631'],
            'region_label' => 'PCSO NCR',
        ],
        'VISAYAS' => [
            'suffix' => 'VISAYAS',
            'partner_ids' => ['648', '650', '651', '653', '655', '656', '658', '660'],
            'region_label' => 'PCSO VISAYAS',
        ],
        'MINDANAO' => [
            'suffix' => 'MINDANAO',
            'partner_ids' => ['662', '670', '680'],
            'region_label' => 'PCSO MINDANAO',
        ],
    ];
}

/**
 * Fetch partner data for LANDBANK (PCSO) by region - returns individual partner entries
 */
function getPcsoLandbankPartners(
    mysqli $conn,
    array $partner_ids,
    string $bank,
    string $settlement_type,
    string $date_from,
    string $date_to,
    string $region_label
): array {
    if (empty($partner_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($partner_ids), '?'));
    
    $where_regular = ["bt.partner_id_kpx IN ($placeholders)"];
    $params_regular = $partner_ids;
    $types_regular = str_repeat('s', count($partner_ids));

    $where_adjustment = ["bt.partner_id_kpx IN ($placeholders)"];
    $params_adjustment = $partner_ids;
    $types_adjustment = str_repeat('s', count($partner_ids));

    if (!empty($bank)) {
        $where_regular[] = "pm.bank = ?";
        $params_regular[] = $bank;
        $types_regular .= "s";
        $where_adjustment[] = "pm.bank = ?";
        $params_adjustment[] = $bank;
        $types_adjustment .= "s";
    }

    if (!empty($settlement_type)) {
        $where_regular[] = "pm.settled_online_check = ?";
        $params_regular[] = $settlement_type;
        $types_regular .= "s";
        $where_adjustment[] = "pm.settled_online_check = ?";
        $params_adjustment[] = $settlement_type;
        $types_adjustment .= "s";
    }

    if (!empty($date_from) && !empty($date_to)) {
        $where_regular[] = "bt.datetime BETWEEN ? AND ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "ss";
    } elseif (!empty($date_from)) {
        $where_regular[] = "bt.datetime >= ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $types_regular .= "s";
    } elseif (!empty($date_to)) {
        $where_regular[] = "bt.datetime <= ?";
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "s";
    }
    $where_regular[] = "(bt.status IS NULL OR bt.status = '')";

    if (!empty($date_from) && !empty($date_to)) {
        $where_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "ss";
    } elseif (!empty($date_from)) {
        $where_adjustment[] = "bt.cancellation_date >= ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $types_adjustment .= "s";
    } elseif (!empty($date_to)) {
        $where_adjustment[] = "bt.cancellation_date <= ?";
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "s";
    }
    $where_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";

    $regular_sql = "SELECT 
            bt.partner_id_kpx,
            pm.partner_name,
            pm.partner_accName,
            pm.bank_accNumber,
            pm.bank,
            pm.settled_online_check as settlement_type,
            COALESCE(pm.charge_to, '') as charge_to,
            COALESCE(pm.charge_sched, '') as charge_sched,
            COUNT(*) as txn_count,
            SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
            SUM(bt.charge_to_customer) as charge_to_customer,
            SUM(bt.charge_to_partner) as charge_to_partner,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN 1 ELSE 0 END) as settled_count,
            SUM(CASE WHEN bt.settle_unsettle IS NULL 
                      OR bt.settle_unsettle = '' 
                      OR bt.settle_unsettle != 'Settled' 
                 THEN 1 ELSE 0 END) as unsettled_count,
            MAX(bt.datetime) as last_transaction_date,
            MIN(bt.datetime) as first_transaction_date
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE " . implode(" AND ", $where_regular) . "
        GROUP BY bt.partner_id_kpx, pm.partner_name, pm.partner_accName, pm.bank_accNumber, 
                 pm.bank, pm.settled_online_check, pm.charge_to, pm.charge_sched";

    $adjustment_sql = "SELECT 
            bt.partner_id_kpx,
            SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE " . implode(" AND ", $where_adjustment) . "
        GROUP BY bt.partner_id_kpx";

    $entries = [];
    $adjustments = [];

    // Get adjustments first
    $stmt = $conn->prepare($adjustment_sql);
    if ($stmt) {
        $stmt->bind_param($types_adjustment, ...$params_adjustment);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $adjustments[$row['partner_id_kpx']] = (float)($row['total_adjustment'] ?? 0);
        }
        $stmt->close();
    }

    // Get regular data
    $stmt = $conn->prepare($regular_sql);
    if ($stmt) {
        $stmt->bind_param($types_regular, ...$params_regular);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $partner_id = $row['partner_id_kpx'];
            $entry = [
                'partner_id_kpx' => $partner_id,
                'partner_name' => $row['partner_name'] ?? $partner_id,
                'partner_accName' => $row['partner_accName'] ?? 'N/A',
                'bank_accNumber' => $row['bank_accNumber'] ?? 'N/A',
                'bank' => $row['bank'] ?? '',
                'settlement_type' => $row['settlement_type'] ?? '',
                'charge_to' => $row['charge_to'] ?? '',
                'charge_sched' => $row['charge_sched'] ?? '',
                'settle_unsettle' => '',
                'txn_count' => (int)($row['txn_count'] ?? 0),
                'total_principal' => (float)($row['total_principal'] ?? 0),
                'charge_to_customer' => (float)($row['charge_to_customer'] ?? 0),
                'charge_to_partner' => (float)($row['charge_to_partner'] ?? 0),
                'total_adjustment' => $adjustments[$partner_id] ?? 0,
                'settled_count' => (int)($row['settled_count'] ?? 0),
                'unsettled_count' => (int)($row['unsettled_count'] ?? 0),
                'last_transaction_date' => $row['last_transaction_date'] ?? null,
                'first_transaction_date' => $row['first_transaction_date'] ?? null,
                'pcso_region' => $region_label,
                'is_pcso_landbank' => true
            ];
            $entries[] = $entry;
        }
        $stmt->close();
    }

    // Check for partners that only have adjustments
    foreach ($partner_ids as $pid) {
        if (!isset($entries[$pid]) && isset($adjustments[$pid]) && $adjustments[$pid] != 0) {
            $details_sql = "SELECT partner_name, partner_accName, bank_accNumber, bank,
                                   settled_online_check as settlement_type,
                                   COALESCE(charge_to, '') as charge_to,
                                   COALESCE(charge_sched, '') as charge_sched
                            FROM masterdata.partner_masterfile WHERE partner_id_kpx = ?";
            $dstmt = $conn->prepare($details_sql);
            if ($dstmt) {
                $dstmt->bind_param("s", $pid);
                $dstmt->execute();
                $dres = $dstmt->get_result();
                if ($details = $dres->fetch_assoc()) {
                    $entries[] = [
                        'partner_id_kpx' => $pid,
                        'partner_name' => $details['partner_name'] ?? $pid,
                        'partner_accName' => $details['partner_accName'] ?? 'N/A',
                        'bank_accNumber' => $details['bank_accNumber'] ?? 'N/A',
                        'bank' => $details['bank'] ?? '',
                        'settlement_type' => $details['settlement_type'] ?? '',
                        'charge_to' => $details['charge_to'] ?? '',
                        'charge_sched' => $details['charge_sched'] ?? '',
                        'settle_unsettle' => '',
                        'txn_count' => 0,
                        'total_principal' => 0,
                        'charge_to_customer' => 0,
                        'charge_to_partner' => 0,
                        'total_adjustment' => $adjustments[$pid],
                        'settled_count' => 0,
                        'unsettled_count' => 0,
                        'last_transaction_date' => null,
                        'first_transaction_date' => null,
                        'pcso_region' => $region_label,
                        'is_pcso_landbank' => true
                    ];
                }
                $dstmt->close();
            }
        }
    }

    return $entries;
}

/**
 * Fetch aggregated settlement totals for a partner, optionally filtered by regions
 * and/or an extra raw WHERE clause.
 */
function getPartnerTotalsByRegions(
    mysqli $conn,
    string $partner_id,
    string $bank,
    string $settlement_type,
    string $date_from,
    string $date_to,
    array $regions = [],
    ?string $extra_where = null
): ?array {
    if (empty($partner_id)) {
        return null;
    }

    $where_regular = ["bt.partner_id_kpx = ?"];
    $params_regular = [$partner_id];
    $types_regular = "s";

    $where_adjustment = ["bt.partner_id_kpx = ?"];
    $params_adjustment = [$partner_id];
    $types_adjustment = "s";

    if (!empty($regions)) {
        $ph = implode(',', array_fill(0, count($regions), '?'));
        $where_regular[] = "bt.region IN ($ph)";
        $where_adjustment[] = "bt.region IN ($ph)";
        foreach ($regions as $r) {
            $params_regular[] = $r;
            $types_regular .= "s";
            $params_adjustment[] = $r;
            $types_adjustment .= "s";
        }
    }

    if (!empty($extra_where)) {
        $where_regular[] = $extra_where;
        $where_adjustment[] = $extra_where;
    }

    if (!empty($bank)) {
        $where_regular[] = "pm.bank = ?";
        $params_regular[] = $bank;
        $types_regular .= "s";
        $where_adjustment[] = "pm.bank = ?";
        $params_adjustment[] = $bank;
        $types_adjustment .= "s";
    }

    if (!empty($settlement_type)) {
        $where_regular[] = "pm.settled_online_check = ?";
        $params_regular[] = $settlement_type;
        $types_regular .= "s";
        $where_adjustment[] = "pm.settled_online_check = ?";
        $params_adjustment[] = $settlement_type;
        $types_adjustment .= "s";
    }

    if (!empty($date_from) && !empty($date_to)) {
        $where_regular[] = "bt.datetime BETWEEN ? AND ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "ss";
    } elseif (!empty($date_from)) {
        $where_regular[] = "bt.datetime >= ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $types_regular .= "s";
    } elseif (!empty($date_to)) {
        $where_regular[] = "bt.datetime <= ?";
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "s";
    }
    $where_regular[] = "(bt.status IS NULL OR bt.status = '')";

    if (!empty($date_from) && !empty($date_to)) {
        $where_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "ss";
    } elseif (!empty($date_from)) {
        $where_adjustment[] = "bt.cancellation_date >= ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $types_adjustment .= "s";
    } elseif (!empty($date_to)) {
        $where_adjustment[] = "bt.cancellation_date <= ?";
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "s";
    }
    $where_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";

    $regular_sql = "SELECT 
            bt.partner_id_kpx,
            pm.partner_name,
            pm.partner_accName,
            pm.bank_accNumber,
            pm.bank,
            pm.settled_online_check as settlement_type,
            COALESCE(pm.charge_to, '') as charge_to,
            COALESCE(pm.charge_sched, '') as charge_sched,
            COUNT(*) as txn_count,
            SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
            SUM(bt.charge_to_customer) as charge_to_customer,
            SUM(bt.charge_to_partner) as charge_to_partner,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN 1 ELSE 0 END) as settled_count,
            SUM(CASE WHEN bt.settle_unsettle IS NULL 
                      OR bt.settle_unsettle = '' 
                      OR bt.settle_unsettle != 'Settled' 
                 THEN 1 ELSE 0 END) as unsettled_count,
            MAX(bt.datetime) as last_transaction_date,
            MIN(bt.datetime) as first_transaction_date
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE " . implode(" AND ", $where_regular) . "
        GROUP BY bt.partner_id_kpx, pm.partner_name, pm.partner_accName, pm.bank_accNumber, 
                 pm.bank, pm.settled_online_check, pm.charge_to, pm.charge_sched";

    $adjustment_sql = "SELECT 
            bt.partner_id_kpx,
            SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE " . implode(" AND ", $where_adjustment) . "
        GROUP BY bt.partner_id_kpx";

    $entry = null;

    $stmt = $conn->prepare($regular_sql);
    if ($stmt) {
        $stmt->bind_param($types_regular, ...$params_regular);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $entry = [
                'partner_id_kpx' => $partner_id,
                'partner_name' => $row['partner_name'] ?? $partner_id,
                'partner_accName' => $row['partner_accName'] ?? 'N/A',
                'bank_accNumber' => $row['bank_accNumber'] ?? 'N/A',
                'bank' => $row['bank'] ?? '',
                'settlement_type' => $row['settlement_type'] ?? '',
                'charge_to' => $row['charge_to'] ?? '',
                'charge_sched' => $row['charge_sched'] ?? '',
                'settle_unsettle' => '',
                'txn_count' => (int)($row['txn_count'] ?? 0),
                'total_principal' => (float)($row['total_principal'] ?? 0),
                'charge_to_customer' => (float)($row['charge_to_customer'] ?? 0),
                'charge_to_partner' => (float)($row['charge_to_partner'] ?? 0),
                'total_adjustment' => 0,
                'settled_count' => (int)($row['settled_count'] ?? 0),
                'unsettled_count' => (int)($row['unsettled_count'] ?? 0),
                'last_transaction_date' => $row['last_transaction_date'] ?? null,
                'first_transaction_date' => $row['first_transaction_date'] ?? null,
            ];
        }
        $stmt->close();
    }

    $stmt = $conn->prepare($adjustment_sql);
    if ($stmt) {
        $stmt->bind_param($types_adjustment, ...$params_adjustment);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $adj = (float)($row['total_adjustment'] ?? 0);
            if ($entry !== null) {
                $entry['total_adjustment'] = $adj;
            } else {
                $details_sql = "SELECT partner_name, partner_accName, bank_accNumber, bank,
                                       settled_online_check as settlement_type,
                                       COALESCE(charge_to, '') as charge_to,
                                       COALESCE(charge_sched, '') as charge_sched
                                FROM masterdata.partner_masterfile WHERE partner_id_kpx = ?";
                $dstmt = $conn->prepare($details_sql);
                if ($dstmt) {
                    $dstmt->bind_param("s", $partner_id);
                    $dstmt->execute();
                    $dres = $dstmt->get_result();
                    if ($details = $dres->fetch_assoc()) {
                        $entry = [
                            'partner_id_kpx' => $partner_id,
                            'partner_name' => $details['partner_name'] ?? $partner_id,
                            'partner_accName' => $details['partner_accName'] ?? 'N/A',
                            'bank_accNumber' => $details['bank_accNumber'] ?? 'N/A',
                            'bank' => $details['bank'] ?? '',
                            'settlement_type' => $details['settlement_type'] ?? '',
                            'charge_to' => $details['charge_to'] ?? '',
                            'charge_sched' => $details['charge_sched'] ?? '',
                            'settle_unsettle' => '',
                            'txn_count' => 0,
                            'total_principal' => 0,
                            'charge_to_customer' => 0,
                            'charge_to_partner' => 0,
                            'total_adjustment' => $adj,
                            'settled_count' => 0,
                            'unsettled_count' => 0,
                            'last_transaction_date' => null,
                            'first_transaction_date' => null,
                        ];
                    }
                    $dstmt->close();
                }
            }
        }
        $stmt->close();
    }

    return $entry;
}

// Get filter values from GET parameters
$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$selected_rfp_no = isset($_GET['rfp_no']) ? trim($_GET['rfp_no']) : '';

// Validate RFP No.
if (empty($selected_rfp_no)) {
    die("RFP No. is required for PDF export.");
}

// Get excluded rows from GET parameters (comma-separated list of row indices)
$excluded_rows = isset($_GET['excluded_rows']) ? explode(',', trim($_GET['excluded_rows'])) : [];
$excluded_rows = array_filter($excluded_rows, 'is_numeric');

// Get current user name for Prepared By
$display_name = 'GUEST';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') {
        $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
    } elseif ($_SESSION['user_type'] === 'user') {
        $display_name = $_SESSION['user_name'] ?? 'USER';
    }
}

/**
 * Format date range for display
 */
function formatDateRange(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) {
        return strtoupper(date('F d, Y'));
    }
    
    $from = strtotime($date_from);
    $to = !empty($date_to) ? strtotime($date_to) : $from;
    
    if ($from == $to) {
        return strtoupper(date('F d, Y', $from));
    } else {
        $from_month = date('F', $from);
        $to_month = date('F', $to);
        $from_day = date('d', $from);
        $to_day = date('d', $to);
        $to_year = date('Y', $to);
        
        if ($from_month == $to_month) {
            return strtoupper($from_month . ' ' . $from_day . ' - ' . $to_day . ', ' . $to_year);
        } else {
            return strtoupper(date('F d', $from) . ' - ' . date('F d, Y', $to));
        }
    }
}

// ============================================
// AUTO-POPULATE BANK FROM PARTNER
// Skip for partner 259 and 257
// ============================================
$auto_selected_bank = '';
if (!empty($selected_partner) && empty($selected_bank) && $selected_partner !== '259' && $selected_partner !== '257') {
    $auto_selected_bank = getPartnerBank($conn, $selected_partner);
    if (!empty($auto_selected_bank)) {
        $selected_bank = $auto_selected_bank;
        $_GET['bank'] = $auto_selected_bank;
    }
}

// ============================================
// SPECIAL WEEKLY PARTNERS LOGIC
// ============================================
$special_partners = getSpecialWeeklyPartners();
$include_special_partners = false;
$special_date_from = $selected_date_from;
$special_date_to = $selected_date_to;
$selected_is_special = in_array($selected_partner, $special_partners, true);

if (!empty($selected_date_to) && isTuesday($selected_date_to)) {
    $include_special_partners = true;
    list($special_date_from, $special_date_to) = getWednesdayToTuesdayRange($selected_date_to);
}

// ============================================
// SPECIAL WEEK-BEFORE PARTNER LOGIC (Partner 1005)
// ============================================
$special_wb_partners = getSpecialWeekBeforePartners();
$include_special_wb_partners = false;
$special_wb_date_from = $selected_date_from;
$special_wb_date_to = $selected_date_to;
$selected_is_special_wb = in_array($selected_partner, $special_wb_partners, true);

if (!empty($selected_date_to) && isTuesday($selected_date_to)) {
    $include_special_wb_partners = true;
    list($special_wb_date_from, $special_wb_date_to) = getMondayToSundayRange($selected_date_to);
}

// Build the queries - ADAPTED from settlement-per-bank.php logic
try {
    // ============================================
    // BUILD SEPARATE WHERE CONDITIONS FOR REGULAR AND ADJUSTMENT
    // ============================================
    $where_conditions_regular = [];
    $where_conditions_adjustment = [];
    $params_regular = [];
    $params_adjustment = [];
    $types_regular = "";
    $types_adjustment = "";
    
    // Partner filter - applies to both
    if (!empty($selected_partner)) {
        $where_conditions_regular[] = "bt.partner_id_kpx = ?";
        $params_regular[] = $selected_partner;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
        $params_adjustment[] = $selected_partner;
        $types_adjustment .= "s";
    }
    
    // Bank filter - applies to both
    if (!empty($selected_bank)) {
        $where_conditions_regular[] = "pm.bank = ?";
        $params_regular[] = $selected_bank;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.bank = ?";
        $params_adjustment[] = $selected_bank;
        $types_adjustment .= "s";
    }
    
    // Settlement type filter - applies to both
    if (!empty($selected_settlement_type)) {
        $where_conditions_regular[] = "pm.settled_online_check = ?";
        $params_regular[] = $selected_settlement_type;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.settled_online_check = ?";
        $params_adjustment[] = $selected_settlement_type;
        $types_adjustment .= "s";
    }
    
    // ============================================
    // SPECIAL WEEKLY PARTNERS (457, 458, 459, 460)
    // ============================================
    if ($selected_is_special && !$include_special_partners) {
        $where_conditions_regular[] = "1=0";
        $where_conditions_adjustment[] = "1=0";
    }
    
    if (empty($selected_partner) && !$include_special_partners) {
        $sp_placeholders = implode(',', array_fill(0, count($special_partners), '?'));
        $where_conditions_regular[] = "bt.partner_id_kpx NOT IN ($sp_placeholders)";
        $where_conditions_adjustment[] = "bt.partner_id_kpx NOT IN ($sp_placeholders)";
        foreach ($special_partners as $sp) {
            $params_regular[] = $sp;
            $types_regular .= "s";
            $params_adjustment[] = $sp;
            $types_adjustment .= "s";
        }
    }
    
    // ============================================
    // SPECIAL WEEK-BEFORE PARTNER (1005)
    // ============================================
    if ($selected_is_special_wb && !$include_special_wb_partners) {
        $where_conditions_regular[] = "1=0";
        $where_conditions_adjustment[] = "1=0";
    }
    
    if (empty($selected_partner) && !$include_special_wb_partners) {
        $wb_placeholders = implode(',', array_fill(0, count($special_wb_partners), '?'));
        $where_conditions_regular[] = "bt.partner_id_kpx NOT IN ($wb_placeholders)";
        $where_conditions_adjustment[] = "bt.partner_id_kpx NOT IN ($wb_placeholders)";
        foreach ($special_wb_partners as $wb) {
            $params_regular[] = $wb;
            $types_regular .= "s";
            $params_adjustment[] = $wb;
            $types_adjustment .= "s";
        }
    }
    
    // ============================================
    // DATE FILTERS
    // ============================================
    $use_special_dates = false;
    if ($selected_is_special && $include_special_partners) {
        $use_special_dates = true;
    }
    
    $use_special_wb_dates = false;
    if ($selected_is_special_wb && $include_special_wb_partners) {
        $use_special_wb_dates = true;
    }
    
    if ($use_special_dates) {
        $effective_date_from = $special_date_from;
        $effective_date_to = $special_date_to;
    } elseif ($use_special_wb_dates) {
        $effective_date_from = $special_wb_date_from;
        $effective_date_to = $special_wb_date_to;
    } else {
        $effective_date_from = $selected_date_from;
        $effective_date_to = $selected_date_to;
    }
    
    // ============================================
    // REGULAR TRANSACTIONS: Based on datetime, NOT cancelled
    // ============================================
    if (!empty($effective_date_from) && !empty($effective_date_to)) {
        $where_conditions_regular[] = "bt.datetime BETWEEN ? AND ?";
        $params_regular[] = $effective_date_from . ' 00:00:00';
        $params_regular[] = $effective_date_to . ' 23:59:59';
        $types_regular .= "ss";
    } elseif (!empty($effective_date_from)) {
        $where_conditions_regular[] = "bt.datetime >= ?";
        $params_regular[] = $effective_date_from . ' 00:00:00';
        $types_regular .= "s";
    } elseif (!empty($effective_date_to)) {
        $where_conditions_regular[] = "bt.datetime <= ?";
        $params_regular[] = $effective_date_to . ' 23:59:59';
        $types_regular .= "s";
    }
    
    // Regular transactions: EXCLUDE cancelled/voided
    $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";
    
    // ============================================
    // ADJUSTMENTS: Based on cancellation_date, ONLY cancelled
    // ============================================
    if (!empty($effective_date_from) && !empty($effective_date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
        $params_adjustment[] = $effective_date_from . ' 00:00:00';
        $params_adjustment[] = $effective_date_to . ' 23:59:59';
        $types_adjustment .= "ss";
    } elseif (!empty($effective_date_from)) {
        $where_conditions_adjustment[] = "bt.cancellation_date >= ?";
        $params_adjustment[] = $effective_date_from . ' 00:00:00';
        $types_adjustment .= "s";
    } elseif (!empty($effective_date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date <= ?";
        $params_adjustment[] = $effective_date_to . ' 23:59:59';
        $types_adjustment .= "s";
    }
    
    // Adjustments: ONLY cancelled/voided
    $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";
    
    // ============================================
    // QUERY 1: Regular transactions (not cancelled)
    // ============================================
    $regular_sql = "SELECT 
                    bt.partner_id_kpx,
                    pm.partner_name,
                    pm.partner_accName,
                    pm.bank_accNumber,
                    pm.bank,
                    pm.settled_online_check as settlement_type,
                    COALESCE(pm.charge_to, '') as charge_to,
                    COALESCE(pm.charge_sched, '') as charge_sched,
                    COUNT(*) as txn_count,
                    SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                    SUM(bt.charge_to_customer) as charge_to_customer,
                    SUM(bt.charge_to_partner) as charge_to_partner,
                    SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN 1 ELSE 0 END) as settled_count,
                    SUM(CASE WHEN bt.settle_unsettle IS NULL 
                              OR bt.settle_unsettle = '' 
                              OR bt.settle_unsettle != 'Settled' 
                         THEN 1 ELSE 0 END) as unsettled_count,
                    MAX(bt.datetime) as last_transaction_date,
                    MIN(bt.datetime) as first_transaction_date
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm 
                    ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_regular) . "
                GROUP BY bt.partner_id_kpx, 
                         pm.partner_name, 
                         pm.partner_accName, 
                         pm.bank_accNumber, 
                         pm.bank, 
                         pm.settled_online_check, 
                         pm.charge_to, 
                         pm.charge_sched";
    
    // ============================================
    // QUERY 2: Adjustments (cancelled transactions)
    // ============================================
    $adjustment_sql = "SELECT 
                            bt.partner_id_kpx,
                            SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment
                        FROM mldb.billspayment_transaction bt
                        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                        WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                        GROUP BY bt.partner_id_kpx";
    
    // ============================================
    // EXECUTE QUERIES
    // ============================================
    
    // Execute regular query
    $regular_result = null;
    if (!empty($params_regular)) {
        $stmt = $conn->prepare($regular_sql);
        if ($stmt) {
            $stmt->bind_param($types_regular, ...$params_regular);
            $stmt->execute();
            $regular_result = $stmt->get_result();
        } else {
            error_log("Settlement - Regular query prepare failed: " . $conn->error);
            $regular_result = false;
        }
    } else {
        $regular_result = $conn->query($regular_sql);
    }
    
    // Execute adjustment query
    $adjustment_result = null;
    if (!empty($params_adjustment)) {
        $stmt = $conn->prepare($adjustment_sql);
        if ($stmt) {
            $stmt->bind_param($types_adjustment, ...$params_adjustment);
            $stmt->execute();
            $adjustment_result = $stmt->get_result();
        } else {
            error_log("Settlement - Adjustment query prepare failed: " . $conn->error);
            $adjustment_result = false;
        }
    } else {
        $adjustment_result = $conn->query($adjustment_sql);
    }
    
    // ============================================
    // COMBINE RESULTS IN PHP
    // ============================================
    $combined_data = [];
    
    if ($regular_result && $regular_result->num_rows > 0) {
        while ($row = $regular_result->fetch_assoc()) {
            $partner_id = $row['partner_id_kpx'];
            $combined_data[$partner_id] = [
                'partner_id_kpx' => $partner_id,
                'partner_name' => $row['partner_name'] ?? $partner_id,
                'partner_accName' => $row['partner_accName'] ?? 'N/A',
                'bank_accNumber' => $row['bank_accNumber'] ?? 'N/A',
                'bank' => $row['bank'] ?? '',
                'settlement_type' => $row['settlement_type'] ?? '',
                'charge_to' => $row['charge_to'] ?? '',
                'charge_sched' => $row['charge_sched'] ?? '',
                'settle_unsettle' => $row['settle_unsettle'] ?? '',
                'txn_count' => (int)($row['txn_count'] ?? 0),
                'total_principal' => (float)($row['total_principal'] ?? 0),
                'charge_to_customer' => (float)($row['charge_to_customer'] ?? 0),
                'charge_to_partner' => (float)($row['charge_to_partner'] ?? 0),
                'total_adjustment' => 0,
                'settled_count' => (int)($row['settled_count'] ?? 0),
                'unsettled_count' => (int)($row['unsettled_count'] ?? 0),
                'last_transaction_date' => $row['last_transaction_date'] ?? null,
                'first_transaction_date' => $row['first_transaction_date'] ?? null
            ];
        }
    }
    
    if ($adjustment_result && $adjustment_result->num_rows > 0) {
        while ($row = $adjustment_result->fetch_assoc()) {
            $partner_id = $row['partner_id_kpx'];
            if (isset($combined_data[$partner_id])) {
                $combined_data[$partner_id]['total_adjustment'] = (float)($row['total_adjustment'] ?? 0);
            } else {
                $partner_details_sql = "SELECT 
                                            partner_name,
                                            partner_accName,
                                            bank_accNumber,
                                            bank,
                                            settled_online_check as settlement_type,
                                            COALESCE(charge_to, '') as charge_to,
                                            COALESCE(charge_sched, '') as charge_sched
                                        FROM masterdata.partner_masterfile 
                                        WHERE partner_id_kpx = ?";
                $stmt = $conn->prepare($partner_details_sql);
                if ($stmt) {
                    $stmt->bind_param("s", $partner_id);
                    $stmt->execute();
                    $details_result = $stmt->get_result();
                    if ($details_result && $details_result->num_rows > 0) {
                        $details = $details_result->fetch_assoc();
                        $combined_data[$partner_id] = [
                            'partner_id_kpx' => $partner_id,
                            'partner_name' => $details['partner_name'] ?? $partner_id,
                            'partner_accName' => $details['partner_accName'] ?? 'N/A',
                            'bank_accNumber' => $details['bank_accNumber'] ?? 'N/A',
                            'bank' => $details['bank'] ?? '',
                            'settlement_type' => $details['settlement_type'] ?? '',
                            'charge_to' => $details['charge_to'] ?? '',
                            'charge_sched' => $details['charge_sched'] ?? '',
                            'settle_unsettle' => '',
                            'txn_count' => 0,
                            'total_principal' => 0,
                            'charge_to_customer' => 0,
                            'charge_to_partner' => 0,
                            'total_adjustment' => (float)($row['total_adjustment'] ?? 0),
                            'settled_count' => 0,
                            'unsettled_count' => 0,
                            'last_transaction_date' => null,
                            'first_transaction_date' => null
                        ];
                    }
                }
            }
        }
    }

    // ------------------------------------------------
    // SPECIAL: Handle partners 457, 458, 459 and 460
    // ------------------------------------------------
    if (!$include_special_partners) {
        foreach ($special_partners as $sp_id) {
            if (isset($combined_data[$sp_id])) {
                unset($combined_data[$sp_id]);
            }
        }
    } else {
        foreach ($special_partners as $sp_id) {
            $should_process = isset($combined_data[$sp_id]) || $selected_partner === $sp_id;
            
            if (!$should_process) {
                continue;
            }
            
            $sp_bank = '';
            $sp_settlement = '';
            $sp_details_sql = "SELECT bank, settled_online_check FROM masterdata.partner_masterfile WHERE partner_id_kpx = ?";
            $sp_stmt = $conn->prepare($sp_details_sql);
            if ($sp_stmt) {
                $sp_stmt->bind_param("s", $sp_id);
                $sp_stmt->execute();
                $sp_result = $sp_stmt->get_result();
                if ($sp_row = $sp_result->fetch_assoc()) {
                    $sp_bank = $sp_row['bank'] ?? '';
                    $sp_settlement = $sp_row['settled_online_check'] ?? '';
                }
                $sp_stmt->close();
            }
            
            if (!isSpecialWeeklyPartner($sp_id, $sp_bank, $sp_settlement)) {
                continue;
            }
            
            unset($combined_data[$sp_id]);
            
            $sp_entry = getPartnerTotalsByRegions(
                $conn,
                $sp_id,
                $selected_bank,
                $selected_settlement_type,
                $special_date_from,
                $special_date_to,
                [],
                null
            );
            
            if ($sp_entry !== null) {
                $sp_entry['special_weekly'] = true;
                $sp_entry['special_date_from'] = $special_date_from;
                $sp_entry['special_date_to'] = $special_date_to;
                $combined_data[$sp_id] = $sp_entry;
            }
        }
    }

    // ------------------------------------------------
    // SPECIAL: Handle partner 1005 (BDO/ONLINE)
    // ------------------------------------------------
    if (!$include_special_wb_partners) {
        foreach ($special_wb_partners as $wb_id) {
            if (isset($combined_data[$wb_id])) {
                unset($combined_data[$wb_id]);
            }
        }
    } else {
        foreach ($special_wb_partners as $wb_id) {
            $should_process = isset($combined_data[$wb_id]) || $selected_partner === $wb_id;
            
            if (!$should_process) {
                continue;
            }
            
            $wb_bank = '';
            $wb_settlement = '';
            $wb_details_sql = "SELECT bank, settled_online_check FROM masterdata.partner_masterfile WHERE partner_id_kpx = ?";
            $wb_stmt = $conn->prepare($wb_details_sql);
            if ($wb_stmt) {
                $wb_stmt->bind_param("s", $wb_id);
                $wb_stmt->execute();
                $wb_result = $wb_stmt->get_result();
                if ($wb_row = $wb_result->fetch_assoc()) {
                    $wb_bank = $wb_row['bank'] ?? '';
                    $wb_settlement = $wb_row['settled_online_check'] ?? '';
                }
                $wb_stmt->close();
            }
            
            if (!isSpecialWeekBeforePartner($wb_id, $wb_bank, $wb_settlement)) {
                continue;
            }
            
            unset($combined_data[$wb_id]);
            
            $wb_entry = getPartnerTotalsByRegions(
                $conn,
                $wb_id,
                $selected_bank,
                $selected_settlement_type,
                $special_wb_date_from,
                $special_wb_date_to,
                [],
                null
            );
            
            if ($wb_entry !== null) {
                $wb_entry['special_week_before'] = true;
                $wb_entry['special_wb_date_from'] = $special_wb_date_from;
                $wb_entry['special_wb_date_to'] = $special_wb_date_to;
                $combined_data[$wb_id] = $wb_entry;
            }
        }
    }

    // ------------------------------------------------
    // Special: Split partner 256 (FDC Mindanao) into GENSAN and CDO
    // ------------------------------------------------
    if (isset($combined_data['256'])) {
        unset($combined_data['256']);

        $fdc_sets = getFdcMindanaoRegionSets();
        foreach ($fdc_sets as $key => $set) {
            $entry = getPartnerTotalsByRegions(
                $conn,
                '256',
                $selected_bank,
                $selected_settlement_type,
                $selected_date_from,
                $selected_date_to,
                $set['regions']
            );
            if ($entry !== null) {
                $entry['partner_name'] = 'FDC - ' . $set['suffix'];
                $entry['partner_accName'] = $set['account_name'];
                $entry['bank_accNumber'] = $set['account_number'];
                $entry['fdc_split'] = $key;
                $entry['fdc_regions'] = $set['regions'];
                $combined_data['256-' . $key] = $entry;
            }
        }
    }

    // ------------------------------------------------
    // Special: Split partner 257
    // ------------------------------------------------
    $selected_bank_upper_257 = strtoupper(trim($selected_bank));
    $is_bdo_bank_257 = !empty($selected_bank) && (
        strpos($selected_bank_upper_257, 'BDO') !== false ||
        strpos($selected_bank_upper_257, 'UNIBANK') !== false
    );
    $is_chinabank_257 = !empty($selected_bank) && (
        strpos($selected_bank_upper_257, 'CHINA') !== false ||
        strpos($selected_bank_upper_257, 'CHINABANK') !== false
    );

    if (isset($combined_data['257']) || $selected_partner === '257') {
        if (isset($combined_data['257'])) {
            unset($combined_data['257']);
        }

        $fdc257_sets = getPartner257Sets();

        foreach ($fdc257_sets as $key => $set) {
            $include = true;
            if (!empty($selected_bank)) {
                if ($set['bank_key'] === 'BDO' && !$is_bdo_bank_257) {
                    $include = false;
                } elseif ($set['bank_key'] === 'CHINABANK' && !$is_chinabank_257) {
                    $include = false;
                }
            }

            if (!$include) {
                continue;
            }

            $bank_for_query = '';

            $entry = getPartnerTotalsByRegions(
                $conn,
                '257',
                $bank_for_query,
                $selected_settlement_type,
                $selected_date_from,
                $selected_date_to,
                $set['regions'],
                $set['extra_where'] ?? null
            );
            if ($entry !== null) {
                $entry['partner_name'] = $set['partner_name'];
                if (!empty($set['account_name'])) {
                    $entry['partner_accName'] = $set['account_name'];
                }
                if (!empty($set['account_number'])) {
                    $entry['bank_accNumber'] = $set['account_number'];
                }
                $entry['bank'] = ($set['bank_key'] === 'BDO')
                    ? 'BDO UNIBANK, INC.'
                    : 'CHINA BANKING CORPORATION (CHINABANK)';
                $entry['fdc257_split'] = $key;
                $entry['fdc257_regions'] = $set['regions'];
                $entry['fdc257_extra_where'] = $set['extra_where'] ?? null;
                $combined_data['257-' . $key] = $entry;
            }
        }
    }

    // ------------------------------------------------
    // Special: Split partner 259
    // ------------------------------------------------
    $selected_bank_upper = strtoupper(trim($selected_bank));
    $is_bpi_bank = !empty($selected_bank) && (
        strpos($selected_bank_upper, 'BPI') !== false ||
        strpos($selected_bank_upper, 'PHILIPPINE ISLANDS') !== false
    );
    $is_bdo_bank = !empty($selected_bank) && strpos($selected_bank_upper, 'BDO') !== false;

    if (isset($combined_data['259']) || $selected_partner === '259') {
        unset($combined_data['259']);

        $fui_sets = getFuiUnimerchantsRegionSets();

        foreach ($fui_sets as $key => $set) {
            $include = true;
            if (!empty($selected_bank)) {
                if ($set['bank_key'] === 'BPI' && !$is_bpi_bank) {
                    $include = false;
                } elseif ($set['bank_key'] === 'BDO' && !$is_bdo_bank) {
                    $include = false;
                }
            }

            if (!$include) {
                continue;
            }

            $entry = getPartnerTotalsByRegions(
                $conn,
                '259',
                '',
                $selected_settlement_type,
                $selected_date_from,
                $selected_date_to,
                $set['regions']
            );
            if ($entry !== null) {
                $entry['partner_name'] = $set['partner_name'];
                $entry['partner_accName'] = $set['account_name'];
                $entry['bank_accNumber'] = $set['account_number'];
                $entry['bank'] = ($set['bank_key'] === 'BPI')
                    ? 'BANK OF THE PHILIPPINE ISLANDS (BPI)'
                    : 'BDO UNIBANK, INC.';
                $entry['fui_split'] = $key;
                $entry['fui_regions'] = $set['regions'];
                $combined_data['259-' . $key] = $entry;
            }
        }
    }

    // ------------------------------------------------
    // Special: LANDBANK (PCSO)
    // ------------------------------------------------
    $selected_bank_upper_pcso = strtoupper(trim($selected_bank));
    $is_landbank_pcso = !empty($selected_bank) && (
        strpos($selected_bank_upper_pcso, 'LANDBANK') !== false ||
        strpos($selected_bank_upper_pcso, 'PCSO') !== false
    );

    $pcso_partner_ids = ['631', '648', '650', '651', '653', '655', '656', '658', '660', '662', '670', '680'];
    $has_pcso_partners = false;
    foreach ($pcso_partner_ids as $pcso_id) {
        if (isset($combined_data[$pcso_id])) {
            $has_pcso_partners = true;
            break;
        }
    }

    $selected_is_pcso = in_array($selected_partner, $pcso_partner_ids);

    if ($is_landbank_pcso || $has_pcso_partners || $selected_is_pcso) {
        foreach ($pcso_partner_ids as $pcso_id) {
            if (isset($combined_data[$pcso_id])) {
                unset($combined_data[$pcso_id]);
            }
        }

        $pcso_sets = getPcsoLandbankSets();

        foreach ($pcso_sets as $key => $set) {
            if (!empty($selected_partner) && !in_array($selected_partner, $set['partner_ids'])) {
                continue;
            }

            $entries = getPcsoLandbankPartners(
                $conn,
                $set['partner_ids'],
                $selected_bank,
                $selected_settlement_type,
                $selected_date_from,
                $selected_date_to,
                $set['region_label']
            );
            
            foreach ($entries as $entry) {
                if ($entry !== null) {
                    $combined_data['pcso-' . $entry['partner_id_kpx']] = $entry;
                }
            }
        }
    }

    // ============================================
    // PROCESS COMBINED DATA
    // ============================================
    $data_array = [];
    if (!empty($combined_data)) {
        $data_array = array_values($combined_data);
        
        usort($data_array, function($a, $b) {
            $order = [
                'CUSTOMER_DAILY' => 1,
                'CUSTOMER_WEEKLY' => 2,
                'CUSTOMER_MONTHLY' => 3,
                'PARTNER_DAILY' => 4,
                'PARTNER_WEEKLY' => 5,
                'PARTNER_SEMI-MONTHLY' => 6,
                'PARTNER_MONTHLY' => 7,
                'BOTH_DAILY' => 8,
                'BOTH_WEEKLY' => 9,
                'BOTH_MONTHLY' => 10,
                'UNCATEGORIZED' => 11
            ];
            
            $charge_to = strtoupper(trim($a['charge_to'] ?? ''));
            $charge_sched = normalizeChargeSched($a['charge_sched'] ?? '');
            if (empty($charge_to) || empty($charge_sched)) {
                $key_a = 'UNCATEGORIZED';
            } else {
                $key_a = $charge_to . '_' . $charge_sched;
            }
            
            $charge_to_b = strtoupper(trim($b['charge_to'] ?? ''));
            $charge_sched_b = normalizeChargeSched($b['charge_sched'] ?? '');
            if (empty($charge_to_b) || empty($charge_sched_b)) {
                $key_b = 'UNCATEGORIZED';
            } else {
                $key_b = $charge_to_b . '_' . $charge_sched_b;
            }
            
            $order_a = $order[$key_a] ?? 12;
            $order_b = $order[$key_b] ?? 12;
            
            if ($order_a == $order_b) {
                return strcmp($a['partner_name'] ?? '', $b['partner_name'] ?? '');
            }
            return $order_a - $order_b;
        });
    }
    
    // Define groups
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER SEMI MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH DAILY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'UNCATEGORIZED' => [
            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0];
    $all_rows = [];
    $row_index = 0;
    
    foreach ($data_array as $row) {
        $charge_to = strtoupper(trim($row['charge_to'] ?? ''));
        $charge_sched = normalizeChargeSched($row['charge_sched'] ?? '');
        
        $group_key = null;
        
        if (empty($charge_to) || empty($charge_sched)) {
            $group_key = 'UNCATEGORIZED';
        } elseif ($charge_to === 'CUSTOMER') {
            if ($charge_sched === 'DAILY') {
                $group_key = 'CHARGE BY CUSTOMER DAILY';
            } elseif ($charge_sched === 'WEEKLY') {
                $group_key = 'CHARGE BY CUSTOMER WEEKLY';
            } elseif ($charge_sched === 'MONTHLY') {
                $group_key = 'CHARGE BY CUSTOMER MONTHLY';
            } else {
                $group_key = 'UNCATEGORIZED';
            }
        } elseif ($charge_to === 'PARTNER') {
            if ($charge_sched === 'DAILY') {
                $group_key = 'CHARGE BY PARTNER DAILY';
            } elseif ($charge_sched === 'WEEKLY') {
                $group_key = 'CHARGE BY PARTNER WEEKLY';
            } elseif ($charge_sched === 'SEMI-MONTHLY') {
                $group_key = 'CHARGE BY PARTNER SEMI MONTHLY';
            } elseif ($charge_sched === 'MONTHLY') {
                $group_key = 'CHARGE BY PARTNER MONTHLY';
            } else {
                $group_key = 'UNCATEGORIZED';
            }
        } elseif ($charge_to === 'BOTH') {
            if ($charge_sched === 'DAILY') {
                $group_key = 'CHARGE BY BOTH DAILY';
            } elseif ($charge_sched === 'WEEKLY') {
                $group_key = 'CHARGE BY BOTH WEEKLY';
            } elseif ($charge_sched === 'MONTHLY') {
                $group_key = 'CHARGE BY BOTH MONTHLY';
            } else {
                $group_key = 'UNCATEGORIZED';
            }
        } else {
            $group_key = 'UNCATEGORIZED';
        }
        
        if (!isset($groups[$group_key])) {
            $group_key = 'UNCATEGORIZED';
        }
        
        $txn_count = (int)($row['txn_count'] ?? 0);
        $principal = (float)($row['total_principal'] ?? 0);
        $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
        $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
        $adjustment = (float)($row['total_adjustment'] ?? 0);
        
        $settlement_amount = calculateSettlementAmount(
            $charge_to,
            $charge_sched,
            $principal,
            $charge_to_customer,
            $charge_to_partner,
            $adjustment,
            $row['partner_id_kpx'] ?? '',
            $txn_count
        );
        
        $settled_count = (int)($row['settled_count'] ?? 0);
        $unsettled_count = (int)($row['unsettled_count'] ?? 0);
        $is_fully_settled = ($settled_count > 0 && $unsettled_count == 0);
        $is_partially_settled = ($settled_count > 0 && $unsettled_count > 0);
        
        if ($is_fully_settled) {
            $status = 'Settled';
        } elseif ($is_partially_settled) {
            $status = 'Partial';
        } else {
            $status = 'Unsettled';
        }
        
        $display_partner_name = $row['partner_name'] ?? $row['partner_id_kpx'];
        if (!empty($row['is_pcso_landbank']) && !empty($row['pcso_region'])) {
            $display_partner_name = $row['pcso_region'] . ' - ' . $display_partner_name;
        }
        
        $row_data = [
            'row_index' => $row_index,
            'partner_name' => $display_partner_name,
            'account_name' => $row['partner_accName'] ?? 'N/A',
            'account_number' => $row['bank_accNumber'] ?? 'N/A',
            'txn_count' => $txn_count,
            'principal' => $principal,
            'charge_to_customer' => $charge_to_customer,
            'charge_to_partner' => $charge_to_partner,
            'adjustment' => $adjustment,
            'settlement_amount' => $settlement_amount,
            'status' => $status,
            'is_fully_settled' => $is_fully_settled,
            'is_partially_settled' => $is_partially_settled,
            'settled_count' => $settled_count,
            'unsettled_count' => $unsettled_count,
            'group_key' => $group_key,
            'charge_to' => $charge_to,
            'service_charge' => $charge_sched
        ];
        
        $all_rows[] = $row_data;
        $row_index++;
    }
    
    // Filter out excluded rows
    $excluded_rows_set = array_flip($excluded_rows);
    $filtered_rows = array_filter($all_rows, function($row) use ($excluded_rows_set) {
        return !isset($excluded_rows_set[$row['row_index']]);
    });
    
    // Rebuild groups with filtered rows
    $groups = [
        'CHARGE BY CUSTOMER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY CUSTOMER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY CUSTOMER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER DAILY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER SEMI MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY PARTNER MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH DAILY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH WEEKLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'CHARGE BY BOTH MONTHLY' => [
            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ],
        'UNCATEGORIZED' => [
            'display_name' => '⚠️ PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
            'rows' => [],
            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0]
        ]
    ];
    
    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0];
    
    foreach ($filtered_rows as $row_data) {
        $group_key = $row_data['group_key'];
        
        if (!isset($groups[$group_key])) {
            continue;
        }
        
        $groups[$group_key]['rows'][] = $row_data;
        
        $groups[$group_key]['totals']['txn_count'] += $row_data['txn_count'];
        $groups[$group_key]['totals']['principal'] += $row_data['principal'];
        $groups[$group_key]['totals']['charge_to_customer'] += $row_data['charge_to_customer'];
        $groups[$group_key]['totals']['charge_to_partner'] += $row_data['charge_to_partner'];
        $groups[$group_key]['totals']['adjustment'] += $row_data['adjustment'];
        $groups[$group_key]['totals']['settlement'] += $row_data['settlement_amount'];
        
        $grand_totals['txn_count'] += $row_data['txn_count'];
        $grand_totals['principal'] += $row_data['principal'];
        $grand_totals['charge_to_customer'] += $row_data['charge_to_customer'];
        $grand_totals['charge_to_partner'] += $row_data['charge_to_partner'];
        $grand_totals['adjustment'] += $row_data['adjustment'];
        $grand_totals['settlement'] += $row_data['settlement_amount'];
    }
    
    $groups = array_filter($groups, function($group) {
        return !empty($group['rows']);
    });

    // CHECK FOR UNSETTLED TRANSACTIONS
    $has_unsettled = false;
    foreach ($groups as $group_data) {
        foreach ($group_data['rows'] as $row_data) {
            if ($row_data['status'] !== 'Settled') {
                $has_unsettled = true;
                break 2;
            }
        }
    }

    if ($has_unsettled) {
        die("Cannot export PDF: There are unsettled transactions. Please settle all transactions before exporting.");
    }
    
    // ============================================
    // CAD NUMBER GENERATION - FIXED
    // ============================================
    
    // Prefer existing CAD from DB if it is valid for this RFP + bank
    $existing_cad = '';
    if (!empty($selected_rfp_no)) {
        $cad_query = "SELECT DISTINCT cad_no FROM mldb.billspayment_transaction 
                      WHERE rfp_no = ? AND cad_no IS NOT NULL AND cad_no != '' LIMIT 1";
        $cad_stmt = $conn->prepare($cad_query);
        if ($cad_stmt) {
            $cad_stmt->bind_param("s", $selected_rfp_no);
            $cad_stmt->execute();
            $cad_result = $cad_stmt->get_result();
            if ($cad_row = $cad_result->fetch_assoc()) {
                $existing_cad = trim($cad_row['cad_no']);
            }
            $cad_stmt->close();
        }
    }

    // Resolve bank abbreviation
    $bank_abbreviation = '';
    if (!empty($selected_bank)) {
        $bank_abbreviation = getBankAbbreviation($conn, $selected_bank);
    }
    if (empty($bank_abbreviation) && !empty($selected_partner)) {
        $abbr_query = "SELECT DISTINCT bank_abbreviation FROM masterdata.partner_masterfile 
                       WHERE partner_id_kpx = ? AND bank_abbreviation IS NOT NULL AND bank_abbreviation != '' LIMIT 1";
        $abbr_stmt = $conn->prepare($abbr_query);
        if ($abbr_stmt) {
            $abbr_stmt->bind_param("s", $selected_partner);
            $abbr_stmt->execute();
            $abbr_result = $abbr_stmt->get_result();
            if ($abbr_row = $abbr_result->fetch_assoc()) {
                $bank_abbreviation = strtoupper(trim($abbr_row['bank_abbreviation']));
            }
            $abbr_stmt->close();
        }
    }

    // Decide whether the existing CAD is usable
    $cad_number = '';
    $use_existing = false;
    if (!empty($existing_cad) && !empty($bank_abbreviation)) {
        if (stripos($existing_cad, $bank_abbreviation . '-') === 0) {
            $use_existing = true;
            $cad_number = $existing_cad;
        }
    } elseif (!empty($existing_cad) && empty($bank_abbreviation) && stripos($existing_cad, 'RFP-') !== 0) {
        $use_existing = true;
        $cad_number = $existing_cad;
    }

    if (!$use_existing) {
        $settlement_abbr = '';
        if (!empty($selected_settlement_type)) {
            $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
        }

        $cad_date = formatCADDate($selected_date_from, $selected_date_to);

        if (!empty($bank_abbreviation)) {
            $cad_number = $bank_abbreviation;
        } else {
            error_log("PDF Export - WARNING: No bank abbreviation found for bank='$selected_bank' partner='$selected_partner'. Using 'RFP' as fallback.");
            $cad_number = 'RFP';
        }

        if (!empty($settlement_abbr)) {
            $cad_number .= '-' . $settlement_abbr;
        }

        $cad_number .= '-' . $cad_date;
    }

    error_log("PDF Export - Final CAD Number: " . $cad_number);
    
    // Format date range for display
    $date_range_display = formatDateRange($selected_date_from, $selected_date_to);
    
    // Current date for the header
    $current_date = strtoupper(date('F d, Y'));

} catch (Exception $e) {
    error_log("Error in export_bank_settlement_pdf: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}

// ============================================
// GENERATE HTML FOR PDF
// ============================================

$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Settlement Report</title>
    <style>
        @page {
            margin: 15mm 12mm 15mm 12mm;
            font-size: 9pt;
        }
        body {
            font-family: "Helvetica", "Arial", sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #333;
        }
        .header-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: 1px;
            padding: 4px 0;
            border-bottom: 2px solid #000;
            margin-bottom: 6px;
        }
        .company-name {
            font-size: 11pt;
            font-weight: bold;
            float: left;
        }
        .date-info {
            font-size: 9pt;
            font-weight: bold;
            float: right;
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
        .info-row {
            margin: 2px 0;
            font-size: 9pt;
        }
        .info-row strong {
            font-weight: bold;
        }
        .section-spacer {
            height: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            margin-top: 4px;
        }
        table th {
            background-color: #e9ecef;
            font-weight: bold;
            text-align: center;
            padding: 4px 6px;
            border: 1px solid #333;
            font-size: 7.5pt;
        }
        table td {
            padding: 3px 6px;
            border: 1px solid #333;
            vertical-align: middle;
            font-size: 7.5pt;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .group-header td {
            font-weight: bold;
            background-color: #ffffff;
            padding: 4px 8px;
            font-size: 8pt;
        }
        .group-header-uncategorized td {
            background-color: #fff3cd;
            color: #856404;
        }
        .group-header-both td {
            background-color: #FFFFFF;
            color: #000000;
        }
        .subtotal-row td {
            font-weight: bold;
            background-color: #e8f4fd;
            padding: 4px 6px;
        }
        .subtotal-uncategorized td {
            background-color: #fff3cd;
        }
        .subtotal-both td {
            background-color: #FFFFFF;
        }
        .grand-total td {
            font-weight: bold;
            background-color: #f8f9fa;
            padding: 5px 6px;
            border-top: 2px solid #333;
            font-size: 8.5pt;
        }
        .negative-amount {
            color: #dc3545;
        }
        .positive-amount {
            color: #28a745;
        }
        .footer {
            margin-top: 8px;
            font-size: 7pt;
            text-align: center;
            color: #6c757d;
            border-top: 1px solid #ddd;
            padding-top: 4px;
        }
        .signature-section {
            margin-top: 15px;
            font-size: 8pt;
        }
        .signature-section table {
            border: none;
            width: 100%;
            margin-top: 4px;
        }
        .signature-section td {
            border: none;
            padding: 4px 12px 4px 0;
            vertical-align: top;
            text-align: left;
        }
        .signature-name {
            font-weight: bold;
            font-size: 9pt;
            margin-bottom: 2px;
        }
        .signature-blank {
            border-bottom: 1px solid #333;
            width: 180px;
            display: inline-block;
            height: 1px;
            margin-top: 2px;
        }
        .signature-title {
            font-size: 7pt;
            color: #555;
            margin-top: 2px;
        }
        .page-break {
            page-break-after: always;
        }
        .amount-col {
            font-weight: 500;
        }
        .col-partner { width: 28%; }
        .col-account { width: 22%; }
        .col-acctno { width: 25%; }
        .col-settlement { width: 25%; text-align: right; }
    </style>
</head>
<body>';

// HEADER SECTION
$html .= '
<div class="header-title">REQUEST FOR PAYMENT FORM</div>

<div class="clearfix">
    <div class="company-name">M. LHUILLIER PHILIPPINES, INC.</div>
    <div class="date-info">DATE: ' . $current_date . '</div>
</div>

<div class="clearfix" style="margin-top: 2px;">
    <div style="float: left; font-size: 10pt; font-weight: bold;">BILLS PAYMENT SETTLEMENT</div>
    <div style="float: right; font-size: 9pt; font-weight: bold;">CAD NO.: ' . htmlspecialchars($cad_number) . '</div>
</div>

<div style="text-align: right; font-size: 9pt; font-weight: bold; margin-top: 2px;">RFP NO.: ' . htmlspecialchars($selected_rfp_no) . '</div>

<div class="section-spacer"></div>

<div class="info-row"><strong>BANK NAME:</strong> ' . htmlspecialchars($selected_bank ?: '') . '</div>
<div class="info-row"><strong>DATE OF TRANSACTION:</strong> ' . $date_range_display . '</div>
<div class="info-row"><strong>MODE OF PAYMENT:</strong> </div>

<div class="section-spacer"></div>';

// TABLE SECTION
$html .= '<table>
    <thead>
        <tr>
            <th class="col-partner">LIST OF BILLS PAYMENT PARTNER</th>
            <th class="col-account">ACCOUNT NAME</th>
            <th class="col-acctno">ACCOUNT NUMBER</th>
            <th class="col-settlement">AMOUNT FOR SETTLEMENT</th>
        </tr>
    </thead>
    <tbody>';

// DATA ROWS
foreach ($groups as $group_key => $group_data) {
    if (empty($group_data['rows'])) {
        continue;
    }
    
    $is_uncategorized = ($group_key === 'UNCATEGORIZED');
    $is_both = (strpos($group_key, 'BOTH') !== false);
    
    // Group header
    $group_class = '';
    if ($is_uncategorized) {
        $group_class = 'group-header-uncategorized';
    } elseif ($is_both) {
        $group_class = 'group-header-both';
    }
    
    $html .= '<tr class="group-header ' . $group_class . '">';
    $html .= '<td colspan="4">' . htmlspecialchars($group_data['display_name']) . '</td>';
    $html .= '</tr>';
    
    // Data rows
    foreach ($group_data['rows'] as $row_data) {
        $settlement = $row_data['settlement_amount'];
        $settlement_class = ($settlement < 0) ? 'negative-amount' : '';
        
        $html .= '<tr>';
        $html .= '<td class="text-left">' . htmlspecialchars($row_data['partner_name']) . '</td>';
        $html .= '<td class="text-left">' . htmlspecialchars($row_data['account_name']) . '</td>';
        $html .= '<td class="text-center">' . htmlspecialchars($row_data['account_number']) . '</td>';
        $html .= '<td class="text-right amount-col ' . $settlement_class . '"> ' . number_format($settlement, 2) . '</td>';
        $html .= '</tr>';
    }
}

// GRAND TOTAL
$settlement_class = ($grand_totals['settlement'] < 0) ? 'negative-amount' : '';

$html .= '<tr class="grand-total">';
$html .= '<td colspan="3" class="text-right"><strong>GRAND TOTAL</strong></td>';
$html .= '<td class="text-right amount-col ' . $settlement_class . '"> ' . number_format($grand_totals['settlement'], 2) . '</td>';
$html .= '</tr>';

$html .= '</tbody></table>';

// SIGNATURE SECTION
$html .= '
<div class="signature-section">
    <table class="signature-table">
        <tr>
            <td style="width: 50%; text-align: left;">
                <div style="font-weight: bold; font-size: 9pt;">Prepared by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">' . htmlspecialchars($display_name) . '</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Accounting Staff</div>
                </div>
            </td>
            <td style="width: 50%; text-align: left;">
                <div style="font-weight: bold; font-size: 9pt;">Checked by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">{Accounting Staff}</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Accounting Staff</div>
                </div>
            </td>
        </tr>
        <tr>
            <td style="width: 50%; text-align: left; padding-top: 15px;">
                <div style="font-weight: bold; font-size: 9pt;">Reviewed by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">ELVIE CILLO</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Department Manager</div>
                </div>
            </td>
            <td style="width: 50%; text-align: left; padding-top: 15px;">
                <div style="font-weight: bold; font-size: 9pt;">Noted by :</div>
                <div style="margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 9pt;">LUELLA PERALTA</div>
                    <span class="signature-blank"></span>
                    <div style="font-size: 7pt; color: #555;">Division Manager</div>
                </div>
            </td>
        </tr>
    </table>
</div>

</body>
</html>';

// ============================================
// GENERATE PDF
// ============================================

try {
    $options = new Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('isFontSubsettingEnabled', true);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Output PDF
    $filename = $cad_number . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $dompdf->output();
    exit;
    
} catch (Exception $e) {
    error_log("PDF Generation Error: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}
?>