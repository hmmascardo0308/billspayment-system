<?php
// Connect to the database

use FontLib\Table\Type\head;

include 'config/config.php';

// Start session only when safe to send headers (avoid warnings when embedded)
if (session_status() === PHP_SESSION_NONE) {
   if (!headers_sent()) {
      session_start();
   } else {
      // Headers already sent (embedded include). Try to start quietly to avoid warnings.
      @session_start();
   }
}

$isEmbeddedLogin = isset($EMBED_LOGIN_FORM) && $EMBED_LOGIN_FORM === true;

// Build stable app-relative URLs so redirects work in standalone and embedded modes.
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($appBasePath === '.' || $appBasePath === '/') {
   $appBasePath = '';
}
$loginUrl = $appBasePath . '/login_form.php';
$logoutUrl = $appBasePath . '/logout.php';
$dashboardUrl = $appBasePath . '/dashboard/';
$soaApprovalUrl = $appBasePath . '/dashboard/billspayment-soa/approval/soa-approval.php';
$soaReviewUrl = $appBasePath . '/dashboard/billspayment-soa/review/for-checking-review.php';

// Include shared header (scripts/styles used across the app)
if (!$isEmbeddedLogin) {
   @include_once __DIR__ . '/templates/header.php';
}

// Handle password change success/error messages FIRST before redirect check
if(isset($_SESSION['success_message']) || isset($_SESSION['error_message'])){
   // Don't redirect to dashboard if we have messages to show
   // This will be handled after showing the messages
} else {
   // Only check for redirect if there are no messages to show
   if(isset($_SESSION['user_type'])){
      header('location: ' . $dashboardUrl);
      exit();
   }
}

if (!$isEmbeddedLogin) {
   echo '<script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>';
   echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
   echo '<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>';
}

