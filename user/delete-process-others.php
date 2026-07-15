<?php session_start(); 
@include '../export/export-good-cancelled.php';
@include 'fetch-partner-data.php';

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
    <title>Delete Records</title>
    <link href="../css/delete.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>
<body> 
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card mt-5">
                    <div class="btn-back">
                        <a href="billsPayment-menu.php" id="back">Back</a>
                    </div>
                    <div class="header">
                        <input type="text" name="header-t" id="header-t" value="Bills Payment Transaction" selected readonly>
                    </div>
                    <div class="card-body">
                        <form action="" method="post"  enctype="multipart/form-data">
                            <div class="row">
                                
                                <div class="col">
                                    <div class="form-group">
                                        <label>From Date:</label>
                                        <input type="date" id="fromDate" name="fromDate" value="<?php if(isset($_POST['fromDate'])){ echo $_POST['fromDate']; } ?>" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="form-group">
                                        <label>To Date:</label>
                                        <input type="date" id="toDate" name="toDate" value="<?php if(isset($_POST['toDate'])){ echo $_POST['toDate']; } ?>" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col">
                                <div class="form-group">
                                        <select onchange="s()" class="form-control" id="partner-select" name="PartnerName">
                                            <option selected id="select" value="SECURITY BANK RTA"><center>-- Select Partner Name --</center></option>
                                            <?php foreach ($options as $option) { ?>
                                            <option value="<?php echo $option['partner_name']; ?>"><?php echo $option['partner_name']; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <input type="button" onclick="openModal()" name="del" id="del" class="filter-btn" value="Delete" disabled>
                                    </div>
                                </div>
                            </div>
                            <!-- The Modal -->
                            <div id="myModal" class="modal">

                                <!-- Modal content -->
                                <div class="modal-content">
                                    <div class="close-div">
                                        <span class="close" onclick="closeModal()">&times;</span>
                                    </div><br>
                                    <div class="del-content">
                                        <p>Are you sure you want to delete?</p>
                                        <input type="submit" name="delete" id="delete" onclick="deleteItem()" value="Delete">
                                        <button onclick="closeModal()" id="cancel">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

 
            <?php 
            if(isset($_POST['delete'])){
                $select = "SELECT billspayment_others.account_number, billspayment_others.partner_name, partner_masterfile.partner_name
                            FROM billspayment_others, partner_masterfile
                            WHERE billspayment_others.partner_name = partner_masterfile.partner_name ";
                $result = mysqli_query($conn, $select);
                if($result->num_rows > 0){
                    if(isset($_POST['fromDate'])&&($_POST['toDate'])){
                        $from_date = $_POST['fromDate'];
                        $to_date = $_POST['toDate'];   
                        $query = "DELETE FROM billsPayment_others WHERE date_time >= '".$_POST["fromDate"]."' AND date_time <= '".$_POST["toDate"]."' AND partner_name = '".$_POST['PartnerName']."' ";
                        $query_run = mysqli_query($conn, $query);
                        echo '<script type="text/javascript">';
                        echo ' alert("Records Successfully Deleted!")';  
                        echo '</script>';
                    }else{
                        echo '<script type="text/javascript">';
                        echo ' alert("Already Deleted!")';  
                        echo '</script>';
                    }
                }else{
                    echo "<p style='margin:1%;background-color:whitesmoke;color:#d70c0c;padding:5px;width:auto;'>No Records Found!</p>";
                }
            }
            ?>        
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta1/dist/js/bootstrap.bundle.min.js"></script>

    <script type="text/javascript">
    function s(){
        var i=document.getElementById("fromDate");
        var i=document.getElementById("toDate");
        if(i=="")
        {
            document.getElementById("del").disabled=true;
        }
        else{
            document.getElementById("del").disabled=false;
        }
        var header = document.getElementById("header-t");
        var d = document.getElementById("partner-select");
        var displayText = d.options[d.selectedIndex].text;
        document.getElementById("header-t").value=displayText;

        // Get the value from local storage, if available
        var cachedValue = localStorage.getItem('myInputValue');
        if (cachedValue) {
        header.value = cachedValue;
        }
        // Save the value to local storage when the input changes
        header.addEventListener('change', function() {
        localStorage.setItem('myInputValue', header.value);
        });
    }
    </script>

	<script>
		// Get the modal
		var modal = document.getElementById("myModal");

		// Get the button that opens the modal
		var btn = document.getElementsByTagName("del");

		// When the user clicks on the button, open the modal
		function openModal() {
			modal.style.display = "block";
		}

		// When the user clicks on <span> (x), close the modal
		function closeModal() {
			modal.style.display = "none";
		}

		// When the user clicks on the delete button, delete the item and close the modal
		function deleteItem() {
			// Add your delete logic here
			console.log("Item deleted");
			closeModal();
		}
	</script>
</body>
</html>