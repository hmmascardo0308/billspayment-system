<?php
include_once __DIR__ . '/../../support_ticket/includes/bootstrap.php';

global $conn;

st_require_login('../../../login_form.php');
if (!function_exists('has_any_permission') || !has_any_permission(['Support Ticket Report', 'Maintenance Support Ticket'])) {
    http_response_code(403);
    echo '<div class="ip-error">Forbidden</div>';
    exit;
}

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    echo '<div class="ip-error">Invalid attachment ID.</div>';
    exit;
}

$schema = st_schema();
$sql = "SELECT file_name, mime_type FROM {$schema}.ticket_attachments WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo '<div class="ip-error">Unable to query attachment.</div>';
    exit;
}

$stmt->bind_param('i', $attachmentId);
if (!$stmt->execute()) {
    $stmt->close();
    echo '<div class="ip-error">Unable to fetch attachment.</div>';
    exit;
}

$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    echo '<div class="ip-error">Attachment not found.</div>';
    exit;
}

$fileName = htmlspecialchars($row['file_name'] ?? ('attachment-' . $attachmentId));
$mimeType = (string) ($row['mime_type'] ?? 'application/octet-stream');
$isImage = preg_match('/^image\//', $mimeType) === 1;
?>
<div class="ip-overlay" id="stImagePreviewOverlay">
  <div class="ip-modal">
    <button type="button" class="ip-close" data-ip-close aria-label="Close">&times;</button>
    <div class="ip-body">
      <?php if ($isImage): ?>
        <img class="ip-image" src="../../support_ticket/controllers/attachments/serve.php?id=<?php echo $attachmentId; ?>" alt="<?php echo $fileName; ?>">
      <?php else: ?>
        <div class="ip-file-placeholder">
          <div class="ip-file-icon"><i class="fa-solid fa-file"></i></div>
          <div class="ip-file-name"><?php echo $fileName; ?></div>
          <div class="ip-file-help">Preview unavailable. Use download button.</div>
        </div>
      <?php endif; ?>
    </div>
    <div class="ip-footer">
      <a class="tm-btn tm-btn--red ip-download" href="../../support_ticket/controllers/attachments/download.php?id=<?php echo $attachmentId; ?>">Download</a>
    </div>
  </div>
</div>
