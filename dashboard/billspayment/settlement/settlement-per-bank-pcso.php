<?php
// settlement-per-bank-pcso.php

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Settlement Per Bank','Bills Payment'])) { header('Location: ../../home.php'); exit; }

$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// ============================================
// PCSO / LANDBANK partner IDs — the ONLY partners this page handles
// ============================================
function getPcsoPartnerIds(): array {
    return ['631','648','650','656','658','660','662','670','680'];
}

function getPcsoLandbankSets(): array {
    return [
        'NCR' => [
            'suffix' => 'NCR',
            'partner_ids' => ['631'],
            'region_label' => 'PCSO NCR',
        ],
        'VISAYAS' => [
            'suffix' => 'VISAYAS',
            'partner_ids' => ['648', '650', '656', '658', '660'],
            'region_label' => 'PCSO VISAYAS',
        ],
        'MINDANAO' => [
            'suffix' => 'MINDANAO',
            'partner_ids' => ['662', '670', '680'],
            'region_label' => 'PCSO MINDANAO',
        ],
    ];
}

// Column in billspayment_transaction that holds the outlet/branch name.
const PCSO_BRANCH_COLUMN = 'outlet';

// ============================================
// PCSO-NCR Agent No. mapping (by outlet / branch name)
// ============================================
function getPcsoNcrAgentNo(string $region_label, string $branch_name): string {
    // Only PCSO-NCR rows get an Agent No.
    if (strtoupper(trim($region_label)) !== 'PCSO NCR') return '';
    $branch = strtoupper(trim($branch_name));
    $map = [
        'ML MAKATI AVENUE' => '10341035',
        'ML PASAY ROAD'    => '10341027',
    ];
    return $map[$branch] ?? '';
}

// ============================================
// Shared helper functions
// ============================================
function getBankAbbreviation(mysqli $conn, string $bank_name): string {
    if (empty($bank_name)) return '';
    $bank_name = trim($bank_name);

    $query = "SELECT bank_abbreviation FROM mldb.bank_table WHERE bank_name = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['bank_abbreviation'])) { $stmt->close(); return strtoupper(trim($row['bank_abbreviation'])); }
        }
        $stmt->close();
    }
    $query = "SELECT bank_abbreviation FROM mldb.bank_table 
              WHERE UPPER(bank_name) LIKE CONCAT('%', UPPER(?), '%') 
                 OR UPPER(?) LIKE CONCAT('%', UPPER(bank_name), '%') LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("ss", $bank_name, $bank_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['bank_abbreviation'])) { $stmt->close(); return strtoupper(trim($row['bank_abbreviation'])); }
        }
        $stmt->close();
    }
    $query2 = "SELECT DISTINCT bank_abbreviation FROM masterdata.partner_masterfile 
               WHERE (bank = ? OR UPPER(bank) LIKE CONCAT('%', UPPER(?), '%'))
                 AND bank_abbreviation IS NOT NULL AND bank_abbreviation != '' LIMIT 1";
    $stmt2 = $conn->prepare($query2);
    if ($stmt2) {
        $stmt2->bind_param("ss", $bank_name, $bank_name);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($row2 = $result2->fetch_assoc()) {
            if (!empty($row2['bank_abbreviation'])) { $stmt2->close(); return strtoupper(trim($row2['bank_abbreviation'])); }
        }
        $stmt2->close();
    }
    return '';
}

function getPartnerBank(mysqli $conn, string $partner_id): string {
    if (empty($partner_id)) return '';
    $query = "SELECT bank FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $partner_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) { $stmt->close(); return trim($row['bank'] ?? ''); }
        $stmt->close();
    }
    return '';
}

function getSettlementAbbreviation(string $settlement_type): string {
    if (empty($settlement_type)) return '';
    $type = strtoupper(trim($settlement_type));
    if ($type === 'CHECK' || $type === 'CHEQUE') return 'CHK';
    if ($type === 'ONLINE' || $type === 'ONL') return 'ONL';
    return strtoupper(substr($type, 0, 3));
}

function normalizeChargeSched(?string $charge_sched): string {
    $value = strtoupper(trim((string)$charge_sched));
    if ($value === '' || $value === 'NOT APPLICABLE' || $value === 'NO-BANK-SETTLEMENT') return '';
    if ($value === 'PER TRANSACTION') return 'DAILY';
    return $value;
}

function formatCADDate(?string $date_from, ?string $date_to): string {
    if (empty($date_from) && empty($date_to)) return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    $date = !empty($date_to) ? $date_to : $date_from;
    $timestamp = strtotime($date);
    if ($timestamp === false) return date('Y-m') . '-' . sprintf('%05d', (int)date('d'));
    return date('Y-m', $timestamp) . '-' . sprintf('%05d', (int)date('d', $timestamp));
}

function calculateSettlementAmount($charge_to, $charge_sched, $principal, $charge_to_customer, $charge_to_partner, $adjustment, $partner_id = '', $txn_count = 0) {
    if ((string)$partner_id === '34') return (float)$txn_count + (float)$principal;
    if ((string)$partner_id === '107') {
        $vat_amount = ($charge_to_partner / 1.12) * 0.02;
        $charge_amount = $charge_to_partner - $vat_amount;
        return ($principal - $charge_amount) + $adjustment;
    }
    if ((string)$partner_id === '811') {
        $vat_amount = (($txn_count * 10) / 1.12) * 0.02;
        $transaction_amount = ($txn_count * 10) - $vat_amount;
        return $principal - $transaction_amount + $adjustment;
    }
    $charge_to_upper = strtoupper(trim($charge_to));
    $charge_sched_upper = normalizeChargeSched($charge_sched);
    if (empty($charge_to_upper)) return (float)$principal + (float)$adjustment;
    if (($charge_to_upper === 'PARTNER' || $charge_to_upper === 'CUSTOMER') &&
        in_array($charge_sched_upper, ['WEEKLY', 'MONTHLY', 'SEMI-MONTHLY'])) return $principal + $adjustment;
    if (($charge_to_upper === 'CUSTOMER' || $charge_to_upper === 'PARTNER' || $charge_to_upper === 'BOTH')
        && $charge_sched_upper === 'DAILY') return $principal - $charge_to_partner + $adjustment;
    if ($charge_to_upper === 'BOTH') return $principal + $charge_to_customer + $charge_to_partner + $adjustment;
    return (float)$principal + (float)$adjustment;
}

// ============================================
// FETCH FILTER DATA — only PCSO partners / their banks / settlement types
// ============================================
$pcso_partner_ids = getPcsoPartnerIds();
$pcso_placeholders = implode(',', array_fill(0, count($pcso_partner_ids), '?'));

