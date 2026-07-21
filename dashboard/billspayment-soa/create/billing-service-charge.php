<?php
// Connect to the database
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

// Start the session
session_start();
$current_user_email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

// Enable error logging and display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/php_errors.log');

// Resolve current user id and fetch signature blob (if any)
include '../../../templates/middleware.php';
$current_user_id = resolve_user_identifier();
if (empty($current_user_id)) { header('Location: ../../../login_form.php'); exit; }
if (!function_exists('has_any_permission') || !has_any_permission(['Billing Invoice Service Charge','Bills Payment'])) { header('Location: ../../home.php'); exit; }
$prepared_sig_blob = null;
$sig_blob = null;

// Resolve the display name/email early so both the AJAX handlers (save_invoice)
// and the HTML below can use it.
$display_name = 'GUEST';
$display_email = '';
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'admin') {
        $display_name = $_SESSION['admin_name'] ?? 'ADMIN';
        $display_email = $_SESSION['admin_email'] ?? '';
    } elseif ($_SESSION['user_type'] === 'user') {
        $display_name = $_SESSION['user_name'] ?? 'USER';
        $display_email = $_SESSION['user_email'] ?? '';
    }
}

// Fetch partners for dropdown - show all partners with soa_status = 'WITH SOA'
// Modified query to include partners even if partner_id_kpx is NULL or empty
$partners_query = "SELECT DISTINCT partner_id_kpx, partner_name, soa_status, abbreviation, series_number, 
                   partner_accName, partnerTin, address, businessStyle, serviceCharge, inc_exc, withheld 
                   FROM masterdata.partner_masterfile 
                   WHERE partner_name IS NOT NULL AND partner_name != '' 
                   AND TRIM(UPPER(soa_status)) = 'WITH SOA'
                   ORDER BY partner_name ASC";
