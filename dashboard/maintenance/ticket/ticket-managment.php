<?php
include_once __DIR__ . '/../../support_ticket/includes/bootstrap.php';
include_once __DIR__ . '/../../support_ticket/includes/ticket_queries.php';
include_once __DIR__ . '/../../support_ticket/includes/ticket-report.php';

global $conn;

st_require_login('../../../login_form.php');
st_require_permission_page(['Support Ticket Report', 'Maintenance Support Ticket'], '../../home.php');

$flash = st_flash_get('maintenance_ticket');
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'open')));
if (!in_array($mode, ['open', 'active', 'closed'], true)) {
    $mode = 'open';
}

function st_trail_role_icon_asset_maintenance($role)
{
    $r = strtoupper(trim((string) $role));
    if ($r === 'BRANCH') return '../../../assets/images/icons/branch-icon.svg';
    if ($r === 'VPO') return '../../../assets/images/icons/vpo-icon.svg';
    if ($r === 'CAD') return '../../../assets/images/icons/cad-icon.svg';
    return '';
}

$ticketSearchInput = trim((string) ($_GET['ticket_search'] ?? ''));
$ticketSearchNormalized = strtoupper($ticketSearchInput);
$autoOpenModalId = '';
$searchNotFound = false;

$allTickets = st_get_report_tickets($conn);
[$openTickets, $activeTickets, $closedTickets] = st_partition_report_tickets($allTickets);
// Counts for mode badges
$openCount = count($openTickets);
$activeCount = count($activeTickets);
$closedCount = count($closedTickets);

if ($ticketSearchNormalized !== '') {
    $ticketFoundId = 0;
    foreach ($openTickets as $t) {
        if (strtoupper(trim((string) ($t['ticket_number'] ?? ''))) === $ticketSearchNormalized) {
            $ticketFoundId = (int) ($t['id'] ?? 0);
            break;
        }
    }

    if ($ticketFoundId === 0) {
        foreach ($activeTickets as $t) {
            if (strtoupper(trim((string) ($t['ticket_number'] ?? ''))) === $ticketSearchNormalized) {
                $ticketFoundId = (int) ($t['id'] ?? 0);
                break;
            }
        }
    }

    if ($ticketFoundId === 0) {
        foreach ($closedTickets as $t) {
            if (strtoupper(trim((string) ($t['ticket_number'] ?? ''))) === $ticketSearchNormalized) {
                $ticketFoundId = (int) ($t['id'] ?? 0);
                break;
            }
        }
    }

    if ($ticketFoundId > 0) {
        $autoOpenModalId = 'stTicketTrailModalMaintenance-' . $ticketFoundId;
    } else {
        $searchNotFound = true;
    }
}

// Load branches from masterdata for filter dropdown (label=name, value=id)
$branches = [];
$branchSql = "SELECT branch_id, branch_name FROM masterdata.branch_profile WHERE branch_name IS NOT NULL AND TRIM(branch_name) <> '' ORDER BY branch_name ASC";
$branchRes = $conn->query($branchSql);
if ($branchRes) {
    while ($br = $branchRes->fetch_assoc()) {
        $branches[] = $br;
    }
}

$filterStatuses = [];
$filterTypes = [];
$filterSources = [];

foreach ($allTickets as $ticket) {
    $s = trim((string) ($ticket['status'] ?? ''));
    if ($s !== '') $filterStatuses[$s] = true;

    $t = trim((string) (($ticket['ticket_type_label'] ?: $ticket['type_of_request']) ?? ''));
    if ($t !== '') $filterTypes[$t] = true;

    $src = trim((string) ($ticket['source'] ?? ''));
    if ($src !== '') $filterSources[$src] = true;
}