try {
    $partners_query = "SELECT DISTINCT partner_id_kpx, partner_name, bank 
                       FROM masterdata.partner_masterfile 
                       WHERE partner_id_kpx IN ($pcso_placeholders)
                       ORDER BY partner_name";
    $stmt = $conn->prepare($partners_query);
    $partners = [];
    if ($stmt) {
        $stmt->bind_param(str_repeat('s', count($pcso_partner_ids)), ...$pcso_partner_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $partners[] = $row;
        $stmt->close();
    }

    $banks_query = "SELECT DISTINCT bank FROM masterdata.partner_masterfile 
                    WHERE partner_id_kpx IN ($pcso_placeholders)
                      AND bank IS NOT NULL AND bank != '' ORDER BY bank";
    $stmt = $conn->prepare($banks_query);
    $banks = [];
    if ($stmt) {
        $stmt->bind_param(str_repeat('s', count($pcso_partner_ids)), ...$pcso_partner_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $banks[] = $row;
        $stmt->close();
    }

    $settlement_types_query = "SELECT DISTINCT settled_online_check FROM masterdata.partner_masterfile 
                               WHERE partner_id_kpx IN ($pcso_placeholders)
                                 AND settled_online_check IS NOT NULL AND settled_online_check != '' 
                               ORDER BY settled_online_check";
    $stmt = $conn->prepare($settlement_types_query);
    $settlement_types = [];
    if ($stmt) {
        $stmt->bind_param(str_repeat('s', count($pcso_partner_ids)), ...$pcso_partner_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $settlement_types[] = $row;
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching PCSO filter data: " . $e->getMessage());
    $partners = []; $banks = []; $settlement_types = [];
}

$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$selected_rfp_no = isset($_GET['rfp_no']) ? trim($_GET['rfp_no']) : '';
$include_lookback = isset($_GET['include_lookback']) ? trim($_GET['include_lookback']) : '';

$has_filters = !empty(array_filter($_GET));

// Reject non-PCSO partner if one is somehow posted
if (!empty($selected_partner) && !in_array($selected_partner, $pcso_partner_ids, true)) {
    $selected_partner = '';
}

$auto_selected_bank = '';
if (!empty($selected_partner) && empty($selected_bank)) {
    $auto_selected_bank = getPartnerBank($conn, $selected_partner);
    if (!empty($auto_selected_bank)) {
        $selected_bank = $auto_selected_bank;
        $_GET['bank'] = $auto_selected_bank;
    }
}

// Auto-populate date range from RFP No.
if (!empty($selected_rfp_no) && empty($selected_date_from) && empty($selected_date_to)) {
    $rfp_date_query = "SELECT DATE(MIN(bt.datetime)) AS min_date, DATE(MAX(bt.datetime)) AS max_date
                       FROM mldb.billspayment_transaction bt
                       WHERE bt.rfp_no = ? AND bt.datetime IS NOT NULL LIMIT 1";
    $rfp_date_stmt = $conn->prepare($rfp_date_query);
    if ($rfp_date_stmt) {
        $rfp_date_stmt->bind_param("s", $selected_rfp_no);
        $rfp_date_stmt->execute();
        $rfp_date_result = $rfp_date_stmt->get_result();
        if ($rfp_date_row = $rfp_date_result->fetch_assoc()) {
            $min_date = $rfp_date_row['min_date'] ?? '';
            $max_date = $rfp_date_row['max_date'] ?? '';
            if (!empty($min_date) && !empty($max_date)) {
                $selected_date_from = $min_date;
                $selected_date_to   = $max_date;
                $_GET['date_from']  = $min_date;
                $_GET['date_to']    = $max_date;
            }
        }
        $rfp_date_stmt->close();
    }
}

// CAD number generation
$bank_abbreviation = '';
$cad_number = '';
$cad_generated = false;

$existing_cad = '';
if (!empty($selected_rfp_no)) {
    $cad_query = "SELECT DISTINCT cad_no FROM mldb.billspayment_transaction 
                  WHERE rfp_no = ? AND cad_no IS NOT NULL AND cad_no != '' LIMIT 1";
    $cad_stmt = $conn->prepare($cad_query);
    if ($cad_stmt) {
        $cad_stmt->bind_param("s", $selected_rfp_no);
        $cad_stmt->execute();
        $cad_result = $cad_stmt->get_result();
        if ($cad_row = $cad_result->fetch_assoc()) $existing_cad = trim($cad_row['cad_no']);
        $cad_stmt->close();
    }
}

if (!empty($selected_bank)) $bank_abbreviation = getBankAbbreviation($conn, $selected_bank);

if (empty($bank_abbreviation) && !empty($selected_partner)) {
    $abbr_query = "SELECT DISTINCT bank_abbreviation FROM masterdata.partner_masterfile 
                   WHERE partner_id_kpx = ? AND bank_abbreviation IS NOT NULL AND bank_abbreviation != '' LIMIT 1";
    $abbr_stmt = $conn->prepare($abbr_query);
    if ($abbr_stmt) {
        $abbr_stmt->bind_param("s", $selected_partner);
        $abbr_stmt->execute();
        $abbr_result = $abbr_stmt->get_result();
        if ($abbr_row = $abbr_result->fetch_assoc()) $bank_abbreviation = strtoupper(trim($abbr_row['bank_abbreviation']));
        $abbr_stmt->close();
    }
}

$use_existing = false;
if (!empty($existing_cad) && !empty($bank_abbreviation)) {
    if (stripos($existing_cad, $bank_abbreviation . '-') === 0) {
        $use_existing = true; $cad_number = $existing_cad; $cad_generated = true;
    }
} elseif (!empty($existing_cad) && empty($bank_abbreviation) && stripos($existing_cad, 'RFP-') !== 0) {
    $use_existing = true; $cad_number = $existing_cad; $cad_generated = true;
}

if (!$use_existing) {
    $settlement_abbr = '';
    if (!empty($selected_settlement_type)) $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
    $cad_date = formatCADDate($selected_date_from, $selected_date_to);
    if (!empty($bank_abbreviation)) { $cad_number = $bank_abbreviation; $cad_generated = true; }
    else { $cad_number = 'RFP'; $cad_generated = false; }
    if (!empty($settlement_abbr)) $cad_number .= '-' . $settlement_abbr;
    $cad_number .= '-' . $cad_date;
}

if (!empty(array_filter($_GET))) $_SESSION['settlement_filters'] = $_GET;

$has_required_dates = (!empty($selected_date_from) && !empty($selected_date_to));
$has_date_range = false;
if (!empty($selected_date_from) && !empty($selected_date_to)) {
    if ($selected_date_from !== $selected_date_to) $has_date_range = true;
} elseif (!empty($selected_date_from) || !empty($selected_date_to)) {
    $has_date_range = false;
}

// ============================================
// PCSO BRANCH-LEVEL BREAKDOWN
// Groups transactions by outlet/branch across all partner IDs in the group.
// ============================================
function getPcsoLandbankBranchBreakdown(
    mysqli $conn,
    array $partner_ids,
    string $bank,
    string $settlement_type,
    string $date_from,
    string $date_to,
    string $region_label
): array {
    if (empty($partner_ids)) return [];

    $branch_col = PCSO_BRANCH_COLUMN;
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

    $is_single = (!empty($date_from) && !empty($date_to) && $date_from === $date_to);
    $lb_from = null; $lb_to = null;
    $allow_lb = (isset($_GET['include_lookback']) && $_GET['include_lookback'] === '1');
    if ($is_single && $allow_lb) {
        $lb_from = date('Y-m-d', strtotime($date_from . ' -7 days'));
        $lb_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
    }

    if ($is_single && $allow_lb && $lb_from && $lb_to) {
        $where_regular[] = "(
            (bt.datetime BETWEEN ? AND ?)
            OR
            (
                bt.datetime BETWEEN ? AND ?
                AND UPPER(TRIM(COALESCE(bt.import_status, ''))) = 'LATE POSTING'
                AND (bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR UPPER(TRIM(bt.settle_unsettle)) != 'SETTLED')
            )
        )";
        $params_regular[] = $date_from . ' 00:00:00';
        $params_regular[] = $date_to   . ' 23:59:59';
        $params_regular[] = $lb_from . ' 00:00:00';
        $params_regular[] = $lb_to   . ' 23:59:59';
        $types_regular .= "ssss";
    } elseif (!empty($date_from) && !empty($date_to)) {
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
            TRIM(COALESCE(bt.{$branch_col}, '')) AS branch_name,
            pm.partner_accName,
            pm.bank_accNumber,
            pm.bank,
            pm.settled_online_check AS settlement_type,
            COALESCE(pm.charge_to, '') AS charge_to,
            COALESCE(pm.charge_sched, '') AS charge_sched,
            COUNT(*) AS txn_count,
            SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) AS total_principal,
            SUM(bt.charge_to_customer) AS charge_to_customer,
            SUM(bt.charge_to_partner) AS charge_to_partner,
            SUM(CASE WHEN bt.settle_unsettle = 'Settled' THEN 1 ELSE 0 END) AS settled_count,
            SUM(CASE WHEN bt.settle_unsettle IS NULL
                      OR bt.settle_unsettle = ''
                      OR bt.settle_unsettle != 'Settled'
                 THEN 1 ELSE 0 END) AS unsettled_count,
            MAX(bt.datetime) AS last_transaction_date,
            MIN(bt.datetime) AS first_transaction_date
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE " . implode(" AND ", $where_regular) . "
        GROUP BY TRIM(COALESCE(bt.{$branch_col}, '')),
                 pm.partner_accName, pm.bank_accNumber, pm.bank,
                 pm.settled_online_check, pm.charge_to, pm.charge_sched
        ORDER BY branch_name ASC";

    $adjustment_sql = "SELECT
            TRIM(COALESCE(bt.{$branch_col}, '')) AS branch_name,
            SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) AS total_adjustment
        FROM mldb.billspayment_transaction bt
        LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
        WHERE " . implode(" AND ", $where_adjustment) . "
        GROUP BY TRIM(COALESCE(bt.{$branch_col}, ''))";

    $adjustments = [];

    $stmt = $conn->prepare($adjustment_sql);
    if ($stmt) {
        $stmt->bind_param($types_adjustment, ...$params_adjustment);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $key = $row['branch_name'] ?? '';
            $adjustments[$key] = (float)($row['total_adjustment'] ?? 0);
        }
        $stmt->close();
    }

    $entries = [];
    $stmt = $conn->prepare($regular_sql);
    if ($stmt) {
        $stmt->bind_param($types_regular, ...$params_regular);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $branch = $row['branch_name'] ?? '';
            if ($branch === '') $branch = '(No Branch)';

            $entries[] = [
                'partner_id_kpx'      => 'pcso-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $branch)),
                'partner_name'        => $branch,
                'branch_name'         => $branch,
                'agent_no'            => getPcsoNcrAgentNo($region_label, $branch),
                'partner_accName'     => $row['partner_accName'] ?? 'N/A',
                'bank_accNumber'      => $row['bank_accNumber'] ?? 'N/A',
                'bank'                => $row['bank'] ?? '',
                'settlement_type'     => $row['settlement_type'] ?? '',
                'charge_to'           => $row['charge_to'] ?? '',
                'charge_sched'        => $row['charge_sched'] ?? '',
                'settle_unsettle'     => '',
                'txn_count'           => (int)($row['txn_count'] ?? 0),
                'total_principal'     => (float)($row['total_principal'] ?? 0),
                'charge_to_customer'  => (float)($row['charge_to_customer'] ?? 0),
                'charge_to_partner'   => (float)($row['charge_to_partner'] ?? 0),
                'total_adjustment'    => $adjustments[$branch] ?? 0,
                'settled_count'       => (int)($row['settled_count'] ?? 0),
                'unsettled_count'     => (int)($row['unsettled_count'] ?? 0),
                'last_transaction_date'  => $row['last_transaction_date'] ?? null,
                'first_transaction_date' => $row['first_transaction_date'] ?? null,
                'pcso_region'         => $region_label,
                'is_pcso_landbank'    => true,
                'is_pcso_branch_row'  => true,
            ];
        }
        $stmt->close();
    }

    return $entries;
}

