<?php
// Connect to the database
include '../../../config/config.php';
require '../../../vendor/autoload.php';

// Start the session
session_start();
@include_once __DIR__ . '/../../../templates/middleware.php';
$id = resolve_user_identifier();
if (empty($id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['EDI Report','Bills Payment'])) { header('Location: ../../home.php'); exit; }



// prefer explicit session values for current user email; avoid role-based gating
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Fetch partners
$partnersQuery = "SELECT partner_name FROM masterdata.partner_masterfile ORDER BY partner_name";
$partnersResult = $conn->query($partnersQuery);

// Fetch main zones
$mainzoneQuery = "SELECT 
                    mmzm.main_zone_code
                FROM 
                    masterdata.main_zone_masterfile AS mmzm 
                WHERE 
                    main_zone_code NOT IN ('JEW', 'HO')";
$mainzoneResult = $conn->query($mainzoneQuery);

// Handle AJAX requests for zones
if (isset($_POST['action']) && $_POST['action'] === 'get_zones') {
    $mainzone = strtoupper(trim($_POST['mainzone'] ?? ''));
    
    $zoneQuery = "SELECT 
                    mzm.zone_code
                FROM masterdata.main_zone_masterfile AS mmzm
                JOIN masterdata.zone_masterfile AS mzm
                    ON mmzm.main_zone_code = mzm.main_zone_code
                AND mzm.zone_code NOT IN (
                        'HO',
                        'JEW',
                        'VISMIN-MANCOMM',
                        'LNCR-MANCOMM',
                        'VISMIN-SUPPORT',
                        'LNCR-SUPPORT'
                )
                AND mmzm.main_zone_code NOT IN ('JEW', 'HO')";
                
    if($mainzone !== 'ALL'){
        $zoneQuery .= " WHERE mmzm.main_zone_code = ?";
        $stmt = $conn->prepare($zoneQuery);
        $stmt->bind_param("s", $mainzone);
    } else {
        $stmt = $conn->prepare($zoneQuery);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $options = '';
    while ($row = $result->fetch_assoc()) {
        $options .= '<option value="' . $row['zone_code'] . '">' . $row['zone_code'] . '</option>';
    }
    
    echo $options;
    exit; // Stop execution for AJAX response
}
elseif (isset($_POST['action']) && $_POST['action'] === 'get_regions'){
    $mainzone = strtoupper(trim($_POST['mainzone'] ?? ''));
    $zoneInput = strtoupper(trim($_POST['zone'] ?? ''));
    $zone = ($zoneInput === 'SHOWROOM') ? 'Showroom' : $zoneInput;
    
    $regionQuery = "SELECT 
                    mrm.region_code,
                    mrm.region_description
                FROM masterdata.main_zone_masterfile AS mmzm
                JOIN masterdata.zone_masterfile AS mzm
                    ON mmzm.main_zone_code = mzm.main_zone_code
                AND mzm.zone_code NOT IN (
                        'VISMIN-MANCOMM',
                        'LNCR-MANCOMM',
                        'VISMIN-SUPPORT',
                        'LNCR-SUPPORT'
                    )
                AND mmzm.main_zone_code NOT IN (
                        'JEW', 'HO'
                    )
                JOIN masterdata.region_masterfile AS mrm
                    ON mzm.zone_code = mrm.zone_code";
                    
                if($mainzone !== 'ALL'){ //(LNCR, VISMIN)
                    if($zone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (LNCR, VISMIN)
                        if ($zone !== 'Showroom') { // (LZN,NCR,VIS,MIN), (LNCR, VISMIN)
                            $regionQuery .= " WHERE mmzm.main_zone_code = ? AND mzm.zone_code = ?";
                        }
                    }else{
                        $regionQuery .= " WHERE mmzm.main_zone_code = ?";
                    }
                }else{ // (ALL)
                    if($zone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (ALL)
                        if ($zone !== 'Showroom') { // (LZN,NCR,VIS,MIN), (ALL)
                            $regionQuery .= " WHERE mzm.zone_code = ?";
                        }
                    }
                }
    
    $stmt = $conn->prepare($regionQuery);

    if($mainzone !== 'ALL'){ //(LNCR, VISMIN)
        if($zone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (LNCR, VISMIN)
            if ($zone !== 'Showroom') { // (LZN,NCR,VIS,MIN), (LNCR, VISMIN)
                $stmt->bind_param("ss", $mainzone, $zone);
            }
        }else{
            $stmt->bind_param("s", $mainzone);
        }
    }else{ // (ALL)
        if($zone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (ALL)
            if ($zone !== 'Showroom') { // (LZN,NCR,VIS,MIN), (ALL)
                $stmt->bind_param("s", $zone);
            }
        }
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $options2 = '';
    while ($row = $result->fetch_assoc()) {
        $options2 .= '<option value="' . $row['region_code'] . '">' . $row['region_description'] . '</option>';
    }
    
    echo $options2;
    exit;
}
elseif (isset($_POST['action']) && $_POST['action'] === 'get_areas') {
    $mainzone = strtoupper(trim($_POST['mainzone'] ?? ''));
    $zoneInput = strtoupper(trim($_POST['zone'] ?? ''));
    $zone = ($zoneInput === 'SHOWROOM') ? 'Showroom' : $zoneInput;
    $region = strtoupper(trim($_POST['region'] ?? ''));
    
    $areaQuery = "SELECT DISTINCT area FROM masterdata.branch_profile WHERE 1=1";
    $params = array();
    $types = "";
    
    if($mainzone !== 'ALL'){ //(LNCR, VISMIN)
        if($zone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (LNCR, VISMIN)
            if ($zone !== 'Showroom') { // (LZN,NCR,VIS,MIN), (LNCR, VISMIN)
                if($region !== 'ALL'){ // (LNCR01-19, R01-31), (LZN,NCR,VIS,MIN)
                    if($region === 'LZN' || $region === 'NCR'){ // (LZN or NCR)
                        $areaQuery .= " AND ml_matic_region = ? AND zone = ?";
                        $params[] = "LNCR " . $zone;
                        $params[] = $region;
                        $types .= "ss";
                    }elseif($region === 'VIS' || $region === 'MIN'){ // (VIS or MIN)
                        $areaQuery .= " AND ml_matic_region = ? AND zone = ?";
                        $params[] = "VISMIN " . $zone;
                        $params[] = $region;
                        $types .= "ss";
                    }else{
                        // Regular region code like LNCR01-19, R01-31, etc.
                        $areaQuery .= " AND region_code = ?";
                        $params[] = $region;
                        $types .= "s";
                    }
                }else{
                    // Region is ALL, filter by mainzone and zone
                    $areaQuery .= " AND ml_matic_region = ?";
                    $params[] = $mainzone . " " . $zone;
                    $types .= "s";
                }
            }else{ // SHOWROOM (LZN, NCR, VIS, MIN)
                if($region !== 'ALL'){ // (LZN,NCR,VIS,MIN)
                    if($region === 'LZN' || $region === 'NCR'){ // (LZN or NCR)
                        $areaQuery .= " AND ml_matic_region = ? AND zone = ?";
                        $params[] = "LNCR Showroom";
                        $params[] = $region;
                        $types .= "ss";
                    }elseif($region === 'VIS' || $region === 'MIN'){ // (VIS or MIN)
                        $areaQuery .= " AND ml_matic_region = ? AND zone = ?";
                        $params[] = "VISMIN Showroom";
                        $params[] = $region;
                        $types .= "ss";
                    } else {
                        // Fallback for non-standard showroom region labels (e.g., LNCR SHOWROOM)
                        $areaQuery .= " AND ml_matic_region = ?";
                        $params[] = $mainzone . " Showroom";
                        $types .= "s";
                    }
                }else{
                    // Region is ALL for Showroom
                    $areaQuery .= " AND ml_matic_region = ?";
                    $params[] = $mainzone . " Showroom";
                    $types .= "s";
                }
            }
        }else{
            // Zone is ALL
            if($region !== 'ALL'){
                if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN'){
                    // Handle showroom regions
                    $areaQuery .= " AND zone = ?";
                    $params[] = $region;
                    $types .= "s";
                }else{
                    // Regular region code
                    $areaQuery .= " AND region_code = ?";
                    $params[] = $region;
                    $types .= "s";
                }
            }else{
                // Both zone and region are ALL
                $areaQuery .= " AND ml_matic_region LIKE ?";
                $params[] = $mainzone . "%";
                $types .= "s";
            }
        }
    }else{ // Mainzone is ALL
        if($zone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM)
            if($zone !== 'Showroom'){ // (LZN,NCR,VIS,MIN)
                if($region !== 'ALL'){
                    if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN'){
                        // Handle showroom regions
                        $areaQuery .= " AND zone = ?";
                        $params[] = $region;
                        $types .= "s";
                    }else{
                        // Regular region code
                        $areaQuery .= " AND region_code = ?";
                        $params[] = $region;
                        $types .= "s";
                    }
                }else{
                    // Region is ALL, filter by zone only
                    $areaQuery .= " AND zone = ?";
                    $params[] = $zone;
                    $types .= "s";
                }
            }else{ // Showroom
                if($region !== 'ALL'){
                    if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN'){
                        $areaQuery .= " AND ml_matic_region LIKE ? AND zone = ?";
                        $params[] = "% Showroom";
                        $params[] = $region;
                        $types .= "ss";
                    }
                }else{
                    // All showroom areas
                    $areaQuery .= " AND ml_matic_region LIKE ?";
                    $params[] = "% Showroom";
                    $types .= "s";
                }
            }
        }else{
            // Zone is ALL
            if($region !== 'ALL'){
                if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN'){
                    // Handle showroom regions
                    $areaQuery .= " AND zone = ?";
                    $params[] = $region;
                    $types .= "s";
                }else{
                    // Regular region code
                    $areaQuery .= " AND region_code = ?";
                    $params[] = $region;
                    $types .= "s";
                }
            }
            // If both zone and region are ALL, no additional filter needed
        }
    }

    $areaQuery .= " AND area IS NOT NULL GROUP BY area ORDER BY area";

    $stmt = $conn->prepare($areaQuery);
    
    // Bind parameters if any
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    $options3 = '';
    while ($row = $result->fetch_assoc()) {
        $options3 .= '<option value="' . htmlspecialchars($row['area']) . '">' . htmlspecialchars($row['area']) . '</option>';
    }
    
    echo $options3;
    exit;
}

