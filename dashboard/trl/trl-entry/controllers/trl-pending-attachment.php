<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

$userId = resolve_user_identifier();
$hasEntryAccess = function_exists('has_any_permission') && has_any_permission(['TRL Entry', 'Bills Payment']);
$canReviewPending = !empty($userId) && $hasEntryAccess && (
    (($_SESSION['user_type'] ?? '') === 'admin') ||
    ((string) $userId === '17098209')
);

if (!$canReviewPending) {
    http_response_code(403);
    exit('You are not authorized to view this attachment.');
}

$attachmentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    exit('Invalid attachment.');
}

$stmt = $conn->prepare("SELECT a.file_name, a.mime_type, a.file_size, a.file_data
    FROM mldb.trl_attachments a
    INNER JOIN mldb.trl t ON t.trl_no = a.trl_no
    WHERE a.id = ? AND t.status = 'PENDING_APPROVAL'
    LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load attachment.');
}
$stmt->bind_param('i', $attachmentId);
$stmt->execute();
$attachment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found or the transaction is no longer pending approval.');
}

$fileName = basename((string) ($attachment['file_name'] ?? 'attachment'));
$mimeType = trim((string) ($attachment['mime_type'] ?? 'application/octet-stream'));
$fileData = $attachment['file_data'];
$fileSize = (int) ($attachment['file_size'] ?? strlen((string) $fileData));
$safeFileName = str_replace(["\r", "\n", '"'], '', $fileName);

header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . $safeFileName . '"');
header('X-Content-Type-Options: nosniff');
echo $fileData;
exit;