$filterStatuses = array_keys($filterStatuses);
$filterTypes = array_keys($filterTypes);
$filterSources = array_keys($filterSources);
sort($filterStatuses, SORT_NATURAL | SORT_FLAG_CASE);
sort($filterTypes, SORT_NATURAL | SORT_FLAG_CASE);
sort($filterSources, SORT_NATURAL | SORT_FLAG_CASE);

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support Ticket - Tickets (Maintenance)</title>
    <link rel="icon" href="../../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../support_ticket/assets/css/support-ticket.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../support_ticket/assets/css/ticket-modal.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../support_ticket/assets/css/image-preview.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <style>
        .st-panel-filters {
            margin-bottom: 12px;
        }

        .st-search-wrap--panel {
            margin-bottom: 8px;
        }
   .st-search-wrap {
    display: flex;
    width: 100%;               /* ensure full container width */
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
}

.st-search-wrap input#stGlobalTicketSearch {
    flex: 1;                   /* takes all remaining space */
    min-width: 0;              /* override any min-width that prevents shrinking */
    width: auto;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 13px;
    background: #fff;
    color: #111827;
}

.st-search-wrap button {
    flex-shrink: 0;            /* button stays at its natural width */
    border: 0;
    background: #dc3545;
    color: #fff;
    border-radius: 8px;
    padding: 8px 12px;
    font-weight: 700;
    cursor: pointer;
}
/* Search button hover */
.st-search-wrap button:hover {
    background: #b02a37;  /* darker red */
    transform: scale(1.02);
    transition: all 0.2s ease;
    cursor: pointer;
}