// Handle password change success/error messages FIRST before login processing
if(isset($_SESSION['success_message'])){
   echo "<script>
            window.onload = function() {
               Swal.fire({
                  title: 'Success!',
                  text: '".$_SESSION['success_message']."',
                  icon: 'success',
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  allowEnterKey: false,
                  showConfirmButton: true
               }).then((result) => {
                  if (result.isConfirmed) {
                     // Clear session and redirect to login page
                     fetch('".$logoutUrl."', {
                        method: 'POST'
                     }).then(() => {
                        window.top.location.href = '".$loginUrl."';
                     });
                  }
               });
            }
         </script>";
   unset($_SESSION['success_message']);
   // Clear user session data to prevent auto-redirect
   unset($_SESSION['user_type']);
   unset($_SESSION['user_name']);
   unset($_SESSION['user_email']);
   unset($_SESSION['admin_name']);
   unset($_SESSION['admin_email']);
} 
elseif(isset($_SESSION['error_message'])){
   echo "<script>
            window.onload = function() {
               Swal.fire({
                  title: 'Error!',
                  text: '".$_SESSION['error_message']."',
                  icon: 'error',
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  allowEnterKey: false,
                  showConfirmButton: true
               }).then((result) => {
                  if (result.isConfirmed) {
                     // Clear session and redirect to login page
                     fetch('".$logoutUrl."', {
                        method: 'POST'
                     }).then(() => {
                        window.top.location.href = '".$loginUrl."';
                     });
                  }
               });
            }
         </script>";
   unset($_SESSION['error_message']);
   // Clear user session data to prevent auto-redirect
   unset($_SESSION['user_type']);
   unset($_SESSION['user_name']);
   unset($_SESSION['user_email']);
   unset($_SESSION['admin_name']);
   unset($_SESSION['admin_email']);
} 
elseif(isset($_POST['submit'])){
   $email = mysqli_real_escape_string($conn, $_POST['email']);
   $pass = md5($_POST['password']);

   $current_day_and_time = date('Y-m-d H:i:s');
   $loginquery = "UPDATE mldb.user_form SET last_online = '$current_day_and_time' WHERE email = '$email'";
   $select = "SELECT * FROM mldb.user_form WHERE email = '$email' && password = '$pass'";
   $result = mysqli_query($conn, $select);
   // Get the current day and time.
   if(mysqli_num_rows($result) > 0){
      $row = mysqli_fetch_array($result);
      if($row['user_type'] == 'admin'){
         if($row['status'] == 'Inactive'){
            echo "<script>
                     window.onload = function() {
                        Swal.fire({
                           title: 'End-User is Inactive',
                           text: 'Please contact the system administrator.',
                           icon: 'error',
                           allowOutsideClick: false,
                           allowEscapeKey: false,
                           allowEnterKey: false,
                           showConfirmButton: true
                        });
                     }
                  </script>";
         }else{
            $loginresult = mysqli_query($conn, $loginquery);
            $_SESSION['admin_name'] =  $row['first_name'].' '.$row['middle_name'].' '.$row['last_name'];
            $_SESSION['admin_email'] = $row['email'];
            $_SESSION['id_number'] = $row['id_number'] ?? '';
            $_SESSION['user_type'] = $row['user_type'];
            $_SESSION['user_access_level'] = isset($row['access_level'])
               ? (int)$row['access_level']
               : (isset($row['acess_level']) ? (int)$row['acess_level'] : 0);
            // $_SESSION['user_roles'] = $row['roles'];
            echo "<script>
                  window.onload = function() {
                     const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                        backdrop: true,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        allowEnterKey: false,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                          toast.addEventListener('mouseenter', Swal.stopTimer)
                          toast.addEventListener('mouseleave', Swal.resumeTimer)
                        }
                      })
                      
                      Toast.fire({
                        icon: 'success',
                        title: 'Signed in successfully'
                      }).then(() => {
                        // Redirect to the generate_payment.php page.
                                    window.top.location.href = '".$dashboardUrl."';
                    });
                  }
               </script>";
         }
      }elseif($row['user_type'] == 'user'){
         if($row['status'] == 'Inactive'){
            echo "<script>
                     window.onload = function() {
                        Swal.fire({
                           title: 'End-User is Inactive',
                           text: 'Please contact the system administrator.',
                           icon: 'error',
                           allowOutsideClick: false,
                           allowEscapeKey: false,
                           allowEnterKey: false,
                           allowOutsideClick: false,
                           showConfirmButton: true
                        });
                     }
                  </script>";
         }else{
            $loginresult = mysqli_query($conn, $loginquery);
            $_SESSION['user_name'] =  $row['first_name'].' '.$row['middle_name'].' '.$row['last_name'];
            $_SESSION['user_email'] = $row['email'];
            $_SESSION['id_number'] = $row['id_number'] ?? '';
            $_SESSION['user_type'] = $row['user_type'];
            $_SESSION['user_access_level'] = isset($row['access_level'])
               ? (int)$row['access_level']
               : (isset($row['acess_level']) ? (int)$row['acess_level'] : 0);
            // Check if the password is "Password1"
            if($pass == md5("Mlinc1234")){
               // Show a modal to prompt the user to create another password
               echo '<script>
                  window.onload = function() {
                     Swal.fire({
                        title: "Change Password",
                        icon: "warning",
                        showCancelButton: true,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        allowEnterKey: false,
                        confirmButtonText: "OK",
                        cancelButtonText: "Cancel"
                     }).then((result) => {
                        if (result.isConfirmed) {
                           var changePasswordModal = document.getElementById("changePasswordModal");
                           changePasswordModal.style.display = "block";
                        } 
                        else {
                           // Send AJAX request to destroy session and redirect
                           fetch("'.$logoutUrl.'", {
                              method: "POST"
                           }).then(() => {
                              window.top.location.href = "'.$loginUrl.'";
                           });
                        }  
                     });
                  }
               </script>';
            } else {
               // Show a Sweetalert mixin with the success message
               if ($_SESSION['user_email'] === 'pera94005055') {
                  echo '<script>
                     window.onload = function() {
                        const Toast = Swal.mixin({
                           toast: true,
                           position: "top-end",
                           showConfirmButton: false,
                           timer: 2000,
                           backdrop: true,
                           allowOutsideClick: false,
                           allowEscapeKey: false,
                           allowEnterKey: false,
                           timerProgressBar: true,
                           didOpen: (toast) => {
                              toast.addEventListener("mouseenter", Swal.stopTimer)
                              toast.addEventListener("mouseleave", Swal.resumeTimer)
                           }
                           });
                           
                           Toast.fire({
                           icon: "success",
                           title: "Signed in successfully",
                           }).then(() => {
                           window.top.location.href = "'.$soaApprovalUrl.'";
                        });
                     }
                  </script>';
               }
               elseif ($_SESSION['user_email'] === 'cill17098209') {
                  echo '<script>
                     window.onload = function() {
                        const Toast = Swal.mixin({
                           toast: true,
                           position: "top-end",
                           showConfirmButton: false,
                           timer: 2000,
                           backdrop: true,
                           allowOutsideClick: false,
                           allowEscapeKey: false,
                           allowEnterKey: false,
                           timerProgressBar: true,
                           didOpen: (toast) => {
                              toast.addEventListener("mouseenter", Swal.stopTimer)
                              toast.addEventListener("mouseleave", Swal.resumeTimer)
                           }
                           });
                           
                           Toast.fire({
                           icon: "success",
                           title: "Signed in successfully",
                           }).then(() => {
                           window.top.location.href = "'.$soaReviewUrl.'";
                        });
                     }
                  </script>';
               }else{
                  echo '<script>
                     window.onload = function() {
                        const Toast = Swal.mixin({
                           toast: true,
                           position: "top-end",
                           showConfirmButton: false,
                           timer: 2000,
                           backdrop: true,
                           allowOutsideClick: false,
                           allowEscapeKey: false,
                           allowEnterKey: false,
                           timerProgressBar: true,
                           didOpen: (toast) => {
                              toast.addEventListener("mouseenter", Swal.stopTimer)
                              toast.addEventListener("mouseleave", Swal.resumeTimer)
                           }
                           });
                           
                           Toast.fire({
                           icon: "success",
                           title: "Signed in successfully",
                           }).then(() => {
                           window.top.location.href = "'.$dashboardUrl.'";
                        });
                     }
                  </script>';
               }
            }
         }
      }
   }else{
      echo '<script>
               window.onload = function() {
                  Swal.fire({
                     title: "Incorrect Username or Password",
                     text: "Please check your username and password. Try again.",
                     icon: "error",
                     allowOutsideClick: false,
                     allowEscapeKey: false,
                     allowEnterKey: false,
                     showConfirmButton: true
                  });
               }
            </script>';
   }
}

