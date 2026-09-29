<?php
// Connect to the databases (both $conn and $conn_exact are defined in config.php)
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();

if (isset($_SESSION['user_type'])) {
    $current_user_email = '';
    if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
        $current_user_email = $_SESSION['admin_email'];
    } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
        $current_user_email = $_SESSION['user_email'];
    }
}

// NOTE:
// $conn       -> mldb   (localhost)                       [from config.php]
// $conn_exact -> glcs_db (ho-cad-exactdb or localhost)    [from config.php]

// ------------------------------------------------------------
// 0. Detect reference column (fallback safe)
// ------------------------------------------------------------
$reference_column = 'reference_no';
$check_col = $conn->query("
    SELECT COLUMN_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'mldb' 
      AND TABLE_NAME = 'billspayment_transaction' 
      AND (COLUMN_NAME LIKE '%ref%' OR COLUMN_NAME LIKE '%reference%')
");
if ($check_col && $check_col->num_rows > 0) {
    $c = $check_col->fetch_assoc();
    $reference_column = $c['COLUMN_NAME'];
    $check_col->free();
}

// ------------------------------------------------------------
// 1. FETCH PARTNERS FOR DROPDOWN
// ------------------------------------------------------------
$partners = [];
$result_partners = $conn->query("
    SELECT partner_id_kpx, partner_name 
    FROM masterdata.partner_masterfile 
    WHERE partner_id_kpx IS NOT NULL 
      AND partner_name IS NOT NULL
    ORDER BY partner_name ASC
");
if ($result_partners) {
    while ($row = $result_partners->fetch_assoc()) {
        $partners[] = $row;
    }
    $result_partners->free();
}

// ------------------------------------------------------------
// 2. FETCH + RECONCILE TRANSACTIONS
// ------------------------------------------------------------
$transactions   = [];
$filter_applied = false;
$debug_message  = '';

// Totals
$total_amount_paid     = 0;
$total_charge_customer = 0;
$total_charge_partner  = 0;
$total_debit           = 0;
$total_credit          = 0;
$matched_count         = 0;
$unmatched_local       = 0;

if (isset($_GET['filter'])) {
    $partner_id   = isset($_GET['partner']) ? trim($_GET['partner']) : '';
    $date_from    = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to      = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $reference_no = isset($_GET['reference_no']) ? trim($_GET['reference_no']) : '';

    // Resolve partner name (used for GLE desc matching)
    $partner_name = '';
    if ($partner_id !== '') {
        foreach ($partners as $p) {
            if ((string)$p['partner_id_kpx'] === (string)$partner_id) {
                $partner_name = $p['partner_name'];
                break;
            }
        }
    }

    // ---------- Validation ----------
    if ($reference_no === '' && $partner_id === '') {
        $debug_message = "Either Reference No or Partner is required.";
    } elseif ($partner_id !== '' && ($date_from === '' || $date_to === '')) {
        $debug_message = "When filtering by Partner, both Date From and Date To are required.";
    } elseif ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
        $debug_message = "Date From cannot be later than Date To.";
    } else {
        $filter_applied = true;

        // ============================================================
        // LOCAL (Bills Payment) QUERY
        // ============================================================
        $sql = "
            SELECT 
                t.partner_id_kpx,
                p.partner_name,
                t.`{$reference_column}` AS reference_no,
                t.datetime,
                t.amount_paid,
                t.charge_to_customer,
                t.charge_to_partner,
                t.branch_id,
                t.outlet
            FROM mldb.billspayment_transaction t
            LEFT JOIN masterdata.partner_masterfile p 
                ON t.partner_id_kpx = p.partner_id_kpx
            WHERE 1=1
        ";

        $params = [];
        $types  = '';

        if ($reference_no !== '') {
            $sql .= " AND t.`{$reference_column}` = ?";
            $params[] = $reference_no;
            $types   .= 's';
        }

        if ($partner_id !== '') {
            $sql .= " AND t.partner_id_kpx = ?";
            $params[] = $partner_id;
            $types   .= 's';
        }

        if ($date_from !== '') {
            $sql .= " AND t.datetime >= ?";
            $params[] = $date_from . ' 00:00:00';
            $types   .= 's';
        }
        if ($date_to !== '') {
            $sql .= " AND t.datetime <= ?";
            $params[] = $date_to . ' 23:59:59';
            $types   .= 's';
        }

        $sql .= " ORDER BY t.datetime DESC LIMIT 500";

        $local_rows = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $local_rows[] = $row;
            }
            $stmt->close();
        } else {
            $debug_message = "Local query error: " . $conn->error;
        }

        // Collect unique references from local rows (for GLE IN-clause)
        $refs = [];
        foreach ($local_rows as $lr) {
            $r = trim($lr['reference_no'] ?? '');
            if ($r !== '') {
                $refs[$r] = true;
            }
        }
        $refs = array_keys($refs);

        // ============================================================
        // EXACTDB (GLE) QUERY
        // - Primary: match on reference (IN list when multiple)
        // - Also pull rows where desc contains the partner name
        //   (especially useful for gl_code = 428101)
        // ============================================================
        $gle_by_ref = [];

        if (!empty($refs) || $partner_id !== '') {
            $gle_sql = "
                SELECT 
                    datetime,
                    gl_code,
                    gl_desc,
                    partner_id,
                    `desc`,
                    reference,
                    entry_number,
                    currency,
                    debit,
                    credit,
                    transaction_type,
                    branch_id,
                    branch_code,
                    item,
                    main_zone,
                    zone,
                    region
                FROM gle
                WHERE 1=1
            ";

            $gle_params = [];
            $gle_types  = '';

            // Build OR conditions for reference list + partner-desc match
            $or_parts = [];

            if (!empty($refs)) {
                $placeholders = implode(',', array_fill(0, count($refs), '?'));
                $or_parts[] = "reference IN ($placeholders)";
                foreach ($refs as $r) {
                    $gle_params[] = $r;
                    $gle_types   .= 's';
                }
            }

            // Partner match via description (partner_name appears in desc)
            // Focus on gl_code 428101 as requested
            if ($partner_id !== '' && $partner_name !== '') {
                $or_parts[] = "(gl_code = '428101' AND `desc` LIKE ?)";
                $gle_params[] = '%' . $partner_name . '%';
                $gle_types   .= 's';
            }

            if (!empty($or_parts)) {
                $gle_sql .= " AND (" . implode(' OR ', $or_parts) . ")";
            } else {
                // Safety: should not reach here
                $gle_sql .= " AND 1=0";
            }

            if ($date_from !== '') {
                $gle_sql .= " AND datetime >= ?";
                $gle_params[] = $date_from . ' 00:00:00';
                $gle_types   .= 's';
            }
            if ($date_to !== '') {
                $gle_sql .= " AND datetime <= ?";
                $gle_params[] = $date_to . ' 23:59:59';
                $gle_types   .= 's';
            }

            $gle_sql .= " ORDER BY entry_number ASC, gl_code ASC LIMIT 5000";

            $stmt_gle = $conn_exact->prepare($gle_sql);
            if ($stmt_gle) {
                if ($gle_types !== '') {
                    $stmt_gle->bind_param($gle_types, ...$gle_params);
                }
                $stmt_gle->execute();
                $result_gle = $stmt_gle->get_result();
                while ($row = $result_gle->fetch_assoc()) {
                    $ref = trim($row['reference'] ?? '');
                    if ($ref !== '') {
                        $gle_by_ref[$ref][] = $row;
                    } else {
                        // GLE rows that matched only via desc (no usable reference)
                        $gle_by_ref['__DESC_MATCH__'][] = $row;
                    }
                }
                $stmt_gle->close();
            } else {
                $debug_message .= ($debug_message ? ' | ' : '') . "ExactDB query error: " . $conn_exact->error;
            }
        }

        // ============================================================
        // RECONCILIATION (driven by local BP rows)
        // ============================================================
        foreach ($local_rows as $local) {
            $ref = trim($local['reference_no'] ?? '');

            $gle_rows  = ($ref !== '' && isset($gle_by_ref[$ref])) ? $gle_by_ref[$ref] : [];
            $has_match = !empty($gle_rows);

            if ($has_match) {
                $matched_count++;
            } else {
                $unmatched_local++;
            }

            $gle_debit_total  = 0;
            $gle_credit_total = 0;
            foreach ($gle_rows as $g) {
                $gle_debit_total  += (float)($g['debit']  ?? 0);
                $gle_credit_total += (float)($g['credit'] ?? 0);
            }

            usort($gle_rows, function ($a, $b) {
                $cmp = strcmp((string)($a['entry_number'] ?? ''), (string)($b['entry_number'] ?? ''));
                if ($cmp !== 0) return $cmp;
                return strcmp((string)($a['gl_code'] ?? ''), (string)($b['gl_code'] ?? ''));
            });

            $transactions[] = [
                'partner_id_kpx'     => $local['partner_id_kpx'],
                'partner_name'       => $local['partner_name'],
                'reference_no'       => $local['reference_no'],
                'datetime'           => $local['datetime'],
                'amount_paid'        => $local['amount_paid'],
                'charge_to_customer' => $local['charge_to_customer'],
                'charge_to_partner'  => $local['charge_to_partner'],

                'gle_row_count'      => count($gle_rows),
                'gle_debit_total'    => $has_match ? $gle_debit_total  : null,
                'gle_credit_total'   => $has_match ? $gle_credit_total : null,
                'gle_rows'           => $gle_rows,

                'match_status'       => $has_match ? 'MATCHED' : 'UNMATCHED (Bills Payment only)',
            ];

            $total_amount_paid     += (float)($local['amount_paid'] ?? 0);
            $total_charge_customer += (float)($local['charge_to_customer'] ?? 0);
            $total_charge_partner  += (float)($local['charge_to_partner'] ?? 0);

            if ($has_match) {
                $total_debit  += $gle_debit_total;
                $total_credit += $gle_credit_total;
            }
        }

        // Optional: also surface pure GLE rows that matched only via desc
        // (no corresponding local BP row). Uncomment the block below if needed.
        
        // if (isset($gle_by_ref['__DESC_MATCH__'])) {
        //     foreach ($gle_by_ref['__DESC_MATCH__'] as $g) {
        //         $transactions[] = [
        //             'partner_id_kpx'     => $partner_id,
        //             'partner_name'       => $partner_name,
        //             'reference_no'       => $g['reference'] ?? '—',
        //             'datetime'           => $g['datetime'] ?? null,
        //             'amount_paid'        => null,
        //             'charge_to_customer' => null,
        //             'charge_to_partner'  => null,
        //             'gle_row_count'      => 1,
        //             'gle_debit_total'    => (float)($g['debit'] ?? 0),
        //             'gle_credit_total'   => (float)($g['credit'] ?? 0),
        //             'gle_rows'           => [$g],
        //             'match_status'       => 'UNMATCHED (GLE only – desc match)',
        //         ];
        //     }
        // }
        
    }
}

