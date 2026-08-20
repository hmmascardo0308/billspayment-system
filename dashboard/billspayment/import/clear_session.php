<?php
session_start();
if (isset($_POST['action']) && $_POST['action'] === 'clear_csv_data') {
    unset($_SESSION['csv_data']);
    unset($_SESSION['csv_headers']);
    unset($_SESSION['csv_uploaded']);
    echo json_encode(['success' => true]);
    exit;
}
echo json_encode(['success' => false]);
exit;
?>