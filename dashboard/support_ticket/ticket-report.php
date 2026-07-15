<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/includes/ticket_queries.php';
include_once __DIR__ . '/includes/ticket-report.php';

global $conn;

st_require_login('../../login_form.php');

$hasReportPermission = function_exists('has_permission') && has_permission('Support Ticket Report');
if (!$hasReportPermission) {
    header('Location: ../home.php');
    exit;
}

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'open')));
if (!in_array($mode, ['open', 'active', 'closed'], true)) {
    $mode = 'open';
}

function st_trail_role_icon_asset_report_page($role)
{
    $r = strtoupper(trim((string) $role));
    if ($r === 'BRANCH') return '../../assets/images/icons/branch-icon.svg';
    if ($r === 'VPO') return '../../assets/images/icons/vpo-icon.svg';
    if ($r === 'CAD') return '../../assets/images/icons/cad-icon.svg';
    return '';
}

$searchTicketNumber = strtoupper(trim((string) ($_GET['ticket_number'] ?? '')));

$allTickets = st_get_report_tickets($conn);
[$openTickets, $activeTickets, $closedTickets] = st_partition_report_tickets($allTickets);

$ticketTrailsByTicketId = [];
$ticketAttachmentsByTicketId = [];
$ticketSupplementalByTicketNumber = [];
$ownerIds = [];
$ticketByNumber = [];

foreach ($allTickets as $ticket) {
    $ticketId = (int) ($ticket['id'] ?? 0);
    if ($ticketId <= 0) {
        continue;
    }

    $ticketTrailsByTicketId[$ticketId] = st_get_ticket_trails($conn, $ticketId);
    $ticketAttachmentsByTicketId[$ticketId] = st_get_ticket_attachments_grouped_by_trail_report($conn, $ticketId);

    $ticketNumber = strtoupper(trim((string) ($ticket['ticket_number'] ?? '')));
    if ($ticketNumber !== '') {
        $ticketByNumber[$ticketNumber] = $ticket;
        $ticketSupplementalByTicketNumber[$ticketNumber] = [
            'wrongbiller' => st_get_ticket_wrongbiller_by_ticket_number($conn, $ticketNumber),
            'overstated' => st_get_ticket_overstatedamount_by_ticket_number($conn, $ticketNumber),
            'cancelled' => st_get_ticket_cancelledtransaction_by_ticket_number($conn, $ticketNumber),
        ];
    }

    if (isset($ticket['created_by']) && is_numeric($ticket['created_by'])) {
        $ownerIds[] = (int) $ticket['created_by'];
    }
    if (isset($ticket['vpo_owner']) && is_numeric($ticket['vpo_owner'])) {
        $ownerIds[] = (int) $ticket['vpo_owner'];
    }
    if (isset($ticket['cad_owner']) && is_numeric($ticket['cad_owner'])) {
        $ownerIds[] = (int) $ticket['cad_owner'];
    }
}

$ownerNamesById = st_get_user_names_by_id_numbers($conn, $ownerIds);
$stats = st_build_report_stats($allTickets, $openTickets, $activeTickets, $closedTickets);

$partnerSummaryData = [];
foreach ($allTickets as $ticket) {
    $partnerId = trim((string) ($ticket['partner_ext_id'] ?? ''));
    if ($partnerId === '') {
        $partnerId = trim((string) ($ticket['sb_partner_ext_id'] ?? ''));
    }

    $partnerName = trim((string) ($ticket['partner_name'] ?? ''));
    if ($partnerName === '') {
        $partnerName = $partnerId;
    }

    // For Support Ticket report, exclude only tickets with status == 'closed'.
    // Tickets with any other status should be counted in the receivable summary.
    $status = isset($ticket['status']) ? strtolower(trim((string) $ticket['status'])) : '';
    if ($status === 'closed') {
        continue;
    }

    if ($partnerId === '' || $partnerName === '') {
        continue;
    }

    $dtRaw = (string) ($ticket['transfer_datetime'] ?? ($ticket['created_at'] ?? ''));
    $dtTs = strtotime($dtRaw);
    if ($dtTs === false) {
        continue;
    }

    $year = (int) date('Y', $dtTs);
    if ($year <= 0) {
        continue;
    }

    $billerName = trim((string) ($ticket['biller_name'] ?? ''));
    if ($billerName === '') {
        $billerName = trim((string) ($ticket['wrong_biller_id'] ?? ''));
    }
    if ($billerName === '') {
        $billerName = 'UNKNOWN BILLER';
    }

    $amount = isset($ticket['amount']) && $ticket['amount'] !== null && $ticket['amount'] !== '' ? (float) $ticket['amount'] : 0.0;

    if (!isset($partnerSummaryData[$partnerId])) {
        $partnerSummaryData[$partnerId] = [
            'partner_name' => $partnerName,
            'years' => [],
            'rows' => [],
            'totals_by_year' => [],
            'grand_total' => 0.0,
        ];
    }

    if (!isset($partnerSummaryData[$partnerId]['years'][$year])) {
        $partnerSummaryData[$partnerId]['years'][$year] = true;
    }

    if (!isset($partnerSummaryData[$partnerId]['rows'][$billerName])) {
        $partnerSummaryData[$partnerId]['rows'][$billerName] = [
            'name' => $billerName,
            'years' => [],
            'total' => 0.0,
        ];
    }

    if (!isset($partnerSummaryData[$partnerId]['rows'][$billerName]['years'][$year])) {
        $partnerSummaryData[$partnerId]['rows'][$billerName]['years'][$year] = 0.0;
    }
    $partnerSummaryData[$partnerId]['rows'][$billerName]['years'][$year] += $amount;
    $partnerSummaryData[$partnerId]['rows'][$billerName]['total'] += $amount;

    if (!isset($partnerSummaryData[$partnerId]['totals_by_year'][$year])) {
        $partnerSummaryData[$partnerId]['totals_by_year'][$year] = 0.0;
    }
    $partnerSummaryData[$partnerId]['totals_by_year'][$year] += $amount;
    $partnerSummaryData[$partnerId]['grand_total'] += $amount;
}

