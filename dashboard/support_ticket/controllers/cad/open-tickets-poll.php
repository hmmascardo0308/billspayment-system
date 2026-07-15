<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
include_once __DIR__ . '/../../includes/ticket_queries.php';
global $conn;
st_require_login('../../../../login_form.php');
st_require_permission_api(['Support Ticket CAD']);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    st_json(false, 'Invalid request method.', [], 405);
}

function st_status_class_cad_poll($status)
{
    return 'st-status st-status-' . strtolower((string) $status);
}

function st_partner_name_cad_poll($ticket)
{
    $partner = trim((string) ($ticket['partner_name'] ?? ''));
    if ($partner !== '') {
        return $partner;
    }
    $ext = trim((string) ($ticket['partner_ext_id'] ?? ''));
    return $ext !== '' ? $ext : 'N/A';
}

$cadOpen = st_get_cad_open_tickets($conn);
$ticketNumbers = [];
foreach ($cadOpen as $ticket) {
    $tn = trim((string) ($ticket['ticket_number'] ?? ''));
    if ($tn !== '') {
        $ticketNumbers[] = $tn;
    }
}

$badgeCounts = st_get_ticket_badge_counts($conn, $ticketNumbers, 'CAD');

$hashRows = [];
foreach ($cadOpen as $ticket) {
    $ticketNumber = (string) ($ticket['ticket_number'] ?? '');
    $hashRows[] = [
        'id' => (int) ($ticket['id'] ?? 0),
        'ticket_number' => $ticketNumber,
        'status' => (string) ($ticket['status'] ?? ''),
        'created_at' => (string) ($ticket['created_at'] ?? ''),
        'unread' => (int) ($badgeCounts[$ticketNumber] ?? 0),
    ];
}
$hash = md5(json_encode($hashRows));

ob_start();
if (empty($cadOpen)):
?>
<div class="st-empty">No tickets in CAD open queue.</div>
<?php
else:
?>
<div class="st-ticket-table" role="table" aria-label="Open CAD tickets">
    <div class="st-ticket-row st-ticket-row-head" role="row">
        <span class="st-ticket-col st-col-number">Ticket #</span>
        <span class="st-ticket-col st-col-date">Created</span>
        <span class="st-ticket-col st-col-type">Type</span>
        <span class="st-ticket-col st-col-partner">Partner</span>
        <span class="st-ticket-col st-col-status">Status</span>
    </div>
    <?php foreach ($cadOpen as $ticket): ?>
        <button type="button" class="st-ticket-row" role="row" data-ticket-modal="stTicketTrailModalCad-<?php echo (int) $ticket['id']; ?>" data-ticket-id="<?php echo (int) $ticket['id']; ?>" data-ticket-number="<?php echo htmlspecialchars((string) $ticket['ticket_number']); ?>" data-status="<?php echo htmlspecialchars((string) ($ticket['status'] ?? '')); ?>" data-seen-role="CAD">
            <?php $cadUnread = (int) ($badgeCounts[(string) ($ticket['ticket_number'] ?? '')] ?? 0); ?>
            <span class="st-ticket-col st-col-number"><?php echo htmlspecialchars((string) $ticket['ticket_number']); ?><?php if ($cadUnread > 0): ?> <span class="st-ticket-unread-badge"><?php echo $cadUnread; ?></span><?php endif; ?></span>
            <span class="st-ticket-col st-col-date"><?php echo htmlspecialchars((string) $ticket['created_at']); ?></span>
            <span class="st-ticket-col st-col-type"><?php echo htmlspecialchars((string) ($ticket['ticket_type_label'] ?: $ticket['type_of_request'])); ?></span>
            <span class="st-ticket-col st-col-partner"><?php echo htmlspecialchars(st_partner_name_cad_poll($ticket)); ?></span>
            <span class="st-ticket-col st-col-status"><span class="<?php echo htmlspecialchars(st_status_class_cad_poll($ticket['status'])); ?>"><?php echo htmlspecialchars((string) $ticket['status']); ?></span></span>
        </button>
    <?php endforeach; ?>
</div>
<?php
endif;
$html = ob_get_clean();

st_json(true, 'Open tickets polled.', [
    'hash' => $hash,
    'count' => count($cadOpen),
    'open_html' => $html,
]);
