<?php
include '../../../../config/config.php';
session_start();
include '../../../../templates/middleware.php';

header('Content-Type: application/json');

$userId = resolve_user_identifier();
if (empty($userId)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
if (!function_exists('has_any_permission') || !has_any_permission(['TRL Entry', 'Bills Payment'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to submit this draft.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$trlNo = (int) ($_POST['trl_no'] ?? 0);
if ($trlNo <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid draft record.']);
    exit;
}

function trl_draft_files($fieldName) {
    if (!isset($_FILES[$fieldName])) return [];
    $upload = $_FILES[$fieldName];
    if (!is_array($upload['name'])) return [$upload];

    $files = [];
    $count = count($upload['name']);
    for ($index = 0; $index < $count; $index++) {
        $files[] = [
            'name' => $upload['name'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '',
            'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0
        ];
    }
    return $files;
}

function trl_draft_validate_files($files) {
    $allowed = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'pdf', 'docx', 'txt', 'xlsx', 'csv', 'ods'];
    if (empty($files)) throw new Exception('Add at least one attachment before submitting this draft.');
    if (count($files) > 10) throw new Exception('A maximum of 10 attachments is allowed.');

    $validated = [];
    foreach ($files as $file) {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new Exception('One of the attachments could not be uploaded.');

        $name = basename((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) ($file['size'] ?? 0);
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($name === '' || !in_array($extension, $allowed, true)) throw new Exception('An attachment has an unsupported file type.');
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new Exception($name . ' must be between 1 byte and 10 MB.');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) throw new Exception('Unable to verify uploaded attachment: ' . $name);

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $file['name'] = $name;
        $file['mime_type'] = (string) ($fileInfo->file($tmpName) ?: 'application/octet-stream');
        $validated[] = $file;
    }
    if (empty($validated)) throw new Exception('Add at least one attachment before submitting this draft.');
    return $validated;
}

function trl_draft_insert_file($conn, $trlNo, $userId, $file) {
    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) throw new Exception('Unable to read attachment: ' . $file['name']);

    $sql = "INSERT INTO mldb.trl_attachments (trl_no, file_name, mime_type, file_size, file_data, created_by) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Unable to prepare attachment insert.');

    $fileName = (string) $file['name'];
    $mimeType = (string) $file['mime_type'];
    $fileSize = (int) $file['size'];
    $createdBy = (string) $userId;
    $null = null;
    $stmt->bind_param('issibs', $trlNo, $fileName, $mimeType, $fileSize, $null, $createdBy);
    $stmt->send_long_data(4, $binary);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to save attachment: ' . $fileName);
    }
    $stmt->close();
}

try {
    $files = trl_draft_validate_files(trl_draft_files('attachments'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$conn->autocommit(false);
try {
    $lockStmt = $conn->prepare("SELECT draft_record.trl_no, draft_record.status FROM mldb.trl draft_record
        WHERE draft_record.trl_no = ?
          AND (draft_record.status = 'DRAFT' OR (draft_record.status IS NULL AND NOT EXISTS (
              SELECT 1 FROM mldb.trl_attachments existing_attachment
              WHERE existing_attachment.trl_no = draft_record.trl_no
          )))
        LIMIT 1 FOR UPDATE");
    if (!$lockStmt) throw new Exception('Unable to prepare draft lookup.');
    $lockStmt->bind_param('i', $trlNo);
    if (!$lockStmt->execute()) {
        $lockStmt->close();
        throw new Exception('Unable to fetch the selected draft.');
    }
    $draft = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if (!$draft) throw new Exception('The draft was not found or has already been submitted.');

    foreach ($files as $file) {
        trl_draft_insert_file($conn, $trlNo, $userId, $file);
    }

    if (strcasecmp((string) ($draft['status'] ?? ''), 'DRAFT') === 0) {
        $updateStmt = $conn->prepare("UPDATE mldb.trl SET status = NULL WHERE trl_no = ? AND status = 'DRAFT'");
        if (!$updateStmt) throw new Exception('Unable to prepare draft submission.');
        $updateStmt->bind_param('i', $trlNo);
        if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
            $updateStmt->close();
            throw new Exception('The draft could not be submitted for review.');
        }
        $updateStmt->close();
    }

    $conn->commit();
    $conn->autocommit(true);
    echo json_encode([
        'success' => true,
        'message' => 'The attachment was saved and the transaction is now ready for TRL Review.',
        'redirect' => '../trl-review/trl-review.php'
    ]);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
