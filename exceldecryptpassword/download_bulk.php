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

if (!isset($_FILES['excelFiles'])) {
    respond(['error' => 'No files uploaded'], 400);
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

$names = $_FILES['excelFiles']['name'] ?? [];
$tmpNames = $_FILES['excelFiles']['tmp_name'] ?? [];
$errors = $_FILES['excelFiles']['error'] ?? [];

if (!is_array($names)) {
    $names = [$names];
    $tmpNames = [$_FILES['excelFiles']['tmp_name']];
    $errors = [$_FILES['excelFiles']['error']];
}

if (count($names) === 0) {
    respond(['error' => 'No files uploaded'], 400);
}

$zipPath = tempnam(sys_get_temp_dir(), 'zip_');
if ($zipPath === false) {
    respond(['error' => 'Failed to create temporary ZIP'], 500);
}
$zipRealPath = $zipPath . '.zip';
@rename($zipPath, $zipRealPath);

$tempFilesToDelete = [$zipRealPath];
register_shutdown_function(static function () use (&$tempFilesToDelete): void {
    foreach ($tempFilesToDelete as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
});

$zip = new ZipArchive();
if ($zip->open($zipRealPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    respond(['error' => 'Failed to create ZIP archive'], 500);
}

$addedCount = 0;

for ($i = 0; $i < count($names); $i++) {
    $fileName = (string)$names[$i];
    $tmpName = (string)$tmpNames[$i];
    $errorCode = (int)$errors[$i];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['xls', 'xlsx'], true)) {
        continue;
    }

    if ($errorCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
        continue;
    }

    $inTemp = tempnam(sys_get_temp_dir(), 'enc_');
    $outTemp = tempnam(sys_get_temp_dir(), 'dec_');
    if ($inTemp === false || $outTemp === false) {
        continue;
    }

    $tempFilesToDelete[] = $inTemp;
    $tempFilesToDelete[] = $outTemp;

    if (!move_uploaded_file($tmpName, $inTemp)) {
        continue;
    }

    $cmd = build_python_command($pythonPath, $scriptPath, $inTemp, $outTemp, $password);
    $output = [];
    $exitCode = 1;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($outTemp) || filesize($outTemp) === 0) {
        continue;
    }

    $base = pathinfo($fileName, PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$base);
    $entryName = $safeBase . '_decrypted.' . $extension;

    if ($zip->addFile($outTemp, $entryName)) {
        $addedCount++;
    }
}

$zip->close();

if ($addedCount === 0 || !is_file($zipRealPath) || filesize($zipRealPath) === 0) {
    respond(['error' => 'No files were decrypted successfully for ZIP export'], 500);
}

$zipName = 'decrypted_excels_' . date('Ymd_His') . '.zip';
header_remove('Content-Type');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . (string)filesize($zipRealPath));
readfile($zipRealPath);
exit;
