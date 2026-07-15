<?php

session_start();

if (!isset($_SESSION['admin_name'])) {
   header('location:../login_form.php');
}

require_once __DIR__ . '/../config/config.php';

// Handle registration form submission
if (isset($_POST['submit'])) {
   // Sanitize user input
   $idNumber = mysqli_real_escape_string($conn, $_POST['idNum']);
   $fname = mysqli_real_escape_string($conn, $_POST['fname']);
   $mname = mysqli_real_escape_string($conn, $_POST['mname']);
   $lname = mysqli_real_escape_string($conn, $_POST['lname']);
   $email = mysqli_real_escape_string($conn, $_POST['email']);
   $password = md5(isset($_POST['password']) ? $_POST['password'] : 'Mlinc1234'); // Set default password as "Password1"
   $cpassword = md5(isset($_POST['cpassword']) ? $_POST['cpassword'] : 'Mlinc1234'); // Set default password as "Password1"
   $user_type = $_POST['user_type'];

   // Variable for storing errors
   $errors = array();

   // Check for existing user
   $select = "SELECT * FROM user_form WHERE email = '$email' OR id_number = '$idNumber'";
   $result = mysqli_query($conn, $select);

   // Validate user input
   if (mysqli_num_rows($result) > 0) {
       $errors[] = 'User already exists!';
   } else if ($password != $cpassword) {
       $errors[] = 'Passwords do not match!';
   } else {
       // Insert new user into database
       $insert = "INSERT INTO user_form(id_number, first_name, middle_name, last_name, email, password, user_type) VALUES ('$idNumber', '$fname', '$mname', '$lname', '$email', '$password', '$user_type')";
       if (mysqli_query($conn, $insert)) {
           // Show success message using SweetAlert
           echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
           echo "<script>
               window.onload = function() {
                   Swal.fire({
                       title: 'Success!',
                       text: 'User registered successfully!',
                       icon: 'success',
                       confirmButtonText: 'Ok',
                   }).then((result) => {
                       if (result.value) {
                           window.location.href = 'userLog.php';
                       }
                   });
               }
           </script>";
       } else {
           // Handle insert error
           $errors[] = 'Error registering user.';
       }
   }// If there are errors, display them using SweetAlert
   if (!empty($errors)) {
      echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
      echo "<script>
      window.onload = function() {
          Swal.fire({
              title: 'Error!',
              text: '" . implode("<br>", $errors) . "',
              icon: 'error',
              confirmButtonText: 'Ok'
          });
        }
      </script>";
  }
}

// Handle search form submission
if (isset($_POST['search'])) {
    $search = $_POST['search-input'];

    if (!empty($search)) { // Check if search input is not empty
        $query = "SELECT * FROM user_form WHERE id_number LIKE '%$search%' OR  first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR middle_name LIKE '%$search%' OR email LIKE '%$search%' OR user_type LIKE '%$search%'";
        $result = mysqli_query($conn, $query);

        if ($result) {
            $searchResults = mysqli_fetch_all($result, MYSQLI_ASSOC);
        } else {
            $searchResults = array(); // Empty array if there is an error
            echo "Error: " . mysqli_error($conn); // Display the SQL error message
        }
    } else {
        $searchResults = array(); // Empty array if search input is empty
    }
} else {
    $searchResults = array(); // Empty array if no search is performed
}

