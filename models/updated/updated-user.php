<?php
include '../../config/config.php';

session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is authorized
if (!isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Check if JSON decode was successful
        if ($input === null) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit;
        }
        
        // Validate required fields
        $required_fields = ['original_id_number', 'user_type', 'id_number', 'first_name', 'last_name', 'username'];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) {
                echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
                exit;
            }
        }
        
        // Sanitize inputs
        $original_id_number = mysqli_real_escape_string($conn, trim($input['original_id_number']));
        $id_number = mysqli_real_escape_string($conn, trim($input['id_number']));
        $first_name = mysqli_real_escape_string($conn, trim($input['first_name']));
        $middle_name = mysqli_real_escape_string($conn, trim($input['middle_name'] ?? ''));
        $last_name = mysqli_real_escape_string($conn, trim($input['last_name']));
        $username = mysqli_real_escape_string($conn, trim($input['username']));
        $user_type = mysqli_real_escape_string($conn, trim($input['user_type']));
        
        // Get current user info for modified_by field
        $modified_by = '';
        if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_name'])) {
            $modified_by = $_SESSION['admin_name'];
        } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_name'])) {
            $modified_by = $_SESSION['user_name'];
        } else {
            $modified_by = 'Guest';
        }
        
        $modified_date = date('Y-m-d H:i:s');
        
        // Check if ID number already exists (excluding current user)
        if ($id_number !== $original_id_number) {
            $check_query = "SELECT id_number FROM mldb.user_form WHERE id_number = ? AND id_number != ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "ss", $id_number, $original_id_number);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                echo json_encode(['success' => false, 'message' => 'ID Number already exists']);
                exit;
            }
        }
        
        // Check if email/username already exists (excluding current user)
        $check_email_query = "SELECT email FROM mldb.user_form WHERE email = ? AND id_number != ?";
        $check_email_stmt = mysqli_prepare($conn, $check_email_query);
        mysqli_stmt_bind_param($check_email_stmt, "ss", $username, $original_id_number);
        mysqli_stmt_execute($check_email_stmt);
        $check_email_result = mysqli_stmt_get_result($check_email_stmt);
        
        if (mysqli_num_rows($check_email_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'Username/Email already exists']);
            exit;
        }
        
        // Update user in user_form table
        $update_query = "UPDATE mldb.user_form 
                        SET id_number = ?,
                            first_name = ?,
                            middle_name = ?,
                            last_name = ?,
                            email = ?,
                            user_type = ?,
                            modified_date = ?,
                            modified_by = ?
                        WHERE id_number = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "sssssssss", 
            $id_number, $first_name, $middle_name, $last_name, $username, 
            $user_type, $modified_date, $modified_by, $original_id_number
        );
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Get the updated user data (matching the SELECT query structure from user-management.php)
            $updated_user_query = "SELECT id_number, first_name, middle_name, last_name, email as username, user_type, status, last_online, date_created, created_by, modified_date, modified_by FROM mldb.user_form WHERE id_number = ?";
            $updated_user_stmt = mysqli_prepare($conn, $updated_user_query);
            mysqli_stmt_bind_param($updated_user_stmt, "s", $id_number);
            mysqli_stmt_execute($updated_user_stmt);
            $updated_user_result = mysqli_stmt_get_result($updated_user_stmt);
            $updated_user_data = mysqli_fetch_assoc($updated_user_result);
            
            echo json_encode([
                'success' => true, 
                'message' => 'User updated successfully',
                'user_data' => $updated_user_data
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

mysqli_close($conn);
?>