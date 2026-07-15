<?php
include '../../config/config.php';

session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is authorized (allow both admin and user types)
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
        $required_fields = ['id_number', 'username', 'current_status', 'new_status'];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) {
                echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
                exit;
            }
        }
        
        // Sanitize inputs
        $id_number = mysqli_real_escape_string($conn, trim($input['id_number']));
        $username = mysqli_real_escape_string($conn, trim($input['username']));
        $current_status = mysqli_real_escape_string($conn, trim($input['current_status']));
        $new_status = mysqli_real_escape_string($conn, trim($input['new_status']));
        
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
        
        // Validate status values
        if (!in_array($new_status, ['Active', 'Inactive'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid status value']);
            exit;
        }
        
        // Check if user exists and current status matches using prepared statements
        $check_query = "SELECT id_number, email, status FROM mldb.user_form WHERE id_number = ? AND email = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "ss", $id_number, $username);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        
        $user_data = mysqli_fetch_assoc($check_result);
        
        // Verify current status matches what we expect
        if ($user_data['status'] !== $current_status) {
            echo json_encode(['success' => false, 'message' => 'Status has been changed by another user. Please refresh and try again.']);
            exit;
        }
        
        // Update user status in user_form table using prepared statements
        $update_query = "UPDATE mldb.user_form 
                        SET status = ?,
                            modified_date = ?,
                            modified_by = ?
                        WHERE id_number = ? AND email = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "sssss", 
            $new_status, $modified_date, $modified_by, $id_number, $username
        );
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Check if any rows were affected
            if (mysqli_affected_rows($conn) > 0) {
                echo json_encode([
                    'success' => true, 
                    'message' => "User status has been changed from '$current_status' to '$new_status' successfully",
                    'new_status' => $new_status,
                    'old_status' => $current_status,
                    'username' => $username
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No changes were made to the status']);
            }
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