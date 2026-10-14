<?php
// settlement-per-bank.php

// Add cache control headers to prevent browser caching
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

// prefer explicit session values for current user email; don't gate on role
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

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
    // The caller should handle this case and use 'RFP' as fallback
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
    // YYYY-MM-000DD  (day padded to 5 digits)
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

    // Special case for partner_id_kpx = 107
    // Amount for Settlement = (Principal - Charge Amount) + Adjustment
    // VAT Amount = (Charge to Partner / 1.12) * 0.02
    // Charge Amount = Charge to Partner - VAT Amount
    if ((string)$partner_id === '107') {
        $vat_amount = ($charge_to_partner / 1.12) * 0.02;
        $charge_amount = $charge_to_partner - $vat_amount;
        return ($principal - $charge_amount) + $adjustment;
    }

    // Special case for partner_id_kpx = 811
    // VAT Amount = ((Volume Count * 10) / 1.12) * 0.02
    // Transaction Amount = (Volume Count * 10) - VAT Amount
    // Amount for Settlement = Principal - Transaction Amount + Adjustment
    if ((string)$partner_id === '811') {
        $vat_amount = (($txn_count * 10) / 1.12) * 0.02;
        $transaction_amount = ($txn_count * 10) - $vat_amount;
        return $principal - $transaction_amount + $adjustment;
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
// FUNCTION: Check if a date is Monday
// ============================================
function isMonday(?string $date): bool {
    if (empty($date)) return false;
    $timestamp = strtotime($date);
    if ($timestamp === false) return false;
    return date('N', $timestamp) == 1; // 1 = Monday
}

// ============================================
// FUNCTION: Get Wednesday-to-Tuesday range
// Given a Tuesday date, returns [wednesday_last_week, tuesday]
// ============================================
function getWednesdayToTuesdayRange(string $tuesday_date): array {
    $timestamp = strtotime($tuesday_date);
    if ($timestamp === false) {
        return [$tuesday_date, $tuesday_date];
    }
    // Wednesday of last week = Tuesday - 6 days
    $wednesday = date('Y-m-d', strtotime('-6 days', $timestamp));
    $tuesday = date('Y-m-d', $timestamp);
    return [$wednesday, $tuesday];
}

// ============================================
// FUNCTION: Get Monday-to-Sunday range (week BEFORE a given Tuesday)
// Given a Tuesday date, returns [monday, sunday] of the week before it
// ============================================
function getMondayToSundayRange(string $tuesday_date): array {
    $timestamp = strtotime($tuesday_date);
    if ($timestamp === false) {
        return [$tuesday_date, $tuesday_date];
    }
    // Sunday before the Tuesday = Tuesday - 2 days
    $sunday = date('Y-m-d', strtotime('-2 days', $timestamp));
    // Monday of that week = Sunday - 6 days
    $monday = date('Y-m-d', strtotime('-6 days', strtotime($sunday)));
    return [$monday, $sunday];
}

// ============================================
// FUNCTION: Get Saturday-to-Friday range
// Given a Monday date, returns [saturday_previous_week, friday_before_monday]
// Example: Monday 2026-08-10 => [2026-08-01 (Sat), 2026-08-07 (Fri)]
// ============================================
function getSaturdayToFridayRange(string $monday_date): array {
    $timestamp = strtotime($monday_date);
    if ($timestamp === false) {
        return [$monday_date, $monday_date];
    }
    // Saturday of the previous week = Monday - 9 days
    $saturday = date('Y-m-d', strtotime('-9 days', $timestamp));
    // Friday before the Monday = Monday - 3 days
    $friday = date('Y-m-d', strtotime('-3 days', $timestamp));
    return [$saturday, $friday];
}

// ============================================
// FUNCTION: Get Monday-to-Sunday range (week BEFORE a given Monday)
// Given a Monday date, returns [monday, sunday] of the previous week
// Example: Monday 2026-10-12 => [2026-10-05 (Mon), 2026-10-11 (Sun)]
// ============================================
function getPreviousMondayToSundayRange(string $monday_date): array {
    $timestamp = strtotime($monday_date);
    if ($timestamp === false) {
        return [$monday_date, $monday_date];
    }
    // Monday of the previous week = Monday - 7 days
    $prev_monday = date('Y-m-d', strtotime('-7 days', $timestamp));
    // Sunday of that week = prev Monday + 6 days
    $prev_sunday = date('Y-m-d', strtotime('+6 days', strtotime($prev_monday)));
    return [$prev_monday, $prev_sunday];
}

// ============================================
// FUNCTION: Check if date range is Friday-to-Sunday (or subset within)
// Used for the daily breakdown feature of specific weekend partners.
// Accepts:
//   - Friday -> Sunday
//   - Saturday -> Sunday
//   - Friday -> Saturday
// ============================================
function isFridayToSundayRange(?string $date_from, ?string $date_to): bool {
    if (empty($date_from) || empty($date_to)) return false;

    $from_ts = strtotime($date_from);
    $to_ts = strtotime($date_to);
    if ($from_ts === false || $to_ts === false) return false;

    $from_day = (int)date('N', $from_ts); // 5 = Friday, 6 = Saturday
    $to_day   = (int)date('N', $to_ts);   // 6 = Saturday, 7 = Sunday

    // Friday -> Sunday
    if ($from_day === 5 && $to_day === 7) return true;
    // Saturday -> Sunday
    if ($from_day === 6 && $to_day === 7) return true;
    // Friday -> Saturday
    if ($from_day === 5 && $to_day === 6) return true;

    return false;
}

// ============================================
// FUNCTION: Get partners that require a per-day breakdown
// when the date range covers Friday to Sunday.
// ============================================
function getWeekendBreakdownPartners(): array {
    return ['291', '811', '819', '1050', '297', '99', '927', '348', '494'];
}

// ============================================
// FUNCTION: Check if a partner requires per-day breakdown for a weekend range
// ============================================
function requiresWeekendDailyBreakdown(string $partner_id, ?string $date_from, ?string $date_to): bool {
    if (!isFridayToSundayRange($date_from, $date_to)) {
        return false;
    }
    return in_array(trim($partner_id), getWeekendBreakdownPartners(), true);
}

// ============================================
// FUNCTION: Get special weekly partners list
// Partners 457, 458, 459 = CHINABANK (CHECK)
// Partner 460 = BDO (ONLINE)
// These partners only settle on Tuesdays, capturing Wed last week -> Tue
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
    
    // CHINABANK partners: 457, 458, 459 with CHECK
    if (in_array($partner_id, ['457', '458', '459'])) {
        if (strpos($bank_upper, 'CHINA') !== false && $type_upper === 'CHECK') {
            return true;
        }
    }
    
    // BDO partner: 460 with ONLINE
    if ($partner_id === '460') {
        if (strpos($bank_upper, 'BDO') !== false && $type_upper === 'ONLINE') {
            return true;
        }
    }
    
    return false;
}

// ============================================
// FUNCTION: Get week-before partners list
// Partner 1005 = BDO UNIBANK, INC. (BDO) / ONLINE
// Only settles on Tuesdays, capturing Monday -> Sunday of the week BEFORE the Tuesday
// ============================================
function getSpecialWeekBeforePartners(): array {
    return ['1005'];
}

// ============================================
// FUNCTION: Check if partner is a week-before partner
// Partner 1005 with BDO/ONLINE
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
// FUNCTION: Get special Monday partners list
// Partner 119 = settles on Mondays, capturing Sat -> Fri of the previous week
// ============================================
function getSpecialMondayPartners(): array {
    return ['119'];
}

// ============================================
// FUNCTION: Check if partner is a special Monday partner
// ============================================
function isSpecialMondayPartner(string $partner_id): bool {
    return trim($partner_id) === '119';
}

// ============================================
// FUNCTION: Get special Monday-to-Sunday partners list
// Partners 1101, 1228, 1230, 1094
// Only settles on Mondays, capturing Monday -> Sunday of the PREVIOUS week
// ============================================
function getSpecialMondayToSundayPartners(): array {
    return ['1101', '1228', '1230', '1094'];
}

// ============================================
// FUNCTION: Check if partner is a special Monday-to-Sunday partner
// ============================================
function isSpecialMondayToSundayPartner(string $partner_id): bool {
    return in_array(trim($partner_id), getSpecialMondayToSundayPartners(), true);
}

// ============================================
// FETCH FILTER DATA
// ============================================
try {
    // Get distinct partners (partner_id_kpx + partner_name)
    $partners_query = "SELECT DISTINCT partner_id_kpx, partner_name, bank FROM masterdata.partner_masterfile WHERE partner_id_kpx IS NOT NULL AND partner_id_kpx != '' ORDER BY partner_name";
    $partners_result = $conn->query($partners_query);
    $partners = [];
    while ($row = $partners_result->fetch_assoc()) {
        $partners[] = $row;
    }

    // Get distinct banks from partner_masterfile
    $banks_query = "SELECT DISTINCT bank FROM masterdata.partner_masterfile WHERE bank IS NOT NULL AND bank != '' ORDER BY bank";
    $banks_result = $conn->query($banks_query);
    $banks = [];
    while ($row = $banks_result->fetch_assoc()) {
        $banks[] = $row;
    }

    // Get distinct settlement types from partner_masterfile
    $settlement_types_query = "SELECT DISTINCT settled_online_check FROM masterdata.partner_masterfile WHERE settled_online_check IS NOT NULL AND settled_online_check != '' ORDER BY settled_online_check";
    $settlement_types_result = $conn->query($settlement_types_query);
    $settlement_types = [];
    while ($row = $settlement_types_result->fetch_assoc()) {
        $settlement_types[] = $row;
    }

} catch (Exception $e) {
    error_log("Error fetching filter data: " . $e->getMessage());
    $partners = [];
    $banks = [];
    $settlement_types = [];
}

// Get filter values from GET parameters with proper sanitization
$selected_partner = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$selected_bank = isset($_GET['bank']) ? trim($_GET['bank']) : '';
$selected_settlement_type = isset($_GET['settlement_type']) ? trim($_GET['settlement_type']) : '';
$selected_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$selected_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$selected_rfp_no = isset($_GET['rfp_no']) ? trim($_GET['rfp_no']) : '';

// Flag to check if filters are applied
$has_filters = !empty(array_filter($_GET));

// ============================================
// AUTO-POPULATE BANK FROM PARTNER
// Skip for partner 259 (FAST UNIMERCHANTS INC.) so all three
// region/bank splits (BPI + BDO Negros + BDO Cebu/Bohol) can appear
// when only the partner is selected.
// Also skip for partner 257 (FDC Visayas) so BDO-Panay + Chinabank
// (Bohol/Ormoc/Samar/Tacloban) splits can all appear.
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
// Partners 457, 458, 459 (CHINABANK/CHECK) and 460 (BDO/ONLINE)
// Only included when date_to falls on a Tuesday.
// When included, transaction range = Wednesday of last week to Tuesday.
// ============================================
$special_partners = getSpecialWeeklyPartners();
$include_special_partners = false;
$special_date_from = $selected_date_from;
$special_date_to = $selected_date_to;
$selected_is_special = in_array($selected_partner, $special_partners, true);

// Check if date_to falls on a Tuesday
if (!empty($selected_date_to) && isTuesday($selected_date_to)) {
    $include_special_partners = true;
    // Override date range: Wednesday last week to Tuesday
    list($special_date_from, $special_date_to) = getWednesdayToTuesdayRange($selected_date_to);
}

// ============================================
// SPECIAL WEEK-BEFORE PARTNER LOGIC (Partner 1005)
// Partner 1005 = BDO UNIBANK, INC. (BDO) / ONLINE
// Only included when date_to falls on a Tuesday.
// When included, transaction range = Monday → Sunday of the week BEFORE the Tuesday.
// ============================================
$special_wb_partners = getSpecialWeekBeforePartners();
$include_special_wb_partners = false;
$special_wb_date_from = $selected_date_from;
$special_wb_date_to = $selected_date_to;
$selected_is_special_wb = in_array($selected_partner, $special_wb_partners, true);

// Check if date_to falls on a Tuesday
if (!empty($selected_date_to) && isTuesday($selected_date_to)) {
    $include_special_wb_partners = true;
    // Override date range: Monday → Sunday of the week before the Tuesday
    list($special_wb_date_from, $special_wb_date_to) = getMondayToSundayRange($selected_date_to);
}

// ============================================
// SPECIAL MONDAY PARTNER LOGIC (Partner 119)
// Partner 119 only settles on Mondays.
// When date_to falls on a Monday, transaction range = Saturday -> Friday of the previous week.
// ============================================
$special_monday_partners = getSpecialMondayPartners();
$include_special_monday_partners = false;
$special_monday_date_from = $selected_date_from;
$special_monday_date_to = $selected_date_to;
$selected_is_special_monday = in_array($selected_partner, $special_monday_partners, true);

// Check if date_to falls on a Monday
if (!empty($selected_date_to) && isMonday($selected_date_to)) {
    $include_special_monday_partners = true;
    // Override date range: Saturday (prev week) -> Friday (before Monday)
    list($special_monday_date_from, $special_monday_date_to) = getSaturdayToFridayRange($selected_date_to);
}

// ============================================
// SPECIAL MONDAY-TO-SUNDAY PARTNER LOGIC (Partners 1101, 1228, 1230, 1094)
// These partners only settle on Mondays.
// When date_to falls on a Monday, transaction range = Monday -> Sunday of the PREVIOUS week.
// ============================================
$special_m2s_partners = getSpecialMondayToSundayPartners();
$include_special_m2s_partners = false;
$special_m2s_date_from = $selected_date_from;
$special_m2s_date_to = $selected_date_to;
$selected_is_special_m2s = in_array($selected_partner, $special_m2s_partners, true);

// Check if date_to falls on a Monday
if (!empty($selected_date_to) && isMonday($selected_date_to)) {
    $include_special_m2s_partners = true;
    // Override date range: Monday -> Sunday of the previous week
    list($special_m2s_date_from, $special_m2s_date_to) = getPreviousMondayToSundayRange($selected_date_to);
}

// ============================================
// RETRIEVE RFP NO. FROM DATABASE IF NOT PROVIDED
// ============================================
if (empty($selected_rfp_no) && $has_filters) {
    $rfp_query = "SELECT DISTINCT rfp_no FROM mldb.billspayment_transaction bt 
                  LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                  WHERE 1=1";
    
    $rfp_params = [];
    $rfp_types = "";
    
    if (!empty($selected_partner)) {
        $rfp_query .= " AND bt.partner_id_kpx = ?";
        $rfp_params[] = $selected_partner;
        $rfp_types .= "s";
    }
    if (!empty($selected_bank)) {
        $rfp_query .= " AND pm.bank = ?";
        $rfp_params[] = $selected_bank;
        $rfp_types .= "s";
    }
    if (!empty($selected_settlement_type)) {
        $rfp_query .= " AND pm.settled_online_check = ?";
        $rfp_params[] = $selected_settlement_type;
        $rfp_types .= "s";
    }
    if (!empty($selected_date_from) && !empty($selected_date_to)) {
        $rfp_query .= " AND bt.datetime BETWEEN ? AND ?";
        $rfp_params[] = $selected_date_from . ' 00:00:00';
        $rfp_params[] = $selected_date_to . ' 23:59:59';
        $rfp_types .= "ss";
    } elseif (!empty($selected_date_from)) {
        $rfp_query .= " AND bt.datetime >= ?";
        $rfp_params[] = $selected_date_from . ' 00:00:00';
        $rfp_types .= "s";
    } elseif (!empty($selected_date_to)) {
        $rfp_query .= " AND bt.datetime <= ?";
        $rfp_params[] = $selected_date_to . ' 23:59:59';
        $rfp_types .= "s";
    }
    
    $rfp_query .= " AND rfp_no IS NOT NULL AND rfp_no != '' LIMIT 1";
    
    $rfp_stmt = $conn->prepare($rfp_query);
    if ($rfp_stmt && !empty($rfp_params)) {
        $rfp_stmt->bind_param($rfp_types, ...$rfp_params);
        $rfp_stmt->execute();
        $rfp_result = $rfp_stmt->get_result();
        if ($rfp_row = $rfp_result->fetch_assoc()) {
            $selected_rfp_no = $rfp_row['rfp_no'];
            $_GET['rfp_no'] = $selected_rfp_no;
        }
        $rfp_stmt->close();
    }
}

