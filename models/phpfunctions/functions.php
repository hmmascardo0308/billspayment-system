<?php

function convertExcelDate($excelDate) {
    // Excel dates are stored as serial numbers starting from January 1, 1900
    $unixDate = ($excelDate - 25569) * 86400; // convert Excel date to Unix timestamp
    return gmdate("Y-m-d", $unixDate);
}

function convertExcelTime($excelTime) {
    // Multiply the fraction of a day by the number of seconds in a day
    $seconds = $excelTime * 86400;
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds / 60) % 60);
    $seconds = $seconds % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

function convertToMySQLDate($dateStr) {
    $year = substr($dateStr, 0, 4);
    $month = substr($dateStr, 4, 2);
    $day = substr($dateStr, 6, 2);

    $date = "$year-$month-$day";

    if ( $date >= date("Y-m-d")) {
        return false;
    }

    $date1 = DateTime::createFromFormat('Y-m-d', "$date");

    if ($date1) {
        return $date1->format('Y-m-d');
    }

    error_log('Invalid date format: ' . htmlspecialchars($dateStr));
    return 'Invalid Date';
}

function convertToMySQLTime($timeStr) {
    $hour = substr($timeStr, 0, 2);
    $minute = substr($timeStr, 2, 2);
    $second = substr($timeStr, 4, 2);
    $microsecond = substr($timeStr, 6, 6);

    $time = "$hour:$minute:$second.$microsecond";

    $time1 = DateTime::createFromFormat('H:i:s.u', "$time");

    if ($time1) {
        return $time1->format('H:i:s.u');
    }

    error_log('Invalid date format: ' . htmlspecialchars($timeStr));
    return 'Invalid Time';
}

?>