if(isset($_POST['action']) && $_POST['action'] === 'get_report_data') {
    
    $partner_name_raw = $_POST['partner_name'];
    $partner_id = null;
    $partner_id_kpx = null;
    
    // CONVERT partner name to partner id and partner id kpx for displaying the data in the report
    if($partner_name_raw !== 'All'){
        $partner_idsQuery = "SELECT partner_id, partner_id_kpx FROM masterdata.partner_masterfile WHERE partner_name = ?";
        $partner_idsStmt = $conn->prepare($partner_idsQuery);
        if($partner_idsStmt){
            $partner_idsStmt->bind_param("s", $partner_name_raw);
            $partner_idsStmt->execute();
            $partner_idsResult = $partner_idsStmt->get_result();
            $partner_ids = $partner_idsResult->fetch_assoc();
            if($partner_ids) {
                $partner_id = $partner_ids['partner_id']; // KP7 partner id
                $partner_id_kpx = $partner_ids['partner_id_kpx']; // KPX partner id
            }
        }
    }

    $mainzone = strtoupper(trim($_POST['mainzone'] ?? ''));
    $zoneInput = strtoupper(trim($_POST['zone'] ?? ''));
    $zone = ($zoneInput === 'SHOWROOM') ? 'Showroom' : $zoneInput;
    $region = strtoupper(trim($_POST['region'] ?? ''));
    $area = trim($_POST['area'] ?? '');

    $filterType_raw = $_POST['filterType'];
    if($filterType_raw === 'date_range'){
        $startDate = $_POST['startDate'];
        $endDate = $_POST['endDate'];
        $filterType = $filterType_raw;

    } elseif($filterType_raw === 'month_range'){
        $startDate = $_POST['startDate'];
        $endDate = $_POST['endDate'];
        $filterType = $filterType_raw;

    } elseif($filterType_raw === 'year_range'){
        $startDate = $_POST['startDate'];
        $endDate = $_POST['endDate'];
        $filterType = $filterType_raw;

    } else {
        $startDate = $_POST['startDate'];
        $filterType = $filterType_raw;

    }

    // Build date condition based on filter type
    $dateCondition = "";
    if($filterType === 'date_range') {
        $dateCondition = "(
            DATE(bt.datetime) BETWEEN ? AND ?
            OR 
            DATE(bt.cancellation_date) BETWEEN ? AND ?
            OR
            DATE(bt.report_date) BETWEEN ? AND ?
        )";
    } elseif($filterType === 'month_range') {
        $dateCondition = "(
            (YEAR(bt.datetime) >= YEAR(?) AND MONTH(bt.datetime) >= MONTH(?)) AND
            (YEAR(bt.datetime) <= YEAR(?) AND MONTH(bt.datetime) <= MONTH(?))
            OR 
            (YEAR(bt.cancellation_date) >= YEAR(?) AND MONTH(bt.cancellation_date) >= MONTH(?)) AND
            (YEAR(bt.cancellation_date) <= YEAR(?) AND MONTH(bt.cancellation_date) <= MONTH(?))
            OR
            (YEAR(bt.report_date) >= YEAR(?) AND MONTH(bt.report_date) >= MONTH(?)) AND
            (YEAR(bt.report_date) <= YEAR(?) AND MONTH(bt.report_date) <= MONTH(?))
        )";
    } elseif($filterType === 'year_range') {
        $dateCondition = "(
            YEAR(bt.datetime) BETWEEN ? AND ?
            OR 
            YEAR(bt.cancellation_date) BETWEEN ? AND ?
            OR
            YEAR(bt.report_date) BETWEEN ? AND ?
        )";
    } elseif($filterType === 'per_day') {
        $dateCondition = "(
            DATE(bt.datetime) = ?
            OR 
            DATE(bt.cancellation_date) = ?
            OR
            DATE(bt.report_date) = ?
        )";
    } elseif($filterType === 'per_month') {
        $dateCondition = "(
            (YEAR(bt.datetime) = YEAR(?) AND MONTH(bt.datetime) = MONTH(?))
            OR 
            (YEAR(bt.cancellation_date) = YEAR(?) AND MONTH(bt.cancellation_date) = MONTH(?))
            OR
            (YEAR(bt.report_date) = YEAR(?) AND MONTH(bt.report_date) = MONTH(?))
        )";
    } elseif($filterType === 'per_year') {
        $dateCondition = "(
            YEAR(bt.datetime) = ?
            OR 
            YEAR(bt.cancellation_date) = ?
            OR
            YEAR(bt.report_date) = ?
        )";
    }

    // Enhanced query structure
    // When Partner = "All": Merges transactions by branch_id, showing "Multiple Partners (X)" format
    // When Partner = specific: Shows individual partner transactions per branch
    // Build the query based on whether we're showing all partners or a specific partner
    if($partner_name_raw === 'All') {
        // When showing all partners, merge transactions by branch_id
        $getDataSql = "WITH all_partners AS (
                            SELECT 
                                mpm.partner_name,
                                mpm.partner_id,
                                mpm.partner_id_kpx
                            FROM masterdata.partner_masterfile AS mpm
                            WHERE mpm.status = 'ACTIVE'
                        ),
                        all_branches AS (
                            SELECT 
                                mbp.zone,
                                mbp.ml_matic_region,
                                mbp.region,
                                mbp.region_code,
                                mbp.`area`,
                                mbp.kp_code,
                                mbp.branch_id,
                                mbp.branch_name
                            FROM masterdata.branch_profile AS mbp
                        ),
                        all_branch_transactions AS (
                            SELECT 
                                bt.branch_id,
                                bt.partner_name,
                                bt.partner_id,
                                bt.partner_id_kpx,
                                MAX(bt.zone_code) AS transaction_zone,
                                MAX(bt.region) AS transaction_region,
                                MAX(bt.outlet) AS transaction_outlet,
                                SUM(bt.charge_to_customer + bt.charge_to_partner) AS charges
                            FROM mldb.billspayment_transaction AS bt
                            WHERE 
                                " . $dateCondition . "
                                AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                                AND bt.outlet NOT IN ('ML CEBU HEAD OFFICE', 'ML HEAD OFFICE', 'CEBU HEAD OFFICE', 'HEAD OFFICE')
                                AND NOT REGEXP_LIKE(bt.payor, '\\bTEST\\b')
                            GROUP BY bt.branch_id, bt.partner_name, bt.partner_id, bt.partner_id_kpx
                        )

                        SELECT 
                            CASE 
                                WHEN COUNT(DISTINCT abt.partner_name) > 1 
                                THEN CONCAT('Multiple Partners (', COUNT(DISTINCT abt.partner_name), ')')
                                ELSE MAX(abt.partner_name)
                            END AS partner_name,
                            GROUP_CONCAT(DISTINCT abt.partner_id ORDER BY abt.partner_name SEPARATOR ', ') AS partner_id,
                            GROUP_CONCAT(DISTINCT abt.partner_id_kpx ORDER BY abt.partner_name SEPARATOR ', ') AS partner_id_kpx,
                            MAX(ab.ml_matic_region) AS ml_matic_region,
                            COALESCE(NULLIF(NULLIF(MAX(ab.zone), ''), '-'), NULLIF(NULLIF(MAX(abt.transaction_zone), ''), '-'), '-') AS zone,
                            COALESCE(NULLIF(NULLIF(MAX(ab.region), ''), '-'), NULLIF(NULLIF(MAX(abt.transaction_region), ''), '-'), '-') AS region,
                            MAX(ab.region_code) AS region_code,
                            MAX(ab.kp_code) AS kp_code,
                            SUM(abt.charges) AS total_charges,
                            MAX(ab.area) AS area,
                            COALESCE(NULLIF(NULLIF(MAX(ab.branch_name), ''), '-'), NULLIF(NULLIF(MAX(abt.transaction_outlet), ''), '-'), '-') AS branch_name,
                            abt.branch_id
                        FROM all_branch_transactions AS abt
                        LEFT JOIN all_branches AS ab ON abt.branch_id = ab.branch_id
                        WHERE 1=1";
    } else {
        // When showing specific partner, keep original grouping
        $getDataSql = "WITH all_partners AS (
                            SELECT 
                                mpm.partner_name,
                                mpm.partner_id,
                                mpm.partner_id_kpx
                            FROM masterdata.partner_masterfile AS mpm
                            WHERE mpm.status = 'ACTIVE'
                        ),
                        all_branches AS (
                            SELECT 
                                mbp.zone,
                                mbp.ml_matic_region,
                                mbp.region,
                                mbp.region_code,
                                mbp.`area`,
                                mbp.kp_code,
                                mbp.branch_id,
                                mbp.branch_name
                            FROM masterdata.branch_profile AS mbp
                        ),
                        all_branch_transactions AS (
                            SELECT 
                                bt.branch_id,
                                bt.partner_name,
                                bt.partner_id,
                                bt.partner_id_kpx,
                                MAX(bt.zone_code) AS transaction_zone,
                                MAX(bt.region) AS transaction_region,
                                MAX(bt.outlet) AS transaction_outlet,
                                SUM(bt.charge_to_customer + bt.charge_to_partner) AS charges
                            FROM mldb.billspayment_transaction AS bt
                            WHERE 
                                " . $dateCondition . "
                                AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                                AND bt.outlet NOT IN ('ML CEBU HEAD OFFICE', 'ML HEAD OFFICE', 'CEBU HEAD OFFICE', 'HEAD OFFICE')
                                AND NOT REGEXP_LIKE(bt.payor, '\\bTEST\\b')
                            GROUP BY bt.branch_id, bt.partner_name, bt.partner_id, bt.partner_id_kpx
                        )

                        SELECT 
                            abt.partner_name,
                            MAX(abt.partner_id) AS partner_id,
                            MAX(abt.partner_id_kpx) AS partner_id_kpx,
                            MAX(ab.ml_matic_region) AS ml_matic_region,
                            COALESCE(NULLIF(NULLIF(MAX(ab.zone), ''), '-'), NULLIF(NULLIF(MAX(abt.transaction_zone), ''), '-'), '-') AS zone,
                            COALESCE(NULLIF(NULLIF(MAX(ab.region), ''), '-'), NULLIF(NULLIF(MAX(abt.transaction_region), ''), '-'), '-') AS region,
                            MAX(ab.region_code) AS region_code,
                            MAX(ab.kp_code) AS kp_code,
                            SUM(abt.charges) AS total_charges,
                            MAX(ab.area) AS area,
                            COALESCE(NULLIF(NULLIF(MAX(ab.branch_name), ''), '-'), NULLIF(NULLIF(MAX(abt.transaction_outlet), ''), '-'), '-') AS branch_name
                        FROM all_branch_transactions AS abt
                        LEFT JOIN all_branches AS ab ON abt.branch_id = ab.branch_id
                        WHERE 1=1";
    }
                    
    // Add partner filter (only when specific partner is selected)
    if($partner_name_raw !== 'All') {
        $getDataSql .= " AND abt.partner_name = ?";
    }
    
    // Add geographic filters
    $geoConditions = array();
    $geoParams = array();
    
    if($mainzone !== 'ALL') {
        if($zone !== 'ALL') {
            if($zone !== 'Showroom') {
                if($region !== 'ALL') {
                    if($region === 'LZN' || $region === 'NCR') {
                        $geoConditions[] = "ab.ml_matic_region = ? AND ab.zone = ?";
                        $geoParams[] = "LNCR " . $zone;
                        $geoParams[] = $region;
                    } elseif($region === 'VIS' || $region === 'MIN') {
                        $geoConditions[] = "ab.ml_matic_region = ? AND ab.zone = ?";
                        $geoParams[] = "VISMIN " . $zone;
                        $geoParams[] = $region;
                    } else {
                        $geoConditions[] = "ab.region_code = ?";
                        $geoParams[] = $region;
                    }
                    
                    if($area !== 'ALL') {
                        $geoConditions[] = "ab.area = ?";
                        $geoParams[] = $area;
                    }
                } else {
                    if($mainzone === 'LNCR') {
                        $geoConditions[] = "ab.zone = ? AND ab.ml_matic_region <> ?";
                        $geoParams[] = $zone;
                        $geoParams[] = $mainzone . " Showroom";
                    } elseif($mainzone === 'VISMIN') {
                        $geoConditions[] = "ab.zone = ? AND ab.ml_matic_region <> ?";
                        $geoParams[] = $zone;
                        $geoParams[] = $mainzone . " Showroom";
                    } else {
                        $geoConditions[] = "ab.ml_matic_region = ?";
                        $geoParams[] = $mainzone . " " . $zone;
                    }
                }
            } else {
                // Showroom
                if($region !== 'ALL') {
                    if($region === 'LZN' || $region === 'NCR') {
                        $geoConditions[] = "ab.ml_matic_region = ? AND ab.zone = ?";
                        $geoParams[] = "LNCR Showroom";
                        $geoParams[] = $region;
                    } elseif($region === 'VIS' || $region === 'MIN') {
                        $geoConditions[] = "ab.ml_matic_region = ? AND ab.zone = ?";
                        $geoParams[] = "VISMIN Showroom";
                        $geoParams[] = $region;
                    } else {
                        // Fallback for non-standard showroom region labels (e.g., LNCR SHOWROOM)
                        $geoConditions[] = "ab.ml_matic_region = ?";
                        $geoParams[] = $mainzone . " Showroom";
                    }
                    
                    if($area !== 'ALL') {
                        $geoConditions[] = "ab.area = ?";
                        $geoParams[] = $area;
                    }
                } else {
                    // REGION ALL
                    if($mainzone === 'LNCR') {
                        $geoConditions[] = "ab.ml_matic_region = ?";
                        $geoParams[] = $mainzone . " ". $zone;
                    } elseif($mainzone === 'VISMIN') {
                        $geoConditions[] = "ab.ml_matic_region = ?";
                        $geoParams[] = $mainzone . " ". $zone;
                    }

                    if($area !== 'ALL') {
                        $geoConditions[] = "ab.area = ?";
                        $geoParams[] = $area;
                    }
                }
            }
        } else {
            // Zone is ALL
            if($region !== 'ALL') {
                if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                    $geoConditions[] = "ab.zone = ?";
                    $geoParams[] = $region;
                } else {
                    $geoConditions[] = "ab.region_code = ?";
                    $geoParams[] = $region;
                }
                
                if($area !== 'ALL') {
                    $geoConditions[] = "ab.area = ?";
                    $geoParams[] = $area;
                }
            } else {
                if ($mainzone === 'LNCR') {
                    $geoConditions[] = "(ab.zone IN ('LZN', 'NCR') OR ab.ml_matic_region = ?)";
                    $geoParams[] = $mainzone . ' Showroom';
                } elseif ($mainzone === 'VISMIN') {
                    $geoConditions[] = "(ab.zone IN ('VIS', 'MIN') OR ab.ml_matic_region = ?)";
                    $geoParams[] = $mainzone . ' Showroom';
                } else {
                    $geoConditions[] = 'ab.ml_matic_region LIKE ?';
                    $geoParams[] = $mainzone . '%';
                }
            }
        }
    } else {
        // Mainzone is ALL
        if($zone !== 'ALL') {
            if($zone !== 'Showroom') {
                if($region !== 'ALL') {
                    if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                        $geoConditions[] = "ab.zone = ?";
                        $geoParams[] = $region;
                    } else {
                        $geoConditions[] = "ab.region_code = ?";
                        $geoParams[] = $region;
                    }
                    
                    if($area !== 'ALL') {
                        $geoConditions[] = "ab.area = ?";
                        $geoParams[] = $area;
                    }
                } else {
                    $geoConditions[] = "ab.zone = ?";
                    $geoParams[] = $zone;
                }
            } else {
                // Showroom
                if($region !== 'ALL') {
                    if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                        $geoConditions[] = "ab.ml_matic_region LIKE ? AND ab.zone = ?";
                        $geoParams[] = "% Showroom";
                        $geoParams[] = $region;
                    }
                    
                    if($area !== 'ALL') {
                        $geoConditions[] = "ab.area = ?";
                        $geoParams[] = $area;
                    }
                } else {
                    $geoConditions[] = "ab.ml_matic_region LIKE ?";
                    $geoParams[] = "% Showroom";
                }
            }
        } else {
            // Zone is ALL
            if($region !== 'ALL') {
                if($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                    $geoConditions[] = "ab.zone = ?";
                    $geoParams[] = $region;
                } else {
                    $geoConditions[] = "ab.region_code = ?";
                    $geoParams[] = $region;
                }
                
                if($area !== 'ALL') {
                    $geoConditions[] = "ab.area = ?";
                    $geoParams[] = $area;
                }
            }
        }
    }
    
    if(!empty($geoConditions)) {
        $getDataSql .= " AND (" . implode(" AND ", $geoConditions) . ")";
    }
    
    // Add different GROUP BY and ORDER BY based on partner selection
    if($partner_name_raw === 'All') {
        // When showing all partners, group by branch_id to merge multiple partners per branch
        $getDataSql .= " GROUP BY abt.branch_id ORDER BY MAX(ab.branch_name)";
    } else {
        // When showing specific partner, group by both branch_id and partner_name
        $getDataSql .= " GROUP BY abt.branch_id, abt.partner_name ORDER BY abt.partner_name";
    }
    
    // Prepare parameters for binding
    $params = array();
    $types = "";
    
    // Add date parameters
    if($filterType === 'date_range') {
        $params = array_merge($params, [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
        $types .= "ssssss";
    } elseif($filterType === 'month_range') {
        $params = array_merge($params, [
            $startDate.'-01', $startDate.'-01', $endDate.'-01', $endDate.'-01',
            $startDate.'-01', $startDate.'-01', $endDate.'-01', $endDate.'-01',
            $startDate.'-01', $startDate.'-01', $endDate.'-01', $endDate.'-01'
        ]);
        $types .= "ssssssssssss";
    } elseif($filterType === 'year_range') {
        $params = array_merge($params, [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
        $types .= "ssssss";
    } elseif($filterType === 'per_day') {
        $params = array_merge($params, [$startDate, $startDate, $startDate]);
        $types .= "sss";
    } elseif($filterType === 'per_month') {
        $params = array_merge($params, [
            $startDate.'-01', $startDate.'-01',
            $startDate.'-01', $startDate.'-01',
            $startDate.'-01', $startDate.'-01'
        ]);
        $types .= "ssssss";
    } elseif($filterType === 'per_year') {
        $params = array_merge($params, [$startDate, $startDate, $startDate]);
        $types .= "sss";
    }
    
    // Add partner parameter
    if($partner_name_raw !== 'All') {
        $params[] = $partner_name_raw;
        $types .= "s";
    }
    
    // Add geographic parameters
    if(!empty($geoParams)) {
        $params = array_merge($params, $geoParams);
        $types .= str_repeat('s', count($geoParams));
    }
    
    // Execute the query
    $stmt = $conn->prepare($getDataSql);
    if(!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = array();
    $totalCharges = 0;
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
        $totalCharges += $row['total_charges'];
    }
    
    // Add debug information for when Partner is "All"
    if($partner_name_raw === 'All') {
        error_log("Partner 'All' selected - Total branches found: " . count($data));
        if(count($data) > 0) {
            error_log("Sample row structure: " . json_encode($data[0]));
        }
    }
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'data' => $data,
        'totalRecords' => count($data),
        'totalCharges' => $totalCharges,
        'debug' => [
            'partnerSelection' => $partner_name_raw,
            'queryType' => $partner_name_raw === 'All' ? 'merged_branches' : 'separate_partners',
            'sampleData' => count($data) > 0 ? $data[0] : null
        ],
        'filters' => [
            'partner' => $partner_name_raw,
            'mainzone' => $mainzone,
            'zone' => $zone,
            'region' => $region,
            'area' => $area,
            'filterType' => $filterType,
            'startDate' => $startDate,
            'endDate' => $endDate ?? null
        ]
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDI Report | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <style>
        #loading-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(33, 37, 41, 0.35);
            align-items: center;
            justify-content: center;
        }

        #loading-overlay.d-none {
            display: none;
        }

        #loading-overlay.d-flex {
            display: flex;
        }

        .scrollable-table {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }

        .scrollable-table table {
            margin-bottom: 0;
        }

        .scrollable-table thead th {
            position: sticky;
            top: 0;
            background-color: var(--bs-light);
            z-index: 10;
            border-bottom: 2px solid #dee2e6;
        }

        .scrollable-table tfoot th {
            position: sticky;
            bottom: 0;
            background-color: var(--bs-dark);
            color: white;
            z-index: 10;
            border-top: 2px solid #dee2e6;
        }

        /* Custom scrollbar styling */
        .scrollable-table::-webkit-scrollbar {
            width: 8px;
        }

        .scrollable-table::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .scrollable-table::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .scrollable-table::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <!-- Show and Hide Side Nav Menu -->
        <?php include '../../../templates/sidebar.php'; ?>
        <div id="loading-overlay" class="d-none" aria-live="polite" aria-busy="true">
            <div class="bg-white rounded-3 shadow p-4 text-center">
                <div class="spinner-border text-danger" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div class="mt-2 fw-semibold text-secondary">Loading EDI report data...</div>
            </div>
        </div>
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-file-export" aria-hidden="true"></i>
                <div>
                    <h2>EDI Report</h2>
                    <p class="bp-section-sub">Export EDI files for reporting</p>
                </div>
            </div>
        </div>
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-18">
                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-end">
                                <!-- Partner List -->
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label">Partners Name:</label>
                                    <select id="partnerlistDropdown" class="form-select select2" aria-label="Select Partner" name="partnerlist" data-placeholder="Search or select a Partner..." required>
                                        <option value="">Select Partner</option>
                                        <option value="All">All</option>
                                        <?php 
                                            if ($partnersResult && mysqli_num_rows($partnersResult) > 0) {
                                                while ($row = mysqli_fetch_assoc($partnersResult)) {
                                                    $partner_names = htmlspecialchars($row['partner_name']);
                                                    $selected = (isset($_GET['partner_name']) && $_GET['partner_name'] == $partner_names) ? 'selected' : '';
                                                    echo "<option value='$partner_names' $selected>" . ucfirst($partner_names) . "</option>";
                                                }
                                            }
                                        ?>
                                    </select>
                                </div>

                                <!-- Main Zone Dropdown -->
                                <div class="col-md-2">
                                    <label class="form-label">Mainzone:</label>
                                    <select class="form-select form-select-sm" required>
                                        <option value="">Select Mainzone</option>
                                        <option value="ALL">ALL</option>
                                        <?php 
                                            while ($row = $mainzoneResult->fetch_assoc()) {
                                                echo '<option value="' . $row['main_zone_code'] . '">' . $row['main_zone_code'] . '</option>';
                                            }
                                        ?>
                                    </select>
                                </div>

                                <!-- Zone Dropdown -->
                                <div class="col-md-2">
                                    <label class="form-label">Zone:</label>
                                    <select class="form-select form-select-sm" required>
                                        <option value="">Select Zone</option>
                                        <!-- options will be populated by JS when Source File Type is selected -->
                                    </select>
                                </div>

                                <!-- Region Dropdown -->
                                <div class="col-md-2">
                                    <label class="form-label">Region:</label>
                                    <select class="form-select form-select-sm" required>
                                        <option value="">Select Region</option>
                                        <!-- options will be populated by JS when Source File Type is selected -->
                                    </select>
                                </div>

                                <!-- Area Dropdown -->
                                <div class="col-md-2">
                                    <label class="form-label">Area:</label>
                                    <select class="form-select form-select-sm" required>
                                        <option value="">Select Area</option>
                                        <!-- options will be populated by JS when Source File Type is selected -->
                                    </select>
                                </div>

                                <!-- Time Frame Dropdown -->
                                <div class="col-md-2">
                                    <label class="form-label">Time Frame:</label>
                                    <select class="form-select form-select-sm" required>
                                        <option value="">Select Time Frame</option>
                                        <option value="per_day">Per Day</option>
                                        <option value="date_range">Date Range</option>
                                        <option value="per_month">Per Month</option>
                                        <option value="month_range">Monthly Range</option>
                                        <option value="per_year">Per Year</option>
                                        <option value="year_range">Yearly Range</option>
                                    </select>
                                </div>

                                <!-- Transaction Date (Per Day, Date Range, Per Month, Monthly Range, Per Year, Yearly Range) -->
                                <div class="col-md-3" style="display: none;">
                                    <label class="form-label">Transaction Date:</label>
                                    <div class="d-flex gap-1 align-items-center">
                                        <span class="text-nowrap small" id="startDateLabel">From:</span>
                                        <input type="date" class="form-control form-control-sm" id="startDateInput" required>
                                        <span class="text-nowrap small" id="endDateLabel">To:</span>
                                        <input type="date" class="form-control form-control-sm" id="endDateInput" required>
                                    </div>
                                </div>

                                <!-- Action Submit Button When final Dropdown is selected -->
                                <div class="col-md-2 d-flex align-items-end gap-2">
                                    <input type="submit" class="btn btn-secondary btn-sm flex-fill" name="upload" value="Proceed" disabled>
                                    <button class="btn btn-danger btn-sm flex-fill" id="exportButton" type="button">Export to Excel</button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="container-fluid">
                                <!-- Horizontal Layout: Filter Card and Table -->
                                <div class="row">
                                    <!-- Filter Result Card - Left Side (30%) -->
                                    <div class="col-lg-3 col-md-4">
                                        <div class="card mb-4 h-100" id="filterResultCard">
                                            <div class="card-header bg-danger text-white">
                                                <h5 class="card-title mb-0">
                                                    <i class="fa-solid fa-filter me-2"></i>
                                                    Filter Result
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <div class="row mb-2">
                                                        <div class="col-5"><strong>Partner:</strong></div>
                                                        <div class="col-7"><span id="filterPartner" class="text-muted">-</span></div>
                                                    </div>
                                                    <div class="row mb-2">
                                                        <div class="col-5"><strong>Mainzone:</strong></div>
                                                        <div class="col-7"><span id="filterMainzone" class="text-muted">-</span></div>
                                                    </div>
                                                    <div class="row mb-2">
                                                        <div class="col-5"><strong>Zone:</strong></div>
                                                        <div class="col-7"><span id="filterZone" class="text-muted">-</span></div>
                                                    </div>
                                                    <div class="row mb-2">
                                                        <div class="col-5"><strong>Region:</strong></div>
                                                        <div class="col-7"><span id="filterRegion" class="text-muted">-</span></div>
                                                    </div>
                                                    <div class="row mb-2">
                                                        <div class="col-5"><strong>Area:</strong></div>
                                                        <div class="col-7"><span id="filterArea" class="text-muted">-</span></div>
                                                    </div>
                                                    <div class="row mb-2">
                                                        <div class="col-5"><strong>Time Frame:</strong></div>
                                                        <div class="col-7"><span id="filterTimeFrame" class="text-muted">-</span></div>
                                                    </div>
                                                    <!-- Time Frame Based Filters populate at javascript -->
                                                </div>
                                                
                                                <div class="alert alert-info mb-0 p-2">
                                                    <div class="small">
                                                        <div><strong>Records:</strong> <span id="totalRecords">0</span></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Table - Right Side (70%) -->
                                    <div class="col-lg-9 col-md-8">
                                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                            <table id="transactionReportTable" class="table table-bordered table-hover table-striped">
                                                <thead class="table-light  sticky-top">
                                                    <tr>
                                                        <th>Zone</th>
                                                        <th>Region Name</th>
                                                        <th>KP Code</th>
                                                        <th>Branch Name</th>
                                                        <th>Charges</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <!-- Data will be populated via JavaScript -->
                                                </tbody>
                                                <tfoot class="sticky-bottom table-dark">
                                                    <!-- Footer will be added by JavaScript -->
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../../../templates/footer.php'; ?>

<!-- PARTNER -->
<script>
    // Initialize Select2 for partner dropdown
    $('#partnerlistDropdown').select2({
        placeholder: 'Search or select a Partner...',
        allowClear: true
    });
</script>

<!-- ZONING -->
<script>
$(document).ready(function() {
    // Fixed selectors - corrected indexing based on actual HTML structure
    const partnerSelect = $('#partnerlistDropdown'); // Partner dropdown
    const mainzoneSelect = $('.col-md-2').eq(1).find('select'); // Mainzone dropdown (second .col-md-2)
    const zoneSelect = $('.col-md-2').eq(2).find('select'); // Zone dropdown (third .col-md-2)
    const regionSelect = $('.col-md-2').eq(3).find('select'); // Region dropdown (fourth .col-md-2)
    const areaSelect = $('.col-md-2').eq(4).find('select'); // Area dropdown (fifth .col-md-2)
    const timeFrameSelect = $('.col-md-2').eq(5).find('select'); // Time Frame dropdown (sixth .col-md-2)

    // Debug: Check if selectors are working
    console.log('Mainzone select:', mainzoneSelect.length);
    console.log('Zone select:', zoneSelect.length);

    // Handle mainzone selection change
    mainzoneSelect.on('change', function() {
        const selectedValue = $(this).val();
        console.log('Mainzone selected:', selectedValue); // Debug log

        if(selectedValue !== ''){
            if (selectedValue === 'ALL') {
                // Show Zone and Filter Type for ALL
                $.ajax({
                    type: 'POST',
                    data: { 
                        action: 'get_zones',
                        mainzone: selectedValue 
                    },
                    success: function(response) {
                        console.log('Zone response:', response); // Debug log
                        let options = '<option value="">Select Zone</option><option value="ALL">ALL</option>';
                        options += response;
                        options += '<option value="Showroom">SHOWROOM</option>';
                        zoneSelect.html(options);
                    },
                    error: function() {
                        zoneSelect.html('<option value="">Select Zone</option><option value="ALL">ALL</option><option value="Showroom">SHOWROOM</option>');
                    }
                });
            }else{
                // For any other selected mainzone, make AJAX call to get zones
                $.ajax({
                    type: 'POST',
                    data: { 
                        action: 'get_zones',
                        mainzone: selectedValue 
                    },
                    success: function(response) {
                        console.log('Zone response for', selectedValue, ':', response); // Debug log
                        let options = '<option value="">Select Zone</option><option value="ALL">ALL</option>';
                        options += response;
                        options += '<option value="Showroom">SHOWROOM</option>';
                        zoneSelect.html(options);
                    },
                    error: function() {
                        console.log('AJAX error for mainzone:', selectedValue); // Debug log
                        zoneSelect.html('<option value="">Select Zone</option><option value="ALL">ALL</option><option value="Showroom">SHOWROOM</option>');
                    }
                });
            }
        }else{
            zoneSelect.html('<option value="">Select Zone</option>');
            regionSelect.html('<option value="">Select Region</option>');
            areaSelect.html('<option value="">Select Area</option>');
        }
        // Remove this line if checkFormValidity function doesn't exist
        // checkFormValidity();
    });

    // Handle zone selection change
    zoneSelect.on('change', function() {
        const selectedZone = $(this).val();
        const mainzoneValue = mainzoneSelect.val();
        const zoneValue = selectedZone;
        
        // Add AJAX call to populate regions based on selected zone
        if (selectedZone !== '') {
            if(mainzoneValue !== 'ALL'){ // (LNCR, VISMIN)
                if(selectedZone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (LNCR, VISMIN)
                    if (selectedZone !== 'Showroom') { // (LZN,NCR,VIS,MIN), (LNCR, VISMIN)
                        $.ajax({
                            type: 'POST',
                            data: { 
                                action: 'get_regions',
                                mainzone: mainzoneValue,
                                zone: selectedZone 
                            },
                            success: function(response) {
                                let regionOptions = '<option value="">Select Region</option><option value="ALL">ALL</option>';
                                regionOptions += response;
                                regionSelect.html(regionOptions);
                            },
                            error: function() {
                                regionSelect.html('<option value="">Select Region</option><option value="ALL">ALL</option>');
                            }
                        });

                    }else{ // (Showroom) (LNCR, VISMIN)
                        let regionOptions = '<option value="">Select Region</option><option value="ALL">ALL</option>';
                        if(mainzoneValue === 'VISMIN'){
                            regionOptions += '<option value="VIS">VISAYAS SHOWROOM</option>';
                            regionOptions += '<option value="MIN">MINDANAO SHOWROOM</option>';
                        }
                        if(mainzoneValue === 'LNCR'){
                            regionOptions += '<option value="LZN">LUZON SHOWROOM</option>';
                            regionOptions += '<option value="NCR">NCR SHOWROOM</option>';
                        }
                        regionSelect.html(regionOptions);
                    }
                }else{ // (ALL) (LNCR, VISMIN)
                    $.ajax({
                        type: 'POST',
                        data: { 
                            action: 'get_regions',
                            mainzone: mainzoneValue,
                            zone: zoneValue 
                        },
                        success: function(response) {
                            let regionOptions = '<option value="">Select Region</option><option value="ALL">ALL</option>';
                            regionOptions += response;
                            regionOptions += '<option value="' + mainzoneValue + ' Showroom">' + mainzoneValue + ' SHOWROOM</option>';
                            regionSelect.html(regionOptions);
                        },
                        error: function() {
                            regionSelect.html('<option value="">Select Region</option><option value="ALL">ALL</option>');
                        }
                    });
                }
            }else{ // (ALL)
                if(selectedZone !== 'ALL'){ // (LZN,NCR,VIS,MIN,SHOWROOM), (ALL)
                    if(selectedZone !== 'Showroom'){ // (LZN,NCR,VIS,MIN), (ALL)
                        $.ajax({
                            type: 'POST',
                            data: { 
                                action: 'get_regions',
                                mainzone: mainzoneValue,
                                zone: selectedZone 
                            },
                            success: function(response) {
                                let regionOptions = '<option value="">Select Region</option><option value="ALL">ALL</option>';
                                regionOptions += response;
                                if(selectedZone === 'LZN'){
                                    regionOptions += '<option value="LZN">LUZON SHOWROOM</option>';
                                }else if (selectedZone === 'NCR'){
                                    regionOptions += '<option value="NCR">NCR SHOWROOM</option>';
                                }else if (selectedZone === 'VIS'){
                                    regionOptions += '<option value="VIS">VISAYAS SHOWROOM</option>';
                                }else if (selectedZone === 'MIN'){
                                    regionOptions += '<option value="MIN">MINDANAO SHOWROOM</option>';
                                }
                                regionSelect.html(regionOptions);
                            },
                            error: function() {
                                regionSelect.html('<option value="">Select Region</option><option value="ALL">ALL</option>');
                            }
                        });

                    }else{ // (Showroom) (ALL)
                        let regionOptions = '<option value="">Select Region</option><option value="ALL">ALL</option>';
                        regionOptions += '<option value="LZN">LUZON SHOWROOM</option>';
                        regionOptions += '<option value="NCR">NCR SHOWROOM</option>';
                        regionOptions += '<option value="VIS">VISAYAS SHOWROOM</option>';
                        regionOptions += '<option value="MIN">MINDANAO SHOWROOM</option>';
                        regionSelect.html(regionOptions);
                    }
                }else{ // (ALL) (ALL)
                    $.ajax({
                        type: 'POST',
                        data: { 
                            action: 'get_regions',
                            mainzone: mainzoneValue,
                            zone: zoneValue 
                        },
                        success: function(response) {
                            let regionOptions = '<option value="">Select Region</option><option value="ALL">ALL</option>';
                            regionOptions += response;
                            regionOptions += '<option value="LZN">LUZON SHOWROOM</option>';
                            regionOptions += '<option value="NCR">NCR SHOWROOM</option>';
                            regionOptions += '<option value="VIS">VISAYAS SHOWROOM</option>';
                            regionOptions += '<option value="MIN">MINDANAO SHOWROOM</option>';
                            regionSelect.html(regionOptions);
                        },
                        error: function() {
                            regionSelect.html('<option value="">Select Region</option><option value="ALL">ALL</option>');
                        }
                    });
                }
            }
        }else{
            regionSelect.html('<option value="">Select Region</option>');
            areaSelect.html('<option value="">Select Area</option>');
        }
        // Remove this line if checkFormValidity function doesn't exist
        // checkFormValidity();
    });

    // Handle region selection change
    regionSelect.on('change', function() {
        const selectedRegion = $(this).val();
        const mainzoneValue = mainzoneSelect.val();
        const zoneValue = zoneSelect.val();
        
        // Add AJAX call to populate areas based on selected region
        if (selectedRegion !== '') {
            $.ajax({
                type: 'POST',
                data: { 
                    action: 'get_areas',
                    mainzone: mainzoneValue,
                    zone: zoneValue,
                    region: selectedRegion 
                },
                success: function(response) {
                    console.log('Area response:', response); // Debug log
                    console.log('Request data:', { // Debug log
                        mainzone: mainzoneValue,
                        zone: zoneValue,
                        region: selectedRegion
                    });
                    let areaOptions = '<option value="">Select Area</option><option value="ALL">ALL</option>';
                    areaOptions += response;
                    areaSelect.html(areaOptions);
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error for region:', selectedRegion); // Debug log
                    console.log('Error details:', xhr.responseText); // Debug log
                    areaSelect.html('<option value="">Select Area</option><option value="ALL">ALL</option>');
                }
            });
        } else {
            areaSelect.html('<option value="">Select Area</option>');
        }
        
        // Remove this line if checkFormValidity function doesn't exist
        // checkFormValidity();
    });
});
</script>

<!-- TIME FRAME -->
<script>
$(document).ready(function() {
    const timeFrameSelect = $('.col-md-2').eq(5).find('select'); // Time Frame dropdown
    const dateContainer = $('.col-md-3'); // Transaction Date container
    const startDateInput = $('#startDateInput');
    const endDateInput = $('#endDateInput');
    const startDateLabel = $('#startDateLabel');
    const endDateLabel = $('#endDateLabel');

    // Handle time frame selection change
    timeFrameSelect.on('change', function() {
        const selectedTimeFrame = $(this).val();
        
        if (selectedTimeFrame === '') {
            // Hide date container if no time frame selected
            dateContainer.hide();
            startDateInput.prop('required', false);
            endDateInput.prop('required', false);
        } else {
            // Show date container
            dateContainer.show();
            
            switch(selectedTimeFrame) {
                case 'per_day':
                    // Single date field for Per Day
                    startDateLabel.text('Date:');
                    startDateInput.prop('required', true).show();
                    endDateLabel.hide();
                    endDateInput.prop('required', false).hide();
                    startDateInput.attr('type', 'date');
                    break;
                    
                case 'per_month':
                    // Single month field for Per Month
                    startDateLabel.text('Month:');
                    startDateInput.prop('required', true).show();
                    endDateLabel.hide();
                    endDateInput.prop('required', false).hide();
                    startDateInput.attr('type', 'month');
                    break;
                    
                case 'per_year':
                    // Single year field for Per Year
                    startDateLabel.text('Year:');
                    startDateInput.prop('required', true).show();
                    endDateLabel.hide();
                    endDateInput.prop('required', false).hide();
                    startDateInput.attr('type', 'number');
                    startDateInput.attr('min', '2000');
                    startDateInput.attr('max', new Date().getFullYear());
                    startDateInput.attr('placeholder', 'YYYY');
                    break;
                    
                case 'date_range':
                    // Two date fields for Date Range
                    startDateLabel.text('From:').show();
                    endDateLabel.text('To:').show();
                    startDateInput.prop('required', true).show();
                    endDateInput.prop('required', true).show();
                    startDateInput.attr('type', 'date');
                    endDateInput.attr('type', 'date');
                    startDateInput.removeAttr('min max placeholder');
                    break;
                    
                case 'month_range':
                    // Two month fields for Monthly Range
                    startDateLabel.text('From:').show();
                    endDateLabel.text('To:').show();
                    startDateInput.prop('required', true).show();
                    endDateInput.prop('required', true).show();
                    startDateInput.attr('type', 'month');
                    endDateInput.attr('type', 'month');
                    startDateInput.removeAttr('min max placeholder');
                    break;
                    
                case 'year_range':
                    // Two year fields for Yearly Range
                    startDateLabel.text('From:').show();
                    endDateLabel.text('To:').show();
                    startDateInput.prop('required', true).show();
                    endDateInput.prop('required', true).show();
                    startDateInput.attr('type', 'number');
                    endDateInput.attr('type', 'number');
                    startDateInput.attr('min', '2000');
                    startDateInput.attr('max', new Date().getFullYear());
                    endDateInput.attr('min', '2000');
                    endDateInput.attr('max', new Date().getFullYear());
                    startDateInput.attr('placeholder', 'YYYY');
                    endDateInput.attr('placeholder', 'YYYY');
                    break;
                    
                default:
                    dateContainer.hide();
                    startDateInput.prop('required', false);
                    endDateInput.prop('required', false);
            }
        }
        
        // Clear previous values when changing time frame
        startDateInput.val('');
        endDateInput.val('');
    });

    // Add validation for date ranges
    startDateInput.on('change', function() {
        const timeFrame = timeFrameSelect.val();
        if (['date_range', 'month_range', 'year_range'].includes(timeFrame)) {
            endDateInput.attr('min', $(this).val());
        }
    });

    endDateInput.on('change', function() {
        const timeFrame = timeFrameSelect.val();
        if (['date_range', 'month_range', 'year_range'].includes(timeFrame)) {
            startDateInput.attr('max', $(this).val());
        }
    });
});
</script>

<!-- ACTION -->
<script>
$(document).ready(function() {
    // Get all the form elements
    const partnerSelect = $('#partnerlistDropdown');
    const mainzoneSelect = $('.col-md-2').eq(1).find('select');
    const zoneSelect = $('.col-md-2').eq(2).find('select');
    const regionSelect = $('.col-md-2').eq(3).find('select');
    const areaSelect = $('.col-md-2').eq(4).find('select');
    const timeFrameSelect = $('.col-md-2').eq(5).find('select');
    const startDateInput = $('#startDateInput');
    const endDateInput = $('#endDateInput');
    const proceedButton = $('input[name="upload"]');
    const exportButton = $('#exportButton');

    // Function to check if all required fields are filled
    function checkFormValidity() {
        const partner = partnerSelect.val();
        const mainzone = mainzoneSelect.val();
        const zone = zoneSelect.val();
        const region = regionSelect.val();
        const area = areaSelect.val();
        const timeFrame = timeFrameSelect.val();
        const startDate = startDateInput.val();
        const endDate = endDateInput.val();

        // Check if basic fields are filled
        let isValid = partner && mainzone && zone && region && area && timeFrame;

        // Check date fields based on time frame
        if (timeFrame && isValid) {
            switch(timeFrame) {
                case 'per_day':
                case 'per_month':
                case 'per_year':
                    // Single date field required
                    isValid = isValid && startDate;
                    break;
                case 'date_range':
                case 'month_range':
                case 'year_range':
                    // Both date fields required
                    isValid = isValid && startDate && endDate;
                    break;
            }
        }

        // Enable/disable button and change appearance
        if (isValid) {
            proceedButton.prop('disabled', false);
            proceedButton.removeClass('btn-secondary').addClass('btn-danger');
        } else {
            proceedButton.prop('disabled', true);
            proceedButton.removeClass('btn-danger').addClass('btn-secondary');
        }
    }

    // Attach change events to all form elements
    partnerSelect.on('change', checkFormValidity);
    mainzoneSelect.on('change', checkFormValidity);
    zoneSelect.on('change', checkFormValidity);
    regionSelect.on('change', checkFormValidity);
    areaSelect.on('change', checkFormValidity);
    timeFrameSelect.on('change', checkFormValidity);
    startDateInput.on('change input', checkFormValidity);
    endDateInput.on('change input', checkFormValidity);

    // Initial check on page load
    checkFormValidity();

    // Handle export button click
    exportButton.on('click', function(e) {
        e.preventDefault();

        if (proceedButton.prop('disabled')) {
            Swal.fire({
                title: 'Incomplete Filters',
                text: 'Please complete all required filters and click Proceed first.',
                icon: 'warning'
            });
            return;
        }

        const exportParams = {
            partner_name: partnerSelect.val(),
            mainzone: mainzoneSelect.val(),
            zone: zoneSelect.val(),
            region: regionSelect.val(),
            area: areaSelect.val(),
            filterType: timeFrameSelect.val(),
            startDate: startDateInput.val(),
            endDate: endDateInput.val()
        };

        const exportUrl = '../../../models/generate/excel/generate-excel-edi-report.php?' + $.param(exportParams);
        window.location.href = exportUrl;
    });

    // Handle form submission
    proceedButton.on('click', function(e) {
        e.preventDefault(); // Prevent default form submission
        
        if (!$(this).prop('disabled')) {
            // Verify table exists before proceeding
            const $table = $('#transactionReportTable');
            console.log('Table element found:', $table.length > 0);
            console.log('Table tbody exists:', $table.find('tbody').length > 0);
            console.log('Table tfoot exists:', $table.find('tfoot').length > 0);
            
            // Show loading state
            $('#loading-overlay').removeClass('d-none').addClass('d-flex');
            proceedButton.prop('disabled', true);
            
            // Collect all form data
            const formData = {
                action: 'get_report_data',
                partner_name: partnerSelect.val(),
                mainzone: mainzoneSelect.val(),
                zone: zoneSelect.val(),
                region: regionSelect.val(),
                area: areaSelect.val(),
                filterType: timeFrameSelect.val(),
                startDate: startDateInput.val(),
                endDate: endDateInput.val()
            };

            console.log('Form submitted with data:', formData);
            
            // AJAX call to get report data
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    console.log('AJAX Success - Raw response:', response);

                    if (response && response.success) {
                        console.log('Report data received successfully');
                        console.log('Debug info:', response.debug);
                        console.log('Partner selection:', response.filters.partner);

                        // Update filter display
                        try {
                            if (typeof updateFilterResult === 'function') {
                                updateFilterResult(response.filters);
                                console.log('Filter result updated successfully');
                            }
                        } catch (filterError) {
                            console.error('Error updating filter result:', filterError);
                        }

                        // Render data table using robust renderer
                        try {
                            if (typeof renderTableFromResponse === 'function') {
                                renderTableFromResponse(response);
                                console.log('Table rendered using renderTableFromResponse');
                            } else if (typeof updateDataTable === 'function') {
                                updateDataTable(response.data, response.totalCharges);
                                console.log('Table rendered using updateDataTable');
                            } else {
                                throw new Error('No table rendering function available');
                            }
                        } catch (tableError) {
                            console.error('Error rendering table:', tableError);
                            // Fallback: manually create table
                            try {
                                const data = response.data || [];
                                window.updateDataTable(data, response.totalCharges || 0);
                            } catch (fallbackError) {
                                console.error('Fallback table rendering also failed:', fallbackError);
                            }
                        }

                        // Update total records
                        try {
                            const totalRecords = (response.totalRecords || 0);
                            $('#totalRecords').text(totalRecords.toLocaleString());
                            console.log('Total records updated:', totalRecords);
                        } catch (recordsError) {
                            console.error('Error updating total records:', recordsError);
                        }

                        // Show success or no-data notice
                        if ((response.totalRecords || 0) === 0) {
                            Swal.fire({
                                title: 'No Results',
                                text: 'No records found for the selected filters.',
                                icon: 'info',
                                timer: 2500,
                                showConfirmButton: false,
                                toast: true,
                                position: 'top-end'
                            });
                        } else {
                            Swal.fire({
                                title: 'Success!',
                                text: `Report generated successfully. Found ${response.totalRecords} records.`,
                                icon: 'success',
                                timer: 3000,
                                showConfirmButton: false,
                                toast: true,
                                position: 'top-end'
                            });
                        }
                    } else {
                        console.error('Report generation failed - Invalid response:', response);
                        Swal.fire({
                            title: 'Error!',
                            text: response && response.message ? response.message : 'Failed to generate report. Please check your filters and try again.',
                            icon: 'error'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error Details:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status,
                        readyState: xhr.readyState
                    });
                    
                    let errorMessage = 'Failed to generate report. Please try again.';
                    
                    // Try to provide more specific error messages
                    if (xhr.status === 500) {
                        errorMessage = 'Server error occurred. Please check your database connection and try again.';
                    } else if (xhr.status === 404) {
                        errorMessage = 'Request endpoint not found. Please contact administrator.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'Network error. Please check your internet connection.';
                    } else if (xhr.responseText) {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.message) {
                                errorMessage = errorResponse.message;
                            }
                        } catch (parseError) {
                            console.error('Failed to parse error response:', parseError);
                        }
                    }
                    
                    Swal.fire({
                        title: 'Error!',
                        text: errorMessage,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                },
                complete: function() {
                    $('#loading-overlay').removeClass('d-flex').addClass('d-none');
                    checkFormValidity();
                }
            });
        }
    });
});
</script>

