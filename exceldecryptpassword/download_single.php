<?php
declare(strict_types=1);

header('Content-Type: application/json');

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function build_python_command(string $pythonPath, string $scriptPath, string $inputPath, string $outputPath, string $password): string
{
    return escapeshellarg($pythonPath)
        . ' ' . escapeshellarg($scriptPath)
        . ' ' . escapeshellarg($inputPath)
        . ' ' . escapeshellarg($outputPath)
    . ' --password ' . escapeshellarg($password)
        . ' 2>&1';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

if (!isset($_FILES['excelFile'])) {
    respond(['error' => 'No file uploaded'], 400);
}

$password = trim((string)($_POST['password'] ?? ''));
if ($password === '') {
    respond(['error' => 'Password is required'], 400);
}

$pythonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'decrypt_excel.py';

if (!is_file($pythonPath) || !is_file($scriptPath)) {
    respond(['error' => 'Python decrypt setup not found'], 500);
}

$fileName = (string)($_FILES['excelFile']['name'] ?? '');
$tmpName = (string)($_FILES['excelFile']['tmp_name'] ?? '');
$errorCode = (int)($_FILES['excelFile']['error'] ?? UPLOAD_ERR_NO_FILE);
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($extension, ['xls', 'xlsx'], true)) {
    respond(['error' => 'Unsupported extension'], 400);
}

if ($errorCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
    respond(['error' => 'Upload failed'], 400);
}

$inTemp = tempnam(sys_get_temp_dir(), 'enc_');
$outTemp = tempnam(sys_get_temp_dir(), 'dec_');

if ($inTemp === false || $outTemp === false) {
    respond(['error' => 'Failed to create temp files'], 500);
}

register_shutdown_function(static function () use ($inTemp, $outTemp): void {
    if (is_file($inTemp)) {
        @unlink($inTemp);
    }
    if (is_file($outTemp)) {
        @unlink($outTemp);
    }
});

if (!move_uploaded_file($tmpName, $inTemp)) {
    respond(['error' => 'Failed to move uploaded file'], 500);
}

$cmd = build_python_command($pythonPath, $scriptPath, $inTemp, $outTemp, $password);
$output = [];
$exitCode = 1;
exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !is_file($outTemp) || filesize($outTemp) === 0) {
    respond(['error' => implode("\n", $output) ?: 'Decryption failed'], 500);
}

$base = pathinfo($fileName, PATHINFO_FILENAME);
$safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$base);
$downloadName = $safeBase . '_decrypted.' . $extension;

header_remove('Content-Type');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($outTemp));
readfile($outTemp);
exit;