// Check if the form is submitted
if (isset($_POST['update-user'])) {
   // Retrieve the form data
   $edit_user_type = isset($_POST['edit_user_type']) ? $_POST['edit_user_type'] : '';
   $edit_user_id = $_POST['edit_user_id'];
   $edit_idNum = $_POST['edit_idNum'];
   $edit_first_name = $_POST['edit_first_name'];
   $edit_middle_name = $_POST['edit_middle_name'];
   $edit_last_name = $_POST['edit_last_name'];
   $edit_email = $_POST['edit_email'];
   $edit_password = md5($_POST['password']);

   // Perform the update operation on the database
   // Replace the following code with your actual database update query
   $sql = "UPDATE user_form SET user_type='$edit_user_type', id_number = '$edit_idNum', first_name='$edit_first_name', last_name='$edit_last_name', middle_name='$edit_middle_name', email='$edit_email', password = '$edit_password' WHERE id = $edit_user_id";

   // Execute the update query
   if (mysqli_query($conn, $sql)) {
       // User updated successfully
       echo "User updated successfully.";
   } else {
       // Error occurred while updating user
       echo "Error: " . mysqli_error($conn);
   }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Users</title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../assets/css/admin_page.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../assets/images/MLW logo.png" type="image/png">
</head>

<body>
    <div class="container">
        <div class="top-content">
        <div class="usernav">
                    <h4><?php echo $_SESSION['admin_name'] ?></h4>
                    <h5 style="margin-left:5px;"><?php echo "(".$_SESSION['admin_email'].")" ?></h5>
                </div>
            <div class="btn-nav">
                <ul class="nav-list">
                    <li><a href="admin_page.php">HOME</a></li>
                    <li class="dropdown">
                        <button class="dropdown-btn">Import File</button>
                        <div class="dropdown-content">
                        <a id="user" href="billspaymentImportFile.php">BILLSPAYMENT TRANSACTION</a>
                        </div>
                    </li>
                    <li class="dropdown">
                        <button class="dropdown-btn">Transaction</button>
                        <div class="dropdown-content">
                        <a id="user" href="billspaymentSettlement.php">SETTLEMENT</a>
                        </div>
                    </li>
                    <li class="dropdown">
                     <button class="dropdown-btn">Report</button>
                     <div class="dropdown-content">
                        <a id="user" href="billspaymentReport.php">BILLS PAYMENT</a>
                        <a id="user" href="dailyVolume.php">DAILY VOLUME</a>

                     </div>
                  </li>
                    <li class="dropdown">
                        <button class="dropdown-btn">MAINTENANCE</button>
                        <div class="dropdown-content">
                        <a id="user" href="userLog.php">USER</a>
                        <a id="user" href="partnerLog.php">PARTNER</a>
                        <a id="user" href="natureOfBusinessLog.php">NATURE OF BUSINESS</a>
                        <a id="user" href="bankLog.php">BANK</a>
                        </div>
                    </li>
                    <li><a href="../logout.php">LOGOUT</a></li>
                </ul>
            </div>
        </div>
        <div class="s-div">
            <div id="search-div">
                <form method="POST" class="form-group">
                    <div class="left-div">
                        <input type="text" id="search-input" name="search-input" value="<?php if (isset($_POST['search'])) echo $_POST['search']; ?>" placeholder="Search...">
                        <button type="submit" id="search" name="search">Search</button>
                    </div>
                    <div class="right-div">
                        <button type="button" id="add" name="add" onclick="showModal('register-modal')">Add</button>
                        <button type="button" id="edit" name="edit" onclick="showEditModal()">Edit</button>
                        <button type="submit" id="delete" name="delete" onclick="deleteRow()">Delete</button>

                    </div>
                </form>

                <?php if (!empty($searchResults) && isset($_POST['search']) && !empty($search)) : ?>
                    <div id="search-results">
                        <h3>SEARCH RESULT</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th style="display:none;"></th>
                                    <th>ID Number</th>
                                    <th>First Name</th>
                                    <th>Middle Name</th>
                                    <th>Last Name</th>
                                    <th>Username</th>
                                    <th style="display:none;">Password</th>
                                    <th>User Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($searchResults as $result) : ?>
                                    <tr onclick="selectRow(this)">
                                       <td name="id" style="text-align:left; padding-left:10px; display:none;"><?php echo $result['id']; ?></td>
                                       <td style="text-align:left; padding-left:10px;"><?php echo $result['id_number']; ?></td>
                                       <td style="text-align:left; padding-left:10px;"><?php echo $result['first_name']; ?></td>
                                       <td style="text-align:left; padding-left:10px;"><?php echo $result['middle_name']; ?></td>
                                       <td style="text-align:left; padding-left:10px;"><?php echo $result['last_name']; ?></td>
                                       <td style="text-align:left; padding-left:10px; "><?php echo $result['email']; ?></td>
                                       <td style="text-align:left; padding-left:10px; display:none;"><?php echo $result['password']; ?></td>
                                       <td style="text-align:left; padding-left:10px; "><?php echo $result['user_type']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <table id="users-table">
                <h3>ALL USERS</h3>
                <thead>
                    <tr>
                        <th style="display:none;"></th>
                        <th>ID Number</th>
                        <th>First Name</th>
                        <th>Middle Name</th>
                        <th>Last Name</th>
                        <th>Username</th>
                        <th style="display:none;">Password</th>
                        <th>User Type</th>
                    </tr>
                </thead>
                <tbody>
                     <?php
                     $query = "SELECT * FROM user_form";
                     $result = mysqli_query($conn, $query);
                     if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            ?>
                            <tr onclick="selectRow(this)">
                                 <td name="id" style="text-align:left; padding-left:10px; display:none;"><?php echo $row['id']; ?></td>
                                 <td style="text-align:left; padding-left:10px;"><?php echo $row['id_number']; ?></td>
                                 <td style="text-align:left; padding-left:10px;"><?php echo $row['first_name']; ?></td>
                                 <td style="text-align:left; padding-left:10px;"><?php echo $row['middle_name']; ?></td>
                                 <td style="text-align:left; padding-left:10px;"><?php echo $row['last_name']; ?></td>
                                 <td style="text-align:left; padding-left:10px; "><?php echo $row['email']; ?></td>
                                 <td style="text-align:left; padding-left:10px; display:none;"><?php echo $row['password']; ?></td>
                                 <td style="text-align:left; padding-left:10px; "><?php echo $row['user_type']; ?></td>
                            </tr>
                     <?php
                        }
                    }
                    ?>
                  </tbody>
               </table>
            </div>
         </div>
