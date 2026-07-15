<?php
require_once __DIR__ . '/../../config/config.php';
    require '../../vendor/autoload.php';
    
    // Start the session
    session_start();

    if (isset($_SESSION['user_type'])) {
        $current_user_email = '';
        if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
            $current_user_email = $_SESSION['admin_email'];
        } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
            $current_user_email = $_SESSION['user_email'];
            if($_SESSION['user_email'] === 'balb01013333' || $_SESSION['user_email'] === 'pera94005055'){
                header("Location:../../index.php");
                session_destroy();
                exit();
            }
        }else{
            header("Location:../../index.php");
            session_destroy();
            exit();
        }
    }

    use PhpOffice\PhpSpreadsheet\IOFactory;
    use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="../../assets/js/sweetalert2.all.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">

    ';

    // Define formatCurrency function at the top
    function formatCurrency($amount) {
        return '₱ ' . number_format((float)$amount, 2);
    }

    function normalizeReportDate($value) {
        if (!isset($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
    
    if (isset($_POST['upload'])){
        if(isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['import_file']['tmp_name'];
            $file_name = $_FILES['import_file']['name'];
            $file_name_array = explode('.', $file_name);
            $extension = strtolower(end($file_name_array));

            $fileType = $_POST['fileType'] ?? '';
            $partner = $_POST['company'] ?? '';
            $selectedDate = date('Y-m-d');

            $spreadsheet = IOFactory::load($file);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();

            // Increase memory limit and execution time for large files
            ini_set('memory_limit', '100000M');
            ini_set('max_execution_time', 900); // 15 minutes

            // Initialize arrays before the loops
            $partnerselection = [];
            $cancellation_raw = [];
            $regular_transactions = [];

            $duplicate_data = [];
            $ready_to_override_data = [];
            $cancellation_BranchID_data = [];
            $region_not_found_data = [];
            $partner_not_found_data = [];
            $partner_GLCode_not_found_data = [];
            $branchID_notFoundData = [];
            $consolidated_error_data = [];
            $rawData = [];

            if ($partner === 'All') {
                $PartnerID = 'All';
                $PartnerName = 'All';
                $PartnerID_KPX = 'All';
                $GLCode = 'All';
                // Ensure these are defined for downstream usage
                $SubBillersID = null;
                $SubPartnerName = null;
            } else {
                $partnerQuery = " WITH direct_biller AS (
                    SELECT
                        partner_id,
                        partner_id_kpx,
                        gl_code,
                        partner_name AS direct_billers_name,
                        NULL AS sub_billers_name,
                        status
                    FROM masterdata.partner_masterfile
                ),

                sub_biller AS (
                    SELECT
                        partner_id_kpx,
                        sub_billers_id,
                        partner_name AS direct_billers_name,
                        sub_billers_name,
                        NULL AS sub_gl_code
                    FROM masterdata.subbiller
                ),

                merged_left AS (
                    SELECT
                        d.partner_id,
                        COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                        s.sub_billers_id,
                        COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                        CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                        COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
                    FROM direct_biller d
                    LEFT JOIN sub_biller s
                        ON d.direct_billers_name = s.sub_billers_name
                    WHERE COALESCE(d.status, '') = 'ACTIVE'
                ),

                unmatched_sub AS (
                    SELECT
                        NULL AS partner_id,
                        s.partner_id_kpx AS partner_id_kpx,
                        s.sub_billers_id,
                        s.sub_gl_code AS gl_code,
                        s.direct_billers_name AS direct_billers_name,
                        s.sub_billers_name AS sub_billers_name
                    FROM sub_biller s
                    WHERE NOT EXISTS (
                        SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
                    )
                )

                SELECT
                    t.partner_id,
                    t.partner_id_kpx,
                    t.sub_billers_id,
                    t.gl_code,
                    t.direct_billers_name,
                    t.sub_billers_name
                FROM (
                    SELECT * FROM merged_left WHERE CASE WHEN sub_billers_id IS NULL AND direct_billers_name = ? THEN direct_billers_name = ? ELSE sub_billers_name = ? END
                    UNION ALL
                    SELECT * FROM unmatched_sub WHERE CASE WHEN sub_billers_id IS NULL AND direct_billers_name = ? THEN direct_billers_name = ? ELSE sub_billers_name = ? END
                ) t LIMIT 1";
                // if ($fileType === 'KPX') {
                //     $partnerQuery .= " WHERE partner_id_kpx = ? LIMIT 1";
                // }elseif ($fileType === 'KP7') {
                //     $partnerQuery .= " WHERE partner_id = ? LIMIT 1";
                // }
                $stmt = $conn->prepare($partnerQuery);
                if ($stmt) {
                    // the query uses the same parameter multiple times; bind it for each placeholder
                    $stmt->bind_param("ssssss", $partner, $partner, $partner, $partner, $partner, $partner);
                }
                $stmt->execute();
                $partnerResult = $stmt->get_result();
                if ($partnerResult && $partnerResult->num_rows > 0) {
                    $partnerData = $partnerResult->fetch_assoc();
                    $PartnerID = $partnerData['partner_id'];
                    $PartnerID_KPX = $partnerData['partner_id_kpx'];
                    $SubBillersID = $partnerData['sub_billers_id'];
                    $GLCode = $partnerData['gl_code'];
                    if(!empty($SubBillersID)){
                        $PartnerName = $partnerData['direct_billers_name'];
                        $SubPartnerName = $partnerData['sub_billers_name'];
                    }else{
                        $PartnerName = $partnerData['partner_name'];
                        $SubPartnerName = null;
                    }
                }
            }

            $partnerselection[] = [
                'partners_id' => $PartnerID,
                'partners_id_kpx' => $PartnerID_KPX,
                'subbillers_id' => $SubBillersID,
                'gl_code' => $GLCode,
                'sub_billers_name' => $SubPartnerName,
                'companys_name' => $PartnerName

            ];

            $_SESSION['partnerselection'] = $partnerselection;

            if(is_readable($file)) {
                if($extension === 'xlsx' || $extension === 'xls') {

                    // Load the spreadsheet first
                    try {
                        $spreadsheet = IOFactory::load($file);
                    } catch (Exception $e) {
                        echo '<script>
                            Swal.fire({
                                icon: "error",
                                title: "File Loading Error",
                                text: "Error loading the Excel file: ' . $e->getMessage() . '",
                                confirmButtonText: "OK"
                            }).then(() => {
                                window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                            });
                        </script>';
                        exit;
                    }

                    // Move the function outside the loop and fix it
                    function checkDuplicateData($conn, $reference_number, $datetime) {
                        $duplicateData = false;
                        $sql = "SELECT COUNT(*) as count FROM mldb.billspayment_transaction WHERE post_transaction='posted' AND reference_no = ? AND (`datetime` = ? OR cancellation_date = ?) LIMIT 1";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sss", $reference_number, $datetime, $datetime);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) {
                                $row = $result->fetch_assoc();
                                if ($row && $row['count'] > 0) {
                                    $duplicateData = true;
                                }
                            }
                        $stmt->close();
                        return $duplicateData;
                    }

                    function checkHasAlreadyDataReadyToOverride($conn, $reference_number, $datetime) {
                        $overrideData = false;
                        $sql = "SELECT COUNT(*) as count FROM mldb.billspayment_transaction WHERE post_transaction='unposted' AND reference_no = ? AND (`datetime` = ? OR cancellation_date = ?) LIMIT 1";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sss", $reference_number, $datetime, $datetime);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) {
                                $row = $result->fetch_assoc();
                                if ($row && $row['count'] > 0) {
                                    $overrideData = true;
                                }
                            }
                        $stmt->close();
                        return $overrideData;
                    }

                    function checkSpelledRegionName($conn, $fileType, $region_description, $region_code = null) {
                        if (empty(trim((string)$region_description))) {
                            return true;
                        }

                        if (!empty(trim((string)$region_code))) {
                            return false;
                        }

                        $isValidRegion = false;

                        if ($fileType === 'KP7') {
                            $query = "SELECT COUNT(*) as count FROM masterdata.region_masterfile WHERE (gl_region = ? OR region_desc_kp7 = ?) AND NOT zone_code IN ('VISMIN-MANCOMM', 'LNCR-MANCOMM', 'VISMIN-SUPPORT', 'LNCR-SUPPORT') LIMIT 1";
                        } else {
                            $query = "SELECT COUNT(*) as count FROM masterdata.region_masterfile WHERE (gl_region = ? OR region_desc_kpx = ?) AND NOT zone_code IN ('VISMIN-MANCOMM', 'LNCR-MANCOMM', 'VISMIN-SUPPORT', 'LNCR-SUPPORT') LIMIT 1";
                        }

                        $stmt = $conn->prepare($query);
                        if ($stmt) {
                            $stmt->bind_param("ss", $region_description, $region_description);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result) {
                                $row = $result->fetch_assoc();
                                $isValidRegion = ($row && intval($row['count']) > 0);
                            }
                            $stmt->close();
                        }

                        return !$isValidRegion;
                    }

                    function checkhadPartnerID($conn, $fileType, $partner, $partnerIds_kp7, $sub_billers_id, $partnerIds_kpx) {
                        $partnerExists = false;
                        
                        if($fileType === 'KP7') {
                            if($partner === 'All') {
                                // Check if the partner ID from the Excel file exists in the database
                                $sql = "WITH direct_biller AS (
                                        SELECT
                                            partner_id,
                                            partner_id_kpx,
                                            gl_code,
                                            partner_name AS direct_billers_name,
                                            NULL AS sub_billers_name,
                                            status
                                        FROM masterdata.partner_masterfile
                                    ),

                                    sub_biller AS (
                                        SELECT
                                            partner_id_kpx,
                                            sub_billers_id,
                                            partner_name AS direct_billers_name,
                                            sub_billers_name,
                                            NULL AS sub_gl_code
                                        FROM masterdata.subbiller
                                    ),

                                    merged_left AS (
                                        SELECT
                                            d.partner_id,
                                            COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                                            s.sub_billers_id,
                                            COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                                            CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                                            COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
                                        FROM direct_biller d
                                        LEFT JOIN sub_biller s
                                            ON d.direct_billers_name = s.sub_billers_name
                                        WHERE COALESCE(d.status, '') = 'ACTIVE'
                                    ),

                                    unmatched_sub AS (
                                        SELECT
                                            NULL AS partner_id,
                                            s.partner_id_kpx AS partner_id_kpx,
                                            s.sub_billers_id,
                                            s.sub_gl_code AS gl_code,
                                            s.direct_billers_name AS direct_billers_name,
                                            s.sub_billers_name AS sub_billers_name
                                        FROM sub_biller s
                                        WHERE NOT EXISTS (
                                            SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
                                        )
                                    )

                                    SELECT
                                        COUNT(*) OVER (PARTITION BY COALESCE(t.partner_id_kpx, t.partner_id, t.direct_billers_name, t.sub_billers_name)) AS count
                                    FROM (
                                        SELECT * FROM merged_left WHERE partner_id = ?
                                        UNION ALL
                                        SELECT * FROM unmatched_sub WHERE partner_id = ?
                                    ) t LIMIT 1";
                                $stmt = $conn->prepare($sql);
                                if ($stmt) {
                                    $stmt->bind_param("ss", $partnerIds_kp7, $partnerIds_kp7);
                                }
                                $stmt->execute();
                                $result = $stmt->get_result();
                                
                                if ($result) {
                                    $row = $result->fetch_assoc();
                                    if ($row && $row['count'] > 0) {
                                        $partnerExists = true;
                                    }
                                }
                                $stmt->close();
                            } else {
                                // For specific partner selection, assume it exists since it was selected from dropdown
                                $partnerExists = true;
                            }
                        } 
                        elseif($fileType === 'KPX') {
                            if($partner === 'All') {
                                // KPX "All" has no stable partner id in-row, so do not block valid rows on partner lookup.
                                $kpx_value = null;
                                if (!empty($sub_billers_id)){
                                    $sql = "WITH direct_biller AS (
                                        SELECT
                                            partner_id,
                                            partner_id_kpx,
                                            gl_code,
                                            partner_name AS direct_billers_name,
                                            NULL AS sub_billers_name,
                                            status
                                        FROM masterdata.partner_masterfile
                                    ),

                                    sub_biller AS (
                                        SELECT
                                            partner_id_kpx,
                                            sub_billers_id,
                                            partner_name AS direct_billers_name,
                                            sub_billers_name,
                                            NULL AS sub_gl_code
                                        FROM masterdata.subbiller
                                    ),

                                    merged_left AS (
                                        SELECT
                                            d.partner_id,
                                            COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                                            s.sub_billers_id,
                                            COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                                            CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                                            COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
                                        FROM direct_biller d
                                        LEFT JOIN sub_biller s
                                            ON d.direct_billers_name = s.sub_billers_name
                                        WHERE COALESCE(d.status, '') = 'ACTIVE'
                                    ),

                                    unmatched_sub AS (
                                        SELECT
                                            NULL AS partner_id,
                                            s.partner_id_kpx AS partner_id_kpx,
                                            s.sub_billers_id,
                                            s.sub_gl_code AS gl_code,
                                            s.direct_billers_name AS direct_billers_name,
                                            s.sub_billers_name AS sub_billers_name
                                        FROM sub_biller s
                                        WHERE NOT EXISTS (
                                            SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
                                        )
                                    )

                                    SELECT
                                        COUNT(*) OVER (PARTITION BY COALESCE(t.partner_id_kpx, t.partner_id, t.direct_billers_name, t.sub_billers_name)) AS count
                                    FROM (
                                        SELECT * FROM merged_left WHERE sub_billers_id = ?
                                        UNION ALL
                                        SELECT * FROM unmatched_sub WHERE sub_billers_id = ?
                                    ) t LIMIT 1";
                                        
                                    $kpx_value = $sub_billers_id;
                                } elseif (!empty($partnerIds_kpx)) {
                                    $sql = "WITH direct_biller AS (
                                        SELECT
                                            partner_id,
                                            partner_id_kpx,
                                            gl_code,
                                            partner_name AS direct_billers_name,
                                            NULL AS sub_billers_name,
                                            status
                                        FROM masterdata.partner_masterfile
                                    ),

                                    sub_biller AS (
                                        SELECT
                                            partner_id_kpx,
                                            sub_billers_id,
                                            partner_name AS direct_billers_name,
                                            sub_billers_name,
                                            NULL AS sub_gl_code
                                        FROM masterdata.subbiller
                                    ),

                                    merged_left AS (
                                        SELECT
                                            d.partner_id,
                                            COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                                            s.sub_billers_id,
                                            COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                                            CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                                            COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
                                        FROM direct_biller d
                                        LEFT JOIN sub_biller s
                                            ON d.direct_billers_name = s.sub_billers_name
                                        WHERE COALESCE(d.status, '') = 'ACTIVE'
                                    ),

                                    unmatched_sub AS (
                                        SELECT
                                            NULL AS partner_id,
                                            s.partner_id_kpx AS partner_id_kpx,
                                            s.sub_billers_id,
                                            s.sub_gl_code AS gl_code,
                                            s.direct_billers_name AS direct_billers_name,
                                            s.sub_billers_name AS sub_billers_name
                                        FROM sub_biller s
                                        WHERE NOT EXISTS (
                                            SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
                                        )
                                    )

                                    SELECT
                                        COUNT(*) OVER (PARTITION BY COALESCE(t.partner_id_kpx, t.partner_id, t.direct_billers_name, t.sub_billers_name)) AS count
                                    FROM (
                                        SELECT * FROM merged_left WHERE partner_id_kpx = ?
                                        UNION ALL
                                        SELECT * FROM unmatched_sub WHERE partner_id_kpx = ?
                                    ) t LIMIT 1";
                                    
                                    $kpx_value = $partnerIds_kpx;
                                }
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param("ss", $kpx_value, $kpx_value);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                
                                if ($result) {
                                    $row = $result->fetch_assoc();
                                    if ($row && $row['count'] > 0) {
                                        $partnerExists = true;
                                    }
                                }
                                $stmt->close();
                                // $partnerExists = true;
                            } else {
                                // For specific partner selection, assume it exists since it was selected from dropdown
                                $partnerExists = true;
                            }
                        }
                        
                        // Return true if partner is NOT found (indicating an error)
                        return !$partnerExists;
                    }
                    

                    // function checkhadpartnerGLCode($conn, $fileType, $partner, $GLCode) {
                    //     $partnerGLCodeExists = false;
                        
                    //     if($fileType === 'KP7') {
                    //         if($partner === 'All') {
                    //             // Check if the GL Code from the Excel file exists in the database
                    //             if (!empty($GLCode)) {
                    //                 $sql = "SELECT COUNT(*) as count FROM masterdata.partner_masterfile WHERE gl_code = ? LIMIT 1";
                    //                 $stmt = $conn->prepare($sql);
                    //                 $stmt->bind_param("s", $GLCode);
                    //                 $stmt->execute();
                    //                 $result = $stmt->get_result();
                                    
                    //                 if ($result) {
                    //                     $row = $result->fetch_assoc();
                    //                     if ($row && $row['count'] > 0) {
                    //                         $partnerGLCodeExists = true;
                    //                     }
                    //                 }
                    //                 $stmt->close();
                    //             }
                    //         } else {
                    //             // For specific partner selection, check if the selected partner has GL Code
                    //             if (!empty($GLCode)) {
                    //                 $partnerGLCodeExists = true;
                    //             }
                    //         }
                    //     } 
                    //     elseif($fileType === 'KPX') {
                    //         if($partner === 'All') {
                    //             // For KPX All partners, we would need to check based on partner_id_kpx
                    //             // Since KPX files don't contain GL Code directly, we need to validate differently
                    //             // if (!empty($partnerIDKPX)) {
                    //             //     $sql = "SELECT COUNT(*) as count FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? AND gl_code IS NOT NULL AND gl_code != '' LIMIT 1";
                    //             //     $stmt = $conn->prepare($sql);
                    //             //     $stmt->bind_param("s", $partnerIDKPX);
                    //             //     $stmt->execute();
                    //             //     $result = $stmt->get_result();
                                    
                    //             //     if ($result) {
                    //             //         $row = $result->fetch_assoc();
                    //             //         if ($row && $row['count'] > 0) {
                    //             //             $partnerGLCodeExists = true;
                    //             //         }
                    //             //     }
                    //             //     $stmt->close();
                    //             // }
                    //         } else {
                    //             // For specific partner selection, check if the selected partner has GL Code
                    //             if (!empty($GLCode)) {
                    //                 $partnerGLCodeExists = true;
                    //             }
                    //         }
                    //     }
                        
                    //     // Return true if partner GL Code is NOT found (indicating an error)
                    //     return !$partnerGLCodeExists;
                    // }

                    function checkHadBranchID($conn, $branch_id) {
                        if (empty($branch_id) || !is_numeric($branch_id)) {
                            return true;
                        }

                        $branchFound = false;
                        $sql = "SELECT COUNT(*) as count FROM masterdata.branch_profile WHERE branch_id = ? LIMIT 1";
                        $stmt = $conn->prepare($sql);
                        if ($stmt) {
                            $branchIdInt = intval($branch_id);
                            $stmt->bind_param("i", $branchIdInt);
                            $stmt->execute();
                            $result = $stmt->get_result();

                            if ($result) {
                                $row = $result->fetch_assoc();
                                $branchFound = ($row && intval($row['count']) > 0);
                            }
                            $stmt->close();
                        }

                        return !$branchFound;
                    }

                    // Initialize variables before loops
                    // $cancellStatus = '';
                    // $datetime = '';
                    // $control_number = '';
                    // $reference_number = '';
                    // $payor_name = '';
                    // $payor_address = '';
                    // $account_number = '';
                    // $account_name = '';
                    // $amount_paid = 0;
                    // $amount_charge_partner = 0;
                    // $amount_charge_customer = 0;
                    // $contact_number = '';
                    // $other_details = '';
                    // $branch_id = '';
                    // $branch_outlet = '';
                    // $region_code = '';
                    // $region_description = '';
                    // $person_operator = '';
                    // $partnerName = '';
                    // $partnerId = '';
                    // $PartnerID_KPX = '';
                    // $GLCode = '';
                    // $remote_branch = null;
                    // $remote_operator = null;
                    // $settle_unsettle = null;
                    // $claim_unclaim = null;
                    // $report_date = '';
                    // $imported_by = '';
                    // $date_uploaded = '';
                    // $rfp_no = null;
                    // $cad_no = null;
                    // $hold_status = null;
                    // $post_transaction = '';

                    //column headers
                    $extracted_report_date = null;
                    foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                        $getColumnLabels = [];
                        
                        // Read row 9 to get column headers
                        $highestColumn = $worksheet->getHighestColumn();
                        $columnIterator = $worksheet->getRowIterator(9, 9)->current()->getCellIterator('A', $highestColumn);
                        
                        foreach ($columnIterator as $cell) {
                            $columnValue = trim(strval($cell->getValue()));
                            if (!empty($columnValue)) {
                                $getColumnLabels[] = $columnValue;
                            }
                        }

                        if($fileType === 'KP7' || $fileType === 'KPX'){
                            $report_date_raw = strval($worksheet->getCell('B' . '3')->getValue());
                            $extracted_report_date = normalizeReportDate($report_date_raw);
                            if (empty($extracted_report_date) && !empty($report_date_raw)) {
                                $report_date_raw_trim = trim($report_date_raw);
                                if (preg_match('/([A-Za-z]+\s+\d{1,2}\s+\d{4})/i', $report_date_raw_trim, $m)) {
                                    $extracted_report_date = normalizeReportDate($m[1]);
                                }
                            }
                            if (!empty($extracted_report_date)) {
                                $_SESSION['extracted_report_date'] = $extracted_report_date;
                            }
                        }

                        // Break after first worksheet since we only need headers once
                        break;
                    }

                    // Default report date for all row processing paths.
                    $report_date = !empty($extracted_report_date) ? $extracted_report_date : $selectedDate;

                    

                    // Process each row starting from row 10
                    for ($row = 10; $row <= $highestRow; ++$row) {
                        // Check if essential cells (A to E) are empty - if so, break the loop
                        $cellA = trim(strval($worksheet->getCell('A' . $row)->getValue()));
                        $cellB = trim(strval($worksheet->getCell('B' . $row)->getValue()));
                        $cellC = trim(strval($worksheet->getCell('C' . $row)->getValue()));
                        $cellD = trim(strval($worksheet->getCell('D' . $row)->getValue()));
                        $cellE = trim(strval($worksheet->getCell('E' . $row)->getValue()));

                        if (empty($cellA) && empty($cellB) && empty($cellC) && empty($cellD) && empty($cellE)) {
                            break;
                        }

                        // Read row 9 column A 
                        if($getColumnLabels[0] === 'STATUS'){
                            if($fileType === 'KP7'){
                                // KP7 report date comes from B3; keep a fallback to selected date.
                                $report_date = !empty($extracted_report_date) ? $extracted_report_date : $selectedDate;
                                // Reset variables for each row
                                $cancellStatus = '';
                                $is_cancellation = strpos($worksheet->getCell('A' . $row)->getValue(), '*') !== false;
                                if ($is_cancellation) {
                                    $cancellStatus = '*';
                                } else {
                                    $cancellStatus = '';
                                }
    
                                $datetime_raw = $worksheet->getCell('C' . $row)->getValue();

                                if ($datetime_raw) {
                                    $datetime = date('Y-m-d H:i:s', strtotime($datetime_raw));
                                }
    
                                $reference_number= $conn->real_escape_string(strval($worksheet->getCell('E' . $row)->getValue()));
                                $branch_outlet_raw = $conn->real_escape_string(strval($worksheet->getCell('O' . $row)->getValue()));
                                $branch_ids = null;
                                $branch_id = null;
                                $branch_code = null;
                                $branch_outlet_lookup = trim($branch_outlet_raw);
                                if (!preg_match('/^ML\s+/i', $branch_outlet_lookup)) {
                                    $branch_outlet_lookup = 'ML ' . $branch_outlet_lookup;
                                }

                                if (substr($reference_number, 0, 3) === 'BPP') {
                                    $branch_code = intval(substr($reference_number, 3, 3));
                                } elseif (substr($reference_number, 0, 3) === 'BPX') {
                                    $branch_code = intval(substr($reference_number, 3, 3));
                                } else {
                                    $branch_query = "SELECT branch_id FROM masterdata.kpx_branch_masterfile WHERE branch_name = ? LIMIT 1";
                                    $stmt = $conn->prepare($branch_query);
                                    if ($stmt) {
                                        $stmt->bind_param("s", $branch_outlet_lookup);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result && $result->num_rows > 0) {
                                            $branchData = $result->fetch_assoc();
                                            if ($branchData && isset($branchData['branch_id'])) {
                                                $branch_ids = $conn->real_escape_string(strval($branchData['branch_id']));
                                            }else{
                                                $branch_ids = null;
                                            }
                                        }else{
                                            $branch_ids = null;
                                        }
                                        $stmt->close();
                                    }
                                }
    
                                //GET Data for region_code and zone_code
                                $region_description_raw = strval($worksheet->getCell('P' . $row)->getValue());
                                $kp7Query = "SELECT region_code, zone_code FROM masterdata.region_masterfile  WHERE (gl_region = ? OR region_desc_kp7 = ?) LIMIT 1";
                                $stmt = $conn->prepare($kp7Query);
                                if ($stmt) {
                                    $stmt->bind_param("ss", $region_description_raw, $region_description_raw);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    if ($result && $result->num_rows > 0) {
                                        $regioncodeData = $result->fetch_assoc();
                                        if ($regioncodeData && isset($regioncodeData['region_code']) && isset($regioncodeData['zone_code'])) {
                                            $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                            $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                        }else{
                                            $region_code = null;
                                            $zone_code = null;
                                        }
                                    }else{
                                        $region_code = null;
                                        $zone_code = null;
                                    }
                                    $stmt->close();
                                }
    
                                // First, check in branch_profile directly
                                if(!empty($branch_ids)){
                                    $branch_id = $branch_ids;
                                } elseif (!empty($branch_code) && !empty($region_code) && !empty($zone_code)) {
                                    $kp7Query1 = "SELECT mbp.branch_id FROM masterdata.branch_profile as mbp
                                                JOIN masterdata.region_masterfile AS mrm
                                                ON mrm.region_code = mbp.region_code
                                                WHERE mbp.code = ? AND mrm.region_code = ? AND mrm.zone_code = ? LIMIT 1";
        
                                    $stmt = $conn->prepare($kp7Query1);
                                    if ($stmt) {
                                        $stmt->bind_param("iss", $branch_code, $region_code, $zone_code);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result && $result->num_rows > 0) {
                                            $branchIDData = $result->fetch_assoc();
                                            if ($branchIDData && isset($branchIDData['branch_id'])) {
                                                $branch_id = $conn->real_escape_string(intval($branchIDData['branch_id']));
                                            }else{
                                                $branch_id = null;
                                            }
                                        }else{
                                            $branch_id = null;
                                        }
                                        $stmt->close();
                                    }
                                }

                                if (empty($branch_id)) {
                                    $branch_query = "SELECT branch_id FROM masterdata.kpx_branch_masterfile WHERE branch_name = ? LIMIT 1";
                                    $stmt = $conn->prepare($branch_query);
                                    if ($stmt) {
                                        $stmt->bind_param("s", $branch_outlet_lookup);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result && $result->num_rows > 0) {
                                            $branchData = $result->fetch_assoc();
                                            if ($branchData && isset($branchData['branch_id'])) {
                                                $branch_id = $conn->real_escape_string(strval($branchData['branch_id']));
                                            }
                                        }
                                        $stmt->close();
                                    }
                                }
    
                                $control_number= $conn->real_escape_string(strval($worksheet->getCell('D' . $row)->getValue()));
                                $payor_name = $conn->real_escape_string(strval($worksheet->getCell('F' . $row)->getValue()));
                                $payor_address = $conn->real_escape_string(strval($worksheet->getCell('G' . $row)->getValue()));
                                $account_number = $conn->real_escape_string(strval($worksheet->getCell('H' . $row)->getValue()));
                                $account_name = $conn->real_escape_string(strval($worksheet->getCell('I' . $row)->getValue()));
    
                                $amount_paid = $conn->real_escape_string(floatval( str_replace(',', '', $worksheet->getCell('J' . $row)->getValue())));
                                $amount_charge_partner = $conn->real_escape_string(floatval( str_replace(',', '', $worksheet->getCell('K' . $row)->getValue())));
                                $amount_charge_customer = $conn->real_escape_string(floatval( str_replace(',', '', $worksheet->getCell('L' . $row)->getValue())));
    
                                $contact_number = $conn->real_escape_string(strval($worksheet->getCell('M' . $row)->getValue()));
                                $other_details = $conn->real_escape_string(strval($worksheet->getCell('N' . $row)->getValue()));
                                $branch_outlet = $branch_outlet_raw;
                                $region_description = $conn->real_escape_string($region_description_raw);
                                $person_operator = $conn->real_escape_string(strval($worksheet->getCell('Q' . $row)->getValue()));
    
                                if($partner === 'All'){
                                    $partnerName_raw = $conn->real_escape_string(strval($worksheet->getCell('R' . $row)->getValue()));
                                    $partnerIds_kp7 = $conn->real_escape_string(strval($worksheet->getCell('S' . $row)->getValue()));

                                    $getGLCode_partner_kpx = "WITH direct_biller AS (
                                        SELECT
                                            partner_id,
                                            partner_id_kpx,
                                            gl_code,
                                            partner_name AS direct_billers_name,
                                            NULL AS sub_billers_name,
                                            status
                                        FROM masterdata.partner_masterfile
                                    ),

                                    sub_biller AS (
                                        SELECT
                                            partner_id_kpx,
                                            sub_billers_id,
                                            partner_name AS direct_billers_name,
                                            sub_billers_name,
                                            NULL AS sub_gl_code
                                        FROM masterdata.subbiller
                                    ),

                                    merged_left AS (
                                        SELECT
                                            d.partner_id,
                                            COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                                            s.sub_billers_id,
                                            COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                                            CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                                            COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
                                        FROM direct_biller d
                                        LEFT JOIN sub_biller s
                                            ON d.direct_billers_name = s.sub_billers_name
                                        WHERE COALESCE(d.status, '') = 'ACTIVE'
                                    ),

                                    unmatched_sub AS (
                                        SELECT
                                            NULL AS partner_id,
                                            s.partner_id_kpx AS partner_id_kpx,
                                            s.sub_billers_id,
                                            s.sub_gl_code AS gl_code,
                                            s.direct_billers_name AS direct_billers_name,
                                            s.sub_billers_name AS sub_billers_name
                                        FROM sub_biller s
                                        WHERE NOT EXISTS (
                                            SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
                                        )
                                    )

                                    SELECT
                                        t.partner_id_kpx,
                                        t.sub_billers_id,
                                        t.gl_code,
                                        t.direct_billers_name,
                                        t.sub_billers_name
                                    FROM (
                                        SELECT * FROM merged_left WHERE partner_id = ?
                                        UNION ALL
                                        SELECT * FROM unmatched_sub WHERE partner_id = ?
                                    ) t LIMIT 1";
                                    $stmt = $conn->prepare($getGLCode_partner_kpx);
                                    if ($stmt) {
                                        // query contains two placeholders for partner_id
                                        $stmt->bind_param("ss", $partnerIds_kp7, $partnerIds_kp7);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result && $result->num_rows > 0) {
                                            $GLCodeData = $result->fetch_assoc();
                                            if ($GLCodeData) {
                                                $partnerIds_kpx = $conn->real_escape_string(strval($GLCodeData['partner_id_kpx']));
                                                $sub_billers_id = $conn->real_escape_string(strval($GLCodeData['sub_billers_id']));
                                                $GLCode = $conn->real_escape_string(strval($GLCodeData['gl_code']));
                                                if(!empty($sub_billers_id)){
                                                    $partnerName = $conn->real_escape_string(strval($GLCodeData['direct_billers_name']));
                                                }else{
                                                    $partnerName = $partnerName_raw;
                                                }
                                                $SubBillersName = $conn->real_escape_string(strval($GLCodeData['sub_billers_name']));
                                            }else{
                                                $partnerIds_kpx = null;
                                                $sub_billers_id = null;
                                                $GLCode = null;
                                                $SubBillersName = null;
                                                $partnerName = $partnerName_raw;
                                            }
                                        }else{
                                            $partnerIds_kpx = null;
                                            $sub_billers_id = null;
                                            $GLCode = null;
                                            $SubBillersName = null;
                                            $partnerName = $partnerName_raw;
                                        }
                                        $stmt->close();
                                    }
                                }
                                else{
                                    $partnerIds_kp7 = $conn->real_escape_string(strval($PartnerID));
                                    $partnerIds_kpx = $conn->real_escape_string(strval($PartnerID_KPX));
                                    $sub_billers_id = $conn->real_escape_string(strval($SubBillersID));
                                    $GLCode = $conn->real_escape_string(strval($GLCode));
                                    $SubBillersName = $conn->real_escape_string(strval($SubPartnerName));
                                    $partnerName = $conn->real_escape_string(strval($PartnerName));
                                }
    
                                $remote_branch = null;
                                $remote_operator = null;
                                $second_approver = null;
                            }
                            else{
                                echo '<script>
                                    document.addEventListener("DOMContentLoaded", function() {
                                        Swal.fire({
                                            icon: "error",
                                            title: "File Not Found",
                                            text: "The specified file could not be found or accessed.",
                                            confirmButtonText: "OK",
                                            confirmButtonColor: "#dc3545"
                                        }).then(() => {
                                            window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                                        });
                                    });
                                </script>';
                                exit;
                            }
                        } 
                        elseif($getColumnLabels[0] === 'No'){
                            if($fileType === 'KPX'){

                                // Use extracted report date for KPX as well (cell B3)
                                $report_date = $extracted_report_date;
                                // Reset variables for each row
                                $cancellStatus = '';
                                $is_cancellation = strpos($worksheet->getCell('A' . $row)->getValue(), '*') !== false;
                                if ($is_cancellation) {
                                    $cancellStatus = '*';
                                } else {
                                    $cancellStatus = '';
                                }

                                if ($getColumnLabels[1] === 'Date / Time'){
                                    $datetime_raw = $worksheet->getCell('B' . $row)->getValue();
                                    if ($datetime_raw) {
                                        $datetime = date('Y-m-d H:i:s', strtotime($datetime_raw));
                                    }
        
                                    $control_number= $conn->real_escape_string(strval($worksheet->getCell('C' . $row)->getValue()));
                                    $reference_number= $conn->real_escape_string(strval($worksheet->getCell('D' . $row)->getValue()));
                                    
                                    $payor_name = $conn->real_escape_string(strval($worksheet->getCell('E' . $row)->getValue()));
                                    $payor_address = $conn->real_escape_string(strval($worksheet->getCell('F' . $row)->getValue()));
                                    $account_number = $conn->real_escape_string(strval($worksheet->getCell('G' . $row)->getValue()));
                                    $account_name = $conn->real_escape_string(strval($worksheet->getCell('H' . $row)->getValue()));
        
                                    $amount_paid = $conn->real_escape_string(floatval(str_replace(',', '', $worksheet->getCell('I' . $row)->getValue())));
                                    $amount_charge_customer = $conn->real_escape_string(floatval(str_replace(',', '', $worksheet->getCell('J' . $row)->getValue())));
                                    $amount_charge_partner = $conn->real_escape_string(floatval(str_replace(',', '', $worksheet->getCell('K' . $row)->getValue())));

                                    if ($getColumnLabels[11] === 'Contact No.'){
                                        $contact_number = $conn->real_escape_string(strval($worksheet->getCell('L' . $row)->getValue()));
                                        $other_details = $conn->real_escape_string(strval($worksheet->getCell('M' . $row)->getValue()));
            
                                        $branch_id_raw = $worksheet->getCell('N' . $row)->getValue();
                                        $branch_outlet_raw = $conn->real_escape_string(strval($worksheet->getCell('O' . $row)->getValue()));

                                        if($getColumnLabels[13] === 'Branch ID'){
                                            if (is_numeric($branch_id_raw)) {
                                                $cntl_num_for_region = ($branch_id_raw == 581) ? intval(2607) : intval($branch_id_raw);
                                            } elseif ($branch_id_raw === 'HEAD OFFICE' || $branch_id_raw === 'ML HEAD OFFICE') {
                                                $cntl_num_for_region = intval(2607);
                                            } elseif ($branch_id_raw === 'CEBU HEAD OFFICE' || $branch_id_raw === 'ML CEBU HEAD OFFICE') {
                                                $cntl_num_for_region = intval(581);
                                            }else{
                                                $cntl_num_for_region = intval($branch_id_raw);
                                            }

                                            if($branch_outlet_raw === 'HEAD OFFICE' || $branch_outlet_raw === 'ML HEAD OFFICE'){
                                                $cntl_num_for_region = intval(2607);
                                                $branch_outlet = $branch_outlet_raw;
                                            }elseif($branch_outlet_raw === 'CEBU HEAD OFFICE' || $branch_outlet_raw === 'ML CEBU HEAD OFFICE'){
                                                $cntl_num_for_region = intval(581);
                                                $branch_outlet = $branch_outlet_raw;
                                            }else{
                                                $branch_outlet = $branch_outlet_raw;
                                            }

                                            $branch_id = $conn->real_escape_string($cntl_num_for_region);
                                            $kpxbranchcodeQuery = "SELECT code FROM masterdata.branch_profile where branch_id = ? LIMIT 1";
                                            $stmt = $conn->prepare($kpxbranchcodeQuery);
                                            if ($stmt) {
                                                $stmt->bind_param("i", $cntl_num_for_region);
                                                $stmt->execute();
                                                $result = $stmt->get_result();
                                                if ($result && $result->num_rows > 0) {
                                                    $branchCodeData = $result->fetch_assoc();
                                                    if ($branchCodeData && isset($branchCodeData['code'])) {
                                                        $branch_code = $conn->real_escape_string(strval($branchCodeData['code']));
                                                    } else {
                                                        $branch_code = null;
                                                    }
                                                } else {
                                                    $branch_code = null;
                                                }
                                                $stmt->close();
                                            }
            
                                            
                                            $tg_region_code_raw = $conn->real_escape_string(strval($worksheet->getCell('P' . $row)->getValue()));
                                            $region_description = strval($worksheet->getCell('Q' . $row)->getValue());
                                            $kpxregioncodeQuery1 = "SELECT region_code, zone_code FROM masterdata.region_masterfile
                                                                    WHERE (gl_region = ? OR region_desc_kpx = ? OR tg_region_code = ?) LIMIT 1";
                                            $stmt = $conn->prepare($kpxregioncodeQuery1);
                                            if ($stmt) {
                                                $stmt->bind_param("sss",$region_description, $region_description, $tg_region_code_raw);
                                                $stmt->execute();
                                                $result = $stmt->get_result();
                                                if ($result && $result->num_rows > 0) {
                                                    $regioncodeData = $result->fetch_assoc();
                                                    if ($regioncodeData && isset($regioncodeData['region_code'])) {
                                                        $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                                        $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                                    }else{
                                                        $region_code = null;
                                                        $zone_code = null;
                                                    }
                                                }else{
                                                    $region_code = null;
                                                    $zone_code = null;
                                                }
                                                $stmt->close();
                                            }
                                            
                                            $person_operator = $conn->real_escape_string(strval($worksheet->getCell('R' . $row)->getValue()));
                                            $remote_branch = $conn->real_escape_string(strval($worksheet->getCell('S' . $row)->getValue()));
                                            $remote_operator = $conn->real_escape_string(strval($worksheet->getCell('T' . $row)->getValue()));
                                            $second_approver = $conn->real_escape_string(strval($worksheet->getCell('U' . $row)->getValue()));

                                        }else{ // WITHOUT BRANCH ID COLUMN AND REGION CODE COLUMN

                                            if ($branch_id_raw === 'HEAD OFFICE' || $branch_outlet_raw === 'ML HEAD OFFICE') {
                                                $cntl_num_for_region = intval(2607);
                                            } elseif ($branch_id_raw === 'CEBU HEAD OFFICE' || $branch_outlet_raw === 'ML CEBU HEAD OFFICE') {
                                                $cntl_num_for_region = intval(581);
                                            }elseif (empty($control_number)) {
                                                if (substr($reference_number, 0, 3) === 'APB') {
                                                    $cntl_num_for_region = intval(2607);
                                                }
                                            } else {
                                                if (substr($control_number, 0, 3) === 'BPX') {
                                                    $cntl_num_for_region = intval(2607);
                                                } else {
                                                    $cntl_no_str = '';
                                                    for ($i = 0; $i < strlen($control_number); $i++) {
                                                        if ($control_number[$i] === '-') break;
                                                        if (is_numeric($control_number[$i])) {
                                                            $cntl_no_str .= $control_number[$i];
                                                        }
                                                    }
                                                    $cntl_num_for_region = intval($cntl_no_str);
                                                }
                                            }
                                            $branch_id = $conn->real_escape_string($cntl_num_for_region);
                                            $kpxbranchcodeQuery = "SELECT code FROM masterdata.branch_profile where branch_id = ? LIMIT 1";
                                            $stmt = $conn->prepare($kpxbranchcodeQuery);
                                            if ($stmt) {
                                                $stmt->bind_param("i", $cntl_num_for_region);
                                                $stmt->execute();
                                                $result = $stmt->get_result();
                                                if ($result && $result->num_rows > 0) {
                                                    $branchCodeData = $result->fetch_assoc();
                                                    if ($branchCodeData && isset($branchCodeData['code'])) {
                                                        $branch_code = $conn->real_escape_string(strval($branchCodeData['code']));
                                                    } else {
                                                        $branch_code = null;
                                                    }
                                                } else {
                                                    $branch_code = null;
                                                }
                                                $stmt->close();
                                            }
            
                                            $branch_outlet = $conn->real_escape_string(strval($worksheet->getCell('N' . $row)->getValue()));
                                            $region_description = strval($worksheet->getCell('O' . $row)->getValue());
                                            $kpxregioncodeQuery1 = "SELECT region_code, zone_code FROM masterdata.region_masterfile
                                                                    WHERE (gl_region = ? OR region_desc_kpx = ?) LIMIT 1";
                                            $stmt = $conn->prepare($kpxregioncodeQuery1);
                                            if ($stmt) {
                                                $stmt->bind_param("ss",$region_description, $region_description);
                                                $stmt->execute();
                                                $result = $stmt->get_result();
                                                if ($result && $result->num_rows > 0) {
                                                    $regioncodeData = $result->fetch_assoc();
                                                    if ($regioncodeData && isset($regioncodeData['region_code'])) {
                                                        $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                                        $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                                    }else{
                                                        $region_code = null;
                                                        $zone_code = null;
                                                    }
                                                }else{
                                                    $region_code = null;
                                                    $zone_code = null;
                                                }
                                                $stmt->close();
                                            }
                                            
                                            $person_operator = $conn->real_escape_string(strval($worksheet->getCell('P' . $row)->getValue()));
                                            $remote_branch = $conn->real_escape_string(strval($worksheet->getCell('Q' . $row)->getValue()));
                                            $remote_operator = $conn->real_escape_string(strval($worksheet->getCell('R' . $row)->getValue()));
                                            $second_approver = null;
                                        }

                                    }else { // OTHER DETAILS EXCEL COLUMN
                                        if ($partner === 'All'){
                                            $contact_number = null;
                                            $other_details = $conn->real_escape_string(strval($worksheet->getCell('L' . $row)->getValue()));

                                            $branch_id_raw = $worksheet->getCell('M' . $row)->getValue();
                                            $branch_outlet_raw = $conn->real_escape_string(strval($worksheet->getCell('N' . $row)->getValue()));
                                        
                                            if($getColumnLabels[12] === 'Branch ID'){
                                                if (is_numeric($branch_id_raw)) {
                                                    $cntl_num_for_region = ($branch_id_raw == 581) ? intval(2607) : intval($branch_id_raw);
                                                } elseif ($branch_id_raw === 'HEAD OFFICE' || $branch_id_raw === 'ML HEAD OFFICE') {
                                                    $cntl_num_for_region = intval(2607);
                                                } elseif ($branch_id_raw === 'CEBU HEAD OFFICE' || $branch_id_raw === 'ML CEBU HEAD OFFICE') {
                                                    $cntl_num_for_region = intval(581);
                                                }

                                                if($branch_outlet_raw === 'HEAD OFFICE' || $branch_outlet_raw === 'ML HEAD OFFICE'){
                                                    $cntl_num_for_region = intval(2607);
                                                    $branch_outlet = $branch_outlet_raw;
                                                }elseif($branch_outlet_raw === 'CEBU HEAD OFFICE' || $branch_outlet_raw === 'ML CEBU HEAD OFFICE'){
                                                    $cntl_num_for_region = intval(581);
                                                    $branch_outlet = $branch_outlet_raw;
                                                }else{
                                                    $branch_outlet = $branch_outlet_raw;
                                                }

                                                $branch_id = $conn->real_escape_string($cntl_num_for_region);
                                                $kpxbranchcodeQuery = "SELECT code FROM masterdata.branch_profile where branch_id = ? LIMIT 1";
                                                $stmt = $conn->prepare($kpxbranchcodeQuery);
                                                if ($stmt) {
                                                    $stmt->bind_param("i", $cntl_num_for_region);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    if ($result && $result->num_rows > 0) {
                                                        $branchCodeData = $result->fetch_assoc();
                                                        if ($branchCodeData && isset($branchCodeData['code'])) {
                                                            $branch_code = $conn->real_escape_string(strval($branchCodeData['code']));
                                                        } else {
                                                            $branch_code = null;
                                                        }
                                                    } else {
                                                        $branch_code = null;
                                                    }
                                                    $stmt->close();
                                                }
                
                                                
                                                $branch_outlet = $branch_outlet_raw;
                                                $tg_region_code_raw = $conn->real_escape_string(strval($worksheet->getCell('O' . $row)->getValue()));
                                                $region_description = strval($worksheet->getCell('P' . $row)->getValue());
                                                $kpxregioncodeQuery1 = "SELECT region_code, zone_code FROM masterdata.region_masterfile
                                                                        WHERE (gl_region = ? OR region_desc_kpx = ? OR tg_region_code = ?) LIMIT 1";
                                                $stmt = $conn->prepare($kpxregioncodeQuery1);
                                                if ($stmt) {
                                                    $stmt->bind_param("sss",$region_description, $region_description, $tg_region_code_raw);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    if ($result && $result->num_rows > 0) {
                                                        $regioncodeData = $result->fetch_assoc();
                                                        if ($regioncodeData && isset($regioncodeData['region_code'])) {
                                                            $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                                            $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                                        }else{
                                                            $region_code = null;
                                                            $zone_code = null;
                                                        }
                                                    }else{
                                                        $region_code = null;
                                                        $zone_code = null;
                                                    }
                                                    $stmt->close();
                                                }
                                                
                                                $person_operator = $conn->real_escape_string(strval($worksheet->getCell('Q' . $row)->getValue()));
                                                $remote_branch = $conn->real_escape_string(strval($worksheet->getCell('R' . $row)->getValue()));
                                                $remote_operator = $conn->real_escape_string(strval($worksheet->getCell('S' . $row)->getValue()));
                                                $second_approver = $conn->real_escape_string(strval($worksheet->getCell('T' . $row)->getValue()));

                                            } else{ // WITHOUT BRANCH ID COLUMN AND REGION CODE COLUMN
                                                if ($branch_id_raw === 'HEAD OFFICE' || $branch_outlet_raw === 'ML HEAD OFFICE') {
                                                    $cntl_num_for_region = intval(2607);
                                                } elseif ($branch_id_raw === 'CEBU HEAD OFFICE' || $branch_outlet_raw === 'ML CEBU HEAD OFFICE') {
                                                    $cntl_num_for_region = intval(581);
                                                } elseif (empty($control_number)) {
                                                    if (substr($reference_number, 0, 3) === 'APB') {
                                                        $cntl_num_for_region = intval(2607);
                                                    }
                                                } else {
                                                    if (substr($control_number, 0, 3) === 'BPX') {
                                                        $cntl_num_for_region = intval(2607);
                                                    } else {
                                                        $cntl_no_str = '';
                                                        for ($i = 0; $i < strlen($control_number); $i++) {
                                                            if ($control_number[$i] === '-') break;
                                                            if (is_numeric($control_number[$i])) {
                                                                $cntl_no_str .= $control_number[$i];
                                                            }
                                                        }
                                                        $cntl_num_for_region = intval($cntl_no_str);
                                                    }
                                                }

                                                $branch_id = $conn->real_escape_string($cntl_num_for_region);
                                                $kpxbranchcodeQuery = "SELECT code FROM masterdata.branch_profile where branch_id = ? LIMIT 1";
                                                $stmt = $conn->prepare($kpxbranchcodeQuery);
                                                if ($stmt) {
                                                    $stmt->bind_param("i", $cntl_num_for_region);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    if ($result && $result->num_rows > 0) {
                                                        $branchCodeData = $result->fetch_assoc();
                                                        if ($branchCodeData && isset($branchCodeData['code'])) {
                                                            $branch_code = $conn->real_escape_string(strval($branchCodeData['code']));
                                                        } else {
                                                            $branch_code = null;
                                                        }
                                                    } else {
                                                        $branch_code = null;
                                                    }
                                                    $stmt->close();
                                                }
                
                                                $branch_outlet = $conn->real_escape_string(strval($worksheet->getCell('M' . $row)->getValue()));
                                                $region_description = strval($worksheet->getCell('N' . $row)->getValue());
                                                $kpxregioncodeQuery1 = "SELECT region_code, zone_code FROM masterdata.region_masterfile
                                                                        WHERE (gl_region = ? OR region_desc_kpx = ?) LIMIT 1";
                                                $stmt = $conn->prepare($kpxregioncodeQuery1);
                                                if ($stmt) {
                                                    $stmt->bind_param("ss",$region_description, $region_description);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    if ($result && $result->num_rows > 0) {
                                                        $regioncodeData = $result->fetch_assoc();
                                                        if ($regioncodeData && isset($regioncodeData['region_code'])) {
                                                            $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                                            $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                                        }else{
                                                            $region_code = null;
                                                            $zone_code = null;
                                                        }
                                                    }else{
                                                        $region_code = null;
                                                        $zone_code = null;
                                                    }
                                                    $stmt->close();
                                                }
                                                
                                                $person_operator = $conn->real_escape_string(strval($worksheet->getCell('O' . $row)->getValue()));
                                                $remote_branch = $conn->real_escape_string(strval($worksheet->getCell('P' . $row)->getValue()));
                                                $remote_operator = $conn->real_escape_string(strval($worksheet->getCell('Q' . $row)->getValue()));
                                                $second_approver = $conn->real_escape_string(strval($worksheet->getCell('R' . $row)->getValue()));
                                            }
                                        }
                                    }

                                } elseif ($getColumnLabels[2] === 'Date / Time'){
                                    $datetime_raw = $worksheet->getCell('C' . $row)->getValue();

                                    if ($datetime_raw) {
                                        $datetime = date('Y-m-d H:i:s', strtotime($datetime_raw));
                                    }

                                    $control_number= $conn->real_escape_string(strval($worksheet->getCell('D' . $row)->getValue()));
                                    $reference_number= $conn->real_escape_string(strval($worksheet->getCell('E' . $row)->getValue()));
                                    
                                    $payor_name = $conn->real_escape_string(strval($worksheet->getCell('F' . $row)->getValue()));
                                    $payor_address = $conn->real_escape_string(strval($worksheet->getCell('G' . $row)->getValue()));
                                    $account_number = $conn->real_escape_string(strval($worksheet->getCell('H' . $row)->getValue()));
                                    $account_name = $conn->real_escape_string(strval($worksheet->getCell('I' . $row)->getValue()));
        
                                    $amount_paid = $conn->real_escape_string(floatval(str_replace(',', '', $worksheet->getCell('J' . $row)->getValue())));
                                    $amount_charge_customer = $conn->real_escape_string(floatval(str_replace(',', '', $worksheet->getCell('K' . $row)->getValue())));
                                    $amount_charge_partner = $conn->real_escape_string(floatval(str_replace(',', '', $worksheet->getCell('L' . $row)->getValue())));
        
                                    $contact_number = $conn->real_escape_string(strval($worksheet->getCell('M' . $row)->getValue()));
                                    $other_details = $conn->real_escape_string(strval($worksheet->getCell('N' . $row)->getValue()));
        
                                    $branch_id_raw = $worksheet->getCell('O' . $row)->getValue();
                                    if($getColumnLabels[14] === 'Branch ID'){
                                        if (is_numeric($branch_id_raw)) {
                                            $cntl_num_for_region = ($branch_id_raw == 581) ? intval(2607) : intval($branch_id_raw);
                                        } elseif ($branch_id_raw === 'HEAD OFFICE' || $branch_id_raw === 'ML HEAD OFFICE') {
                                            $cntl_num_for_region = intval(2607);
                                        } elseif ($branch_id_raw === 'CEBU HEAD OFFICE' || $branch_id_raw === 'ML CEBU HEAD OFFICE') {
                                            $cntl_num_for_region = intval(581);
                                        }
                                        $branch_id = $conn->real_escape_string($cntl_num_for_region);
                                        $kpxbranchcodeQuery = "SELECT code FROM masterdata.branch_profile where branch_id = ? LIMIT 1";
                                        $stmt = $conn->prepare($kpxbranchcodeQuery);
                                        if ($stmt) {
                                            $stmt->bind_param("i", $cntl_num_for_region);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result && $result->num_rows > 0) {
                                                $branchCodeData = $result->fetch_assoc();
                                                if ($branchCodeData && isset($branchCodeData['code'])) {
                                                    $branch_code = $conn->real_escape_string(strval($branchCodeData['code']));
                                                } else {
                                                    $branch_code = null;
                                                }
                                            } else {
                                                $branch_code = null;
                                            }
                                            $stmt->close();
                                        }
        
                                        $branch_outlet = $conn->real_escape_string(strval($worksheet->getCell('P' . $row)->getValue()));

                                        $tg_region_code_raw = $conn->real_escape_string(strval($worksheet->getCell('Q' . $row)->getValue()));
                                        $region_description = strval($worksheet->getCell('R' . $row)->getValue());
                                        $kpxregioncodeQuery1 = "SELECT region_code, zone_code FROM masterdata.region_masterfile
                                                                WHERE (gl_region = ? OR region_desc_kpx = ? OR tg_region_code = ?) LIMIT 1";
                                        $stmt = $conn->prepare($kpxregioncodeQuery1);
                                        if ($stmt) {
                                            $stmt->bind_param("sss",$region_description, $region_description, $tg_region_code_raw);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result && $result->num_rows > 0) {
                                                $regioncodeData = $result->fetch_assoc();
                                                if ($regioncodeData && isset($regioncodeData['region_code'])) {
                                                    $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                                    $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                                }else{
                                                    $region_code = null;
                                                    $zone_code = null;
                                                }
                                            }else{
                                                $region_code = null;
                                                $zone_code = null;
                                            }
                                            $stmt->close();
                                        }
                                        
                                        $person_operator = $conn->real_escape_string(strval($worksheet->getCell('S' . $row)->getValue()));
                                        $remote_branch = $conn->real_escape_string(strval($worksheet->getCell('T' . $row)->getValue()));
                                        $remote_operator = $conn->real_escape_string(strval($worksheet->getCell('U' . $row)->getValue()));
                                        $second_approver = $conn->real_escape_string(strval($worksheet->getCell('V' . $row)->getValue()));

                                    }else{
                                        if ($branch_id_raw === 'HEAD OFFICE' || $branch_id_raw === 'ML HEAD OFFICE') {
                                            $cntl_num_for_region = intval(2607);
                                        } elseif ($branch_id_raw === 'CEBU HEAD OFFICE' || $branch_id_raw === 'ML CEBU HEAD OFFICE') {
                                            $cntl_num_for_region = intval(581);
                                        } elseif (empty($control_number)) {
                                            if (substr($reference_number, 0, 3) === 'APB') {
                                                $cntl_num_for_region = intval(2607);
                                            }
                                        } else {
                                            if (substr($control_number, 0, 3) === 'BPX') {
                                                $cntl_num_for_region = intval(2607);
                                            } else {
                                                $cntl_no_str = '';
                                                for ($i = 0; $i < strlen($control_number); $i++) {
                                                    if ($control_number[$i] === '-') break;
                                                    if (is_numeric($control_number[$i])) {
                                                        $cntl_no_str .= $control_number[$i];
                                                    }
                                                }
                                                $cntl_num_for_region = intval($cntl_no_str);
                                            }
                                        }
                                        $branch_id = $conn->real_escape_string($cntl_num_for_region);
                                        $kpxbranchcodeQuery = "SELECT code FROM masterdata.branch_profile where branch_id = ? LIMIT 1";
                                        $stmt = $conn->prepare($kpxbranchcodeQuery);
                                        if ($stmt) {
                                            $stmt->bind_param("i", $cntl_num_for_region);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result && $result->num_rows > 0) {
                                                $branchCodeData = $result->fetch_assoc();
                                                if ($branchCodeData && isset($branchCodeData['code'])) {
                                                    $branch_code = $conn->real_escape_string(strval($branchCodeData['code']));
                                                } else {
                                                    $branch_code = null;
                                                }
                                            } else {
                                                $branch_code = null;
                                            }
                                            $stmt->close();
                                        }
        
                                        $branch_outlet = $conn->real_escape_string(strval($worksheet->getCell('O' . $row)->getValue()));
                                        $region_description = strval($worksheet->getCell('P' . $row)->getValue());
                                        $kpxregioncodeQuery1 = "SELECT region_code, zone_code FROM masterdata.region_masterfile
                                                                WHERE (gl_region = ? OR region_desc_kpx = ?) LIMIT 1";
                                        $stmt = $conn->prepare($kpxregioncodeQuery1);
                                        if ($stmt) {
                                            $stmt->bind_param("ss",$region_description, $region_description);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result && $result->num_rows > 0) {
                                                $regioncodeData = $result->fetch_assoc();
                                                if ($regioncodeData && isset($regioncodeData['region_code'])) {
                                                    $region_code = $conn->real_escape_string(strval($regioncodeData['region_code']));
                                                    $zone_code = $conn->real_escape_string(strval($regioncodeData['zone_code']));
                                                }else{
                                                    $region_code = null;
                                                    $zone_code = null;
                                                }
                                            }else{
                                                $region_code = null;
                                                $zone_code = null;
                                            }
                                            $stmt->close();
                                        }
                                        
                                        $person_operator = $conn->real_escape_string(strval($worksheet->getCell('Q' . $row)->getValue()));
                                        $remote_branch = $conn->real_escape_string(strval($worksheet->getCell('R' . $row)->getValue()));
                                        $remote_operator = $conn->real_escape_string(strval($worksheet->getCell('S' . $row)->getValue()));
                                        $second_approver = $conn->real_escape_string(strval($worksheet->getCell('T' . $row)->getValue()));
                                    }
                                }
                                
                                
                                if ($partner === 'All'){ // CONSOLIDATED
                                    if($getColumnLabels[12] === 'Branch ID'){
                                        $partnerIds_kpx = $conn->real_escape_string(strval($worksheet->getCell('U' . $row)->getValue()));
                                        $partnerName_raw = $conn->real_escape_string(strval($worksheet->getCell('V' . $row)->getValue()));
                                    }else { // WITHOUT BRANCH ID COLUMN AND REGION CODE COLUMN
                                        $partnerIds_kpx = $conn->real_escape_string(strval($worksheet->getCell('S' . $row)->getValue()));
                                        $partnerName_raw = $conn->real_escape_string(strval($worksheet->getCell('T' . $row)->getValue()));
                                    }

                                    $mainpartnerNameQuery = "SELECT partner_name FROM masterdata.subbiller GROUP BY partner_name";
                                    $validPartnerNames = [];
                                    $stmt1 = $conn->prepare($mainpartnerNameQuery);
                                    if ($stmt1) {
                                        $stmt1->execute();
                                        $result = $stmt1->get_result();
                                        if ($result && $result->num_rows > 0) {
                                            while ($rowData = $result->fetch_assoc()) {
                                                if (isset($rowData['partner_name'])) {
                                                    $validPartnerNames[] = $conn->real_escape_string(strval($rowData['partner_name']));
                                                }
                                            }
                                        }
                                        $stmt1->close();
                                    }

                                    if(in_array($partnerName_raw, $validPartnerNames, true)){
                                        $getGLCode_partner_ID = "SELECT partner_id, gl_code FROM masterdata.partner_masterfile WHERE partner_id_kpx = ? LIMIT 1";
                                        $GLCodeData['direct_billers_name'] = $partnerName_raw;
                                        $GLCodeData['sub_billers_id'] = null;
                                        $GLCodeData['sub_billers_name'] = null;

                                        $stmt = $conn->prepare($getGLCode_partner_ID);
                                        if ($stmt) {
                                            $stmt->bind_param("s", $partnerIds_kpx);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result && $result->num_rows > 0) {
                                                $GLCodeData = $result->fetch_assoc();
                                                if ($GLCodeData) {
                                                    $partnerIds_kp7 = isset($GLCodeData['partner_id']) ? $conn->real_escape_string(strval($GLCodeData['partner_id'])) : null;
                                                    $sub_billers_id = isset($GLCodeData['sub_billers_id']) ? $conn->real_escape_string(strval($GLCodeData['sub_billers_id'])) : null;
                                                    $GLCode = isset($GLCodeData['gl_code']) ? $conn->real_escape_string(strval($GLCodeData['gl_code'])) : null;
                                                    $SubBillersName = isset($GLCodeData['sub_billers_name']) ? $conn->real_escape_string(strval($GLCodeData['sub_billers_name'])) : null;
                                                    $partnerName = isset($GLCodeData['direct_billers_name']) ? $conn->real_escape_string(strval($GLCodeData['direct_billers_name'])) : $partnerName_raw;
                                                }else{
                                                    $partnerIds_kp7 = null;
                                                    $sub_billers_id = null;
                                                    $GLCode = null;
                                                    $SubBillersName = null;
                                                    $partnerName = $partnerName_raw;
                                                }
                                            }else{
                                                $partnerIds_kp7 = null;
                                                $sub_billers_id = null;
                                                $GLCode = null;
                                                $SubBillersName = null;
                                                $partnerName = $partnerName_raw;
                                            }
                                            $stmt->close();
                                        }
                                    }else {
                                        $getGLCode_partner_ID = "WITH direct_biller AS (
                                            SELECT
                                                partner_id,
                                                partner_id_kpx,
                                                gl_code,
                                                partner_name AS direct_billers_name,
                                                NULL AS sub_billers_name,
                                                status
                                            FROM masterdata.partner_masterfile
                                        ),

                                        sub_biller AS (
                                            SELECT
                                                partner_id_kpx,
                                                sub_billers_id,
                                                partner_name AS direct_billers_name,
                                                sub_billers_name,
                                                NULL AS sub_gl_code
                                            FROM masterdata.subbiller
                                        ),

                                        merged_left AS (
                                            SELECT
                                                d.partner_id,
                                                COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                                                s.sub_billers_id,
                                                COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                                                CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                                                COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
                                            FROM direct_biller d
                                            LEFT JOIN sub_biller s
                                                ON d.direct_billers_name = s.sub_billers_name
                                            WHERE COALESCE(d.status, '') = 'ACTIVE'
                                        ),

                                        unmatched_sub AS (
                                            SELECT
                                                NULL AS partner_id,
                                                s.partner_id_kpx AS partner_id_kpx,
                                                s.sub_billers_id,
                                                s.sub_gl_code AS gl_code,
                                                s.direct_billers_name AS direct_billers_name,
                                                s.sub_billers_name AS sub_billers_name
                                            FROM sub_biller s
                                            WHERE NOT EXISTS (
                                                SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
                                            )
                                        )

                                        SELECT
                                            t.partner_id,
                                            t.sub_billers_id,
                                            t.gl_code,
                                            t.direct_billers_name,
                                            t.sub_billers_name
                                        FROM (
                                            SELECT * FROM merged_left WHERE sub_billers_name = ?
                                            UNION ALL
                                            SELECT * FROM unmatched_sub WHERE sub_billers_name = ?
                                        ) t LIMIT 1";

                                        // prepare and bind on the same statement object

                                        $stmt = $conn->prepare($getGLCode_partner_ID);
                                        if ($stmt) {
                                            $stmt->bind_param("ss", $partnerName_raw, $partnerName_raw);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            if ($result && $result->num_rows > 0) {
                                                $GLCodeData = $result->fetch_assoc();
                                                if ($GLCodeData) {
                                                    $partnerIds_kp7 = $conn->real_escape_string(strval($GLCodeData['partner_id']));
                                                    $sub_billers_id = $conn->real_escape_string(strval($GLCodeData['sub_billers_id']));
                                                    $GLCode = $conn->real_escape_string(strval($GLCodeData['gl_code']));
                                                    $SubBillersName = $conn->real_escape_string(strval($GLCodeData['sub_billers_name']));
                                                    $partnerName = $conn->real_escape_string(strval($GLCodeData['direct_billers_name']));
                                                }else{
                                                    $partnerIds_kp7 = null;
                                                    $sub_billers_id = null;
                                                    $GLCode = null;
                                                    $SubBillersName = null;
                                                    $partnerName = $partnerName_raw;
                                                }
                                            }else{
                                                $partnerIds_kp7 = null;
                                                $sub_billers_id = null;
                                                $GLCode = null;
                                                $SubBillersName = null;
                                                $partnerName = $partnerName_raw;
                                            }
                                            $stmt->close();
                                        }
                                    }

                                    
                                }else { // Per Partner
                                    $partnerIds_kp7 = $conn->real_escape_string(strval($PartnerID));
                                    $partnerIds_kpx = $conn->real_escape_string(strval($PartnerID_KPX));
                                    $sub_billers_id = $conn->real_escape_string(strval($SubBillersID));
                                    $GLCode = $conn->real_escape_string(strval($GLCode));
                                    $SubBillersName = $conn->real_escape_string(strval($SubPartnerName));
                                    $partnerName = $conn->real_escape_string(strval($PartnerName));
                                }
                            }else {
                                echo '<script>
                                    document.addEventListener("DOMContentLoaded", function() {
                                        Swal.fire({
                                            icon: "error",
                                            title: "File Not Found",
                                            text: "The specified file could not be found or accessed.",
                                            confirmButtonText: "OK",
                                            confirmButtonColor: "#dc3545"
                                        }).then(() => {
                                            window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                                        });
                                    });
                                </script>';
                            }
                        }

                        $settle_unsettle = 'Unsettle';
                        $claim_unclaim = null;
                        $rfp_no = null;
                        $cad_no = null;
                        $hold_status = null;
                        $post_transaction = 'unposted';
                        $imported_by = $conn->real_escape_string(strval($_SESSION['admin_name'] ?? $_SESSION['user_name']));
                        $date_uploaded = date('Y-m-d');

                        $is_duplicate = checkDuplicateData($conn, $reference_number, $datetime);
                        $is_partner_not_found = checkhadPartnerID($conn, $fileType, $partner, $partnerIds_kp7, $sub_billers_id, $partnerIds_kpx);
                        $is_region_not_found = checkSpelledRegionName($conn, $fileType, $region_description, $region_code);
                        $is_branch_not_found = checkHadBranchID($conn, $branch_id);

                        $row_error_modules = [];

                        if ($is_duplicate) {
                            $row_error_modules[] = 'duplicate';
                        }
                        if ($is_partner_not_found) {
                            $row_error_modules[] = 'partner_not_found';
                        }
                        if ($is_region_not_found) {
                            $row_error_modules[] = 'region_not_found';
                        }
                        if ($is_branch_not_found) {
                            $row_error_modules[] = 'branch_id_not_found';
                        }

                        if (!empty($row_error_modules)) {
                            $consolidated_error_data[] = [
                                'original_file_name' => $file_name,
                                'source_file_type' => $fileType,
                                'source_partner' => $partner,
                                'uploaded_date' => $date_uploaded,
                                'uploaded_by' => $imported_by,
                                'row_in_excel' => $row,
                                'report_date' => $report_date,
                                'transaction_date' => $datetime,
                                'cancellation_date' => $is_cancellation ? $datetime : null,
                                'reference_number' => $reference_number,
                                'payor_name' => $payor_name,
                                'amount_paid' => $amount_paid,
                                'amount_charge_customer' => $amount_charge_customer,
                                'amount_charge_partner' => $amount_charge_partner,
                                'branch_id' => $branch_id,
                                'ml_outlet' => $branch_outlet,
                                'region_code' => $region_code,
                                'region' => $region_description,
                                'partner_id' => $partnerIds_kp7,
                                'partner_id_kpx' => $partnerIds_kpx,
                                'partner_name' => $partnerName,
                                'error_remarks' => implode('; ', $row_error_modules),
                                'validation_modules' => $row_error_modules
                            ];
                            continue;
                        }

                        if (checkHasAlreadyDataReadyToOverride($conn, $reference_number, $datetime)) {
                                $ready_to_override_data[] = [
                                    'numeric_number' => $cancellStatus,
                                    'datetime' => $datetime,
                                    'control_number' => $control_number,
                                    'reference_number' => $reference_number,
                                    'payor_name' => $payor_name,
                                    'payor_address' => $payor_address,
                                    'account_number' => $account_number,
                                    'account_name' => $account_name,
                                    'amount_paid' => $amount_paid,
                                    'amount_charge_partner' => $amount_charge_partner,
                                    'amount_charge_customer' => $amount_charge_customer,
                                    'contact_number' => $contact_number,
                                    'other_details' => $other_details,
                                    'branch_id' => $branch_id,
                                    'branch_code' => $branch_code,
                                    'branch_outlet' => $branch_outlet,
                                    'region_code' => $region_code,
                                    'zone_code' => $zone_code,
                                    'region_description' => $region_description,
                                    'person_operator' => $person_operator,
                                    'partner_name' => $partnerName,
                                    'partner_id' => $partnerIds_kp7,
                                    'partner_id_kpx' => $partnerIds_kpx,
                                    'sub_billers_id' => $sub_billers_id,
                                    'sub_billers_name' => $SubBillersName,
                                    'GLCode' => $GLCode,
                                    'remote_branch' => $remote_branch,
                                    'remote_operator' => $remote_operator,
                                    'second_approver' => $second_approver,
                                    'settle_unsettle' => $settle_unsettle,
                                    'claim_unclaim' => $claim_unclaim,
                                    'report_date' => $report_date,
                                    'imported_by' => $imported_by,
                                    'date_uploaded' => $date_uploaded,
                                    'rfp_no' => $rfp_no,
                                    'cad_no' => $cad_no,
                                    'hold_status' => $hold_status,
                                    'post_transaction' => $post_transaction
                                ];
                        } else {
                                $rawData[] = [
                                    'numeric_number' => $cancellStatus,
                                    'datetime' => $datetime,
                                    'control_number' => $control_number,
                                    'reference_number' => $reference_number,
                                    'payor_name' => $payor_name,
                                    'payor_address' => $payor_address,
                                    'account_number' => $account_number,
                                    'account_name' => $account_name,
                                    'amount_paid' => $amount_paid,
                                    'amount_charge_partner' => $amount_charge_partner,
                                    'amount_charge_customer' => $amount_charge_customer,
                                    'contact_number' => $contact_number,
                                    'other_details' => $other_details,
                                    'branch_id' => $branch_id,
                                    'branch_code' => $branch_code,
                                    'branch_outlet' => $branch_outlet,
                                    'region_code' => $region_code,
                                    'zone_code' => $zone_code,
                                    'region_description' => $region_description,
                                    'person_operator' => $person_operator,
                                    'partner_name' => $partnerName,
                                    'partner_id' => $partnerIds_kp7,
                                    'partner_id_kpx' => $partnerIds_kpx,
                                    'sub_billers_id' => $sub_billers_id,
                                    'sub_billers_name' => $SubBillersName,
                                    'GLCode' => $GLCode,
                                    'remote_branch' => $remote_branch,
                                    'remote_operator' => $remote_operator,
                                    'second_approver' => $second_approver,
                                    'settle_unsettle' => $settle_unsettle,
                                    'claim_unclaim' => $claim_unclaim,
                                    'report_date' => $report_date,
                                    'imported_by' => $imported_by,
                                    'date_uploaded' => $date_uploaded,
                                    'rfp_no' => $rfp_no,
                                    'cad_no' => $cad_no,
                                    'hold_status' => $hold_status,
                                    'post_transaction' => $post_transaction
                                ];
                        }

                    }

                    // Set session variables immediately after processing
                    $_SESSION['original_file_name'] = $file_name;
                    $_SESSION['source_file_type'] = $fileType;
                    $_SESSION['transactionDate'] = $selectedDate;
                    $_SESSION['ready_to_override_data'] = $ready_to_override_data;
                    $_SESSION['Matched_BranchID_data'] = $rawData; // Store non-duplicate data
                    $_SESSION['cancellation_BranchID_data'] = $cancellation_BranchID_data;
                    $_SESSION['consolidated_data'] = $consolidated_error_data;
                    $_SESSION['validation_error_json'] = json_encode([
                        'summary' => [
                            'total_errors' => count($consolidated_error_data),
                            'duplicate' => count($duplicate_data),
                            'partner_not_found' => count($partner_not_found_data),
                            'branch_id_not_found' => count($branchID_notFoundData),
                            'region_not_found' => count($region_not_found_data)
                        ],
                        'errors_by_module' => [
                            'duplicate' => $duplicate_data,
                            'partner_not_found' => $partner_not_found_data,
                            'branch_id_not_found' => $branchID_notFoundData,
                            'region_not_found' => $region_not_found_data
                        ],
                        'rows' => $consolidated_error_data
                    ], JSON_UNESCAPED_UNICODE);

                }else{
                    echo '<script>
                                Swal.fire({
                                    icon: "error",
                                    title: "Invalid File Type",
                                    text: "Please upload a valid Excel file.",
                                    confirmButtonText: "OK"
                                }).then(() => {
                                    window.location.href = "billspay-transaction.php";
                                });
                            </script>';
                }
            }
        }
    }
    
    if(isset($_POST['confirm_import'])) {
        $Matched_BranchID_data = $_SESSION['Matched_BranchID_data'] ?? [];
        $cancellation_BranchID_data = $_SESSION['cancellation_BranchID_data'] ?? [];
        $selected_report_date = normalizeReportDate($_POST['report_date'] ?? ($_SESSION['manual_report_date'] ?? ($_SESSION['extracted_report_date'] ?? null)));

        if (empty($selected_report_date)) {
            $firstMatched = $Matched_BranchID_data[0]['report_date'] ?? null;
            $firstCancelled = $cancellation_BranchID_data[0]['report_date'] ?? null;
            $selected_report_date = normalizeReportDate($firstMatched ?? $firstCancelled);
        }
        if (empty($selected_report_date)) {
            $selected_report_date = date('Y-m-d');
        }

        $_SESSION['manual_report_date'] = $selected_report_date;

        // Add this debug code INSIDE the confirm_import block
        error_log("Debug - Matched data count: " . count($Matched_BranchID_data));
        error_log("Debug - Cancellation data count: " . count($cancellation_BranchID_data));

        if (empty($Matched_BranchID_data) && empty($cancellation_BranchID_data)) {
            echo '<script>
                Swal.fire({
                    icon: "warning",
                    title: "No Data Found",
                    text: "No data available to import. Please upload a file first.",
                    confirmButtonText: "OK"
                }).then(() => {
                    window.location.href = "billspay-transaction.php";
                });
            </script>';
            exit;
        }

        $raw_matched_data = [];

        // Add matched data to raw_matched_data array
        foreach($Matched_BranchID_data as $matched_row) {
            $raw_matched_data[] = $matched_row;
        }
        
        // Add cancellation data to raw_matched_data array
        foreach($cancellation_BranchID_data as $cancellation_row) {
            $raw_matched_data[] = $cancellation_row;
        }

        // NEW LOGIC: Process matched pairs and create final dataset
        $processed_data = [];
        $cancellation_refs = [];
        $regular_refs = [];
        
        if($_SESSION['source_file_type'] === 'KP7'){
            // Logic for KP7 file type
            $processed_data = $raw_matched_data;
        }
        elseif($_SESSION['source_file_type'] === 'KPX') {

            // First pass: separate cancellations and regular transactions
            foreach($raw_matched_data as $row) {
                $is_cancellation = isset($row['numeric_number']) && $row['numeric_number'] === '*';
                
                if ($is_cancellation) {
                    $cancellation_refs[$row['reference_number']] = $row;
                } else {
                    $regular_refs[$row['reference_number']] = $row;
                }
            }
            
            // Second pass: create processed dataset
            foreach($cancellation_refs as $ref_no => $cancellation_row) {
                if (isset($regular_refs[$ref_no])) {
                    // Found matching regular transaction - merge them
                    $merged_row = $cancellation_row; // Start with cancellation data
                    $merged_row['regular_datetime'] = $regular_refs[$ref_no]['datetime']; // Add regular datetime
                    $processed_data[] = $merged_row;
                    
                    // Remove the regular transaction from processing (it won't be inserted separately)
                    // unset($regular_refs[$ref_no]);
                } else {
                    // Cancellation without matching regular transaction
                    $processed_data[] = $cancellation_row;
                }
            }
            
            // Add any remaining regular transactions (those without cancellations)
            foreach($regular_refs as $regular_row) {
                $processed_data[] = $regular_row;
            }
        }

        // Add debug for sample row data AFTER arrays are populated
        error_log("Debug - Processed data count: " . count($processed_data));
        error_log("Debug - Sample processed row: " . print_r($processed_data[0] ?? [], true));
        
        // Start transaction for better data integrity
        $conn->autocommit(FALSE);
        
        $insertedCount = 0;
        $errors = [];
        
        try {

            foreach($processed_data as $row) {
                // Check if it's a cancellation transaction based on numeric_number
                $is_cancellation = isset($row['numeric_number']) && $row['numeric_number'] === '*';
                
                // Use the existing field names from your data structure
                $status = $is_cancellation ? '*' : null;
                $source_file = $_SESSION['source_file_type'] ?? $fileType;
                $control_number = $row['control_number'];
                $reference_number = $row['reference_number'];
                $payor_name = $row['payor_name'];
                $payor_address = $row['payor_address'];
                $account_number = $row['account_number'];
                $account_name = $row['account_name'];
                $amount_paid = floatval($row['amount_paid']);
                $amount_charge_partner = floatval($row['amount_charge_partner']);
                $amount_charge_customer = floatval($row['amount_charge_customer']);
                $contact_number = $row['contact_number'];
                $other_details = $row['other_details'];
                $branch_id = $row['branch_id'];
                $branch_code = $row['branch_code'];
                $branch_outlet = $row['branch_outlet'];
                $region_code = $row['region_code'];
                $zone_code = $row['zone_code'];
                $region_description = $row['region_description'];
                $person_operator = $row['person_operator'];
                $sub_billers_id = $row['sub_billers_id'] ?? null;
                $sub_billers_name = $row['sub_billers_name'] ?? null;
                $partner_name = $row['partner_name'];
                $partner_id = $row['partner_id'] ?? null;
                $partner_ID_KPX = $row['partner_id_kpx'] ?? null;
                $GLCode = $row['GLCode'] ?? null;
                $imported_by = $row['imported_by'];
                $imported_date = $row['date_uploaded'];
                $remote_branch = $row['remote_branch'];
                $remote_operator = $row['remote_operator'];
                $second_approver = $row['second_approver'] ?? null;
                $report_date = $conn->real_escape_string($selected_report_date);
                
                // NEW LOGIC: Handle datetime based on whether it's a matched cancellation
                $datetime_value = null;
                $cancellation_date = null;
                
                if ($is_cancellation && isset($row['regular_datetime'])) {
                    // This is a cancellation with matching regular transaction
                    $datetime_value = $row['regular_datetime']; // Regular transaction datetime
                    $cancellation_date = $row['datetime']; // Cancellation datetime
                } elseif ($is_cancellation) {
                    // This is a cancellation without matching regular transaction
                    if ($_SESSION['source_file_type'] === 'KP7') {
                        $datetime_value = $row['datetime'];
                        $cancellation_date = date('Y-m-d H:i:s', strtotime($row['report_date'])) ?? null;
                    }elseif($_SESSION['source_file_type'] === 'KPX') {
                        $cancellation_date = $row['datetime'];
                        $datetime_value = null;
                    }
                } else {
                    // This is a regular transaction without cancellation
                    $datetime_value = $row['datetime'];
                    $cancellation_date = null;
                }

                $settle_unsettle = 'Unsettle';
                $claim_unclaim = null;
                $rfp_no = null;
                $cad_no = null;
                $hold_status = null;
                $post_transaction = 'unposted';

                // Build SQL query with proper escaping
                $sql = "INSERT INTO mldb.billspayment_transaction (
                    `status`, 
                    `datetime`, 
                    cancellation_date, 
                    report_date,
                    source_file, 
                    control_no, 
                    reference_no, 
                    payor, 
                    `address`, 
                    account_no, 
                    account_name, 
                    amount_paid, 
                    charge_to_partner, 
                    charge_to_customer, 
                    contact_no, 
                    other_details, 
                    branch_id, 
                    branch_code,
                    outlet, 
                    zone_code,
                    region_code, 
                    region, 
                    operator, 
                    partner_name, 
                    partner_id, 
                    partner_id_kpx,
                    mpm_gl_code,
                    settle_unsettle, 
                    claim_unclaim, 
                    imported_by, 
                    imported_date, 
                    rfp_no, 
                    cad_no, 
                    hold_status, 
                    remote_branch, 
                    remote_operator,
                    `2nd_approver`,
                    sub_billers_id,
                    sub_billers_name,
                    post_transaction
                ) VALUES (
                    " . ($status ? "'$status'" : "NULL") . ",
                    " . ($datetime_value ? "'$datetime_value'" : "NULL") . ",
                    " . ($cancellation_date ? "'$cancellation_date'" : "NULL") . ",
                    '$report_date',
                    '$source_file',
                    '$control_number',
                    '$reference_number',
                    '$payor_name',
                    '$payor_address',
                    '$account_number',
                    '$account_name',
                    $amount_paid,
                    $amount_charge_partner,
                    $amount_charge_customer,
                    '$contact_number',
                    '$other_details',
                    '$branch_id',
                    '$branch_code',
                    '$branch_outlet',
                    '$zone_code',
                    '$region_code',
                    '$region_description',
                    '$person_operator',
                    '$partner_name',
                    " . ($partner_id ? "'$partner_id'" : "NULL") . ",
                    " . ($partner_ID_KPX ? "'$partner_ID_KPX'" : "NULL") . ",
                    " . ($GLCode ? "'$GLCode'" : "NULL") . ",
                    " . ($settle_unsettle ? "'$settle_unsettle'" : "NULL") . ",
                    " . ($claim_unclaim ? "'$claim_unclaim'" : "NULL") . ",
                    '$imported_by',
                    '$imported_date',
                    " . ($rfp_no ? "'$rfp_no'" : "NULL") . ",
                    " . ($cad_no ? "'$cad_no'" : "NULL") . ",
                    " . ($hold_status ? "'$hold_status'" : "NULL") . ",
                    '$remote_branch',
                    '$remote_operator',
                    " . ($second_approver ? "'$second_approver'" : "NULL") . ",
                    " . ($sub_billers_id ? "'$sub_billers_id'" : "NULL") . ",
                    " . ($sub_billers_name ? "'$sub_billers_name'" : "NULL") . ",
                    '$post_transaction'
                )";

                // Execute the query
                $result = $conn->query($sql);
                
                if ($result) {
                    $insertedCount++;
                    error_log("Successfully inserted row: " . $reference_number . 
                            " (Cancellation: " . ($is_cancellation ? 'Yes' : 'No') . 
                            ", Has matched pair: " . (isset($row['regular_datetime']) ? 'Yes' : 'No') . ")");
                } else {
                    $error_msg = "Row insert failed for reference: $reference_number - Error: " . $conn->error;
                    $errors[] = $error_msg;
                    error_log($error_msg);
                    error_log("Failed SQL: " . $sql);
                }
            }

            if (empty($errors)) {
                // Commit transaction if all inserts successful
                $conn->commit();
                
                // Clear session data after successful import
                unset($_SESSION['Matched_BranchID_data']);
                unset($_SESSION['cancellation_BranchID_data']);
                unset($_SESSION['ready_to_override_data']);
                unset($_SESSION['original_file_name']);
                unset($_SESSION['source_file_type']);
                unset($_SESSION['transactionDate']);
                unset($_SESSION['manual_report_date']);
                unset($_SESSION['extracted_report_date']);
                
                echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        Swal.fire({
                            icon: "success",
                            title: "Data Successfully Imported",
                            html: `
                                <div class="text-center">
                                    <div class="alert alert-success">
                                        <strong>' . $insertedCount . '</strong> records inserted.
                                    </div>
                                </div>
                            `,
                            showConfirmButton: true,
                            confirmButtonText: "Close",
                            confirmButtonColor: "#28a745",
                            allowOutsideClick: false,
                            allowEscapeKey: false
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                            }
                        });
                    });
                </script>';
            } else {
                throw new Exception("Insert errors occurred: " . implode("; ", array_slice($errors, 0, 5)));
            }
        
        } catch (Exception $e) {
            // Rollback transaction if there were errors
            $conn->rollback();
            
            error_log("Import transaction failed: " . $e->getMessage());
            
            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    Swal.fire({
                        icon: "error",
                        title: "Import Transaction Failed",
                        html: `
                            <div class="text-center">
                                <i class="fas fa-exclamation-triangle text-danger mb-3" style="font-size: 3rem;"></i>
                                <h4 class="text-danger mb-3">Import Error Occurred</h4>
                                <div class="alert alert-danger">
                                    <strong>Error:</strong> ' . addslashes($e->getMessage()) . '
                                </div>
                                <div class="text-start mt-3">
                                    <small class="text-muted">Error details:</small>
                                    <ul class="list-unstyled mt-2">
                                        ' . (!empty($errors) ? implode('', array_map(function($error) { 
                                            return '<li class="text-danger small">• ' . htmlspecialchars($error) . '</li>'; 
                                        }, array_slice($errors, 0, 3))) : '<li class="text-danger small">• No specific error details available</li>') . '
                                        ' . (count($errors) > 3 ? '<li class="text-muted small">... and ' . (count($errors) - 3) . ' more errors</li>' : '') . '
                                    </ul>
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: "Try Again",
                        confirmButtonColor: "#dc3545",
                        allowOutsideClick: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                        }
                    });
                });
            </script>';
        } finally {
            // Always restore autocommit regardless of success or failure
            $conn->autocommit(TRUE);
        }
    } elseif (isset($_POST['override_comfirm'])){
        $ready_to_override_data = $_SESSION['ready_to_override_data'] ?? [];

        // Increase memory limit and execution time for large files
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 900); // 15 minutes
        
        if (empty($ready_to_override_data)) {
            echo '<script>
                Swal.fire({
                    icon: "warning",
                    title: "No Override Data Found",
                    text: "No data available to override. Please upload a file first.",
                    confirmButtonText: "OK"
                }).then(() => {
                    window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                });
            </script>';
            exit;
        }

        // Process override data to handle cancellation matching
        $processed_override_data = [];
        $matched_data = $_SESSION['Matched_BranchID_data'] ?? [];
        $cancellation_data = $_SESSION['cancellation_BranchID_data'] ?? [];
        
        // Separate cancellations and regular transactions from override data
        $override_cancellations = [];
        $override_regular = [];
        
        foreach($ready_to_override_data as $row) {
            $is_cancellation = isset($row['numeric_number']) && $row['numeric_number'] === '*';
            if ($is_cancellation) {
                $override_cancellations[$row['reference_number']] = $row;
            } else {
                $override_regular[$row['reference_number']] = $row;
            }
        }
        
        // Process cancellation matching for override data
        foreach($override_cancellations as $ref_no => $cancellation_row) {
            if (isset($override_regular[$ref_no])) {
                // Found matching regular transaction - merge them
                $merged_row = $cancellation_row; // Start with cancellation data
                $merged_row['regular_datetime'] = $override_regular[$ref_no]['datetime']; // Add regular datetime
                $processed_override_data[] = $merged_row;
                
                // Remove the regular transaction from processing (it won't be inserted separately)
                // unset($override_regular[$ref_no]);
            } else {
                // Cancellation without matching regular transaction
                $processed_override_data[] = $cancellation_row;
            }
        }
        
        // Add any remaining regular transactions (those without cancellations)
        foreach($override_regular as $regular_row) {
            $processed_override_data[] = $regular_row;
        }

        // Calculate counts
        $matched_count = count($processed_override_data); // Records that match existing data (processed)
        
        // Calculate unmatched data from other session arrays  
        $unmatched_count = count($matched_data) + count($cancellation_data); // Records that don't match existing data

        // Show single override confirmation modal
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                Swal.fire({
                    title: "Do you want to Override it?",
                    html: `
                        <div class="text-center">
                            <h4 class="text-primary mb-3">Confirm Override Action</h4>
                            <div class="alert alert-warning">
                                <strong>Override Process:</strong><br>
                                • ' . $matched_count . ' existing records will be replaced.<br>
                                • ' . $unmatched_count . ' new records.<br>
                                • This action cannot be undone
                            </div>
                        </div>
                    `,
                    icon: "question",
                    showCancelButton: true,
                    confirmButtonText: "Yes, Override it",
                    cancelButtonText: "No, Cancel it",
                    confirmButtonColor: "#28a745",
                    cancelButtonColor: "#dc3545",
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        // User confirmed override - process the data
                        processOverrideData();
                    } else {
                        // User cancelled - redirect back
                        window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                    }
                });
            });

            function processOverrideData() {
                // Show loading
                Swal.fire({
                    title: "Processing Override...",
                    html: "Please wait while we process your data override.",
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Submit form to process override
                const form = document.createElement("form");
                form.method = "POST";
                form.style.display = "none";
                
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = "process_override";
                input.value = "1";
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        </script>';
    
        // Store processed data back to session
        $_SESSION['processed_override_data'] = $processed_override_data;
        
    } elseif (isset($_POST['process_override'])) {
        $processed_override_data = $_SESSION['processed_override_data'] ?? [];
        $matched_data = $_SESSION['Matched_BranchID_data'] ?? [];
        $cancellation_data = $_SESSION['cancellation_BranchID_data'] ?? [];
        $override_source_type = strtoupper(trim((string)($_SESSION['source_file_type'] ?? '')));
        $selected_report_date = normalizeReportDate($_POST['report_date'] ?? ($_SESSION['manual_report_date'] ?? ($_SESSION['extracted_report_date'] ?? null)));

        if (empty($selected_report_date)) {
            $firstOverride = $processed_override_data[0]['report_date'] ?? null;
            $firstMatched = $matched_data[0]['report_date'] ?? null;
            $firstCancelled = $cancellation_data[0]['report_date'] ?? null;
            $selected_report_date = normalizeReportDate($firstOverride ?? $firstMatched ?? $firstCancelled);
        }
        if (empty($selected_report_date)) {
            $selected_report_date = date('Y-m-d');
        }

        $_SESSION['manual_report_date'] = $selected_report_date;

        // Increase memory limit and execution time for large files
        ini_set('memory_limit', '100000M');
        ini_set('max_execution_time', 900); // 15 minutes
        
        if (empty($processed_override_data) && empty($matched_data) && empty($cancellation_data)) {
            echo '<script>
                Swal.fire({
                    icon: "error",
                    title: "No Data to Process",
                    text: "No override data found to process.",
                    confirmButtonText: "OK"
                }).then(() => {
                    window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                });
            </script>';
            exit;
        }

        // Start transaction for better data integrity
        $conn->autocommit(FALSE);
        
        $processedCount = 0;
        $insertedCount = 0;
        $deletedCount = 0;
        $errors = [];
        
        try {
            // Step 1: Process override data (records that match existing data)
            // FIXED: First create reference map for datetime sharing
            $reference_datetime_map = [];
            
            // Build map from ALL sources: override data, matched data, and cancellation data
            $all_data_sources = [
                $processed_override_data,
                $matched_data,
                $cancellation_data
            ];
            
            // Build map of regular transaction datetimes by reference number
            foreach($all_data_sources as $data_source) {
                foreach($data_source as $row) {
                    $is_cancellation = isset($row['numeric_number']) && $row['numeric_number'] === '*';
                    if (!$is_cancellation) {
                        // This is a regular transaction, store its datetime
                        $reference_datetime_map[$row['reference_number']] = $row['datetime'];
                    }
                }
            }
            
            foreach($processed_override_data as $row) {
                // Get source file from session or use default
                $source_file = $_SESSION['source_file_type'] ?? 'Unknown';

                // Normalize nullable fields so DB accepts NULLs (allow branch_id to be NULL)
                $row['branch_id'] = isset($row['branch_id']) && $row['branch_id'] !== '' ? $row['branch_id'] : null;
                $row['partner_ID_KP7'] = isset($row['partner_id']) && $row['partner_id'] !== '' ? $row['partner_id'] : null;
                $row['PartnerID_KPX'] = isset($row['partner_id_kpx']) && $row['partner_id_kpx'] !== '' ? $row['partner_id_kpx'] : null;
                $row['sub_billers_id'] = isset($row['sub_billers_id']) && $row['sub_billers_id'] !== '' ? $row['sub_billers_id'] : null;
                $row['second_approver'] = $row['second_approver'] ?? null;
                $row['post_transaction'] = $row['post_transaction'] ?? 'unposted';
                $row['datetime'] = $row['datetime'] ?? null;

                // First, clean up existing data using DELETE criteria
                $deleteSQL = "DELETE FROM `mldb`.`billspayment_transaction` 
                            WHERE post_transaction = ? 
                            AND reference_no = ? 
                            AND (`datetime` = ? OR cancellation_date = ?)";

                // Build dynamic params and types so we only add partner filter when partner id is present
                $types = "ssss";
                $params = [
                    &$row['post_transaction'],
                    &$row['reference_number'],
                    &$row['datetime'],
                    &$row['datetime']
                ];

                if ($source_file === 'KP7' && !empty($row['partner_ID_KP7'])) {
                    if(!empty($row['sub_billers_id'])){
                        $deleteSQL .= " AND sub_billers_id = ?";
                        $types .= "s";
                        $params[] = &$row['sub_billers_id'];
                    }else{
                        $deleteSQL .= " AND partner_id = ?";
                        $types .= "s";
                        $params[] = &$row['partner_ID_KP7'];
                    }
                } elseif ($source_file === 'KPX' && !empty($row['PartnerID_KPX'])) {
                    if(!empty($row['sub_billers_id'])){
                        $deleteSQL .= " AND sub_billers_id = ?";
                        $types .= "s";
                        $params[] = &$row['sub_billers_id'];
                    }else{
                        $deleteSQL .= " AND partner_id_kpx = ?";
                        $types .= "s";
                        $params[] = &$row['PartnerID_KPX'];
                    }
                }

                $deleteStmt = $conn->prepare($deleteSQL);
                if (!$deleteStmt) {
                    throw new Exception("Failed to prepare delete statement: " . $conn->error);
                }

                // Bind params dynamically (preserving references)
                array_unshift($params, $types);
                call_user_func_array([$deleteStmt, 'bind_param'], $params);

                if (!$deleteStmt->execute()) {
                    throw new Exception("Failed to delete existing record for reference: " . $row['reference_number'] . " - Error: " . $deleteStmt->error);
                }

                $deletedCount += $deleteStmt->affected_rows;
                
                // Then insert new record - handle cancellation matching
                $is_cancellation = isset($row['numeric_number']) && $row['numeric_number'] === '*';
                $status = $is_cancellation ? '*' : null;

                // COMPLETELY FIXED LOGIC: Handle datetime properly for cancellations
                $datetime_value = null;
                $cancellation_date = null;
                
                if ($is_cancellation) {
                    // This is a cancellation - ALWAYS try to find matching regular transaction datetime
                    if (isset($reference_datetime_map[$row['reference_number']])) {
                        // Found matching regular transaction.
                        if ($override_source_type === 'KP7') {
                            // KP7 override rule: do not write cancellation_date.
                            $datetime_value = $reference_datetime_map[$row['reference_number']];
                            $cancellation_date = null;
                        } else {
                            $datetime_value = $reference_datetime_map[$row['reference_number']];
                            $cancellation_date = $row['datetime'];
                        }
                    } else {
                        // No matching regular transaction found - handle based on file type
                        if ($override_source_type === 'KP7') {
                            // For KP7, use cancellation datetime as main datetime
                            $datetime_value = $row['datetime'];
                            $cancellation_date = null;
                        } elseif ($override_source_type === 'KPX') {
                            // For KPX, put datetime in cancellation_date field, keep main datetime null
                            $datetime_value = null;
                            $cancellation_date = $row['datetime'];
                        }
                    }
                } else {
                    // This is a regular transaction - always use its own datetime
                    $datetime_value = $row['datetime'];
                    $cancellation_date = null;
                }

                // Final safety: for KP7 cancellation rows, write cancellation_date from report_date.
                if ($is_cancellation && $override_source_type === 'KP7') {
                    if (empty($datetime_value)) {
                        $datetime_value = $row['datetime'] ?? null;
                    }
                    $kp7CancellationDateRaw = $row['report_date'] ?? $selected_report_date;
                    $kp7CancellationTs = strtotime((string)$kp7CancellationDateRaw);
                    $cancellation_date = ($kp7CancellationTs !== false)
                        ? date('Y-m-d H:i:s', $kp7CancellationTs)
                        : ($row['datetime'] ?? null);
                }

                // Ensure branch_id remains NULL if not provided so INSERT accepts it
                if ($row['branch_id'] === null || $row['branch_id'] === '') {
                    $row['branch_id'] = '';
                }

                $insertSQL = "INSERT INTO mldb.billspayment_transaction (
                            status, 
                            datetime, 
                            cancellation_date, 
                            report_date,
                            source_file, 
                            control_no, 
                            reference_no, 
                            payor, 
                            address, 
                            account_no, 
                            account_name, 
                            amount_paid, 
                            charge_to_partner, 
                            charge_to_customer, 
                            contact_no, 
                            other_details, 
                            branch_id, 
                            branch_code,
                            outlet, 
                            zone_code,
                            region_code, 
                            region, 
                            operator, 
                            partner_name, 
                            partner_id, 
                            partner_id_kpx,
                            mpm_gl_code,
                            settle_unsettle, 
                            claim_unclaim, 
                            imported_by, 
                            imported_date, 
                            rfp_no, 
                            cad_no, 
                            hold_status, 
                            remote_branch, 
                            remote_operator,
                            `2nd_approver`,
                            sub_billers_id,
                            sub_billers_name,
                            post_transaction
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $insertStmt = $conn->prepare($insertSQL);
                if (!$insertStmt) {
                    throw new Exception("Failed to prepare insert statement: " . $conn->error);
                }

                // Bind params; nulls in $row will be sent as SQL NULL
                $insertStmt->bind_param("sssssssssssdddssisssssssssssssssssssssss",
                    $status,
                    $datetime_value,
                    $cancellation_date,
                    $selected_report_date,
                    $source_file,
                    $row['control_number'],
                    $row['reference_number'],
                    $row['payor_name'],
                    $row['payor_address'],
                    $row['account_number'],
                    $row['account_name'],
                    $row['amount_paid'],
                    $row['amount_charge_partner'],
                    $row['amount_charge_customer'],
                    $row['contact_number'],
                    $row['other_details'],
                    $row['branch_id'],
                    $row['branch_code'],
                    $row['branch_outlet'],
                    $row['zone_code'],
                    $row['region_code'],
                    $row['region_description'],
                    $row['person_operator'],
                    $row['partner_name'],
                    $row['partner_ID_KP7'],
                    $row['PartnerID_KPX'],
                    $row['GLCode'],
                    $row['settle_unsettle'],
                    $row['claim_unclaim'],
                    $row['imported_by'],
                    $row['date_uploaded'],
                    $row['rfp_no'],
                    $row['cad_no'],
                    $row['hold_status'],
                    $row['remote_branch'],
                    $row['remote_operator'],
                    $row['second_approver'],
                    $row['sub_billers_id'],
                    $row['sub_billers_name'],
                    $row['post_transaction']
                );
                
                if (!$insertStmt->execute()) {
                    throw new Exception("Failed to insert override record for reference: " . $row['reference_number'] . " - Error: " . $insertStmt->error);
                }
                
                $deleteStmt->close();
                $insertStmt->close();
                $processedCount++;
            }

            // Step 2: Process unmatched data (records that don't exist in database)
            $all_unmatched_data = array_merge($matched_data, $cancellation_data);
            
            
            // Insert all unmatched data individually
            foreach($all_unmatched_data as $row) {
                $is_cancellation = isset($row['numeric_number']) && $row['numeric_number'] === '*';
                $status = $is_cancellation ? '*' : null;
                
                // SAME FIXED LOGIC: Handle datetime properly for unmatched cancellations
                $datetime_value = null;
                $cancellation_date = null;
                
                if ($is_cancellation) {
                    // This is a cancellation - use the complete reference map
                    if (isset($reference_datetime_map[$row['reference_number']])) {
                        // Found matching regular transaction.
                        if ($override_source_type === 'KP7') {
                            // KP7 override rule: do not write cancellation_date.
                            $datetime_value = $reference_datetime_map[$row['reference_number']];
                            $cancellation_date = null;
                        } else {
                            $datetime_value = $reference_datetime_map[$row['reference_number']];
                            $cancellation_date = $row['datetime'];
                        }
                    } else {
                        // No matching regular transaction found - handle based on file type
                        if ($override_source_type === 'KP7') {
                            // For KP7, use cancellation datetime as main datetime
                            $datetime_value = $row['datetime'];
                            $cancellation_date = null;
                        } elseif ($override_source_type === 'KPX') {
                            // For KPX, put datetime in cancellation_date field, keep main datetime null
                            $datetime_value = null;
                            $cancellation_date = $row['datetime'];
                        }
                    }
                } else {
                    // This is a regular transaction - always use its own datetime
                    $datetime_value = $row['datetime'];
                    $cancellation_date = null;
                }

                // Final safety: for KP7 cancellation rows, write cancellation_date from report_date.
                if ($is_cancellation && $override_source_type === 'KP7') {
                    if (empty($datetime_value)) {
                        $datetime_value = $row['datetime'] ?? null;
                    }
                    $kp7CancellationDateRaw = $row['report_date'] ?? $selected_report_date;
                    $kp7CancellationTs = strtotime((string)$kp7CancellationDateRaw);
                    $cancellation_date = ($kp7CancellationTs !== false)
                        ? date('Y-m-d H:i:s', $kp7CancellationTs)
                        : ($row['datetime'] ?? null);
                }
                
                // Ensure branch_id remains NULL if not provided so INSERT accepts it
                if ($row['branch_id'] === null || $row['branch_id'] === '') {
                    $row['branch_id'] = '';
                }
                $row['second_approver'] = $row['second_approver'] ?? null;
                
                // Debug logging for unmatched data too
                error_log("Processing unmatched for ref: " . $row['reference_number'] . 
                        ", is_cancellation: " . ($is_cancellation ? 'true' : 'false') . 
                        ", datetime_value: " . ($datetime_value ?? 'null') . 
                        ", cancellation_date: " . ($cancellation_date ?? 'null'));
                
                $insertSQL = "INSERT INTO mldb.billspayment_transaction (
                    status, 
                    datetime, 
                    cancellation_date, 
                    report_date,
                    source_file, 
                    control_no, 
                    reference_no, 
                    payor, 
                    address, 
                    account_no, 
                    account_name, 
                    amount_paid, 
                    charge_to_partner, 
                    charge_to_customer, 
                    contact_no, 
                    other_details, 
                    branch_id, 
                    branch_code,
                    outlet, 
                    zone_code,
                    region_code, 
                    region, 
                    operator, 
                    partner_name, 
                    partner_id, 
                    partner_id_kpx,
                    mpm_gl_code,
                    settle_unsettle, 
                    claim_unclaim, 
                    imported_by, 
                    imported_date, 
                    rfp_no, 
                    cad_no, 
                    hold_status, 
                    remote_branch, 
                    remote_operator,
                    `2nd_approver`,
                    sub_billers_id,
                    sub_billers_name,
                    post_transaction
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $insertStmt = $conn->prepare($insertSQL);
                
                // Get source file from session or use default
                $source_file = $_SESSION['source_file_type'] ?? 'Unknown';

                $insertStmt->bind_param("sssssssssssdddssiissssssssssssssssssssss", //40
                    $status,
                    $datetime_value,
                    $cancellation_date,
                    $selected_report_date,
                    $source_file,
                    $row['control_number'],
                    $row['reference_number'],
                    $row['payor_name'],
                    $row['payor_address'],
                    $row['account_number'],
                    $row['account_name'],
                    $row['amount_paid'],
                    $row['amount_charge_partner'],
                    $row['amount_charge_customer'],
                    $row['contact_number'],
                    $row['other_details'],
                    $row['branch_id'],
                    $row['branch_code'],
                    $row['branch_outlet'],
                    $row['zone_code'],
                    $row['region_code'],
                    $row['region_description'],
                    $row['person_operator'],
                    $row['partner_name'],
                    $row['partner_ID_KP7'],
                    $row['PartnerID_KPX'],
                    $row['GLCode'],
                    $row['settle_unsettle'],
                    $row['claim_unclaim'],
                    $row['imported_by'],
                    $row['date_uploaded'],
                    $row['rfp_no'],
                    $row['cad_no'],
                    $row['hold_status'],
                    $row['remote_branch'],
                    $row['remote_operator'],
                    $row['second_approver'],
                    $row['sub_billers_id'],
                    $row['sub_billers_name'],
                    $row['post_transaction']
                );
                
                if (!$insertStmt->execute()) {
                    throw new Exception("Failed to insert new record for reference: " . $row['reference_number'] . " - Error: " . $insertStmt->error);
                }
                
                $insertStmt->close();
                $insertedCount++;
            }

            // Commit transaction if all operations successful
            $conn->commit();
            
            // Clear session data after successful override
            unset($_SESSION['ready_to_override_data']);
            unset($_SESSION['processed_override_data']);
            unset($_SESSION['Matched_BranchID_data']);
            unset($_SESSION['cancellation_BranchID_data']);
            unset($_SESSION['original_file_name']);
            unset($_SESSION['source_file_type']);
            unset($_SESSION['transactionDate']);
            unset($_SESSION['manual_report_date']);
            unset($_SESSION['extracted_report_date']);
            
            $totalProcessed = $processedCount + $insertedCount;
            
            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    Swal.fire({
                        icon: "success",
                        title: "Override Successful!",
                        html: `
                            <div class="text-center">
                                <div class="alert alert-success">
                                    <strong>Processing Summary:</strong><br>
                                    • <strong>' . $processedCount . '</strong> records overridden<br>
                                    • <strong>' . $insertedCount . '</strong> new records inserted<br>
                                    • <strong>' . $totalProcessed . '</strong> total records processed
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: "Continue",
                        confirmButtonColor: "#28a745",
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                        }
                    });
                });
            </script>';
            
        } 
        catch (Exception $e) {
            // Rollback transaction if there were errors
            $conn->rollback();
            
            error_log("Override transaction failed: " . $e->getMessage());
            
            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    Swal.fire({
                        icon: "error",
                        title: "Override Failed",
                        html: `
                            <div class="text-center">
                                <i class="fas fa-exclamation-triangle text-danger mb-3" style="font-size: 3rem;"></i>
                                <h4 class="text-danger mb-3">Override Error Occurred</h4>
                                <div class="alert alert-danger">
                                    <strong>Error:</strong> ' . addslashes($e->getMessage()) . '
                                </div>
                                <p class="text-muted">Please try again or contact system administrator.</p>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: "Try Again",
                        confirmButtonColor: "#dc3545",
                        allowOutsideClick: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                        }
                    });
                });
            </script>';
        } finally {
            // Always restore autocommit regardless of success or failure
            $conn->autocommit(TRUE);
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import File</title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../assets/css/billspaymentImportFile.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../../images/MLW logo.png" type="image/png">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <style>
       /* Print styles */
        @media print {
            body * {
                visibility: hidden;
                visibility: visible;
            }
            .alert-warning {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                border: none !important;
                background-color: white !important;
                color: black !important;
            }
            .alert-warning .d-flex {
                display: none !important;
            }
            .alert-warning h4 {
                text-align: center;
                font-size: 18px;
                margin-bottom: 15px;
            }
            .alert-warning p {
                text-align: center;
                margin-bottom: 15px;
            }
            /* Make sure the table-responsive container shows all content */
            .table-responsive {
                max-height: none !important;
                height: auto !important;
                overflow: visible !important;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                page-break-inside: auto;
            }
            .table th, .table td {
                border: 1px solid #000;
            }
            .table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            .sticky-top {
                position: static;
            }
        }

        
        /* Enhanced SweetAlert2 backdrop for confidentiality */
        .swal2-container.swal2-backdrop-show {
            backdrop-filter: blur(10px);
            background-color: rgba(0,0,0,0.8) !important;
        }
        
        /* Make sure the modal itself is still clear */
        .swal2-popup {
            backdrop-filter: none !important;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }
        
    </style>
</head>
<body>
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>
    <?php 
        if(isset($_POST['upload'])){
            $file = $_FILES['import_file']['tmp_name'];
            $file_name = $_FILES['import_file']['name'];
            $file_name_array = explode('.', $file_name);
            $extension = strtolower(end($file_name_array));

            if(is_readable($file)) {
                if($extension === 'xlsx' || $extension === 'xls') {

                    // Get session data
                    $partnerSelection = $_SESSION['partnerselection'] ?? [];

                    $matchedData = $_SESSION['Matched_BranchID_data'] ?? [];
                    $cancellationData = $_SESSION['cancellation_BranchID_data'] ?? [];
                    $notFoundData = $_SESSION['missing_branch_ids'] ?? [];
                    $regionNotFoundData = $_SESSION['region_not_found_data'] ?? [];

                    // Enhanced summary calculation function
                    function calculateTransactionSummary($matchedRows, $cancellationRows) {
                        $summaries = [
                            'net' => ['count' => 0, 'principal' => 0, 'charge_partner' => 0, 'charge_customer' => 0, 'total_charge' => 0, 'settlement' => 0],
                            'adjustment' => ['count' => 0, 'principal' => 0, 'charge_partner' => 0, 'charge_customer' => 0, 'total_charge' => 0, 'settlement' => 0],
                            'summary' => ['count' => 0, 'principal' => 0, 'charge_partner' => 0, 'charge_customer' => 0, 'total_charge' => 0, 'settlement' => 0]
                        ];

                        // First, collect all cancellation reference numbers
                        $cancellation_reference_numbers = [];
                        foreach ($matchedRows as $row) {
                            if (isset($row['numeric_number']) && $row['numeric_number'] === '*') {
                                $cancellation_reference_numbers[] = $row['reference_number'];
                            }
                        }

                        // Also check the separate cancellation array if it exists
                        if (!empty($cancellationRows)) {
                            foreach ($cancellationRows as $cancellationGroup) {
                                if (is_array($cancellationGroup)) {
                                    if (isset($cancellationGroup[0]) && is_array($cancellationGroup[0])) {
                                        foreach ($cancellationGroup as $rowArray) {
                                            foreach ($rowArray as $row) {
                                                if (isset($row['numeric_number']) && $row['numeric_number'] === '*') {
                                                    $cancellation_reference_numbers[] = $row['reference_number'];
                                                }
                                            }
                                        }
                                    } else {
                                        if (isset($cancellationGroup['numeric_number']) && $cancellationGroup['numeric_number'] === '*') {
                                            $cancellation_reference_numbers[] = $cancellationGroup['reference_number'];
                                        }
                                    }
                                }
                            }
                        }

                        // Remove duplicates
                        $cancellation_reference_numbers = array_unique($cancellation_reference_numbers);

                        // Calculate SUMMARY (Only regular transactions that DON'T have matching cancellations)
                        foreach ($matchedRows as $row) {
                            // Only include regular transactions (not cancellations)
                            if (!isset($row['numeric_number']) || $row['numeric_number'] !== '*') {
                                // Check if this regular transaction has a matching cancellation
                                if (!in_array($row['reference_number'], $cancellation_reference_numbers)) {
                                    // This regular transaction doesn't have a matching cancellation, include it in SUMMARY
                                    $summaries['summary']['count']++;
                                    $summaries['summary']['principal'] += abs(floatval($row['amount_paid'] ?? 0));
                                    $summaries['summary']['charge_partner'] += abs(floatval($row['amount_charge_partner'] ?? 0));
                                    $summaries['summary']['charge_customer'] += abs(floatval($row['amount_charge_customer'] ?? 0));
                                }
                            }
                        }

                        // Calculate ADJUSTMENT (only cancellation transactions)
                        foreach ($matchedRows as $row) {
                            if (isset($row['numeric_number']) && $row['numeric_number'] === '*') {
                                $summaries['adjustment']['count']++;
                                $summaries['adjustment']['principal'] += abs(floatval($row['amount_paid'] ?? 0));
                                $summaries['adjustment']['charge_partner'] += abs(floatval($row['amount_charge_partner'] ?? 0));
                                $summaries['adjustment']['charge_customer'] += abs(floatval($row['amount_charge_customer'] ?? 0));
                            }
                        }

                        // Also check the separate cancellation array for ADJUSTMENT
                        if (!empty($cancellationRows)) {
                            foreach ($cancellationRows as $cancellationGroup) {
                                if (is_array($cancellationGroup)) {
                                    if (isset($cancellationGroup[0]) && is_array($cancellationGroup[0])) {
                                        foreach ($cancellationGroup as $rowArray) {
                                            foreach ($rowArray as $row) {
                                                if (isset($row['numeric_number']) && $row['numeric_number'] === '*') {
                                                    $summaries['adjustment']['count']++;
                                                    $summaries['adjustment']['principal'] += abs(floatval($row['amount_paid'] ?? 0));
                                                    $summaries['adjustment']['charge_partner'] += abs(floatval($row['amount_charge_partner'] ?? 0));
                                                    $summaries['adjustment']['charge_customer'] += abs(floatval($row['amount_charge_customer'] ?? 0));
                                                }
                                            }
                                        }
                                    } else {
                                        if (isset($cancellationGroup['numeric_number']) && $cancellationGroup['numeric_number'] === '*') {
                                            $summaries['adjustment']['count']++;
                                            $summaries['adjustment']['principal'] += abs(floatval($cancellationGroup['amount_paid'] ?? 0));
                                            $summaries['adjustment']['charge_partner'] += abs(floatval($cancellationGroup['amount_charge_partner'] ?? 0));
                                            $summaries['adjustment']['charge_customer'] += abs(floatval($cancellationGroup['amount_charge_customer'] ?? 0));
                                        }
                                    }
                                }
                            }
                        }

                        // Calculate NET as SUMMARY - ADJUSTMENT
                        $summaries['net']['count'] = $summaries['summary']['count'] - $summaries['adjustment']['count'];
                        $summaries['net']['principal'] = $summaries['summary']['principal'] - $summaries['adjustment']['principal'];
                        $summaries['net']['charge_partner'] = $summaries['summary']['charge_partner'] - $summaries['adjustment']['charge_partner'];
                        $summaries['net']['charge_customer'] = $summaries['summary']['charge_customer'] - $summaries['adjustment']['charge_customer'];

                        // Calculate totals and settlements for all categories
                        foreach ($summaries as $key => &$summary) {
                            $summary['total_charge'] = $summary['charge_partner'] + $summary['charge_customer'];
                            $summary['settlement'] = $summary['principal'] - $summary['charge_partner'] - $summary['charge_customer'];
                        }

                        return $summaries;
                    }

                    // Main logic flow
                    if (!empty($_SESSION['consolidated_data'])) {
                        error_log("Redirecting to consolidated validation result page");
                        echo '<script>window.location.href = "../error/ErrorResult.php";</script>';
                    } elseif (!empty($partner_GLCode_not_found_data)) {
                        error_log("Handling GL Code error");
                        
                        // Check if it's a specific partner or All partners
                        if ($partner !== 'All') {
                            // For specific partner, show SweetAlert modal
                            $partnerInfo = $partner_GLCode_not_found_data[0]; // Get first error for partner info
                            echo '<script>
                                document.addEventListener("DOMContentLoaded", function() {
                                    Swal.fire({
                                        icon: "error",
                                        title: "No GL Code Found",
                                        html: `
                                            <div class="text-center">
                                                <i class="fas fa-exclamation-triangle text-danger mb-3" style="font-size: 3rem;"></i>
                                                <h4 class="text-danger mb-3">Partner GL Code Missing</h4>
                                                <div class="alert alert-danger">
                                                    <strong>No GL Code for this Partner, Please contact your administrator to assign GL Codes before importing.</strong>
                                                </div>
                                                <div class="text-start mt-3">
                                                    <table class="table table-bordered">
                                                        <tr>
                                                            <td><strong>File Name:</strong></td>
                                                            <td>' . htmlspecialchars($file_name) . '</td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Uploaded Date:</strong></td>
                                                            <td>' . date('F d, Y') . '</td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Partner Name:</strong></td>
                                                            <td>' . htmlspecialchars($partner) . '</td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>Uploaded By:</strong></td>
                                                            <td>' . htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Unknown') . '</td>
                                                        </tr>
                                                        <tr>
                                                            <td><strong>File Type:</strong></td>
                                                            <td>' . htmlspecialchars($fileType) . '</td>
                                                        </tr>
                                                    </table>
                                                </div>
                                            </div>
                                        `,
                                        showConfirmButton: true,
                                        confirmButtonText: "OK",
                                        confirmButtonColor: "#dc3545",
                                        allowOutsideClick: false,
                                        allowEscapeKey: false
                                    }).then((result) => {
                                        if (result.isConfirmed) {
                                            window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                                        }
                                    });
                                });
                            </script>';
                        } else {
                            // For All partners, redirect to error display page
                            echo '<script>window.location.href = "../error/partnerGLCodesErrorDisplay.php";</script>';
                        }
                    } elseif (!empty($notFoundData)) {
                        error_log("Redirecting to branch ID error page");
                        echo '<script>window.location.href = "../error/branchIdErrorDisplay.php";</script>'; // DONE
                    } elseif (!empty($regionNotFoundData)) {
                        error_log("Redirecting to region error page");
                        // Redirect to region error display
                        echo '<script>window.location.href = "../error/regionNotFoundErrorDisplay.php";</script>';
                    } elseif (!empty($_SESSION['duplicate_data'])) {
                        error_log("Redirecting to duplicate error page");
                        // Redirect to duplicate error display
                        echo '<script>window.location.href = "../error/duplicateErrorDisplay.php";</script>';
                    } elseif (!empty($_SESSION['ready_to_override_data'])) {
                        error_log("Showing override confirmation");
                        // Show override confirmation page
                        echo '<script>
                            document.addEventListener("DOMContentLoaded", function() {
                                // Create a form to trigger the override confirmation
                                const form = document.createElement("form");
                                form.method = "POST";
                                form.style.display = "none";
                                
                                const input = document.createElement("input");
                                input.type = "hidden";
                                input.name = "override_comfirm";
                                input.value = "1";
                                
                                form.appendChild(input);
                                document.body.appendChild(form);
                                form.submit();
                            });
                        </script>';
                    } elseif (!empty($matchedData)) {
                        error_log("Showing summary display with " . count($matchedData) . " records");
                        // Calculate all summaries
                        $summaries = calculateTransactionSummary($matchedData, $cancellationData);

                        // Resolve display report date (extracted from file/session)
                        $displayReportDateRaw = normalizeReportDate($_SESSION['manual_report_date'] ?? ($_SESSION['extracted_report_date'] ?? null));
                        if (empty($displayReportDateRaw)) {
                            $firstMatched = $matchedData[0]['report_date'] ?? null;
                            $firstCancelled = $cancellationData[0]['report_date'] ?? null;
                            $displayReportDateRaw = normalizeReportDate($firstMatched ?? $firstCancelled);
                        }

                        // Get display variables
                        $displayData = [
                            'company' => htmlspecialchars(strval($partnerSelection[0]['companys_name'] ?? '')),
                            // 'company' => htmlspecialchars($_POST['company'] ?? ''),
                            // 'partnerId' => htmlspecialchars($partners_id ?? ''),
                            'partnerId' => htmlspecialchars(strval($partnerSelection[0]['partners_id'] ?? '')),
                            'partnerIdKPX' => htmlspecialchars(strval($partnerSelection[0]['partners_id_kpx'] ?? '')),
                            'GLCodes' => htmlspecialchars(strval($partnerSelection[0]['gl_code'] ?? '')),
                            'rowCount' => number_format($summaries['summary']['count']),
                            'sourceType' => htmlspecialchars(strval(($_POST['fileType'] ?? '') . " System")),
                            'reportDateRaw' => htmlspecialchars(strval($displayReportDateRaw ?? '')),
                            'reportDate' => htmlspecialchars(strval(!empty($displayReportDateRaw) ? date('F d, Y', strtotime($displayReportDateRaw)) : 'N/A')),
                            'transactionDate' => htmlspecialchars(strval(date('F d, Y')))
                        ];

                        // Define table rows data
                        $tableRows = [
                            ['label' => 'TOTAL COUNT', 'icon' => 'fas fa-calculator text-secondary'],
                            ['label' => 'TOTAL PRINCIPAL', 'icon' => 'fas fa-money-bill-wave text-success'],
                            ['label' => 'TOTAL CHARGE', 'icon' => 'fas fa-receipt text-danger'],
                            ['label' => 'CHARGE TO PARTNER', 'icon' => 'fas fa-building text-primary'],
                            ['label' => 'CHARGE TO CUSTOMER', 'icon' => 'fas fa-user text-info']
                        ];

                        echo '<div id="summary-section">
                            <div id="upload-success" class="container-fluid py-4" style="margin-top: 20px;">
                                <div class="text-center mb-4">
                                    <div class="card shadow-sm border-0 bg-light py-4">
                                        <h3 class="text-center fw-bold text-primary">Would you like to proceed inserting the data?</h3>
                                        <div class="card-body">
                                            <form method="post" id="confirmImportForm" class="d-inline">
                                                <input type="hidden" name="confirm_import" value="1">
                                                <input type="hidden" name="report_date" id="hiddenReportDate" value="' . $displayData['reportDateRaw'] . '">
                                                <button type="submit" class="btn btn-success btn-lg me-3 shadow-sm" id="confirmImportButton">
                                                    <i class="fas fa-check-circle me-2"></i>Confirm Import
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-danger btn-lg shadow-sm" onclick="confirmCancel()">
                                                <i class="fas fa-times-circle me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-4 gx-4">
                                    <!-- Import Details Card -->
                                    <div class="col-md-3">
                                        <div class="card shadow border-0 h-100">
                                            <div class="card-header bg-success text-white py-3">
                                                <h4 class="mb-0 text-center"><i class="fas fa-info-circle me-2"></i>Import Details</h4>
                                            </div>
                                            <div class="card-body">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-hover align-middle">
                                                        <thead>
                                                            <tr class="table-secondary">
                                                                <th>Property</th>
                                                                <th>Value</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <td><i class="fas fa-id-card text-primary me-2"></i>KP7 Partner ID</td>
                                                                <td class="fw-semibold">' . $displayData['partnerId'] . '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-id-card text-primary me-2"></i>KPX Partner ID</td>
                                                                <td class="fw-semibold">' . $displayData['partnerIdKPX'] . '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-id-card text-primary me-2"></i>GL Code</td>
                                                                <td class="fw-semibold">' . $displayData['GLCodes'] . '</td>
                                                            </tr>'?>
                                                            <?php 
                                                                if(!empty($displayData['subbillers_id'])) {
                                                                    echo '<tr>
                                                                        <td><i class="fas fa-id-card text-primary me-2"></i>Sub-Biller ID</td>
                                                                        <td class="fw-semibold">' . htmlspecialchars($displayData['subbillers_id']) . '</td>
                                                                    </tr>
                                                                    <tr>
                                                                        <td><i class="fas fa-id-card text-primary me-2"></i>Sub-Biller Name</td>
                                                                        <td class="fw-semibold">' . htmlspecialchars($displayData['subbillers_name']) . '</td>
                                                                    </tr>
                                                                    ';
                                                                }else {
                                                                    echo '<tr>
                                                                        <td><i class="fas fa-id-card text-primary me-2"></i>Sub-Biller ID</td>
                                                                        <td class="fw-semibold">N/A</td>
                                                                    </tr>
                                                                    <tr>
                                                                        <td><i class="fas fa-id-card text-primary me-2"></i>Sub-Biller Name</td>
                                                                        <td class="fw-semibold">N/A</td>
                                                                    </tr>';
                                                                }
                                                            ?>
                                                            <?php echo '<tr>
                                                                <td><i class="fas fa-building text-primary me-2"></i>Partner Name</td>
                                                                <td class="fw-semibold">'?>
                                                                <?php 
                                                                    if($partner !== 'All') {
                                                                        echo $displayData['company'];
                                                                    } else {
                                                                        echo 'Multiple Partners';
                                                                    }
                                                                ?>
                                                                <?php echo '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-list-ol text-primary me-2"></i>No. of Data Rows Uploaded</td>
                                                                <td class="fw-semibold">' . $displayData['rowCount'] . '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-file-import text-primary me-2"></i>Source</td>
                                                                <td class="fw-semibold">' . $displayData['sourceType'] . '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-calendar-day text-primary me-2"></i>Report Date</td>
                                                                <td class="fw-semibold" id="reportDateText">' . $displayData['reportDate'] . '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-calendar-alt text-primary me-2"></i>Uploaded Date</td>
                                                                <td class="fw-semibold">' . $displayData['transactionDate'] . '</td>
                                                            </tr>
                                                            <tr>
                                                                <td><i class="fas fa-calendar-alt text-primary me-2"></i>Uploaded By</td>
                                                                <td class="fw-semibold">'.($_SESSION['admin_name'] ?? $_SESSION['user_name']).'</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Transaction Summary Table -->
                                    <div class="col-md-9">
                                        <div class="card shadow border-0">
                                            <div class="card-header bg-danger text-white py-3">
                                                <h4 class="mb-0 text-center"><i class="fas fa-chart-line me-2"></i>Transaction Summary</h4>
                                            </div>
                                            <div class="card-body">
                                                <table class="table table-bordered table-hover align-middle">
                                                    <thead>
                                                        <tr class="bg-danger text-white text-center fw-bold">
                                                            <th class="text-center" style="width: 33%">SUMMARY</th>
                                                            <th class="text-center" style="width: 33%">CANCELLED TRANSACTIONS</th>
                                                            <th class="text-center" style="width: 33%">NET</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>';

                        // Generate dynamic table rows
                        foreach ($tableRows as $row) {
                            // Fix field mapping logic
                            $field = '';
                            switch ($row['label']) {
                                case 'TOTAL COUNT':
                                    $field = 'count';
                                    break;
                                case 'TOTAL PRINCIPAL':
                                    $field = 'principal';
                                    break;
                                case 'TOTAL CHARGE':
                                    $field = 'total_charge';
                                    break;
                                case 'CHARGE TO PARTNER':
                                    $field = 'charge_partner';
                                    break;
                                case 'CHARGE TO CUSTOMER':
                                    $field = 'charge_customer';
                                    break;
                                default:
                                    $field = 'count';
                            }
                            
                            echo '<tr>
                                <td class="border-end">
                                    <div class="row">
                                        <div class="col-6 fw-semibold"><i class="' . $row['icon'] . ' me-2"></i>' . $row['label'] . '</div>
                                        <div class="col-6 text-end fw-bold">' . ($row['label'] === 'TOTAL COUNT' ? number_format($summaries['summary'][$field]) : formatCurrency($summaries['summary'][$field])) . '</div>
                                    </div>
                                </td>
                                <td class="border-end">
                                    <div class="row">
                                        <div class="col-6 fw-semibold"><i class="' . $row['icon'] . ' me-2"></i>' . $row['label'] . '</div>
                                        <div class="col-6 text-end fw-bold">' . ($row['label'] === 'TOTAL COUNT' ? number_format($summaries['adjustment'][$field]) : formatCurrency($summaries['adjustment'][$field])) . '</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="row">
                                        <div class="col-6 fw-semibold"><i class="' . $row['icon'] . ' me-2"></i>' . $row['label'] . '</div>
                                        <div class="col-6 text-end fw-bold">' . ($row['label'] === 'TOTAL COUNT' ? number_format($summaries['net'][$field]) : formatCurrency($summaries['net'][$field])) . '</div>
                                    </div>
                                </td>
                            </tr>';
                        }

                        echo '                      </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                            document.addEventListener("DOMContentLoaded", function() {
                                var confirmForm = document.getElementById("confirmImportForm");
                                if (confirmForm) {
                                    confirmForm.addEventListener("submit", function() {
                                        var uploadSuccess = document.getElementById("upload-success");
                                        if (uploadSuccess) {
                                            uploadSuccess.style.display = "none";
                                        }
                                    });
                                }
                            });
                        </script>';
                        // echo '                  <tr class="table-secondary">
                        //                                     <td class="text-start fw-bold fs-5"><i class="fas fa-coins text-warning me-2"></i>TOTAL AMOUNT (PHP)</td>
                        //                                     <td class="text-end fw-bold fs-5"></td>
                        //                                     <td class="text-end fw-bold fs-5">' . formatCurrency($summaries['net']['settlement']) . '</td>
                        //                                 </tr>
                        //                             </tbody>
                        //                         </table>
                        //                     </div>
                        //                 </div>
                        //             </div>
                        //         </div>
                        //     </div>
                        // </div>
                        // <script>
                        //     // Hide the confirmation/summary section after clicking Confirm Import
                        //     document.addEventListener("DOMContentLoaded", function() {
                        //         var confirmForm = document.getElementById("confirmImportForm");
                        //         if (confirmForm) {
                        //             confirmForm.addEventListener("submit", function() {
                        //                 var uploadSuccess = document.getElementById("upload-success");
                        //                 if (uploadSuccess) {
                        //                     uploadSuccess.style.display = "none";
                        //                 }
                        //             });
                        //         }
                        //     });
                        // </script>';
                    } else {
                        error_log("No data found to process");
                        echo '<script>
                            Swal.fire({
                                icon: "warning",
                                title: "No Data Found",
                                text: "No valid data was found in the uploaded file.",
                                confirmButtonText: "OK"
                            }).then(() => {
                                window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                            });
                        </script>';
                        exit;
                    }
                }else{
                    echo '<script>
                            Swal.fire({
                                icon: "error",
                                title: "Invalid File Type",
                                text: "Please upload a valid Excel file.",
                                confirmButtonText: "OK"
                            }).then(() => {
                                window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                            });
                        </script>';
                }
            } else {?>
                <script>
                    Swal.fire({
                        icon: "error",
                        title: "Invalid File Type",
                        text: "Please upload a valid Excel file.",
                        confirmButtonText: "OK"
                    }).then(() => {
                        window.location.href = "../../dashboard/billspayment/import/billspay-transaction.php";
                    });
                </script>
    <?php 
            }
        }?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                if (typeof $ !== "undefined" && $.fn.select2) {
                    console.log("Initializing Select2 for partner dropdown");
                    $("#companyDropdown").select2({
                        placeholder: "Search or select a company...",
                        allowClear: true,
                        width: "100%",
                        minimumResultsForSearch: 0,
                        dropdownParent: $("#companyDropdown").parent()
                    });
                } else {
                    console.error("jQuery or Select2 library not loaded");
                }
            });
        </script>
        <script>
            // script.js or within <script> tags in <head> or before </body>
            (function(){
                var _uploadForm = document.getElementById('uploadForm');
                if (_uploadForm) {
                    _uploadForm.addEventListener('submit', function() {
                        // Show loading overlay when form is submitted
                        var _loading = document.getElementById('loading-overlay');
                        if (_loading) _loading.style.display = 'block';
                    });
                }

                // Loop through each element and set its display style to "block" (guarded)
                if (typeof elements !== 'undefined' && elements && elements.length) {
                    for (var i = 0; i < elements.length; i++) {
                        if (elements[i]) elements[i].style.display = "block";
                    }
                }
            })();

            $(document).ready(function() {
                $('#companyDropdown').select2({
                    placeholder: "Search or select a company...",
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#companyDropdown').parent(),
                    minimumResultsForSearch: 0, // Always show search box
                    searchInputPlaceholder: 'Type to search partners...',
                    language: {
                        noResults: function() {
                            return "No partner found with that name";
                        }
                    }
                });

                // Add change event handler for company dropdown
                $('#companyDropdown').on('change', function() {
                    var selectedValue = $(this).val();
                    var datePicker = $('#datePicker');
                    
                    // Always keep date picker enabled and required regardless of selection
                    datePicker.prop('disabled', false);
                    datePicker.prop('required', true);
                });

                // Form validation
                $('#uploadForm').on('submit', function(e) {
                    var selectedCompany = $('#companyDropdown').val();
                    var datePicker = $('#datePicker');
                    var fileType = $('#fileType').val();
                    
                    // Validate source file type is selected
                    if (!fileType) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Missing File Type',
                            text: 'Please select a source file type (KPX or KP7).',
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return false;
                    }
                    
                    // Validate date is selected regardless of partner selection
                    if (!datePicker.val()) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Missing Date',
                            text: 'Please select a date for the upload.',
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return false;
                    }
                    
                    // Show loading overlay
                    document.getElementById('loading-overlay'). style.display = 'block';
                });
            });
            // Add the JavaScript function for the confirmation
            function confirmCancel() {
                Swal.fire({
                    title: 'Are you sure?',
                    text: "Cancelling the process will discard all uploaded data",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, cancel it!',
                    cancelButtonText: 'No, continue'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = '../../dashboard/billspayment/import/billspay-transaction.php';
                    }
                });
            }
        </script>
    </body>
</html>