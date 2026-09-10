<?php
// change_password.php
require_once __DIR__ . '/config/config.php';
session_start();

if (isset($_POST['newPass'])) {
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Support both admin and regular user sessions
    $email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';

    if (empty($email)) {
        $_SESSION['error_message'] = 'Session expired. Please login again.';
        header('Location: login_form.php');
        exit();
    }

    if ($newPassword === $confirmPassword && $newPassword !== '') {
        // Prevent setting the same default password again
        if ($newPassword === 'Mlinc1234') {
            $_SESSION['error_message'] = 'Please choose a different password. The default password is not allowed.';
            header('Location: login_form.php');
            exit();
        }

        $hashedPassword = md5($newPassword);

        // Use prepared statement + correct schema
        $updateQuery = "UPDATE mldb.user_form SET password = ? WHERE email = ?";
        $stmt = mysqli_prepare($conn, $updateQuery);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $hashedPassword, $email);
            $result = mysqli_stmt_execute($stmt);

            if ($result) {
                $_SESSION['success_message'] = 'Password changed successfully! Please login with your new password.';
            } else {
                $_SESSION['error_message'] = 'Failed to change the password. Please try again.';
            }
            mysqli_stmt_close($stmt);
        } else {
            $_SESSION['error_message'] = 'Database error. Please try again.';
        }
    } else {
        $_SESSION['error_message'] = 'Passwords do not match.';
    }

    // Clear login session so user must re-login with new password
    unset($_SESSION['user_type']);
    unset($_SESSION['user_name']);
    unset($_SESSION['user_email']);
    unset($_SESSION['admin_name']);
    unset($_SESSION['admin_email']);
    unset($_SESSION['id_number']);
    unset($_SESSION['user_access_level']);
    unset($_SESSION['access_level']);

    header('Location: login_form.php');
    exit();
}

// If someone opens the page directly without POST
header('Location: login_form.php');
exit();
?>