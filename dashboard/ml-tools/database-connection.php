<?php 
$host = "ho-cad-exactdb";
$username = "mlcad";
$password = "CADMLhuillier2023";
$database = ["masterdata"];

date_default_timezone_set('Asia/Manila');

$connections = [];
foreach ($database as $db) {
    $connections[] = mysqli_connect($host, $username, $password, $db);
}

// keep original variable names for compatibility
$conn = $connections[0] ?? null;

// check connections
foreach ($connections as $i => $connection) {
    if (!$connection) {
        $failedDb = $database[$i];
        die("Connection to '{$failedDb}' failed: " . mysqli_connect_error());
    }
}
?>
