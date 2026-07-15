<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/includes/ticket_queries.php';

global $conn;

st_require_login('../../login_form.php');
st_require_permission_page(['Support Ticket Create'], '../home.php');

$userId = st_user_id_or_null();
$ticketTypes = st_get_ticket_types($conn);
$subbillers = st_get_subbillers($conn, 2500);
// Load branches for Payment Branch dropdown (masterdata.branch_profile)
$branches = [];
$branchSql = "SELECT branch_id, branch_name FROM masterdata.branch_profile WHERE branch_name IS NOT NULL AND TRIM(branch_name) <> '' ORDER BY branch_name ASC";
$branchRes = $conn->query($branchSql);
if ($branchRes) {
    while ($br = $branchRes->fetch_assoc()) {
        $branches[] = $br;
    }
}
$flash = st_flash_get('create_ticket');
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'open')));
if ($mode !== 'open' && $mode !== 'closed') {
    $mode = 'open';
}

$branchTickets = [];
$openTickets = [];
$closedTickets = [];
if ($userId !== null) {
    $branchTickets = st_get_branch_tickets($conn, $userId);
}

foreach ($branchTickets as $ticket) {
    $statusLower = strtolower((string) ($ticket['status'] ?? ''));
    if (in_array($statusLower, ['resolved', 'closed'], true)) {
        $closedTickets[] = $ticket;
    } else {
        $openTickets[] = $ticket;
    }
}

function st_status_class_branch($status)
{
    return 'st-status st-status-' . strtolower((string) $status);
}

function st_card_partner_name($ticket)
{
    $partner = trim((string) ($ticket['partner_name'] ?? ''));
    if ($partner !== '') {
        return $partner;
    }
    $ext = trim((string) ($ticket['partner_ext_id'] ?? ''));
    return $ext !== '' ? $ext : 'N/A';
}

function st_trail_type_label($type)
{
    $t = strtolower(trim((string) $type));
    if ($t === 'accept') return 'Accepted';
    if ($t === 'transfer') return 'Transferred';
    if ($t === 'resolve') return 'Resolved';
    if ($t === 'close') return 'Closed';
    if ($t === 'auto_close') return 'Auto Closed';
    return 'Message';
}

function st_trail_role_icon_asset($role)
{
    $r = strtoupper(trim((string) $role));
    if ($r === 'BRANCH') return '../../assets/images/icons/branch-icon.svg';
    if ($r === 'VPO') return '../../assets/images/icons/vpo-icon.svg';
    if ($r === 'CAD') return '../../assets/images/icons/cad-icon.svg';
    return '';
}

function st_get_ticket_attachments_grouped_by_trail($conn, $ticketId)
{
    $schema = st_schema();
    $sql = "SELECT id, ticket_trail_id, file_name, mime_type, file_size, created_at
            FROM {$schema}.ticket_attachments
            WHERE ticket_id = ?
            ORDER BY id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $ticketId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    $grouped = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $trailId = (int) ($row['ticket_trail_id'] ?? 0);
            if ($trailId <= 0) {
                continue;
            }
            if (!isset($grouped[$trailId])) {
                $grouped[$trailId] = [];
            }
            $grouped[$trailId][] = $row;
        }
    }

    $stmt->close();
    return $grouped;
}

