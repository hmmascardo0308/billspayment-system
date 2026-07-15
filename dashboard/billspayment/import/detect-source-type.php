<?php
declare(strict_types=1);

header('Content-Type: application/json');

require '../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['success' => false, 'error' => 'No file uploaded'], 400);
}

$uploaded = $_FILES['file'];
if (($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['success' => false, 'error' => 'Upload failed'], 400);
}

$tmpPath = (string)($uploaded['tmp_name'] ?? '');
$originalName = (string)($uploaded['name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, ['xls', 'xlsx'], true)) {
    json_response(['success' => false, 'error' => 'Invalid file type'], 400);
}

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    json_response(['success' => false, 'error' => 'Invalid uploaded file'], 400);
}

try {
    $spreadsheet = IOFactory::load($tmpPath);
    $sourceType = 'UNKNOWN';
    $debugRows = [];

    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $b4Value = (string)$sheet->getCell('B4')->getFormattedValue();
        $b4Normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $b4Value) ?? ''));
        $a9Value = (string)$sheet->getCell('A9')->getFormattedValue();
        $a9Normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $a9Value) ?? ''));
        $b9Value = (string)$sheet->getCell('B9')->getFormattedValue();
        $b9Normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $b9Value) ?? ''));
        $s10Value = (string)$sheet->getCell('S10')->getFormattedValue();
        $s10Normalized = strtoupper(trim(preg_replace('/\s+/', '', $s10Value) ?? ''));
        $u10Raw = $sheet->getCell('U10')->getCalculatedValue();
        $u10Formatted = (string)$sheet->getCell('U10')->getFormattedValue();
        $u10Numeric = is_numeric($u10Raw) || is_numeric(str_replace(',', '', trim($u10Formatted)));

        $debugRows[] = [
            'sheet' => $sheet->getTitle(),
            'B4' => [
                'value' => $b4Value,
                'normalized' => $b4Normalized,
            ],
            'A9' => [
                'value' => $a9Value,
                'normalized' => $a9Normalized,
            ],
            'B9' => [
                'value' => $b9Value,
                'normalized' => $b9Normalized,
            ],
            'S10' => [
                'value' => $s10Value,
                'normalized' => $s10Normalized,
            ],
            'U10' => [
                'raw' => $u10Raw,
                'formatted' => $u10Formatted,
                'isNumeric' => $u10Numeric,
            ],
        ];

        if ($s10Normalized !== '' && (str_starts_with($s10Normalized, 'MLBPP') || str_contains($s10Normalized, 'MLBPP'))) {
            $sourceType = 'KP7';
            break;
        }

        if ($a9Normalized === 'STATUS' && $b9Normalized === '') {
            $sourceType = 'KP7';
            break;
        }

        if ($u10Numeric) {
            $sourceType = 'KPX';
        }

        if ($b4Normalized === 'ALL PARTNERS' && $a9Normalized === 'NO' && $b9Normalized === 'DATE / TIME') {
            $sourceType = 'KPX';
            break;
        }

        if ($b4Normalized !== 'ALL PARTNERS' && $a9Normalized === 'NO') {
            $sourceType = 'KPX';
        }
    }

    json_response([
        'success' => true,
        'sourceType' => $sourceType,
        'debug' => $debugRows,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Failed to parse spreadsheet',
    ], 500);
}