// Remove the duplicate session message handling code at the bottom
// Clear the session variables after displaying the modal
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Add this code to handle password change success/error messages
if(isset($_SESSION['success_message'])){
   echo "<script>
            window.onload = function() {
               Swal.fire({
                  title: 'Success!',
                  text: '".$_SESSION['success_message']."',
                  icon: 'success',
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  allowEnterKey: false,
                  showConfirmButton: true
               });
            }
         </script>";
   unset($_SESSION['success_message']);
}

if(isset($_SESSION['error_message'])){
   echo "<script>
            window.onload = function() {
               Swal.fire({
                  title: 'Error!',
                  text: '".$_SESSION['error_message']."',
                  icon: 'error',
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  allowEnterKey: false,
                  showConfirmButton: true
               });
            }
         </script>";
   unset($_SESSION['error_message']);
}

?>
<?php if (!$isEmbeddedLogin): ?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Login - ML Billspayment</title>
   <link rel="icon" href="images/MLW logo.png" type="image/png">
   <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap">
   <link rel="stylesheet" href="./assets/css/style.css?v=<?php echo time(); ?>">
   <link rel="stylesheet" href="./assets/css/login.css?v=<?php echo time(); ?>">
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
   <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
   <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
</head>
<body>
<?php else: ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap">
<link rel="stylesheet" href="./assets/css/login.css?v=<?php echo time(); ?>">
<!-- Embedded-mode tweaks: transparent background and close button inside card -->
<style>
   /* Embedded fragment should size to the card only (no full-page 100vh wrapper). */
   .login-page {
      background: transparent !important;
      min-height: auto !important;
      height: auto !important;
      width: auto !important;
      display: block !important;
   }
   /* center the login card without creating extra scrollable area */
   .login-panel {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 0 !important;
      background: transparent !important;
      min-height: auto !important;
   }
   /* close X that appears on the card when embedded */
   .login-card .embedded-close {
      position: absolute;
      top: 12px;
      right: 12px;
      z-index: 40;
      background: rgba(0,0,0,0.35);
      border: 0;
      color: #fff;
      width: 34px;
      height: 34px;
      border-radius: 999px;
      font-size: 20px;
      line-height: 1;
      cursor: pointer;
   }