$partners_result = mysqli_query($conn, $partners_query);

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] == 'get_partner_details') {
        try {
            $partner_id = mysqli_real_escape_string($conn, $_GET['partner_id']);
            
            // Debug: Log the partner ID
            error_log("Fetching partner details for ID: " . $partner_id);
            
            // First, check if partner exists and get all data
            $partner_query = "SELECT partner_id_kpx, partner_name, soa_status, abbreviation, series_number, 
                              partner_accName, partnerTin, address, businessStyle, serviceCharge, inc_exc, withheld 
                              FROM masterdata.partner_masterfile 
                              WHERE partner_id_kpx = '$partner_id'";
            
            error_log("Partner Query: " . $partner_query);
            
            $partner_result = mysqli_query($conn, $partner_query);
            
            if (!$partner_result) {
                error_log("MySQL Error: " . mysqli_error($conn));
                echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
                exit;
            }
            
            if (mysqli_num_rows($partner_result) == 0) {
                error_log("Partner not found: " . $partner_id);
                echo json_encode(['error' => 'Partner not found']);
                exit;
            }
            
            $partner_data = mysqli_fetch_assoc($partner_result);
            error_log("Partner Data: " . print_r($partner_data, true));
            
            // Get the partner name for use in other queries
            $partner_name = $partner_data['partner_name'];
            $abbreviation = trim($partner_data['abbreviation'] ?? '');
            
            // Generate control number based on existing reference numbers in soa_transaction
            $control_number = '';
            $next_series = 1;
            
            if (!empty($abbreviation)) {
                // Check if soa_transaction table exists
                $check_soa_table = "SHOW TABLES LIKE 'soa_transaction'";
                $check_soa_result = mysqli_query($conn, $check_soa_table);
                $soa_table_exists = mysqli_num_rows($check_soa_result) > 0;
                
                if ($soa_table_exists) {
                    // Check if reference_number column exists
                    $check_ref_column = "SHOW COLUMNS FROM mldb.soa_transaction LIKE 'reference_number'";
                    $check_ref_result = mysqli_query($conn, $check_ref_column);
                    $ref_column_exists = mysqli_num_rows($check_ref_result) > 0;
                    
                    if ($ref_column_exists) {
                        // Query to get the latest reference number for this abbreviation
                        $ref_query = "SELECT reference_number 
                                      FROM mldb.soa_transaction 
                                      WHERE reference_number LIKE '$abbreviation-%' 
                                      ORDER BY id DESC LIMIT 1";
                        
                        error_log("Reference Number Query: " . $ref_query);
                        
                        $ref_result = mysqli_query($conn, $ref_query);
                        
                        if ($ref_result && mysqli_num_rows($ref_result) > 0) {
                            $ref_data = mysqli_fetch_assoc($ref_result);
                            $latest_ref = $ref_data['reference_number'];
                            
                            // Extract the series number from the reference number
                            // Format: ABBREVIATION-NUMBER
                            $parts = explode('-', $latest_ref);
                            if (count($parts) == 2) {
                                $latest_series = intval($parts[1]);
                                $next_series = $latest_series + 1;
                            }
                            
                            error_log("Latest reference: $latest_ref, Next series: $next_series");
                        } else {
                            // No existing reference number found, start from 1
                            $next_series = 1;
                            error_log("No existing reference number found for abbreviation: $abbreviation. Starting from 1.");
                        }
                    } else {
                        error_log("Column 'reference_number' does not exist in soa_transaction table");
                        // Fallback: use series_number from partner_masterfile
                        $series_number = intval($partner_data['series_number'] ?? 0);
                        $next_series = $series_number + 1;
                    }
                } else {
                    error_log("Table 'soa_transaction' does not exist in database 'mldb'");
                    // Fallback: use series_number from partner_masterfile
                    $series_number = intval($partner_data['series_number'] ?? 0);
                    $next_series = $series_number + 1;
                }
                
                // Generate control number with the next series
                $control_number = $abbreviation . '-' . $next_series;
            } else {
                // Fallback: use partner_id if abbreviation is missing
                $series_number = intval($partner_data['series_number'] ?? 0);
                $control_number = 'SC-' . $partner_id . '-' . ($series_number + 1);
                error_log("Warning: Abbreviation missing for partner " . $partner_id . ". Using fallback control number: " . $control_number);
            }
            
            // Determine inc_exc value
            $inc_exc_value = trim($partner_data['inc_exc'] ?? '');
            if (empty($inc_exc_value) && strtoupper(trim($partner_data['soa_status'] ?? '')) == 'WITH SOA') {
                $inc_exc_value = 'NON-VAT';
            }
            
            // Check if partner is 434 for additional fields
            $is_partner_434 = ($partner_id == '434');
            $po_number = '';
            $po_year_error = '';
            
            if ($is_partner_434) {
                // Check if soa_transaction table exists
                $check_soa_table = "SHOW TABLES LIKE 'soa_transaction'";
                $check_soa_result = mysqli_query($conn, $check_soa_table);
                $soa_table_exists = mysqli_num_rows($check_soa_result) > 0;
                
                if ($soa_table_exists) {
                    // Check if the table has the partner_Name column
                    $check_partner_name_column = "SHOW COLUMNS FROM mldb.soa_transaction LIKE 'partner_Name'";
                    $check_partner_name_result = mysqli_query($conn, $check_partner_name_column);
                    $partner_name_column_exists = mysqli_num_rows($check_partner_name_result) > 0;
                    
                    if (!$partner_name_column_exists) {
                        error_log("Column 'partner_Name' does not exist in soa_transaction table");
                        $po_number = '';
                    } else {
                        // Get PO number for partner 434 using partner_name
                        $po_query = "SELECT po_number, from_date 
                                     FROM mldb.soa_transaction 
                                     WHERE partner_Name = '$partner_name' 
                                     ORDER BY id DESC LIMIT 1";
                        
                        error_log("PO Query: " . $po_query);
                        
                        $po_result = mysqli_query($conn, $po_query);
                        if ($po_result && $po_data = mysqli_fetch_assoc($po_result)) {
                            $po_number = $po_data['po_number'] ?? '';
                            if (!empty($po_data['from_date'])) {
                                $po_year = date('Y', strtotime($po_data['from_date']));
                                $current_year = date('Y');
                                if ($po_year < $current_year) {
                                    $po_year_error = 'Previous year PO not allowed. Please input updated/this year`s PO.';
                                }
                            }
                        } else {
                            error_log("PO Query Error: " . mysqli_error($conn));
                        }
                    }
                } else {
                    error_log("Table 'soa_transaction' does not exist in database 'mldb'");
                }
            }
            
            $response = array_merge($partner_data, [
                'control_number' => $control_number,
                'next_series' => $next_series,
                'inc_exc_display' => $inc_exc_value,
                'is_partner_434' => $is_partner_434,
                'po_number' => $po_number,
                'po_year_error' => $po_year_error,
                'debug_info' => [
                    'partner_id' => $partner_id,
                    'partner_name' => $partner_name,
                    'abbreviation' => $abbreviation,
                    'next_series' => $next_series,
                    'soa_table_exists' => isset($soa_table_exists) ? $soa_table_exists : false,
                    'partner_name_column_exists' => isset($partner_name_column_exists) ? $partner_name_column_exists : false,
                    'ref_column_exists' => isset($ref_column_exists) ? $ref_column_exists : false
                ]
            ]);
            
            error_log("Final Response: " . json_encode($response));
            
            echo json_encode($response);
            exit;
            
        } catch (Exception $e) {
            error_log("Exception caught: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            echo json_encode(['error' => 'An error occurred: ' . $e->getMessage()]);
            exit;
        }
    }
    
    // NEW: Fetch partner details by name (for partners without ID)
    if ($_GET['action'] == 'get_partner_by_name') {
        try {
            $partner_name = mysqli_real_escape_string($conn, $_GET['partner_name'] ?? '');
            
            if (empty($partner_name)) {
                echo json_encode(['error' => 'Partner name is required']);
                exit;
            }
            
            error_log("Fetching partner by name: " . $partner_name);
            
            // Query partner_masterfile by name
            $partner_query = "SELECT partner_id_kpx, partner_name, soa_status, abbreviation, series_number, 
                              partner_accName, partnerTin, address, businessStyle, serviceCharge, inc_exc, withheld 
                              FROM masterdata.partner_masterfile 
                              WHERE partner_name = '$partner_name'";
            
            error_log("Partner Query (by name): " . $partner_query);
            
            $partner_result = mysqli_query($conn, $partner_query);
            
            if (!$partner_result) {
                error_log("MySQL Error (by name): " . mysqli_error($conn));
                echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
                exit;
            }
            
            if (mysqli_num_rows($partner_result) == 0) {
                error_log("Partner not found by name: " . $partner_name);
                echo json_encode(['error' => 'Partner not found']);
                exit;
            }
            
            $partner_data = mysqli_fetch_assoc($partner_result);
            error_log("Partner Data (by name): " . print_r($partner_data, true));
            
            $abbreviation = trim($partner_data['abbreviation'] ?? '');
            
            // Generate control number based on existing records in soa_transaction
            $control_number = '';
            $next_series = 1;
            
            if (!empty($abbreviation)) {
                // Check if soa_transaction table exists
                $check_soa_table = "SHOW TABLES LIKE 'soa_transaction'";
                $check_soa_result = mysqli_query($conn, $check_soa_table);
                $soa_table_exists = mysqli_num_rows($check_soa_result) > 0;
                
                if ($soa_table_exists) {
                    // Check if reference_number column exists
                    $check_ref_column = "SHOW COLUMNS FROM mldb.soa_transaction LIKE 'reference_number'";
                    $check_ref_result = mysqli_query($conn, $check_ref_column);
                    $ref_column_exists = mysqli_num_rows($check_ref_result) > 0;
                    
                    if ($ref_column_exists) {
                        // Query to get the latest reference number for this abbreviation
                        // This will work regardless of whether partner has ID or not
                        $ref_query = "SELECT reference_number 
                                      FROM mldb.soa_transaction 
                                      WHERE reference_number LIKE '$abbreviation-%' 
                                      ORDER BY id DESC LIMIT 1";
                        
                        error_log("Reference Number Query (by name): " . $ref_query);
                        
                        $ref_result = mysqli_query($conn, $ref_query);
                        
                        if ($ref_result && mysqli_num_rows($ref_result) > 0) {
                            $ref_data = mysqli_fetch_assoc($ref_result);
                            $latest_ref = $ref_data['reference_number'];
                            
                            // Extract the series number from the reference number
                            // Format: ABBREVIATION-NUMBER
                            $parts = explode('-', $latest_ref);
                            if (count($parts) == 2) {
                                $latest_series = intval($parts[1]);
                                $next_series = $latest_series + 1;
                            }
                            
                            error_log("Latest reference: $latest_ref, Next series: $next_series");
                        } else {
                            // No existing reference number found, start from 1
                            $next_series = 1;
                            error_log("No existing reference number found for abbreviation: $abbreviation. Starting from 1.");
                        }
                    } else {
                        error_log("Column 'reference_number' does not exist in soa_transaction table");
                        // Fallback: use series_number from partner_masterfile
                        $series_number = intval($partner_data['series_number'] ?? 0);
                        $next_series = $series_number + 1;
                    }
                } else {
                    error_log("Table 'soa_transaction' does not exist in database 'mldb'");
                    // Fallback: use series_number from partner_masterfile
                    $series_number = intval($partner_data['series_number'] ?? 0);
                    $next_series = $series_number + 1;
                }
                
                // Generate control number with the next series
                $control_number = $abbreviation . '-' . $next_series;
            } else {
                // No abbreviation - use fallback with partner_name
                $control_number = 'SC-' . md5($partner_name) . '-1';
                error_log("Warning: Abbreviation missing for partner $partner_name. Using fallback control number: " . $control_number);
            }
            
            // Determine inc_exc value
            $inc_exc_value = trim($partner_data['inc_exc'] ?? '');
            if (empty($inc_exc_value) && strtoupper(trim($partner_data['soa_status'] ?? '')) == 'WITH SOA') {
                $inc_exc_value = 'NON-VAT';
            }
            
            // Partner without ID cannot be 434 (hardcoded check)
            $is_partner_434 = false;
            $po_number = '';
            $po_year_error = '';
            
            $response = array_merge($partner_data, [
                'control_number' => $control_number,
                'next_series' => $next_series,
                'inc_exc_display' => $inc_exc_value,
                'is_partner_434' => $is_partner_434,
                'po_number' => $po_number,
                'po_year_error' => $po_year_error,
                'has_partner_id' => false,
                'debug_info' => [
                    'partner_name' => $partner_name,
                    'abbreviation' => $abbreviation,
                    'next_series' => $next_series,
                    'fetched_by' => 'name'
                ]
            ]);
            
            error_log("Final Response (by name): " . json_encode($response));
            
            echo json_encode($response);
            exit;
            
        } catch (Exception $e) {
            error_log("Exception in get_partner_by_name: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            echo json_encode(['error' => 'An error occurred: ' . $e->getMessage()]);
            exit;
        }
    }
    
    // NEW: Check if SOA already exists for partner and date range
if ($_GET['action'] == 'check_existing_soa') {
    try {
        $partner_id   = mysqli_real_escape_string($conn, $_GET['partner_id'] ?? '');
        $partner_name = mysqli_real_escape_string($conn, $_GET['partner_name'] ?? '');
        $from_date    = mysqli_real_escape_string($conn, $_GET['from_date'] ?? '');
        $to_date      = mysqli_real_escape_string($conn, $_GET['to_date'] ?? '');

        error_log("Checking existing SOA - Partner ID: $partner_id, Name: $partner_name, From: $from_date, To: $to_date");

        // Get abbreviation (try ID first, fallback to name)
        $abbreviation = '';
        if (!empty($partner_id) && !str_starts_with($partner_id, 'no-id-')) {
            $abbrev_query = "SELECT abbreviation FROM masterdata.partner_masterfile WHERE partner_id_kpx = '$partner_id'";
            $abbrev_result = mysqli_query($conn, $abbrev_query);
            if ($abbrev_result && mysqli_num_rows($abbrev_result) > 0) {
                $abbrev_data = mysqli_fetch_assoc($abbrev_result);
                $abbreviation = trim($abbrev_data['abbreviation'] ?? '');
            }
        }
        if (empty($abbreviation) && !empty($partner_name)) {
            $abbrev_query = "SELECT abbreviation FROM masterdata.partner_masterfile WHERE partner_name = '$partner_name'";
            $abbrev_result = mysqli_query($conn, $abbrev_query);
            if ($abbrev_result && mysqli_num_rows($abbrev_result) > 0) {
                $abbrev_data = mysqli_fetch_assoc($abbrev_result);
                $abbreviation = trim($abbrev_data['abbreviation'] ?? '');
            }
        }

        if (empty($abbreviation)) {
            echo json_encode(['exists' => false, 'error' => 'No abbreviation found for this partner']);
            exit;
        }

        // Safety checks for table/columns
        $check_soa_table = "SHOW TABLES LIKE 'soa_transaction'";
        $soa_table_exists = mysqli_num_rows(mysqli_query($conn, $check_soa_table)) > 0;

        if (!$soa_table_exists) {
            echo json_encode(['exists' => false]);
            exit;
        }

        $ref_column_exists = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM mldb.soa_transaction LIKE 'reference_number'")) > 0;
        $from_column_exists = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM mldb.soa_transaction LIKE 'from_date'")) > 0;
        $to_column_exists = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM mldb.soa_transaction LIKE 'to_date'")) > 0;

        if (!$ref_column_exists || !$from_column_exists || !$to_column_exists) {
            echo json_encode(['exists' => false]);
            exit;
        }

        // Overlapping date range check (same as automated version)
        $check_query = "SELECT id, reference_number, from_date, to_date, partner_Name 
                        FROM mldb.soa_transaction 
                        WHERE reference_number LIKE '$abbreviation-%' 
                        AND (
                            (from_date <= '$to_date' AND to_date >= '$from_date')
                            OR (from_date >= '$from_date' AND to_date <= '$to_date')
                            OR (from_date <= '$from_date' AND to_date >= '$to_date')
                        )
                        ORDER BY id DESC LIMIT 1";

        error_log("Check existing SOA query: " . $check_query);

        $check_result = mysqli_query($conn, $check_query);

        if (!$check_result) {
            error_log("Check SOA Query Error: " . mysqli_error($conn));
            echo json_encode(['exists' => false, 'error' => 'Database error']);
            exit;
        }

        if (mysqli_num_rows($check_result) > 0) {
            $existing = mysqli_fetch_assoc($check_result);
            echo json_encode([
                'exists' => true,
                'reference_number' => $existing['reference_number'],
                'from_date' => $existing['from_date'],
                'to_date' => $existing['to_date'],
                'partner_Name' => $existing['partner_Name'] ?? ''
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
        exit;

    } catch (Exception $e) {
        error_log("Exception in check_existing_soa: " . $e->getMessage());
        echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
    
    if ($_GET['action'] == 'generate_invoice') {
        // Check if SOA already exists before generating
        $partner_id = mysqli_real_escape_string($conn, $_GET['partner_id'] ?? '');
        $from_date = mysqli_real_escape_string($conn, $_GET['from_date'] ?? '');
        $to_date = mysqli_real_escape_string($conn, $_GET['to_date'] ?? '');
        
        if (empty($partner_id) || empty($from_date) || empty($to_date)) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit;
        }
        
        // Get partner abbreviation
        $abbrev_query = "SELECT abbreviation FROM masterdata.partner_masterfile WHERE partner_id_kpx = '$partner_id'";
        $abbrev_result = mysqli_query($conn, $abbrev_query);
        $abbrev_data = mysqli_fetch_assoc($abbrev_result);
        $abbreviation = trim($abbrev_data['abbreviation'] ?? '');
        
        if (empty($abbreviation)) {
            echo json_encode(['success' => false, 'message' => 'No abbreviation found for this partner']);
            exit;
        }
        
        // Check for existing SOA
        $check_query = "SELECT id, reference_number, from_date, to_date 
                        FROM mldb.soa_transaction 
                        WHERE reference_number LIKE '$abbreviation-%' 
                        AND (
                            (from_date <= '$to_date' AND to_date >= '$from_date')
                            OR (from_date >= '$from_date' AND to_date <= '$to_date')
                            OR (from_date <= '$from_date' AND to_date >= '$to_date')
                        )
                        LIMIT 1";
        
        $check_result = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check_result) > 0) {
            $existing = mysqli_fetch_assoc($check_result);
            echo json_encode([
                'success' => false, 
                'exists' => true,
                'message' => 'SOA already exists for this partner and date range.',
                'reference_number' => $existing['reference_number'],
                'existing_from' => $existing['from_date'],
                'existing_to' => $existing['to_date']
            ]);
            exit;
        }
        
        // DISABLED - Development mode (only reaches here if no existing SOA)
        echo json_encode(['success' => false, 'message' => 'SOA generation is currently disabled (Development Mode)']);
        exit;
    }
}

// Handle Save Invoice (POST) - persists the invoice to mldb.soa_transaction
// and advances masterdata.partner_masterfile.series_number for the partner.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_invoice') {
    header('Content-Type: application/json');

    try {
        // ---- Collect + sanitize incoming fields ----
        $partner_id       = mysqli_real_escape_string($conn, $_POST['partner_id'] ?? '');
        $invoice_date_raw = $_POST['invoice_date'] ?? '';
        $control_number   = mysqli_real_escape_string($conn, $_POST['control_number'] ?? '');
        $partner_acc_name = mysqli_real_escape_string($conn, $_POST['partner_acc_name'] ?? '');
        $billing_period   = null; // not populated by this form
        $partner_tin      = mysqli_real_escape_string($conn, $_POST['partner_tin'] ?? '');
        $address          = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $business_style   = mysqli_real_escape_string($conn, $_POST['business_style'] ?? '');
        $service_charge   = mysqli_real_escape_string($conn, $_POST['service_charge'] ?? '');
        $from_date        = mysqli_real_escape_string($conn, $_POST['from_date'] ?? '');
        $to_date          = mysqli_real_escape_string($conn, $_POST['to_date'] ?? '');
        $po_number        = mysqli_real_escape_string($conn, $_POST['po_number'] ?? '');
        $transaction_count = intval($_POST['transaction_count'] ?? 0);
        $amount           = floatval($_POST['amount'] ?? 0);
        // add_amount: computed total (500 * number of days), no decimals, no peso sign
        $add_amount       = mysqli_real_escape_string($conn, $_POST['add_amount'] ?? '');
        // amount_add: the flat 500 rate for partner 434, no decimals ('' when not applicable)
        $amount_add       = mysqli_real_escape_string($conn, $_POST['amount_add'] ?? '');
        // numberOf_days: raw days entered, empty when nothing was entered
        $number_of_days   = mysqli_real_escape_string($conn, $_POST['number_of_days'] ?? '');
        // formula / formula_withheld / formulaInc_Exc columns:
        //   formula          <- inc_exc (Inclusive/Exclusive/Non-VAT)
        //   formula_withheld <- withheld (Yes/No)
        //   formulaInc_Exc   <- the human-readable VAT/withholding calculation text
        $formula          = mysqli_real_escape_string($conn, $_POST['formula'] ?? '');
        $formula_withheld = mysqli_real_escape_string($conn, $_POST['formula_withheld'] ?? '');
        $formula_inc_exc  = mysqli_real_escape_string($conn, $_POST['formula_calc_text'] ?? '');
        $vat_amount       = mysqli_real_escape_string($conn, $_POST['vat_amount'] ?? '');
        $net_of_vat       = mysqli_real_escape_string($conn, $_POST['net_of_vat'] ?? '');
        $withholding_tax  = mysqli_real_escape_string($conn, $_POST['withholding_tax'] ?? '');
        $total_amount_due = mysqli_real_escape_string($conn, $_POST['total_amount_due'] ?? '');
        $net_amount_due   = mysqli_real_escape_string($conn, $_POST['net_amount_due'] ?? '');

        if (empty($partner_id) || empty($control_number) || empty($from_date) || empty($to_date) || empty($invoice_date_raw)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields for saving the invoice.']);
            exit;
        }

        if (!strtotime($invoice_date_raw) || !strtotime($from_date) || !strtotime($to_date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
            exit;
        }

        $invoice_date = date('Y-m-d', strtotime($invoice_date_raw));
        $prepared_date_signature = date('m-d-Y', strtotime($invoice_date_raw));

        // Server-side identity fields - never trust client-supplied name/id
        $prepared_by = $display_name;
        $prepared_signature = mysqli_real_escape_string($conn, $_SESSION['id_number'] ?? '');
        $status = 'Prepared';

        // ---- Re-derive the series number from the control number ----
        // e.g. control_number "ANECO-40" -> series part 40 -> series_number to store is 41
        $series_part = 0;
        $cn_parts = explode('-', $control_number);
        if (count($cn_parts) >= 2) {
            $series_part = intval(end($cn_parts));
        }
        $next_series_to_store = $series_part + 1;

        // ---- Guard against a duplicate/overlapping SOA slipping through a race condition ----
        $dup_check_query = "SELECT id FROM mldb.soa_transaction 
                             WHERE reference_number = '$control_number' 
                             LIMIT 1";
        $dup_check_result = mysqli_query($conn, $dup_check_query);
        if ($dup_check_result && mysqli_num_rows($dup_check_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'This control number has already been saved. Please refresh and try again.']);
            exit;
        }

        // ---- Run the insert + series_number update as one transaction ----
        mysqli_begin_transaction($conn);

        $insert_query = "INSERT INTO mldb.soa_transaction (
                            `date`, reference_number, partner_Name, billing_period, partner_Tin, address, 
                            business_style, service_charge, from_date, to_date, po_number, 
                            number_of_transactions, amount, add_amount, amount_add, numberOf_days, 
                            formula, formula_withheld, formulaInc_Exc, vat_amount, net_of_vat, 
                            withholding_tax, totalAmountDue, net_amount_due, prepared_by, 
                            preparedDate_signature, prepared_signature, status
                         ) VALUES (
                            '$invoice_date', '$control_number', '$partner_acc_name', " . ($billing_period === null ? "NULL" : "'$billing_period'") . ", '$partner_tin', '$address',
                            '$business_style', '$service_charge', '$from_date', '$to_date', '$po_number',
                            $transaction_count, $amount, '$add_amount', '$amount_add', '$number_of_days',
                            '$formula', '$formula_withheld', '$formula_inc_exc', '$vat_amount', '$net_of_vat',
                            '$withholding_tax', '$total_amount_due', '$net_amount_due', '" . mysqli_real_escape_string($conn, $prepared_by) . "',
                            '$prepared_date_signature', '$prepared_signature', '$status'
                         )";

        if (!mysqli_query($conn, $insert_query)) {
            $insert_error = mysqli_error($conn);
            error_log("Save Invoice - Insert Error: " . $insert_error . " | Query: " . $insert_query);
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'message' => 'Failed to save invoice: ' . $insert_error]);
            exit;
        }

        $new_id = mysqli_insert_id($conn);

        // ---- Update series number - handle both ID-based and name-based partners ----
        // Check if the partner_id is in 'no-id-' format (meaning no partner_id_kpx)
        if (str_starts_with($partner_id, 'no-id-')) {
            // For partners without ID, update by partner_name
            // First, get the partner_name from the partner_acc_name field
            $partner_name_clean = mysqli_real_escape_string($conn, $partner_acc_name);
            $update_series_query = "UPDATE masterdata.partner_masterfile 
                                     SET series_number = $next_series_to_store 
                                     WHERE partner_accName = '$partner_name_clean'";
            
            error_log("Save Invoice - Series Update by Name: " . $update_series_query);
            
            if (!mysqli_query($conn, $update_series_query)) {
                $series_error = mysqli_error($conn);
                error_log("Save Invoice - Series Update Error (by name): " . $series_error);
                // Don't rollback for name-based updates, just log the error
                // The invoice is already saved
            }
        } else {
            // Normal update by ID
            $update_series_query = "UPDATE masterdata.partner_masterfile 
                                     SET series_number = $next_series_to_store 
                                     WHERE partner_id_kpx = '$partner_id'";
            
            error_log("Save Invoice - Series Update Query: " . $update_series_query);
            
            if (!mysqli_query($conn, $update_series_query)) {
                $series_error = mysqli_error($conn);
                error_log("Save Invoice - Series Update Error: " . $series_error);
                mysqli_rollback($conn);
                echo json_encode(['success' => false, 'message' => 'Failed to update series number: ' . $series_error]);
                exit;
            }
        }

        mysqli_commit($conn);

        error_log("Save Invoice - Saved successfully. ID: $new_id, Control Number: $control_number, New series_number: $next_series_to_store");

        echo json_encode([
            'success' => true,
            'message' => 'Invoice saved successfully.',
            'id' => $new_id,
            'reference_number' => $control_number,
            'series_number' => $next_series_to_store
        ]);
        exit;

    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Save Invoice - Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Billing (Manual) | <?php if($_SESSION['user_type'] === 'admin' || $_SESSION['user_type'] === 'user') echo ucfirst($_SESSION['user_type']); else echo "Guest";?></title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link rel="icon" href="../../../images/MLW logo.png" type="image/png">
    <link rel="stylesheet" href="css/bi_sc_auto.css?v=<?= time(); ?>">
    <!-- Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    
    <style>
        .btn-success.disabled-btn {
            background-color: #95a5a6 !important;
            cursor: not-allowed !important;
            opacity: 0.7;
        }
        .soa-exists-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: none;
        }
        .soa-exists-warning i {
            margin-right: 10px;
        }
        /* Style for partners without partner_id_kpx in dropdown */
        .partner-no-id {
            color: #666;
            font-style: italic;
        }
        .partner-no-id .no-id-badge {
            color: #e67e22;
            font-size: 11px;
            background: #fef9e7;
            padding: 1px 6px;
            border-radius: 3px;
            margin-left: 5px;
            border: 1px solid #f39c12;
        }
        .select2-results__option .no-id-badge {
            color: #e67e22;
            font-size: 11px;
            background: #fef9e7;
            padding: 1px 6px;
            border-radius: 3px;
            margin-left: 5px;
            border: 1px solid #f39c12;
        }
        .select2-results__option .with-id-badge {
            color: #27ae60;
            font-size: 11px;
            background: #eafaf1;
            padding: 1px 6px;
            border-radius: 3px;
            margin-left: 5px;
            border: 1px solid #27ae60;
        }
        
        /* Manual input styles */
        .manual-input-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
        }
        .manual-input-section .section-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        .manual-input-section .form-group {
            margin-bottom: 12px;
        }
        .manual-input-section .form-group label {
            font-weight: 500;
            color: #34495e;
        }
        .manual-input-section .form-group input {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 8px 12px;
            width: 100%;
            transition: border-color 0.3s;
        }
        .manual-input-section .form-group input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        .manual-input-section .form-group input.readonly-field {
            background-color: #e9ecef;
        }
        .manual-badge {
            background: #ff6b6b;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        
      
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../../../templates/header_ui.php'; ?>
        <?php include '../../../templates/sidebar.php'; ?>
        
        <div id="loading-overlay">
            <div class="loading-spinner"></div>
        </div>
        
        <div class="bp-section-header" role="region" aria-label="Page title">
            <div class="bp-section-title">
                <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                <div>
                    <h2>Billing Invoice - Service Charge <span class="manual-badge">MANUAL</span></h2>
                    <p class="bp-section-sub">Manual billing Invoice generation - Enter transaction details manually</p>
                </div>
            </div>
        </div>
        
        <div class="invoice-container">
            <div class="invoice-header">
                <div class="invoice-title">
                    <i class="fa-solid fa-plus-circle" style="color: #3498db;"></i>
                    Create New Invoice (Manual)
                </div>
                <div>
                    <span class="badge" style="background: #ff6b6b; color: white; padding: 8px 15px; border-radius: 20px;">
                        <i class="fa-regular fa-calendar"></i> <?php echo date('F d, Y'); ?>
                    </span>
                </div>
            </div>
            
            <div id="alert-container"></div>
            
            <!-- SOA Exists Warning -->
            <div class="soa-exists-warning" id="soaExistsWarning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span id="soaExistsMessage">SOA already exists for this partner and date range.</span>
            </div>
            
            <!-- Date Restriction Info -->
            <div class="date-restriction-info" id="dateRestrictionInfo">
                <div class="info-text">
                    <i class="fa-solid fa-info-circle"></i> 
                    <span id="dateRestrictionMessage">Please select a partner to see date restrictions.</span>
                </div>
            </div>
            
            <form id="invoiceForm" class="invoice-form">

            <!-- Transaction Date From -->
                <div class="form-group">
                    <label for="fromDate"><i class="fa-solid fa-calendar-day"></i> Transaction Date From <span style="color: red;">*</span></label>
                    <input type="date" id="fromDate" name="from_date">
                </div>
                
                <!-- Transaction Date To -->
                <div class="form-group">
                    <label for="toDate"><i class="fa-solid fa-calendar-day"></i> Transaction Date To <span style="color: red;">*</span></label>
                    <input type="date" id="toDate" name="to_date">
                </div>


                <!-- Partner Selection -->
                <div class="form-group">
                    <label for="partnerSelect"><i class="fa-solid fa-handshake-angle"></i> Partner <span style="color: red;">*</span></label>
                    <select id="partnerSelect" name="partner_id" style="width: 100%;" required>
                        <option value="">Search by Partner ID, Name, or Account Name...</option>
                        <?php 
                            $partners_data = [];
                            while($partner = mysqli_fetch_assoc($partners_result)): 
                                $partners_data[] = $partner;
                                $partner_id_kpx = trim($partner['partner_id_kpx'] ?? '');
                                $partner_name = htmlspecialchars($partner['partner_name'] ?? '');
                                $partner_accName = htmlspecialchars($partner['partner_accName'] ?? '');
                                
                                // Build the display text based on whether partner_id_kpx exists
                                if (!empty($partner_id_kpx)) {
                                    $display_text = $partner_id_kpx . ' - ' . $partner_name . ' - ' . $partner_accName;
                                    $has_id = true;
                                } else {
                                    $display_text = $partner_name . ' - ' . $partner_accName;
                                    $has_id = false;
                                }
                                
                                // Use a data attribute to store whether this partner has an ID
                                $data_has_id = $has_id ? 'true' : 'false';
                            ?>
                                <option value="<?php echo htmlspecialchars($partner_id_kpx ?: 'no-id-' . md5($partner_name)); ?>"
                                        data-soa-status="<?php echo htmlspecialchars(strtoupper(trim($partner['soa_status'] ?? ''))); ?>"
                                        data-partner-name="<?php echo htmlspecialchars($partner['partner_name']); ?>"
                                        data-partner-accname="<?php echo htmlspecialchars($partner['partner_accName'] ?? ''); ?>"
                                        data-service-charge="<?php echo htmlspecialchars($partner['serviceCharge'] ?? ''); ?>"
                                        data-abbreviation="<?php echo htmlspecialchars($partner['abbreviation'] ?? ''); ?>"
                                        data-has-id="<?php echo $data_has_id; ?>"
                                        data-partner-id-kpx="<?php echo htmlspecialchars($partner_id_kpx); ?>">
                                    <?php echo $display_text; ?>
                                </option>
                            <?php endwhile; ?>
                    </select>
                </div>
                
                <!-- Control Number -->
                <div class="form-group">
                    <label for="controlNumber"><i class="fa-solid fa-hashtag"></i> Control Number</label>
                    <input type="text" id="controlNumber" readonly placeholder="Select partner to generate">
                </div>
                
                <!-- Invoice Date -->
                <div class="form-group">
                    <label for="invoiceDate"><i class="fa-solid fa-calendar-check"></i> Invoice Date</label>
                    <input type="date" id="invoiceDate" value="<?php echo date('Y-m-d'); ?>" readonly>
                </div>
                
                <!-- Partner Account Name -->
                <div class="form-group">
                    <label for="partnerAccName"><i class="fa-solid fa-address-card"></i> Partner Account Name</label>
                    <input type="text" id="partnerAccName" readonly placeholder="Auto-fetched based on partner.">
                </div>
                
                <!-- Partner TIN -->
                <div class="form-group">
                    <label for="partnerTin"><i class="fa-solid fa-credit-card"></i> Partner TIN</label>
                    <input type="text" id="partnerTin" readonly placeholder="Auto-fetched based on partner.">
                </div>

                <!-- Partner Abbreviation -->
                <div class="form-group">
                    <label for="partnerAbbreviation"><i class="fa-solid fa-shortcode"></i><i class="fa-solid fa-receipt"></i> Abbreviation</label>
                    <input type="text" id="partnerAbbreviation" readonly placeholder="Auto-fetched based on partner.">
                </div>
                
                <!-- Address -->
                <div class="form-group full-width">
                    <label for="address"><i class="fa-solid fa-location-dot"></i> Address</label>
                    <textarea id="address" readonly rows="2" placeholder="Auto-fetched based on partner."></textarea>
                </div>
                
                <!-- Business Style -->
                <div class="form-group">
                    <label for="businessStyle"><i class="fa-brands fa-hubspot"></i> Business Style</label>
                    <input type="text" id="businessStyle" readonly placeholder="Auto-fetched based on partner.">
                </div>
                
                <!-- Service Charge -->
                <div class="form-group">
                    <label for="serviceCharge"><i class="fa-solid fa-business-time"></i> Service Charge</label>
                    <input type="text" id="serviceCharge" readonly placeholder="Auto-fetched based on partner.">
                </div>
                
                <!-- VAT (VAT (Inclusive / Exclusive)) -->
                <div class="form-group">
                    <label for="incExc"><i class="fa-solid fa-circle-notch"></i> VAT (Inclusive / Exclusive)</label>
                    <input type="text" id="incExc" readonly placeholder="Auto-fetched based on partner.">
                </div>
                
                <!-- Withholding Tax -->
                <div class="form-group">
                    <label for="withholdingTax"><i class="fa-solid fa-percent"></i> Withholding Tax</label>
                    <input type="text" id="withholdingTax" readonly placeholder="Auto-fetched based on partner.">
                </div>
                
                <!-- ============================================= -->
                <!-- MANUAL INPUT SECTION - User enters values here -->
                <!-- ============================================= -->
                <div class="manual-input-section">
                    <div class="section-title">
                        <i class="fa-solid fa-pen-to-square"></i> Manual Transaction Details
                        <span style="font-size: 12px; color: #e74c3c; font-weight: normal; margin-left: 10px;">(Enter values manually)</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="numberOfTransactions"><i class="fa-solid fa-list-ol"></i> Number of Transactions <span style="color: red;">*</span></label>
                        <input type="number" id="numberOfTransactions" name="number_of_transactions" min="0" step="1" placeholder="Enter number of transactions">
                    </div>
                    
                    <div class="form-group">
                        <label for="totalPrincipal"><i class="fa-solid fa-peso-sign"></i> Total Principal <span style="color: red;">*</span></label>
                        <input type="number" id="totalPrincipal" name="total_principal" min="0" step="0.01" placeholder="Enter total principal amount">
                    </div>
                    
                    <div class="form-group">
                        <label for="serviceChargeAmount"><i class="fa-solid fa-money-bill-transfer"></i> Service Charge Amount <span style="color: red;">*</span></label>
                        <input type="number" id="serviceChargeAmount" name="service_charge_amount" min="0" step="0.01" placeholder="Enter service charge amount">
                    </div>
                    
                    <!-- Additional Fields for Partner 434 -->
                    <div class="additional-fields" id="additionalFields" style="display: none;">
                        <div style="border-top: 1px dashed #dee2e6; margin: 15px 0; padding-top: 15px;">
                            <h5 style="color: #2c3e50; margin-bottom: 10px;">
                                <i class="fa-solid fa-plus-circle"></i> For JT INTERNATIONAL (PHILIPPINES) INC.
                            </h5>
                        </div>
                        
                        <div class="form-group">
                            <label for="poNumber"><i class="fa-solid fa-folder-closed"></i> PO Number <span style="color: red;">*</span></label>
                            <input type="text" id="poNumber" name="po_number" placeholder="Enter 10-digit PO number" maxlength="10">
                            <div id="poError" class="error-message"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="numberOfDays"><i class="fa-regular fa-sun"></i> Number of Days <span style="color: red;">*</span></label>
                            <input type="number" id="numberOfDays" name="number_of_days" min="0" placeholder="Enter number of days">
                        </div>
                        
                        <div class="form-group">
                            <label for="addAmount"><i class="fa-solid fa-plus"></i> Add Amount</label>
                            <input type="number" id="addAmount" name="add_amount" value="500" step="0.01" readonly class="readonly-field">
                            <small style="color: #7f8c8d;">Calculated: 500 * number of days</small>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Preview Section -->
                <div class="summary-section">
                    <h4 style="margin-top: 0; color: #2c3e50;">
                        <i class="fa-solid fa-chart-pie"></i> Summary Preview
                    </h4>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="label"><i class="fa-solid fa-list-ol"></i> Number of Transactions</div>
                            <div class="value" id="previewTransactionCount">0</div>
                        </div>
                        <div class="summary-item">
                            <div class="label"><i class="fa-solid fa-peso-sign"></i> Total Principal</div>
                            <div class="value" id="previewTotalPrincipal">₱ 0.00</div>
                        </div>
                        <div class="summary-item">
                            <div class="label"><i class="fa-solid fa-money-bill-transfer"></i> Service Charge</div>
                            <div class="value" id="previewServiceChargeAmount">₱ 0.00</div>
                        </div>
                    </div>
                </div>
                
                <!-- Button Group -->
                <div class="button-group">
                    <button type="button" class="btn btn-primary" id="fetchPartnerBtn">
                        <i class="fa-solid fa-user"></i> Fetch Partner Details
                    </button>
                    <button type="button" class="btn btn-success" id="showInvoiceModalBtn">
                        <i class="fa-solid fa-file-invoice"></i> Create Invoice
                    </button>
                    
                    <a href="billing-service-charge.php" class="btn btn-secondary"> <i class="fa-solid fa-undo"></i> Reset </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Invoice Modal -->
    <div class="modal-overlay" id="invoiceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fa-solid fa-file-invoice" style="color: #27ae60;"></i>
                    Invoice Preview (Manual Entry)
                </h2>
                <button class="modal-close" id="closeInvoiceModal">&times;</button>
            </div>
            <div class="modal-body" id="invoiceModalBody">
                <div class="invoice-preview" id="invoicePreview">
                    <!-- Invoice content will be dynamically generated -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="closeInvoiceModalBtn">
                    <i class="fa-solid fa-times"></i> Close
                </button>
                <button class="btn btn-danger" id="saveInvoiceBtn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Invoice For Review
                </button>
            </div>
        </div>
    </div>

    <!-- Save Invoice Info Modal -->
    <div class="modal-overlay" id="saveInfoModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header" style="background: #f8f9fa;">
                <h2 style="color: #db3434;">
                    <i class="fa-solid fa-info-circle" style="color: #000000;"></i>
                    Save Invoice
                </h2>
                <button class="modal-close" id="closeSaveInfoModal">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 30px 20px;">
                <div style="font-size: 48px; color: #ff0000; margin-bottom: 20px;">
                    <i class="fa-solid fa-floppy-disk"></i>
                </div>
                <h3 id="saveInfoTitle" style="color: #2c3e50; margin-bottom: 10px;">Save Invoice</h3>
                <p id="saveInfoMessage" style="color: #7f8c8d; font-size: 16px; line-height: 1.6;"></p>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button class="btn btn-danger" id="closeSaveInfoModalBtn">
                    <i class="fa-solid fa-check"></i> Got it
                </button>
            </div>
        </div>
    </div>
    
    <?php include '../../../templates/footer.php'; ?>
    <?php include '../no-signature-modal.php'; ?>
    
    <script>
    $(document).ready(function() {

        // Holds the fully-computed invoice payload built by generateInvoicePreview(),
        // consumed by the Save Invoice button.
        let currentInvoiceData = null;

        // Store partner name for later use (for partners without ID)
        window._currentPartnerName = '';

        // Helper function to format numbers with commas
        function formatNumberWithCommas(number) {
            if (number === null || number === undefined || isNaN(number)) {
                return '0';
            }
            const parts = String(number).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            return parts.join('.');
        }

        // Helper function to format numbers for display (with commas)
        function formatNumber(num) {
            if (num === null || num === undefined || isNaN(num)) {
                return '0';
            }
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Helper function to round to 2 decimal places
        function roundTo2(num) {
            return Math.round(num * 100) / 100;
        }

        // Initialize Select2 for partner dropdown
        $('#partnerSelect').select2({
            placeholder: 'Partner ID - Partner Name - Partner Acc Name',
            allowClear: true,
            width: '100%',
            matcher: function(params, data) {
                if ($.trim(params.term) === '') {
                    return data;
                }
                var searchTerm = params.term.toLowerCase();
                var text = data.text.toLowerCase();
                var id = data.id ? data.id.toLowerCase() : '';
                var accName = data.element ? $(data.element).data('partner-accname') || '' : '';
                accName = accName.toLowerCase();
                
                if (id.indexOf(searchTerm) > -1 || text.indexOf(searchTerm) > -1 || accName.indexOf(searchTerm) > -1) {
                    return data;
                }
                return null;
            },
            templateResult: function(data) {
                if (data.element) {
                    var $element = $(data.element);
                    var isDisabled = $element.prop('disabled');
                    var soaStatus = $element.data('soa-status') || '';
                    var hasId = $element.data('has-id') === true || $element.data('has-id') === 'true';
                    
                    if (isDisabled) {
                        return $('<span style="color: #999; background-color: #f5f5f5; padding: 4px 8px; border-radius: 3px; display: block;">' + 
                            data.text + 
                            ' <span style="font-size: 11px; color: #e74c3c; font-weight: 600;">(Disabled - Without SOA)</span>' + 
                            '</span>');
                    }
                    
                    var badgeHtml = '';
                    if (hasId) {
                        badgeHtml = ' <span class="with-id-badge" style="color: #27ae60; font-size: 11px; background: #eafaf1; padding: 1px 6px; border-radius: 3px; margin-left: 5px; border: 1px solid #27ae60;">✓ Has ID</span>';
                    } else {
                        badgeHtml = ' <span class="no-id-badge" style="color: #e67e22; font-size: 11px; background: #fef9e7; padding: 1px 6px; border-radius: 3px; margin-left: 5px; border: 1px solid #f39c12;">No ID</span>';
                    }
                    
                    if (soaStatus === 'WITH SOA') {
                        return $('<span>' + data.text + ' <span style="color: #27ae60; font-size: 12px; font-weight: 600;">✓</span>' + badgeHtml + '</span>');
                    }
                    return $('<span>' + data.text + badgeHtml + '</span>');
                }
                return data.text;
            },
            templateSelection: function(data) {
                if (data.element) {
                    var $element = $(data.element);
                    var isDisabled = $element.prop('disabled');
                    if (isDisabled) {
                        return $('<span style="color: #999;">' + data.text + ' (Disabled)</span>');
                    }
                    return data.text;
                }
                return data.text;
            }
        });
        
        // Prevent selection of disabled options
        $('#partnerSelect').on('select2:selecting', function(e) {
            var $element = $(e.params.args.data.element);
            if ($element && $element.prop('disabled')) {
                e.preventDefault();
                showAlert('This partner has "WITHOUT SOA" status and cannot be selected. Please choose a partner with "WITH SOA" status.', 'warning');
                return false;
            }
        });
        
        // Initialize date inputs
        $('#fromDate').val('');
        $('#toDate').val('');
        
        // =============================================
        // DATE RESTRICTION LOGIC
        // =============================================
        
        function getServiceChargeType() {
            const serviceCharge = $('#serviceCharge').val() || '';
            return serviceCharge.trim().toUpperCase();
        }
        
        function getDateRestrictionMessage(serviceChargeType) {
            const messages = {
                'MONTHLY': 'Monthly: From date must be the 1st of the month, To date must be the last day of the month.',
                'SEMI-MONTHLY': 'Semi-Monthly: From date must be the 1st or 16th of the month, To date must be the 15th or last day of the month.',
                'WEEKLY': 'Weekly: From date must be Monday, To date must be Sunday (7-day week).',
                'DAILY': 'Daily: From date and To date must be the same day.'
            };
            return messages[serviceChargeType] || 'No specific date restrictions for this partner.';
        }
        
        function getDateRangeLimits(serviceChargeType) {
            const fromDate = $('#fromDate').val();
            const toDate = $('#toDate').val();
            
            if (!fromDate || !toDate) return null;
            
            const from = new Date(fromDate);
            const to = new Date(toDate);
            const month = from.getMonth();
            const year = from.getFullYear();
            const lastDay = new Date(year, month + 1, 0).getDate();
            const fromDayOfWeek = from.getDay();
            const toDayOfWeek = to.getDay();
            const fromDay = from.getDate();
            const toDay = to.getDate();
            const diffTime = Math.abs(to - from);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            let isValid = false;
            let errorMessage = '';
            
            switch(serviceChargeType) {
                case 'MONTHLY':
                    if (fromDay === 1 && toDay === lastDay && diffDays === (lastDay - 1)) {
                        isValid = true;
                    } else {
                        errorMessage = `Monthly requires: From = 1st of month, To = ${lastDay}th (last day of month).`;
                    }
                    break;
                case 'SEMI-MONTHLY':
                    const isFromValid = (fromDay === 1 || fromDay === 16);
                    const isToValid = (toDay === 15 || toDay === lastDay);
                    let isRangeValid = false;
                    if (fromDay === 1 && toDay === 15 && diffDays === 14) {
                        isRangeValid = true;
                    } else if (fromDay === 16 && toDay === lastDay && diffDays === (lastDay - 16)) {
                        isRangeValid = true;
                    }
                    if (isFromValid && isToValid && isRangeValid) {
                        isValid = true;
                    } else {
                        errorMessage = `Semi-Monthly requires: From = 1st or 16th, To = 15th or last day of month.`;
                    }
                    break;
                case 'WEEKLY':
                    if (fromDayOfWeek === 1 && toDayOfWeek === 0 && diffDays === 6) {
                        isValid = true;
                    } else {
                        errorMessage = `Weekly requires: From = Monday, To = Sunday (7-day week).`;
                    }
                    break;
                case 'DAILY':
                    if (diffDays === 0) {
                        isValid = true;
                    } else {
                        errorMessage = `Daily requires: From date and To date must be the same day.`;
                    }
                    break;
                default:
                    isValid = true;
                    errorMessage = '';
            }
            
            return { isValid, errorMessage, from, to, diffDays };
        }
        
        function updateDateRestrictionInfo() {
            const serviceChargeType = getServiceChargeType();
            const serviceChargeField = $('#serviceCharge').val();
            
            if (serviceChargeField && serviceChargeField.trim() !== '') {
                const message = getDateRestrictionMessage(serviceChargeType);
                $('#dateRestrictionMessage').html(
                    '<strong>' + serviceChargeType + ':</strong> ' + message
                );
                $('#dateRestrictionInfo').addClass('active');
            } else {
                $('#dateRestrictionInfo').removeClass('active');
            }
        }
        
        function validateDateRange() {
            const fromDate = $('#fromDate').val();
            const toDate = $('#toDate').val();
            const serviceChargeField = $('#serviceCharge').val();
            
            if (!fromDate || !toDate || !serviceChargeField || serviceChargeField.trim() === '') {
                return true;
            }
            
            const serviceChargeType = getServiceChargeType();
            const result = getDateRangeLimits(serviceChargeType);
            
            if (!result) return true;
            
            if (!result.isValid) {
                showAlert(result.errorMessage, 'warning');
                $('#fromDate, #toDate').css('border-color', '#e74c3c');
                return false;
            } else {
                $('#fromDate, #toDate').css('border-color', '#27ae60');
                return true;
            }
        }
        
        $('#fromDate, #toDate').on('change', function() {
            updateDateRestrictionInfo();
            if ($('#fromDate').val() && $('#toDate').val()) {
                validateDateRange();
            }
        });
        
        // =============================================
        // END DATE RESTRICTION LOGIC
        // =============================================
        
        // Partner selection change
        $('#partnerSelect').on('change.select2', function() {
            const partnerId = $(this).val();
            if (partnerId) {
                var selectedOption = $(this).find('option:selected');
                if (selectedOption.prop('disabled')) {
                    $(this).val('').trigger('change.select2');
                    showAlert('Invalid selection. Please select a partner with "WITH SOA" status.', 'warning');
                    return;
                }
                var hasId = selectedOption.data('has-id') === true || selectedOption.data('has-id') === 'true';
                var partnerName = selectedOption.data('partner-name') || '';
                window._currentPartnerName = partnerName;
                
                // Clear manual inputs when partner changes
                clearManualInputs();
                
                if (!hasId) {
                    showAlert('This partner does not have a Partner ID. Fetching by name.', 'info');
                    fetchPartnerByName(partnerName);
                    return;
                }
                fetchPartnerDetails(partnerId);
            } else {
                clearForm();
                $('#dateRestrictionInfo').removeClass('active');
                $('#soaExistsWarning').hide();
                window._currentPartnerName = '';
                updateCreateInvoiceButton(false);
                clearManualInputs();
            }
        });
        
        // =============================================
        // MANUAL INPUT PREVIEW UPDATE
        // =============================================
        
        function updateManualPreview() {
            const transactionCount = parseInt($('#numberOfTransactions').val()) || 0;
            const totalPrincipal = parseFloat($('#totalPrincipal').val()) || 0;
            const serviceChargeAmount = parseFloat($('#serviceChargeAmount').val()) || 0;
            
            $('#previewTransactionCount').text(formatNumberWithCommas(transactionCount));
            $('#previewTotalPrincipal').text('₱ ' + formatNumberWithCommas(totalPrincipal.toFixed(2)));
            $('#previewServiceChargeAmount').text('₱ ' + formatNumberWithCommas(serviceChargeAmount.toFixed(2)));
        }
        
        $('#numberOfTransactions, #totalPrincipal, #serviceChargeAmount').on('input', function() {
            updateManualPreview();
        });
        
        function clearManualInputs() {
            $('#numberOfTransactions').val('');
            $('#totalPrincipal').val('');
            $('#serviceChargeAmount').val('');
            $('#previewTransactionCount').text('0');
            $('#previewTotalPrincipal').text('₱ 0.00');
            $('#previewServiceChargeAmount').text('₱ 0.00');
        }
        
        // =============================================
        // END MANUAL INPUT PREVIEW UPDATE
        // =============================================
        
        // Number of days calculation for Partner 434
        $('#numberOfDays').on('input', function() {
            const days = parseInt($(this).val()) || 0;
            const addAmount = days * 500;
            $('#addAmount').val(addAmount.toFixed(2));
        });
        
        // PO Number validation
        $('#poNumber').on('input', function() {
            const poNumber = $(this).val();
            const errorDiv = $('#poError');
            if (poNumber && poNumber.length > 0) {
                if (poNumber.length !== 10 || !/^\d{10}$/.test(poNumber)) {
                    errorDiv.text('PO Number must be exactly 10 digits.');
                    $(this).css('border-color', '#e74c3c');
                } else {
                    errorDiv.text('');
                    $(this).css('border-color', '');
                }
            } else {
                errorDiv.text('');
                $(this).css('border-color', '');
            }
        });
        
        // =============================================
        // CHECK EXISTING SOA FUNCTION
        // =============================================
        
        function checkExistingSOA(partnerId, fromDate, toDate) {
            return new Promise((resolve, reject) => {
                if (partnerId && partnerId.startsWith('no-id-')) {
                    var partnerName = window._currentPartnerName || '';
                    if (!partnerName) {
                        var selectedOption = $('#partnerSelect').find('option:selected');
                        partnerName = selectedOption.data('partner-name') || '';
                    }
                    if (!partnerName) {
                        resolve({ exists: false });
                        return;
                    }
                    $.ajax({
                        url: window.location.href,
                        method: 'GET',
                        data: {
                            action: 'check_existing_soa',
                            partner_name: partnerName,
                            from_date: fromDate,
                            to_date: toDate
                        },
                        success: function(response) {
                            try {
                                if (typeof response === 'string') {
                                    response = JSON.parse(response);
                                }
                                resolve(response);
                            } catch (e) {
                                reject(e);
                            }
                        },
                        error: function(xhr, status, error) {
                            reject(error);
                        }
                    });
                } else {
                    $.ajax({
                        url: window.location.href,
                        method: 'GET',
                        data: {
                            action: 'check_existing_soa',
                            partner_id: partnerId,
                            from_date: fromDate,
                            to_date: toDate
                        },
                        success: function(response) {
                            try {
                                if (typeof response === 'string') {
                                    response = JSON.parse(response);
                                }
                                resolve(response);
                            } catch (e) {
                                reject(e);
                            }
                        },
                        error: function(xhr, status, error) {
                            reject(error);
                        }
                    });
                }
            });
        }
        
        function updateCreateInvoiceButton(exists) {
            const btn = $('#showInvoiceModalBtn');
            if (exists) {
                btn.addClass('disabled-btn').prop('disabled', true);
                $('#soaExistsWarning').show();
            } else {
                btn.removeClass('disabled-btn').prop('disabled', false);
                $('#soaExistsWarning').hide();
            }
        }
        
        // =============================================
        // END CHECK EXISTING SOA
        // =============================================
        
        // Fetch Partner Details button
        $('#fetchPartnerBtn').click(async function() {
            const partnerId = $('#partnerSelect').val();
            if (!partnerId) {
                showAlert('Please select a partner first.', 'warning');
                return;
            }
            const fromDate = $('#fromDate').val();
            const toDate = $('#toDate').val();
            if (!fromDate || !toDate) {
                showAlert('Please select both Transaction Date From and Transaction Date To.', 'warning');
                return;
            }
            
            if (!validateDateRange()) {
                return;
            }
            
            if (partnerId.startsWith('no-id-')) {
                var selectedOption = $('#partnerSelect').find('option:selected');
                var partnerName = selectedOption.data('partner-name') || '';
                window._currentPartnerName = partnerName;
                fetchPartnerByName(partnerName);
                return;
            }
            
            try {
                showLoadingModal('Checking...', 'Verifying if SOA already exists for this partner and date range.');
                const result = await checkExistingSOA(partnerId, fromDate, toDate);
                hideLoadingModal();
                
                if (result.exists) {
                    updateCreateInvoiceButton(true);
                    $('#soaExistsMessage').html(
                        'SOA <strong>' + result.reference_number + '</strong> already exists for this partner (' + 
                        result.from_date + ' to ' + result.to_date + ').'
                    );
                    Swal.fire({
                        icon: 'warning',
                        title: 'SOA Already Exists',
                        html: `
                            <div style="text-align: left;">
                                <p><strong>An SOA has already been created for this partner and date range.</strong></p>
                                <hr>
                                <p><strong>Reference Number:</strong> ${result.reference_number}</p>
                                <p><strong>Existing Period:</strong> ${result.from_date} to ${result.to_date}</p>
                                <p style="color: #e74c3c; margin-top: 10px;"><i class="fa-solid fa-ban"></i> Please refresh page and select a different date range or partner.</p>
                            </div>
                        `,
                        confirmButtonColor: '#d33',
                        confirmButtonText: 'OK',
                        allowOutsideClick: false
                    });
                    return;
                }
                
                updateCreateInvoiceButton(false);
                fetchPartnerDetails(partnerId);
                
            } catch (error) {
                hideLoadingModal();
                console.error('Error checking existing SOA:', error);
                fetchPartnerDetails(partnerId);
            }
        });
        
        // Date change - auto fetch
        let dateChangeTimer;
        $('#fromDate, #toDate').on('change', function() {
            clearTimeout(dateChangeTimer);
            dateChangeTimer = setTimeout(async function() {
                const partnerId = $('#partnerSelect').val();
                const fromDate = $('#fromDate').val();
                const toDate = $('#toDate').val();
                if (partnerId && fromDate && toDate) {
                    if (validateDateRange()) {
                        if (partnerId.startsWith('no-id-')) {
                            var selectedOption = $('#partnerSelect').find('option:selected');
                            var partnerName = selectedOption.data('partner-name') || '';
                            window._currentPartnerName = partnerName;
                            fetchPartnerByName(partnerName);
                            return;
                        }
                        try {
                            const result = await checkExistingSOA(partnerId, fromDate, toDate);
                            if (result.exists) {
                                updateCreateInvoiceButton(true);
                                $('#soaExistsMessage').html(
                                    'SOA <strong>' + result.reference_number + '</strong> already exists for this partner (' + 
                                    result.from_date + ' to ' + result.to_date + ').'
                                );
                                showLoadingModal('Fetching data...', 'Please wait while we retrieve partner details.');
                                fetchPartnerDetails(partnerId);
                                return;
                            }
                            updateCreateInvoiceButton(false);
                            showLoadingModal('Fetching data...', 'Please wait while we retrieve partner details.');
                            fetchPartnerDetails(partnerId);
                        } catch (error) {
                            console.error('Error checking existing SOA:', error);
                            showLoadingModal('Fetching data...', 'Please wait while we retrieve partner details.');
                            fetchPartnerDetails(partnerId);
                        }
                    }
                }
            }, 300);
        });
        
        // Fetch partner by name for partners without ID
        function fetchPartnerByName(partnerName) {
            const fromDate = $('#fromDate').val();
            const toDate = $('#toDate').val();
            
            if (!fromDate || !toDate) {
                hideLoadingModal();
                showAlert('Please select transaction dates first.', 'warning');
                return;
            }
            
            showLoadingModal('Fetching Data', 'Please wait while we retrieve partner details...');
            
            $.ajax({
                url: window.location.href,
                method: 'GET',
                data: {
                    action: 'get_partner_by_name',
                    partner_name: partnerName
                },
                success: function(response) {
                    hideLoadingModal();
                    try {
                        if (typeof response === 'string') {
                            response = JSON.parse(response);
                        }
                        if (response.error) {
                            showAlert('Error: ' + response.error, 'error');
                            return;
                        }
                        window._currentPartnerName = partnerName;
                        populateForm(response);
                        updateDateRestrictionInfo();
                        validateDateRange();
                    } catch (e) {
                        showAlert('Error parsing response. Please check the console for details.', 'error');
                        console.error('Response:', response);
                        console.error('Error:', e);
                    }
                },
                error: function(xhr, status, error) {
                    hideLoadingModal();
                    let errorMsg = 'Error fetching partner details. ';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.error) {
                            errorMsg += response.error;
                        } else {
                            errorMsg += 'Please try again.';
                        }
                    } catch (e) {
                        errorMsg += 'Status: ' + status + ', Error: ' + error;
                        console.error('Full error response:', xhr.responseText);
                    }
                    showAlert(errorMsg, 'error');
                }
            });
        }
        
        function fetchPartnerDetails(partnerId) {
            const fromDate = $('#fromDate').val();
            const toDate = $('#toDate').val();
            
            if (!fromDate || !toDate) {
                hideLoadingModal();
                showAlert('Please select transaction dates and partner to fetch other fields.', 'warning');
                return;
            }
            
            showLoadingModal('Fetching Data', 'Please wait while we retrieve partner details...');
            
            $.ajax({
                url: window.location.href,
                method: 'GET',
                data: {
                    action: 'get_partner_details',
                    partner_id: partnerId
                },
                success: function(response) {
                    hideLoadingModal();
                    try {
                        if (typeof response === 'string') {
                            response = JSON.parse(response);
                        }
                        if (response.error) {
                            showAlert('Error: ' + response.error, 'error');
                            return;
                        }
                        populateForm(response);
                        updateDateRestrictionInfo();
                        validateDateRange();
                    } catch (e) {
                        showAlert('Error parsing response. Please check the console for details.', 'error');
                        console.error('Response:', response);
                        console.error('Error:', e);
                    }
                },
                error: function(xhr, status, error) {
                    hideLoadingModal();
                    let errorMsg = 'Error fetching partner details. ';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.error) {
                            errorMsg += response.error;
                        } else {
                            errorMsg += 'Please try again.';
                        }
                    } catch (e) {
                        errorMsg += 'Status: ' + status + ', Error: ' + error;
                        console.error('Full error response:', xhr.responseText);
                    }
                    showAlert(errorMsg, 'error');
                }
            });
        }
        
        function populateForm(data) {
            $('#controlNumber').val(data.control_number || '');
            $('#partnerAccName').val(data.partner_accName || '');
            $('#partnerTin').val(data.partnerTin || '');
            $('#partnerAbbreviation').val(data.abbreviation || ''); 
            $('#address').val(data.address || '');
            $('#businessStyle').val(data.businessStyle || '');
            $('#serviceCharge').val(data.serviceCharge ? data.serviceCharge : '');
            $('#incExc').val(data.inc_exc_display || data.inc_exc || '');
            $('#withholdingTax').val(data.withheld || '');
            
            // Check if this partner has an ID (from the response)
            if (data.has_partner_id === false) {
                $('#additionalFields').hide();
                $('#numberOfDays').val('');
                $('#addAmount').val('500');
                $('#poNumber').val('');
                $('#poError').text('');
                $('#poNumber').css('border-color', '');
            } else if (data.is_partner_434) {
                $('#additionalFields').show();
                $('#poNumber').val(data.po_number || '');
                if (data.po_number && data.po_number.length === 10 && /^\d{10}$/.test(data.po_number)) {
                    $('#poError').text('');
                    $('#poNumber').css('border-color', '');
                } else if (data.po_number) {
                    $('#poError').text('Invalid PO number format.');
                    $('#poNumber').css('border-color', '#e74c3c');
                }
                if (data.po_year_error) {
                    $('#poError').text(data.po_year_error);
                    $('#poNumber').css('border-color', '#e74c3c');
                }
            } else {
                $('#additionalFields').hide();
                $('#numberOfDays').val('');
                $('#addAmount').val('500');
                $('#poNumber').val('');
                $('#poError').text('');
                $('#poNumber').css('border-color', '');
            }
            
            updateDateRestrictionInfo();
            showAlert('Partner details fetched successfully! Please enter transaction details below.', 'success');
        }
        
        function clearForm() {
            $('#controlNumber').val('');
            $('#partnerAccName').val('');
            $('#partnerTin').val('');
            $('#partnerAbbreviation').val(''); 
            $('#address').val('');
            $('#businessStyle').val('');
            $('#serviceCharge').val('');
            $('#incExc').val('');
            $('#withholdingTax').val('');
            $('#additionalFields').hide();
            $('#alert-container').html('');
            $('#numberOfDays').val('');
            $('#addAmount').val('500');
            $('#poNumber').val('');
            $('#poError').text('');
            $('#poNumber').css('border-color', '');
            $('#dateRestrictionInfo').removeClass('active');
            $('#fromDate, #toDate').css('border-color', '');
            $('#soaExistsWarning').hide();
            currentInvoiceData = null;
            window._currentPartnerName = '';
            updateCreateInvoiceButton(false);
            clearManualInputs();
        }
        
        function showAlert(message, type) {
            const alertContainer = $('#alert-container');
            const alertClass = type === 'success' ? 'alert-success' : 
                             type === 'warning' ? 'alert-warning' : 'alert-danger';
            const icon = type === 'success' ? 'fa-check-circle' :
                        type === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle';
            
            alertContainer.html(`
                <div class="alert ${alertClass}" style="padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                    <i class="fas ${icon}" style="margin-right: 10px;"></i>
                    ${message}
                </div>
            `);
            
            if (type === 'success') {
                setTimeout(() => {
                    alertContainer.html('');
                }, 5000);
            }
        }
        
        // =============================================
        // SHOW INVOICE MODAL
        // =============================================
        
        $('#showInvoiceModalBtn').click(async function() {
            if ($(this).prop('disabled')) {
                showAlert('Cannot create invoice. SOA already exists for this partner and date range.', 'warning');
                return;
            }
            
            const partnerId = $('#partnerSelect').val();
            if (!partnerId) {
                showAlert('Please select a partner first.', 'warning');
                return;
            }
            const fromDate = $('#fromDate').val();
            const toDate = $('#toDate').val();
            if (!fromDate || !toDate) {
                showAlert('Please select both Transaction Date From and Transaction Date To.', 'warning');
                return;
            }
            
            if (!validateDateRange()) {
                return;
            }
            
            // Check if partner details are fetched
            const controlNumber = $('#controlNumber').val();
            if (!controlNumber) {
                showAlert('Please fetch partner details first (click Fetch Partner Details).', 'warning');
                return;
            }
            
            // Validate manual inputs
            const transactionCount = parseInt($('#numberOfTransactions').val()) || 0;
            const totalPrincipal = parseFloat($('#totalPrincipal').val()) || 0;
            const serviceChargeAmount = parseFloat($('#serviceChargeAmount').val()) || 0;
            
            if (transactionCount <= 0) {
                showAlert('Please enter a valid Number of Transactions (must be greater than 0).', 'warning');
                return;
            }
            if (totalPrincipal <= 0) {
                showAlert('Please enter a valid Total Principal (must be greater than 0).', 'warning');
                return;
            }
            if (serviceChargeAmount <= 0) {
                showAlert('Please enter a valid Service Charge Amount (must be greater than 0).', 'warning');
                return;
            }
            
            // Check for PO Number if Partner 434
            const isPartner434 = $('#additionalFields').is(':visible');
            if (isPartner434) {
                const poNumber = $('#poNumber').val();
                if (!poNumber || poNumber.length !== 10 || !/^\d{10}$/.test(poNumber)) {
                    showAlert('Please enter a valid 10-digit PO Number for Partner 434.', 'warning');
                    return;
                }
            }
            
            if (partnerId.startsWith('no-id-')) {
                generateInvoicePreview();
                return;
            }
            
            try {
                showLoadingModal('Checking...', 'Verifying if SOA already exists for this partner and date range.');
                const result = await checkExistingSOA(partnerId, fromDate, toDate);
                hideLoadingModal();
                
                if (result.exists) {
                    updateCreateInvoiceButton(true);
                    $('#soaExistsMessage').html(
                        'SOA <strong>' + result.reference_number + '</strong> already exists for this partner (' + 
                        result.from_date + ' to ' + result.to_date + ').'
                    );
                    Swal.fire({
                        icon: 'warning',
                        title: 'SOA Already Exists',
                        html: `
                            <div style="text-align: left;">
                                <p><strong>An SOA has already been created for this partner and date range.</strong></p>
                                <hr>
                                <p><strong>Reference Number:</strong> ${result.reference_number}</p>
                                <p><strong>Existing Period:</strong> ${result.from_date} to ${result.to_date}</p>
                                <p style="color: #e74c3c; margin-top: 10px;"><i class="fa-solid fa-ban"></i> Please refresh page and select a different date range or partner.</p>
                            </div>
                        `,
                        confirmButtonColor: '#d33',
                        confirmButtonText: 'OK',
                        allowOutsideClick: false
                    });
                    return;
                }
                
                updateCreateInvoiceButton(false);
                generateInvoicePreview();
                
            } catch (error) {
                hideLoadingModal();
                console.error('Error checking existing SOA:', error);
                showAlert('Error checking for existing SOA. Please try again.', 'error');
            }
        });
        
        // =============================================
        // GENERATE INVOICE PREVIEW (MANUAL VERSION)
        // =============================================
        
        function generateInvoicePreview() {
            // Get partner details from fields
            const partnerAccountName = $('#partnerAccName').val() || 'N/A';
            const tin = $('#partnerTin').val() || 'N/A';
            const address = $('#address').val() || 'N/A';
            const businessStyle = $('#businessStyle').val() || 'NONE';
            const serviceCharge = $('#serviceCharge').val() || '0';
            const incExc = $('#incExc').val() || 'N/A';
            const withholdingTax = $('#withholdingTax').val() || 'NO';
            const controlNumber = $('#controlNumber').val() || 'N/A';
            const invoiceDate = $('#invoiceDate').val() || '';
            const partnerId = $('#partnerSelect').val() || '';
            const fromDate = $('#fromDate').val() || '';
            const toDate = $('#toDate').val() || '';
            const poNumber = $('#poNumber').val() || '';
            
            // Get manual input values
            const transactionCount = parseInt($('#numberOfTransactions').val()) || 0;
            const totalPrincipal = parseFloat($('#totalPrincipal').val()) || 0;
            const serviceChargeAmount = parseFloat($('#serviceChargeAmount').val()) || 0;
            
            // Get add amount (for partner 434)
            const numberOfDaysRaw = $('#numberOfDays').val();
            const numberOfDays = parseInt(numberOfDaysRaw) || 0;
            const isPartner434 = $('#additionalFields').is(':visible');
            
            // Calculate add amount
            let addAmountValue = 0;
            let addAmountDisplay = '₱ 0';
            let amountAddBase = '';
            let addAmountForStorage = '0';
            let numberOfDaysForStorage = '';
            
            if (isPartner434 && numberOfDays > 0) {
                addAmountValue = 500 * numberOfDays;
                addAmountDisplay = `₱ 500 × ${numberOfDays}`;
                amountAddBase = '500';
                addAmountForStorage = (500 * numberOfDays).toFixed(2); 
                numberOfDaysForStorage = String(numberOfDays);
            } else if (isPartner434) {
                addAmountValue = 500;
                addAmountDisplay = '₱ 500';
                amountAddBase = '500';
                addAmountForStorage = '500';
                numberOfDaysForStorage = '';
            }
            
            // Format dates
            const fromDateFormatted = fromDate ? new Date(fromDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
            const toDateFormatted = toDate ? new Date(toDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
            const invoiceDateFormatted = invoiceDate ? new Date(invoiceDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
            
            // Initialize variables
            let vatAmount = 0;
            let netOfVat = 0;
            let withholdingTaxAmount = 0;
            let totalAmountDue = 0;
            let lessWT = 0;
            let netAmountDue = 0;
            
            // Normalize values for comparison
            const incExcUpper = incExc.toUpperCase().trim();
            const withheldUpper = withholdingTax.toUpperCase().trim();
            const isNonVat = incExcUpper === 'NON-VAT';
            const isInclusive = incExcUpper === 'INCLUSIVE';
            const isExclusive = incExcUpper === 'EXCLUSIVE';
            const isWithheld = withheldUpper === 'YES';
            const isNoWithheld = withheldUpper === 'NO' || withheldUpper === 'NO WITHHELD' || withheldUpper === '';
            
            // Use the manually entered service charge amount as the base
            const baseAmount = serviceChargeAmount;
            
            // Calculate based on conditions
            let formulaText = '';

            if (isNonVat) {
                vatAmount = 0;
                netOfVat = 0;
                withholdingTaxAmount = 0;
                totalAmountDue = baseAmount;
                lessWT = 0;
                netAmountDue = totalAmountDue + addAmountValue;
                formulaText = 'VAT Amount 0%';
            } else if (isInclusive && isWithheld) {
                vatAmount = roundTo2((baseAmount * 0.12) / 1.12);
                netOfVat = roundTo2(baseAmount - vatAmount);
                withholdingTaxAmount = roundTo2(netOfVat * 0.02);
                totalAmountDue = baseAmount;
                lessWT = withholdingTaxAmount;
                netAmountDue = roundTo2(totalAmountDue - lessWT + addAmountValue);
                formulaText = 'VAT Amount 12% = (Amount * 12%) / 1.12 | Net of VAT = Amount - VAT Amount | WTax = Net of VAT * 2%';
            } else if (isInclusive && isNoWithheld) {
                vatAmount = roundTo2((baseAmount * 0.12) / 1.12);
                netOfVat = roundTo2(baseAmount - vatAmount);
                withholdingTaxAmount = 0;
                totalAmountDue = baseAmount;
                lessWT = 0;
                netAmountDue = roundTo2(totalAmountDue - lessWT + addAmountValue);
                formulaText = 'VAT Amount 12% = (Amount * 12%) / 1.12 | Net of VAT = Amount - VAT Amount';
            } else if (isExclusive && isWithheld) {
                vatAmount = roundTo2(baseAmount * 0.12);
                netOfVat = 0;
                withholdingTaxAmount = roundTo2(baseAmount * 0.02);
                totalAmountDue = roundTo2(baseAmount + vatAmount);
                lessWT = withholdingTaxAmount;
                netAmountDue = roundTo2(totalAmountDue - lessWT + addAmountValue);
                formulaText = 'VAT Amount 12% = Amount * 12% | WTax = Amount * 2%';
            } else if (isExclusive && isNoWithheld) {
                vatAmount = roundTo2(baseAmount * 0.12);
                netOfVat = 0;
                withholdingTaxAmount = 0;
                totalAmountDue = roundTo2(baseAmount + vatAmount);
                lessWT = 0;
                netAmountDue = roundTo2(totalAmountDue - lessWT + addAmountValue);
                formulaText = 'VAT Amount 12% = Amount * 12%';
            } else {
                vatAmount = 0;
                netOfVat = 0;
                withholdingTaxAmount = 0;
                totalAmountDue = baseAmount;
                lessWT = 0;
                netAmountDue = totalAmountDue + addAmountValue;
                formulaText = '';
            }

            // Stash the full payload for Save Invoice
            currentInvoiceData = {
                partner_id: partnerId,
                invoice_date: invoiceDate,
                control_number: controlNumber,
                partner_acc_name: partnerAccountName,
                partner_tin: tin,
                address: address,
                business_style: businessStyle,
                service_charge: serviceCharge,
                from_date: fromDate,
                to_date: toDate,
                po_number: poNumber,
                transaction_count: String(transactionCount),
                amount: baseAmount,
                add_amount: addAmountForStorage,
                amount_add: amountAddBase,
                number_of_days: numberOfDaysForStorage,
                formula: incExc,
                formula_withheld: withholdingTax,
                formula_calc_text: formulaText,
                vat_amount: vatAmount.toFixed(2),
                net_of_vat: netOfVat.toFixed(2),
                withholding_tax: withholdingTaxAmount.toFixed(2),
                total_amount_due: totalAmountDue.toFixed(2),
                net_amount_due: netAmountDue.toFixed(2)
            };
            
            // Build left column particulars
            let leftColumnParticulars = '';
            
            leftColumnParticulars += `
                <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                    <span>Service Charge:</span>
                    <span style="font-weight: bold;">${serviceCharge}</span>
                </div>`;
            
            leftColumnParticulars += `
                <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                    <span>Number of Transactions:</span>
                    <span style="font-weight: bold;">${formatNumber(transactionCount)}</span>
                </div>`;
            
            leftColumnParticulars += `
                <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                    <span>Transaction Date From:</span>
                    <span style="font-weight: bold;">${fromDateFormatted}</span>
                </div>`;
            
            leftColumnParticulars += `
                <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                    <span>Transaction Date To:</span>
                    <span style="font-weight: bold;">${toDateFormatted}</span>
                </div>`;
            
            leftColumnParticulars += `
                <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                    <span>VAT (Incl. or Excl.):</span>
                    <span style="font-weight: bold;">${incExc}</span>
                </div>`;
            
            leftColumnParticulars += `
                <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                    <span>With Holding Tax (Y / N):</span>
                    <span style="font-weight: bold;">${withholdingTax}</span>
                </div>`;
            
            if (isPartner434) {
                leftColumnParticulars += `
                    <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                        <span>Add Amount:</span>
                        <span style="font-weight: bold;">${addAmountDisplay}</span>
                    </div>`;
            }

            // Add VAT and tax text display
            if (!isNonVat) {
                if (isInclusive && isWithheld) {
                    leftColumnParticulars += `
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">VAT Amount 12% = (Amount * 12%) / 1.12</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">Net of VAT = Amount - VAT Amount</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">WTax = Net of VAT * 2%</span>
                        </div>`;
                } else if (isInclusive && isNoWithheld) {
                    leftColumnParticulars += `
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">VAT Amount 12% = (Amount * 12%) / 1.12</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">Net of VAT = Amount - VAT Amount</span>
                        </div>`;
                } else if (isExclusive && isWithheld) {
                    leftColumnParticulars += `
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">VAT Amount 12% = Amount * 12%</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">WTax = Amount * 2%</span>
                        </div>`;
                } else if (isExclusive && isNoWithheld) {
                    leftColumnParticulars += `
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                            <span style="color: #ff0000">VAT Amount 12% = Amount * 12%</span>
                        </div>`;
                }
            } else {
                leftColumnParticulars += `
                    <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                        <span style="color: #ff0000">VAT Amount 0%</span>
                    </div>`;
            }
            
            // Build the invoice HTML
            const invoiceHTML = `
                <div class="invoice-preview">
                    <div class="invoice-title-main">BILLING INVOICE</div>
                    
                    <div class="divider"></div>
                    
                    <div class="right-side-header">
                        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 20px; margin-bottom: 5px;">
                            <span style="font-weight: bold; min-width: 120px; text-align: left;">Invoice Date:</span>
                            <span style="min-width: 150px; text-align: right; font-weight: bold; color: #e74c3c;">${invoiceDateFormatted}</span>
                        </div>
                        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 20px; margin-bottom: 5px;">
                            <span style="font-weight: bold; min-width: 120px; text-align: left;">Control No:</span>
                            <span style="min-width: 150px; text-align: right; font-weight: bold; color: #e74c3c;">${controlNumber}</span>
                        </div>
                    </div>
                    
                    <div class="left-side-details">
                        <div class="row">
                            <span class="label">Partner Name: <span style="color: red;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${partnerAccountName}</span></span>
                        </div>
                        <div class="row">
                            <span class="label">TIN: <span style="color: red;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${tin}</span></span>
                        </div>
                        <div class="row">
                            <span class="label">Address: <span style="color: red;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${address}</span></span>
                        </div>
                        <div class="row">
                            <span class="label">Business Style: <span style="color: red;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${businessStyle}</span></span>
                        </div>
                    </div>
                    
                    <div class="divider"></div>
                    
                    <div class="side-labels">
                        <div class="left-label">PARTICULARS</div>
                        <div class="right-label">AMOUNT</div>
                    </div>
                    
                    <div class="divider"></div>
                    
                    <div style="display: flex; justify-content: space-between; gap: 40px; padding: 5px 0;">
                        <div style="flex: 1;">
                            ${leftColumnParticulars}
                        </div>
                        
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee; font-weight: bold; font-size: 15px;">
                                <span>Service Charge Amount:</span>
                                <span style="font-weight: bold;">₱ ${formatNumber(baseAmount.toFixed(2))}</span>
                            </div>
                            ${!isNonVat ? `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>VAT Amount:</span>
                                <span style="font-weight: bold;">₱ ${formatNumber(vatAmount.toFixed(2))}</span>
                            </div>
                            ` : `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>VAT Amount:</span>
                                <span style="font-weight: bold;">₱ 0.00</span>
                            </div>
                            `}
                            ${isInclusive ? `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Net of VAT:</span>
                                <span style="font-weight: bold;">₱ ${formatNumber(netOfVat.toFixed(2))}</span>
                            </div>
                            ` : `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Net of VAT:</span>
                                <span style="font-weight: bold;">₱ 0.00</span>
                            </div>
                            `}
                            ${isWithheld ? `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Withholding Tax:</span>
                                <span style="font-weight: bold;">₱ ${formatNumber(withholdingTaxAmount.toFixed(2))}</span>
                            </div>
                            ` : `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Withholding Tax:</span>
                                <span style="font-weight: bold;">₱ 0.00</span>
                            </div>
                            `}
                            ${isPartner434 ? `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Add Amount:</span>
                                <span style="font-weight: bold;">₱ ${formatNumber(addAmountValue.toFixed(2))}</span>
                            </div>
                            ` : ''}
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 2px double #333; font-weight: bold; font-size: 15px; margin-top: 5px;">
                                <span>Total Amount Due:</span>
                                <span>₱ ${formatNumber(totalAmountDue.toFixed(2))}</span>
                            </div>
                            ${isWithheld ? `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Less: Withholding Tax:</span>
                                <span>₱ ${formatNumber(lessWT.toFixed(2))}</span>
                            </div>
                            ` : `
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; border-bottom: 1px dashed #eee;">
                                <span>Less: Withholding Tax:</span>
                                <span>₱ 0.00</span>
                            </div>
                            `}
                            <div style="display: flex; justify-content: space-between; padding: 1px 0; font-weight: bold; font-size: 16px; border-top: 2px double #333; margin-top: 5px; padding-top: 10px;">
                                <span>Net Amount Due:</span>
                                <span>₱ ${formatNumber(netAmountDue.toFixed(2))}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="divider-double"></div>
                    
                    <div class="signature-fields">
                        <div class="signature-item">
                            <div class="signature-line"><?php echo htmlspecialchars($display_name); ?></div>
                            <span class="signature-label">Accounting Staff</span>
                        </div>
                        <div class="signature-item">
                            <div class="signature-line">&nbsp;</div>
                            <span class="signature-label">Department Manager</span>
                        </div>
                        <div class="signature-item">
                            <div class="signature-line">&nbsp;</div>
                            <span class="signature-label">Division Head</span>
                        </div>
                    </div>

                    <div style="text-align: center; font-size: 11px; color: #999; margin-top: 10px;">
                        <i class="fa-regular fa-file-lines"></i> Generated on ${new Date().toLocaleString()}
                    </div>
                </div>
            `;
            
            $('#invoicePreview').html(invoiceHTML);
            openInvoiceModal();
        }
        
        // =============================================
        // MODAL CONTROLS
        // =============================================
        
        function openInvoiceModal() {
            $('#invoiceModal').addClass('active');
            $('body').css('overflow', 'hidden');
        }
        
        function closeInvoiceModal() {
            $('#invoiceModal').removeClass('active');
            $('body').css('overflow', '');
        }
        
        $('#closeInvoiceModal').click(closeInvoiceModal);
        $('#closeInvoiceModalBtn').click(closeInvoiceModal);
        
        $('#invoiceModal').click(function(e) {
            if (e.target === this) {
                closeInvoiceModal();
            }
        });
        
        $(document).keydown(function(e) {
            if (e.key === 'Escape' && $('#invoiceModal').hasClass('active')) {
                closeInvoiceModal();
            }
            if (e.key === 'Escape' && $('#saveInfoModal').hasClass('active')) {
                closeSaveInfoModal();
            }
        });
        
        // =============================================
        // SAVE INVOICE BUTTON
        // =============================================
        
        $('#saveInvoiceBtn').click(async function() {
            if (!currentInvoiceData) {
                showAlert('Please generate the invoice preview first.', 'warning');
                return;
            }

            const $btn = $(this);
            $btn.prop('disabled', true);

            try {
                showLoadingModal('Saving...', 'Saving the invoice for review.');

                const response = await $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: Object.assign({ action: 'save_invoice' }, currentInvoiceData)
                });

                hideLoadingModal();

                let result = response;
                if (typeof result === 'string') {
                    result = JSON.parse(result);
                }

                if (result.success) {
                    openSaveInfoModal(true, result);
                    closeInvoiceModal();
                    updateCreateInvoiceButton(true);
                } else {
                    openSaveInfoModal(false, result);
                }

            } catch (error) {
                hideLoadingModal();
                console.error('Error saving invoice:', error);
                openSaveInfoModal(false, { message: 'Could not reach the server. Please try again.' });
            } finally {
                $btn.prop('disabled', false);
            }
        });
        
        // =============================================
        // SAVE INFO MODAL CONTROLS
        // =============================================
        
        function openSaveInfoModal(success, result) {
            result = result || {};
            const $modal = $('#saveInfoModal');
            const $icon = $modal.find('.modal-body > div:first-child');
            const $iconTag = $icon.find('i');
            const $title = $modal.find('#saveInfoTitle');
            const $message = $modal.find('#saveInfoMessage');

            if (success) {
                $icon.css('color', '#27ae60');
                $iconTag.attr('class', 'fa-solid fa-circle-check');
                $title.text('Invoice Saved');
                $message.html(
                    'The invoice <strong>' + (result.reference_number || '') + '</strong> was saved for review.'
                );
            } else {
                $icon.css('color', '#ff0000');
                $iconTag.attr('class', 'fa-solid fa-triangle-exclamation');
                $title.text('Save Failed');
                $message.html(result.message || 'Something went wrong while saving the invoice.');
            }

            $modal.addClass('active');
            $('body').css('overflow', 'hidden');
        }
        
        function closeSaveInfoModal() {
            $('#saveInfoModal').removeClass('active');
            $('body').css('overflow', '');
        }
        
        $('#closeSaveInfoModal').click(closeSaveInfoModal);
        $('#closeSaveInfoModalBtn').click(closeSaveInfoModal);
        
        $('#saveInfoModal').click(function(e) {
            if (e.target === this) {
                closeSaveInfoModal();
            }
        });
        
        // =============================================
        // LOADING MODAL
        // =============================================
        
        let loadingModal = null;
        
        function showLoadingModal(title, text) {
            if (loadingModal) {
                loadingModal.close();
            }
            loadingModal = Swal.fire({
                title: title || 'Loading...',
                text: text || 'Please wait while we fetch the data.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                showCancelButton: false,
                didOpen: function() {
                    Swal.showLoading();
                }
            });
        }
        
        function hideLoadingModal() {
            if (loadingModal) {
                loadingModal.close();
                loadingModal = null;
            }
        }
    });
    </script>
</body>
</html>