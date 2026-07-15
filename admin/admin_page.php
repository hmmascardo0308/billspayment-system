<?php

   session_start();

   if (!isset($_SESSION['admin_name'])) {
      header('location:../login_form.php');
   }

?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Admin Dashboard</title>
   <!-- custom CSS file link  -->
   <link rel="stylesheet" href="../assets/css/admin_page.css?v=<?php echo time(); ?>">
   <link rel="icon" href="../images/MLW logo.png" type="image/png">
</head>

<body>
   <div class="container">
      <div class="top-content">
         <div class="usernav">
               <h4 style="margin-right: 0.5rem; font-size: 1rem;"><?php echo $_SESSION['admin_name'] ?></h4>
               <h5 style="font-size: 1rem;"><?php echo "- ".$_SESSION['admin_email']."" ?></h5>
         </div>
         <?php include '../templates/admin/sidebar.php'; ?>
      </div>
   </div>
</body>

</html>