</style>
<?php endif; ?>

<!-- Change Password Modal -->
<div id="changePasswordModal" class="change-password-modal" aria-hidden="true">
   <div class="change-password-modal-card">
      <header class="cpm-header">
         <h3>Create a New Password</h3>
         <button type="button" class="cpm-close" id="cpmClose" aria-label="Close">&times;</button>
      </header>
      <div class="cpm-sub">Please choose a strong password. (Press ESC to close)</div>
      <form action="change_password.php" method="post" id="changePasswordForm" class="cpm-form">
         <div class="field-group">
            <label for="new_password">New Password</label>
            <div class="field-input">
               <i class="fa-solid fa-lock fi-icon"></i>
               <input id="new_password" name="new_password" type="password" required autocomplete="new-password" placeholder="Enter new password">
               <button type="button" class="eye-btn small" data-target="new_password" aria-label="Toggle new password visibility"><i class="fa-solid fa-eye"></i></button>
            </div>
         </div>

         <div class="field-group">
            <label for="confirm_password">Confirm Password</label>
            <div class="field-input">
               <i class="fa-solid fa-lock fi-icon"></i>
               <input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password" placeholder="Repeat new password">
               <button type="button" class="eye-btn small" data-target="confirm_password" aria-label="Toggle confirm password visibility"><i class="fa-solid fa-eye"></i></button>
            </div>
         </div>

         <div class="cpm-actions">
            <button type="submit" name="newPass" class="login-submit-btn">Change Password</button>
         </div>
      </form>
   </div>
</div>

<!-- Split-panel Login Layout -->
<div class="login-page">

   <!-- Right: Form Panel (single-column layout) -->
   <div class="login-panel">
      <div class="login-card" role="dialog" aria-modal="true">
         <?php if ($isEmbeddedLogin): ?>
            <button type="button" class="embedded-close" data-bs-dismiss="modal" aria-label="Close">&times;</button>
         <?php endif; ?>
         <div class="login-panel-brand">
            <img src="./images/MLW Logo.png" alt="ML Logo" class="brand-logo">
            
         </div>
         <p class="card-title">Welcome Back</p>
         <p class="card-sub">Sign in to your account to continue</p>
         <div class="accent-bar"></div>

         <form action="" method="post" id="loginForm">

            <!-- Username -->
            <div class="field-group">
               <label for="email"><i class="fa-solid fa-user" style="margin-right:6px;color:#d70c0c;"></i>Username</label>
               <div class="field-input">
                  <i class="fa-solid fa-user fi-icon"></i>
                  <input
                     id="email"
                     type="text"
                     name="email"
                     required
                     placeholder="e.g. JUANDELACRUZ"
                     autocomplete="off"
                     oninput="this.value = this.value.toUpperCase()"
                     value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($_COOKIE['saved_username']) ? htmlspecialchars($_COOKIE['saved_username']) : ''); ?>"
                  >
               </div>
            </div>

            <!-- Password -->
            <div class="field-group">
               <label for="password"><i class="fa-solid fa-lock" style="margin-right:6px;color:#d70c0c;"></i>Password</label>
               <div class="field-input">
                  <i class="fa-solid fa-lock fi-icon"></i>
                  <input
                     id="password"
                     type="password"
                     name="password"
                     required
                     placeholder="Enter your password"
                     autocomplete="off"
                  >
                  <button type="button" class="eye-btn" id="togglePassword" aria-label="Toggle password visibility">
                     <i class="fa-solid fa-eye"></i>
                  </button>
               </div>
            </div>

            <!-- Remember username -->
            <div class="save-row">
               <input type="checkbox" id="save_as" name="save_as" <?php echo isset($_COOKIE['saved_username']) ? 'checked' : ''; ?>>
               <label for="save_as">Remember my username</label>
            </div>

            <!-- Submit -->
            <button type="submit" name="submit" class="login-submit-btn">
               <i class="fa-solid fa-right-to-bracket" style="margin-right:8px;"></i>LOGIN
            </button>

            <div class="login-back">
               <a href="index.php"><i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i>Back to home</a>
            </div>

         </form>
      </div>
   </div>

