<?php
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
session_start(); 
if(isset($_POST['submit'])){
   $idNumber = mysqli_real_escape_string($conn, $_POST['idNum']);
   $fname = mysqli_real_escape_string($conn, $_POST['fname']);
   $mname = mysqli_real_escape_string($conn, $_POST['mname']);
   $lname = mysqli_real_escape_string($conn, $_POST['lname']);
   $email = mysqli_real_escape_string($conn, $_POST['email']);
   $pass = md5($_POST['password']);
   $cpass = md5($_POST['cpassword']);
   $user_type = $_POST['user_type'];

   $select = " SELECT * FROM user_form WHERE email = '$email' && password = '$pass' ";

   $result = mysqli_query($conn, $select);
   if(mysqli_num_rows($result) > 0){
      $error[] = 'user already exist!';
   }else{
      if($pass != $cpass){
         $error[] = 'password not matched!';
      }else{
         $insert = "INSERT INTO user_form(id_number,first_name,middle_name,last_name, email, password, user_type) VALUES('$idNumber','$fname','$mname','$lname','$email','$pass','$user_type')";
         mysqli_query($conn, $insert);
         header('location:admin/admin_page.php');
      }
   }
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>register form</title>  
   <!-- custom css file link  -->
   <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
</head>
<body> 
   <div class="form-container">
      <form action="" method="post">
         <div class="logo">
            <img src="./images/MLW Logo.png" alt="logo">
         </div>
         <h3>Register now</h3>
         <?php
         if(isset($error)){
            foreach($error as $error){
               echo '<span class="error-msg">'.$error.'</span>';
            };
         };
         ?>
         <select name="user_type">
            <option value="user">user</option>
            <option value="admin">admin</option>
            </select>
            <input type="text" name="idNum" id="idNum" required placeholder="ID Number" autocomplete="off">
            <input type="text" name="fname" id="fname" required placeholder="Firstname" autocomplete="off">
            <input type="text" name="mname" id="mname" placeholder="Middle Name" autocomplete="off">
            <input type="text" name="lname" id="lname" placeholder="Lastname" autocomplete="off">
            <input type="text" name="email" id="email" required placeholder="Username" autocomplete="off">
            <input id="psw" type="password" name="password" required placeholder="Enter your password" onInput="check()" 
                  pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters" autocomplete="off">
            <div id="message">
               <h3>Password must contain the following:</h3>
               <p id="letter" class="invalid">A <b>lowercase</b> letter</p>
               <p id="capital" class="invalid">A <b>capital (uppercase)</b> letter</p>
               <p id="number" class="invalid">A <b>number</b></p>
               <p id="length" class="invalid">Minimum <b>8 characters</b></p>
            </div>
            <input type="password" name="cpassword" id="cpass" required placeholder="Confirm your password" autocomplete="off">
            <input type="submit" name="submit" value="register now" class="form-btn">  
         </form>

      <script>
         var myInput = document.getElementById("psw");
         var letter = document.getElementById("letter");
         var capital = document.getElementById("capital");
         var number = document.getElementById("number");
         var length = document.getElementById("length");
         // When the user clicks on the password field, show the message box
         myInput.onfocus = function() {
            document.getElementById("message").style.display = "block";
         }
         // When the user clicks outside of the password field, hide the message box
         myInput.onblur = function() {
            document.getElementById("message").style.display = "none";
         }
         // When the user starts to type something inside the password field
         myInput.onkeyup = function() {
            // Validate lowercase letters
            var lowerCaseLetters = /[a-z]/g;
            if(myInput.value.match(lowerCaseLetters)) {  
               letter.classList.remove("invalid");
               letter.classList.add("valid");
            } else {
               letter.classList.remove("valid");
               letter.classList.add("invalid");
            } 
            // Validate capital letters
            var upperCaseLetters = /[A-Z]/g;
            if(myInput.value.match(upperCaseLetters)) {  
               capital.classList.remove("invalid");
               capital.classList.add("valid");
            } else {
               capital.classList.remove("valid");
               capital.classList.add("invalid");
            }
            // Validate numbers
            var numbers = /[0-9]/g;
            if(myInput.value.match(numbers)) {  
               number.classList.remove("invalid");
               number.classList.add("valid");
            } else {
               number.classList.remove("valid");
               number.classList.add("invalid");
            }
            // Validate length
            if(myInput.value.length >= 8) {
               length.classList.remove("invalid");
               length.classList.add("valid");
            } else {
               length.classList.remove("valid");
               length.classList.add("invalid");
            }
         }
      </script>
   </div>
</body>
</html>