// ============================================
// AUTO-POPULATE DATE RANGE FROM RFP NO.
// When the user supplies an RFP number but leaves the date filters empty,
// look up the actual min/max transaction datetime for that RFP so the
// main query stays selective and does not time out.
// Example: RFP-CIS-001 on 2026-09-01 → date_from = date_to = 2026-09-01
// ============================================
if (!empty($selected_rfp_no) && empty($selected_date_from) && empty($selected_date_to)) {
    $rfp_date_query = "SELECT 
                           DATE(MIN(bt.datetime)) AS min_date,
                           DATE(MAX(bt.datetime)) AS max_date
                       FROM mldb.billspayment_transaction bt
                       WHERE bt.rfp_no = ?
                         AND bt.datetime IS NOT NULL
                       LIMIT 1";
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
                // Re-evaluate whether we have a multi-day range
                $has_date_range = ($selected_date_from !== $selected_date_to);
                error_log("Settlement Page - Auto-filled dates from RFP '$selected_rfp_no': $min_date → $max_date");
            }
        }
        $rfp_date_stmt->close();
    }
}

// ============================================
// GENERATE CAD NUMBER
// ============================================
$bank_abbreviation = '';
$cad_number = '';
$cad_generated = false;

// Prefer generating a fresh CAD from the current filters.
// Only reuse a DB value when it already looks correct (starts with a real bank abbr).
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

// Resolve bank abbreviation first (needed for both generation and validation)
if (!empty($selected_bank)) {
    $bank_abbreviation = getBankAbbreviation($conn, $selected_bank);
    error_log("Settlement Page - Bank abbreviation for '$selected_bank': '$bank_abbreviation'");
}

// If we still don't have a bank abbreviation, try to get it from the partner
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
            error_log("Settlement Page - Bank abbreviation from partner: '$bank_abbreviation'");
        }
        $abbr_stmt->close();
    }
}

// Decide whether the existing CAD is usable
$use_existing = false;
if (!empty($existing_cad) && !empty($bank_abbreviation)) {
    // Accept only if it starts with the correct bank abbr (reject old RFP- fallbacks)
    if (stripos($existing_cad, $bank_abbreviation . '-') === 0) {
        $use_existing = true;
        $cad_number = $existing_cad;
        $cad_generated = true;
    }
} elseif (!empty($existing_cad) && empty($bank_abbreviation) && stripos($existing_cad, 'RFP-') !== 0) {
    // No bank selected - keep non-RFP existing value
    $use_existing = true;
    $cad_number = $existing_cad;
    $cad_generated = true;
}

if (!$use_existing) {
    // Generate a new CAD
    $settlement_abbr = '';
    if (!empty($selected_settlement_type)) {
        $settlement_abbr = getSettlementAbbreviation($selected_settlement_type);
    }

    $cad_date = formatCADDate($selected_date_from, $selected_date_to);

    if (!empty($bank_abbreviation)) {
        $cad_number = $bank_abbreviation;
        $cad_generated = true;
    } else {
        error_log("Settlement Page - WARNING: No bank abbreviation found for bank='$selected_bank' partner='$selected_partner'. Using 'RFP' as fallback.");
        $cad_number = 'RFP';
        $cad_generated = false; // CAD is not valid for settlement
    }

    if (!empty($settlement_abbr)) {
        $cad_number .= '-' . $settlement_abbr;
    }

    $cad_number .= '-' . $cad_date;
}

error_log("Settlement Page - Final CAD Number: " . $cad_number);
error_log("Settlement Page - CAD Generated: " . ($cad_generated ? 'Yes' : 'No'));

// Store filters in session to maintain state
if (!empty(array_filter($_GET))) {
    $_SESSION['settlement_filters'] = $_GET;
}

// Check if date range exists and if dates are different (not the same day)
$has_date_range = false;
if (!empty($selected_date_from) && !empty($selected_date_to)) {
    if ($selected_date_from !== $selected_date_to) {
        $has_date_range = true;
    }
} elseif (!empty($selected_date_from) || !empty($selected_date_to)) {
    $has_date_range = false;
}

