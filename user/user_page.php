<?php

// Start the session
session_start();

// Connect to the database
include '../config/config.php';

// Redirect to login page if the user is not logged in
if (!isset($_SESSION['user_type'])) {
    header("Location:../login_form.php");
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>User Dashboard</title>
   <!-- custom CSS file link  -->
   <link rel="stylesheet" href="../assets/css/user_page.css?v=<?php echo time(); ?>">
   <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <link rel="icon" href="../images/MLW logo.png" type="image/png">
</head>

<body>
   <div class="container">

      <div class="top-content">
         <div class="nav-container">
            <i id="menu-btn" class="fa-solid fa-bars"></i>
            <div class="usernav">
               <h4><?php echo $_SESSION['user_name'] ?></h4>
               <h4 style="margin-left:5px;"><?php echo "(".$_SESSION['user_email'].")" ?></h5>
            </div>
         </div>
      </div>
      <!-- Show and Hide Side Nav Menu -->
      <?php include '../templates/user/sidebar.php'; ?>
   </div>
</body>
<?php include '../templates/user/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Admin dropdown toggle (fix for .dropdown .dropdown-btn not opening)
    document.querySelectorAll('.dropdown').forEach(function(drop) {
        var btn = drop.querySelector('.dropdown-btn');
        var content = drop.querySelector('.dropdown-content');
        if (!btn || !content) return;

        // Ensure initial state
        content.style.display = content.style.display || 'none';

        // Toggle on button click
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // prevent other document click handlers from closing immediately
            // Close other dropdowns first
            document.querySelectorAll('.dropdown .dropdown-content').forEach(function(other) {
                if (other !== content) other.style.display = 'none';
            });
            // Toggle this one
            content.style.display = (content.style.display === 'block') ? 'none' : 'block';
            btn.classList.toggle('active');
        });
    });

    // Close any open dropdown when clicking elsewhere
    document.addEventListener('click', function () {
        document.querySelectorAll('.dropdown .dropdown-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.querySelectorAll('.dropdown .dropdown-btn').forEach(function(b) {
            b.classList.remove('active');
        });
    });

    // Optional: close on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown .dropdown-content').forEach(function(content) {
                content.style.display = 'none';
            });
            document.querySelectorAll('.dropdown .dropdown-btn').forEach(function(b) {
                b.classList.remove('active');
            });
        }
    });
});
</script>
</html>