<!-- Register Modal -->
<div id="register-modal" class="modal" onmouseleave="clearModalFields()">
   <div class="register_modal-content">
      <span class="close" onclick="hideModal('register-modal')">&times;</span>
      <form action="" method="post">
         <div class="logo">
            <img src="../images/MLW Logo.png" alt="logo">
         </div>
         <h3>Register</h3>
         <div class="inputs-div">
            <div class="inputs">
            <div class="input-container">
            <label for="user-type">User Type</label>

               <select name="user_type" id="user-type">
                  <option value="" disabled selected></option>
                  <option value="user">User</option>
                  <option value="admin">Admin</option>
               </select>

               </div>

               <div class="input-container">
               <label for="idNum">ID Number</label>
               <input type="text" name="idNum" id="idNum" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="fname">Firstname</label>
               <input type="text" name="fname" id="fname" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="mname">Middle Name</label>
               <input type="text" name="mname" id="mname" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="lname">Lastname</label>
               <input type="text" name="lname" id="lname" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="email">Username</label>
               <input type="text" name="email" id="email" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="psw">Enter your password</label>
               <input id="psw" type="text" name="password" required oninput="check()"
                  pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters"
                  autocomplete="off" value="Mlinc1234" readonly>
            </div>

            <div id="message" style="display: none;">
               <h3>Password must contain the following:</h3>
               <p id="letter" class="invalid">A <b>lowercase</b> letter</p>
               <p id="capital" class="invalid">A <b>capital (uppercase)</b> letter</p>
               <p id="number" class="invalid">A <b>number</b></p>
               <p id="length" class="invalid">Minimum <b>8 characters</b></p>
            </div>

            <div class="input-container">
            <label for="cpass">Confirm your password</label>
               <input type="text" name="cpassword" id="cpass" required autocomplete="off" value="Mlinc1234" readonly>
            </div>

            </div>
         </div>
         <center><button type="submit" id="register" name="submit" class="form-btn">REGISTER NOW</button></center>
      </form>
   </div>
</div>
<!-- Edit User Modal -->
<div id="edit-modal" class="modal" onmouseleave="clearModalFields()">
   <div class="edit_modal-content">
      <span class="close" onclick="hideModal('edit-modal')">&times;</span>
      
      <form method="POST" action="">
         <div class="logo">
            <img src="../images/MLW Logo.png" alt="logo">
         </div>
         <center>
      <h3>Edit User</h3>
      </center>
         <div class="inputs-div">
            <div class="inputs">
            <div class="input-container">
            <label for="edit_user_type">User Type</label>
               <select id="edit_user_type" name="edit_user_type">
                  <option value="" disabled selected>Select User Type</option>
                  <option value="admin">Admin</option>
                  <option value="user">User</option>
               </select>
               </div>

               <div class="input-container">
               <input type="hidden" id="edit_user_id" name="edit_user_id">
               </div>

               <div class="input-container">
               <label for="edit_idNum">ID Number</label>
               <input type="text" name="edit_idNum" id="edit_idNum" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="edit_first_name">First Name</label>
               <input type="text" id="edit_first_name" name="edit_first_name" required autocomplete="off">
               </div>

               <div class="input-container">
               <label for="edit_middle_name">Middle Name</label>
               <input type="text" id="edit_middle_name" name="edit_middle_name" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="edit_last_name">Last Name</label>
               <input type="text" id="edit_last_name" name="edit_last_name" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="edit_email">Username</label>
               <input type="text" id="edit_email" name="edit_email" autocomplete="off">
               </div>

               <div class="input-container">
               <label for="password">Password</label>
               <input id="password" type="password" name="password" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters" autocomplete="off">
               </div>
               <center><button type="submit" id="update-user" name="update-user">Update User</button></center>
            </div>
         </div>
      </form>
   </div>
