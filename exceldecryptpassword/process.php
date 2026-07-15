<?php
declare(strict_types=1);

header('Content-Type: application/json');

const FIXED_PASSWORD = 'MBTC2026';

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

if (!isset($_FILES['excelFiles'])) {
    respond(['error' => 'No files uploaded'], 400);
}

$scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'decrypt_excel.py';
$pythonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';

if (!is_file($pythonPath)) {
    respond(['error' => 'Python venv not found. Expected .venv/Scripts/python.exe'], 500);
}

if (!is_file($scriptPath)) {
    respond(['error' => 'Decrypt script not found'], 500);
}

$uploadNames = $_FILES['excelFiles']['name'] ?? [];
$tmpNames = $_FILES['excelFiles']['tmp_name'] ?? [];
$errors = $_FILES['excelFiles']['error'] ?? [];

if (!is_array($uploadNames)) {
    $uploadNames = [$uploadNames];
    $tmpNames = [$_FILES['excelFiles']['tmp_name']];
    $errors = [$_FILES['excelFiles']['error']];
}

$batchId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
$outRoot = __DIR__ . DIRECTORY_SEPARATOR . 'output';
$outDir = $outRoot . DIRECTORY_SEPARATOR . $batchId;
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    respond(['error' => 'Failed to create output folder'], 500);
}

$results = [];

for ($i = 0; $i < count($uploadNames); $i++) {
    $originalName = (string)$uploadNames[$i];
    $tmpPath = (string)$tmpNames[$i];
    $uploadError = (int)$errors[$i];

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
        $results[] = [
            'index' => $i,
            'name' => $originalName,
            'ext' => $extension,
            'status' => 'error',
            'message' => 'Unsupported extension'
        ];
        continue;
    }

    if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
        $results[] = [
            'index' => $i,
            'name' => $originalName,
            'ext' => $extension,
            'status' => 'error',
            'message' => 'Upload failed'
        ];
        continue;
    }

    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $targetName = $safeBase . '_decrypted.' . $extension;
    $targetPath = $outDir . DIRECTORY_SEPARATOR . $targetName;

    $command = escapeshellarg($pythonPath)
        . ' ' . escapeshellarg($scriptPath)
        . ' ' . escapeshellarg($tmpPath)
        . ' ' . escapeshellarg($targetPath)
        . ' --password ' . escapeshellarg(FIXED_PASSWORD)
        . ' 2>&1';

    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($targetPath)) {
        $results[] = [
            'index' => $i,
            'name' => $originalName,
            'ext' => $extension,
            'status' => 'error',
            'message' => implode("\n", $output) ?: 'Decryption failed'
        ];
        continue;
    }

    $results[] = [
        'index' => $i,
        'name' => $originalName,
        'ext' => $extension,
        'status' => 'done',
        'message' => 'Decrypted successfully',
        'downloadName' => $targetName,
        'downloadUrl' => 'output/' . rawurlencode($batchId) . '/' . rawurlencode($targetName)
    ];
}

respond([
    'batchId' => $batchId,
    'results' => $results
]);
