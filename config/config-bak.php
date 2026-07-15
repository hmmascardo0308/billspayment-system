<?php
    $host = "localhost";
    $username = "root";
    $password = "Password1";
    $database = "mldb";
    $database2 = "masterdata";

    date_default_timezone_set('Asia/Manila');

    // Create DB Connection
    $conn = mysqli_connect($host, $username, $password, $database);

    // Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    // Create DB Connection for masterdata
    $conn2 = mysqli_connect($host, $username, $password, $database2);

    if (!$conn2) {
        die("Connection to masterdata failed: " . mysqli_connect_error());
    }

    // ini_set('memory_limit', '100000M');
    // set_time_limit(300); // Increase the time limit to 300 seconds (5 minutes)

?>