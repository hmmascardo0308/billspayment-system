<?php
// Connect to the database
include '../../config/config.php';

// Start the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_type'])) {
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated']);
    exit();
}

// Get the current user who is creating the new user
$created_by = '';
if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_email'])) {
    $created_by = $_SESSION['admin_email'];
} elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_email'])) {
    $created_by = $_SESSION['user_email'];
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

try {
    // Get form data
    $id_number = mysqli_real_escape_string($conn, trim($_POST['id_number']));
    $first_name = mysqli_real_escape_string($conn, trim($_POST['first_name']));
    $middle_name = mysqli_real_escape_string($conn, trim($_POST['middle_name']));
    $last_name = mysqli_real_escape_string($conn, trim($_POST['last_name']));
    $username = mysqli_real_escape_string($conn, trim($_POST['username'])); // This will be stored as email
    $password = mysqli_real_escape_string($conn, trim($_POST['default_password']));
    $user_type = mysqli_real_escape_string($conn, trim($_POST['user_type']));
    
    // Validate required fields
    if (empty($id_number) || empty($first_name) || empty($last_name) || empty($username) || empty($password) || empty($user_type)) {
        echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled']);
        exit();
    }
    
    // Check if ID number already exists
    $check_id_query = "SELECT id_number FROM mldb.user_form WHERE id_number = '$id_number'";
    $check_id_result = mysqli_query($conn, $check_id_query);
    
    if (mysqli_num_rows($check_id_result) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID Number already exists']);
        exit();
    }
    
    // Check if email/username already exists
    $check_email_query = "SELECT email FROM mldb.user_form WHERE email = '$username'";
    $check_email_result = mysqli_query($conn, $check_email_query);
    
    if (mysqli_num_rows($check_email_result) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username/Email already exists']);
        exit();
    }
    
    // Hash the password
    $hashed_password = md5($password);
    
    // Get current timestamp
    $date_created = date('Y-m-d H:i:s');
    
    // Prepare the INSERT query
    $insert_query = "INSERT INTO mldb.user_form (
        id_number, 
        first_name, 
        middle_name, 
        last_name, 
        email, 
        password, 
        user_type, 
        status, 
        date_created, 
        created_by
    ) VALUES (
        '$id_number',
        '$first_name',
        '$middle_name',
        '$last_name',
        '$username',
        '$hashed_password',
        '$user_type',
        'Active',
        '$date_created',
        '$created_by'
    )";
    
    // Execute the query
    if (mysqli_query($conn, $insert_query)) {
        echo json_encode([
            'status' => 'success', 
            'message' => 'User added successfully',
            'data' => [
                'id_number' => $id_number,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'username' => $username,
                'user_type' => $user_type,
                'status' => 'Active',
                'date_created' => $date_created,
                'created_by' => $created_by
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

// Close database connection
mysqli_close($conn);
?>