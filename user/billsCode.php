<?php
session_start();
include '../config/config.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function checkRegionCode(){

}

function getRegionCode($region) {
    global $conn;
    $stmt = $conn->prepare("SELECT region_code FROM regions WHERE region_name = ?");
    $stmt->bind_param("s", $region);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc()['region_code'];
    } else {
        return null; // or handle the case where the region is not found
    }
}