// ============================================
// FUNCTION: Get daily breakdown for a partner
// ============================================
function getDailyBreakdown(mysqli $conn, string $partner_id, string $bank, string $settlement_type, string $date_from, string $date_to, array $regions = [], ?string $extra_where = null) {
    if (empty($partner_id)) {
        return [];
    }
    
    $where_conditions_regular = [];
    $where_conditions_adjustment = [];
    $params_regular = [];
    $params_adjustment = [];
    $types_regular = "";
    $types_adjustment = "";
    
    $where_conditions_regular[] = "bt.partner_id_kpx = ?";
    $params_regular[] = $partner_id;
    $types_regular .= "s";
    
    $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
    $params_adjustment[] = $partner_id;
    $types_adjustment .= "s";
    
    // Optional region filter (used for FDC Mindanao split: GENSAN / CDO, Partner 257 splits)
    if (!empty($regions)) {
        $placeholders = implode(',', array_fill(0, count($regions), '?'));
        $where_conditions_regular[] = "bt.region IN ($placeholders)";
        $where_conditions_adjustment[] = "bt.region IN ($placeholders)";
        foreach ($regions as $reg) {
            $params_regular[] = $reg;
            $types_regular .= "s";
            $params_adjustment[] = $reg;
            $types_adjustment .= "s";
        }
    }

    // Extra raw WHERE (account_no / address filters for FDC Ormoc / Tacloban)
    if (!empty($extra_where)) {
        $where_conditions_regular[] = $extra_where;
        $where_conditions_adjustment[] = $extra_where;
    }
    
    if (!empty($bank)) {
        $where_conditions_regular[] = "pm.bank = ?";
        $params_regular[] = $bank;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.bank = ?";
        $params_adjustment[] = $bank;
        $types_adjustment .= "s";
    }
    
    if (!empty($settlement_type)) {
        $where_conditions_regular[] = "pm.settled_online_check = ?";
        $params_regular[] = $settlement_type;
        $types_regular .= "s";
        
        $where_conditions_adjustment[] = "pm.settled_online_check = ?";
        $params_adjustment[] = $settlement_type;
        $types_adjustment .= "s";
    }
    
    // LATE POSTING LOOKBACK support (same rules as main query)
    $is_single = (!empty($date_from) && !empty($date_to) && $date_from === $date_to);
    $lb_from = null;
    $lb_to   = null;
    if ($is_single) {
        $lb_from = date('Y-m-d', strtotime($date_from . ' -7 days'));
        $lb_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
    }

    if ($is_single && $lb_from && $lb_to) {
        $where_conditions_regular[] = "(
            (bt.datetime BETWEEN ? AND ?)
            OR
            (
                bt.datetime BETWEEN ? AND ?
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
        $params_regular[] = $date_from . ' 00:00:00';
        $types_regular .= "s";
    } elseif (!empty($date_to)) {
        $where_conditions_regular[] = "bt.datetime <= ?";
        $params_regular[] = $date_to . ' 23:59:59';
        $types_regular .= "s";
    }
    
    $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";
    
    // Adjustments remain on the exact settlement date(s)
    if (!empty($date_from) && !empty($date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date BETWEEN ? AND ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "ss";
    } elseif (!empty($date_from)) {
        $where_conditions_adjustment[] = "bt.cancellation_date >= ?";
        $params_adjustment[] = $date_from . ' 00:00:00';
        $types_adjustment .= "s";
    } elseif (!empty($date_to)) {
        $where_conditions_adjustment[] = "bt.cancellation_date <= ?";
        $params_adjustment[] = $date_to . ' 23:59:59';
        $types_adjustment .= "s";
    }
    
    $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";
    
    $sql = "SELECT 
                transaction_date,
                SUM(txn_count) as txn_count,
                SUM(total_principal) as total_principal,
                SUM(charge_to_customer) as charge_to_customer,
                SUM(charge_to_partner) as charge_to_partner,
                SUM(total_adjustment) as total_adjustment,
                MAX(settle_status) as settle_status
            FROM (
                SELECT 
                    DATE(bt.datetime) as transaction_date,
                    COUNT(*) as txn_count,
                    SUM(CASE WHEN bt.amount_paid > 0 THEN bt.amount_paid ELSE 0 END) as total_principal,
                    SUM(bt.charge_to_customer) as charge_to_customer,
                    SUM(bt.charge_to_partner) as charge_to_partner,
                    0 as total_adjustment,
                    MAX(bt.settle_unsettle) as settle_status
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_regular) . "
                GROUP BY DATE(bt.datetime)
                
                UNION ALL
                
                SELECT 
                    DATE(bt.cancellation_date) as transaction_date,
                    0 as txn_count,
                    0 as total_principal,
                    0 as charge_to_customer,
                    0 as charge_to_partner,
                    SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment,
                    '' as settle_status
                FROM mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                GROUP BY DATE(bt.cancellation_date)
            ) combined
            GROUP BY transaction_date
            ORDER BY transaction_date ASC";
    
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
                
                $charge_to = '';
                $charge_sched = '';
                $partner_sql = "SELECT COALESCE(charge_to, '') as charge_to, COALESCE(charge_sched, '') as charge_sched 
                                FROM masterdata.partner_masterfile 
                                WHERE partner_id_kpx = ?";
                $partner_stmt = $conn->prepare($partner_sql);
                if ($partner_stmt) {
                    $partner_stmt->bind_param("s", $partner_id);
                    $partner_stmt->execute();
                    $partner_result = $partner_stmt->get_result();
                    if ($partner_row = $partner_result->fetch_assoc()) {
                        $charge_to = $partner_row['charge_to'] ?? '';
                        $charge_sched = $partner_row['charge_sched'] ?? '';
                    }
                    $partner_stmt->close();
                }
                
                $principal = (float)($row['total_principal'] ?? 0);
                $charge_to_customer = (float)($row['charge_to_customer'] ?? 0);
                $charge_to_partner = (float)($row['charge_to_partner'] ?? 0);
                $adjustment = (float)($row['total_adjustment'] ?? 0);
                $txn_count = (int)($row['txn_count'] ?? 0);
                
                $amount_for_settlement = calculateSettlementAmount(
                    $charge_to,
                    $charge_sched,
                    $principal,
                    $charge_to_customer,
                    $charge_to_partner,
                    $adjustment,
                    $partner_id,
                    $txn_count
                );
                
                $data[] = [
                    'transaction_date' => $row['transaction_date'],
                    'txn_count' => $txn_count,
                    'total_principal' => $principal,
                    'charge_to_customer' => $charge_to_customer,
                    'charge_to_partner' => $charge_to_partner,
                    'total_adjustment' => $adjustment,
                    'amount_for_settlement' => $amount_for_settlement,
                    'settle_status' => $status,
                    'is_settled' => $is_settled
                ];
            }
            return $data;
        }
    }
    return [];
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

    // LATE POSTING LOOKBACK support (same rules as main query)
    $is_single = (!empty($date_from) && !empty($date_to) && $date_from === $date_to);
    $lb_from = null;
    $lb_to   = null;
    if ($is_single) {
        $lb_from = date('Y-m-d', strtotime($date_from . ' -7 days'));
        $lb_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
    }

    if ($is_single && $lb_from && $lb_to) {
        $where_regular[] = "(
            (bt.datetime BETWEEN ? AND ?)
            OR
            (
                bt.datetime BETWEEN ? AND ?
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

    // Adjustments remain on the exact settlement date(s)
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
 * Supports late-posting lookback when date_from == date_to (single-day settlement).
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

    // LATE POSTING LOOKBACK support (same rules as main query)
    $is_single = (!empty($date_from) && !empty($date_to) && $date_from === $date_to);
    $lb_from = null;
    $lb_to   = null;
    if ($is_single) {
        $lb_from = date('Y-m-d', strtotime($date_from . ' -7 days'));
        $lb_to   = date('Y-m-d', strtotime($date_from . ' -1 day'));
    }

    if ($is_single && $lb_from && $lb_to) {
        $where_regular[] = "(
            (bt.datetime BETWEEN ? AND ?)
            OR
            (
                bt.datetime BETWEEN ? AND ?
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

    // Adjustments remain on the exact settlement date(s)
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

// ============================================
// FUNCTION: Generate daily breakdown HTML
// ============================================
function generateDailyBreakdownHTML(array $data): string {
    if (empty($data)) {
        return '<div style="text-align: center; padding: 10px; color: #6c757d;">No daily transactions found for this date range.</div>';
    }
    
    $html = '<table class="daily-breakdown-table" style="width: 100%; border-collapse: collapse; margin: 5px 0;">';
    $html .= '<thead>';
    $html .= '<tr style="background-color: #e9ecef; font-size: 12px;">';
    $html .= '<th style="padding: 6px 12px; text-align: center; width: 120px;">Date</th>';
    $html .= '<th style="padding: 6px 12px; text-align: center;">Volume Count</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">₱ Principal</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">₱ Charge to Customer</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">₱ Charge to Partner</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">₱ Adjustment</th>';
    $html .= '<th style="padding: 6px 12px; text-align: right;">₱ Settlement</th>';
    $html .= '<th style="padding: 6px 12px; text-align: center;">Status</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    $dailyTotals = [
        'txn_count' => 0,
        'principal' => 0,
        'charge_to_customer' => 0,
        'charge_to_partner' => 0,
        'adjustment' => 0,
        'settlement' => 0,
        'settled_count' => 0,
        'unsettled_count' => 0
    ];
    
    foreach ($data as $daily) {
        $dailyTotals['txn_count'] += $daily['txn_count'];
        $dailyTotals['principal'] += $daily['total_principal'];
        $dailyTotals['charge_to_customer'] += $daily['charge_to_customer'];
        $dailyTotals['charge_to_partner'] += $daily['charge_to_partner'];
        $dailyTotals['adjustment'] += $daily['total_adjustment'];
        $dailyTotals['settlement'] += $daily['amount_for_settlement'];
        
        if ($daily['is_settled']) {
            $dailyTotals['settled_count'] += $daily['txn_count'];
        } else {
            $dailyTotals['unsettled_count'] += $daily['txn_count'];
        }
        
        $adjClass = '';
        $adjSign = '';
        $adjAmount = $daily['total_adjustment'];
        if ($adjAmount < 0) {
            $adjClass = 'color: #dc3545;';
        } else if ($adjAmount > 0) {
            $adjClass = 'color: #28a745;';
        }
        $adjSign = $adjAmount >= 0 ? '+' : '';
        
        $settleClass = $daily['amount_for_settlement'] < 0 ? 'color: #dc3545;' : '';
        
        $statusBadge = $daily['is_settled'] 
            ? '<span class="settled-status"><i class="fas fa-check-circle"></i> Settled</span>'
            : '<span class="unsettled-status"><i class="fas fa-clock"></i> Unsettled</span>';
        
        $html .= '<tr class="daily-breakdown-row ' . ($daily['is_settled'] ? 'settled-row' : 'unsettled-row') . '" style="font-size: 13px;">';
        $html .= '<td style="padding: 6px 12px; text-align: center; font-weight: 500; color: #495057;">' . 
            date('M d, Y', strtotime($daily['transaction_date'])) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: center;">' . 
            number_format($daily['txn_count'], 0) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;"> ' . 
            number_format($daily['total_principal'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;"> ' . 
            number_format($daily['charge_to_customer'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: right;"> ' . 
            number_format($daily['charge_to_partner'], 2) . '</td>';
        
        if ($daily['total_adjustment'] != 0) {
            $html .= '<td style="padding: 6px 12px; text-align: right; ' . $adjClass . '">' . 
                $adjSign . ' ' . number_format($daily['total_adjustment'], 2) . '</td>';
        } else {
            $html .= '<td style="padding: 6px 12px; text-align: right;"></td>';
        }
        
        $html .= '<td style="padding: 6px 12px; text-align: right; font-weight: 600; ' . $settleClass . '"> ' . 
            number_format($daily['amount_for_settlement'], 2) . '</td>';
        $html .= '<td style="padding: 6px 12px; text-align: center;">' . $statusBadge . '</td>';
        $html .= '</tr>';
    }
    
    $adjClass = $dailyTotals['adjustment'] < 0 ? 'color: #dc3545;' : ($dailyTotals['adjustment'] > 0 ? 'color: #28a745;' : '');
    $adjSign = $dailyTotals['adjustment'] >= 0 ? '+' : '';
    $settleClass = $dailyTotals['settlement'] < 0 ? 'color: #dc3545;' : '';
    
    $html .= '<tr class="daily-subtotal-row" style="font-size: 13px; font-weight: 600; background-color: #e9ecef;">';
    $html .= '<td style="padding: 6px 12px; text-align: right; font-weight: 600;">DAILY SUBTOTAL</td>';
    $html .= '<td style="padding: 6px 12px; text-align: center;">' . number_format($dailyTotals['txn_count'], 0) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;"> ' . number_format($dailyTotals['principal'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;"> ' . number_format($dailyTotals['charge_to_customer'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: right;"> ' . number_format($dailyTotals['charge_to_partner'], 2) . '</td>';
    
    if ($dailyTotals['adjustment'] != 0) {
        $html .= '<td style="padding: 6px 12px; text-align: right; ' . $adjClass . '">' . 
            $adjSign . ' ' . number_format($dailyTotals['adjustment'], 2) . '</td>';
    } else {
        $html .= '<td style="padding: 6px 12px; text-align: right;"></td>';
    }
    
    $html .= '<td style="padding: 6px 12px; text-align: right; ' . $settleClass . '"> ' . 
        number_format($dailyTotals['settlement'], 2) . '</td>';
    $html .= '<td style="padding: 6px 12px; text-align: center; font-size: 11px;">';
    if ($dailyTotals['settled_count'] > 0 && $dailyTotals['unsettled_count'] > 0) {
        $html .= '<span style="color: #28a745;">' . number_format($dailyTotals['settled_count']) . ' Settled</span> / ';
        $html .= '<span style="color: #dc3545;">' . number_format($dailyTotals['unsettled_count']) . ' Unsettled</span>';
    } elseif ($dailyTotals['settled_count'] > 0) {
        $html .= '<span class="settled-status">All ' . number_format($dailyTotals['settled_count']) . ' Settled</span>';
    } else {
        $html .= '<span class="unsettled-status">All ' . number_format($dailyTotals['unsettled_count']) . ' Unsettled</span>';
    }
    $html .= '</td>';
    $html .= '</tr>';
    
    $html .= '</tbody></table>';
    return $html;
}

$display_name = 'GUEST';
$display_email = '';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') {
        $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
        $display_email = $_SESSION['admin_email'] ?? '';
    } elseif ($_SESSION['user_type'] === 'user') {
        $display_name = $_SESSION['user_name'] ?? 'USER';
        $display_email = $_SESSION['user_email'] ?? '';
    }
}

// ============================================
// REASON NOT SETTLED OPTIONS
// ============================================
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
    <title>Settlement Per Bank | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
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
    <!-- Simple Loading Overlay - Solid opaque background -->
    <div id="loadingModal">
        <div class="simple-loader-content">
            <div class="simple-loader-ring"></div>
            <h3 class="simple-loader-title">Loading Settlement Data</h3>
            <p class="simple-loader-subtitle">Please wait a moment</p>
            <div class="simple-loader-progress">
                <div class="simple-loader-progress-bar"></div>
            </div>
            <div class="simple-loader-time">
                <i class="far fa-clock"></i>
                <span>Elapsed: <span id="elapsedTime">0</span>s</span>
            </div>
        </div>
    </div>

    <!-- Main Content - Hidden initially -->
    <div class="main-container main-content-hidden" id="mainContent">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Settlement Per Bank</h2>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" action="" class="filter-form" id="filterForm">
                <!-- Partner Filter -->
                <div class="filter-group">
                    <label for="partner">Partner</label>
                    <select id="partner" name="partner" class="select2-dropdown" data-selected="<?php echo htmlspecialchars($selected_partner); ?>">
                        <option value="">All Partners</option>
                        <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo htmlspecialchars($partner['partner_id_kpx']); ?>" 
                                data-bank="<?php echo htmlspecialchars($partner['bank'] ?? ''); ?>"
                                <?php echo ($selected_partner == $partner['partner_id_kpx']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($partner['partner_id_kpx'] . ' - ' . $partner['partner_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Bank Filter -->
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

                <!-- Settlement Type Filter -->
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

                <!-- Date Range Filters -->
                <div class="filter-group">
                    <label for="date_from">Transaction Date From</label>
                    <input type="date" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($selected_date_from); ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">Transaction Date To</label>
                    <input type="date" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($selected_date_to); ?>">
                </div>

                <!-- RFP No. Field -->
                <div class="filter-group" style="flex: 0 0 200px;">
                    <label for="rfp_no">RFP No.</label>
                    <input type="text" id="rfp_no" name="rfp_no" 
                           placeholder="Enter RFP No." 
                           value="<?php echo htmlspecialchars($selected_rfp_no); ?>"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px;">
                </div>

                <!-- Action Buttons -->
                <div class="filter-actions">
                    <button type="submit" class="btn-filter" id="filterBtn">
                        <i class="fas fa-search" aria-hidden="true"></i> Filter
                    </button>
                    <a href="settlement-per-bank.php" class="btn-reset"><i class="fas fa-undo" aria-hidden="true"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <div id="resultsContainer">
        <?php
        // Process the filters and display results
        if ($has_filters) {
            try {
                $where_conditions_regular = [];
                $where_conditions_adjustment = [];
                $params_regular = [];
                $params_adjustment = [];
                $types_regular = "";
                $types_adjustment = "";
                
                if (!empty($selected_partner)) {
                    $where_conditions_regular[] = "bt.partner_id_kpx = ?";
                    $params_regular[] = $selected_partner;
                    $types_regular .= "s";
                    
                    $where_conditions_adjustment[] = "bt.partner_id_kpx = ?";
                    $params_adjustment[] = $selected_partner;
                    $types_adjustment .= "s";
                }
                
                if (!empty($selected_bank)) {
                    $where_conditions_regular[] = "pm.bank = ?";
                    $params_regular[] = $selected_bank;
                    $types_regular .= "s";
                    
                    $where_conditions_adjustment[] = "pm.bank = ?";
                    $params_adjustment[] = $selected_bank;
                    $types_adjustment .= "s";
                }
                
                if (!empty($selected_settlement_type)) {
                    $where_conditions_regular[] = "pm.settled_online_check = ?";
                    $params_regular[] = $selected_settlement_type;
                    $types_regular .= "s";
                    
                    $where_conditions_adjustment[] = "pm.settled_online_check = ?";
                    $params_adjustment[] = $selected_settlement_type;
                    $types_adjustment .= "s";
                }

                // Filter by RFP No. when provided – this is critical so that
                // querying by RFP alone does not scan the entire table.
                if (!empty($selected_rfp_no)) {
                    $where_conditions_regular[] = "bt.rfp_no = ?";
                    $params_regular[] = $selected_rfp_no;
                    $types_regular .= "s";

                    $where_conditions_adjustment[] = "bt.rfp_no = ?";
                    $params_adjustment[] = $selected_rfp_no;
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
                    // Partner 1005 selected but date_to is not Tuesday → exclude
                    $where_conditions_regular[] = "1=0";
                    $where_conditions_adjustment[] = "1=0";
                }
                
                if (empty($selected_partner) && !$include_special_wb_partners) {
                    // No partner selected and date_to is not Tuesday → exclude 1005
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
                // SPECIAL MONDAY PARTNER (119)
                // ============================================
                if ($selected_is_special_monday && !$include_special_monday_partners) {
                    // Partner 119 selected but date_to is not Monday → exclude
                    $where_conditions_regular[] = "1=0";
                    $where_conditions_adjustment[] = "1=0";
                }
                
                if (empty($selected_partner) && !$include_special_monday_partners) {
                    // No partner selected and date_to is not Monday → exclude 119
                    $sm_placeholders = implode(',', array_fill(0, count($special_monday_partners), '?'));
                    $where_conditions_regular[] = "bt.partner_id_kpx NOT IN ($sm_placeholders)";
                    $where_conditions_adjustment[] = "bt.partner_id_kpx NOT IN ($sm_placeholders)";
                    foreach ($special_monday_partners as $sm) {
                        $params_regular[] = $sm;
                        $types_regular .= "s";
                        $params_adjustment[] = $sm;
                        $types_adjustment .= "s";
                    }
                }
                
                // ============================================
                // SPECIAL MONDAY-TO-SUNDAY PARTNERS (1101, 1228, 1230, 1094)
                // ============================================
                if ($selected_is_special_m2s && !$include_special_m2s_partners) {
                    // Partner selected but date_to is not Monday → exclude
                    $where_conditions_regular[] = "1=0";
                    $where_conditions_adjustment[] = "1=0";
                }
                
                if (empty($selected_partner) && !$include_special_m2s_partners) {
                    // No partner selected and date_to is not Monday → exclude these partners
                    $m2s_placeholders = implode(',', array_fill(0, count($special_m2s_partners), '?'));
                    $where_conditions_regular[] = "bt.partner_id_kpx NOT IN ($m2s_placeholders)";
                    $where_conditions_adjustment[] = "bt.partner_id_kpx NOT IN ($m2s_placeholders)";
                    foreach ($special_m2s_partners as $m2s) {
                        $params_regular[] = $m2s;
                        $types_regular .= "s";
                        $params_adjustment[] = $m2s;
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
                
                $use_special_monday_dates = false;
                if ($selected_is_special_monday && $include_special_monday_partners) {
                    $use_special_monday_dates = true;
                }
                
                $use_special_m2s_dates = false;
                if ($selected_is_special_m2s && $include_special_m2s_partners) {
                    $use_special_m2s_dates = true;
                }
                
                if ($use_special_dates) {
                    $effective_date_from = $special_date_from;
                    $effective_date_to = $special_date_to;
                } elseif ($use_special_wb_dates) {
                    $effective_date_from = $special_wb_date_from;
                    $effective_date_to = $special_wb_date_to;
                } elseif ($use_special_monday_dates) {
                    $effective_date_from = $special_monday_date_from;
                    $effective_date_to = $special_monday_date_to;
                } elseif ($use_special_m2s_dates) {
                    $effective_date_from = $special_m2s_date_from;
                    $effective_date_to = $special_m2s_date_to;
                } else {
                    $effective_date_from = $selected_date_from;
                    $effective_date_to = $selected_date_to;
                }

                // ============================================================
                // LATE POSTING LOOKBACK (single-day settlement)
                // When Transaction Date From == To (e.g. 2026-09-02), also
                // include UNSETTLED transactions from the previous 7 days
                // (late postings that were uploaded after the previous day's
                // settlement was already processed). Only unsettled ones are
                // pulled so already-settled volume from prior days is never
                // double-counted.
                // Example: Sept 2 has 10 txns + 1 unsettled late from Sept 1
                //          → volume count on Sept 2 = 11
                // ============================================================
                $is_single_day_settlement = (
                    !empty($effective_date_from)
                    && !empty($effective_date_to)
                    && $effective_date_from === $effective_date_to
                    && !$use_special_dates
                    && !$use_special_wb_dates
                    && !$use_special_monday_dates
                    && !$use_special_m2s_dates
                );
                $lookback_from = null;
                $lookback_to   = null;
                if ($is_single_day_settlement) {
                    $lookback_from = date('Y-m-d', strtotime($effective_date_from . ' -7 days'));
                    $lookback_to   = date('Y-m-d', strtotime($effective_date_from . ' -1 day'));
                }
                
                if ($is_single_day_settlement && $lookback_from && $lookback_to) {
                    // Include:
                    //  1) All transactions on the selected day (any settle status)
                    //  2) Only UNSETTLED transactions from the 7-day lookback window
                    $where_conditions_regular[] = "(
                        (bt.datetime BETWEEN ? AND ?)
                        OR
                        (
                            bt.datetime BETWEEN ? AND ?
                            AND (bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR UPPER(TRIM(bt.settle_unsettle)) != 'SETTLED')
                        )
                    )";
                    $params_regular[] = $effective_date_from . ' 00:00:00';
                    $params_regular[] = $effective_date_to   . ' 23:59:59';
                    $params_regular[] = $lookback_from . ' 00:00:00';
                    $params_regular[] = $lookback_to   . ' 23:59:59';
                    $types_regular .= "ssss";
                } elseif (!empty($effective_date_from) && !empty($effective_date_to)) {
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
                
                $where_conditions_regular[] = "(bt.status IS NULL OR bt.status = '')";
                
                // Adjustments stay strictly on the effective (settlement) date range
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
                
                $where_conditions_adjustment[] = "(bt.status IS NOT NULL AND bt.status != '')";

                // Detect whether we should apply weekend per-day breakdown
                $apply_weekend_daily_breakdown = false;
                $weekend_partner_list = getWeekendBreakdownPartners();

                // If specific partner selected and is a weekend-breakdown partner and range is Fri-Sun
                if (!empty($selected_partner) && in_array($selected_partner, $weekend_partner_list, true)
                    && isFridayToSundayRange($effective_date_from, $effective_date_to)) {
                    $apply_weekend_daily_breakdown = true;
                }
                
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
                    MIN(bt.datetime) as first_transaction_date,
                    DATE(bt.datetime) as transaction_date_group
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
                         pm.charge_sched,
                         DATE(bt.datetime)
                ORDER BY bt.partner_id_kpx, DATE(bt.datetime) ASC";
                
                $adjustment_sql = "SELECT 
                                        bt.partner_id_kpx,
                                        DATE(bt.cancellation_date) as cancellation_date_group,
                                        SUM(CASE WHEN bt.amount_paid < 0 THEN bt.amount_paid ELSE 0 END) as total_adjustment
                                    FROM mldb.billspayment_transaction bt
                                    LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                                    WHERE " . implode(" AND ", $where_conditions_adjustment) . "
                                    GROUP BY bt.partner_id_kpx, DATE(bt.cancellation_date)";
                
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
                
                $combined_data = [];
                // We'll store adjustments keyed by partner_id and also partner_id + date
                $adjustments_by_partner = [];
                $adjustments_by_partner_date = [];
                
                if ($adjustment_result && $adjustment_result->num_rows > 0) {
                    while ($row = $adjustment_result->fetch_assoc()) {
                        $partner_id = $row['partner_id_kpx'];
                        $date_group = $row['cancellation_date_group'] ?? null;
                        $adj_val = (float)($row['total_adjustment'] ?? 0);
                        
                        // Accumulate per partner
                        if (!isset($adjustments_by_partner[$partner_id])) {
                            $adjustments_by_partner[$partner_id] = 0;
                        }
                        $adjustments_by_partner[$partner_id] += $adj_val;
                        
                        // Accumulate per partner + date
                        if (!empty($date_group)) {
                            $key = $partner_id . '|' . $date_group;
                            if (!isset($adjustments_by_partner_date[$key])) {
                                $adjustments_by_partner_date[$key] = 0;
                            }
                            $adjustments_by_partner_date[$key] += $adj_val;
                        }
                    }
                }
                
                if ($regular_result && $regular_result->num_rows > 0) {
                    while ($row = $regular_result->fetch_assoc()) {
                        $partner_id = $row['partner_id_kpx'];
                        $transaction_date = $row['transaction_date_group'] ?? null;
                        
                        // Determine if this partner should be split per day for a weekend range
                        $needs_daily_breakdown = false;
                        if (!empty($transaction_date)
                            && isFridayToSundayRange($effective_date_from, $effective_date_to)
                            && in_array($partner_id, $weekend_partner_list, true)) {
                            $needs_daily_breakdown = true;
                        }
                        
                        if ($needs_daily_breakdown) {
                            $key = $partner_id . '_' . $transaction_date;
                            $date_key = $partner_id . '|' . $transaction_date;
                            $adj_for_date = $adjustments_by_partner_date[$date_key] ?? 0;
                            
                            $combined_data[$key] = [
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
                                'total_adjustment' => $adj_for_date,
                                'settled_count' => (int)($row['settled_count'] ?? 0),
                                'unsettled_count' => (int)($row['unsettled_count'] ?? 0),
                                'last_transaction_date' => $row['last_transaction_date'] ?? null,
                                'first_transaction_date' => $row['first_transaction_date'] ?? null,
                                'is_daily_breakdown' => true,
                                'transaction_date' => $transaction_date
                            ];
                        } else {
                            $key = $partner_id;
                            if (isset($combined_data[$key]) && empty($combined_data[$key]['is_daily_breakdown'])) {
                                // Merge with existing lump-sum entry (shouldn't normally happen since we group by date,
                                // but if there's a mix, aggregate)
                                $combined_data[$key]['txn_count'] += (int)($row['txn_count'] ?? 0);
                                $combined_data[$key]['total_principal'] += (float)($row['total_principal'] ?? 0);
                                $combined_data[$key]['charge_to_customer'] += (float)($row['charge_to_customer'] ?? 0);
                                $combined_data[$key]['charge_to_partner'] += (float)($row['charge_to_partner'] ?? 0);
                                $combined_data[$key]['settled_count'] += (int)($row['settled_count'] ?? 0);
                                $combined_data[$key]['unsettled_count'] += (int)($row['unsettled_count'] ?? 0);
                            } else {
                                $combined_data[$key] = [
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
                    }
                }
                
                // Apply lump-sum adjustments to non-daily-breakdown entries
                foreach ($adjustments_by_partner as $partner_id => $adj_total) {
                    // If partner has only a lump-sum entry, apply the full adjustment
                    if (isset($combined_data[$partner_id]) && empty($combined_data[$partner_id]['is_daily_breakdown'])) {
                        $combined_data[$partner_id]['total_adjustment'] = $adj_total;
                    } else {
                        // If partner has daily-breakdown entries or no entry, handle:
                        // - For daily breakdown entries: already handled per-date above
                        // - If no entry exists at all, create a lump-sum row with just the adjustment
                        $has_any_daily = false;
                        foreach ($combined_data as $k => $v) {
                            if ($v['partner_id_kpx'] === $partner_id && !empty($v['is_daily_breakdown'])) {
                                $has_any_daily = true;
                                break;
                            }
                        }
                        
                        if (!$has_any_daily && !isset($combined_data[$partner_id])) {
                            // Partner has only adjustments, no regular rows
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
                                        'total_adjustment' => $adj_total,
                                        'settled_count' => 0,
                                        'unsettled_count' => 0,
                                        'last_transaction_date' => null,
                                        'first_transaction_date' => null
                                    ];
                                }
                                $stmt->close();
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
                // Only include when date_to falls on a Tuesday.
                // When included, re-fetch with Monday→Sunday range (week before the Tuesday).
                // ------------------------------------------------
                if (!$include_special_wb_partners) {
                    // date_to is not Tuesday → remove partner 1005 from results
                    foreach ($special_wb_partners as $wb_id) {
                        if (isset($combined_data[$wb_id])) {
                            unset($combined_data[$wb_id]);
                        }
                    }
                } else {
                    // date_to IS Tuesday - re-fetch partner 1005 with Mon→Sun range
                    foreach ($special_wb_partners as $wb_id) {
                        $should_process = isset($combined_data[$wb_id]) || $selected_partner === $wb_id;
                        
                        if (!$should_process) {
                            continue;
                        }
                        
                        // Verify bank + settlement type
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
                        
                        // Remove existing entry and re-fetch
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
                // SPECIAL: Handle partner 119 (Monday settlement, Sat->Fri of prev week)
                // Only include when date_to falls on a Monday.
                // When included, re-fetch with Saturday -> Friday range (previous week).
                // ------------------------------------------------
                if (!$include_special_monday_partners) {
                    // date_to is not Monday → remove partner 119 from results
                    foreach ($special_monday_partners as $sm_id) {
                        if (isset($combined_data[$sm_id])) {
                            unset($combined_data[$sm_id]);
                        }
                    }
                } else {
                    // date_to IS Monday - re-fetch partner 119 with Sat->Fri range
                    foreach ($special_monday_partners as $sm_id) {
                        $should_process = isset($combined_data[$sm_id]) || $selected_partner === $sm_id;

                        if (!$should_process) {
                            continue;
                        }

                        if (!isSpecialMondayPartner($sm_id)) {
                            continue;
                        }

                        // Remove existing entry and re-fetch
                        unset($combined_data[$sm_id]);

                        $sm_entry = getPartnerTotalsByRegions(
                            $conn,
                            $sm_id,
                            $selected_bank,
                            $selected_settlement_type,
                            $special_monday_date_from,
                            $special_monday_date_to,
                            [],
                            null
                        );

                        if ($sm_entry !== null) {
                            $sm_entry['special_monday'] = true;
                            $sm_entry['special_monday_date_from'] = $special_monday_date_from;
                            $sm_entry['special_monday_date_to'] = $special_monday_date_to;
                            $combined_data[$sm_id] = $sm_entry;
                        }
                    }
                }

                // ------------------------------------------------
                // SPECIAL: Handle partners 1101, 1228, 1230, 1094
                // (Monday-to-Sunday previous week capture)
                // Only include when date_to falls on a Monday.
                // When included, re-fetch with Monday -> Sunday range (previous week).
                // ------------------------------------------------
                if (!$include_special_m2s_partners) {
                    // date_to is not Monday → remove these partners from results
                    foreach ($special_m2s_partners as $m2s_id) {
                        if (isset($combined_data[$m2s_id])) {
                            unset($combined_data[$m2s_id]);
                        }
                    }
                } else {
                    // date_to IS Monday - re-fetch these partners with Mon->Sun (prev week) range
                    foreach ($special_m2s_partners as $m2s_id) {
                        $should_process = isset($combined_data[$m2s_id]) || $selected_partner === $m2s_id;

                        if (!$should_process) {
                            continue;
                        }

                        if (!isSpecialMondayToSundayPartner($m2s_id)) {
                            continue;
                        }

                        // Remove existing entry and re-fetch
                        unset($combined_data[$m2s_id]);

                        $m2s_entry = getPartnerTotalsByRegions(
                            $conn,
                            $m2s_id,
                            $selected_bank,
                            $selected_settlement_type,
                            $special_m2s_date_from,
                            $special_m2s_date_to,
                            [],
                            null
                        );

                        if ($m2s_entry !== null) {
                            $m2s_entry['special_monday_to_sunday'] = true;
                            $m2s_entry['special_m2s_date_from'] = $special_m2s_date_from;
                            $m2s_entry['special_m2s_date_to'] = $special_m2s_date_to;
                            $combined_data[$m2s_id] = $m2s_entry;
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
                            // If same group, order by partner_id then by date (for daily breakdown rows)
                            $pid_compare = strcmp($a['partner_id_kpx'] ?? '', $b['partner_id_kpx'] ?? '');
                            if ($pid_compare !== 0) {
                                return $pid_compare;
                            }
                            $date_a = $a['transaction_date'] ?? '';
                            $date_b = $b['transaction_date'] ?? '';
                            return strcmp($date_a, $date_b);
                        }
                        return $order_a - $order_b;
                    });
                    
                    $groups = [
                        'CHARGE BY CUSTOMER DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER DAILY',
                            'icon' => 'fa-user',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY CUSTOMER WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER WEEKLY',
                            'icon' => 'fa-user-clock',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY CUSTOMER MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY CUSTOMER MONTHLY',
                            'icon' => 'fa-user-plus',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER DAILY',
                            'icon' => 'fa-calendar-day',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER WEEKLY',
                            'icon' => 'fa-calendar-week',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER SEMI MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER SEMI-MONTHLY',
                            'icon' => 'fa-calendar-alt',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY PARTNER MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY PARTNER MONTHLY',
                            'icon' => 'fa-calendar-check',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0]
                        ],
                        'CHARGE BY BOTH DAILY' => [
                            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) DAILY',
                            'icon' => 'fa-handshake',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_both' => true
                        ],
                        'CHARGE BY BOTH WEEKLY' => [
                            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) WEEKLY',
                            'icon' => 'fa-handshake',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_both' => true
                        ],
                        'CHARGE BY BOTH MONTHLY' => [
                            'display_name' => 'NOTE: CHARGE BY BOTH (CUSTOMER & PARTNER) MONTHLY',
                            'icon' => 'fa-handshake',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_both' => true
                        ],
                        'UNCATEGORIZED' => [
                            'display_name' => 'PARTNERS WITHOUT CHARGE TYPE (UNCATEGORIZED)',
                            'icon' => 'fa-exclamation-triangle',
                            'rows' => [],
                            'totals' => ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0],
                            'is_uncategorized' => true
                        ]
                    ];
                    
                    $grand_totals = ['txn_count' => 0, 'principal' => 0, 'charge_to_customer' => 0, 'charge_to_partner' => 0, 'adjustment' => 0, 'settlement' => 0, 'settled_count' => 0, 'unsettled_count' => 0];
                    
                    $row_index = 0;
                    $daily_breakdown_cache = [];
                    if ($has_date_range) {
                        foreach ($data_array as $row) {
                            $partner_id = $row['partner_id_kpx'] ?? '';
                            if (empty($partner_id)) {
                                continue;
                            }
                            
                            // Skip daily-breakdown rows for the weekend partners — they're already split per day.
                            if (!empty($row['is_daily_breakdown'])) {
                                continue;
                            }
                            
                            $daily_date_from = $selected_date_from;
                            $daily_date_to = $selected_date_to;
                            if (!empty($row['special_weekly'])) {
                                $daily_date_from = $row['special_date_from'] ?? $selected_date_from;
                                $daily_date_to = $row['special_date_to'] ?? $selected_date_to;
                            } elseif (!empty($row['special_week_before'])) {
                                $daily_date_from = $row['special_wb_date_from'] ?? $selected_date_from;
                                $daily_date_to = $row['special_wb_date_to'] ?? $selected_date_to;
                            } elseif (!empty($row['special_monday'])) {
                                $daily_date_from = $row['special_monday_date_from'] ?? $selected_date_from;
                                $daily_date_to = $row['special_monday_date_to'] ?? $selected_date_to;
                            } elseif (!empty($row['special_monday_to_sunday'])) {
                                $daily_date_from = $row['special_m2s_date_from'] ?? $selected_date_from;
                                $daily_date_to = $row['special_m2s_date_to'] ?? $selected_date_to;
                            }
                            
                            $regions = $row['fdc_regions'] ?? $row['fui_regions'] ?? $row['fdc257_regions'] ?? [];
                            $extra_where = $row['fdc257_extra_where'] ?? null;
                            
                            if (!empty($row['fdc_split'])) {
                                $cache_key = $partner_id . '-' . $row['fdc_split'];
                            } elseif (!empty($row['fui_split'])) {
                                $cache_key = $partner_id . '-' . $row['fui_split'];
                            } elseif (!empty($row['fdc257_split'])) {
                                $cache_key = $partner_id . '-' . $row['fdc257_split'];
                            } elseif (!empty($row['is_pcso_landbank'])) {
                                $cache_key = 'pcso-' . $partner_id;
                            } else {
                                $cache_key = $partner_id;
                            }

                            if (isset($daily_breakdown_cache[$cache_key])) {
                                continue;
                            }

                            $bank_for_daily = (!empty($row['fui_split']) || !empty($row['fdc257_split'])) ? '' : $selected_bank;

                            $daily_data = getDailyBreakdown(
                                $conn,
                                $partner_id,
                                $bank_for_daily,
                                $selected_settlement_type,
                                $daily_date_from,
                                $daily_date_to,
                                $regions,
                                $extra_where
                            );
                            if (!empty($daily_data)) {
                                $daily_breakdown_cache[$cache_key] = $daily_data;
                            }
                        }
                    }
                    
                    foreach ($data_array as $row) {
                        $charge_to = strtoupper(trim($row['charge_to'] ?? ''));
                        // Normalize charge_sched (PER TRANSACTION -> DAILY; NOT APPLICABLE / NO-BANK-SETTLEMENT / '' -> '')
                        $charge_sched = normalizeChargeSched($row['charge_sched'] ?? '');
                        
                        $group_key = null;
                        
                        if (empty($charge_to) || empty($charge_sched)) {
                            // Any empty charge_to OR empty/normalized-to-empty charge_sched => UNCATEGORIZED
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
                        
                        $partner_id = $row['partner_id_kpx'];
                        if (!empty($row['fdc_split'])) {
                            $daily_cache_key = $partner_id . '-' . $row['fdc_split'];
                        } elseif (!empty($row['fui_split'])) {
                            $daily_cache_key = $partner_id . '-' . $row['fui_split'];
                        } elseif (!empty($row['fdc257_split'])) {
                            $daily_cache_key = $partner_id . '-' . $row['fdc257_split'];
                        } elseif (!empty($row['is_pcso_landbank'])) {
                            $daily_cache_key = 'pcso-' . $partner_id;
                        } else {
                            $daily_cache_key = $partner_id;
                        }
                        
                        $is_daily_breakdown_row = !empty($row['is_daily_breakdown']);
                        $daily_data = (!$is_daily_breakdown_row && $has_date_range && isset($daily_breakdown_cache[$daily_cache_key])) 
                            ? $daily_breakdown_cache[$daily_cache_key] 
                            : [];
                        $daily_html = !empty($daily_data) ? generateDailyBreakdownHTML($daily_data) : '';
                        
                        $is_excluded = $is_fully_settled;
                        
                        $display_partner_name = $row['partner_name'] ?? $row['partner_id_kpx'];
                        if (!empty($row['is_pcso_landbank']) && !empty($row['pcso_region'])) {
                            $display_partner_name = $row['pcso_region'] . ' - ' . $display_partner_name;
                        }
                        
                        // For weekend daily-breakdown rows, append the date to the display name
                        $row_date_display = '';
                        if ($is_daily_breakdown_row && !empty($row['transaction_date'])) {
                            $row_date_display = date('M d, Y', strtotime($row['transaction_date']));
                        }
                        
                        $groups[$group_key]['rows'][] = [
                            'row_index' => $row_index,
                            'partner_id' => $partner_id,
                            'partner_name' => $display_partner_name,
                            'display_date' => $row_date_display,
                            'is_daily_breakdown_row' => $is_daily_breakdown_row,
                            'transaction_date' => $row['transaction_date'] ?? null,
                            'account_name' => $row['partner_accName'] ?? 'N/A',
                            'account_number' => $row['bank_accNumber'] ?? 'N/A',
                            'txn_count' => $txn_count,
                            'principal' => $principal,
                            'charge_to_customer' => $charge_to_customer,
                            'charge_to_partner' => $charge_to_partner,
                            'adjustment' => $adjustment,
                            'settlement_amount' => $settlement_amount,
                            'is_negative' => $settlement_amount < 0,
                            'excluded' => $is_excluded,
                            'is_fully_settled' => $is_fully_settled,
                            'is_partially_settled' => $is_partially_settled,
                            'settled_count' => $settled_count,
                            'unsettled_count' => $unsettled_count,
                            'has_daily_breakdown' => (!$is_daily_breakdown_row && $has_date_range),
                            'daily_html' => $daily_html,
                            'charge_to' => $charge_to,
                            'charge_sched' => $charge_sched,
                            'is_pcso_landbank' => !empty($row['is_pcso_landbank']),
                            'pcso_region' => $row['pcso_region'] ?? '',
                            'special_weekly' => !empty($row['special_weekly']),
                            'special_date_from' => $row['special_date_from'] ?? null,
                            'special_date_to' => $row['special_date_to'] ?? null,
                            'special_week_before' => !empty($row['special_week_before']),
                            'special_wb_date_from' => $row['special_wb_date_from'] ?? null,
                            'special_wb_date_to' => $row['special_wb_date_to'] ?? null,
                            'special_monday' => !empty($row['special_monday']),
                            'special_monday_date_from' => $row['special_monday_date_from'] ?? null,
                            'special_monday_date_to' => $row['special_monday_date_to'] ?? null,
                            'special_monday_to_sunday' => !empty($row['special_monday_to_sunday']),
                            'special_m2s_date_from' => $row['special_m2s_date_from'] ?? null,
                            'special_m2s_date_to' => $row['special_m2s_date_to'] ?? null
                        ];
                        $row_index++;
                    }
                    
                    $groups = array_filter($groups, function($group) {
                        return !empty($group['rows']);
                    });
                    
                    $has_uncategorized = isset($groups['UNCATEGORIZED']) && !empty($groups['UNCATEGORIZED']['rows']);
                    $has_both = false;
                    foreach ($groups as $key => $group) {
                        if (isset($group['is_both']) && $group['is_both'] === true && !empty($group['rows'])) {
                            $has_both = true;
                            break;
                        }
                    }
                    ?>
                    
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="fas fa-file-invoice" aria-hidden="true"></i> Settlement Summary
                            </h3>
                            <span class="table-badge">
                                <i class="fas fa-layer-group"></i> Total Partners: <?php 
                                    $total_partners = 0;
                                    foreach ($groups as $group) {
                                        $total_partners += count($group['rows']);
                                    }
                                    echo $total_partners; 
                                ?>
                            </span>
                            <?php if (!empty($selected_bank)): ?>
                            <span class="table-badge">
                                <i class="fas fa-university"></i> Bank: <?php echo htmlspecialchars($selected_bank); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($selected_rfp_no)): ?>
                            <span class="table-badge">
                                <i class="fas fa-file-invoice"></i> RFP No.: <?php echo htmlspecialchars($selected_rfp_no); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($cad_number)): ?>
                            <span class="table-badge" id="currentCadBadge">
                                <i class="fas fa-hashtag"></i> CAD No.: <?php echo htmlspecialchars($cad_number); ?>
                            </span>
                            <input type="hidden" id="currentCadNo" value="<?php echo htmlspecialchars($cad_number); ?>">
                            <input type="hidden" id="cadGenerated" value="<?php echo $cad_generated ? 'true' : 'false'; ?>">
                            <?php endif; ?>
                            <?php if ($has_date_range): ?>
                            <span class="table-badge">
                                <i class="fas fa-calendar-alt"></i> 
                                <?php 
                                $date_range = '';
                                if (!empty($selected_date_from)) $date_range .= 'From: ' . date('M d, Y', strtotime($selected_date_from));
                                if (!empty($selected_date_to)) $date_range .= ' To: ' . date('M d, Y', strtotime($selected_date_to));
                                echo htmlspecialchars($date_range);
                                ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($include_special_partners && !empty($special_date_from) && !empty($special_date_to)): ?>
                            <span class="table-badge" style="background: #856404; color: #fff;">
                                <i class="fas fa-calendar-week"></i> Weekly Capture for LDS transactions: <?php echo date('M d, Y', strtotime($special_date_from)); ?> → <?php echo date('M d, Y', strtotime($special_date_to)); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($include_special_wb_partners && !empty($special_wb_date_from) && !empty($special_wb_date_to)): ?>
                            <span class="table-badge" style="background: #856404; color: #fff;">
                                <i class="fas fa-calendar-week"></i> Week-Before Capture (Mon-Sun) for SHOPEE - LDS: <?php echo date('M d, Y', strtotime($special_wb_date_from)); ?> → <?php echo date('M d, Y', strtotime($special_wb_date_to)); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($include_special_monday_partners && !empty($special_monday_date_from) && !empty($special_monday_date_to)): ?>
                            <span class="table-badge" style="background: #856404; color: #fff;">
                                <i class="fas fa-calendar-week"></i> Monday Capture (Sat-Fri) for Partner 119: <?php echo date('M d, Y', strtotime($special_monday_date_from)); ?> → <?php echo date('M d, Y', strtotime($special_monday_date_to)); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($include_special_m2s_partners && !empty($special_m2s_date_from) && !empty($special_m2s_date_to)): ?>
                            <span class="table-badge" style="background: #856404; color: #fff;">
                                <i class="fas fa-calendar-week"></i> Monday Capture (Mon-Sun prev week) for Partners 1101, 1228, 1230, 1094: <?php echo date('M d, Y', strtotime($special_m2s_date_from)); ?> → <?php echo date('M d, Y', strtotime($special_m2s_date_to)); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!$cad_generated && !empty($selected_partner)): ?>
                            <span class="table-badge" style="background: #dc3545; color: #fff;">
                                <i class="fas fa-exclamation-triangle"></i> Invalid Bank - Cannot Settle
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$cad_generated && !empty($selected_partner)): ?>
                        <div class="alert-danger-custom">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Error:</strong> 
                            The selected partner does not have a valid bank abbreviation configured. 
                            Please ensure the partner's bank is properly set in the partner masterfile and bank abbreviations are configured in the bank table.
                            <strong>Settlement is disabled until this is fixed.</strong>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($has_both): ?>
                        <div class="alert-info-custom">
                            <i class="fas fa-info-circle"></i>
                            <strong>Information:</strong> 
                            Partners with <strong>"BOTH"</strong> charge type are shown in the BOTH groups. These partners charge both customers and partners.
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($has_uncategorized): ?>
                        <div class="alert-warning-custom">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning:</strong> 
                            Partners under <strong>"UNCATEGORIZED"</strong> group have no charge types. Please update the partner masterfile to properly categorize these partners.
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($include_special_partners): ?>
                        <div class="alert-info-custom" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
                            <i class="fas fa-calendar-week"></i>
                            <strong>Weekly Settlement Notice:</strong> 
                           LDS Partners 457, 458, 459 (CHINABANK/CHECK) and 460 (BDO/ONLINE) settle weekly on Tuesdays. 
                            Transactions captured: <strong><?php echo date('M d, Y', strtotime($special_date_from)); ?></strong> 
                            (Wednesday) → <strong><?php echo date('M d, Y', strtotime($special_date_to)); ?></strong> (Tuesday).
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($include_special_wb_partners): ?>
                        <div class="alert-info-custom" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
                            <i class="fas fa-calendar-week"></i>
                            <strong>Week-Before Settlement Notice:</strong> 
                            Partner SHOPEE - LSD 1005 (BDO/ONLINE) settles on Tuesdays but captures the full week <strong>before</strong> the Tuesday. 
                            Transactions captured: <strong><?php echo date('M d, Y', strtotime($special_wb_date_from)); ?></strong> 
                            (Monday) → <strong><?php echo date('M d, Y', strtotime($special_wb_date_to)); ?></strong> (Sunday).
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($include_special_monday_partners): ?>
                        <div class="alert-info-custom" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
                            <i class="fas fa-calendar-week"></i>
                            <strong>Monday Settlement Notice:</strong> 
                            Partner 119 settles on Mondays but captures the previous week from <strong>Saturday to Friday</strong>. 
                            Transactions captured: <strong><?php echo date('M d, Y', strtotime($special_monday_date_from)); ?></strong> 
                            (Saturday) → <strong><?php echo date('M d, Y', strtotime($special_monday_date_to)); ?></strong> (Friday).
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($include_special_m2s_partners): ?>
                        <div class="alert-info-custom" style="background: #fff3cd; border-color: #ffc107; color: #856404;">
                            <i class="fas fa-calendar-week"></i>
                            <strong>Monday Settlement Notice:</strong> 
                            Partners 1101, 1228, 1230, and 1094 settle on Mondays but capture the <strong>previous week</strong> from Monday to Sunday. 
                            Transactions captured: <strong><?php echo date('M d, Y', strtotime($special_m2s_date_from)); ?></strong> 
                            (Monday) → <strong><?php echo date('M d, Y', strtotime($special_m2s_date_to)); ?></strong> (Sunday).
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($selected_partner) && in_array($selected_partner, $weekend_partner_list, true) && isFridayToSundayRange($selected_date_from, $selected_date_to)): ?>
                        <div class="alert-info-custom" style="background: #d1ecf1; border-color: #bee5eb; color: #0c5460;">
                            <i class="fas fa-calendar-day"></i>
                            <strong>Weekend Per-Day Breakdown:</strong> 
                            Because the selected date range is <strong>Friday → Sunday</strong>, transactions for this partner are shown as a <strong>per-day breakdown</strong> instead of a lump sum.
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($is_single_day_settlement) && !empty($lookback_from) && !empty($lookback_to)): ?>
                        <div class="alert-info-custom" style="background: #e8f5e9; border-color: #81c784; color: #1b5e20;">
                            <i class="fas fa-clock"></i>
                            <strong>Late Posting Lookback Active:</strong>
                            Settlement date (<strong><?php echo date('M d, Y', strtotime($effective_date_from)); ?></strong>),
                            any <strong>unsettled</strong> transactions from the previous 7 days
                            (<strong><?php echo date('M d, Y', strtotime($lookback_from)); ?></strong>
                            → <strong><?php echo date('M d, Y', strtotime($lookback_to)); ?></strong>)
                            are automatically included.
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
                                    <th class="center" style="width: 40px;">
                                        <input type="checkbox" id="selectAllHeader" onchange="toggleAllRows(this)">
                                    </th>
                                    <th class="center" style="width: 30px;"></th>
                                    <th class="center">PARTNER</th>
                                    <th class="center">ACCOUNT NAME</th>
                                    <th class="center">ACCOUNT NUMBER</th>
                                    <th class="center">VOLUME COUNT</th>
                                    <th class="center">₱ PRINCIPAL</th>
                                    <th class="center" colspan="2">₱ CHARGE</th>
                                    <th class="center">₱ ADJUSTMENT (add/less)</th>
                                    <th class="center settlement-col">₱ AMOUNT FOR SETTLEMENT</th>
                                    <th class="center">STATUS</th>
                                    <th class="center" style="min-width: 180px;">REASON NOT SETTLED</th>
                                </tr>
                                <tr>
                                    <th colspan="7" style="font-style: italic; font-size: 12px; color: #ff0000;">Check Partners to Proceed Settlement</th>
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
                                    $is_uncategorized = isset($group_data['is_uncategorized']) && $group_data['is_uncategorized'] === true;
                                    $is_both = isset($group_data['is_both']) && $group_data['is_both'] === true;
                                ?>
                                    <!-- Group Header -->
                                    <tr class="group-header-row <?php echo $is_uncategorized ? 'uncategorized' : ''; ?> <?php echo $is_both ? 'both' : ''; ?>">
                                        <td colspan="13">
                                            <i class="fas <?php echo $group_data['icon']; ?>"></i>
                                            <?php echo htmlspecialchars($group_data['display_name']); ?>
                                            <?php if ($is_uncategorized): ?>
                                               
                                            <?php endif; ?>
                                            <?php if ($is_both): ?>
                                                <span style="font-size: 12px; font-weight: normal; margin-left: 10px; color: #0c5460;">
                                                    (Charge applies to both customers and partners)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <?php foreach ($group_data['rows'] as $row_data): 
                                        $is_settled = $row_data['is_fully_settled'];
                                        $is_partial = $row_data['is_partially_settled'];
                                        $status_class = $is_settled ? 'settled' : ($is_partial ? 'partial' : 'unsettled');
                                        $is_daily_breakdown_row = !empty($row_data['is_daily_breakdown_row']);
                                    ?>
                                        <tr class="data-row <?php echo $row_data['is_negative'] ? 'negative-row' : ''; ?> <?php echo $is_settled ? 'settled-row' : ($is_partial ? 'partial-row' : 'unsettled-row'); ?> <?php echo $is_daily_breakdown_row ? 'weekend-daily-row' : ''; ?>" 
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
                                            data-is-pcso="<?php echo $row_data['is_pcso_landbank'] ? 'true' : 'false'; ?>"
                                            data-pcso-region="<?php echo htmlspecialchars($row_data['pcso_region']); ?>"
                                            data-special-weekly="<?php echo $row_data['special_weekly'] ? 'true' : 'false'; ?>"
                                            data-special-week-before="<?php echo $row_data['special_week_before'] ? 'true' : 'false'; ?>"
                                            data-special-monday="<?php echo $row_data['special_monday'] ? 'true' : 'false'; ?>"
                                            data-special-monday-to-sunday="<?php echo $row_data['special_monday_to_sunday'] ? 'true' : 'false'; ?>"
                                            data-is-daily-breakdown-row="<?php echo $is_daily_breakdown_row ? 'true' : 'false'; ?>"
                                            data-transaction-date="<?php echo htmlspecialchars($row_data['transaction_date'] ?? ''); ?>">
                                            <td class="center checkbox-cell">
                                                <input type="checkbox" class="row-checkbox" 
                                                       data-row-index="<?php echo $row_data['row_index']; ?>"
                                                       onchange="updateTotals(); toggleReasonDropdown(this)"
                                                       <?php echo ($is_settled || $row_data['excluded']) ? 'disabled checked' : ''; ?>
                                                       <?php echo (!$cad_generated && !$is_settled) ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="center">
                                                <?php if ($row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                                    <span class="chevron-toggle" 
                                                          data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                                          data-row-index="<?php echo $row_data['row_index']; ?>"
                                                          onclick="toggleDailyBreakdown(this, <?php echo $row_data['row_index']; ?>)">
                                                        <i class="fas fa-chevron-down"></i>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="chevron-placeholder"></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="partner-name-cell">
                                                <?php echo htmlspecialchars($row_data['partner_name']); ?> <br>
                                                <?php if ($is_daily_breakdown_row && !empty($row_data['display_date'])): ?>
                                                    <span class="date-badge"><?php echo htmlspecialchars($row_data['display_date']); ?> trnx</span>
                                                <?php endif; ?>
                                                <?php if ($row_data['special_weekly']): ?>
                                                    <span style="font-size: 10px; color: #856404; margin-left: 5px;" 
                                                          title="Weekly capture: <?php echo htmlspecialchars($row_data['special_date_from']); ?> → <?php echo htmlspecialchars($row_data['special_date_to']); ?>">
                                                        <i class="fas fa-calendar-week"></i> Weekly
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($row_data['special_week_before'])): ?>
                                                    <span style="font-size: 10px; color: #856404; margin-left: 5px;" 
                                                          title="Week-before capture: <?php echo htmlspecialchars($row_data['special_wb_date_from']); ?> (Mon) → <?php echo htmlspecialchars($row_data['special_wb_date_to']); ?> (Sun)">
                                                        <i class="fas fa-calendar-week"></i> Weekly (Mon-Sun)
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($row_data['special_monday'])): ?>
                                                    <span style="font-size: 10px; color: #856404; margin-left: 5px;" 
                                                          title="Monday capture: <?php echo htmlspecialchars($row_data['special_monday_date_from']); ?> (Sat) → <?php echo htmlspecialchars($row_data['special_monday_date_to']); ?> (Fri)">
                                                        <i class="fas fa-calendar-week"></i> Weekly (Sat-Fri)
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($row_data['special_monday_to_sunday'])): ?>
                                                    <span style="font-size: 10px; color: #856404; margin-left: 5px;" 
                                                          title="Monday capture: <?php echo htmlspecialchars($row_data['special_m2s_date_from']); ?> (Mon) → <?php echo htmlspecialchars($row_data['special_m2s_date_to']); ?> (Sun)">
                                                        <i class="fas fa-calendar-week"></i> Weekly (Mon-Sun prev)
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($row_data['is_pcso_landbank']): ?>
                                                    <span style="font-size: 10px; color: #0c5460; margin-left: 5px;">
                                                        <i class="fas fa-map-marker-alt"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row_data['account_name']); ?></td>
                                            <td class="center"><?php echo htmlspecialchars($row_data['account_number']); ?></td>
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
                                                        <i class="fas fa-check-circle"></i> Settled                                                    <?php elseif ($is_partial): ?>
                                                        <i class="fas fa-clock"></i> Partial
                                                        <span style="font-size: 10px; display: block; color: #666;">
                                                            <?php echo $row_data['settled_count']; ?> settled and <?php echo $row_data['unsettled_count']; ?> unsettled
                                                        </span>
                                                    <?php else: ?>
                                                        <i class="fas fa-clock"></i> Unsettled
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="center reason-cell">
                                                <?php if ($is_settled): ?>
                                                    <span style="color: #6c757d; font-size: 12px;">N/A (Settled)</span>
                                                <?php else: ?>
                                                    <select class="reason-dropdown" 
                                                            data-row-index="<?php echo $row_data['row_index']; ?>"
                                                            data-partner-id="<?php echo $row_data['partner_id']; ?>"
                                                            <?php echo ($row_data['excluded'] || $is_settled) ? 'disabled' : ''; ?>
                                                            onchange="updateReason(this, <?php echo $row_data['row_index']; ?>)">
                                                        <option value="">Select Reason</option>
                                                        <?php foreach ($reason_options as $value => $label): ?>
                                                            <?php if ($value !== ''): ?>
                                                                <option value="<?php echo htmlspecialchars($value); ?>">
                                                                    <?php echo htmlspecialchars($label); ?>
                                                                </option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        
                                        <?php if (!$is_daily_breakdown_row && $row_data['has_daily_breakdown'] && !empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" 
                                                id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" 
                                                style="display: none;">
                                                <td colspan="13">
                                                    <?php echo $row_data['daily_html']; ?>
                                                </td>
                                            </tr>
                                        <?php elseif (!$is_daily_breakdown_row && $row_data['has_daily_breakdown'] && empty($row_data['daily_html'])): ?>
                                            <tr class="daily-breakdown-container" 
                                                id="dailyBreakdown_<?php echo $row_data['row_index']; ?>" 
                                                style="display: none;">
                                                <td colspan="13" class="daily-breakdown-error">
                                                    <i class="fas fa-info-circle"></i>
                                                    No daily transactions found for this date range.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    
                                    <tr class="group-subtotal-row <?php echo $is_uncategorized ? 'uncategorized-subtotal' : ''; ?> <?php echo $is_both ? 'both-subtotal' : ''; ?>" data-group="<?php echo $group_key; ?>">
                                        <td colspan="5" style="text-align: right;">
                                            <strong>Subtotal - <?php echo htmlspecialchars($group_data['display_name']); ?></strong>
                                        </td>
                                        <td class="center group-txn-count"><?php echo number_format($group_data['totals']['txn_count']); ?></td>
                                        <td class="right group-principal"> <?php echo number_format($group_data['totals']['principal'], 2); ?></td>
                                        <td class="right group-charge-to-customer"> <?php echo number_format($group_data['totals']['charge_to_customer'], 2); ?></td>
                                        <td class="right group-charge-to-partner"> <?php echo number_format($group_data['totals']['charge_to_partner'], 2); ?></td>
                                        <td class="right group-adjustment <?php echo $group_data['totals']['adjustment'] < 0 ? 'negative-amount' : ($group_data['totals']['adjustment'] > 0 ? 'positive-amount' : ''); ?>">
                                            <?php if ($group_data['totals']['adjustment'] != 0): ?>
                                                <?php echo ($group_data['totals']['adjustment'] >= 0 ? '+' : ''); ?> <?php echo number_format($group_data['totals']['adjustment'], 2); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="right settlement-col group-settlement <?php echo $group_data['totals']['settlement'] < 0 ? 'negative-amount' : ''; ?>">
                                             <?php echo number_format($group_data['totals']['settlement'], 2); ?>
                                        </td>
                                        <td class="center group-status">
                                            <?php 
                                            $g_settled = $group_data['totals']['settled_count'] ?? 0;
                                            $g_unsettled = $group_data['totals']['unsettled_count'] ?? 0;
                                            if ($g_settled > 0 && $g_unsettled == 0): ?>
                                                <span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>
                                            <?php elseif ($g_settled > 0 && $g_unsettled > 0): ?>
                                                <span style="color: #856404;"><i class="fas fa-clock"></i> <?php echo number_format($g_settled); ?> Settled / <?php echo number_format($g_unsettled); ?> Unsettled</span>
                                            <?php else: ?>
                                                <span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="center group-reason"></td>
                                    </tr>
                                    
                                    <?php if (!$is_last_group): ?>
                                        <tr style="height: 8px; background: transparent;">
                                            <td colspan="13" style="border: none; padding: 0;"></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <tr class="grand-total-row">
                                    <td colspan="5" style="text-align: right;">₱ GRAND TOTAL</td>
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
                                            <span style="color: #856404;"><i class="fas fa-clock"></i> <?php echo number_format($g_settled); ?> Settled / <?php echo number_format($g_unsettled); ?> Unsettled</span>
                                        <?php else: ?>
                                            <span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center grand-reason"></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="export-buttons">
                            <button class="btn-export excel" onclick="exportToExcel()">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button class="btn-export pdf" onclick="exportToPDF()">
                                <i class="fa-solid fa-file-pdf"></i> Export PDF
                            </button>
                            <button class="btn-export save-reasons" onclick="saveReasons()" style="margin-left: 10px;">
                                <i class="fas fa-save"></i> Save Reasons
                            </button>
                            <button class="btn-export settle <?php echo !$cad_generated ? 'btn-disabled' : ''; ?>" 
                                    onclick="settleSelected()" 
                                    <?php echo !$cad_generated ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?> 
                                    style="margin-left: 10px;">
                                <i class="fas fa-check-circle"></i> Settle
                            </button>
                        </div>
                    </div>
                    
                <?php
                } else {
                    echo '<div class="no-records">';
                    echo '<i class="fas fa-inbox"></i>';
                    echo '<p>No records found matching your filters.</p>';
                    
                    if ($selected_is_special && !$include_special_partners) {
                        echo '<p style="font-size: 14px; margin-top: 15px; color: #856404; background: #fff3cd; padding: 15px; border-radius: 6px;">';
                        echo '<i class="fas fa-calendar-week"></i> <strong>Weekly Settlement Notice:</strong><br>';
                        echo 'Partner(s) 457, 458, 459 (CHINABANK/CHECK) and 460 (BDO/ONLINE) only settle on <strong>Tuesdays</strong>.<br>';
                        echo 'The selected "Transaction Date To" (<strong>' . htmlspecialchars($selected_date_to) . '</strong>) is not a Tuesday.<br>';
                        echo 'Please select a Tuesday as the end date to view these partners.';
                        echo '</p>';
                    } elseif ($selected_is_special_wb && !$include_special_wb_partners) {
                        echo '<p style="font-size: 14px; margin-top: 15px; color: #856404; background: #fff3cd; padding: 15px; border-radius: 6px;">';
                        echo '<i class="fas fa-calendar-week"></i> <strong>Week-Before Settlement Notice:</strong><br>';
                        echo 'Partner 1005 (BDO/ONLINE) only settles on <strong>Tuesdays</strong>.<br>';
                        echo 'The selected "Transaction Date To" (<strong>' . htmlspecialchars($selected_date_to) . '</strong>) is not a Tuesday.<br>';
                        echo 'Please select a Tuesday as the end date to view this partner.';
                        echo '</p>';
                    } elseif ($selected_is_special_monday && !$include_special_monday_partners) {
                        echo '<p style="font-size: 14px; margin-top: 15px; color: #856404; background: #fff3cd; padding: 15px; border-radius: 6px;">';
                        echo '<i class="fas fa-calendar-week"></i> <strong>Monday Settlement Notice:</strong><br>';
                        echo 'Partner 119 only settles on <strong>Mondays</strong>.<br>';
                        echo 'The selected "Transaction Date To" (<strong>' . htmlspecialchars($selected_date_to) . '</strong>) is not a Monday.<br>';
                        echo 'Please select a Monday as the end date to view this partner.';
                        echo '</p>';
                    } elseif ($selected_is_special_m2s && !$include_special_m2s_partners) {
                        echo '<p style="font-size: 14px; margin-top: 15px; color: #856404; background: #fff3cd; padding: 15px; border-radius: 6px;">';
                        echo '<i class="fas fa-calendar-week"></i> <strong>Monday Settlement Notice:</strong><br>';
                        echo 'Partners 1101, 1228, 1230, and 1094 only settle on <strong>Mondays</strong>.<br>';
                        echo 'The selected "Transaction Date To" (<strong>' . htmlspecialchars($selected_date_to) . '</strong>) is not a Monday.<br>';
                        echo 'Please select a Monday as the end date to view these partners.';
                        echo '</p>';
                    } else {
                        echo '<p style="font-size: 14px; margin-top: 5px;">Please try adjusting your filter criteria.</p>';
                    }
                    
                    echo '</div>';
                }
                
            } catch (Exception $e) {
                error_log("Error fetching settlement data: " . $e->getMessage());
                echo '<div class="no-records" style="border: 1px solid #dc3545; color: #dc3545;">';
                echo '<i class="fas fa-exclamation-triangle"></i>';
                echo '<p>Error fetching data. Please try again.</p>';
                echo '<p style="font-size: 14px; margin-top: 5px;">' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
        } else {
            echo '<div class="no-records">';
            echo '<i class="fas fa-filter"></i>';
            echo '<p>Please select filters and click Filter to view settlement data.</p>';
            echo '<p style="font-size: 14px; margin-top: 5px;">Use the filters above to search for specific settlement records.</p>';
            echo '</div>';
        }
        ?>
        </div>

    </div>
    <?php include '../../../templates/footer.php'; ?>

<script>
    // Global variables
    var loadingTimer = null;
    var seconds = 0;
    var isPageLoaded = false;
    var reasonData = {};
    
    $(document).ready(function() {
        var initialPartner = $('#partner').data('selected') || '';
        var initialBank = $('#bank').data('selected') || '';
        var initialSettlement = $('#settlement_type').data('selected') || '';
        
        $('.select2-dropdown').select2({
            placeholder: function() {
                return $(this).data('placeholder') || 'Select an option';
            },
            allowClear: true,
            width: '100%'
        });
        
        // Auto-populate bank when partner is selected
        $('#partner').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var bank = selectedOption.data('bank') || '';
            if (bank) {
                $('#bank').val(bank).trigger('change');
            } else {
                $('#bank').val('').trigger('change');
            }
        });
        
        if (initialPartner) {
            $('#partner').val(initialPartner).trigger('change');
        }
        if (initialBank) {
            $('#bank').val(initialBank).trigger('change');
        }
        if (initialSettlement) {
            $('#settlement_type').val(initialSettlement).trigger('change');
        }

        startLoadingTimer();

        $(window).on('load', function() {
            isPageLoaded = true;
            setTimeout(function() {
                hideLoadingModal();
                showMainContent();
            }, 500);
        });

        setTimeout(function() {
            if (!isPageLoaded) {
                var hasContent = $('#resultsContainer').children().length > 0;
                if (hasContent) {
                    hideLoadingModal();
                    showMainContent();
                }
            }
        }, 10000);

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            var hasFilters = false;
            $(this).find('select, input[type="date"], input[name="rfp_no"]').each(function() {
                var val = $(this).val();
                if (val && val.trim() !== '' && val !== '0') {
                    hasFilters = true;
                    return false;
                }
            });
            
            if (!hasFilters) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Filters Selected',
                    text: 'Please select at least one filter to search.',
                    confirmButtonColor: '#007bff'
                });
                return;
            }
            
            showLoadingModal();
            hideMainContent();
            resetAndStartTimer();
            
            var formData = $(this).serialize();
            
            $.ajax({
                url: window.location.href,
                type: 'GET',
                data: formData,
                dataType: 'html',
                cache: false,
                timeout: 120000,
                success: function(response) {
                    var tempDiv = $('<div>').html(response);
                    var newContent = tempDiv.find('#resultsContainer').html();
                    if (newContent) {
                        $('#resultsContainer').html(newContent);
                    } else {
                        var content = response;
                        var start = content.indexOf('<div id="resultsContainer">');
                        if (start !== -1) {
                            var end = content.indexOf('</div>', start);
                            if (end !== -1) {
                                $('#resultsContainer').html(content.substring(start, end + 6));
                            }
                        }
                    }
                    
                    setTimeout(function() {
                        hideLoadingModal();
                        showMainContent();
                    }, 300);
                },
                error: function(xhr, status, error) {
                    hideLoadingModal();
                    showMainContent();
                    var errorMsg = 'Failed to load data. Please try again.';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. The query may be taking too long. Please try with fewer filters.';
                    }
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMsg,
                        confirmButtonColor: '#dc3545'
                    });
                    console.error('AJAX Error:', status, error);
                }
            });
        });
    });

    function showLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    function hideLoadingModal() {
        var modal = document.getElementById('loadingModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.style.overflow = '';
            if (loadingTimer) {
                clearInterval(loadingTimer);
                loadingTimer = null;
            }
        }
    }

    function showMainContent() {
        var mainContent = document.getElementById('mainContent');
        if (mainContent) {
            mainContent.classList.remove('main-content-hidden');
            mainContent.classList.add('main-content-visible');
        }
    }

    function hideMainContent() {
        var mainContent = document.getElementById('mainContent');
        if (mainContent) {
            mainContent.classList.remove('main-content-visible');
            mainContent.classList.add('main-content-hidden');
        }
    }

    function startLoadingTimer() {
        if (loadingTimer) {
            clearInterval(loadingTimer);
            loadingTimer = null;
        }
        seconds = 0;
        var elapsedElement = document.getElementById('elapsedTime');
        if (elapsedElement) {
            elapsedElement.textContent = '0';
        }
        loadingTimer = setInterval(function() {
            seconds++;
            var elapsedElement = document.getElementById('elapsedTime');
            if (elapsedElement) {
                elapsedElement.textContent = seconds;
            }
        }, 1000);
    }

    function resetAndStartTimer() {
        if (loadingTimer) {
            clearInterval(loadingTimer);
            loadingTimer = null;
        }
        seconds = 0;
        var elapsedElement = document.getElementById('elapsedTime');
        if (elapsedElement) {
            elapsedElement.textContent = '0';
        }
        loadingTimer = setInterval(function() {
            seconds++;
            var elapsedElement = document.getElementById('elapsedTime');
            if (elapsedElement) {
                elapsedElement.textContent = seconds;
            }
        }, 1000);
    }

    function toggleDailyBreakdown(element, rowIndex) {
        var breakdownRow = document.getElementById('dailyBreakdown_' + rowIndex);
        var chevron = element;
        
        if (!breakdownRow) {
            return;
        }
        
        if (breakdownRow.style.display !== 'none') {
            breakdownRow.style.display = 'none';
            chevron.classList.remove('expanded');
            chevron.innerHTML = '<i class="fas fa-chevron-down"></i>';
            return;
        }
        
        breakdownRow.style.display = 'table-row';
        chevron.classList.add('expanded');
        chevron.innerHTML = '<i class="fas fa-chevron-up"></i>';
    }

    // ============================================
    // REASON NOT SETTLED FUNCTIONS
    // ============================================
    
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
            if (rowIndex !== undefined) {
                delete reasonData[rowIndex];
            }
        } else if (!isDisabled) {
            reasonSelect.prop('disabled', false);
            reasonSelect.css('opacity', '1');
        }
    }

    function updateReason(element, rowIndex) {
        var reason = $(element).val();
        var partnerId = $(element).data('partner-id');
        
        if (reason) {
            reasonData[rowIndex] = {
                reason: reason,
                partner_id: partnerId
            };
        } else {
            delete reasonData[rowIndex];
        }
    }

    function toggleAllRows(checkbox) {
        var isChecked = $(checkbox).prop('checked');
        var cadGenerated = $('#cadGenerated').val() === 'true';
        
        $('.row-checkbox:not(:disabled)').each(function() {
            var row = $(this).closest('.data-row');
            var isSettled = row.data('is-settled') === true;
            
            if (!cadGenerated && !isSettled) {
                return;
            }
            
            $(this).prop('checked', isChecked);
            toggleReasonDropdown(this);
        });
        updateTotals();
    }
    
    function updateTotals() {
        var dataRows = $('.data-row');
        var groupTotals = {};
        var grandTotals = {
            txn_count: 0,
            principal: 0,
            charge_to_customer: 0,
            charge_to_partner: 0,
            adjustment: 0,
            settlement: 0,
            settled_count: 0,
            unsettled_count: 0
        };
        
        dataRows.each(function() {
            var row = $(this);
            var checkbox = row.find('.row-checkbox');
            var isChecked = checkbox.prop('checked') && !checkbox.prop('disabled');
            var isSettled = row.data('is-settled') === true;
            
            var txnCount = parseInt(row.data('txn-count')) || 0;
            var principal = parseFloat(row.data('principal')) || 0;
            var chargeToCustomer = parseFloat(row.data('charge-to-customer')) || 0;
            var chargeToPartner = parseFloat(row.data('charge-to-partner')) || 0;
            var adjustment = parseFloat(row.data('adjustment')) || 0;
            var settlement = parseFloat(row.data('settlement')) || 0;
            
            var groupRow = row.prevAll('.group-header-row').first();
            var groupKey = groupRow.find('td').text().trim();
            
            if (!groupTotals[groupKey]) {
                groupTotals[groupKey] = {
                    txn_count: 0,
                    principal: 0,
                    charge_to_customer: 0,
                    charge_to_partner: 0,
                    adjustment: 0,
                    settlement: 0,
                    settled_count: 0,
                    unsettled_count: 0
                };
            }
            
            if (isSettled) {
                groupTotals[groupKey].settled_count += txnCount;
            } else {
                groupTotals[groupKey].unsettled_count += txnCount;
            }
            
            if (isChecked) {
                groupTotals[groupKey].txn_count += txnCount;
                groupTotals[groupKey].principal += principal;
                groupTotals[groupKey].charge_to_customer += chargeToCustomer;
                groupTotals[groupKey].charge_to_partner += chargeToPartner;
                groupTotals[groupKey].adjustment += adjustment;
                groupTotals[groupKey].settlement += settlement;
                
                grandTotals.txn_count += txnCount;
                grandTotals.principal += principal;
                grandTotals.charge_to_customer += chargeToCustomer;
                grandTotals.charge_to_partner += chargeToPartner;
                grandTotals.adjustment += adjustment;
                grandTotals.settlement += settlement;
            }
            
            if (isSettled) {
                grandTotals.settled_count += txnCount;
            } else {
                grandTotals.unsettled_count += txnCount;
            }
        });
        
        $('.group-subtotal-row').each(function() {
            var groupRow = $(this);
            var displayText = groupRow.find('td').first().text().trim();
            var matchedKey = null;
            for (var key in groupTotals) {
                if (displayText.indexOf(key) !== -1 || key.indexOf(displayText) !== -1) {
                    matchedKey = key;
                    break;
                }
            }
            
            if (matchedKey && groupTotals[matchedKey]) {
                var totals = groupTotals[matchedKey];
                groupRow.find('.group-txn-count').text(formatNumberInt(totals.txn_count));
                groupRow.find('.group-principal').text(' ' + formatNumberDecimal(totals.principal));
                groupRow.find('.group-charge-to-customer').text(' ' + formatNumberDecimal(totals.charge_to_customer));
                groupRow.find('.group-charge-to-partner').text(' ' + formatNumberDecimal(totals.charge_to_partner));
                
                if (totals.adjustment != 0) {
                    var adjText = (totals.adjustment >= 0 ? '+' : '') + ' ' + formatNumberDecimal(totals.adjustment);
                    groupRow.find('.group-adjustment').text(adjText);
                    groupRow.find('.group-adjustment').removeClass('negative-amount positive-amount');
                    if (totals.adjustment < 0) {
                        groupRow.find('.group-adjustment').addClass('negative-amount');
                    } else if (totals.adjustment > 0) {
                        groupRow.find('.group-adjustment').addClass('positive-amount');
                    }
                } else {
                    groupRow.find('.group-adjustment').text('');
                    groupRow.find('.group-adjustment').removeClass('negative-amount positive-amount');
                }
                
                groupRow.find('.group-settlement').text(' ' + formatNumberDecimal(totals.settlement));
                groupRow.find('.group-settlement').removeClass('negative-amount');
                if (totals.settlement < 0) {
                    groupRow.find('.group-settlement').addClass('negative-amount');
                }
                
                var gSettled = totals.settled_count || 0;
                var gUnsettled = totals.unsettled_count || 0;
                var statusHtml = '';
                if (gSettled > 0 && gUnsettled == 0) {
                    statusHtml = '<span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>';
                } else if (gSettled > 0 && gUnsettled > 0) {
                    statusHtml = '<span style="color: #856404;"><i class="fas fa-clock"></i> ' + formatNumberInt(gSettled) + ' Settled / ' + formatNumberInt(gUnsettled) + ' Unsettled</span>';
                } else {
                    statusHtml = '<span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>';
                }
                groupRow.find('.group-status').html(statusHtml);
            }
        });
        
        $('.grand-total-row').find('.grand-txn-count').text(formatNumberInt(grandTotals.txn_count));
        $('.grand-total-row').find('.grand-principal').text(' ' + formatNumberDecimal(grandTotals.principal));
        $('.grand-total-row').find('.grand-charge-to-customer').text(' ' + formatNumberDecimal(grandTotals.charge_to_customer));
        $('.grand-total-row').find('.grand-charge-to-partner').text(' ' + formatNumberDecimal(grandTotals.charge_to_partner));
        
        if (grandTotals.adjustment != 0) {
            var grandAdjText = (grandTotals.adjustment >= 0 ? '+' : '') + ' ' + formatNumberDecimal(grandTotals.adjustment);
            $('.grand-total-row').find('.grand-adjustment').text(grandAdjText);
            $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
            if (grandTotals.adjustment < 0) {
                $('.grand-total-row').find('.grand-adjustment').addClass('negative-amount');
            } else if (grandTotals.adjustment > 0) {
                $('.grand-total-row').find('.grand-adjustment').addClass('positive-amount');
            }
        } else {
            $('.grand-total-row').find('.grand-adjustment').text('');
            $('.grand-total-row').find('.grand-adjustment').removeClass('negative-amount positive-amount');
        }
        
        $('.grand-total-row').find('.grand-settlement').text(' ' + formatNumberDecimal(grandTotals.settlement));
        $('.grand-total-row').find('.grand-settlement').removeClass('negative-amount');
        if (grandTotals.settlement < 0) {
            $('.grand-total-row').find('.grand-settlement').addClass('negative-amount');
        }
        
        var gSettled = grandTotals.settled_count || 0;
        var gUnsettled = grandTotals.unsettled_count || 0;
        var statusHtml = '';
        if (gSettled > 0 && gUnsettled == 0) {
            statusHtml = '<span class="settled-status"><i class="fas fa-check-circle"></i> All Settled</span>';
        } else if (gSettled > 0 && gUnsettled > 0) {
            statusHtml = '<span style="color: #856404;"><i class="fas fa-clock"></i> ' + formatNumberInt(gSettled) + ' Settled / ' + formatNumberInt(gUnsettled) + ' Unsettled</span>';
        } else {
            statusHtml = '<span class="unsettled-status"><i class="fas fa-clock"></i> All Unsettled</span>';
        }
        $('.grand-total-row').find('.grand-status').html(statusHtml);
        
        var totalCheckboxes = $('.row-checkbox:not(:disabled)').length;
        var checkedCheckboxes = $('.row-checkbox:not(:disabled):checked').length;
        var selectAllHeader = $('#selectAllHeader');
        var selectAllRows = $('#selectAllRows');
        
        if (totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes) {
            selectAllHeader.prop('checked', true);
            selectAllRows.prop('checked', true);
            selectAllHeader.prop('indeterminate', false);
            selectAllRows.prop('indeterminate', false);
        } else if (checkedCheckboxes === 0) {
            selectAllHeader.prop('checked', false);
            selectAllRows.prop('checked', false);
            selectAllHeader.prop('indeterminate', false);
            selectAllRows.prop('indeterminate', false);
        } else {
            selectAllHeader.prop('checked', false);
            selectAllRows.prop('checked', false);
            selectAllHeader.prop('indeterminate', true);
            selectAllRows.prop('indeterminate', true);
        }
    }
    
    function recalculateTotals() {
        updateTotals();
        Swal.fire({
            icon: 'success',
            title: 'Totals Updated',
            text: 'Settlement totals have been recalculated based on selected rows.',
            timer: 1500,
            showConfirmButton: false
        });
    }
    
    function formatNumberInt(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(value);
    }
    
    function formatNumberDecimal(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }

    // ============================================
    // SAVE REASONS FUNCTION
    // ============================================
    function saveReasons() {
        var hasReasons = false;
        for (var key in reasonData) {
            if (reasonData.hasOwnProperty(key) && reasonData[key].reason) {
                hasReasons = true;
                break;
            }
        }
        
        if (!hasReasons) {
            Swal.fire({
                icon: 'info',
                title: 'No Reasons to Save',
                text: 'Please select reasons for unsettled transactions before saving.',
                confirmButtonColor: '#17a2b8'
            });
            return;
        }
        
        var partner = $('#partner').val() || '';
        var bank = $('#bank').val() || '';
        var settlementType = $('#settlement_type').val() || '';
        var dateFrom = $('#date_from').val() || '';
        var dateTo = $('#date_to').val() || '';
        var rfpNo = $('#rfp_no').val().trim() || '';
        
        Swal.fire({
            title: 'Save Reasons?',
            html: `
                <div style="text-align: left;">
                    <p>You are about to save reasons for <strong>${Object.keys(reasonData).length}</strong> partner(s).</p>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px; max-height: 200px; overflow-y: auto;">
                        ${Object.values(reasonData).map(r => 
                            `<p style="margin: 5px 0;"><strong>Partner ID ${r.partner_id}:</strong> ${r.reason}</p>`
                        ).join('')}
                    </div>
                    <p style="color: #0c5460; margin-top: 10px;">
                        <i class="fas fa-info-circle"></i> 
                        This will only update the "Reason Not Settled" field. Settlement status will NOT be changed.
                    </p>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#17a2b8',
            cancelButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Save Reasons',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Saving Reasons',
                        html: 'Please wait while we save the reasons...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    var settledBy = '<?php echo addslashes($display_name); ?>';
                    var reasonDataStr = JSON.stringify(reasonData);
                    
                    $.ajax({
                        url: 'save_reasons.php',
                        type: 'POST',
                        data: {
                            reason_data: reasonDataStr,
                            saved_by: settledBy,
                            partner_filter: partner,
                            bank_filter: bank,
                            settlement_type_filter: settlementType,
                            date_from: dateFrom,
                            date_to: dateTo,
                            rfp_no: rfpNo
                        },
                        dataType: 'json',
                        timeout: 60000,
                        success: function(response) {
                            if (response.success) {
                                resolve({ success: true, message: response.message, data: response.data });
                            } else {
                                resolve({ success: false, message: response.message || 'Failed to save reasons.' });
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = 'An error occurred while saving reasons.';
                            if (status === 'timeout') {
                                errorMsg = 'Request timed out. Please try again.';
                            }
                            resolve({ success: false, message: errorMsg });
                        }
                    });
                });
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                if (result.value.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Reasons Saved Successfully',
                        html: `
                            <p>${result.value.message}</p>
                            ${result.value.data ? `
                                <div style="text-align: left; margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 4px;">
                                    <strong>Summary:</strong>
                                    <ul style="margin-top: 10px; padding-left: 20px;">
                                        <li>Reasons updated for: ${result.value.data.updated_count} transaction(s)</li>
                                        <li>Saved by: ${result.value.data.saved_by}</li>
                                        <li>Date: ${result.value.data.save_date}</li>
                                    </ul>
                                </div>
                            ` : ''}
                        `,
                        confirmButtonColor: '#17a2b8'
                    });
                    
                    reasonData = {};
                    $('.reason-dropdown').prop('disabled', true).css('opacity', '0.5');
                    
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Save Reasons',
                        text: result.value.message,
                        confirmButtonColor: '#dc3545'
                    });
                }
            }
        });
    }

    function exportToExcel() {
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) {
            Swal.fire({
                icon: 'warning',
                title: 'RFP No. Required',
                text: 'Please enter an RFP No. before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var hasUnsettled = false;
        $('.data-row').each(function() {
            var isSettled = $(this).data('is-settled') === true;
            if (!isSettled) {
                hasUnsettled = true;
                return false;
            }
        });
        
        if (hasUnsettled) {
            Swal.fire({
                icon: 'warning',
                title: 'Unsettled Transactions',
                text: 'Cannot export. Please settle all transactions before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        showLoadingModal();
        setTimeout(function() {
            var table = document.getElementById('settlementTable');
            if (!table) {
                hideLoadingModal();
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data',
                    text: 'No data available to export.',
                    confirmButtonColor: '#ffc107'
                });
                return;
            }
            
            var partner = $('#partner').val() || '';
            var bank = $('#bank').val() || '';
            var settlementType = $('#settlement_type').val() || '';
            var dateFrom = $('#date_from').val() || '';
            var dateTo = $('#date_to').val() || '';
            
            var excludedRows = [];
            $('.data-row').each(function() {
                var checkbox = $(this).find('.row-checkbox');
                if (!checkbox.prop('checked')) {
                    var rowIndex = $(this).data('row-index');
                    if (rowIndex !== undefined) {
                        excludedRows.push(rowIndex);
                    }
                }
            });
            
            var exportUrl = 'export_bank_settlement.php?';
            exportUrl += 'partner=' + encodeURIComponent(partner);
            exportUrl += '&bank=' + encodeURIComponent(bank);
            exportUrl += '&settlement_type=' + encodeURIComponent(settlementType);
            exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            exportUrl += '&rfp_no=' + encodeURIComponent(rfpNo);
            
            if (excludedRows.length > 0) {
                exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            }
            
            var reasonDataStr = JSON.stringify(reasonData);
            exportUrl += '&reason_data=' + encodeURIComponent(reasonDataStr);
            
            window.open(exportUrl, '_blank');
            
            setTimeout(function() {
                hideLoadingModal();
            }, 500);
        }, 300);
    }

    function exportToPDF() {
        var rfpNo = $('#rfp_no').val().trim();
        if (!rfpNo) {
            Swal.fire({
                icon: 'warning',
                title: 'RFP No. Required',
                text: 'Please enter an RFP No. before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var hasUnsettled = false;
        $('.data-row').each(function() {
            var isSettled = $(this).data('is-settled') === true;
            if (!isSettled) {
                hasUnsettled = true;
                return false;
            }
        });
        
        if (hasUnsettled) {
            Swal.fire({
                icon: 'warning',
                title: 'Unsettled Transactions',
                text: 'Cannot export. Please settle all transactions before exporting.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        showLoadingModal();
        setTimeout(function() {
            var table = document.getElementById('settlementTable');
            if (!table) {
                hideLoadingModal();
                Swal.fire({
                    icon: 'warning',
                    title: 'No Data',
                    text: 'No data available to export.',
                    confirmButtonColor: '#ffc107'
                });
                return;
            }
            
            var partner = $('#partner').val() || '';
            var bank = $('#bank').val() || '';
            var settlementType = $('#settlement_type').val() || '';
            var dateFrom = $('#date_from').val() || '';
            var dateTo = $('#date_to').val() || '';
            
            var excludedRows = [];
            $('.data-row').each(function() {
                var checkbox = $(this).find('.row-checkbox');
                if (!checkbox.prop('checked')) {
                    var rowIndex = $(this).data('row-index');
                    if (rowIndex !== undefined) {
                        excludedRows.push(rowIndex);
                    }
                }
            });
            
            var exportUrl = 'export_bank_settlement_pdf.php?';
            exportUrl += 'partner=' + encodeURIComponent(partner);
            exportUrl += '&bank=' + encodeURIComponent(bank);
            exportUrl += '&settlement_type=' + encodeURIComponent(settlementType);
            exportUrl += '&date_from=' + encodeURIComponent(dateFrom);
            exportUrl += '&date_to=' + encodeURIComponent(dateTo);
            exportUrl += '&rfp_no=' + encodeURIComponent(rfpNo);
            
            if (excludedRows.length > 0) {
                exportUrl += '&excluded_rows=' + encodeURIComponent(excludedRows.join(','));
            }
            
            var reasonDataStr = JSON.stringify(reasonData);
            exportUrl += '&reason_data=' + encodeURIComponent(reasonDataStr);
            
            window.open(exportUrl, '_blank');
            
            setTimeout(function() {
                hideLoadingModal();
            }, 500);
        }, 300);
    }

    // ============================================
    // SETTLE FUNCTION - UPDATED (No page reload)
    // ============================================
    function settleSelected() {
        var cadGenerated = $('#cadGenerated').val() === 'true';
        if (!cadGenerated) {
            Swal.fire({
                icon: 'error',
                title: 'Cannot Settle',
                text: 'The selected partner does not have a valid bank abbreviation configured. Please fix the partner\'s bank configuration first.',
                confirmButtonColor: '#dc3545'
            });
            return;
        }
        
        var rfpNo = $('#rfp_no').val().trim();
        
        if (!rfpNo) {
            Swal.fire({
                icon: 'warning',
                title: 'RFP No. Required',
                text: 'Please enter an RFP No.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var cadNo = $('#currentCadNo').val() || '';
        
        if (!cadNo) {
            var badgeText = $('#currentCadBadge').text() || $('.table-badge:contains("CAD No.")').first().text() || '';
            var match = badgeText.match(/CAD No\.:\s*([A-Z0-9\-]+)/i);
            if (match) {
                cadNo = match[1].trim();
            }
        }
        
        if (!cadNo) {
            Swal.fire({
                icon: 'warning',
                title: 'CAD No. Missing',
                text: 'CAD No. is not available. Please refresh the page and try again.',
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var checkedRows = [];
        var totalVolume = 0;
        var totalSettlement = 0;
        var skippedSettled = 0;
        var unsettledReasons = [];
        
        $('.data-row').each(function() {
            var checkbox = $(this).find('.row-checkbox');
            var isSettled = $(this).data('is-settled') === true;
            
            if (checkbox.prop('checked') && !checkbox.prop('disabled')) {
                if (isSettled) {
                    skippedSettled++;
                    return;
                }
                
                var partnerId = $(this).data('partner-id');
                var partnerName = $(this).data('partner-name') || $(this).find('.partner-name-cell').text().trim();
                var txnCount = parseInt($(this).data('txn-count')) || 0;
                var settlement = parseFloat($(this).data('settlement')) || 0;
                var rowIndex = $(this).data('row-index');
                
                checkedRows.push({
                    partner_id: partnerId,
                    partner_name: partnerName,
                    txn_count: txnCount,
                    settlement_amount: settlement,
                    row_index: rowIndex
                });
                totalVolume += txnCount;
                totalSettlement += settlement;
            }
        });
        
        $('.data-row').each(function() {
            var checkbox = $(this).find('.row-checkbox');
            var isSettled = $(this).data('is-settled') === true;
            var rowIndex = $(this).data('row-index');
            
            if (!checkbox.prop('checked') && !isSettled && rowIndex !== undefined) {
                var reason = reasonData[rowIndex];
                if (reason && reason.reason) {
                    unsettledReasons.push({
                        partner_id: reason.partner_id,
                        reason: reason.reason
                    });
                }
            }
        });
        
        if (checkedRows.length === 0) {
            var msg = 'No unsettled rows selected.';
            if (skippedSettled > 0) {
                msg += ' ' + skippedSettled + ' selected row(s) are already settled and cannot be processed again.';
            }
            Swal.fire({
                icon: 'warning',
                title: 'No Rows to Settle',
                text: msg,
                confirmButtonColor: '#ffc107'
            });
            return;
        }
        
        var skipMsg = skippedSettled > 0 ? `<p style="color: #856404; margin-top: 10px;"><i class="fas fa-info-circle"></i> ${skippedSettled} already settled row(s) were skipped.</p>` : '';
        
        var reasonMsg = '';
        if (unsettledReasons.length > 0) {
            reasonMsg = `
                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <p><strong>Reasons for not settling:</strong></p>
                    <ul style="margin: 5px 0; padding-left: 20px;">
                        ${unsettledReasons.map(r => `<li>${r.partner_id}: ${r.reason}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        Swal.fire({
            title: 'Settle Selected Transactions?',
            html: `
                <div style="text-align: left; max-height: 300px; overflow-y: auto;">
                    <p><strong>RFP No.:</strong> ${rfpNo}</p>
                    <p><strong>CAD No.:</strong> ${cadNo}</p>
                    <p><strong>You are about to settle:</strong></p>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        ${checkedRows.map(row => 
                            `<li><strong>${row.partner_name}</strong> - ${row.txn_count.toLocaleString()} transactions ( ${row.settlement_amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})})</li>`
                        ).join('')}
                    </ul>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <p><strong>Total Partners:</strong> ${checkedRows.length}</p>
                        <p><strong>Total Volume Count:</strong> ${totalVolume.toLocaleString()}</p>
                        <p><strong>Total Settlement Amount:</strong>  ${totalSettlement.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                    </div>
                    ${reasonMsg}
                    <p style="color: #856404; margin-top: 10px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        This will mark <strong>ALL</strong> unsettled transactions for the selected partners as <strong>SETTLED</strong>.
                    </p>
                    <p style="color: #0c5460; margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> 
                        Settled By: <strong><?php echo $display_name; ?></strong>
                    </p>
                    ${skipMsg}
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Settle Selected',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Processing Settlement',
                        html: 'Please wait while we process the settlement...<br><small>This may take a few moments.</small>',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    var settledBy = '<?php echo addslashes($display_name); ?>';
                    var partnerIds = checkedRows.map(row => row.partner_id);
                    
                    var partner = $('#partner').val() || '';
                    var bank = $('#bank').val() || '';
                    var settlementType = $('#settlement_type').val() || '';
                    var dateFrom = $('#date_from').val() || '';
                    var dateTo = $('#date_to').val() || '';
                    
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
                            reason_data: reasonDataStr
                        },
                        dataType: 'json',
                        timeout: 120000,
                        success: function(response) {
                            if (response.success) {
                                resolve({ success: true, message: response.message, data: response.data, partnerIds: partnerIds });
                            } else {
                                resolve({ success: false, message: response.message || 'Settlement failed.' });
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = 'An error occurred while processing settlement.';
                            if (status === 'timeout') {
                                errorMsg = 'Request timed out. The settlement may still be processing. Please check the status.';
                            }
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
                        var partnerId = $(this).data('partner-id');
                        if (settledPartnerIds.includes(partnerId)) {
                            $(this).data('is-settled', true);
                            
                            var checkbox = $(this).find('.row-checkbox');
                            checkbox.prop('disabled', true);
                            checkbox.prop('checked', true);
                            
                            var statusCell = $(this).find('.status-badge');
                            statusCell.removeClass('unsettled partial');
                            statusCell.addClass('settled');
                            statusCell.html('<i class="fas fa-check-circle"></i> Settled');
                            
                            var reasonSelect = $(this).find('.reason-dropdown');
                            reasonSelect.prop('disabled', true);
                            reasonSelect.val('');
                            reasonSelect.css('opacity', '0.5');
                            
                            $(this).removeClass('unsettled-row partial-row');
                            $(this).addClass('settled-row');
                            
                            var rowIndex = $(this).data('row-index');
                            if (rowIndex !== undefined) {
                                delete reasonData[rowIndex];
                            }
                        }
                    });
                    
                    updateTotals();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Settlement Successful',
                        html: `
                            <p>${result.value.message}</p>
                            ${result.value.data ? `
                                <div style="text-align: left; margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 4px;">
                                    <strong>Settlement Details:</strong>
                                    <ul style="margin-top: 10px; padding-left: 20px;">
                                        <li>RFP No.: ${rfpNo}</li>
                                        <li>CAD No.: ${cadNo}</li>
                                        <li>Total Partners: ${result.value.data.total_partners}</li>
                                        <li>Total Transactions: ${result.value.data.total_transactions.toLocaleString()}</li>
                                        <li>Total Amount:  ${result.value.data.total_amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</li>
                                        <li>Settled By: ${result.value.data.settled_by}</li>
                                        <li>Settlement Date: ${result.value.data.settlement_date}</li>
                                    </ul>
                                </div>
                            ` : ''}
                            ${reasonData && Object.keys(reasonData).length > 0 ? `
                                <div style="text-align: left; margin-top: 15px; background: #fff3cd; padding: 15px; border-radius: 4px; border: 1px solid #ffc107;">
                                    <strong><i class="fas fa-info-circle"></i> Reasons for Not Settled:</strong>
                                    <ul style="margin-top: 10px; padding-left: 20px;">
                                        ${Object.values(reasonData).map(r => `<li>${r.partner_id}: ${r.reason}</li>`).join('')}
                                    </ul>
                                </div>
                            ` : ''}
                            <div style="text-align: left; margin-top: 15px; background: #d4edda; padding: 15px; border-radius: 4px; border: 1px solid #28a745;">
                                <strong><i class="fas fa-check-circle"></i> Ready to Export:</strong>
                                <p style="margin-top: 5px;">You can now export this settlement to Excel or PDF using the buttons above.</p>
                            </div>
                        `,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    });
                    
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Settlement Failed',
                        text: result.value.message,
                        confirmButtonColor: '#dc3545'
                    });
                }
            }
        });
    }
</script>

</body>
</html>