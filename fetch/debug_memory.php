<?php
header('Content-Type: application/json');

// Basic runtime and memory diagnostics
$data = [];
$data['memory_limit'] = ini_get('memory_limit');
$data['memory_usage_bytes'] = memory_get_usage();
$data['memory_usage_real_bytes'] = memory_get_usage(true);
$data['memory_peak_bytes'] = memory_get_peak_usage();
$data['memory_peak_real_bytes'] = memory_get_peak_usage(true);
$data['max_execution_time'] = ini_get('max_execution_time');
$data['post_max_size'] = ini_get('post_max_size');
$data['upload_max_filesize'] = ini_get('upload_max_filesize');
$data['error_last'] = error_get_last();

// Optionally include a small allocation test (safe, minimal) to show memory growth
try {
    $arr = range(1, 1000);
    $data['allocation_test'] = 'ok';
    unset($arr);
} catch (Throwable $e) {
    $data['allocation_test'] = 'failed: ' . $e->getMessage();
}

echo json_encode(['success' => true, 'data' => $data]);