</div>

<script>
    
    function clearModalFields() {
   var inputs = document.getElementById('register-modal').getElementsByTagName('input');
   for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      if (input.type !== 'password' && input.id !== 'cpass') {
         input.value = '';
      }
   }
}


   function showModal(modalId) {
      var modal = document.getElementById(modalId);
      modal.style.display = 'block';
   }

   function hideModal(modalId) {
      var modal = document.getElementById(modalId);
      modal.style.display = 'none';
   }

   function selectRow(row) {
      var selectedRow = document.querySelector('.selected');

      if (selectedRow) {
         selectedRow.classList.remove('selected');
         selectedRow.style.backgroundColor = '';
         selectedRow.style.color = '';
      }

      if (selectedRow !== row) {
         row.classList.add('selected');
         row.style.backgroundColor = 'red';
         row.style.color = 'white';
      }
   }

   function populateModalInputs() {
      var selectedRow = document.querySelector('.selected');

      if (selectedRow) {
         var inputs = document.getElementById('edit-modal').getElementsByTagName('input');
         var select = document.getElementById('edit_user_type');
         inputs[0].value = selectedRow.cells[0].textContent;
         inputs[1].value = selectedRow.cells[1].textContent;
         inputs[2].value = selectedRow.cells[2].textContent;
         inputs[3].value = selectedRow.cells[3].textContent;
         inputs[4].value = selectedRow.cells[4].textContent;
         inputs[5].value = selectedRow.cells[5].textContent;
         inputs[6].value = selectedRow.cells[6].textContent;
         select.value = selectedRow.cells[7].textContent.toLowerCase();

         showModal('edit-modal');
      }
   }

   function showEditModal() {
   var selectedRow = document.querySelector('#users-table tr.selected');
   var selectedRow2 = document.querySelector('#search-results tr.selected');

      if (selectedRow) {
         var cells = selectedRow.getElementsByTagName('td');
         document.getElementById('edit_user_id').value = cells[0].innerText;
         document.getElementById('edit_idNum').value = cells[1].innerText;
         document.getElementById('edit_first_name').value = cells[2].innerText;
         document.getElementById('edit_middle_name').value = cells[3].innerText;
         document.getElementById('edit_last_name').value = cells[4].innerText;
         document.getElementById('edit_email').value = cells[5].innerText;
         document.getElementById('password').value = ''; // Set password field value to empty string
         document.getElementById('edit_user_type').value = cells[7].innerText;

         showModal('edit-modal');
      } else if (selectedRow2) {
         var cells = selectedRow2.getElementsByTagName('td');
         document.getElementById('edit_user_id').value = cells[0].innerText;
         document.getElementById('edit_idNum').value = cells[1].innerText;
         document.getElementById('edit_first_name').value = cells[2].innerText;
         document.getElementById('edit_middle_name').value = cells[3].innerText;
         document.getElementById('edit_last_name').value = cells[4].innerText;
         document.getElementById('edit_email').value = cells[5].innerText;
         document.getElementById('password').value = ''; // Set password field value to empty string
         document.getElementById('edit_user_type').value = cells[7].innerText;

         showModal('edit-modal');
      } else {
         alert('Please select a user to edit.');
      }
   }
   function deleteRow() {
  var selectedRow = document.querySelector('.selected');

  if (selectedRow) {
    var confirmation = confirm('Are you sure you want to delete this user?');
    if (confirmation) {
      var id = selectedRow.cells[0].textContent;
      var formData = new FormData();
      formData.append('id', id);
      formData.append('user', 'true');

      fetch('delete.php', {
        method: 'POST',
        body: formData
      })
        .then(response => {
          if (!response.ok) {
            throw new Error('Failed to delete user.');
          }
          return response.text();
        })
        .then(data => {
            console.log(data); 
            if (data.trim() === 'success') { 
                selectedRow.remove();
                alert('User deleted successfully.');
            } else {
                throw new Error('Failed to delete user.');
            }
        })
        .catch(error => {
          console.log(error);
          alert('An error occurred. Please try again.');
        });
    }
  } else {
    alert('Please select a row to delete.');
  }
}

   function storeValues() {
      populateModalInputs();
      showModal('register-modal');
   }
</script>
</body>
</html>