<!-- DISPLAY FILTER RESULT -->
<script>
$(document).ready(function() {
    const proceedButton = $('input[name="upload"]');

    window.toggleExportButtonByTotal = function(totalCharges) {
        const numericTotal = parseFloat(totalCharges || 0);
        if (numericTotal > 0) {
            $('#exportButton').show();
        } else {
            $('#exportButton').hide();
        }
    }
    
    // Function to update filter result display (exposed on window)
    window.updateFilterResult = function(filters = null) {
        const partnerSelect = $('#partnerlistDropdown');
        const mainzoneSelect = $('.col-md-2').eq(1).find('select');
        const zoneSelect = $('.col-md-2').eq(2).find('select');
        const regionSelect = $('.col-md-2').eq(3).find('select');
        const areaSelect = $('.col-md-2').eq(4).find('select');
        const timeFrameSelect = $('.col-md-2').eq(5).find('select');
        const startDateInput = $('#startDateInput');
        const endDateInput = $('#endDateInput');        
        
        // Use provided filters or get from form elements
        if (filters) {
            // Update display using provided filter data
            $('#filterPartner').text(filters.partner === 'All' ? 'Multiple Partners' : filters.partner).removeClass('text-muted').addClass('text-dark fw-bold');
            $('#filterMainzone').text(filters.mainzone === 'ALL' ? 'All Mainzones' : filters.mainzone).removeClass('text-muted').addClass('text-dark fw-bold');
            $('#filterZone').text(filters.zone === 'ALL' ? 'All Zones' : filters.zone).removeClass('text-muted').addClass('text-dark fw-bold');
            $('#filterRegion').text(filters.region === 'ALL' ? 'All Regions' : filters.region).removeClass('text-muted').addClass('text-dark fw-bold');
            $('#filterArea').text(filters.area === 'ALL' ? 'All Areas' : filters.area).removeClass('text-muted').addClass('text-dark fw-bold');
            $('#filterTimeFrame').text(filters.filterType.replace('_', ' ').toUpperCase()).removeClass('text-muted').addClass('text-dark fw-bold');
            
            // Handle date display
            displayFilterDates(filters.filterType, filters.startDate, filters.endDate);
            return;
        }

        // Get selected values and text
        const partnerValue = partnerSelect.val();
        const partnerText = partnerValue ? partnerSelect.find('option:selected').text() : '-';
        
        const mainzoneValue = mainzoneSelect.val();
        const mainzoneText = mainzoneValue ? mainzoneSelect.find('option:selected').text() : '-';
        
        const zoneValue = zoneSelect.val();
        const zoneText = zoneValue ? zoneSelect.find('option:selected').text() : '-';
        
        const regionValue = regionSelect.val();
        const regionText = regionValue ? regionSelect.find('option:selected').text() : '-';
        
        const areaValue = areaSelect.val();
        const areaText = areaValue ? areaSelect.find('option:selected').text() : '-';
        
        const timeFrameValue = timeFrameSelect.val();
        const timeFrameText = timeFrameValue ? timeFrameSelect.find('option:selected').text() : '-';
        
        const startDate = startDateInput.val();
        const endDate = endDateInput.val();

        // Update filter result display
        $('#filterPartner').text(partnerText).removeClass('text-muted').addClass(partnerValue ? 'text-dark fw-bold' : 'text-muted');
        $('#filterMainzone').text(mainzoneText).removeClass('text-muted').addClass(mainzoneValue ? 'text-dark fw-bold' : 'text-muted');
        $('#filterZone').text(zoneText).removeClass('text-muted').addClass(zoneValue ? 'text-dark fw-bold' : 'text-muted');
        $('#filterRegion').text(regionText).removeClass('text-muted').addClass(regionValue ? 'text-dark fw-bold' : 'text-muted');
        $('#filterArea').text(areaText).removeClass('text-muted').addClass(areaValue ? 'text-dark fw-bold' : 'text-muted');
        $('#filterTimeFrame').text(timeFrameText).removeClass('text-muted').addClass(timeFrameValue ? 'text-dark fw-bold' : 'text-muted');
        $('#filterTransactionDate').text(timeFrameText).removeClass('text-muted').addClass(timeFrameValue ? 'text-dark fw-bold' : 'text-muted');

        // Handle time frame based date display
        const timeFrameContainer = $('#filterTimeFrame').parent().parent();
        
        // Remove existing date rows
        timeFrameContainer.nextAll('.time-frame-date').remove();
        
        if (timeFrameValue) {
            let dateHTML = '';
            
            switch(timeFrameValue) {
                case 'per_day':
                    if (startDate) {
                        const formattedDate = new Date(startDate).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: '2-digit'
                        });
                        dateHTML = `
                            <div class="row mb-2 time-frame-date">
                                <div class="col-5"><strong>Date:</strong></div>
                                <div class="col-7"><span class="text-dark fw-bold">${formattedDate}</span></div>
                            </div>
                        `;
                    }
                    break;
                    
                case 'per_month':
                    if (startDate) {
                        const formattedMonth = new Date(startDate + '-01').toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long'
                        });
                        dateHTML = `
                            <div class="row mb-2 time-frame-date">
                                <div class="col-5"><strong>Month:</strong></div>
                                <div class="col-7"><span class="text-dark fw-bold">${formattedMonth}</span></div>
                            </div>
                        `;
                    }
                    break;
                    
                case 'per_year':
                    if (startDate) {
                        dateHTML = `
                            <div class="row mb-2 time-frame-date">
                                <div class="col-5"><strong>Year:</strong></div>
                                <div class="col-7"><span class="text-dark fw-bold">${startDate}</span></div>
                            </div>
                        `;
                    }
                    break;
                    
                case 'date_range':
                    if (startDate && endDate) {
                        const formattedStartDate = new Date(startDate).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: '2-digit'
                        });
                        const formattedEndDate = new Date(endDate).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: '2-digit'
                        });
                        dateHTML = `
                            <div class="row mb-2 time-frame-date">
                                <div class="col-5"><strong>Date Range:</strong></div>
                                <div class="col-7"><span class="text-dark fw-bold">${formattedStartDate} to ${formattedEndDate}</span></div>
                            </div>
                        `;
                    }
                    break;
                    
                case 'month_range':
                    if (startDate && endDate) {
                        const formattedStartMonth = new Date(startDate + '-01').toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long'
                        });
                        const formattedEndMonth = new Date(endDate + '-01').toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long'
                        });
                        dateHTML = `
                            <div class="row mb-2 time-frame-date">
                                <div class="col-5"><strong>Month Range:</strong></div>
                                <div class="col-7"><span class="text-dark fw-bold">${formattedStartMonth} to ${formattedEndMonth}</span></div>
                            </div>
                        `;
                    }
                    break;
                    
                case 'year_range':
                    if (startDate && endDate) {
                        dateHTML = `
                            <div class="row mb-2 time-frame-date">
                                <div class="col-5"><strong>Year Range:</strong></div>
                                <div class="col-7"><span class="text-dark fw-bold">${startDate} to ${endDate}</span></div>
                            </div>
                        `;
                    }
                    break;
            }
            
            // Insert the date HTML after the time frame row
            if (dateHTML) {
                timeFrameContainer.after(dateHTML);
            }
        }
        
        // You can add logic here to update the record count
        // For now, keeping it as 0 - you can update this based on your data
        $('#totalRecords').text('Loading...');
        
        console.log('Filter Result Updated with data:', {
            partner: partnerText,
            mainzone: mainzoneText,
            zone: zoneText,
            region: regionText,
            area: areaText,
            timeFrame: timeFrameText,
            startDate: startDate,
            endDate: endDate
        });
    }

    
    // Function to display filter dates (exposed on window)
    window.displayFilterDates = function(filterType, startDate, endDate) {
        const timeFrameContainer = $('#filterTimeFrame').parent().parent();
        
        // Remove existing date rows
        timeFrameContainer.nextAll('.time-frame-date').remove();
        
        let dateHTML = '';
        
        switch(filterType) {
            case 'per_day':
                if (startDate) {
                    const formattedDate = new Date(startDate).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: '2-digit'
                    });
                    dateHTML = `
                        <div class="row mb-2 time-frame-date">
                            <div class="col-5"><strong>Date:</strong></div>
                            <div class="col-7"><span class="text-dark fw-bold">${formattedDate}</span></div>
                        </div>
                    `;
                }
                break;
                
            case 'per_month':
                if (startDate) {
                    const formattedMonth = new Date(startDate + '-01').toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long'
                    });
                    dateHTML = `
                        <div class="row mb-2 time-frame-date">
                            <div class="col-5"><strong>Month:</strong></div>
                            <div class="col-7"><span class="text-dark fw-bold">${formattedMonth}</span></div>
                        </div>
                    `;
                }
                break;
                
            case 'per_year':
                if (startDate) {
                    dateHTML = `
                        <div class="row mb-2 time-frame-date">
                            <div class="col-5"><strong>Year:</strong></div>
                            <div class="col-7"><span class="text-dark fw-bold">${startDate}</span></div>
                        </div>
                    `;
                }
                break;
                
            case 'date_range':
                if (startDate && endDate) {
                    const formattedStartDate = new Date(startDate).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: '2-digit'
                    });
                    const formattedEndDate = new Date(endDate).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: '2-digit'
                    });
                    dateHTML = `
                        <div class="row mb-2 time-frame-date">
                            <div class="col-5"><strong>Date Range:</strong></div>
                            <div class="col-7"><span class="text-dark fw-bold">${formattedStartDate} to ${formattedEndDate}</span></div>
                        </div>
                    `;
                }
                break;
                
            case 'month_range':
                if (startDate && endDate) {
                    const formattedStartMonth = new Date(startDate + '-01').toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long'
                    });
                    const formattedEndMonth = new Date(endDate + '-01').toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long'
                    });
                    dateHTML = `
                        <div class="row mb-2 time-frame-date">
                            <div class="col-5"><strong>Month Range:</strong></div>
                            <div class="col-7"><span class="text-dark fw-bold">${formattedStartMonth} to ${formattedEndMonth}</span></div>
                        </div>
                    `;
                }
                break;
                
            case 'year_range':
                if (startDate && endDate) {
                    dateHTML = `
                        <div class="row mb-2 time-frame-date">
                            <div class="col-5"><strong>Year Range:</strong></div>
                            <div class="col-7"><span class="text-dark fw-bold">${startDate} to ${endDate}</span></div>
                        </div>
                    `;
                }
                break;
        }
        
        // Insert the date HTML after the time frame row
        if (dateHTML) {
            timeFrameContainer.after(dateHTML);
        }
        
        console.log('Filter Result Updated with data:', {
            partner: partnerText,
            mainzone: mainzoneText,
            zone: zoneText,
            region: regionText,
            area: areaText,
            timeFrame: timeFrameText,
            startDate: startDate,
            endDate: endDate
        });
    }
    
    // Function to update data table into the existing #transactionReportTable (exposed on window)
    window.updateDataTable = function(data, totalCharges) {
        console.log('updateDataTable called with data:', data, 'totalCharges:', totalCharges);

        // Build tbody rows
        let rows = '';
        if (data && Array.isArray(data) && data.length > 0) {
            data.forEach(function(row) {
                const mlMaticRegion = row.ml_matic_region || '';
                const zone = (mlMaticRegion === 'VISMIN Showroom' || mlMaticRegion === 'LNCR Showroom')
                    ? mlMaticRegion
                    : (row.zone || row.zone_code || '-');
                const region = (mlMaticRegion === 'VISMIN Showroom' || mlMaticRegion === 'LNCR Showroom')
                    ? mlMaticRegion
                    : (row.region || row.region_code || row.region_description || '-');
                const kp_code = row.kp_code || row.kpcode || '-';
                const branch_name = row.branch_name || row.branch || row.branchName || '-';
                const charges = row.total_charges || row.charges || row.charge || 0;

                rows += `
                    <tr>
                        <td>${zone}</td>
                        <td>${region}</td>
                        <td>${kp_code}</td>
                        <td>${branch_name}</td>
                        <td class="text-end">₱${parseFloat(charges || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    </tr>`;
            });
        } else {
            rows = `
                <tr>
                    <td colspan="5" class="text-center text-muted">No data found for the selected filters</td>
                </tr>`;
        }

        // Build tfoot
        const tfoot = `
            <tr>
                <th colspan="4" class="text-end">Total:</th>
                <th class="text-end">₱${parseFloat(totalCharges || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</th>
            </tr>`;

        toggleExportButtonByTotal(totalCharges);

        // Inject into existing table
        const $table = $('#transactionReportTable');
        if ($table.length) {
            console.log('Updating existing table with', rows.length, 'characters of HTML');
            $table.find('tbody').html(rows);
            $table.find('tfoot').html(tfoot);
        } else {
            console.error('transactionReportTable not found in DOM');
            // Create fallback table
            const fallback = `
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-bordered table-hover table-striped" id="transactionReportTableFallback">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Zone</th>
                                <th>Region Name</th>
                                <th>KP Code</th>
                                <th>Branch Name</th>
                                <th>Charges</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                        <tfoot class="table-dark sticky-bottom">${tfoot}</tfoot>
                    </table>
                </div>`;
            $('.col-lg-9.col-md-8').html(fallback);
        }
    }

    // Robust renderer that accepts full response object and populates the existing table
    window.renderTableFromResponse = function(response) {
        console.log('renderTableFromResponse called with response:', response);
        
        // Safely extract data from response
        let data = [];
        if (response && response.data) {
            if (Array.isArray(response.data)) {
                data = response.data;
            } else {
                try { 
                    data = Object.values(response.data); 
                } catch (e) { 
                    console.error('Failed to convert data to array:', e);
                    data = []; 
                }
            }
        }

        console.log('Extracted data array:', data);

        // Build rows using data
        let rows = '';
        if (data.length > 0) {
            rows = data.map(function(row) {
                const mlMaticRegion = row.ml_matic_region || '';
                const zone = (mlMaticRegion === 'VISMIN Showroom' || mlMaticRegion === 'LNCR Showroom')
                    ? mlMaticRegion
                    : (row.zone || row.zone_code || '-');
                const region = (mlMaticRegion === 'VISMIN Showroom' || mlMaticRegion === 'LNCR Showroom')
                    ? mlMaticRegion
                    : (row.region || row.region_code || row.region_description || '-');
                const kp_code = row.kp_code || row.kpcode || '-';
                const branch_name = row.branch_name || row.branch || row.branchName || '-';
                const charges = row.total_charges || row.charges || row.charge || 0;
                
                return `
                    <tr>
                        <td>${zone}</td>
                        <td>${region}</td>
                        <td>${kp_code}</td>
                        <td>${branch_name}</td>
                        <td class="text-end">₱${parseFloat(charges || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    </tr>`;
            }).join('');
        } else {
            rows = `
                <tr>
                    <td colspan="5" class="text-center text-muted">No data found for the selected filters</td>
                </tr>`;
        }

        const totalCharges = response.totalCharges || response.total_charges || 0;
        const tfoot = `
            <tr>
                <th colspan="4" class="text-end">Total:</th>
                <th class="text-end">₱${parseFloat(totalCharges || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</th>
            </tr>`;

        toggleExportButtonByTotal(totalCharges);

        const $table = $('#transactionReportTable');
        if ($table.length) {
            console.log('Updating table with', data.length, 'rows');
            $table.find('tbody').html(rows);
            $table.find('tfoot').html(tfoot);
            
            // Update totalRecords display if present
            if ($('#totalRecords').length) {
                const totalRecords = data.length;
                $('#totalRecords').text(totalRecords.toLocaleString());
            }
        } else {
            console.error('transactionReportTable not found, creating fallback');
            // Create fallback table
            const fallback = `
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-bordered table-hover table-striped" id="transactionReportTableFallback">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Zone</th>
                                <th>Region Name</th>
                                <th>KP Code</th>
                                <th>Branch Name</th>
                                <th class="text-end">Charges</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                        <tfoot class="table-dark sticky-bottom">${tfoot}</tfoot>
                    </table>
                </div>`;
            $('.col-lg-9.col-md-8').html(fallback);
            
            // Update totalRecords for fallback as well
            if ($('#totalRecords').length) {
                const totalRecords = data.length;
                $('#totalRecords').text(totalRecords.toLocaleString());
            }
        }
    }
});
</script>

<!-- DISPLAY DATA TABLE RESULT -->
<script>
$(document).ready(function() {
    // Initialize empty state for existing #transactionReportTable on page load
    const placeholderRow = `
        <tr>
            <td colspan="5" class="text-center text-muted">Select filters and click "Proceed" to generate report</td>
        </tr>`;

    const placeholderFoot = `
        <tr>
            <th colspan="4" class="text-end">Total:</th>
            <th class="text-end">₱0.00</th>
        </tr>`;

    const $tableInit = $('#transactionReportTable');
    if ($tableInit.length) {
        $tableInit.find('tbody').html(placeholderRow);
        $tableInit.find('tfoot').html(placeholderFoot);
    }

    if (typeof toggleExportButtonByTotal === 'function') {
        toggleExportButtonByTotal(0);
    }
});
</script>
</html>