/* Clear Filters button hover */
.st-clear-filters:hover {
    background: #b02a37;
    transform: scale(1.02);
    transition: all 0.2s ease;
    cursor: pointer;
}
        /* Clear link removed — no styles required */
        @media (max-width: 760px) {
            .st-search-wrap { flex-direction: column; align-items: stretch; gap:6px; }
            .st-search-wrap input#stGlobalTicketSearch { width: 100%; }
        }

        .st-filter-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(140px, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }
        .st-filter-item.st-filter-actions-grid {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        .st-filter-item.st-filter-actions-grid label {
            visibility: hidden;
            height: 0;
            margin: 0;
            padding: 0;
        }
        .st-clear-filters {
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .st-filter-grid .st-filter-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .st-filter-grid label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .st-filter-grid input,
        .st-filter-grid select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            background: #fff;
        }
        .st-filter-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
        }
        .st-filter-actions button {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .st-search-feedback {
            margin-bottom: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }
        .st-search-feedback--ok {
            background: #ecfdf3;
            border: 1px solid #86efac;
            color: #166534;
        }
        .st-search-feedback--error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }
        @media (max-width: 1200px) {
            .st-filter-grid { grid-template-columns: repeat(3, minmax(160px, 1fr)); }
        }
        @media (max-width: 768px) {
            .st-filter-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include __DIR__ . '/../../../templates/header_ui.php'; ?>
        <?php include __DIR__ . '/../../../templates/sidebar.php'; ?>

        <?php if (function_exists('bp_section_header_html')): ?>
            <?php bp_section_header_html('fa-solid fa-ticket-simple', 'Support Ticket - Tickets', 'Maintenance - Delete Ticket'); ?>
        <?php else: ?>
            <div class="container-fluid mt-3"><h3>Support Ticket - Tickets</h3></div>
        <?php endif; ?>

        <div class="container-fluid st-wrapper">

            <form class="st-search-wrap" id="stGlobalTicketSearchForm" method="get" action="">
                <input type="text" id="stGlobalTicketSearch" name="ticket_search" value="<?php echo htmlspecialchars($ticketSearchInput); ?>" placeholder="Search Ticket Number">
                <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search Ticket</button>
            </form>

            <?php if ($ticketSearchInput !== ''): ?>
                <script>
                    window.supportTicketInitialFlash = <?php echo json_encode([ 'type' => ($searchNotFound ? 'danger' : 'success'), 'message' => ($searchNotFound ? ('Ticket ' . $ticketSearchInput . ' was not found.') : ('Ticket ' . $ticketSearchInput . ' found.')) ]); ?>;
                    window.supportTicketAutoOpenModal = <?php echo json_encode($autoOpenModalId); ?>;
                </script>
            <?php endif; ?>

            <div class="mode-cards" data-st-mode-group data-st-param="mode">
                <label class="mode-card <?php echo $mode === 'open' ? 'selected' : ''; ?>" data-mode="open">
                    <input type="radio" name="maintMode" value="open" <?php echo $mode === 'open' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-inbox"></i></div>
                    <div class="mode-text"><p class="mode-label">OPEN</p><small>Unresolved queue</small></div>
                    <?php if (!empty($openCount)): ?><span class="st-mode-count-badge"><?php echo (int) $openCount; ?></span><?php endif; ?>
                </label>

                <label class="mode-card <?php echo $mode === 'active' ? 'selected' : ''; ?>" data-mode="active">
                    <input type="radio" name="maintMode" value="active" <?php echo $mode === 'active' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-bolt"></i></div>
                    <div class="mode-text"><p class="mode-label">ACTIVE</p><small>In-progress tickets</small></div>
                    <?php if (!empty($activeCount)): ?><span class="st-mode-count-badge"><?php echo (int) $activeCount; ?></span><?php endif; ?>
                </label>

                <label class="mode-card <?php echo $mode === 'closed' ? 'selected' : ''; ?>" data-mode="closed">
                    <input type="radio" name="maintMode" value="closed" <?php echo $mode === 'closed' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-box-archive"></i></div>
                    <div class="mode-text"><p class="mode-label">CLOSED</p><small>Resolved and closed</small></div>
                    <?php if (!empty($closedCount)): ?><span class="st-mode-count-badge"><?php echo (int) $closedCount; ?></span><?php endif; ?>
                </label>
            </div>

            <div class="mode-panel <?php echo $mode === 'open' ? '' : 'hidden'; ?>" data-st-panel="open">
                <?php if (empty($openTickets)): ?>
                    <div class="st-empty">No open tickets available.</div>
                <?php else: ?>
                    <div class="st-panel-filters" data-filter-panel="open">
                        <div class="st-filter-grid" aria-label="Open ticket filters">
                            <div class="st-filter-item">
                                <label>Date From</label>
                                <input type="date" data-filter-date-from>
                            </div>
                            <div class="st-filter-item">
                                <label>Date To</label>
                                <input type="date" data-filter-date-to>
                            </div>
                            <div class="st-filter-item">
                                <label>Payment Branch</label>
                                <select data-filter-branch-id>
                                    <option value="">All</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo htmlspecialchars((string) $branch['branch_id']); ?>"><?php echo htmlspecialchars((string) $branch['branch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="st-filter-item">
                                <label>Request Type</label>
                                <select data-filter-request-type>
                                    <option value="">All</option>
                                    <?php foreach ($filterTypes as $typeOpt): ?>
                                        <option value="<?php echo htmlspecialchars($typeOpt); ?>"><?php echo htmlspecialchars($typeOpt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="st-filter-item">
                                <label>Source</label>
                                <select data-filter-source>
                                    <option value="">All</option>
                                    <?php foreach ($filterSources as $sourceOpt): ?>
                                        <option value="<?php echo htmlspecialchars($sourceOpt); ?>"><?php echo htmlspecialchars($sourceOpt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="st-filter-item st-filter-actions-grid">
                                <label>&nbsp;</label>
                                <div style="display:flex;justify-content:flex-end;">
                                    <button type="button" data-filter-reset class="st-clear-filters">Clear Filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="st-ticket-table" role="table" aria-label="Open support ticket maintenance table">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($openTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalMaintenance-<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" data-created-at="<?php echo htmlspecialchars((string) $ticket['created_at']); ?>" data-status="<?php echo htmlspecialchars((string) ($ticket['status'] ?? '')); ?>" data-payment-branch-id="<?php echo htmlspecialchars((string) ($ticket['payment_branch_id'] ?? '')); ?>" data-request-type="<?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?>" data-source="<?php echo htmlspecialchars((string) ($ticket['source'] ?? '')); ?>">
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) $ticket['created_at']); ?></span>
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
                    <div class="st-panel-filters" data-filter-panel="active">
                        <div class="st-filter-grid" aria-label="Active ticket filters">
                            <div class="st-filter-item"><label>Date From</label><input type="date" data-filter-date-from></div>
                            <div class="st-filter-item"><label>Date To</label><input type="date" data-filter-date-to></div>
                            <div class="st-filter-item"><label>Payment Branch</label><select data-filter-branch-id><option value="">All</option><?php foreach ($branches as $branch): ?><option value="<?php echo htmlspecialchars((string) $branch['branch_id']); ?>"><?php echo htmlspecialchars((string) $branch['branch_name']); ?></option><?php endforeach; ?></select></div>
                            <div class="st-filter-item"><label>Request Type</label><select data-filter-request-type><option value="">All</option><?php foreach ($filterTypes as $typeOpt): ?><option value="<?php echo htmlspecialchars($typeOpt); ?>"><?php echo htmlspecialchars($typeOpt); ?></option><?php endforeach; ?></select></div>
                            <div class="st-filter-item"><label>Source</label><select data-filter-source><option value="">All</option><?php foreach ($filterSources as $sourceOpt): ?><option value="<?php echo htmlspecialchars($sourceOpt); ?>"><?php echo htmlspecialchars($sourceOpt); ?></option><?php endforeach; ?></select></div>
                            <div class="st-filter-item st-filter-actions-grid"><label>&nbsp;</label><div style="display:flex;justify-content:flex-end;"><button type="button" data-filter-reset class="st-clear-filters">Clear Filters</button></div></div>
                        </div>
                    </div>
                    <div class="st-ticket-table" role="table" aria-label="Active support ticket maintenance table">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($activeTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalMaintenance-<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" data-created-at="<?php echo htmlspecialchars((string) $ticket['created_at']); ?>" data-status="<?php echo htmlspecialchars((string) ($ticket['status'] ?? '')); ?>" data-payment-branch-id="<?php echo htmlspecialchars((string) ($ticket['payment_branch_id'] ?? '')); ?>" data-request-type="<?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?>" data-source="<?php echo htmlspecialchars((string) ($ticket['source'] ?? '')); ?>">
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) $ticket['created_at']); ?></span>
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
                    <div class="st-panel-filters" data-filter-panel="closed">
                        <div class="st-filter-grid" aria-label="Closed ticket filters">
                            <div class="st-filter-item"><label>Date From</label><input type="date" data-filter-date-from></div>
                            <div class="st-filter-item"><label>Date To</label><input type="date" data-filter-date-to></div>
                            <div class="st-filter-item"><label>Payment Branch</label><select data-filter-branch-id><option value="">All</option><?php foreach ($branches as $branch): ?><option value="<?php echo htmlspecialchars((string) $branch['branch_id']); ?>"><?php echo htmlspecialchars((string) $branch['branch_name']); ?></option><?php endforeach; ?></select></div>
                            <div class="st-filter-item"><label>Request Type</label><select data-filter-request-type><option value="">All</option><?php foreach ($filterTypes as $typeOpt): ?><option value="<?php echo htmlspecialchars($typeOpt); ?>"><?php echo htmlspecialchars($typeOpt); ?></option><?php endforeach; ?></select></div>
                            <div class="st-filter-item"><label>Source</label><select data-filter-source><option value="">All</option><?php foreach ($filterSources as $sourceOpt): ?><option value="<?php echo htmlspecialchars($sourceOpt); ?>"><?php echo htmlspecialchars($sourceOpt); ?></option><?php endforeach; ?></select></div>
                            <div class="st-filter-item st-filter-actions-grid"><label>&nbsp;</label><div style="display:flex;justify-content:flex-end;"><button type="button" data-filter-reset class="st-clear-filters">Clear Filters</button></div></div>
                        </div>
                    </div>
                    <div class="st-ticket-table" role="table" aria-label="Closed support ticket maintenance table">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($closedTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalMaintenance-<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" data-created-at="<?php echo htmlspecialchars((string) ($ticket['closed_at'] ?: $ticket['created_at'])); ?>" data-status="<?php echo htmlspecialchars((string) ($ticket['status'] ?? '')); ?>" data-payment-branch-id="<?php echo htmlspecialchars((string) ($ticket['payment_branch_id'] ?? '')); ?>" data-request-type="<?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?>" data-source="<?php echo htmlspecialchars((string) ($ticket['source'] ?? '')); ?>">
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) ($ticket['closed_at'] ?: $ticket['created_at'])); ?></span>
                                <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
                                <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_partner_name_report($ticket)); ?></span>
                                <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_report($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

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
                <div class="tm-overlay" id="stTicketTrailModalMaintenance-<?php echo $ticketId; ?>" aria-hidden="true" role="dialog" aria-modal="true">
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
                                            <button type="button" class="tm-close-btn" data-st-close-modal="stTicketTrailModalMaintenance-<?php echo $ticketId; ?>" aria-label="Close">&times;</button>
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
                                            $trailIconAsset = st_trail_role_icon_asset_maintenance($trailRole);
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
                                                                <a class="tm-attachment" href="../../support_ticket/controllers/attachments/download.php?id=<?php echo (int) ($att['id'] ?? 0); ?>">
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

                        <div class="tm-footer tm-footer--open">
                            <div class="tm-footer-inner" style="display:block;">
                                <div style="margin-top:4px;display:flex;justify-content:flex-end;gap:8px;">
                                    <?php if ($statusLower === 'resolved'): ?>
                                        <button type="button" class="tm-btn tm-btn--transfer" data-reopen-picker-open="stReopenPickerMaint-<?php echo $ticketId; ?>"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Open Ticket</button>
                                    <?php endif; ?>
                                    <button type="button" class="tm-btn tm-btn--danger tm-btn-close-ticket" data-close-picker-open="stDeletePickerMaint-<?php echo $ticketId; ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete Ticket</button>
                                </div>

                                <div class="tm-submodal-overlay" id="stDeletePickerMaint-<?php echo $ticketId; ?>" style="display:none;" aria-hidden="true">
                                    <div class="tm-submodal" role="dialog" aria-modal="true" aria-label="Delete ticket confirmation">
                                        <div class="tm-submodal-title">Delete Ticket</div>
                                        <div class="tm-submodal-ticket-info">Are you sure you want to permanently delete Ticket <?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>?</div>
                                        <hr class="tm-submodal-divider">
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                                            <form method="post" action="../controllers/delete-ticket.php">
                                                <input type="hidden" name="ticket_id" value="<?php echo $ticketId; ?>">
                                                <input type="hidden" name="return_mode" value="<?php echo htmlspecialchars($mode); ?>">
                                                <button class="tm-btn tm-btn--danger" type="submit">Yes, Delete</button>
                                            </form>
                                        </div>
                                        <div class="tm-submodal-footer" style="margin-top:10px;">
                                            <button type="button" class="tm-btn tm-btn--outline" data-close-picker-cancel="stDeletePickerMaint-<?php echo $ticketId; ?>">Cancel</button>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($statusLower === 'resolved'): ?>
                                <div class="tm-submodal-overlay" id="stReopenPickerMaint-<?php echo $ticketId; ?>" style="display:none;" aria-hidden="true">
                                    <div class="tm-submodal" role="dialog" aria-modal="true" aria-label="Re-open ticket options">
                                        <div class="tm-submodal-title">Re-open Ticket</div>
                                        <div class="tm-submodal-ticket-info">Choose where to re-open Ticket <?php echo htmlspecialchars((string) $ticket['ticket_number']); ?></div>
                                        <hr class="tm-submodal-divider">
                                        <div class="tm-reopen-grid" style="margin-bottom:8px;">
                                            <button type="button" class="tm-reopen-option tm-btn" data-reopen-target="VPO" data-reopen-ticket-id="<?php echo $ticketId; ?>">
                                                <div class="tm-reopen-option-title">Open to VPO</div>
                                                <div class="tm-reopen-option-desc">Assigns ticket back to the VPO team</div>
                                            </button>
                                            <button type="button" class="tm-reopen-option tm-btn" data-reopen-target="CAD" data-reopen-ticket-id="<?php echo $ticketId; ?>">
                                                <div class="tm-reopen-option-title">Open to CAD</div>
                                                <div class="tm-reopen-option-desc">Assigns ticket back to the CAD team</div>
                                            </button>
                                        </div>
                                        <div class="tm-submodal-footer tm-submodal-footer--center" style="margin-top:4px;">
                                            <button type="button" class="tm-btn tm-btn--outline" data-reopen-picker-cancel="stReopenPickerMaint-<?php echo $ticketId; ?>">Cancel</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Confirmation overlays for the two reopen targets -->
                                <div class="tm-submodal-overlay" id="stReopenConfirmMaint-<?php echo $ticketId; ?>-VPO" style="display:none;" aria-hidden="true">
                                    <div class="tm-submodal" role="dialog" aria-modal="true" aria-label="Confirm reopen to VPO">
                                        <div class="tm-submodal-title">Confirm Re-open to VPO</div>
                                        <div class="tm-submodal-ticket-info">Re-open Ticket <?php echo htmlspecialchars((string) $ticket['ticket_number']); ?> to VPO?</div>
                                        <hr class="tm-submodal-divider">
                                        <form method="post" action="../controllers/reopen-ticket.php" class="tm-submodal-form">
                                            <input type="hidden" name="ticket_id" value="<?php echo $ticketId; ?>">
                                            <input type="hidden" name="target" value="VPO">
                                            <input type="hidden" name="return_mode" value="<?php echo htmlspecialchars($mode); ?>">
                                            <div class="tm-submodal-footer tm-submodal-footer--confirm" style="margin-top:6px;">
                                                <button type="button" class="tm-btn tm-btn--outline" data-reopen-confirm-cancel="stReopenConfirmMaint-<?php echo $ticketId; ?>-VPO">Cancel</button>
                                                <button class="tm-btn tm-btn--transfer" type="submit">Confirm</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <div class="tm-submodal-overlay" id="stReopenConfirmMaint-<?php echo $ticketId; ?>-CAD" style="display:none;" aria-hidden="true">
                                    <div class="tm-submodal" role="dialog" aria-modal="true" aria-label="Confirm reopen to CAD">
                                        <div class="tm-submodal-title">Confirm Re-open to CAD</div>
                                        <div class="tm-submodal-ticket-info">Re-open Ticket <?php echo htmlspecialchars((string) $ticket['ticket_number']); ?> to CAD?</div>
                                        <hr class="tm-submodal-divider">
                                        <form method="post" action="../controllers/reopen-ticket.php" class="tm-submodal-form">
                                            <input type="hidden" name="ticket_id" value="<?php echo $ticketId; ?>">
                                            <input type="hidden" name="target" value="CAD">
                                            <input type="hidden" name="return_mode" value="<?php echo htmlspecialchars($mode); ?>">
                                            <div class="tm-submodal-footer tm-submodal-footer--confirm" style="margin-top:6px;">
                                                <button type="button" class="tm-btn tm-btn--outline" data-reopen-confirm-cancel="stReopenConfirmMaint-<?php echo $ticketId; ?>-CAD">Cancel</button>
                                                <button class="tm-btn tm-btn--transfer" type="submit">Confirm</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php include __DIR__ . '/../../../templates/footer.php'; ?>
    </div>

    <?php if ($flash): ?>
    <script>
        window.supportTicketInitialFlash = <?php echo json_encode(['type' => (string) ($flash['type'] ?? 'success'), 'message' => (string) ($flash['message'] ?? '')]); ?>;
    </script>
    <?php endif; ?>

    <script>
        window.supportTicketLiveUpdates = {
            endpoint: '../../support_ticket/controllers/poll/live-updates.php',
            scope: 'MAINT',
            intervalMs: 5000
        };
    </script>

    <script src="../../support_ticket/assets/js/support-ticket-ui.js?v=<?php echo time(); ?>"></script>
    <script>
        (function () {
            function text(v) {
                return String(v || '').trim().toLowerCase();
            }

            function rowDate(row) {
                var raw = String(row.getAttribute('data-created-at') || '');
                if (!raw) return '';
                var d = new Date(raw.replace(' ', 'T'));
                if (isNaN(d.getTime())) {
                    return raw.slice(0, 10);
                }
                var m = String(d.getMonth() + 1).padStart(2, '0');
                var day = String(d.getDate()).padStart(2, '0');
                return d.getFullYear() + '-' + m + '-' + day;
            }

            function applyPanelFilters(panel) {
                if (!panel) return;

                var dateFrom = panel.querySelector('[data-filter-date-from]') ? panel.querySelector('[data-filter-date-from]').value : '';
                var dateTo = panel.querySelector('[data-filter-date-to]') ? panel.querySelector('[data-filter-date-to]').value : '';
                var status = text(panel.querySelector('[data-filter-status]') ? panel.querySelector('[data-filter-status]').value : '');
                var branchId = text(panel.querySelector('[data-filter-branch-id]') ? panel.querySelector('[data-filter-branch-id]').value : '');
                var requestType = text(panel.querySelector('[data-filter-request-type]') ? panel.querySelector('[data-filter-request-type]').value : '');
                var source = text(panel.querySelector('[data-filter-source]') ? panel.querySelector('[data-filter-source]').value : '');

                var rows = panel.querySelectorAll('.st-ticket-row[data-ticket-modal]');
                var visibleCount = 0;

                rows.forEach(function (row) {
                    var ticketNo = text(row.getAttribute('data-ticket-number'));
                    var rowStatus = text(row.getAttribute('data-status'));
                    var rowBranchId = text(row.getAttribute('data-payment-branch-id'));
                    var rowType = text(row.getAttribute('data-request-type'));
                    var rowSource = text(row.getAttribute('data-source'));
                    var rowDay = rowDate(row);

                    var ok = true;
                    if (status && rowStatus !== status) ok = false;
                    if (branchId && rowBranchId !== branchId) ok = false;
                    if (requestType && rowType !== requestType) ok = false;
                    if (source && rowSource !== source) ok = false;
                    if (dateFrom && rowDay && rowDay < dateFrom) ok = false;
                    if (dateTo && rowDay && rowDay > dateTo) ok = false;

                    row.style.display = ok ? '' : 'none';
                    if (ok) visibleCount++;
                });

                var table = panel.querySelector('.st-ticket-table');
                if (!table) return;

                var empty = panel.querySelector('.st-empty[data-dynamic-empty="1"]');
                if (!empty) {
                    empty = document.createElement('div');
                    empty.className = 'st-empty';
                    empty.setAttribute('data-dynamic-empty', '1');
                    empty.textContent = 'No tickets match the current filters.';
                    empty.style.display = 'none';
                    panel.appendChild(empty);
                }

                if (visibleCount === 0) {
                    table.style.display = 'none';
                    empty.style.display = 'block';
                } else {
                    table.style.display = '';
                    empty.style.display = 'none';
                }
            }

            function applyAllPanels() {
                document.querySelectorAll('[data-st-panel]').forEach(function (panel) {
                    applyPanelFilters(panel);
                });
            }

            function bindPanelFilters(panel) {
                if (!panel) return;
                var resetBtn = panel.querySelector('[data-filter-reset]');
                if (resetBtn) {
                    resetBtn.addEventListener('click', function () {
                        var nodes = panel.querySelectorAll('[data-filter-date-from], [data-filter-date-to], [data-filter-status], [data-filter-branch-id], [data-filter-request-type], [data-filter-source]');
                        nodes.forEach(function (node) {
                            node.value = '';
                        });
                        applyPanelFilters(panel);
                    });
                }

                var reactiveNodes = panel.querySelectorAll('[data-filter-date-from], [data-filter-date-to], [data-filter-status], [data-filter-branch-id], [data-filter-request-type], [data-filter-source]');
                reactiveNodes.forEach(function (node) {
                    node.addEventListener('change', function () {
                        applyPanelFilters(panel);
                    });
                });

                applyPanelFilters(panel);
            }

            document.querySelectorAll('[data-st-panel]').forEach(function (panel) {
                bindPanelFilters(panel);
            });
        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var autoId = window.supportTicketAutoOpenModal || '';
            if (!autoId) return;
            var target = document.getElementById(autoId);
            if (!target) return;
            target.classList.add('open');
            var latestCard = target.querySelector('.tm-trail-card[data-tm-latest]');
            if (latestCard) latestCard.classList.add('tm-expanded');
            var body = target.querySelector('.tm-body');
            if (body) body.scrollTop = body.scrollHeight;
        });
    </script>
</body>
</html>