$ticketTrailsByTicketId = [];
$ticketAttachmentsByTicketId = [];
$ticketSupplementalByTicketNumber = [];
$ownerIds = [];
foreach ($branchTickets as $ticket) {
    $ticketId = (int) ($ticket['id'] ?? 0);
    if ($ticketId <= 0) {
        continue;
    }
    $ticketTrailsByTicketId[$ticketId] = st_get_ticket_trails($conn, $ticketId);
    $ticketAttachmentsByTicketId[$ticketId] = st_get_ticket_attachments_grouped_by_trail($conn, $ticketId);

    $ticketNumber = (string) ($ticket['ticket_number'] ?? '');
    if ($ticketNumber !== '') {
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

$ticketNumbersBranch = [];
foreach ($branchTickets as $ticket) {
    $tn = trim((string) ($ticket['ticket_number'] ?? ''));
    if ($tn !== '') {
        $ticketNumbersBranch[] = $tn;
    }
}
$ticketBadgeCountsBranch = st_get_ticket_badge_counts($conn, $ticketNumbersBranch, 'BRANCH');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support Ticket - Branch</title>
    <link rel="icon" href="../../images/MLW%20logo.png" type="image/png">
    <link rel="stylesheet" href="../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/support-ticket.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/ticket-modal.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/image-preview.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../trl/trl-entry/trl-entry.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../trl/trl-entry/components/trl-entry-auto.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../trl/trl-entry/components/trl-entry-manual.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../trl/trl-report/components/trl-report-subbillers.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <style>
        /* Keep branch close action visible in ticket modal header */
        .tm-header-branch-close {
            display: flex;
            justify-content: stretch;
            margin-top: 8px;
            width: 100%;
        }

        .tm-header-branch-close .tm-inline-form {
            display: flex;
            margin: 0;
            width: 100%;
            justify-content: stretch;
        }

        .tm-header-branch-close .tm-btn-close-ticket {
            width: 100%;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../templates/header_ui.php'; ?>
        <?php include '../../templates/sidebar.php'; ?>

        <?php if (function_exists('bp_section_header_html')): ?>
            <?php bp_section_header_html('fa-solid fa-ticket', 'Support Ticket', 'Create and Manage Ticket'); ?>
        <?php else: ?>
            <div class="container-fluid mt-3"><h3>Support Ticket - Branch</h3></div>
        <?php endif; ?>

        <div class="container-fluid st-wrapper">

            <div class="st-toolbar">
                <div class="st-small">Select mode to view your tickets.</div>
                <button type="button" class="btn btn-danger" id="stOpenCreateModal">
                    <i class="fa-solid fa-plus"></i> Create Ticket
                </button>
            </div>

            <div class="mode-cards" data-st-mode-group data-st-param="mode">
                <label class="mode-card <?php echo $mode === 'open' ? 'selected' : ''; ?>" data-mode="open">
                    <input type="radio" name="branchMode" value="open" <?php echo $mode === 'open' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-inbox"></i></div>
                    <div class="mode-text">
                        <p class="mode-label">OPEN</p>
                        <small>Active and resolving tickets</small>
                    </div>
                </label>

                <label class="mode-card <?php echo $mode === 'closed' ? 'selected' : ''; ?>" data-mode="closed">
                    <input type="radio" name="branchMode" value="closed" <?php echo $mode === 'closed' ? 'checked' : ''; ?>>
                    <div class="mode-icon"><i class="fa-solid fa-box-archive"></i></div>
                    <div class="mode-text">
                        <p class="mode-label">CLOSED</p>
                        <small>Completed tickets</small>
                    </div>
                </label>
            </div>

            <div class="mode-panel <?php echo $mode === 'open' ? '' : 'hidden'; ?>" data-st-panel="open">
                <?php if (empty($openTickets)): ?>
                    <div class="st-empty">No open tickets.</div>
                <?php else: ?>
                    <div class="st-ticket-table" role="table" aria-label="Open tickets">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($openTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModal-<?php echo (int) $ticket['id']; ?>" data-ticket-id="<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" data-seen-role="BRANCH">
                                <?php $branchUnread = (int) ($ticketBadgeCountsBranch[(string) ($ticket['ticket_number'] ?? '')] ?? 0); ?>
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?><?php if ($branchUnread > 0): ?> <span class="st-ticket-unread-badge"><?php echo $branchUnread; ?></span><?php endif; ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) $ticket['created_at']); ?></span>
                                <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
                                <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_card_partner_name($ticket)); ?></span>
                                <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_branch($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mode-panel <?php echo $mode === 'closed' ? '' : 'hidden'; ?>" data-st-panel="closed">
                <?php if (empty($closedTickets)): ?>
                    <div class="st-empty">No closed tickets.</div>
                <?php else: ?>
                    <div class="st-ticket-table" role="table" aria-label="Closed tickets">
                        <div class="st-ticket-row st-ticket-row-head" role="row">
                            <span class="st-ticket-col st-col-number">Ticket #</span>
                            <span class="st-ticket-col st-col-date">Created</span>
                            <span class="st-ticket-col st-col-type">Type</span>
                            <span class="st-ticket-col st-col-partner">Partner</span>
                            <span class="st-ticket-col st-col-status">Status</span>
                        </div>
                        <?php foreach ($closedTickets as $ticket): ?>
                            <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModal-<?php echo (int) $ticket['id']; ?>" data-ticket-id="<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" data-seen-role="BRANCH">
                                <?php $branchUnread = (int) ($ticketBadgeCountsBranch[(string) ($ticket['ticket_number'] ?? '')] ?? 0); ?>
                                <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?><?php if ($branchUnread > 0): ?> <span class="st-ticket-unread-badge"><?php echo $branchUnread; ?></span><?php endif; ?></span>
                                <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) $ticket['created_at']); ?></span>
                                <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
                                <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_card_partner_name($ticket)); ?></span>
                                <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_branch($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php foreach ($branchTickets as $ticket): ?>
                <?php
                    $ticketId = (int) ($ticket['id'] ?? 0);
                    $trails = $ticketTrailsByTicketId[$ticketId] ?? [];
                    $attachmentsByTrail = $ticketAttachmentsByTicketId[$ticketId] ?? [];
                    $ticketTypeText = (string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request']);
                    $ticketStatusLower = strtolower((string) ($ticket['status'] ?? ''));
                    $isClosed = $ticketStatusLower === 'closed';
                    $isOpen = $ticketStatusLower === 'open';
                    $ticketNumber = (string) ($ticket['ticket_number'] ?? '');
                    $ticketSupplemental = $ticketSupplementalByTicketNumber[$ticketNumber] ?? [];
                    $createdById = (int) ($ticket['created_by'] ?? 0);
                    $vpoOwnerId = (int) ($ticket['vpo_owner'] ?? 0);
                    $cadOwnerId = (int) ($ticket['cad_owner'] ?? 0);
                    $createdByName = $createdById > 0 ? ($ownerNamesById[$createdById] ?? ('ID ' . $createdById)) : '';
                    $vpoOwnerName = $vpoOwnerId > 0 ? ($ownerNamesById[$vpoOwnerId] ?? ('ID ' . $vpoOwnerId)) : '';
                    $cadOwnerName = $cadOwnerId > 0 ? ($ownerNamesById[$cadOwnerId] ?? ('ID ' . $cadOwnerId)) : '';

                    // Header meta values
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
                <div class="tm-overlay" id="stTicketTrailModal-<?php echo $ticketId; ?>" aria-hidden="true" role="dialog" aria-modal="true">
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
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Reference No.</div>
                                            <div class="tm-meta-value tm-meta-value--ref"><?php echo htmlspecialchars($hdrReference); ?></div>
                                        </div>
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Transaction D/T</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars($hdrTransfer); ?></div>
                                        </div>
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Account No.</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars($hdrAccount); ?></div>
                                        </div>
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Payment Branch</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars($hdrPaymentBranch); ?></div>
                                        </div>

                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Partner</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars(st_card_partner_name($ticket)); ?></div>
                                        </div>
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Type</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars($ticketTypeText); ?></div>
                                        </div>
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Source</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars((string) ($ticket['source'] ?? 'N/A')); ?></div>
                                        </div>
                                        <div class="tm-meta-item">
                                            <div class="tm-meta-label">Amount</div>
                                            <div class="tm-meta-value"><?php echo htmlspecialchars($hdrAmount); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tm-header-right">
                                        <div class="tm-header-actions tm-header-actions--card">
                                            <div class="tm-header-actions-top">
                                                <div class="tm-status tm-status--<?php echo htmlspecialchars($ticketStatusLower); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></div>
                                                <button type="button" class="tm-close-btn" data-st-close-modal="stTicketTrailModal-<?php echo $ticketId; ?>" aria-label="Close">&times;</button>
                                            </div>
                                        </div>
                                </div>
                            </div>

                            <?php if ($isOpen): ?>
                                <div class="tm-header-branch-close">
                                    <form id="stCloseForm-<?php echo $ticketId; ?>" method="post" action="controllers/branch/close-ticket.php" class="tm-inline-form">
                                        <input type="hidden" name="ticket_id" value="<?php echo $ticketId; ?>">
                                        <input type="hidden" name="return_mode" value="<?php echo htmlspecialchars($mode); ?>">
                                        <button type="button" class="tm-btn tm-btn--red tm-btn-close-ticket" data-confirm-transfer-open="stCloseConfirm-<?php echo $ticketId; ?>">Close Ticket</button>
                                    </form>
                                </div>

                                <div class="tm-submodal-overlay" id="stCloseConfirm-<?php echo $ticketId; ?>" style="display:none;" aria-hidden="true">
                                    <div class="tm-submodal" role="dialog" aria-modal="true" aria-label="Close ticket confirmation">
                                        <div class="tm-submodal-title">Close Ticket Immediately?</div>
                                        <div class="tm-submodal-ticket-info">Are you sure you want to close ticket <?php echo htmlspecialchars((string) $ticket['ticket_number']); ?> now?</div>
                                        <hr class="tm-submodal-divider">
                                        <div class="tm-submodal-footer">
                                            <button type="button" class="tm-btn tm-btn--outline" data-confirm-transfer-cancel="stCloseConfirm-<?php echo $ticketId; ?>">Cancel</button>
                                            <button type="button" class="tm-btn tm-btn--transfer" data-confirm-transfer-submit="stCloseConfirm-<?php echo $ticketId; ?>" data-transfer-form="stCloseForm-<?php echo $ticketId; ?>">Close Ticket</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
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
                                            else if ($trailRole === 'VPO') $avatarClass = 'tm-trail-avatar--vpo';
                                            else if ($trailRole === 'CAD') $avatarClass = 'tm-trail-avatar--cad';
                                            $trailIconAsset = st_trail_role_icon_asset($trailRole);
                                            $trailRoleClass = strtolower($trailRole);

                                            $trailOwnerTooltip = '';
                                            if ($trailRole === 'BRANCH') {
                                                $trailOwnerTooltip = $createdByName;
                                            } else if ($trailRole === 'VPO') {
                                                $trailOwnerTooltip = $vpoOwnerName;
                                            } else if ($trailRole === 'CAD') {
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
                                                    <div class="tm-trail-type-label tm-trail-type-label--<?php echo htmlspecialchars(strtolower($trailType)); ?>"><?php echo htmlspecialchars(st_trail_type_label($trailType)); ?></div>
                                                    <div class="tm-trail-chevron">›</div>
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
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--correct"><i class="fa-solid fa-check-circle" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Biller ID</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars($wb['correct_biller_id']); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if (!empty($wb) && !empty($wb['correct_biller_name'])): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--correct"><i class="fa-solid fa-check-circle" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Biller Name</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars($wb['correct_biller_name']); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>

                                                                    <div class="tm-ticket-details-col tm-ticket-details-col--right">
                                                                        <?php if ($wrongBillerId !== ''): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Biller ID</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerId); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if ($wrongBillerName !== ''): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Biller Name</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerName); ?></span>
                                                                            </div>
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
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--correct"><i class="fa-solid fa-check-circle" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Correct Amount</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars(number_format((float) $amountCorrect, 2)); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if ($amountWrong !== null): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Wrong Amount</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars(number_format((float) $amountWrong, 2)); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if ($amountDifference !== null): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon"><i class="fa-solid fa-equals" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Difference</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars(number_format((float) $amountDifference, 2)); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>

                                                                    <div class="tm-ticket-details-col tm-ticket-details-col--right">
                                                                        <?php if ($wrongBillerId !== ''): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Biller ID</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerId); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>

                                                                        <?php if ($wrongBillerName !== ''): ?>
                                                                            <div class="tm-ticket-detail">
                                                                                <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                                <span class="tm-detail-label">Biller Name</span>
                                                                                <span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerName); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>

                                                            <?php else: ?>
                                                                <div class="tm-ticket-details-col tm-ticket-details-col--left">
                                                                    <?php if ($wrongBillerId !== ''): ?>
                                                                        <div class="tm-ticket-detail">
                                                                            <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                            <span class="tm-detail-label">Biller ID</span>
                                                                            <span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerId); ?></span>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php if ($wrongBillerName !== ''): ?>
                                                                        <div class="tm-ticket-detail">
                                                                            <span class="tm-detail-icon tm-detail-icon--wrong"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>
                                                                            <span class="tm-detail-label">Biller Name</span>
                                                                            <span class="tm-detail-value"><?php echo htmlspecialchars($wrongBillerName); ?></span>
                                                                        </div>
                                                                    <?php endif; ?>
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

                        <?php if (!$isClosed): ?>
                            <form method="post" action="controllers/branch/reply-ticket.php" enctype="multipart/form-data">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticketId; ?>">
                                <input type="hidden" name="return_mode" value="<?php echo htmlspecialchars($mode); ?>">
                                <div class="tm-footer tm-footer--open">
                                    <div class="tm-footer-inner">
                                        <label class="tm-btn-attach" for="reply_attachments_<?php echo $ticketId; ?>" title="Attach files">
                                            <i class="fa-solid fa-paperclip"></i>
                                        </label>
                                        <input type="file" id="reply_attachments_<?php echo $ticketId; ?>" name="attachments[]" multiple style="display:none;">
                                        <div class="tm-reply-main">
                                            <textarea name="message" class="tm-textarea" placeholder="Type your reply..." required></textarea>
                                            <div class="tm-attach-preview" id="replyPreview_<?php echo $ticketId; ?>"></div>
                                        </div>
                                            <div>
                                            <button type="submit" class="tm-btn tm-btn--red">Submit</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="tm-footer tm-footer--closed">This ticket is already closed!</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Modal: Create Ticket -->
        <div class="st-modal-backdrop" id="createTicketModal">
            <div class="st-modal">
                <div class="st-modal-header">
                    <h5 class="mb-0">Create Ticket</h5>
                    <button type="button" class="st-modal-close" id="stCloseCreateModal" aria-label="Close">&times;</button>
                </div>

                <div class="st-modal-body">
                    <form id="stCreateTicketForm" method="post" action="controllers/branch/create-ticket.php" enctype="multipart/form-data" class="entry-form auto-entry-form manual-entry-form" novalidate>
                        <input type="hidden" name="return_mode" value="<?php echo htmlspecialchars($mode); ?>">
                        <input type="hidden" name="ticket_type_id" id="ticket_type_id" value="<?php echo isset($ticketTypes[0]) ? (int) $ticketTypes[0]['id'] : 1; ?>">

                        <div class="auto-content-grid">
                            <!-- Left column: Transaction Details -->
                            <div class="auto-data-column">
                                <div class="auto-data-header">
                                    <span class="material-icons">folder_open</span>
                                    <h3>Transaction Details</h3>
                                    <div class="manual-ref-toggle" style="margin-left:12px;">
                                        <div class="toggle-wrapper" style="display:flex;align-items:center;gap:8px;font-weight:600;">
                                            <span style="font-size:13px;color:#334155">Include Reference No.</span>
                                            <label class="switch" aria-label="Include Reference No.">
                                                <input id="mRefToggle" name="include_ref_no" type="checkbox" value="1" checked>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="auto-data-card">
                                    <div class="data-group group-1">
                                        <div class="data-item field-span-2" data-ref-group style="display:none;">
                                            <div class="data-icon"><span class="material-icons">tag</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Reference Number</span>
                                                <input id="reference_number" name="reference_number" class="data-value field-input" type="text" placeholder="Enter reference number">
                                            </div>
                                        </div>

                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">hub</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Source</span>
                                                <select id="source" name="source" class="data-value field-input required-field" required>
                                                    <option value="KPX" selected>KPX</option>
                                                    <option value="KP7">KP7</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">schedule</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Transaction D/T</span>
                                                <input id="transfer_datetime" name="transfer_datetime" class="data-value field-input required-field" type="datetime-local" required>
                                            </div>
                                        </div>

                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">account_balance</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Account Number</span>
                                                <input id="account_no" name="account_no" class="data-value field-input required-field" type="text" placeholder="Enter account number" required>
                                            </div>
                                        </div>

                                        <div class="data-item field-span-2">
                                            <div class="data-icon"><span class="material-icons">person</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Account Name</span>
                                                <input id="account_name" name="account_name" class="data-value field-input required-field" type="text" placeholder="Enter account name" required>
                                            </div>
                                        </div>

                                        
                                    </div>

                                    <div class="data-group group-2">
                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">store</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Payment Branch</span>
                                                <input id="payment_branch_input" name="payment_branch_name" class="data-value field-input required-field" list="paymentBranchDatalist" placeholder="Search branch or select..." required>
                                                <datalist id="paymentBranchDatalist">
                                                    <?php foreach ($branches as $b): ?>
                                                        <option value="<?php echo htmlspecialchars((string) $b['branch_name']); ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                            </div>
                                        </div>

                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">business</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Branch ID</span>
                                                <input id="payment_branch_id" name="payment_branch_id" class="data-value field-input required-field" type="text" placeholder="Branch ID" readonly required>
                                            </div>
                                        </div>

                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">warning</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Biller Name</span>
                                                <input id="subbiller_input" name="subbiller_name_display" class="data-value field-input required-field" list="subbillerDatalist" placeholder="Search subbiller or select...">
                                                <datalist id="subbillerDatalist">
                                                    <?php foreach ($subbillers as $sb): ?>
                                                        <option value="<?php echo htmlspecialchars((string) $sb['subbiller_name']); ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                            </div>
                                        </div>

                                        <div class="data-item">
                                            <div class="data-icon"><span class="material-icons">business</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Biller ID</span>
                                                <input id="biller_id" class="data-value field-input" type="text" placeholder="Biller ID" readonly>
                                                <input type="hidden" id="subbiller_ext_id" name="subbiller_ext_id">
                                                <input type="hidden" name="partner_ext_id" id="partner_ext_id">
                                            </div>
                                        </div>

                                        <div class="data-item field-span-2">
                                            <div class="data-icon"><span class="material-icons">attach_money</span></div>
                                            <div class="data-content">
                                                <span class="data-label">Amount</span>
                                                <input id="amount" name="amount" class="data-value field-input required-field currency-input" type="text" inputmode="decimal" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right column: Request Information -->
                            <div class="auto-input-column">
                                <div class="auto-input-header">
                                    <span class="material-icons">edit_note</span>
                                    <h3>Request Information</h3>
                                </div>
                                <div class="auto-input-card">
                                    <div class="field-group">
                                        <label for="type_of_request"><span class="material-icons">category</span> Type of Request</label>

                                        <div class="subbiller-dropdown type-dropdown" id="typeDropdown">
                                            <button type="button" id="typeToggle" class="subbiller-toggle type-toggle">Select request type <i class="fa-solid fa-caret-down" aria-hidden="true"></i></button>
                                            <div class="subbiller-list partner-list type-list" id="typeList" aria-hidden="true">
                                                <!-- items will be populated from the select by JS -->
                                            </div>
                                        </div>

                                        <select id="type_of_request" name="type_of_request" class="field-input required-field" required style="display:none;">
                                            <option value="">Select request type</option>
                                            <option value="NO PAYMENT RECEIVED">NO PAYMENT RECEIVED</option>
                                            <option value="DOUBLE POSTING">DOUBLE POSTING</option>
                                            <option value="MULTI POSTING">MULTI POSTING</option>
                                            <option value="TRIPLE POSTING">TRIPLE POSTING</option>
                                            <option value="WRONG BILLER">WRONG BILLER</option>
                                            <option value="OVERSTATED AMOUNT">OVERSTATED AMOUNT</option>
                                            <option value="CANCELLED TRANSACTION">CANCELLED TRANSACTION</option>
                                            <option value="UNREFLECTED TRXN">UNREFLECTED TRXN</option>
                                        </select>
                                    </div>

                                    <div class="field-group overstated-group" style="display:none;">
                                        <label for="wrong_amount"><span class="material-icons">payments</span> Wrong Amount</label>
                                        <input id="wrong_amount" name="wrong_amount" class="field-input currency-input" type="text" inputmode="decimal" pattern="[0-9,\.\-]*" placeholder="0.00">
                                    </div>

                                    <div class="field-group overstated-group" style="display:none;">
                                        <label for="correct_amount"><span class="material-icons">payments</span> Correct Amount</label>
                                        <input id="correct_amount" name="correct_amount" class="field-input currency-input" type="text" inputmode="decimal" pattern="[0-9,\.\-]*" placeholder="0.00">
                                    </div>

                                    <div class="field-group overstated-group" style="display:none;">
                                        <label for="difference_value"><span class="material-icons">calculate</span> Difference</label>
                                        <input id="difference_value" name="difference_value" class="field-input currency-input" type="text" readonly placeholder="0.00">
                                    </div>

                                    <div class="field-group">
                                        <label for="correct_biller_name"><span class="material-icons">business</span> Correct Biller Name</label>
                                        <input id="correct_biller_name" name="correct_biller_name" class="field-input required-field" type="text" list="correctBillerDatalist" placeholder="Search subbiller or select..." required>
                                        <datalist id="correctBillerDatalist">
                                            <?php foreach ($subbillers as $sb): ?>
                                                <option value="<?php echo htmlspecialchars((string) $sb['subbiller_name']); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>

                                    <div class="field-group">
                                        <label for="correct_biller_id"><span class="material-icons">check_circle</span> Correct Biller ID</label>
                                        <input id="correct_biller_id" name="correct_biller_id" class="field-input required-field" type="text" placeholder="Auto-filled from biller name" readonly required>
                                    </div>

                                    <div class="field-group field-fullwidth">
                                        <label for="reason"><span class="material-icons">description</span> Reason for Request</label>
                                        <textarea id="reason" name="reason" class="field-input required-field" rows="4" placeholder="Provide detailed reason for this support ticket request" required></textarea>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- Attachments: bottom full-width area -->
                        <div class="st-attachments-section">
                            <h6 style="margin:0 0 8px 0;font-weight:700;color:#111827">Attachments</h6>
                            <div id="stFileUploadArea" class="file-upload-area" tabindex="0">
                                <div class="file-upload-icon"><i class="fa-solid fa-paperclip"></i></div>
                                <div><strong>Drag & drop files here</strong></div>
                                <div class="text-muted">or click to browse</div>
                                <div class="text-muted"><small>Supported: PNG, JPEG, JPG, GIF, WEBP, PDF, DOCX, TXT, XLSX, CSV, ODS</small></div>
                                <input type="file" id="attachments" name="attachments[]" accept="image/*,.pdf,.docx,.doc,.txt,.xlsx,.csv,.ods" multiple style="display:none;">
                            </div>
                            <div id="stFilesContainer" style="margin-top:8px;"></div>
                        </div>

                        <div class="mt-3 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-light" id="stCloseCreateModalBtn">Cancel</button>
                            <button type="submit" class="btn btn-danger">Submit Ticket</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Confirmation modal (shown before final submit) -->
        <div class="st-modal-backdrop" id="stConfirmSubmitModal" aria-hidden="true">
            <div class="st-modal" style="max-width:520px;">
                <div class="st-modal-header">
                    <h5 class="mb-0">Confirm Submit</h5>
                    <button type="button" class="st-modal-close" id="stCloseConfirmModal" aria-label="Close">&times;</button>
                </div>
                <div class="st-modal-body">
                    <p>Are you sure you want to submit this ticket? This will create a support ticket for review.</p>
                    <div class="mt-3 d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" id="stCancelSubmitBtn">Cancel</button>
                        <button type="button" class="btn btn-danger" id="stConfirmSubmitBtn">Confirm Submit</button>
                    </div>
                </div>
            </div>
        </div>

        <?php include '../../templates/footer.php'; ?>
    </div>

    <?php if ($flash): ?>
    <script>
        window.supportTicketInitialFlash = <?php echo json_encode(['type' => (string) ($flash['type'] ?? 'success'), 'message' => (string) ($flash['message'] ?? '')]); ?>;
    </script>
    <?php endif; ?>

    <script>
        window.supportTicketLiveUpdates = {
            endpoint: 'controllers/poll/live-updates.php',
            scope: 'BRANCH',
            intervalMs: 5000
        };
    </script>

    <script src="assets/js/support-ticket-ui.js?v=<?php echo time(); ?>"></script>
    <script>
        // Create maps for client-side searchable datalists
        window.createTicketBranchMap = <?php
            $bmap = [];
            foreach ($branches as $b) {
                $name = strtolower(trim((string) ($b['branch_name'] ?? '')));
                if ($name === '') continue;
                $bmap[$name] = (string) ($b['branch_id'] ?? '');
            }
            echo json_encode($bmap);
        ?>;

        window.createTicketSubbillerMap = <?php
            $smap = [];
            foreach ($subbillers as $sb) {
                $name = strtolower(trim((string) ($sb['subbiller_name'] ?? '')));
                if ($name === '') continue;
                $smap[$name] = [
                    'id' => (string) ($sb['subbiller_ext_id'] ?? ''),
                    'partner_ext_id' => (string) ($sb['partner_ext_id'] ?? '')
                ];
            }
            echo json_encode($smap);
        ?>;
    </script>
    <script src="assets/js/create-ticket.js?v=<?php echo time(); ?>"></script>
    <script>
        (function () {
            var cancelBtn = document.getElementById('stCloseCreateModalBtn');
            var xBtn = document.getElementById('stCloseCreateModal');
            var modal = document.getElementById('createTicketModal');

            function closeModal() {
                if (modal) modal.classList.remove('open');
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', closeModal);
            }
            if (xBtn) {
                xBtn.addEventListener('click', closeModal);
            }

            // open button
            var openBtn = document.getElementById('stOpenCreateModal');
            if (openBtn) {
                openBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var m = document.getElementById('createTicketModal');
                    if (m) m.classList.add('open');
                });
            }
        })();
    </script>
</body>
</html>
