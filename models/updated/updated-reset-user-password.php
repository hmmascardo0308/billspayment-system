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
        $required_fields = ['id_number', 'username'];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) {
                echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
                exit;
            }
        }
        
        // Sanitize inputs
        $id_number = mysqli_real_escape_string($conn, trim($input['id_number']));
        $username = mysqli_real_escape_string($conn, trim($input['username']));
        $new_password = md5('Mlinc1234'); // Hash the default password
        
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
        
        // Check if user exists in user_form table
        $check_query = "SELECT id_number, email FROM mldb.user_form WHERE id_number = ? AND email = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "ss", $id_number, $username);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        
        // Update user password in user_form table
        $update_query = "UPDATE mldb.user_form 
                        SET password = ?,
                            modified_date = ?,
                            modified_by = ?
                        WHERE id_number = ? AND email = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "sssss", 
            $new_password, $modified_date, $modified_by, $id_number, $username
        );
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Check if any rows were affected
            if (mysqli_affected_rows($conn) > 0) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Password has been reset successfully to default: Mlinc1234',
                    'username' => $username
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No changes were made to the password']);
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