// ============================================
// DAILY BREAKDOWN (for the per-branch expander)
// 8th parameter is an array: ['branch_name' => '...'] for PCSO branch rows.
// ============================================
function getDailyBreakdown(
    mysqli $conn,
    string $partner_id,
    string $bank,
    string $settlement_type,
    string $date_from,
    string $date_to,
    array $regions = [],
    ?array $branch_filter = null
) {
    if (empty($partner_id)) return [];

    $where_conditions_regular = [];
    $where_conditions_adjustment = [];
    $params_regular = []; $params_adjustment = [];
    $types_regular = ""; $types_adjustment = "";

    // PCSO branch rows use a synthetic partner_id like "pcso-ncr-branch-x".
    // Use the outlet column match for that branch instead of partner_id_kpx.
    $is_branch = (strpos($partner_id, 'pcso-') === 0);

    if ($is_branch) {
        $branch_name = $branch_filter['branch_name'] ?? '';
        if ($branch_name === '') return [];
        $where_conditions_regular[] = "TRIM(COALESCE(bt." . PCSO_BRANCH_COLUMN . ", '')) = ?";
        $params_regular[] = $branch_name; $types_regular .= "s";
        $where_conditions_adjustment[] = "TRIM(COALESCE(bt." . PCSO_BRANCH_COLUMN . ", '')) = ?";
        $params_adjustment[] = $branch_name; $types_adjustment .= "s";
        if (!empty($bank)) {
            $where_conditions_regular[] = "pm.bank = ?"; $params_regular[] = $bank; $types_regular .= "s";
            $where_conditions_adjustment[] = "pm.bank = ?"; $params_adjustment[] = $bank; $types_adjustment .= "s";
        }
        if (!empty($settlement_type)) {
            $where_conditions_regular[] = "pm.settled_online_check = ?"; $params_regular[] = $settlement_type; $types_regular .= "s";
            $where_conditions_adjustment[] = "pm.settled_online_check = ?"; $params_adjustment[] = $settlement_type; $types_adjustment .= "s";
        }
    } else {
        $where_conditions_regular[] = "bt.partner_id_kpx = ?";
        $params_regular[] = $partner_id; $types_regular .= "s";
        $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
        $params_adjustment[] = $partner_id; $types_adjustment .= "s";

        if (!empty($regions)) {
            $placeholders = implode(',', array_fill(0, count($regions), '?'));
            $where_conditions_regular[] = "bt.region IN ($placeholders)";
            $where_conditions_adjustment[] = "bt.region IN ($placeholders)";
            foreach ($regions as $reg) {
                $params_regular[] = $reg; $types_regular .= "s";
                $params_adjustment[] = $reg; $types_adjustment .= "s";
            }
        }
        if (!empty($bank)) {
            $where_conditions_regular[] = "pm.bank = ?"; $params_regular[] = $bank; $types_regular .= "s";
            $where_conditions_adjustment[] = "pm.bank = ?"; $params_adjustment[] = $bank; $types_adjustment .= "s";
        }
        if (!empty($settlement_type)) {
            $where_conditions_regular[] = "pm.settled_online_check = ?"; $params_regular[] = $settlement_type; $types_regular .= "s";
            $where_conditions_adjustment[] = "pm.settled_online_check = ?"; $params_adjustment[] = $settlement_type; $types_adjustment .= "s";
        }
    }

    $is_single = (!empty($date_from) && !empty($date_to) && $date_from === $date_to);
    $lb_from = null; $lb_to = null;
    $allow_lb = (isset($_GET['include_lookback']) && $_GET['include_lookback'] === '1');
    if ($is_single && $allow_lb) {
        $lb_from = date('Y-m-d', strtotime($date_from . ' -7 days'));
        $lb_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
    }
    if ($is_single && $allow_lb && $lb_from && $lb_to) {
        $where_conditions_regular[] = "(
            (bt.datetime BETWEEN ? AND ?)
            OR
            (
                bt.datetime BETWEEN ? AND ?
                AND UPPER(TRIM(COALESCE(bt.import_status, ''))) = 'LATE POSTING'
                AND (bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR UPPER(TRIM(bt.settle_unsettle)) != 'SETTLED')
            )
        )";
        $params_regular[] = $date_from . ' 00:00:00';
        $params_regular[] = $date_to   . ' 23:59:59';
        $params_regular[] = $lb_from . ' 00:00:00';
        $params_regular[] = $lb_to   . ' 23:59:59';
        $types_regular .= "ssss";
    } elseif (!empty($date_from) && !empty($date_to)) {
        $where_conditions_regular[] = "bt.datetime BETWEEN ? AND ?";
        $params_regular[] = $date_from . ' 00:00:00';
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "ss";
    } elseif (!empty($date_from)) {
        $where_conditions_regular[] = "bt.datetime >= ?";
        $params_regular[] = $date_from . ' 00:00:00'; $types_regular .= "s";
    } elseif (!empty($date_to)) {
        $where_conditions_regular[] = "bt.datetime <= ?";
        $params_regular[] = $date_to . ' 23:59:59'; $types_regular .= "s";
    }
    $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";

    if (!empty($date_from) && !empty($date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $params_adjustment[] = $date_to . ' 23:59:59'; $types_adjustment .= "ss";
    } elseif (!empty($date_from)) {
        $where_conditions_adjustment[] = "bt.cancellation_date >= ?";
        $params_adjustment[] = $date_from . ' 00:00:00'; $types_adjustment .= "s";
    } elseif (!empty($date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date <= ?";
        $params_adjustment[] = $date_to . ' 23:59:59'; $types_adjustment .= "s";
    }
    $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";

    $sql = "SELECT transaction_date, SUM(txn_count) as txn_count, SUM(total_principal) as total_principal,
                SUM(charge_to_customer) as charge_to_customer, SUM(charge_to_partner) as charge_to_partner,
                SUM(total_adjustment) as total_adjustment, MAX(settle_status) as settle_status
            FROM (
                SELECT DATE(bt.datetime) as transaction_date, COUNT(*) as txn_count,
                    SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                    SUM(bt.charge_to_customer) as charge_to_customer, SUM(bt.charge_to_partner) as charge_to_partner,
                    0 as total_adjustment, MAX(bt.settle_unsettle) as settle_status
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_regular) . "
                GROUP BY DATE(bt.datetime)
                UNION ALL
                SELECT DATE(bt.cancellation_date) as transaction_date, 0 as txn_count, 0 as total_principal,
                    0 as charge_to_customer, 0 as charge_to_partner,
                    SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment,
                    '' as settle_status
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                GROUP BY DATE(bt.cancellation_date)
            ) combined
            GROUP BY transaction_date ORDER BY transaction_date ASC";

    $all_params = array_merge($params_regular, $params_adjustment);
    $all_types = $types_regular . $types_adjustment;

    if (!empty($all_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($all_types, ...$all_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $status = $row['settle_status'] ?? '';
                $is_settled = (strtoupper(trim($status)) === 'SETTLED');
                $charge_to = ''; $charge_sched = '';
                if (!$is_branch) {
                    $p_sql = "SELECT COALESCE(charge_to, '') as charge_to, COALESCE(charge_sched, '') as charge_sched 
                              FROM masterdata.partner_masterfile WHERE partner_id_kpx = ?";
                    $p_stmt = $conn->prepare($p_sql);
                    if ($p_stmt) {
                        $p_stmt->bind_param("s", $partner_id);
                        $p_stmt->execute();
                        $p_res = $p_stmt->get_result();
                        if ($p_row = $p_res->fetch_assoc()) {
                            $charge_to = $p_row['charge_to'] ?? '';
                            $charge_sched = $p_row['charge_sched'] ?? '';
                        }
                        $p_stmt->close();
                    }
                }
                $principal = (float)($row['total_principal'] ?? 0);
                $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
                $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
                $adjustment = (float)($row['total_adjustment'] ?? 0);
                $txn_count = (int)($row['txn_count'] ?? 0);
                $amount = calculateSettlementAmount($charge_to, $charge_sched, $principal, $charge_to_customer, $charge_to_partner, $adjustment, $partner_id, $txn_count);
                $data[] = [
                    'transaction_date' => $row['transaction_date'], 'txn_count' => $txn_count,
                    'total_principal' => $principal, 'charge_to_customer' => $charge_to_customer,
                    'charge_to_partner' => $charge_to_partner, 'total_adjustment' => $adjustment,
                    'amount_for_settlement' => $amount, 'settle_status' => $status, 'is_settled' => $is_settled
                ];
            }
            return $data;
        }
    }
    return [];
}

function generateDailyBreakdownHTML(array $data): string {
    if (empty($data)) {
        return '<div style="text-align:center;padding:10px;color:#6c757d;">No daily transactions found for this date range.</div>';
    }
    $html = '<table class="daily-breakdown-table" style="width:100%;border-collapse:collapse;margin:5px 0;">';
    $html .= '<thead><tr style="background-color:#e9ecef;font-size:12px;">';
    $html .= '<th style="padding:6px 12px;text-align:center;width:120px;">Date</th>';
    $html .= '<th style="padding:6px 12px;text-align:center;">Volume Count</th>';
    $html .= '<th style="padding:6px 12px;text-align:right;">₱ Principal</th>';
    $html .= '<th style="padding:6px 12px;text-align:right;">₱ Charge to Customer</th>';
    $html .= '<th style="padding:6px 12px;text-align:right;">₱ Charge to Partner</th>';
    $html .= '<th style="padding:6px 12px;text-align:right;">₱ Adjustment</th>';
    $html .= '<th style="padding:6px 12px;text-align:right;">₱ Settlement</th>';
    $html .= '<th style="padding:6px 12px;text-align:center;">Status</th></tr></thead><tbody>';
    $dailyTotals = ['txn_count'=>0,'principal'=>0,'charge_to_customer'=>0,'charge_to_partner'=>0,'adjustment'=>0,'settlement'=>0,'settled_count'=>0,'unsettled_count'=>0];
    foreach ($data as $daily) {
        $dailyTotals['txn_count'] += $daily['txn_count'];
        $dailyTotals['principal'] += $daily['total_principal'];
        $dailyTotals['charge_to_customer'] += $daily['charge_to_customer'];
        $dailyTotals['charge_to_partner'] += $daily['charge_to_partner'];
        $dailyTotals['adjustment'] += $daily['total_adjustment'];
        $dailyTotals['settlement'] += $daily['amount_for_settlement'];
        if ($daily['is_settled']) $dailyTotals['settled_count'] += $daily['txn_count']; else $dailyTotals['unsettled_count'] += $daily['txn_count'];
        $adjClass = ''; $adjSign = '';
        $adjAmount = $daily['total_adjustment'];
        if ($adjAmount < 0) $adjClass = 'color:#dc3545;'; elseif ($adjAmount > 0) $adjClass = 'color:#28a745;';
        $adjSign = $adjAmount >= 0 ? '+' : '';
        $settleClass = $daily['amount_for_settlement'] < 0 ? 'color:#dc3545;' : '';
        $statusBadge = $daily['is_settled'] ? '<span class="settled-status"><i class="fas fa-check-circle"></i> Settled</span>' : '<span class="unsettled-status"><i class="fas fa-clock"></i> Unsettled</span>';
        $html .= '<tr class="daily-breakdown-row ' . ($daily['is_settled'] ? 'settled-row' : 'unsettled-row') . '" style="font-size:13px;">';
        $html .= '<td style="padding:6px 12px;text-align:center;font-weight:500;color:#495057;">' . date('M d, Y', strtotime($daily['transaction_date'])) . '</td>';
        $html .= '<td style="padding:6px 12px;text-align:center;">' . number_format($daily['txn_count'], 0) . '</td>';
        $html .= '<td style="padding:6px 12px;text-align:right;"> ' . number_format($daily['total_principal'], 2) . '</td>';
        $html .= '<td style="padding:6px 12px;text-align:right;"> ' . number_format($daily['charge_to_customer'], 2) . '</td>';
        $html .= '<td style="padding:6px 12px;text-align:right;"> ' . number_format($daily['charge_to_partner'], 2) . '</td>';
        if ($daily['total_adjustment'] != 0) $html .= '<td style="padding:6px 12px;text-align:right;' . $adjClass . '">' . $adjSign . ' ' . number_format($daily['total_adjustment'], 2) . '</td>';
        else $html .= '<td style="padding:6px 12px;text-align:right;"></td>';
        $html .= '<td style="padding:6px 12px;text-align:right;font-weight:600;' . $settleClass . '"> ' . number_format($daily['amount_for_settlement'], 2) . '</td>';
        $html .= '<td style="padding:6px 12px;text-align:center;">' . $statusBadge . '</td></tr>';
    }
    $adjClass = $dailyTotals['adjustment'] < 0 ? 'color:#dc3545;' : ($dailyTotals['adjustment'] > 0 ? 'color:#28a745;' : '');
    $adjSign = $dailyTotals['adjustment'] >= 0 ? '+' : '';
    $settleClass = $dailyTotals['settlement'] < 0 ? 'color:#dc3545;' : '';
    $html .= '<tr class="daily-subtotal-row" style="font-size:13px;font-weight:600;background-color:#e9ecef;">';
    $html .= '<td style="padding:6px 12px;text-align:right;font-weight:600;">DAILY SUBTOTAL</td>';
    $html .= '<td style="padding:6px 12px;text-align:center;">' . number_format($dailyTotals['txn_count'], 0) . '</td>';
    $html .= '<td style="padding:6px 12px;text-align:right;"> ' . number_format($dailyTotals['principal'], 2) . '</td>';
    $html .= '<td style="padding:6px 12px;text-align:right;"> ' . number_format($dailyTotals['charge_to_customer'], 2) . '</td>';
    $html .= '<td style="padding:6px 12px;text-align:right;"> ' . number_format($dailyTotals['charge_to_partner'], 2) . '</td>';
    if ($dailyTotals['adjustment'] != 0) $html .= '<td style="padding:6px 12px;text-align:right;' . $adjClass . '">' . $adjSign . ' ' . number_format($dailyTotals['adjustment'], 2) . '</td>';
    else $html .= '<td style="padding:6px 12px;text-align:right;"></td>';
    $html .= '<td style="padding:6px 12px;text-align:right;' . $settleClass . '"> ' . number_format($dailyTotals['settlement'], 2) . '</td>';
    $html .= '<td style="padding:6px 12px;text-align:center;font-size:11px;">';
    if ($dailyTotals['settled_count'] > 0 && $dailyTotals['unsettled_count'] > 0) {
        $html .= '<span style="color:#28a745;">' . number_format($dailyTotals['settled_count']) . ' Settled</span> / <span style="color:#dc3545;">' . number_format($dailyTotals['unsettled_count']) . ' Unsettled</span>';
    } elseif ($dailyTotals['settled_count'] > 0) {
        $html .= '<span class="settled-status">All ' . number_format($dailyTotals['settled_count']) . ' Settled</span>';
    } else {
        $html .= '<span class="unsettled-status">All ' . number_format($dailyTotals['unsettled_count']) . ' Unsettled</span>';
    }
    $html .= '</td></tr></tbody></table>';
    return $html;
}

$display_name = 'GUEST';
$display_email = '';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') { $display_name = $_SESSION['admin_name'] ?? 'ADMIN'; $display_email = $_SESSION['admin_email'] ?? ''; }
    elseif ($_SESSION['user_type'] === 'user') { $display_name = $_SESSION['user_name'] ?? 'USER'; $display_email = $_SESSION['user_email'] ?? ''; }
}

