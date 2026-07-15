<?php
include_once __DIR__ . '/../../includes/bootstrap.php';
global $conn;
st_require_login('../../../../login_form.php');
if (!function_exists('has_any_permission') || !has_any_permission(['Support Ticket Create', 'Support Ticket VPO', 'Support Ticket CAD'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    echo 'Invalid attachment ID.';
    exit;
}

$schema = st_schema();
$sql = "SELECT file_name, mime_type, file_size, file_data FROM {$schema}.ticket_attachments WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo 'Unable to prepare attachment query.';
    exit;
}

$stmt->bind_param('i', $attachmentId);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo 'Unable to fetch attachment.';
    exit;
}

$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$fileName = $row['file_name'] ?: ('attachment-' . $attachmentId);
$mimeType = $row['mime_type'] ?: 'application/octet-stream';
$fileSize = (int) ($row['file_size'] ?? strlen((string) $row['file_data']));
$fileData = $row['file_data'];

// Serve inline so browsers can preview images/PDFs natively
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');

echo $fileData;
exit;