// Combined total for Bills Payment (Amount Paid + Charge to Customer + Charge to Partner)
$total_bp_combined = $total_amount_paid + $total_charge_customer + $total_charge_partner;

// Variances
$variance_vs_debit  = $total_bp_combined - $total_debit;
$variance_vs_credit = $total_bp_combined - $total_credit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BP vs GLE Recon Report | <?php echo isset($_SESSION['user_type']) ? ucfirst($_SESSION['user_type']) : "Guest"; ?></title>

    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="stylesheet" href="css/billsgle.css?v=<?= time(); ?>">

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>

        <div id="loading-overlay" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 9999; justify-content: center; align-items: center;">
            <div class="loading-spinner" style="border: 6px solid #f3f3f3; border-top: 6px solid #bd1a1a; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;"></div>
        </div>
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>

        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div>
                    <h2>Recon Report</h2>
                    <p class="bp-section-sub">Bills Payment vs GLE Records</p>
                </div>
            </div>
        </div>

        <!-- Filter card -->
        <div class="bp-card" style="margin: 1rem; padding: .5rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <form id="filter-form" method="GET" action="">
                <input type="hidden" name="filter" value="1">

                <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-end;">

                    <!-- Reference No — optional when Partner + dates are used -->
                    <div style="flex: 1 1 320px; min-width: 260px;">
                        <label for="reference_no" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-solid fa-hashtag"></i> Reference No
                            <small style="color:#888; font-weight:400;"> (or select Partner + dates)</small>
                        </label>
                        <input type="text" name="reference_no" id="reference_no" class="bp-input"
                               placeholder="Enter reference number..."
                               value="<?php echo isset($_GET['reference_no']) ? htmlspecialchars($_GET['reference_no']) : ''; ?>"
                               autocomplete="off"
                               style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;">
                    </div>

                    <!-- Partner — optional -->
                    <div style="flex: 1 1 260px; min-width: 220px;">
                        <label for="partner" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-solid fa-handshake"></i> Partner
                        </label>
                        <select name="partner" id="partner" class="bp-select2" style="width: 100%;">
                            <option value="">— Any Partner (Partner ID - Partner Name) —</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['partner_id_kpx']); ?>"
                                    <?php echo (isset($_GET['partner']) && $_GET['partner'] == $p['partner_id_kpx']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['partner_id_kpx'] . ' — ' . $p['partner_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 0 1 170px;">
                        <label for="date_from" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-regular fa-calendar"></i> Date From
                            <small style="color:#888; font-weight:400;"></small>
                        </label>
                        <input type="date" name="date_from" id="date_from" class="bp-input"
                               value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>"
                               style="width: 100%; padding: 0.2rem; border: 1px solid #ccc; border-radius: 4px;">
                    </div>

                    <div style="flex: 0 1 170px;">
                        <label for="date_to" style="display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 15px; color: #333;">
                            <i class="fa-regular fa-calendar"></i> Date To
                            <small style="color:#888; font-weight:400;"></small>
                        </label>
                        <input type="date" name="date_to" id="date_to" class="bp-input"
                               value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>"
                               style="width: 100%; padding: 0.2rem; border: 1px solid #ccc; border-radius: 4px;">
                    </div>

                    <div style="flex: 0 0 auto; display: flex; gap: 0.5rem;">
                        <button type="submit" class="bp-btn bp-btn-primary"
                                style="padding: 0.5rem 1.2rem; background: #b30000; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                            <i class="fa-solid fa-filter"></i> Filter &amp; Recon
                        </button>
                        <a href="?" id="reset-btn" class="bp-btn bp-btn-secondary"
                           style="padding: 0.5rem 1.2rem; background: #e0e0e0; color: #333; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Debug info -->
        <?php if (!empty($debug_message)): ?>
            <div class="debug-box">
                <strong><i class="fa-solid fa-bug"></i> Info:</strong><br>
                <?php echo htmlspecialchars($debug_message); ?>
            </div>
        <?php endif; ?>

        <!-- Summary cards -->
        <?php if ($filter_applied && count($transactions) > 0): ?>
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin: 1.5rem;">
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #1a3c5e;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Total Amount Paid</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #1a3c5e; margin-top: 0.2rem;">
                        <?php echo number_format($total_amount_paid, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #b30000;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Charge to Customer</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #b30000; margin-top: 0.2rem;">
                        <?php echo number_format($total_charge_customer, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #2e7d32;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Charge to Partner</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #2e7d32; margin-top: 0.2rem;">
                        <?php echo number_format($total_charge_partner, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #0d47a1;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">BP Total</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #0d47a1; margin-top: 0.2rem;">
                        <?php echo number_format($total_bp_combined, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #1565c0;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">GLE Debit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #1565c0; margin-top: 0.2rem;">
                        <?php echo number_format($total_debit, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #6a1b9a;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">GLE Credit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #6a1b9a; margin-top: 0.2rem;">
                        <?php echo number_format($total_credit, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #ef6c00;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">Matched / Unmatched</div>
                    <div style="font-size: 1rem; font-weight: 700; color: #ef6c00; margin-top: 0.2rem;">
                        <?php echo $matched_count; ?> / <?php echo $unmatched_local; ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #c62828;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">BP Total - GLE Debit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: <?php echo $variance_vs_debit == 0 ? '#2e7d32' : '#c62828'; ?>; margin-top: 0.2rem;">
                        <?php if ($variance_vs_debit == 0): ?>
                            <i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i>
                        <?php endif; ?>
                        <?php echo number_format($variance_vs_debit, 2); ?>
                    </div>
                </div>
                <div style="flex: 1 1 180px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: .5rem 1.2rem; border-left: 5px solid #ad1457;">
                    <div style="font-size: 0.75rem; color: #666; text-transform: uppercase;">BP Total - GLE Credit</div>
                    <div style="font-size: 1rem; font-weight: 700; color: <?php echo $variance_vs_credit == 0 ? '#2e7d32' : '#ad1457'; ?>; margin-top: 0.2rem;">
                        <?php if ($variance_vs_credit == 0): ?>
                            <i class="fa-solid fa-circle-check" style="font-size:0.8rem;"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-triangle-exclamation" style="font-size:0.8rem;"></i>
                        <?php endif; ?>
                        <?php echo number_format($variance_vs_credit, 2); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Results table -->
        <div class="bp-card" style="margin: 1.5rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden;">
            <div style="padding: .3rem 1rem; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1rem; color: #1a3c5e;"><i class="fa-solid fa-table"></i> Reconciliation Results</h3>
                <div style="font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 1rem;">
                    <?php if ($filter_applied && count($transactions) > 0): ?>
                        <button type="button" id="expand-all" style="background: #f0f0f0; border: 1px solid #ccc; border-radius: 4px; padding: 3px 10px; cursor: pointer; font-size: 0.8rem;">
                            <i class="fa-solid fa-up-right-and-down-left-from-center"></i> Expand All
                        </button>
                        <button type="button" id="collapse-all" style="background: #f0f0f0; border: 1px solid #ccc; border-radius: 4px; padding: 3px 10px; cursor: pointer; font-size: 0.8rem;">
                            <i class="fa-solid fa-down-left-and-up-right-to-center"></i> Collapse All
                        </button>
                    <?php endif; ?>
                    <span>
                        <?php if ($filter_applied): ?>
                            <i class="fa-solid fa-circle-info"></i> <?php echo count($transactions); ?> record(s)
                        <?php else: ?>
                            <i class="fa-solid fa-circle-info"></i> Enter a Reference No or select Partner + dates to view results
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Scrollable area – max-height + sticky headers -->
            <div style="overflow-x: auto; max-height: 49vh; overflow-y: auto;">
                <table class="bp-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr class="group-header">
                            <th colspan="7" class="group-local" style="text-align: center;">
                                <i class="fa-solid fa-receipt"></i> Bills Payment Transaction
                            </th>
                            <th colspan="8" class="group-gle" style="text-align: center;">
                                <i class="fa-solid fa-book"></i> GLE Records
                            </th>
                        </tr>
                        <tr class="col-header" style="color: #fff;">
                            <th class="col-local" style="padding: 0.65rem 0.5rem; text-align: center;">Status</th>
                            <th class="col-local" style="padding: 0.65rem 0.5rem; text-align: center;">Partner</th>
                            <th class="col-local" style="padding: 0.65rem 0.5rem; text-align: center;">Reference</th>
                            <th class="col-local" style="padding: 0.65rem 0.5rem; text-align: center;">Datetime</th>
                            <th class="col-local" style="padding: 0.65rem 0.5rem; text-align: center;">Amount Paid</th>
                            <th class="col-local" style="padding: 0.65rem 0.5rem; text-align: center;">Chg Cust</th>
                            <th class="col-local divider-right" style="padding: 0.65rem 0.5rem; text-align: center;">Chg Partr</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">GL Code</th>

                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">GL Desc</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">Partner</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">Reference</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">Datetime</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">Debit</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">Credit</th>
                            <th class="col-gle" style="padding: 0.65rem 0.5rem; text-align: center;">Entry No.</th>
                        </tr>
                    </thead>
                    <tbody id="results-body">
                        <?php if ($filter_applied && count($transactions) > 0): ?>
                            <?php foreach ($transactions as $idx => $row): ?>
                                <?php
                                    $child_rows = $row['gle_rows'] ?? [];
                                    $can_expand = !empty($child_rows);
                                    $has_match  = ($row['match_status'] === 'MATCHED');
                                    $is_multi   = (($row['gle_row_count'] ?? 0) > 1);
                                ?>

                                <tr class="parent-row <?php echo $can_expand ? 'expandable' : ''; ?>"
                                    data-target="detail-<?php echo $idx; ?>">
                                    <td style="padding: 0.35rem 0.5rem;">
                                        <?php if ($has_match): ?>
                                            <span class="status-matched<?php echo $is_multi ? ' multi' : ''; ?>">
                                                <?php if ($can_expand): ?>
                                                    <i class="fa-solid fa-caret-down toggle-icon" style="margin-right:4px;"></i>
                                                <?php endif; ?>
                                                MATCHED<?php echo $is_multi ? ' (' . $row['gle_row_count'] . ' rows)' : ''; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-unmatched"><?php echo htmlspecialchars($row['match_status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.35rem 0.5rem;">
                                        <?php echo htmlspecialchars(trim(($row['partner_id_kpx'] ?? '') . ' - ' . ($row['partner_name'] ?? ''), ' -')); ?>
                                    </td>
                                    <td style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>
                                    <td style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['datetime'] ?? '—'); ?></td>
                                    <td style="padding: 0.35rem 0.5rem; text-align: right;"><?php echo $row['amount_paid'] !== null ? number_format((float)$row['amount_paid'], 2) : '—'; ?></td>
                                    <td style="padding: 0.35rem 0.5rem; text-align: right;"><?php echo $row['charge_to_customer'] !== null ? number_format((float)$row['charge_to_customer'], 2) : '—'; ?></td>
                                    <td class="divider-right" style="padding: 0.35rem 0.5rem; text-align: right;"><?php echo $row['charge_to_partner'] !== null ? number_format((float)$row['charge_to_partner'], 2) : '—'; ?></td>

                                    <!-- GLE side on parent – always placeholders (details live in children) -->
                                    <td style="padding: 0.35rem 0.5rem; text-align: right;">—</td>
                                    <td style="padding: 0.35rem 0.5rem;">—</td>
                                    <td style="padding: 0.35rem 0.5rem;">—</td>
                                    <td style="padding: 0.35rem 0.5rem;"><?php echo htmlspecialchars($row['reference_no'] ?? '—'); ?></td>

                                    <td style="padding: 0.35rem 0.5rem; text-align: right; font-weight: 600; color:#1565c0;">—</td>
                                    <td style="padding: 0.35rem 0.5rem; text-align: right; font-weight: 600; color:#6a1b9a;">—</td>
                                    <td style="padding: 0.35rem 0.5rem; text-align: right;">—</td>
                                    <td style="padding: 0.35rem 0.5rem;">—</td>
                                </tr>

                                <!-- ============ CHILD ROWS — VISIBLE BY DEFAULT ============ -->
                                <?php if ($can_expand): ?>
                                    <?php foreach ($child_rows as $g): ?>
                                        <tr class="detail-row" data-parent="detail-<?php echo $idx; ?>">
                                            <td class="cell-local" style="padding: 0.35rem 0.5rem 0.35rem 1.6rem;">
                                                <?php if ($is_multi): ?>
                                                    <i class="fa-solid fa-turn-up fa-rotate-90" style="opacity:.5; margin-right:6px;"></i>
                                                <?php endif; ?>
                                                <small>GLE</small>
                                            </td>
                                            <td class="cell-local"></td>
                                            <td class="cell-local"></td>
                                            <td class="cell-local"></td>
                                            <td class="cell-local"></td>
                                            <td class="cell-local"></td>
                                            <td class="cell-local divider-right"></td>
                                            <td style="padding: 0.35rem 0.5rem; font-weight:600; color:#1a3c5e; text-align: right;">
                                                <?php echo htmlspecialchars($g['gl_code'] ?? '—'); ?>
                                            </td>
                                            <td style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($g['gl_desc'] ?? '—'); ?>
                                            </td>
                                            <td style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($g['desc'] ?? '—'); ?>
                                            </td>
                                            <td style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($g['reference'] ?? '—'); ?>
                                            </td>
                                            <td style="padding: 0.35rem 0.5rem; color:#000000;">
                                                <?php echo htmlspecialchars($g['datetime'] ?? '—'); ?>
                                            </td>
                                            <td style="padding: 0.35rem 0.5rem; text-align: right; color:#1565c0; font-weight:600;">
                                                <?php echo number_format((float)($g['debit'] ?? 0), 2); ?>
                                            </td>
                                            <td style="padding: 0.35rem 0.5rem; text-align: right; color:#6a1b9a; font-weight:600;">
                                                <?php echo number_format((float)($g['credit'] ?? 0), 2); ?>
                                            </td>
                                            
                                            <td style="padding: 0.35rem 0.5rem;">
                                                <?php echo htmlspecialchars($g['entry_number'] ?? '—'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif ($filter_applied && count($transactions) === 0): ?>
                            <tr>
                                <td colspan="15" style="padding: 2rem; text-align: center; color: #888;">
                                    <i class="fa-regular fa-folder-open" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                                    No transactions found for the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" style="padding: 2rem; text-align: center; color: #888;">
                                    <i class="fa-regular fa-folder-open" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i>
                                    Enter a Reference No or select Partner + dates, then click Filter &amp; Recon.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($filter_applied && count($transactions) > 0): ?>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="4" style="text-align: right;">
                                    <i class="fa-solid fa-calculator"></i> TOTAL
                                </td>
                                <td style="text-align: right;"><?php echo number_format($total_amount_paid, 2); ?></td>
                                <td style="text-align: right;"><?php echo number_format($total_charge_customer, 2); ?></td>
                                <td class="divider-right" style="text-align: right;"><?php echo number_format($total_charge_partner, 2); ?></td>
                                <td colspan="5"></td>
                                <td style="text-align: right;"><?php echo number_format($total_debit, 2); ?></td>
                                <td style="text-align: right;"><?php echo number_format($total_credit, 2); ?></td>
                                <td></td>
                            </tr>
                            <tr  class="total-row">
                                <td colspan="4" style="text-align: right;"><i class="fa-solid fa-calculator"></i> Total (Amount + Charges): </td>
                                <td colspan="2" style="text-align: center;"><strong><?php echo number_format($total_bp_combined, 2); ?></strong></td>
                                <td class="divider-right" style="text-align: right;"></td>
                                <td colspan="8"></td>

                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#partner').select2({
            placeholder: '— Any Partner (Partner ID - Partner Name) —',
            allowClear: true,
            width: '100%'
        });

        // ---------- Expand/collapse one parent row ----------
        $(document).on('click', 'tr.parent-row.expandable', function() {
            var target = $(this).data('target');
            var $icon  = $(this).find('.toggle-icon');

            $('tr.detail-row[data-parent="' + target + '"]').toggle();
            $icon.toggleClass('fa-caret-right fa-caret-down');
        });

        // ---------- Expand all ----------
        $('#expand-all').on('click', function() {
            $('tr.detail-row').show();
            $('tr.parent-row.expandable .toggle-icon')
                .removeClass('fa-caret-right')
                .addClass('fa-caret-down');
        });

        // ---------- Collapse all ----------
        $('#collapse-all').on('click', function() {
            $('tr.detail-row').hide();
            $('tr.parent-row.expandable .toggle-icon')
                .removeClass('fa-caret-down')
                .addClass('fa-caret-right');
        });

        // ---------- Form submit validation ----------
        $('#filter-form').on('submit', function(e) {
            var from    = $('#date_from').val();
            var to      = $('#date_to').val();
            var refno   = $('#reference_no').val().trim();
            var partner = $('#partner').val();

            // Either Reference No OR Partner is required
            if (!refno && !partner) {
                e.preventDefault();
                Swal.fire('Missing Filter', 'Please enter a Reference No or select a Partner.', 'warning');
                return false;
            }

            // Partner requires both dates (to keep result set manageable)
            if (partner && (!from || !to)) {
                e.preventDefault();
                Swal.fire('Dates Required', 'When filtering by Partner, both Date From and Date To are required.', 'warning');
                return false;
            }

            // Date range sanity
            if (from && to && from > to) {
                e.preventDefault();
                Swal.fire('Invalid Date Range', '"Date From" cannot be later than "Date To".', 'warning');
                return false;
            }

            $('#loading-overlay').css('display', 'flex');
        });

        // ---------- Enter key on reference input ----------
        $('#reference_no').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#filter-form').trigger('submit');
            }
        });

        // Clear error highlight while typing
        $('#reference_no').on('input', function() {
            if ($(this).val().trim() !== '') {
                $(this).removeClass('input-required-error');
            }
        });
    });
    </script>

    <?php include '../../../templates/footer.php'; ?>
</body>
</html>