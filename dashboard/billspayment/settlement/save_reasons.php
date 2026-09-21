<?php
// save_reasons.php
header('Content-Type: application/json');
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// Check authentication
$id = resolve_user_identifier();
if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['Settlement Per Bank','Bills Payment'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// ============================================
// HELPER FUNCTIONS (mirrored from settlement-per-bank.php)
// ============================================
function isTuesday(?string $date): bool {
    if (empty($date)) return false;
    $timestamp = strtotime($date);
    if ($timestamp === false) return false;
    return date('N', $timestamp) == 2;
}

function getWednesdayToTuesdayRange(string $tuesday_date): array {
    $timestamp = strtotime($tuesday_date);
    if ($timestamp === false) return [$tuesday_date, $tuesday_date];
    $wednesday = date('Y-m-d', strtotime('-6 days', $timestamp));
    $tuesday = date('Y-m-d', $timestamp);
    return [$wednesday, $tuesday];
}

function getMondayToSundayRange(string $tuesday_date): array {
    $timestamp = strtotime($tuesday_date);
    if ($timestamp === false) return [$tuesday_date, $tuesday_date];
    $sunday = date('Y-m-d', strtotime('-2 days', $timestamp));
    $monday = date('Y-m-d', strtotime('-6 days', strtotime($sunday)));
    return [$monday, $sunday];
}

function getSpecialWeeklyPartners(): array {
    return ['457', '458', '459', '460'];
}

function getSpecialWeekBeforePartners(): array {
    return ['1005'];
}

function getFdcMindanaoRegionSets(): array {
    return [
        'GENSAN' => [
            'regions' => ['R24 SOCSK REGION', 'R16 SARGEN REGION'],
        ],
        'CDO' => [
            'regions' => ['R18 CAGAYAN DE ORO REGION', 'R19 LANAO REGION', 'R30 BUKIDNON REGION', 'R14 DAVAO REGION'],
        ],
    ];
}

function getFuiUnimerchantsRegionSets(): array {
    return [
        'BPI' => [
            'regions' => ['R21 ZANORTE REGION', 'R20 ZASURMIS REGION', 'R19 LANAO REGION', 'R22 ZAMSIBUGAY REGION'],
        ],
        'BDO_NEGROS' => [
            'regions' => ['R04 NEG.OR.-SIQ. REGION', 'R08 NEG OCC A REGION', 'R29 NEG OCC B REGION'],
        ],
        'BDO_CEBU' => [
            'regions' => ['R02 CEBU NORTH A REGION', 'R03 CEBU SOUTH REGION', 'R05 BOHOL REGION', 'R26 CEBU NORTH B REGION', 'R01 CEBU CENTRAL A REGION', 'R27 CEBU CENTRAL B REGION'],
        ],
    ];
}

function getPartner257Sets(): array {
    return [
        'BDO_PANAY' => [
            'regions' => ['R10 PANAY NORTH REGION', 'R11 PANAY CENTRAL REGION'],
            'extra_where' => null,
        ],
        'CHINABANK_BOHOL' => [
            'regions' => ['R05 BOHOL REGION'],
            'extra_where' => null,
        ],
        'CHINABANK_ORMOC' => [
            'regions' => [],
            'extra_where' => "(bt.account_no LIKE '%orm%' OR bt.address LIKE '%orm%' OR bt.account_no LIKE '%sog%' OR bt.address LIKE '%sog%')",
        ],
        'CHINABANK_SAMAR' => [
            'regions' => ['R07 SAMAR REGION'],
            'extra_where' => null,
        ],
        'CHINABANK_TACLOBAN' => [
            'regions' => [],
            'extra_where' => "(bt.account_no LIKE '%tac%' OR bt.address LIKE '%tac%')",
        ],
    ];
}

function getPcsoLandbankSets(): array {
    return [
        'NCR' => ['partner_ids' => ['631']],
        'VISAYAS' => ['partner_ids' => ['648', '650', '651', '653', '655', '656', '658', '660']],
        'MINDANAO' => ['partner_ids' => ['662', '670', '680']],
    ];
}

// Get POST data
$reason_data_json = isset($_POST['reason_data']) ? trim($_POST['reason_data']) : '';
$saved_by = isset($_POST['saved_by']) ? trim($_POST['saved_by']) : '';
$partner_filter = isset($_POST['partner_filter']) ? trim($_POST['partner_filter']) : '';
$bank_filter = isset($_POST['bank_filter']) ? trim($_POST['bank_filter']) : '';
$settlement_type_filter = isset($_POST['settlement_type_filter']) ? trim($_POST['settlement_type_filter']) : '';
$date_from = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
$date_to = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
$rfp_no = isset($_POST['rfp_no']) ? trim($_POST['rfp_no']) : '';

// Decode reason data
$reason_data = [];
if (!empty($reason_data_json)) {
    $reason_data = json_decode($reason_data_json, true);
    if (!is_array($reason_data)) {
        $reason_data = [];
    }
}

// Validate
if (empty($reason_data)) {
    echo json_encode(['success' => false, 'message' => 'No reasons to save.']);
    exit;
}

// Get saved by name
if (empty($saved_by)) {
    if (isset($_SESSION['user_type'])) {
        if ($_SESSION['user_type'] === 'admin') {
            $saved_by = $_SESSION['admin_name'] ?? 'ADMIN';
        } elseif ($_SESSION['user_type'] === 'user') {
            $saved_by = $_SESSION['user_name'] ?? 'USER';
        } else {
            $saved_by = 'SYSTEM';
        }
    } else {
        $saved_by = 'SYSTEM';
    }
}

$save_date = date('Y-m-d H:i:s');
$save_date_display = date('M d, Y H:i:s');
$total_updated = 0;

// ============================================
// Determine effective date ranges for special partners
// ============================================
$special_partners = getSpecialWeeklyPartners();
$special_wb_partners = getSpecialWeekBeforePartners();

$include_special = !empty($date_to) && isTuesday($date_to);
$include_special_wb = !empty($date_to) && isTuesday($date_to);

$special_date_from = $date_from;
$special_date_to = $date_to;
if ($include_special) {
    list($special_date_from, $special_date_to) = getWednesdayToTuesdayRange($date_to);
}

$special_wb_date_from = $date_from;
$special_wb_date_to = $date_to;
if ($include_special_wb) {
    list($special_wb_date_from, $special_wb_date_to) = getMondayToSundayRange($date_to);
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    foreach ($reason_data as $row_index => $reason_info) {
        $partner_id = isset($reason_info['partner_id']) ? $reason_info['partner_id'] : '';
        $reason = isset($reason_info['reason']) ? $reason_info['reason'] : '';
        
        if (empty($partner_id) || empty($reason)) {
            continue;
        }
        
        // ============================================
        // Determine if this is a split partner
        // ============================================
        $split_type = null;
        $split_key = null;
        $regions = [];
        $extra_where = null;
        
        // FDC Mindanao splits (256-GENSAN, 256-CDO)
        if (strpos($partner_id, '256-') === 0) {
            $split_type = 'fdc';
            $split_key = substr($partner_id, 4);
            $partner_id = '256';
            $sets = getFdcMindanaoRegionSets();
            if (isset($sets[$split_key])) {
                $regions = $sets[$split_key]['regions'];
            }
        }
        // Partner 257 splits (257-BDO_PANAY, 257-CHINABANK_BOHOL, etc.)
        elseif (strpos($partner_id, '257-') === 0) {
            $split_type = 'fdc257';
            $split_key = substr($partner_id, 4);
            $partner_id = '257';
            $sets = getPartner257Sets();
            if (isset($sets[$split_key])) {
                $regions = $sets[$split_key]['regions'];
                $extra_where = $sets[$split_key]['extra_where'];
            }
        }
        // Partner 259 splits (259-BPI, 259-BDO_NEGROS, 259-BDO_CEBU)
        elseif (strpos($partner_id, '259-') === 0) {
            $split_type = 'fui';
            $split_key = substr($partner_id, 4);
            $partner_id = '259';
            $sets = getFuiUnimerchantsRegionSets();
            if (isset($sets[$split_key])) {
                $regions = $sets[$split_key]['regions'];
            }
        }
        // PCSO Landbank splits (pcso-631, pcso-648, etc.)
        elseif (strpos($partner_id, 'pcso-') === 0) {
            $split_type = 'pcso';
            $partner_id = substr($partner_id, 5);
        }
        
        // ============================================
        // Build WHERE clause for this specific partner with filters
        // ============================================
        $where_conditions = [];
        $params = [];
        $types = "";
        
        $where_conditions[] = "bt.partner_id_kpx = ?";
        $params[] = $partner_id;
        $types .= "s";
        
        if (!empty($bank_filter)) {
            $where_conditions[] = "pm.bank = ?";
            $params[] = $bank_filter;
            $types .= "s";
        }
        
        if (!empty($settlement_type_filter)) {
            $where_conditions[] = "pm.settled_online_check = ?";
            $params[] = $settlement_type_filter;
            $types .= "s";
        }
        
        // ============================================
        // Apply region filters for split partners
        // ============================================
        if (!empty($regions)) {
            $placeholders = implode(',', array_fill(0, count($regions), '?'));
            $where_conditions[] = "bt.region IN ($placeholders)";
            foreach ($regions as $reg) {
                $params[] = $reg;
                $types .= "s";
            }
        }
        
        if (!empty($extra_where)) {
            $where_conditions[] = $extra_where;
        }
        
        // ============================================
        // Apply date range - use effective dates for special partners
        // ============================================
        $is_special_partner = in_array($partner_id, $special_partners, true);
        $is_special_wb_partner = in_array($partner_id, $special_wb_partners, true);
        
        $effective_date_from = $date_from;
        $effective_date_to = $date_to;
        
        if ($is_special_partner && $include_special) {
            $effective_date_from = $special_date_from;
            $effective_date_to = $special_date_to;
        } elseif ($is_special_wb_partner && $include_special_wb) {
            $effective_date_from = $special_wb_date_from;
            $effective_date_to = $special_wb_date_to;
        }
        
        if (!empty($effective_date_from) && !empty($effective_date_to)) {
            $where_conditions[] = "bt.datetime BETWEEN ? AND ?";
            $params[] = $effective_date_from . ' 00:00:00';
            $params[] = $effective_date_to . ' 23:59:59';
            $types .= "ss";
        } elseif (!empty($effective_date_from)) {
            $where_conditions[] = "bt.datetime >= ?";
            $params[] = $effective_date_from . ' 00:00:00';
            $types .= "s";
        } elseif (!empty($effective_date_to)) {
            $where_conditions[] = "bt.datetime <= ?";
            $params[] = $effective_date_to . ' 23:59:59';
            $types .= "s";
        }
        
        // Only update unsettled records (not settled yet)
        $where_conditions[] = "(bt.settle_unsettle IS NULL OR bt.settle_unsettle = '' OR bt.settle_unsettle != 'Settled')";
        $where_conditions[] = "(bt.status IS NULL OR bt.status = '')";
        
        $sql = "UPDATE mldb.billspayment_transaction bt
                LEFT JOIN masterdata.partner_masterfile pm ON bt.partner_id_kpx = pm.partner_id_kpx
                SET bt.reason_not_settled = ?
                WHERE " . implode(" AND ", $where_conditions);
        
        $params = array_merge([$reason], $params);
        $types = "s" . $types;
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $total_updated += $stmt->affected_rows;
            $stmt->close();
        }
    }
    
    // Log the action
    error_log("Reasons saved - By: $saved_by, Date: $save_date, Updated: $total_updated transactions");
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "Successfully saved reasons for $total_updated transaction(s).",
        'data' => [
            'updated_count' => $total_updated,
            'saved_by' => $saved_by,
            'save_date' => $save_date_display
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Save reasons error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error saving reasons: ' . $e->getMessage()
    ]);
}

$conn->close();
?>