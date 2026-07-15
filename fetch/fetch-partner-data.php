<?php
require_once __DIR__ . '/../config/config.php';

    $query = "SELECT * FROM masterdata.partner_masterfile";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        $options = mysqli_fetch_all($result, MYSQLI_ASSOC);
        // Filter out empty rows
        $options = array_filter($options, function ($option) {
            // Check if any field is non-empty
            return !empty(array_filter($option, function ($value) {
                return $value !== null && $value !== '';
            }));
        });
        
        // Sort the $options array by partner_name in ascending order
        usort($options, function($a, $b) {
            return strcmp($a['partner_accName'], $b['partner_accName']);
        });
    }

?>