$reason_options = [
    '' => 'Select Reason',
    'For Partner Verification' => 'For Partner Verification',
    'System Error' => 'System Error',
    'For Further Checking' => 'For Further Checking'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PCSO Settlement | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/settlement_bank.css?v=<?= time(); ?>">
</head>
<body>
    <div id="loadingModal">
        <div class="simple-loader-content">
            <div class="simple-loader-ring"></div>
            <h3 class="simple-loader-title">Loading PCSO Settlement Data</h3>
            <p class="simple-loader-subtitle">Please wait a moment</p>
            <div class="simple-loader-progress"><div class="simple-loader-progress-bar"></div></div>
            <div class="simple-loader-time"><i class="far fa-clock"></i><span>Elapsed: <span id="elapsedTime">0</span>s</span></div>
        </div>
    </div>

    <div class="main-container main-content-hidden" id="mainContent">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                <div><h2>PCSO Settlement (LANDBANK)</h2></div>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" action="" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label for="partner">PCSO Partner</label>
                    <select id="partner" name="partner" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_partner); ?>">
                        <option value="">All PCSO Partners</option>
                        <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo htmlspecialchars($partner['partner_id_kpx']); ?>" 
                                data-bank="<?php echo htmlspecialchars($partner['bank'] ?? ''); ?>"
                                <?php echo ($selected_partner == $partner['partner_id_kpx']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($partner['partner_id_kpx'] . ' - ' . $partner['partner_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="bank">Bank</label>
                    <select id="bank" name="bank" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_bank); ?>">
                        <option value="">All Banks</option>
                        <?php foreach ($banks as $bank): ?>
                            <option value="<?php echo htmlspecialchars($bank['bank']); ?>" 
                                <?php echo ($selected_bank == $bank['bank']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($bank['bank']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="settlement_type">Settlement Type</label>
                    <select id="settlement_type" name="settlement_type" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_settlement_type); ?>">
                        <option value="">All Types</option>
                        <?php foreach ($settlement_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['settled_online_check']); ?>" 
                                <?php echo ($selected_settlement_type == $type['settled_online_check']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['settled_online_check']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date_from">Transaction Date From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($selected_date_from); ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">Transaction Date To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($selected_date_to); ?>">
                </div>

                <div class="filter-group" style="flex: 0 0 200px;">
                    <label for="rfp_no">RFP No.<span style="display:block;color:#dc3545;font-size:11px;margin-top:3px;"> <i class="fas fa-info-circle"></i> Select dates first</span></label>
                    <input type="text" id="rfp_no" name="rfp_no" 
                           placeholder="<?php echo $has_required_dates ? 'Enter RFP No.' : 'Select dates first'; ?>" 
                           value="<?php echo htmlspecialchars($selected_rfp_no); ?>"
                           <?php echo !$has_required_dates ? 'disabled' : ''; ?>
                           title="<?php echo $has_required_dates ? '' : 'Select both Transaction Date From and Transaction Date To first'; ?>"
                           style="width:100%;padding:8px 12px;border:1px solid #ced4da;border-radius:4px;<?php echo !$has_required_dates ? ' background:#e9ecef; cursor:not-allowed;' : ''; ?>">
                </div>

                <input type="hidden" id="include_lookback" name="include_lookback" value="<?php echo htmlspecialchars($include_lookback); ?>">

                <div class="filter-actions">
                    <button type="submit" class="btn-filter" id="filterBtn"><i class="fas fa-search" aria-hidden="true"></i> Filter</button>
                    <a href="settlement-per-bank-pcso.php" class="btn-reset"><i class="fas fa-undo" aria-hidden="true"></i> Reset</a>
                </div>
            </form>
        </div>

        <div id="resultsContainer">
        <?php
        if ($has_filters) {
            try {
                $pcso_sets = getPcsoLandbankSets();

                // Determine which region group(s) to render
                $selected_is_pcso = in_array($selected_partner, $pcso_partner_ids, true);
                $selected_pcso_group = null;
                if ($selected_is_pcso) {
                    foreach ($pcso_sets as $gkey => $set) {
                        if (in_array($selected_partner, $set['partner_ids'], true)) {
                            $selected_pcso_group = $gkey;
                            break;
                        }
                    }
                }

                $groups_to_render = [];
                if ($selected_is_pcso && $selected_pcso_group !== null) {
                    $groups_to_render[$selected_pcso_group] = $pcso_sets[$selected_pcso_group];
                } else {
                    $groups_to_render = $pcso_sets;
                }

                $combined_data = [];
                foreach ($groups_to_render as $gkey => $set) {
                    $entries = getPcsoLandbankBranchBreakdown(
                        $conn,
                        $set['partner_ids'],
                        $selected_bank,
                        $selected_settlement_type,
                        $selected_date_from,
                        $selected_date_to,
                        $set['region_label']
                    );
                    foreach ($entries as $entry) {
                        $safe_key = 'pcso-' . $gkey . '-' . md5($entry['branch_name']);
                        $combined_data[$safe_key] = $entry;
                    }
                }

                // Late-posting lookback flags
                $is_single_day_settlement = (
                    !empty($selected_date_from) && !empty($selected_date_to)
                    && $selected_date_from === $selected_date_to
                );
                $lookback_from = null; $lookback_to = null;
                $apply_lookback = ($is_single_day_settlement && $include_lookback === '1');
                if ($apply_lookback) {
                    $lookback_from = date('Y-m-d', strtotime($selected_date_from . ' -7 days'));
                    $lookback_to   = date('Y-m-d', strtotime($selected_date_from . ' -1 day'));
                }
                $effective_date_from = $selected_date_from;
                $effective_date_to = $selected_date_to;

                if (!empty($combined_data)) {
                    $data_array = array_values($combined_data);
                    usort($data_array, function($a, $b) {
                        return strcmp($a['branch_name'] ?? '', $b['branch_name'] ?? '');
                    });

                    $groups = [
                        'PCSO LANDBANK BRANCHES' => [
                            'display_name' => 'PCSO LANDBANK BRANCHES',
                            'icon' => 'fa-building-columns',
                            'rows' => [],
                            'totals' => ['txn_count'=>0,'principal'=>0,'charge_to_customer'=>0,'charge_to_partner'=>0,'adjustment'=>0,'settlement'=>0,'settled_count'=>0,'unsettled_count'=>0]
                        ]
                    ];

                    $grand_totals = ['txn_count'=>0,'principal'=>0,'charge_to_customer'=>0,'charge_to_partner'=>0,'adjustment'=>0,'settlement'=>0,'settled_count'=>0,'unsettled_count'=>0];
                    $row_index = 0;

                    foreach ($data_array as $row) {
                        $partner_id = $row['partner_id_kpx'];
                        $branch_name = $row['branch_name'];
                        $charge_to = strtoupper(trim($row['charge_to'] ?? ''));
                        $charge_sched = normalizeChargeSched($row['charge_sched'] ?? '');
                        $group_key = 'PCSO LANDBANK BRANCHES';

                        $txn_count = (int)($row['txn_count'] ?? 0);
                        $principal = (float)($row['total_principal'] ?? 0);
                        $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
                        $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
                        $adjustment = (float)($row['total_adjustment'] ?? 0);
                        $settlement_amount = calculateSettlementAmount($charge_to, $charge_sched, $principal, $charge_to_customer, $charge_to_partner, $adjustment, $partner_id, $txn_count);
                        $settled_count = (int)($row['settled_count'] ?? 0);
                        $unsettled_count = (int)($row['unsettled_count'] ?? 0);
                        $is_fully_settled = ($settled_count > 0 && $unsettled_count == 0);
                        $is_partially_settled = ($settled_count > 0 && $unsettled_count > 0);

                        $groups[$group_key]['totals']['txn_count'] += $txn_count;
                        $groups[$group_key]['totals']['principal'] += $principal;
                        $groups[$group_key]['totals']['charge_to_customer'] += $charge_to_customer;
                        $groups[$group_key]['totals']['charge_to_partner'] += $charge_to_partner;
                        $groups[$group_key]['totals']['adjustment'] += $adjustment;
                        $groups[$group_key]['totals']['settlement'] += $settlement_amount;
                        $groups[$group_key]['totals']['settled_count'] += $settled_count;
                        $groups[$group_key]['totals']['unsettled_count'] += $unsettled_count;

                        $grand_totals['txn_count'] += $txn_count;
                        $grand_totals['principal'] += $principal;
                        $grand_totals['charge_to_customer'] += $charge_to_customer;
                        $grand_totals['charge_to_partner'] += $charge_to_partner;
                        $grand_totals['adjustment'] += $adjustment;
                        $grand_totals['settlement'] += $settlement_amount;
                        $grand_totals['settled_count'] += $settled_count;
                        $grand_totals['unsettled_count'] += $unsettled_count;

                        // Daily breakdown for this branch
                        $daily_data = [];
                        if ($has_date_range) {
                            $daily_data = getDailyBreakdown(
                                $conn,
                                $partner_id,
                                $selected_bank,
                                $selected_settlement_type,
                                $selected_date_from,
                                $selected_date_to,
                                [],
                                ['branch_name' => $branch_name]
                            );
                        }
                        $daily_html = !empty($daily_data) ? generateDailyBreakdownHTML($daily_data) : '';

                        $groups[$group_key]['rows'][] = [
                            'row_index' => $row_index,
                            'partner_id' => $partner_id,
                            'partner_name' => $branch_name,
                            'agent_no' => $row['agent_no'] ?? '',
                            'display_date' => '',
                            'is_daily_breakdown_row' => false,
                            'transaction_date' => null,
                            'account_name' => $row['partner_accName'] ?? 'N/A',
                            'account_number' => $row['bank_accNumber'] ?? 'N/A',
                            'txn_count' => $txn_count,
                            'principal' => $principal,
                            'charge_to_customer' => $charge_to_customer,
                            'charge_to_partner' => $charge_to_partner,
                            'adjustment' => $adjustment,
                            'settlement_amount' => $settlement_amount,
                            'is_negative' => $settlement_amount < 0,
                            'excluded' => $is_fully_settled,
                            'is_fully_settled' => $is_fully_settled,
                            'is_partially_settled' => $is_partially_settled,
                            'settled_count' => $settled_count,
                            'unsettled_count' => $unsettled_count,
                            'has_daily_breakdown' => $has_date_range,
                            'daily_html' => $daily_html,
                            'reference_html' => '',
                            'charge_to' => $charge_to,
                            'charge_sched' => $charge_sched,
                            'is_pcso_landbank' => true,
                            'is_pcso_branch_row' => true,
                            'pcso_region' => $row['pcso_region'] ?? '',
                            'branch_name' => $branch_name
                        ];
                        $row_index++;
                    }

                    $groups = array_filter($groups, function($group) { return !empty($group['rows']); });
                    ?>
                    
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title"><i class="fas fa-file-invoice" aria-hidden="true"></i> PCSO Settlement Summary</h3>
                            <span class="table-badge">
                                <i class="fas fa-building-columns"></i> Branches: <?php 
                                    $total_branches = 0;
                                    foreach ($groups as $group) $total_branches += count($group['rows']);
                                    echo $total_branches; 
                                ?>
                            </span>
                            <?php if (!empty($selected_bank)): ?>
                            <span class="table-badge"><i class="fas fa-university"></i> Bank: <?php echo htmlspecialchars($selected_bank); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($selected_rfp_no)): ?>
                            <span class="table-badge"><i class="fas fa-file-invoice"></i> RFP No.: <?php echo htmlspecialchars($selected_rfp_no); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($cad_number)): ?>
                            <span class="table-badge" id="currentCadBadge"><i class="fas fa-hashtag"></i> CAD No.: <?php echo htmlspecialchars($cad_number); ?></span>
                            <input type="hidden" id="currentCadNo" value="<?php echo htmlspecialchars($cad_number); ?>">
                            <input type="hidden" id="cadGenerated" value="<?php echo $cad_generated ? 'true' : 'false'; ?>">
                            <?php endif; ?>
                            <?php if ($has_date_range): ?>
                            <span class="table-badge"><i class="fas fa-calendar-alt"></i> 
                                <?php 
                                $date_range = '';
                                if (!empty($selected_date_from)) $date_range .= 'From: ' . date('M d, Y', strtotime($selected_date_from));
                                if (!empty($selected_date_to)) $date_range .= ' To: ' . date('M d, Y', strtotime($selected_date_to));
                                echo htmlspecialchars($date_range);
                                ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$cad_generated && !empty($selected_partner)): ?>
                            <span class="table-badge" style="background:#dc3545;color:#fff;">
                                <i class="fas fa-exclamation-triangle"></i> Invalid Bank - Cannot Settle
                            </span>
                            <?php endif; ?>
                            <?php if (!$has_required_dates): ?>
                            <span class="table-badge" style="background:#dc3545;color:#fff;">
                                <i class="fas fa-calendar-alt"></i> Dates Required to Settle
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$has_required_dates): ?>
                        <div class="alert-warning-custom">
                            <i class="fas fa-calendar-alt"></i>
                            <strong>Date Filters Required:</strong> Please select both <strong>Transaction Date From</strong> and <strong>Transaction Date To</strong> to enable RFP No. entry, Save Reasons, and Settle.
                        </div>
                        <?php endif; ?>

                        <?php if (!$cad_generated && !empty($selected_partner)): ?>
                        <div class="alert-danger-custom">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> The selected partner does not have a valid bank abbreviation configured. <strong>Settlement is disabled until this is fixed.</strong>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($is_single_day_settlement) && $include_lookback === '1' && !empty($lookback_from) && !empty($lookback_to)): ?>
                        <div class="alert-info-custom" style="background:#e8f5e9;border-color:#81c784;color:#1b5e20;">
                            <i class="fas fa-clock"></i>
                            <strong>Late Posting Lookback Included:</strong> Settlement date (<strong><?php echo date('M d, Y', strtotime($effective_date_from)); ?></strong>) plus unsettled rows with <strong>import_status = 'Late Posting'</strong> from the previous 7 days (<strong><?php echo date('M d, Y', strtotime($lookback_from)); ?></strong> → <strong><?php echo date('M d, Y', strtotime($lookback_to)); ?></strong>).
                        </div>
                        <?php elseif (!empty($is_single_day_settlement) && $include_lookback === '0'): ?>
                        <div class="alert-info-custom" style="background:#fff3cd;border-color:#ffc107;color:#856404;">
                            <i class="fas fa-calendar-day"></i>
                            <strong>Selected date only:</strong> Showing transactions for <strong><?php echo date('M d, Y', strtotime($effective_date_from)); ?></strong> only.
                        </div>
                        <?php endif; ?>
                        
                        <div class="table-controls">
                            <div class="checkbox-controls">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="selectAllRows" onchange="toggleAllRows(this)">
                                    <span>Select/Deselect All</span>
                                </label>
                                <button class="btn-recalculate" onclick="recalculateTotals()">
                                    <i class="fas fa-calculator"></i> Recalculate Totals
                                </button>
                            </div>
                        </div>
                        
                        <table class="settlement-table" id="settlementTable">
                            <thead>
                                <tr>
                                    <th class="center" style="width:40px;"><input type="checkbox" id="selectAllHeader" onchange="toggleAllRows(this)"></th>
                                    <th class="center" style="width:60px;">VIEW</th>
                                    <th class="center">BRANCH NAME</th>
                                    <th class="center">ACCOUNT NAME</th>
                                    <th class="center">ACCOUNT NUMBER</th>
                                    <th class="center">AGENT NO.</th>
                                    <th class="center">VOLUME COUNT</th>
                                    <th class="center">₱ PRINCIPAL</th>
                                    <th class="center" colspan="2">₱ CHARGE</th>
                                    <th class="center">₱ ADJUSTMENT (add/less)</th>
                                    <th class="center settlement-col">₱ AMOUNT FOR SETTLEMENT</th>
                                    <th class="center">STATUS</th>
                                    <th class="center" style="min-width:180px;">REASON NOT SETTLED</th>
                                </tr>
                                <tr>
                                    <th colspan="8" style="font-style:italic;font-size:12px;color:#ff0000;">Check Branches to Proceed Settlement</th>
                                    <th class="center">CUSTOMER</th>
                                    <th class="center">PARTNER</th>
                                    <th colspan="4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $group_index = 0;
                                foreach ($groups as $group_key => $group_data): 
                                    $group_index++;
                                    $is_last_group = $group_index === count($groups);
                                ?>
                                    <tr class="group-header-row">
                                        <td colspan="14">
                                            <i class="fas <?php echo $group_data['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($group_data['display_name']); ?>
                                        </td>
                                    </tr>
                                    
                                    <?php foreach ($group_data['rows'] as $row_data): 
                                        $is_settled = $row_data['is_fully_settled'];
                                        $is_partial = $row_data['is_partially_settled'];
                                        $status_class = $is_settled ? 'settled' : ($is_partial ? 'partial' : 'unsettled');
                                    ?>
                                        <tr class="data-row <?php echo $row_data['is_negative'] ? 'negative-row' : ''; ?> <?php echo $is_settled ? 'settled-row' : ($is_partial ? 'partial-row' : 'unsettled-row'); ?>" 
                                            data-row-index="<?php echo $row_data['row_index']; ?>"
                                            data-settlement="<?php echo $row_data['settlement_amount']; ?>"
                                            data-principal="<?php echo $row_data['principal']; ?>"
                                            data-charge-to-customer="<?php echo $row_data['charge_to_customer']; ?>"
                                            data-charge-to-partner="<?php echo $row_data['charge_to_partner']; ?>"
                                            data-adjustment="<?php echo $row_data['adjustment']; ?>"
                                            data-txn-count="<?php echo $row_data['txn_count']; ?>"
                                            data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                            data-partner-name="<?php echo htmlspecialchars($row_data['partner_name']); ?>"
                                            data-is-settled="<?php echo $is_settled ? 'true' : 'false'; ?>"
                                            data-charge-to="<?php echo $row_data['charge_to']; ?>"
                                            data-charge-sched="<?php echo $row_data['charge_sched']; ?>"
                                            data-is-pcso="true"
                                            data-pcso-region="<?php echo htmlspecialchars($row_data['pcso_region']); ?>"
                                            data-branch-name="<?php echo htmlspecialchars($row_data['branch_name']); ?>">
                                            <td class="center checkbox-cell">
                                                <input type="checkbox" class="row-checkbox" 
                                                       data-row-index="<?php echo $row_data['row_index']; ?>"
                                                       onchange="updateTotals(); toggleReasonDropdown(this)"
                                                       <?php echo ($is_settled || $row_data['excluded']) ? 'disabled checked' : ''; ?>
                                                       <?php echo (!$cad_generated && !$is_settled) ? 'disabled' : ''; ?>
                                                       <?php echo (!$has_required_dates && !$is_settled) ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="center">
                                                <div class="row-toggles">
                                                    <?php if ($row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                                        <span class="chevron-toggle" 
                                                              data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                                              data-row-index="<?php echo $row_data['row_index']; ?>"
                                                              title="Show daily breakdown"
                                                              onclick="toggleDailyBreakdown(this, <?php echo $row_data['row_index']; ?>)">
                                                            <i class="fas fa-calendar-day"></i>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="chevron-placeholder"></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="partner-name-cell">
                                                <?php echo htmlspecialchars($row_data['partner_name']); ?>
                                                <span style="font-size:10px;color:#0c5460;margin-left:5px;">
                                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row_data['pcso_region']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row_data['account_name']); ?></td>
                                            <td class="center"><?php echo htmlspecialchars($row_data['account_number']); ?></td>
                                            <td class="center agent-no-cell"><?php echo htmlspecialchars($row_data['agent_no'] ?? ''); ?></td>
                                            <td class="center txn-count"><?php echo number_format($row_data['txn_count']); ?></td>
                                            <td class="right amount-col principal"> <?php echo number_format($row_data['principal'], 2); ?></td>
                                            <td class="right amount-col charge-to-customer"> <?php echo number_format($row_data['charge_to_customer'], 2); ?></td>
                                            <td class="right amount-col charge-to-partner"> <?php echo number_format($row_data['charge_to_partner'], 2); ?></td>
                                            <td class="right amount-col adjustment <?php echo $row_data['adjustment'] < 0 ? 'negative-amount' : ($row_data['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                                <?php if ($row_data['adjustment'] != 0): ?>
                                                    <?php echo ($row_data['adjustment'] >= 0 ? '+' : ''); ?> <?php echo number_format($row_data['adjustment'], 2); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="right settlement-col settlement-amount <?php echo $row_data['is_negative'] ? 'negative-amount' : ''; ?>">
                                                 <?php echo number_format($row_data['settlement_amount'], 2); ?>
                                            </td>
                                            <td class="center">
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php if ($is_settled): ?>
                                                        <i class="fas fa-check-circle"></i> Settled
                                                    <?php elseif ($is_partial): ?>
                                                        <i class="fas fa-clock"></i> Partial
                                                        <span style="font-size:10px;display:block;color:#666;"><?php echo $row_data['settled_count']; ?> settled and <?php echo $row_data['unsettled_count']; ?> unsettled</span>
                                                    <?php else: ?>
                                                        <i class="fas fa-clock"></i> Unsettled
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="center reason-cell">
                                                <?php if ($is_settled): ?>
                                                    <span style="color:#6c757d;font-size:12px;">N/A (Settled)</span>
                                                <?php else: ?>
                                                    <select class="reason-dropdown" 
                                                            data-row-index="<?php echo $row_data['row_index']; ?>"
                                                            data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                                            <?php echo ($row_data['excluded'] || $is_settled || !$has_required_dates) ? 'disabled' : ''; ?>
                                                            onchange="updateReason(this, <?php echo $row_data['row_index']; ?>)">
                                                        <option value="">Select Reason</option>
                                                        <?php foreach ($reason_options as $value => $label): ?>
                                                            <?php if ($value !== ''): ?>
                                                                <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <?php if ($row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" style="display:none;">
                                                <td colspan="14"><?php echo $row_data['daily_html']; ?></td>
                                            </tr>
                                        <?php elseif ($row_data['has_daily_breakdown'] && empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" style="display:none;">
                                                <td colspan="14" class="daily-breakdown-error">
                                                    <i class="fas fa-info-circle"></i> No daily transactions found for this date range.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    
                                    <?php if (!$is_last_group): ?>
                                        <tr style="height:8px;background:transparent;"><td colspan="14" style="border:none;padding:0;"></td></tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <tr class="grand-total-row">
                                    <td colspan="6" style="text-align:right;">₱ GRAND TOTAL</td>
                                    <td class="center grand-txn-count"><?php echo number_format($grand_totals['txn_count']); ?></td>
                                    <td class="right grand-principal"> <?php echo number_format($grand_totals['principal'], 2); ?></td>
                                    <td class="right grand-charge-to-customer"> <?php echo number_format($grand_totals['charge_to_customer'], 2); ?></td>
                                    <td class="right grand-charge-to-partner"> <?php echo number_format($grand_totals['charge_to_partner'], 2); ?></td>
                                    <td class="right grand-adjustment <?php echo $grand_totals['adjustment'] < 0 ? 'negative-amount' : ($grand_totals['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                        <?php if ($grand_totals['adjustment'] != 0): ?>
                                            <?php echo ($grand_totals['adjustment'] >= 0 ? '+' : ''); ?> <?php echo number_format($grand_totals['adjustment'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="right settlement-col grand-settlement <?php echo $grand_totals['settlement'] < 0 ? 'negative-amount' : ''; ?>">
                                         <?php echo number_format($grand_totals['settlement'], 2); ?>
                                    </td>
                                    <td class="center grand-status">
                                        <?php 
                                        $g_settled = $grand_totals['settled_count'] ?? 0;
                                        $g_unsettled = $grand_totals['unsettled_count'] ?? 0;
                                        if ($g_settled > 0 && $g_unsettled == 0): ?>
                                            <span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>
                                        <?php elseif ($g_settled > 0 && $g_unsettled > 0): ?>
                                            <span style="color:#856404;"><i class="fas fa-clock"></i> <?php echo number_format($g_settled); ?> Settled / <?php echo number_format($g_unsettled); ?> Unsettled</span>
                                        <?php else: ?>
                                            <span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center grand-reason"></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="export-buttons">
                            <button class="btn-export excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export Excel</button>
                            <button class="btn-export pdf" onclick="exportToPDF()"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
                            <button class="btn-export save-reasons <?php echo !$has_required_dates ? 'btn-disabled' : ''; ?>" 
                                    onclick="saveReasons()" 
                                    <?php echo !$has_required_dates ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>
                                    title="<?php echo !$has_required_dates ? 'Select both dates first' : ''; ?>"
                                    style="margin-left:10px;">
                                <i class="fas fa-save"></i> Save Reasons
                            </button>
                            <button class="btn-export settle <?php echo (!$cad_generated || !$has_required_dates) ? 'btn-disabled' : ''; ?>" 
                                    onclick="settleSelected()" 
                                    <?php echo (!$cad_generated || !$has_required_dates) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?> 
                                    title="<?php echo !$has_required_dates ? 'Select both dates first' : (!$cad_generated ? 'Invalid bank configuration' : ''); ?>"
                                    style="margin-left:10px;">
                                <i class="fas fa-check-circle"></i> Settle
                            </button>
                        </div>
                    </div>
                    
                <?php
                } else {
                    echo '<div class="no-records">';
                    echo '<i class="fas fa-inbox"></i>';
                    echo '<p>No PCSO LANDBANK transactions found for the selected filters.</p>';
                    echo '<p style="font-size:14px;margin-top:5px;">Please try adjusting your date range or filters.</p>';
                    echo '</div>';
                }
                
            } catch (Exception $e) {
                error_log("Error fetching PCSO settlement data: " . $e->getMessage());
                echo '<div class="no-records" style="border:1px solid #dc3545;color:#dc3545;">';
                echo '<i class="fas fa-exclamation-triangle"></i>';
                echo '<p>Error fetching data. Please try again.</p>';
                echo '<p style="font-size:14px;margin-top:5px;">' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
        } else {
            echo '<div class="no-records">';
            echo '<i class="fas fa-filter"></i>';
            echo '<p>Please select filters and click Filter to view PCSO settlement data.</p>';
            echo '<p style="font-size:14px;margin-top:5px;">Only PCSO / LANDBANK partners (631, 648, 650, 656, 658, 660, 662, 670, 680) are shown on this page.</p>';
            echo '</div>';
        }
        ?>
        </div>

    </div>
    <?php include '../../../templates/footer.php'; ?>

<script>
    var loadingTimer = null;
    var seconds = 0;
    var isPageLoaded = false;
    var reasonData = {};
    
    $(document).ready(function() {
        var initialPartner = $('#partner').data('selected') || '';
        var initialBank = $('#bank').data('selected') || '';
        var initialSettlement = $('#settlement_type').data('selected') || '';
        
        $('.select2-dropdown').select2({
            placeholder: function() { return $(this).data('placeholder') || 'Select an option'; },
            allowClear: true,
            width: '100%'
        });
        
        $('#partner').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var bank = selectedOption.data('bank') || '';
            if (bank) $('#bank').val(bank).trigger('change');
            else $('#bank').val('').trigger('change');
        });
        
        if (initialPartner) $('#partner').val(initialPartner).trigger('change');
        if (initialBank) $('#bank').val(initialBank).trigger('change');
        if (initialSettlement) $('#settlement_type').val(initialSettlement).trigger('change');

        function syncDateDependentControls() {
            var dateFrom = ($('#date_from').val() || '').trim();
            var dateTo   = ($('#date_to').val() || '').trim();
            var hasDates = (dateFrom !== '' && dateTo !== '');
            $('#rfp_no')
                .prop('disabled', !hasDates)
                .attr('placeholder', hasDates ? 'Enter RFP No.' : 'Select dates first')
                .css({ background: hasDates ? '' : '#e9ecef', cursor: hasDates ? '' : 'not-allowed' });
            var $saveBtn = $('.btn-export.save-reasons');
            $saveBtn.prop('disabled', !hasDates);
            $saveBtn.css({ opacity: hasDates ? '' : '0.5', cursor: hasDates ? '' : 'not-allowed' });
            var $settleBtn = $('.btn-export.settle');
            var cadOk = ($('#cadGenerated').val() === 'true');
            $settleBtn.prop('disabled', !hasDates || !cadOk);
            $settleBtn.css({ opacity: (!hasDates || !cadOk) ? '0.5' : '', cursor: (!hasDates || !cadOk) ? 'not-allowed' : '' });
        }
        $('#date_from, #date_to').on('change', syncDateDependentControls);
        syncDateDependentControls();

        startLoadingTimer();

        $(window).on('load', function() {
            isPageLoaded = true;
            setTimeout(function() { hideLoadingModal(); showMainContent(); }, 500);
        });

        setTimeout(function() {
            if (!isPageLoaded) {
                var hasContent = $('#resultsContainer').children().length > 0;
                if (hasContent) { hideLoadingModal(); showMainContent(); }
            }
        }, 10000);

        function runFilterAjax() {
            showLoadingModal();
            hideMainContent();
            resetAndStartTimer();
            var formData = $('#filterForm').serialize();
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: formData,
                dataType: 'html',
                cache: false,
                timeout: 120000,
                success: function(response) {
                    var tempDiv = $('<div>').html(response);
                    var newContent = tempDiv.find('#resultsContainer').html();
                    if (newContent) $('#resultsContainer').html(newContent);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', window.location.pathname + '?' + formData);
                    }
                    setTimeout(function() { hideLoadingModal(); showMainContent(); }, 300);
                },
                error: function(xhr, status, error) {
                    hideLoadingModal();
                    showMainContent();
                    var errorMsg = 'Failed to load data. Please try again.';
                    if (status === 'timeout') errorMsg = 'Request timed out. The query may be taking too long. Please try with fewer filters.';
                    Swal.fire({ icon: 'error', title: 'Error', text: errorMsg, confirmButtonColor: '#dc3545' });
                    console.error('AJAX Error:', status, error);
                }
            });
        }

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            syncDateDependentControls();
            var hasFilters = false;
            $(this).find('select, input[type="date"], input[name="rfp_no"]').each(function() {
                var val = $(this).val();
                if (val && val.trim() !== '' && val !== '0') { hasFilters = true; return false; }
            });
            if (!hasFilters) {
                Swal.fire({ icon: 'info', title: 'No Filters Selected', text: 'Please select at least one filter to search.', confirmButtonColor: '#007bff' });
                return;
            }
            var dateFrom = ($('#date_from').val() || '').trim();
            var dateTo   = ($('#date_to').val() || '').trim();
            var isSingleDay = (dateFrom !== '' && dateTo !== '' && dateFrom === dateTo);
            if (isSingleDay) {
                var lookbackFrom = new Date(dateFrom + 'T00:00:00');
                lookbackFrom.setDate(lookbackFrom.getDate() - 7);
                var lookbackTo = new Date(dateFrom + 'T00:00:00');
                lookbackTo.setDate(lookbackTo.getDate() - 1);
                var fmt = function(d) { return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); };
                Swal.fire({
                    icon: 'question',
                    title: 'Include Late Postings?',
                    html: `<div style="text-align:left;font-size:14px;">
                            <p>You selected a <strong>single date</strong> (<strong>${fmt(new Date(dateFrom + 'T00:00:00'))}</strong>).</p>
                            <p style="margin-top:10px;">There may be transactions from the previous 7 days
                            (<strong>${fmt(lookbackFrom)}</strong> → <strong>${fmt(lookbackTo)}</strong>)
                            with <strong>import_status = 'Late Posting'</strong> that are still unsettled.</p>
                            <p style="margin-top:10px;">Do you want to <strong>include</strong> those Late Posting transactions, or show <strong>only</strong> the selected date?</p>
                        </div>`,
                    showDenyButton: true,
                    showCancelButton: true,
                    confirmButtonText: 'Include Late Postings',
                    denyButtonText: 'Selected date only',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#28a745',
                    denyButtonColor: '#6c757d',
                    cancelButtonColor: '#dc3545'
                }).then(function(result) {
                    if (result.isConfirmed) { $('#include_lookback').val('1'); runFilterAjax(); }
                    else if (result.isDenied) { $('#include_lookback').val('0'); runFilterAjax(); }
                });
                return;
            }
            $('#include_lookback').val('0');
            runFilterAjax();
        });
    });

    function showLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) { modal.classList.remove('hidden'); modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
    }

    function hideLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) {
            modal.classList.add('hidden'); modal.style.display = 'none'; document.body.style.overflow = '';
            if (loadingTimer) { clearInterval(loadingTimer); loadingTimer = null; }
        }
    }

    function showMainContent() {
        var mainContent = document.getElementById('mainContent');
        if (mainContent) { mainContent.classList.remove('main-content-hidden'); mainContent.classList.add('main-content-visible'); }
    }

    function hideMainContent() {
        var mainContent = document.getElementById('mainContent');
        if (mainContent) { mainContent.classList.remove('main-content-visible'); mainContent.classList.add('main-content-hidden'); }
    }

    function startLoadingTimer() {
        if (loadingTimer) { clearInterval(loadingTimer); loadingTimer = null; }
        seconds = 0;
        var elapsedElement = document.getElementById('elapsedTime');
        if (elapsedElement) elapsedElement.textContent = '0';
        loadingTimer = setInterval(function() {
            seconds++;
            var elapsedElement = document.getElementById('elapsedTime');
            if (elapsedElement) elapsedElement.textContent = seconds;
        }, 1000);
    }

    function resetAndStartTimer() { startLoadingTimer(); }

    function toggleDailyBreakdown(element, rowIndex) {
        var breakdownRow = document.getElementById('dailyBreakdown_' + rowIndex);
        if (!breakdownRow) return;
        if (breakdownRow.style.display !== 'none') {
            breakdownRow.style.display = 'none';
            element.classList.remove('expanded');
            element.innerHTML = '<i class="fas fa-calendar-day"></i>';
            return;
        }
        breakdownRow.style.display = 'table-row';
        element.classList.add('expanded');
        element.innerHTML = '<i class="fas fa-calendar-day"></i>';
    }

    function toggleReasonDropdown(checkbox) {
        var row = $(checkbox).closest('.data-row');
        var reasonSelect = row.find('.reason-dropdown');
        var isChecked = $(checkbox).prop('checked');
        var isDisabled = $(checkbox).prop('disabled');
        if (isChecked) {
            reasonSelect.prop('disabled', true);
            reasonSelect.val('');
            reasonSelect.css('opacity', '0.5');
            var rowIndex = row.data('row-index');
            if (rowIndex !== undefined) delete reasonData[rowIndex];
        } else if (!isDisabled) {
            reasonSelect.prop('disabled', false);
            reasonSelect.css('opacity', '1');
        }
    }

    function updateReason(element, rowIndex) {
        var reason = $(element).val();
        var partnerId = $(element).data('partner-id');
        if (reason) reasonData[rowIndex] = { reason: reason, partner_id: partnerId };
        else delete reasonData[rowIndex];
    }

    function toggleAllRows(checkbox) {
        var isChecked = $(checkbox).prop('checked');
        var cadGenerated = $('#cadGenerated').val() === 'true';
        var hasRequiredDates = isDateRangeSet();
        $('.row-checkbox:not(:disabled)').each(function() {
            var row = $(this).closest('.data-row');
            var isSettled = row.data('is-settled') === true;
            if ((!cadGenerated || !hasRequiredDates) && !isSettled) return;
            $(this).prop('checked', isChecked);
            $(this).prop('indeterminate', false); 
            toggleReasonDropdown(this);
        });
        updateTotals();
    }

    function isDateRangeSet() {
        var dateFrom = ($('#date_from').val() || '').trim();
        var dateTo   = ($('#date_to').val() || '').trim();
        return (dateFrom !== '' && dateTo !== '');
    }
    
    function updateTotals() {
        var dataRows = $('.data-row');
        var grandTotals = { txn_count:0, principal:0, charge_to_customer:0, charge_to_partner:0, adjustment:0, settlement:0, settled_count:0, unsettled_count:0 };
        dataRows.each(function() {
            var row = $(this);
            var checkbox = row.find('.row-checkbox');
            var isChecked = checkbox.prop('checked') && !checkbox.prop('disabled');
            var isSettled = row.data('is-settled') === true;
            var rawTxnCount = parseInt(row.data('txn-count')) || 0;
            var rawPrincipal = parseFloat(row.data('principal')) || 0;
            var rawChargeToCustomer = parseFloat(row.data('charge-to-customer')) || 0;
            var rawChargeToPartner = parseFloat(row.data('charge-to-partner')) || 0;
            var rawAdjustment = parseFloat(row.data('adjustment')) || 0;
            var rawSettlement = parseFloat(row.data('settlement')) || 0;
            if (isChecked) {
                grandTotals.txn_count += rawTxnCount;
                grandTotals.principal += rawPrincipal;
                grandTotals.charge_to_customer += rawChargeToCustomer;
                grandTotals.charge_to_partner += rawChargeToPartner;
                grandTotals.adjustment += rawAdjustment;
                grandTotals.settlement += rawSettlement;
            }
            if (isSettled) grandTotals.settled_count += rawTxnCount;
            else grandTotals.unsettled_count += rawTxnCount;
        });
        $('.grand-total-row').find('.grand-txn-count').text(formatNumberInt(grandTotals.txn_count));
        $('.grand-total-row').find('.grand-principal').text(' ' + formatNumberDecimal(grandTotals.principal));
        $('.grand-total-row').find('.grand-charge-to-customer').text(' ' + formatNumberDecimal(grandTotals.charge_to_customer));
        $('.grand-total-row').find('.grand-charge-to-partner').text(' ' + formatNumberDecimal(grandTotals.charge_to_partner));
        if (grandTotals.adjustment != 0) {
            var grandAdjText = (grandTotals.adjustment >= 0 ? '+' : '') + ' ' + formatNumberDecimal(grandTotals.adjustment);
            $('.grand-total-row').find('.grand-adjustment').text(grandAdjText);
            $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
            if (grandTotals.adjustment < 0) $('.grand-total-row').find('.grand-adjustment').addClass('negative-amount');
            else if (grandTotals.adjustment > 0) $('.grand-total-row').find('.grand-adjustment').addClass('positive-amount');
        } else {
            $('.grand-total-row').find('.grand-adjustment').text('');
            $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
        }
        $('.grand-total-row').find('.grand-settlement').text(' ' + formatNumberDecimal(grandTotals.settlement));
        $('.grand-total-row').find('.grand-settlement').removeClass('negative-amount');
        if (grandTotals.settlement < 0) $('.grand-total-row').find('.grand-settlement').addClass('negative-amount');
        var gSettled = grandTotals.settled_count || 0;
        var gUnsettled = grandTotals.unsettled_count || 0;
        var statusHtml = '';
        if (gSettled > 0 && gUnsettled == 0) statusHtml = '<span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>';
        else if (gSettled > 0 && gUnsettled > 0) statusHtml = '<span style="color:#856404;"><i class="fas fa-clock"></i> ' + formatNumberInt(gSettled) + ' Settled / ' + formatNumberInt(gUnsettled) + ' Unsettled</span>';
        else statusHtml = '<span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>';
        $('.grand-total-row').find('.grand-status').html(statusHtml);
        var totalCheckboxes = $('.row-checkbox:not(:disabled)').length;
        var checkedCheckboxes = $('.row-checkbox:not(:disabled):checked').length;
        var selectAllHeader = $('#selectAllHeader');
        var selectAllRows = $('#selectAllRows');
        if (totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes) {
            selectAllHeader.prop('checked', true).prop('indeterminate', false);
            selectAllRows.prop('checked', true).prop('indeterminate', false);
        } else if (checkedCheckboxes === 0) {
            selectAllHeader.prop('checked', false).prop('indeterminate', false);
            selectAllRows.prop('checked', false).prop('indeterminate', false);
        } else {
            selectAllHeader.prop('checked', false).prop('indeterminate', true);
            selectAllRows.prop('checked', false).prop('indeterminate', true);
        }
    }
    
    function recalculateTotals() {
        updateTotals();
        Swal.fire({ icon: 'success', title: 'Totals Updated', text: 'PCSO settlement totals have been recalculated based on selected rows.', timer: 1500, showConfirmButton: false });
    }
    
    function formatNumberInt(value) {
        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value);
    }
    
    function formatNumberDecimal(value) {
        return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
    }

    function saveReasons() {
        if (!isDateRangeSet()) {
            Swal.fire({ icon: 'warning', title: 'Date Filters Required', text: 'Please select both "Transaction Date From" and "Transaction Date To" before saving reasons.', confirmButtonColor: '#ffc107' });
            return;
        }
        var hasReasons = false;
        for (var key in reasonData) {
            if (reasonData.hasOwnProperty(key) && reasonData[key].reason) { hasReasons = true; break; }
        }
        if (!hasReasons) {
            Swal.fire({ icon: 'info', title: 'No Reasons to Save', text: 'Please select reasons for unsettled transactions before saving.', confirmButtonColor: '#17a2b8' });
            return;
        }
        var partner = $('#partner').val() || '';
        var bank = $('#bank').val() || '';
        var settlementType = $('#settlement_type').val() || '';
        var dateFrom = $('#date_from').val() || '';
        var dateTo = $('#date_to').val() || '';
        var rfpNo = $('#rfp_no').val().trim() || '';
        var cadNo = $('#currentCadNo').val() || '';
        Swal.fire({
            title: 'Save Reasons?',
            html: `<div style="text-align:left;">
                    <p>You are about to save reasons for <strong>${Object.keys(reasonData).length}</strong> branch(es).</p>
                    <div style="background:#f8f9fa;padding:10px;border-radius:4px;margin-top:10px;max-height:200px;overflow-y:auto;">
                        ${Object.values(reasonData).map(r => `<p style="margin:5px 0;"><strong>Branch ${r.partner_id}:</strong> ${r.reason}</p>`).join('')}
                    </div>
                    <p style="color:#0c5460;margin-top:10px;"><i class="fas fa-info-circle"></i> This will only update the "Reason Not Settled" field. Settlement status will NOT be changed.</p>
                </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#17a2b8',
            cancelButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Save Reasons',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return new Promise((resolve) => {
                    Swal.fire({ title: 'Saving Reasons', html: 'Please wait while we save the reasons...<br><small>This may take a few moments.</small>', allowOutsideClick: false, allowEscapeKey: false, didOpen: () => { Swal.showLoading(); } });
                    var settledBy = '<?php echo addslashes($display_name); ?>';
                    var partnerIds = [];
                    $('.data-row').each(function() { partnerIds.push(String($(this).data('partner-id'))); });
                    partnerIds = [...new Set(partnerIds)];
                    var includeLookback = $('#include_lookback').val() || '0';
                    var reasonDataStr = JSON.stringify(reasonData);
                    $.ajax({
                        url: 'settle_transactions.php',
                        type: 'POST',
                        data: {
                            action: 'save_reasons',
                            partner_ids: partnerIds,
                            settled_by: settledBy,
                            rfp_no: rfpNo,
                            cad_no: cadNo,
                            partner_filter: partner,
                            bank_filter: bank,
                            settlement_type_filter: settlementType,
                            date_from: dateFrom,
                            date_to: dateTo,
                            include_lookback: includeLookback,
                            reason_data: reasonDataStr
                        },
                        dataType: 'json',
                        timeout: 120000,
                        success: function(response) { resolve({ success: response.success, message: response.message, data: response.data }); },
                        error: function() { resolve({ success: false, message: 'An error occurred while saving reasons.' }); }
                    });
                });
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                if (result.value.success) {
                    Swal.fire({ icon: 'success', title: 'Reasons Saved Successfully', text: result.value.message, confirmButtonColor: '#17a2b8' });
                    reasonData = {};
                    $('.reason-dropdown').prop('disabled', true).css('opacity', '0.5');
                } else {
                    Swal.fire({ icon: 'error', title: 'Failed to Save Reasons', text: result.value.message, confirmButtonColor: '#dc3545' });
                }
            }
        });
    }

    function exportToExcel() {
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) { Swal.fire({ icon: 'warning', title: 'RFP No. Required', text: 'Please enter an RFP No. before exporting.', confirmButtonColor: '#ffc107' }); return; }
        var hasUnsettled = false;
        $('.data-row').each(function() {
            if ($(this).data('is-settled') !== true) { hasUnsettled = true; return false; }
        });
        if (hasUnsettled) { Swal.fire({ icon: 'warning', title: 'Unsettled Transactions', text: 'Cannot export. Please settle all transactions before exporting.', confirmButtonColor: '#ffc107' }); return; }
        showLoadingModal();
        setTimeout(function() {
            var partner = $('#partner').val() || '';
            var bank = $('#bank').val() || '';
            var settlementType = $('#settlement_type').val() || '';
            var dateFrom = $('#date_from').val() || '';
            var dateTo = $('#date_to').val() || '';
            var excludedRows = [];
            $('.data-row').each(function() {
                if (!$(this).find('.row-checkbox').prop('checked')) {
                    var rowIndex = $(this).data('row-index');
                    if (rowIndex !== undefined) excludedRows.push(rowIndex);
                }
            });
            var exportUrl = 'export_bank_settlement_pcso.php?';
            exportUrl += 'partner=' + encodeURIComponent(partner);
            exportUrl += '&bank=' + encodeURIComponent(bank);
            exportUrl += '&settlement_type=' + encodeURIComponent(settlementType);
            exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            exportUrl += '&rfp_no=' + encodeURIComponent(rfpNo);
            exportUrl += '&include_lookback=' + encodeURIComponent($('#include_lookback').val() || '0');
            if (excludedRows.length > 0) exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            var reasonDataStr = JSON.stringify(reasonData);
            exportUrl += '&reason_data=' + encodeURIComponent(reasonDataStr);
            window.open(exportUrl, '_blank');
            setTimeout(function() { hideLoadingModal(); }, 500);
        }, 300);
    }

    function exportToPDF() {
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) { Swal.fire({ icon: 'warning', title: 'RFP No. Required', text: 'Please enter an RFP No. before exporting.', confirmButtonColor: '#ffc107' }); return; }
        var hasUnsettled = false;
        $('.data-row').each(function() {
            if ($(this).data('is-settled') !== true) { hasUnsettled = true; return false; }
        });
        if (hasUnsettled) { Swal.fire({ icon: 'warning', title: 'Unsettled Transactions', text: 'Cannot export. Please settle all transactions before exporting.', confirmButtonColor: '#ffc107' }); return; }
        showLoadingModal();
        setTimeout(function() {
            var partner = $('#partner').val() || '';
            var bank = $('#bank').val() || '';
            var settlementType = $('#settlement_type').val() || '';
            var dateFrom = $('#date_from').val() || '';
            var dateTo = $('#date_to').val() || '';
            var excludedRows = [];
            $('.data-row').each(function() {
                if (!$(this).find('.row-checkbox').prop('checked')) {
                    var rowIndex = $(this).data('row-index');
                    if (rowIndex !== undefined) excludedRows.push(rowIndex);
                }
            });
            var exportUrl = 'export_bank_settlement_pdf_pcso.php?';
            exportUrl += 'partner=' + encodeURIComponent(partner);
            exportUrl += '&bank=' + encodeURIComponent(bank);
            exportUrl += '&settlement_type=' + encodeURIComponent(settlementType);
            exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            exportUrl += '&rfp_no=' + encodeURIComponent(rfpNo);
            exportUrl += '&include_lookback=' + encodeURIComponent($('#include_lookback').val() || '0');
            if (excludedRows.length > 0) exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            var reasonDataStr = JSON.stringify(reasonData);
            exportUrl += '&reason_data=' + encodeURIComponent(reasonDataStr);
            window.open(exportUrl, '_blank');
            setTimeout(function() { hideLoadingModal(); }, 500);
        }, 300);
    }

    function settleSelected() {
        if (!isDateRangeSet()) {
            Swal.fire({ icon: 'warning', title: 'Date Filters Required', text: 'Please select both "Transaction Date From" and "Transaction Date To" before settling.', confirmButtonColor: '#ffc107' });
            return;
        }
        var cadGenerated = $('#cadGenerated').val() === 'true';
        if (!cadGenerated) { Swal.fire({ icon: 'error', title: 'Cannot Settle', text: 'The selected partner does not have a valid bank abbreviation configured.', confirmButtonColor: '#dc3545' }); return; }
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) { Swal.fire({ icon: 'warning', title: 'RFP No. Required', text: 'Please enter an RFP No.', confirmButtonColor: '#ffc107' }); return; }
        var cadNo = $('#currentCadNo').val() || '';
        if (!cadNo) {
            var badgeText = $('#currentCadBadge').text() || $('.table-badge:contains("CAD No.")').first().text() || '';
            var match = badgeText.match(/CAD No\.:\s*([A-Z0-9\-]+)/i);
            if (match) cadNo = match[1].trim();
        }
        if (!cadNo) { Swal.fire({ icon: 'warning', title: 'CAD No. Missing', text: 'CAD No. is not available. Please refresh the page and try again.', confirmButtonColor: '#ffc107' }); return; }
        var checkedRows = [];
        var totalVolume = 0;
        var totalSettlement = 0;
        var skippedSettled = 0;
        var unsettledReasons = [];
        $('.data-row').each(function() {
            var row = $(this);
            var checkbox = row.find('.row-checkbox');
            var isSettled = row.data('is-settled') === true;
            var partnerId = String(row.data('partner-id'));
            var rowIndex = row.data('row-index');
            if (checkbox.prop('checked') && !checkbox.prop('disabled')) {
                if (isSettled) { skippedSettled++; return; }
                var partnerName = row.data('partner-name') || row.find('.partner-name-cell').text().trim();
                var rawTxnCount = parseInt(row.data('txn-count')) || 0;
                var rawSettlement = parseFloat(row.data('settlement')) || 0;
                if (!checkedRows.some(r => r.partner_id === partnerId && r.row_index === rowIndex)) {
                    checkedRows.push({ partner_id: partnerId, partner_name: partnerName, txn_count: rawTxnCount, settlement_amount: rawSettlement, row_index: rowIndex });
                    totalVolume += rawTxnCount;
                    totalSettlement += rawSettlement;
                }
            }
        });
        $('.data-row').each(function() {
            var checkbox = $(this).find('.row-checkbox');
            var isSettled = $(this).data('is-settled') === true;
            var rowIndex = $(this).data('row-index');
            if (!checkbox.prop('checked') && !isSettled && rowIndex !== undefined) {
                var reason = reasonData[rowIndex];
                if (reason && reason.reason) unsettledReasons.push({ partner_id: reason.partner_id, reason: reason.reason });
            }
        });
        if (checkedRows.length === 0) {
            var msg = 'No unsettled branches selected.';
            if (skippedSettled > 0) msg += ' ' + skippedSettled + ' fully settled branch(es) were skipped.';
            Swal.fire({ icon: 'warning', title: 'No Rows to Settle', text: msg, confirmButtonColor: '#ffc107' });
            return;
        }
        var skipMsg = skippedSettled > 0 ? `<p style="color:#856404;margin-top:10px;"><i class="fas fa-info-circle"></i> ${skippedSettled} fully settled branch(es) were skipped.</p>` : '';
        var reasonMsg = '';
        if (unsettledReasons.length > 0) {
            reasonMsg = `<div style="background:#f8f9fa;padding:10px;border-radius:4px;margin-top:10px;">
                    <p><strong>Reasons for not settling:</strong></p>
                    <ul style="margin:5px 0;padding-left:20px;">${unsettledReasons.map(r => `<li>${r.partner_id}: ${r.reason}</li>`).join('')}</ul>
                </div>`;
        }
        Swal.fire({
            title: 'Settle Selected PCSO Branches?',
            html: `<div style="text-align:left;max-height:300px;overflow-y:auto;">
                    <p><strong>RFP No.:</strong> ${rfpNo}</p>
                    <p><strong>CAD No.:</strong> ${cadNo}</p>
                    <p><strong>You are about to settle:</strong></p>
                    <ul style="margin:10px 0;padding-left:20px;">
                        ${checkedRows.map(row => `<li><strong>${row.partner_name}</strong> - ${row.txn_count.toLocaleString()} transactions (${row.settlement_amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})})</li>`).join('')}
                    </ul>
                    <div style="background:#f8f9fa;padding:10px;border-radius:4px;margin-top:10px;">
                        <p><strong>Total Branches:</strong> ${checkedRows.length}</p>
                        <p><strong>Total Volume Count:</strong> ${totalVolume.toLocaleString()}</p>
                        <p><strong>Total Settlement Amount:</strong> ${totalSettlement.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                    </div>
                    ${reasonMsg}
                    <p style="color:#856404;margin-top:10px;"><i class="fas fa-exclamation-triangle"></i> This will mark the selected branch transactions as <strong>SETTLED</strong>.</p>
                    <p style="color:#0c5460;margin-top:5px;"><i class="fas fa-info-circle"></i> Settled By: <strong><?php echo $display_name; ?></strong></p>
                    ${skipMsg}
                </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Settle Selected',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return new Promise((resolve) => {
                    Swal.fire({ title: 'Processing PCSO Settlement', html: 'Please wait while we process the settlement...<br><small>This may take a few moments.</small>', allowOutsideClick: false, allowEscapeKey: false, didOpen: () => { Swal.showLoading(); } });
                    var settledBy = '<?php echo addslashes($display_name); ?>';
                    var partnerIds = [...new Set(checkedRows.map(r => String(r.partner_id)))];
                    var partner = $('#partner').val() || '';
                    var bank = $('#bank').val() || '';
                    var settlementType = $('#settlement_type').val() || '';
                    var dateFrom = $('#date_from').val() || '';
                    var dateTo = $('#date_to').val() || '';
                    var includeLookback = $('#include_lookback').val() || '0';
                    var reasonDataStr = JSON.stringify(reasonData);
                    $.ajax({
                        url: 'settle_transactions.php',
                        type: 'POST',
                        data: {
                            partner_ids: partnerIds,
                            settled_by: settledBy,
                            rfp_no: rfpNo,
                            cad_no: cadNo,
                            partner_filter: partner,
                            bank_filter: bank,
                            settlement_type_filter: settlementType,
                            date_from: dateFrom,
                            date_to: dateTo,
                            include_lookback: includeLookback,
                            reason_data: reasonDataStr,
                            excluded_references: JSON.stringify([])
                        },
                        dataType: 'json',
                        timeout: 120000,
                        success: function(response) {
                            if (response.success) resolve({ success: true, message: response.message, data: response.data, partnerIds: partnerIds });
                            else resolve({ success: false, message: response.message || 'Settlement failed.' });
                        },
                        error: function(xhr, status) {
                            var errorMsg = 'An error occurred while processing settlement.';
                            if (status === 'timeout') errorMsg = 'Request timed out. The settlement may still be processing. Please check the status.';
                            resolve({ success: false, message: errorMsg });
                        }
                    });
                });
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                if (result.value.success) {
                    var settledPartnerIds = result.value.partnerIds || [];
                    $('.data-row').each(function() {
                        var partnerId = String($(this).data('partner-id'));
                        if (settledPartnerIds.indexOf(partnerId) === -1) return;
                        $(this).data('is-settled', true);
                        var checkbox = $(this).find('.row-checkbox');
                        checkbox.prop('disabled', true).prop('checked', true);
                        var statusCell = $(this).find('.status-badge');
                        statusCell.removeClass('unsettled partial').addClass('settled');
                        statusCell.html('<i class="fas fa-check-circle"></i> Settled');
                        $(this).find('.reason-dropdown').prop('disabled', true).val('').css('opacity', '0.5');
                        $(this).removeClass('unsettled-row partial-row').addClass('settled-row');
                        var rowIndex = $(this).data('row-index');
                        if (rowIndex !== undefined) delete reasonData[rowIndex];
                    });
                    updateTotals();
                    Swal.fire({
                        icon: 'success',
                        title: 'PCSO Settlement Successful',
                        html: `<p>${result.value.message}</p>
                            <div style="text-align:left;margin-top:15px;background:#d4edda;padding:15px;border-radius:4px;border:1px solid #28a745;">
                                <strong><i class="fas fa-check-circle"></i> Ready to Export:</strong>
                                <p style="margin-top:5px;">You can now export this PCSO settlement to Excel or PDF using the buttons above.</p>
                            </div>`,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Settlement Failed', text: result.value.message, confirmButtonColor: '#dc3545' });
                }
            }
        });
    }
</script>

</body>
</html>