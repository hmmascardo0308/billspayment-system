<?php
// CLI test to verify reading user-permissions.json (ignores HTTP session)
$mapPath = __DIR__ . '/../../assets/js/user-permissions.json';
$dec = [];
if (file_exists($mapPath)) {
    $raw = @file_get_contents($mapPath);
    $dec = json_decode($raw, true);
}
$testId = 'TEST123';
$perms = [];
if (isset($dec[$testId]) && is_array($dec[$testId])) {
    $perms = $dec[$testId];
}
echo json_encode(['success' => true, 'id_number' => $testId, 'permissions' => $perms], JSON_PRETTY_PRINT) . PHP_EOL;
