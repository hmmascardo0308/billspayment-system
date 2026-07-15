<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action']) && $input['action'] === 'clear_import_session') {
        // Clear all import-related session data
        unset($_SESSION['original_file_name']);
        unset($_SESSION['source_file_type']);
        unset($_SESSION['transactionDate']);
        unset($_SESSION['duplicate_data']);
        unset($_SESSION['ready_to_override_data']);
        unset($_SESSION['region_not_found_data']);
        unset($_SESSION['partner_not_found_data']);
        unset($_SESSION['missing_branch_ids']);
        unset($_SESSION['Matched_BranchID_data']);
        unset($_SESSION['cancellation_BranchID_data']);
        unset($_SESSION['selected_partner']);
        unset($_SESSION['processed_rows']); // Clear processed rows tracking

        // for consolidated error detected in the import process manual
        unset($_SESSION['consolidated_data']);
        unset($_SESSION['validation_error_json']);

        
        // Clear any temporary processing data
        if (isset($_SESSION['temp_processing_data'])) {
            unset($_SESSION['temp_processing_data']);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Session cleared successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>