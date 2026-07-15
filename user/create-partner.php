<?php session_start(); 
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
if(!isset($_SESSION['user_name'])){
   header('location:login_form.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Partner</title>
</head>
<body>
    <div class="container">
        <form class="inputs" action="" method="POST">
            <input type="text" name="partner_name" placeholder="Partner Name" required>
            <input type="text" name="partner_id" placeholder="Partner ID"required>
            <input type="submit" name="save" value="Save">
        </form>
    </div>
    <?php
        if(isset($_POST['save'])){
            $partner_name = mysqli_real_escape_string($conn, $_POST['partner_name']);
            $partner_id = mysqli_real_escape_string($conn, $_POST['partner_id']);

            $select = " SELECT * FROM partner_masterfile WHERE partner_name = '$partner_name' && partner_id = '$partner_id' ";  
            $result = mysqli_query($conn, $select);
            if(mysqli_num_rows($result) > 0){
                echo '<script type="text/javascript">';
                echo ' alert("Partner Already Exist")';  
                echo '</script>';
             }else{
            $insert = "INSERT INTO partner_masterfile(partner_name,partner_id) VALUES('$partner_name','$partner_id')";
            mysqli_query($conn, $insert);
                echo '<script type="text/javascript">';
                echo ' alert("Successfully Saved")';  
                echo '</script>';
            }
        }
    ?>
</body>
</html>