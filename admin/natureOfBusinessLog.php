<?php

session_start();

if (!isset($_SESSION['admin_name'])) {
   header('location:../login_form.php');
}

require_once __DIR__ . '/../config/config.php';

// Handle registration form submission
if (isset($_POST['submit'])) {
   // Sanitize user input
   $description = mysqli_real_escape_string($conn, $_POST['description']);

   // Variable for storing errors
   $errors = array();

   // Check for existing user
   $select = "SELECT * FROM mldb.nature_of_business WHERE description = '$description'";
   $result = mysqli_query($conn, $select);

   // Validate user input
   if (mysqli_num_rows($result) > 0) {
       $errors[] = 'This name already exists!';
   } else {
       // Insert new user into database
       $insert = "INSERT INTO mldb.nature_of_business(description) VALUES ('$description')";
       if (mysqli_query($conn, $insert)) {
           // Show success message using SweetAlert
           echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
           echo "<script>
               window.onload = function() {
                   Swal.fire({
                       title: 'Success!',
                       text: 'Added successfully!',
                       icon: 'success',
                       confirmButtonText: 'Ok',
                   }).then((result) => {
                       if (result.value) {
                           window.location.href = 'natureOfBusinessLog.php';
                       }
                   });
               }
           </script>";
       } else {
           // Handle insert error
           $errors[] = 'Error adding.';
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
        $query = "SELECT * FROM mldb.nature_of_business WHERE id LIKE '%$search%' OR  description LIKE '%$search%'";
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


if (isset($_POST['update-description'])) {
   
   $edit_description = $_POST['edit-description'];
   $edit_id = $_POST['edit-id'];

   $sql = "UPDATE mldb.nature_of_business SET description='$edit_description'  WHERE id = $edit_id";

   if (mysqli_query($conn, $sql)) {
       //  updated successfully
       echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
           echo "<script>
               window.onload = function() {
                   Swal.fire({
                       title: 'Success!',
                       text: 'Updated successfully!',
                       icon: 'success',
                       confirmButtonText: 'Ok',
                   }).then((result) => {
                       if (result.value) {
                           window.location.href = 'natureOfBusinessLog.php';
                       }
                   });
               }
           </script>";
   } else {
       // Error occurred while updating 
       //echo "Error: " . mysqli_error($conn);
       echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>';
       echo "<script>
       window.onload = function() {
          Swal.fire({
              title: 'Error!',
              text: '" .  mysqli_error($conn) . "',
              icon: 'error',
              confirmButtonText: 'Ok'
          });
        }
       </script>";
   }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Nature of Business</title>
    <!-- custom CSS file link  -->
    <link rel="stylesheet" href="../assets/css/natureOfBusinessLog.css?v=<?php echo time(); ?>">
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
                                    <th>ID</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($searchResults as $result) : ?>
                                    <tr onclick="selectRow(this)">
                                       <td name="id" style="text-align:left; padding-left:10px; display:none;"><?php echo $result['id']; ?></td>
                                       <td style="text-align:left; padding-left:10px;"><?php echo $result['id']; ?></td>
                                       <td style="text-align:left; padding-left:10px;"><?php echo $result['description']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </center>
                    </div>
                <?php endif; ?>
            </div>
            <table id="users-table">
                <h3>Nature of Business</h3>
                <thead>
                    <tr>
                        <th>ID Number</th>
                        <th>Nature of Business</th>
                    </tr>
                </thead>
                <tbody>
                     <?php
                     $query = "SELECT * FROM nature_of_business";
                     $result = mysqli_query($conn, $query);
                     if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            ?>
                            <tr onclick="selectRow(this)">
                                 <td name="id" style="text-align:left; padding-left:10px; display:none;"><?php echo $row['id']; ?></td>
                                 <td style="text-align:left; padding-left:10px;"><?php echo $row['id']; ?></td>
                                 <td style="text-align:left; padding-left:10px;"><?php echo $row['description']; ?></td>
                            </tr>
                     <?php
                        }
                    }
                    ?>
                  </tbody>
               </table>
            </div>
         </div>
<!-- Add nature of business Modal -->
<div id="register-modal" class="modal">
   <div class="register_modal-content">
      <span class="close" onclick="hideModal('register-modal')">&times;</span>
      <form action="" method="post">
         <div class="logo">
            <img src="../images/MLW Logo.png" alt="logo">
         </div>
         <h3>Add Nature of Business</h3>
         <div class="inputs-div">
            <div class="inputs">

               <div class="input-container">
                    <label for="description">Description</label>
                    <input type="text" name="description" id="description" required autocomplete="off">
               </div>

            </div>
         </div>
         <center><button type="submit" id="register" name="submit" class="form-btn">ADD</button></center>
      </form>
   </div>
</div>
<!-- Edit nature of business Modal -->
<div id="edit-modal" class="modal">
   <div class="edit_modal-content">
      <span class="close" onclick="hideModal('edit-modal')">&times;</span>
      
      <form method="POST" action="">
         <div class="logo">
            <img src="../images/MLW Logo.png" alt="logo">
         </div>
         <center>
      <h3>Edit Description</h3>
      </center>
         <div class="inputs-div">
            <div class="inputs">

               <div class="input-container">
                    <input type="hidden" id="edit-id" name="edit-id">
               </div>
                <div class="input-container">
                    <label for="edit-description">Description</label>
                    <input type="text" name="edit-description" id="edit-description" required autocomplete="off">
               </div>

               <center><button type="submit" id="update-description" name="update-description">Update</button></center>

            </div>
         </div>
      </form>
   </div>
</div>

<script>
    
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

   function showEditModal() {
    var selectedRow = document.querySelector('#users-table tr.selected');
    var selectedRow2 = document.querySelector('#search-results tr.selected');

    if (selectedRow) {
        var cells = selectedRow.getElementsByTagName('td');
        document.getElementById('edit-id').value = cells[1].innerText;
        document.getElementById('edit-description').value = cells[2].innerText; 

        showModal('edit-modal');
    } else if (selectedRow2) {
        var cells = selectedRow2.getElementsByTagName('td');
        document.getElementById('edit-id').value = cells[1].innerText;
        document.getElementById('edit-description').value = cells[2].innerText; 

        showModal('edit-modal');
    } else {
        alert('Please select a row to edit.');
    }
}
   function deleteRow() {
  var selectedRow = document.querySelector('.selected');

  if (selectedRow) {
    var confirmation = confirm('Are you sure you want to delete this data?');
    if (confirmation) {
      var id = selectedRow.cells[1].textContent;
      var formData = new FormData();
      formData.append('id', id);
      formData.append('natureOfBusiness', 'true');

      fetch('delete.php', {
        method: 'POST',
        body: formData
      })
        .then(response => {
          if (!response.ok) {
            throw new Error('Failed to delete.');
          }
          return response.text();
        })
        .then(data => {
            console.log(data); 
            if (data.trim() === 'success') { 
                selectedRow.remove();
                alert('Data deleted successfully.');
            } else {
                throw new Error('Failed to delete data.');
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

</script>
</body>
</html>
