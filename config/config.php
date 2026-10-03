<?php
// config.php — handles BOTH connections (mldb + glcs_db)

// ------------------------------------------------------------
// Detect environment
// ------------------------------------------------------------
// Production = running on the ExactDB server itself.
// You can also override with APP_ENV env var if needed.
// NOTE: hostname comparison is case-insensitive and uses a
// substring match to survive variants like HO-CAD-EXACTDB,
// ho-cad-exactdb.local, etc.
$hostname     = strtolower(trim(php_uname('n')));
$isProduction = (strpos($hostname, 'ho-cad-exactdb') !== false)
             || (getenv('APP_ENV') === 'production');

// ------------------------------------------------------------
// PRIMARY DB — mldb (billspayment_transaction, masterfile, etc.)
// ------------------------------------------------------------
$host     = "localhost";
$username = "root";
$password = $isProduction ? "CADMLhuillier2023" : "Password1";
$database = "mldb";

$conn = mysqli_connect($host, $username, $password, $database);
if (!$conn) {
    die("Connection failed (mldb): " . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');

// ------------------------------------------------------------
// SECONDARY DB — glcs_db (ExactDB, table: gle)
// ------------------------------------------------------------
// On your PC: reach it over the network via hostname "ho-cad-exactdb".
// On the ExactDB server itself: it's localhost.
$exact_host     = $isProduction ? 'localhost' : 'ho-cad-exactdb';
$exact_user     = 'mlcad';
$exact_password = 'CADMLhuillier2023';
$exact_db       = 'glcs_db';

$conn_exact = @mysqli_connect($exact_host, $exact_user, $exact_password, $exact_db);
if (!$conn_exact) {
    die('Connection failed (glcs_db / ExactDB): ' . mysqli_connect_error());
}
mysqli_set_charset($conn_exact, 'utf8mb4');