if (!empty($partnerSummaryData)) {
    ksort($partnerSummaryData, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($partnerSummaryData as $pid => $pdata) {
        $years = array_keys($pdata['years']);
        sort($years);
        $partnerSummaryData[$pid]['years'] = $years;

        ksort($pdata['totals_by_year']);
        $partnerSummaryData[$pid]['totals_by_year'] = $pdata['totals_by_year'];

        ksort($pdata['rows'], SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($pdata['rows'] as $rowKey => $rowData) {
            ksort($rowData['years']);
            $pdata['rows'][$rowKey] = $rowData;
        }
        $partnerSummaryData[$pid]['rows'] = array_values($pdata['rows']);
    }
}

$typeChartLabels = [];
$typeChartValues = [];
foreach (($stats['type_counts'] ?? []) as $typeLabel => $typeCount) {
    $typeChartLabels[] = (string) $typeLabel;
    $typeChartValues[] = (int) $typeCount;
}

$handlerChartLabels = [];
$handlerChartValues = [];
foreach (($stats['handler_counts'] ?? []) as $handler => $count) {
    $handlerChartLabels[] = (string) $handler;
    $handlerChartValues[] = (int) $count;
}

$agingTicketRows = array_slice((array) ($stats['aging_tickets'] ?? []), 0, 20);
$agingChartLabels = [];
$agingChartValues = [];
$agingChartTooltipTickets = [];
foreach ($agingTicketRows as $agingRow) {
    $agingChartLabels[] = (string) (($agingRow['ticket_number'] ?? '') !== '' ? ($agingRow['ticket_number']) : 'Unknown Ticket');
    $agingChartValues[] = (float) ($agingRow['hours'] ?? 0);
    $agingChartTooltipTickets[] = (string) ($agingRow['ticket_number'] ?? 'Unknown Ticket');
}

$autoOpenModalId = '';
$searchNotFound = false;
if ($searchTicketNumber !== '') {
    if (isset($ticketByNumber[$searchTicketNumber])) {
        $matchedTicket = $ticketByNumber[$searchTicketNumber];
        $autoOpenModalId = 'stTicketTrailModalReport-' . (int) ($matchedTicket['id'] ?? 0);
    } else {
        $searchNotFound = true;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support Ticket - Report</title>
    <link rel="icon" href="../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/support-ticket.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/ticket-modal.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/image-preview.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <style>
        .st-report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .st-report-stat {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .st-report-stat-label {
            font-size: 11px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
        }

        .st-report-stat-value {
            margin-top: 4px;
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            line-height: 1.1;
        }

        .st-report-stat-help {
            margin-top: 4px;
            font-size: 11px;
            color: #475569;
        }

        .st-search-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
        }

        .st-search-wrap input[type="text"] {
            flex: 1 1 auto;
            min-width: 0;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
        }

        .st-search-wrap button,
        .st-search-wrap a {
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .st-search-wrap button {
            border: 1px solid #dc3545;
            background: #dc3545;
            color: #fff;
        }

        .st-search-wrap a {
            border: 1px solid #d1d5db;
            color: #374151;
            background: #fff;
        }

        .st-report-note {
            margin-top: 10px;
            border: 1px solid #fde68a;
            background: #fffbeb;
            color: #92400e;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .st-report-stat.is-clickable {
            cursor: pointer;
            transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
        }

        .st-report-stat.is-clickable:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.08);
            border-color: #fca5a5;
        }

        .st-report-stat.is-clickable:active {
            transform: translateY(0);
        }

        .st-report-stat-tip {
            margin-top: 6px;
            font-size: 10px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .st-stats-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 110000;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .st-stats-modal-overlay.open {
            display: flex;
        }

        .st-stats-modal {
            width: min(1040px, 96vw);
            max-height: 92vh;
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 24px 40px rgba(0, 0, 0, 0.2);
        }

        .st-stats-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 2;
        }

        .st-stats-modal-head h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: #111827;
        }

        .st-stats-modal-body {
            padding: 14px 16px 18px;
        }

        .st-stats-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .st-mini-stat {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 10px;
            background: #f9fafb;
        }

        .st-mini-stat .label {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
        }

        .st-mini-stat .value {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            margin-top: 2px;
        }

        .st-chart-wrap {
            margin-top: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px;
            background: #fff;
        }

        .st-chart-wrap canvas {
            width: 100% !important;
            height: 320px !important;
        }

        .st-summary-filter-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .st-summary-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            min-width: 260px;
            max-width: 100%;
            padding: 8px 10px;
            font-size: 13px;
        }

        .st-summary-table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .st-summary-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
            background: #fff;
        }

        .st-summary-table col.col-name { width: 280px; }
        .st-summary-table col.col-year { width: 130px; }
        .st-summary-table col.col-total { width: 170px; }

        .st-summary-table th,
        .st-summary-table td {
            border-bottom: 1px solid #f1f5f9;
            padding: 8px 10px;
            font-size: 12px;
            white-space: nowrap;
            text-align: left;
        }

        .st-summary-table thead th {
            background: #f8fafc;
            font-weight: 800;
            color: #334155;
        }

        .st-summary-table thead th.partner-col-head {
            text-align: center;
            line-height: 1.2;
        }

        .st-summary-table thead th.partner-col-head span {
            font-weight: 700;
        }

        .st-summary-table thead th:not(.partner-col-head),
        .st-summary-table td:not(:first-child) {
            text-align: right;
        }

        .st-summary-table td.amt,
        .st-summary-table th.amt {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .st-summary-table td.total,
        .st-summary-table tfoot th {
            font-weight: 800;
        }

        .st-summary-table td.total {
            color: #d9534f;
        }

        .st-summary-table tfoot th {
            background: #fff7ed;
            border-top: 2px solid #fed7aa;
        }

        .st-summary-table tfoot th.overall-total,
        .st-summary-table tfoot th.grand-total {
            background: #fff59d;
            color: #000;
        }

        .st-summary-table tfoot th.grand-label {
            text-align: right;
            font-weight: 800;
            background: #fff59d;
            color: #000;
        }

        .st-summary-table tfoot tr.spacer-row th {
            border: 0;
            background: transparent;
            height: 10px;
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../templates/header_ui.php'; ?>
        <?php include '../../templates/sidebar.php'; ?>

        <?php if (function_exists('bp_section_header_html')): ?>
            <?php bp_section_header_html('fa-solid fa-chart-line', 'Support Ticket - Report', 'Management Monitoring and Observation'); ?>
        <?php else: ?>
            <div class="container-fluid mt-3"><h3>Support Ticket - Report</h3></div>
        <?php endif; ?>

        <div class="container-fluid st-wrapper">
            <div class="st-report-grid">
                <div class="st-report-stat is-clickable" data-stat-action="mode-open" title="Go to Open mode">
                    <div class="st-report-stat-label">Open Count</div>
                    <div class="st-report-stat-value"><?php echo (int) $stats['open_count']; ?></div>
                    <div class="st-report-stat-help">Tickets waiting for processing</div>
                    <div class="st-report-stat-tip">Click to switch mode</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="mode-active" title="Go to Active mode">
                    <div class="st-report-stat-label">Active Count</div>
                    <div class="st-report-stat-value"><?php echo (int) $stats['active_count']; ?></div>
                    <div class="st-report-stat-help">Tickets currently being handled</div>
                    <div class="st-report-stat-tip">Click to switch mode</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="mode-closed" title="Go to Closed mode">
                    <div class="st-report-stat-label">Closed Count</div>
                    <div class="st-report-stat-value"><?php echo (int) $stats['closed_count']; ?></div>
                    <div class="st-report-stat-help">Resolved or closed tickets</div>
                    <div class="st-report-stat-tip">Click to switch mode</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="modal-close-rate" title="Open close-rate details">
                    <div class="st-report-stat-label">Close Rate</div>
                    <div class="st-report-stat-value"><?php echo number_format((float) $stats['close_rate'], 1); ?>%</div>
                    <div class="st-report-stat-help">Closed over total tickets</div>
                    <div class="st-report-stat-tip">Click for details</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="modal-aging" title="Open aging details">
                    <div class="st-report-stat-label">Aging Over 24h</div>
                    <div class="st-report-stat-value"><?php echo (int) $stats['aging_over_24h']; ?></div>
                    <div class="st-report-stat-help">Open or active tickets older than 24h</div>
                    <div class="st-report-stat-tip">Click for details</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="modal-receivable" title="Open total receivable details">
                    <div class="st-report-stat-label">Total Receivable</div>
                    <div class="st-report-stat-value">PHP <?php echo number_format((float) $stats['total_receivable'], 2); ?></div>
                    <div class="st-report-stat-help">Open + active receivable only</div>
                    <div class="st-report-stat-tip">Click for partner-year table</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="modal-type" title="Open request type details">
                    <div class="st-report-stat-label">Most Common Type</div>
                    <div class="st-report-stat-value" style="font-size:16px;"><?php echo htmlspecialchars((string) $stats['top_type']); ?></div>
                    <div class="st-report-stat-help"><?php echo (int) $stats['top_type_count']; ?> tickets</div>
                    <div class="st-report-stat-tip">Click for type graph</div>
                </div>

                <div class="st-report-stat is-clickable" data-stat-action="modal-handler" title="Open handler mix details">
                    <div class="st-report-stat-label">Current Handler Mix</div>
                    <div class="st-report-stat-help" style="margin-top:8px;line-height:1.5;">
                        BRANCH: <?php echo (int) ($stats['handler_counts']['BRANCH'] ?? 0); ?><br>
                        VPO: <?php echo (int) ($stats['handler_counts']['VPO'] ?? 0); ?><br>
                        CAD: <?php echo (int) ($stats['handler_counts']['CAD'] ?? 0); ?><br>
                        OTHER: <?php echo (int) ($stats['handler_counts']['OTHER'] ?? 0); ?>
                    </div>
                    <div class="st-report-stat-tip">Click for handler graph</div>
                </div>
            </div>

            <form method="get" class="st-search-wrap">
                <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode); ?>">
                <input type="text" name="ticket_number" value="<?php echo htmlspecialchars($searchTicketNumber); ?>" placeholder="Search Ticket Number">
                <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search Ticket</button>
            </form>

            <div class="mode-cards" data-st-mode-group data-st-param="mode">
                <label class="mode-card <?php echo $mode === 'open' ? 'selected' : ''; ?>" data-mode="open">
                    <input type="radio" name="reportMode" value="open" <?php echo $mode === 'open' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-inbox"></i></div>
                    <div class="mode-text"><p class="mode-label">OPEN</p><small>Unresolved queue</small></div>
                </label>

                <label class="mode-card <?php echo $mode === 'active' ? 'selected' : ''; ?>" data-mode="active">
                    <input type="radio" name="reportMode" value="active" <?php echo $mode === 'active' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-bolt"></i></div>
                    <div class="mode-text"><p class="mode-label">ACTIVE</p><small>In-progress tickets</small></div>
                </label>

                <label class="mode-card <?php echo $mode === 'closed' ? 'selected' : ''; ?>" data-mode="closed">
                    <input type="radio" name="reportMode" value="closed" <?php echo $mode === 'closed' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-box-archive"></i></div>
                    <div class="mode-text"><p class="mode-label">CLOSED</p><small>Resolved and closed</small></div>
                </label>
            </div>

            <div class="mode-panel <?php echo $mode === 'open' ? '' : 'hidden'; ?>" data-st-panel="open">
                <?php if (empty($openTickets)): ?>
                    <div class="st-empty">No open tickets available.</div>
                <?php else: ?>
                    <div class="st-ticket-table" role="table" aria-label="Open support ticket report table">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($openTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalReport-<?php echo (int) $ticket['id']; ?>" data-ticket-id="<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>">
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) ($ticket['created_at'] ?? '')); ?></span>
                                <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
                                <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_partner_name_report($ticket)); ?></span>
                                <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_report($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mode-panel <?php echo $mode === 'active' ? '' : 'hidden'; ?>" data-st-panel="active">
                <?php if (empty($activeTickets)): ?>
                    <div class="st-empty">No active tickets available.</div>
                <?php else: ?>
                    <div class="st-ticket-table" role="table" aria-label="Active support ticket report table">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($activeTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalReport-<?php echo (int) $ticket['id']; ?>" data-ticket-id="<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>">
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) ($ticket['created_at'] ?? '')); ?></span>
                                <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
                                <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_partner_name_report($ticket)); ?></span>
                                <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_report($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mode-panel <?php echo $mode === 'closed' ? '' : 'hidden'; ?>" data-st-panel="closed">
                <?php if (empty($closedTickets)): ?>
                    <div class="st-empty">No closed tickets available.</div>
                <?php else: ?>
                    <div class="st-ticket-table" role="table" aria-label="Closed support ticket report table">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($closedTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalReport-<?php echo (int) $ticket['id']; ?>" data-ticket-id="<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>">
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) (($ticket['closed_at'] ?? '') !== '' ? $ticket['closed_at'] : ($ticket['created_at'] ?? ''))); ?></span>
                                <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
                                <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_partner_name_report($ticket)); ?></span>
                                <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_report($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="st-report-note">Observation mode only: You can view and monitor every ticket, but no actions can be performed here.</div>

            <?php foreach ($allTickets as $ticket): ?>
                <?php
                    $ticketId = (int) ($ticket['id'] ?? 0);
                    $trails = $ticketTrailsByTicketId[$ticketId] ?? [];
                    $attachmentsByTrail = $ticketAttachmentsByTicketId[$ticketId] ?? [];
                    $ticketTypeText = (string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request']);
                    $statusLower = strtolower((string) ($ticket['status'] ?? ''));
                    $ticketNumber = strtoupper((string) ($ticket['ticket_number'] ?? ''));
                    $ticketSupplemental = $ticketSupplementalByTicketNumber[$ticketNumber] ?? [];
                    $vpoOwnerId = (int) ($ticket['vpo_owner'] ?? 0);
                    $cadOwnerId = (int) ($ticket['cad_owner'] ?? 0);
                    $createdById = (int) ($ticket['created_by'] ?? 0);
                    $createdByName = $createdById > 0 ? ($ownerNamesById[$createdById] ?? ('ID ' . $createdById)) : 'N/A';
                    $vpoOwnerName = $vpoOwnerId > 0 ? ($ownerNamesById[$vpoOwnerId] ?? ('ID ' . $vpoOwnerId)) : 'Not assigned';
                    $cadOwnerName = $cadOwnerId > 0 ? ($ownerNamesById[$cadOwnerId] ?? ('ID ' . $cadOwnerId)) : 'Not assigned';

                    $hdrReference = (string) ($ticket['reference_number'] ?? 'N/A');
                    $hdrTransferRaw = (string) ($ticket['transfer_datetime'] ?? '');
                    $hdrTransfer = $hdrTransferRaw;
                    $tsHdr = strtotime($hdrTransferRaw);
                    if ($tsHdr !== false) {
                        $hdrTransfer = date('M d, Y h:i A', $tsHdr);
                    }
                    $hdrAccount = (string) ($ticket['account_no'] ?? ($ticket['account_number'] ?? 'N/A'));
                    $hdrPaymentBranch = (string) ($ticket['payment_branch_name'] ?? ($ticket['payment_branch_id'] ?? 'N/A'));
                    $hdrAmount = isset($ticket['amount']) && $ticket['amount'] !== null && $ticket['amount'] !== '' ? 'PHP ' . number_format((float) $ticket['amount'], 2) : 'N/A';
                ?>
                <div class="tm-overlay" id="stTicketTrailModalReport-<?php echo $ticketId; ?>" aria-hidden="true" role="dialog" aria-modal="true">
                    <div class="tm-modal">
                        <div class="tm-header">
                            <div class="tm-header-top">
                                <div class="tm-header-left">
                                    <div class="tm-ticket-number tm-ticket-number--card">
                                        <div class="tm-ticket-number-main">
                                            <span class="tm-ticket-icon"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span>
                                            <span class="tm-ticket-number-label">Ticket</span>
                                            <span class="tm-ticket-id-value"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                        </div>
                                        <button type="button" class="tm-copy-ticket" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" title="Copy ticket number" aria-label="Copy ticket number"><i class="fa-solid fa-clipboard" aria-hidden="true"></i></button>
                                    </div>
                                    <div class="tm-ticket-meta-grid">
                                        <div class="tm-meta-item"><div class="tm-meta-label">Reference No.</div><div class="tm-meta-value tm-meta-value--ref"><?php echo htmlspecialchars($hdrReference); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Transaction D/T</div><div class="tm-meta-value"><?php echo htmlspecialchars($hdrTransfer); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Account No.</div><div class="tm-meta-value"><?php echo htmlspecialchars($hdrAccount); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Payment Branch</div><div class="tm-meta-value"><?php echo htmlspecialchars($hdrPaymentBranch); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Partner</div><div class="tm-meta-value"><?php echo htmlspecialchars(st_partner_name_report($ticket)); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Created By</div><div class="tm-meta-value"><?php echo htmlspecialchars($createdByName); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Type</div><div class="tm-meta-value"><?php echo htmlspecialchars($ticketTypeText); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Source</div><div class="tm-meta-value"><?php echo htmlspecialchars((string) ($ticket['source'] ?? 'N/A')); ?></div></div>
                                        <div class="tm-meta-item"><div class="tm-meta-label">Amount</div><div class="tm-meta-value"><?php echo htmlspecialchars($hdrAmount); ?></div></div>
                                    </div>
                                </div>
                                <div class="tm-header-right">
                                    <div class="tm-header-actions tm-header-actions--card">
                                        <div class="tm-header-actions-top">
                                            <div class="tm-status tm-status--<?php echo htmlspecialchars($statusLower); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></div>
                                            <button type="button" class="tm-close-btn" data-st-close-modal="stTicketTrailModalReport-<?php echo $ticketId; ?>" aria-label="Close">&times;</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tm-body">
                            <div class="tm-trail">
                                <?php if (empty($trails)): ?>
                                    <div class="tm-empty-trail">No trail entries yet.</div>
                                <?php else: ?>
                                    <?php $lastTrailIndex = count($trails) - 1; ?>
                                    <?php foreach ($trails as $trailIndex => $trail): ?>
                                        <?php
                                            $trailId = (int) ($trail['id'] ?? 0);
                                            $trailRole = strtoupper((string) ($trail['sender_role'] ?? 'SYSTEM'));
                                            $trailType = (string) ($trail['type'] ?? 'message');
                                            $trailDatetimeRaw = (string) ($trail['created_at'] ?? '');
                                            $trailDatetime = $trailDatetimeRaw;
                                            $ts = strtotime($trailDatetimeRaw);
                                            if ($ts !== false) {
                                                $trailDatetime = date('M d, Y h:i A', $ts);
                                            }
                                            $trailAttachments = $attachmentsByTrail[$trailId] ?? [];
                                            $trailMessage = trim((string) ($trail['message'] ?? ''));
                                            $avatarClass = 'tm-trail-avatar--system';
                                            if ($trailRole === 'BRANCH') $avatarClass = 'tm-trail-avatar--branch';
                                            elseif ($trailRole === 'VPO') $avatarClass = 'tm-trail-avatar--vpo';
                                            elseif ($trailRole === 'CAD') $avatarClass = 'tm-trail-avatar--cad';
                                            $trailIconAsset = st_trail_role_icon_asset_report_page($trailRole);
                                            $trailRoleClass = strtolower($trailRole);

                                            $trailOwnerTooltip = '';
                                            if ($trailRole === 'BRANCH') {
                                                $trailOwnerTooltip = $createdByName;
                                            } elseif ($trailRole === 'VPO') {
                                                $trailOwnerTooltip = $vpoOwnerName;
                                            } elseif ($trailRole === 'CAD') {
                                                $trailOwnerTooltip = $cadOwnerName;
                                            }
                                        ?>
                                        <div class="tm-trail-item" data-trail-id="<?php echo (int) $trailId; ?>">
                                            <div class="tm-trail-dot-wrap">
                                                <div class="tm-trail-avatar <?php echo $avatarClass; ?>">
                                                    <?php if ($trailIconAsset !== ''): ?>
                                                        <img class="tm-trail-avatar-icon tm-trail-avatar-icon--<?php echo htmlspecialchars($trailRoleClass); ?>" src="<?php echo htmlspecialchars($trailIconAsset, ENT_QUOTES); ?>" alt="" aria-hidden="true">
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-gear" aria-hidden="true"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="tm-trail-card <?php echo $trailRole === 'SYSTEM' ? 'tm-trail-card--system' : ''; ?> <?php echo $trailIndex === $lastTrailIndex ? 'tm-expanded' : ''; ?>" <?php echo $trailIndex === $lastTrailIndex ? 'data-tm-latest="1"' : ''; ?>>
                                                <div class="tm-trail-card-header">
                                                    <div class="tm-trail-avatar <?php echo $avatarClass; ?>">
                                                        <?php if ($trailIconAsset !== ''): ?>
                                                            <img class="tm-trail-avatar-icon tm-trail-avatar-icon--<?php echo htmlspecialchars($trailRoleClass); ?>" src="<?php echo htmlspecialchars($trailIconAsset, ENT_QUOTES); ?>" alt="" aria-hidden="true">
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-gear" aria-hidden="true"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="tm-trail-meta">
                                                        <div class="tm-trail-sender">
                                                            <span><?php echo htmlspecialchars($trailRole); ?></span>
                                                            <?php if ($trailOwnerTooltip !== ''): ?>
                                                                <span class="tm-owner-help tm-owner-help--inline" title="<?php echo htmlspecialchars($trailOwnerTooltip); ?>">?</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="tm-trail-datetime"><?php echo htmlspecialchars($trailDatetime); ?></div>
                                                    </div>
                                                    <div class="tm-trail-type-label tm-trail-type-label--<?php echo htmlspecialchars(strtolower($trailType)); ?>"><?php echo htmlspecialchars(st_trail_type_label_report($trailType)); ?></div>
                                                    <div class="tm-trail-chevron">&#8250;</div>
                                                </div>
                                                <div class="tm-trail-card-body">
                                                    <?php
                                                        $showTicketDetails = false;
                                                        $ticketReason = trim((string) ($ticket['reason'] ?? ''));
                                                        if ($trailIndex === 0 || ($ticketReason !== '' && $trailMessage === $ticketReason)) {
                                                            $showTicketDetails = true;
                                                        }
                                                    ?>

                                                    <?php if ($showTicketDetails): ?>
                                                        <div class="tm-ticket-details">
                                                            <?php
                                                                $wb = !empty($ticketSupplemental['wrongbiller']) ? $ticketSupplemental['wrongbiller'] : null;
                                                                $oa = !empty($ticketSupplemental['overstated']) ? $ticketSupplemental['overstated'] : null;
                                                                $ct = !empty($ticketSupplemental['cancelled']) ? $ticketSupplemental['cancelled'] : null;
                                                                $wrongBillerId = trim((string) ($ticket['wrong_biller_id'] ?? ''));
                                                                $wrongBillerName = trim((string) ($ticket['biller_name'] ?? ''));

                                                                $typeOfRequest = strtoupper(trim((string) ($ticket['type_of_request'] ?? '')));
                                                                $isWrongBillerType = ($typeOfRequest === 'WRONG BILLER');
                                                                $isCancelledType = ($typeOfRequest === 'CANCELLED TRANSACTION');
                                                                $isOverstatedType = ($typeOfRequest === 'OVERSTATED AMOUNT');
                                                            ?>

                                                            <?php if ($isWrongBillerType): ?>
                                                                <div class="tm-ticket-billers">
                                                                    <div class="tm-ticket-details-col tm-ticket-details-col--left">
                                                                        <?php if (!empty($wb) && !empty($wb['correct_biller_id'])): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Correct Biller ID</span><span class="tm-detail-value"><?php echo htmlspecialchars((string) $wb['correct_biller_id']); ?></span></div>
                                                                        <?php endif; ?>
                                                                        <?php if (!empty($wb) && !empty($wb['correct_biller_name'])): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Correct Biller Name</span><span class="tm-detail-value"><?php echo htmlspecialchars((string) $wb['correct_biller_name']); ?></span></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="tm-ticket-details-col tm-ticket-details-col--right">
                                                                        <?php if ($wrongBillerId !== ''): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Wrong Biller ID</span><span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerId); ?></span></div>
                                                                        <?php endif; ?>
                                                                        <?php if ($wrongBillerName !== ''): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Wrong Biller Name</span><span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerName); ?></span></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php elseif ($isCancelledType || $isOverstatedType): ?>
                                                                <?php
                                                                    $amountSource = $isOverstatedType ? $oa : $ct;
                                                                    $amountCorrect = isset($amountSource['correct_amount']) ? $amountSource['correct_amount'] : null;
                                                                    $amountWrong = isset($amountSource['wrong_amount']) ? $amountSource['wrong_amount'] : null;
                                                                    $amountDifference = ($isOverstatedType && isset($amountSource['difference'])) ? $amountSource['difference'] : null;
                                                                ?>
                                                                <div class="tm-ticket-split">
                                                                    <div class="tm-ticket-details-col tm-ticket-details-col--left">
                                                                        <?php if ($amountCorrect !== null): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Correct Amount</span><span class="tm-detail-value"><?php echo htmlspecialchars(number_format((float) $amountCorrect, 2)); ?></span></div>
                                                                        <?php endif; ?>
                                                                        <?php if ($amountWrong !== null): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Wrong Amount</span><span class="tm-detail-value"><?php echo htmlspecialchars(number_format((float) $amountWrong, 2)); ?></span></div>
                                                                        <?php endif; ?>
                                                                        <?php if ($amountDifference !== null): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Difference</span><span class="tm-detail-value"><?php echo htmlspecialchars(number_format((float) $amountDifference, 2)); ?></span></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="tm-ticket-details-col tm-ticket-details-col--right">
                                                                        <?php if ($wrongBillerId !== ''): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Biller ID</span><span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerId); ?></span></div>
                                                                        <?php endif; ?>
                                                                        <?php if ($wrongBillerName !== ''): ?>
                                                                            <div class="tm-ticket-detail"><span class="tm-detail-label">Biller Name</span><span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerName); ?></span></div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ($trailMessage !== ''): ?>
                                                        <div class="tm-trail-message"><?php echo nl2br(htmlspecialchars($trailMessage)); ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($trailAttachments)): ?>
                                                        <div class="tm-attachments">
                                                            <?php foreach ($trailAttachments as $att): ?>
                                                                <a class="tm-attachment" href="controllers/attachments/download.php?id=<?php echo (int) ($att['id'] ?? 0); ?>">
                                                                    <span class="tm-attachment-icon"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></span>
                                                                    <span class="tm-attachment-name"><?php echo htmlspecialchars((string) ($att['file_name'] ?? 'Attachment')); ?></span>
                                                                    <span class="tm-attachment-size"><?php echo htmlspecialchars((string) ($att['file_size'] ?? '')); ?></span>
                                                                </a>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tm-footer tm-footer--closed">You cannot interact with this Ticket!</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="tm-submodal-overlay" id="stReportNotFoundModal" style="display:<?php echo $searchNotFound ? 'flex' : 'none'; ?>;" aria-hidden="<?php echo $searchNotFound ? 'false' : 'true'; ?>">
            <div class="tm-submodal" role="dialog" aria-modal="true" aria-label="Ticket not found">
                <div class="tm-submodal-title">Ticket Number not found</div>
                <div class="tm-submodal-ticket-info">No ticket matched: <?php echo htmlspecialchars($searchTicketNumber); ?></div>
                <hr class="tm-submodal-divider">
                <div class="tm-submodal-footer">
                    <button type="button" class="tm-btn tm-btn--outline" id="stReportNotFoundClose">Close</button>
                </div>
            </div>
        </div>

        <div class="st-stats-modal-overlay" id="stCloseRateModal" aria-hidden="true">
            <div class="st-stats-modal" role="dialog" aria-modal="true" aria-label="Close Rate Statistics">
                <div class="st-stats-modal-head">
                    <h4>Close Rate Statistics</h4>
                    <button type="button" class="tm-close-btn" data-st-stats-close="stCloseRateModal" aria-label="Close">&times;</button>
                </div>
                <div class="st-stats-modal-body">
                    <div class="st-stats-grid-3">
                        <div class="st-mini-stat"><div class="label">Close Rate</div><div class="value"><?php echo number_format((float) $stats['close_rate'], 1); ?>%</div></div>
                        <div class="st-mini-stat"><div class="label">Open (Not Closed)</div><div class="value"><?php echo number_format((float) $stats['open_rate'], 1); ?>%</div></div>
                        <div class="st-mini-stat"><div class="label">Active (Not Closed)</div><div class="value"><?php echo number_format((float) $stats['active_rate'], 1); ?>%</div></div>
                    </div>
                    <div class="st-chart-wrap"><canvas id="stCloseRateChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="st-stats-modal-overlay" id="stAgingModal" aria-hidden="true">
            <div class="st-stats-modal" role="dialog" aria-modal="true" aria-label="Aging Over 24 Hours">
                <div class="st-stats-modal-head">
                    <h4>Aging Over 24 Hours</h4>
                    <button type="button" class="tm-close-btn" data-st-stats-close="stAgingModal" aria-label="Close">&times;</button>
                </div>
                <div class="st-stats-modal-body">
                    <div class="st-mini-stat">
                        <div class="label">Tickets over 24h</div>
                        <div class="value"><?php echo (int) $stats['aging_over_24h']; ?></div>
                    </div>
                    <div class="st-chart-wrap"><canvas id="stAgingHoursChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="st-stats-modal-overlay" id="stReceivableModal" aria-hidden="true">
            <div class="st-stats-modal" role="dialog" aria-modal="true" aria-label="Total Receivable Summary">
                <div class="st-stats-modal-head">
                    <h4>Total Receivable Summary</h4>
                    <button type="button" class="tm-close-btn" data-st-stats-close="stReceivableModal" aria-label="Close">&times;</button>
                </div>
                <div class="st-stats-modal-body">
                    <div class="st-summary-filter-row">
                        <label for="stReceivablePartnerSelect" style="font-size:12px;font-weight:700;color:#374151;">Partner</label>
                        <select id="stReceivablePartnerSelect" class="st-summary-select"></select>
                    </div>
                    <div id="stReceivableSummaryContainer"></div>
                </div>
            </div>
        </div>

        <div class="st-stats-modal-overlay" id="stTypeModal" aria-hidden="true">
            <div class="st-stats-modal" role="dialog" aria-modal="true" aria-label="Most Common Request Types">
                <div class="st-stats-modal-head">
                    <h4>Request Type Distribution</h4>
                    <button type="button" class="tm-close-btn" data-st-stats-close="stTypeModal" aria-label="Close">&times;</button>
                </div>
                <div class="st-stats-modal-body">
                    <div class="st-mini-stat"><div class="label">Most Common</div><div class="value" style="font-size:18px;"><?php echo htmlspecialchars((string) $stats['top_type']); ?></div></div>
                    <div class="st-chart-wrap"><canvas id="stTypeChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="st-stats-modal-overlay" id="stHandlerModal" aria-hidden="true">
            <div class="st-stats-modal" role="dialog" aria-modal="true" aria-label="Current Handler Mix">
                <div class="st-stats-modal-head">
                    <h4>Current Handler Mix</h4>
                    <button type="button" class="tm-close-btn" data-st-stats-close="stHandlerModal" aria-label="Close">&times;</button>
                </div>
                <div class="st-stats-modal-body">
                    <div class="st-stats-grid-3">
                        <div class="st-mini-stat"><div class="label">BRANCH</div><div class="value"><?php echo (int) ($stats['handler_counts']['BRANCH'] ?? 0); ?></div></div>
                        <div class="st-mini-stat"><div class="label">VPO</div><div class="value"><?php echo (int) ($stats['handler_counts']['VPO'] ?? 0); ?></div></div>
                        <div class="st-mini-stat"><div class="label">CAD</div><div class="value"><?php echo (int) ($stats['handler_counts']['CAD'] ?? 0); ?></div></div>
                    </div>
                    <div class="st-chart-wrap"><canvas id="stHandlerChart"></canvas></div>
                </div>
            </div>
        </div>

        <?php include '../../templates/footer.php'; ?>
    </div>

    <script>
        window.supportTicketLiveUpdates = {
            endpoint: 'controllers/poll/live-updates.php',
            scope: 'REPORT',
            intervalMs: 5000
        };
    </script>

    <script src="assets/js/support-ticket-ui.js?v=<?php echo time(); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function () {
            var closeRateCounts = <?php echo json_encode([(int) $stats['closed_count'], (int) $stats['open_count'], (int) $stats['active_count']]); ?>;
            var closeRateLabels = ['Closed', 'Open', 'Active'];

            var typeLabels = <?php echo json_encode($typeChartLabels); ?>;
            var typeValues = <?php echo json_encode($typeChartValues); ?>;

            var handlerLabels = <?php echo json_encode($handlerChartLabels); ?>;
            var handlerValues = <?php echo json_encode($handlerChartValues); ?>;

            var agingLabels = <?php echo json_encode(array_map(function ($index) { return '#' . ($index + 1); }, array_keys($agingChartValues))); ?>;
            var agingValues = <?php echo json_encode($agingChartValues); ?>;
            var agingTickets = <?php echo json_encode($agingChartTooltipTickets); ?>;

            var partnerSummaryData = <?php echo json_encode($partnerSummaryData, JSON_UNESCAPED_UNICODE); ?>;

            var chartRefs = {
                closeRate: null,
                aging: null,
                type: null,
                handler: null
            };

            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function switchMode(mode) {
                var input = document.querySelector('input[name="reportMode"][value="' + mode + '"]');
                if (!input) return;
                input.checked = true;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function openStatsModal(modalId) {
                var modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
            }

            function closeStatsModal(modalId) {
                var modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            }

            function buildCloseRateChart() {
                var ctx = document.getElementById('stCloseRateChart');
                if (!ctx || typeof Chart === 'undefined') return;
                if (chartRefs.closeRate) chartRefs.closeRate.destroy();
                chartRefs.closeRate = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: closeRateLabels,
                        datasets: [{
                            data: closeRateCounts,
                            backgroundColor: ['#16a34a', '#f59e0b', '#3b82f6'],
                            borderColor: '#ffffff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            }

            function buildAgingChart() {
                var ctx = document.getElementById('stAgingHoursChart');
                if (!ctx || typeof Chart === 'undefined') return;
                if (chartRefs.aging) chartRefs.aging.destroy();
                chartRefs.aging = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: agingLabels,
                        datasets: [{
                            label: 'Hours',
                            data: agingValues,
                            backgroundColor: '#dc2626'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        // copy ticket number on click
                        onClick: function (evt, activeEls) {
                            try {
                                if (!activeEls || !activeEls.length) return;
                                var idx = activeEls[0].index;
                                var ticket = (agingTickets && agingTickets[idx]) ? agingTickets[idx] : (agingLabels && agingLabels[idx] ? agingLabels[idx] : '');
                                if (!ticket) return;

                                function showToast(msg, type) {
                                    if (window.stShowToast) {
                                        window.stShowToast(msg, type === 'danger' ? 'danger' : 'success');
                                        return;
                                    }
                                    var existing = document.getElementById('st-copy-toast');
                                    var klass = (type === 'danger') ? 'st-copy-toast--danger' : 'st-copy-toast--success';
                                    if (existing) {
                                        existing.textContent = msg;
                                        existing.classList.remove('st-copy-toast--hide', 'st-copy-toast--danger', 'st-copy-toast--success');
                                        existing.classList.add('st-copy-toast--show', klass);
                                        clearTimeout(existing._hideTimeout);
                                        existing._hideTimeout = setTimeout(function () {
                                            existing.classList.remove('st-copy-toast--show');
                                            existing.classList.add('st-copy-toast--hide');
                                            setTimeout(function () { try { existing.remove(); } catch (e) {} }, 260);
                                        }, 2200);
                                        return;
                                    }
                                    var toast = document.createElement('div');
                                    toast.id = 'st-copy-toast';
                                    toast.className = 'st-copy-toast st-copy-toast--show ' + klass;
                                    toast.textContent = msg;
                                    document.body.appendChild(toast);
                                    toast._hideTimeout = setTimeout(function () {
                                        toast.classList.remove('st-copy-toast--show');
                                        toast.classList.add('st-copy-toast--hide');
                                        setTimeout(function () { try { toast.remove(); } catch (e) {} }, 260);
                                    }, 2200);
                                }

                                function fallbackCopy(text) {
                                    var ta = document.createElement('textarea');
                                    ta.value = String(text || '');
                                    ta.style.position = 'fixed';
                                    ta.style.left = '-9999px';
                                    document.body.appendChild(ta);
                                    ta.select();
                                    try {
                                        var ok = document.execCommand('copy');
                                        document.body.removeChild(ta);
                                        if (ok) showToast('Ticket number copied to clipboard');
                                        else showToast('Unable to copy ticket number', 'danger');
                                    } catch (err) {
                                        document.body.removeChild(ta);
                                        showToast('Unable to copy ticket number', 'danger');
                                    }
                                }

                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(ticket).then(function () {
                                        showToast('Ticket number copied to clipboard');
                                    }).catch(function () {
                                        fallbackCopy(ticket);
                                    });
                                } else {
                                    fallbackCopy(ticket);
                                }
                            } catch (e) {
                                // ignore click errors
                            }
                        },
                        indexAxis: 'y',
                        scales: {
                            x: { beginAtZero: true, title: { display: true, text: 'Hours' } },
                            y: { ticks: { autoSkip: false } }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: function (items) {
                                        var idx = items && items.length ? items[0].dataIndex : 0;
                                        return 'Ticket: ' + (agingTickets[idx] || 'Unknown');
                                    },
                                    label: function (ctx2) {
                                        return 'Aging: ' + ctx2.parsed.x + ' hours';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function buildTypeChart() {
                var ctx = document.getElementById('stTypeChart');
                if (!ctx || typeof Chart === 'undefined') return;
                if (chartRefs.type) chartRefs.type.destroy();
                chartRefs.type = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            label: 'Ticket Count',
                            data: typeValues,
                            backgroundColor: '#7c3aed'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            }

            function buildHandlerChart() {
                var ctx = document.getElementById('stHandlerChart');
                if (!ctx || typeof Chart === 'undefined') return;
                if (chartRefs.handler) chartRefs.handler.destroy();
                chartRefs.handler = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: handlerLabels,
                        datasets: [{
                            data: handlerValues,
                            backgroundColor: ['#10b981', '#3b82f6', '#f97316', '#6b7280'],
                            borderColor: '#ffffff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            function formatAmount(num) {
                var n = Number(num || 0);
                return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function renderReceivableSummary(partnerId) {
                var container = document.getElementById('stReceivableSummaryContainer');
                if (!container) return;

                if (!partnerId || !partnerSummaryData[partnerId]) {
                    container.innerHTML = '<div class="st-empty">Select a partner to display support ticket receivable summary.</div>';
                    return;
                }

                var summary = partnerSummaryData[partnerId];
                var years = summary.years || [];
                var rows = summary.rows || [];
                var totalsByYear = summary.totals_by_year || {};

                if (!rows.length) {
                    container.innerHTML = '<div class="st-empty">No support ticket amounts found for the selected partner.</div>';
                    return;
                }

                var headCols = years.map(function (y) { return '<th>' + y + '</th>'; }).join('');
                var colGroup = '<colgroup><col class="col-name" />' + years.map(function () { return '<col class="col-year" />'; }).join('') + '<col class="col-total" /></colgroup>';
                var bodyRows = rows.map(function (row) {
                    var yearCells = years.map(function (y) {
                        var v = row.years && row.years[y] ? row.years[y] : null;
                        return '<td class="amt">' + (v !== null ? formatAmount(v) : '-') + '</td>';
                    }).join('');
                    return '<tr><td>' + escapeHtml(row.name || 'UNKNOWN BILLER') + '</td>' + yearCells + '<td class="amt total">' + formatAmount(row.total || 0) + '</td></tr>';
                }).join('');

                var footYearCells = years.map(function (y) {
                    var tv = totalsByYear[y] || 0;
                    return '<th class="amt">' + formatAmount(tv) + '</th>';
                }).join('');

                var blankCells = years.map(function () { return '<th></th>'; }).join('');
                var spanCols = 1 + years.length;

                container.innerHTML =
                    '<div style="margin-bottom:8px;font-weight:800;color:#111827;">' + escapeHtml(String((summary.partner_name || partnerId)).toUpperCase()) + ' SUB BILLERS</div>' +
                    '<div class="st-summary-table-wrap">' +
                        '<table class="st-summary-table">' +
                            colGroup +
                            '<thead><tr><th class="partner-col-head">' + escapeHtml(String((summary.partner_name || partnerId)).toUpperCase()) + '<br><span>SUB BILLERS</span></th>' + headCols + '<th>Total Receivable</th></tr></thead>' +
                            '<tbody>' + bodyRows + '</tbody>' +
                            '<tfoot>' +
                                '<tr><th></th>' + footYearCells + '<th class="amt overall-total">' + formatAmount(summary.grand_total || 0) + '</th></tr>' +
                                '<tr class="spacer-row"><th colspan="' + spanCols + '"></th><th></th></tr>' +
                                '<tr>' + blankCells + '<th class="grand-label">Grand Total</th><th class="amt grand-total">' + formatAmount(summary.grand_total || 0) + '</th></tr>' +
                            '</tfoot>' +
                        '</table>' +
                    '</div>';
            }

            function initReceivableModal() {
                var select = document.getElementById('stReceivablePartnerSelect');
                if (!select) return;

                var opts = ['<option value="">Select Partner</option>'];
                Object.keys(partnerSummaryData).forEach(function (pid) {
                    var label = (partnerSummaryData[pid] && partnerSummaryData[pid].partner_name) ? partnerSummaryData[pid].partner_name : pid;
                    opts.push('<option value="' + escapeHtml(pid) + '">' + escapeHtml(label) + '</option>');
                });
                select.innerHTML = opts.join('');

                select.addEventListener('change', function () {
                    renderReceivableSummary(select.value);
                });
            }

            function openModalForAction(action) {
                if (action === 'mode-open') return switchMode('open');
                if (action === 'mode-active') return switchMode('active');
                if (action === 'mode-closed') return switchMode('closed');

                if (action === 'modal-close-rate') {
                    openStatsModal('stCloseRateModal');
                    buildCloseRateChart();
                    return;
                }
                if (action === 'modal-aging') {
                    openStatsModal('stAgingModal');
                    buildAgingChart();
                    return;
                }
                if (action === 'modal-receivable') {
                    openStatsModal('stReceivableModal');
                    var select = document.getElementById('stReceivablePartnerSelect');
                    if (select && !select.value) {
                        var firstPartner = Object.keys(partnerSummaryData)[0] || '';
                        select.value = firstPartner;
                    }
                    if (select) renderReceivableSummary(select.value);
                    return;
                }
                if (action === 'modal-type') {
                    openStatsModal('stTypeModal');
                    buildTypeChart();
                    return;
                }
                if (action === 'modal-handler') {
                    openStatsModal('stHandlerModal');
                    buildHandlerChart();
                }
            }

            var autoOpenModalId = <?php echo json_encode($autoOpenModalId); ?>;
            if (autoOpenModalId) {
                var target = document.getElementById(autoOpenModalId);
                if (target) {
                    target.classList.add('open');
                    var body = target.querySelector('.tm-body');
                    if (body) body.scrollTop = body.scrollHeight;
                }
            }

            initReceivableModal();

            document.querySelectorAll('.st-report-stat.is-clickable').forEach(function (card) {
                card.addEventListener('click', function () {
                    var action = card.getAttribute('data-stat-action');
                    if (action) openModalForAction(action);
                });
            });

            document.querySelectorAll('[data-st-stats-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    closeStatsModal(btn.getAttribute('data-st-stats-close'));
                });
            });

            document.querySelectorAll('.st-stats-modal-overlay').forEach(function (overlay) {
                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) {
                        overlay.classList.remove('open');
                        overlay.setAttribute('aria-hidden', 'true');
                    }
                });
            });

            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                document.querySelectorAll('.st-stats-modal-overlay.open').forEach(function (overlay) {
                    overlay.classList.remove('open');
                    overlay.setAttribute('aria-hidden', 'true');
                });
            });

            var notFoundOverlay = document.getElementById('stReportNotFoundModal');
            var notFoundClose = document.getElementById('stReportNotFoundClose');
            if (notFoundOverlay && notFoundClose) {
                notFoundClose.addEventListener('click', function () {
                    notFoundOverlay.style.display = 'none';
                    notFoundOverlay.setAttribute('aria-hidden', 'true');
                });
                notFoundOverlay.addEventListener('click', function (e) {
                    if (e.target === notFoundOverlay) {
                        notFoundOverlay.style.display = 'none';
                        notFoundOverlay.setAttribute('aria-hidden', 'true');
                    }
                });
            }
        })();
    </script>
</body>
</html>
