<?php
// Return JSON list of all partners
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

// ensure $conn exists
if (!isset($conn) || !$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection not available']);
    exit;
}

// Always get all partners, no fileType filtering
$sql = "
    WITH direct_biller AS (
        SELECT
            partner_name
        FROM masterdata.partner_masterfile
        WHERE status = 'ACTIVE'
    ),

    sub_biller AS (
        SELECT
            sub_billers_name
        FROM masterdata.subbiller
    )

    SELECT 
        partner_name AS partner_name
    FROM 
        direct_biller

    UNION

    SELECT 
        sub_billers_name AS partner_name
    FROM 
        sub_biller
    ORDER BY partner_name
";

$result = $conn->query($sql);
if ($result === false) {
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

$out = [];
while ($row = $result->fetch_assoc()) {
    $out[] = [
        'partner_name' => $row['partner_name']
    ];
}
// Server-side dedupe: normalize names (trim, collapse spaces, lowercase) and keep first occurrence
$dedup = [];
$seen = [];
foreach ($out as $item) {
    $name = isset($item['partner_name']) ? $item['partner_name'] : '';
    if ($name === null) $name = '';
    // normalize: trim, collapse whitespace
    $norm = preg_replace('/\s+/u', ' ', trim($name));
    // remove invisible/control characters (eg. zero-width space U+200B, BOM)
    $norm = preg_replace('/[\p{C}\x{200B}\x{FEFF}]+/u', '', $norm);
    // attempt Unicode normalization if available
    if (class_exists('Normalizable') || function_exists('normalizer_normalize')) {
        // @phan-suppress-current-line PhanUndeclaredFunction
        if (function_exists('normalizer_normalize')) {
            $norm = normalizer_normalize($norm, Normalizer::FORM_C);
        }
    }
    // case-insensitive key
    $key = mb_strtolower($norm, 'UTF-8');
    if ($key === '') continue;
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $dedup[] = ['partner_name' => $norm];
}

echo json_encode(['success' => true, 'data' => $dedup]);
exit;
?>