</div>
<script>
// Eye toggle
document.addEventListener('DOMContentLoaded', function () {
   var toggle = document.getElementById('togglePassword');
   var pwd    = document.getElementById('password');
   if (toggle && pwd) {
      toggle.addEventListener('click', function () {
         var isText = pwd.getAttribute('type') === 'text';
         pwd.setAttribute('type', isText ? 'password' : 'text');
         var icon = toggle.querySelector('i');
         if (icon) {
            icon.classList.toggle('fa-eye', isText);
            icon.classList.toggle('fa-eye-slash', !isText);
         }
      });
   }

   // Load remembered username from localStorage
   var emailInput   = document.getElementById('email');
   var saveCheckbox = document.getElementById('save_as');
   try {
      var saved = localStorage.getItem('bp_saved_username');
      if (saved && emailInput && !emailInput.value) {
         emailInput.value = saved;
      }
   } catch (e) {}

   // Save / clear on submit
   var form = document.getElementById('loginForm');
   if (form) {
      form.addEventListener('submit', function () {
         try {
            if (saveCheckbox && saveCheckbox.checked && emailInput && emailInput.value) {
               localStorage.setItem('bp_saved_username', emailInput.value);
               document.cookie = 'saved_username=' + encodeURIComponent(emailInput.value) + '; path=/; max-age=' + (60 * 60 * 24 * 30);
            } else {
               localStorage.removeItem('bp_saved_username');
               document.cookie = 'saved_username=; path=/; max-age=0';
            }
         } catch (e) {}
      });
   }

   // ESC closes change-password modal
   var modal = document.getElementById('changePasswordModal');
   document.addEventListener('keydown', function (e) {
      if ((e.key === 'Escape' || e.keyCode === 27) && modal) {
         modal.style.display = 'none';
      }
   });
   
   // Modal close button
   var cpmClose = document.getElementById('cpmClose');
   if (cpmClose && modal) {
      cpmClose.addEventListener('click', function () {
         modal.style.display = 'none';
      });
   }

   // Eye toggles for change-password fields
   var eyeBtns = document.querySelectorAll('.change-password-modal .eye-btn');
   eyeBtns.forEach(function(btn){
      btn.addEventListener('click', function(){
         var targetId = btn.getAttribute('data-target');
         var input = document.getElementById(targetId);
         if (!input) return;
         var isText = input.getAttribute('type') === 'text';
         input.setAttribute('type', isText ? 'password' : 'text');
         var ic = btn.querySelector('i');
         if(ic){
            ic.classList.toggle('fa-eye', isText);
            ic.classList.toggle('fa-eye-slash', !isText);
         }
      });
   });
});
</script>

<?php if (!$isEmbeddedLogin) { @include_once __DIR__ . '/templates/footer.php'; } ?>
<?php if (!$isEmbeddedLogin): ?>
</body>
</html>
<?php endif; ?>
