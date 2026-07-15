<?php
$conn = mysqli_connect('localhost', 'root', 'Password1', 'mldb');
session_start();

if (isset($_POST['newPass'])) {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    $email = $_SESSION['user_email'];

    if ($newPassword === $confirmPassword) {
        $hashedPassword = md5($newPassword);
        $updateQuery = "UPDATE user_form SET password = '$hashedPassword' WHERE email = '$email'";

        // Execute the update query using your database connection
        $result = mysqli_query($conn, $updateQuery);

        if ($result) {
            // Password successfully changed
            $_SESSION['success_message'] = 'Password changed successfully!';
            echo '<script>window.location.href = "login_form.php";</script>';
            exit();
        } else {
            // Handle the case where the update query fails
            $_SESSION['error_message'] = 'Failed to change the password.';
            echo '<script>window.location.href = "login_form.php";</script>';
            exit();
        }
    } else {
        // Passwords do not match
        $_SESSION['error_message'] = 'Passwords do not match.';
        echo '<script>window.location.href = "login_form.php";</script>';
        exit();
    }
}

?>
