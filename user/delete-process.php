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
                    <div class="card-header">
                        <center><h4>Delete Bills Payment Records</h4></center>
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
                                        <input type="date" onchange="s()" id="toDate" name="toDate" value="<?php if(isset($_POST['toDate'])){ echo $_POST['toDate']; } ?>" class="form-control" required>
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
                                    <span class="close" onclick="closeModal()">&times;</span>
                                    <p>Are you sure you want to delete?</p>
                                    <input type="submit" onclick="deleteItem()" name="delete" id="delete" onclick="deleteItem()" value="Delete">
                                    <button onclick="closeModal()">Cancel</button>
                                </div>

                            </div>
                        </form>
                    </div>
                </div>

 
            <?php 
            if(isset($_POST['delete'])){
                $select = "SELECT * FROM billsPayment";
                $result = mysqli_query($conn, $select);
                if($result->num_rows > 0){
                    if(isset($_POST['fromDate'])&&($_POST['toDate'])){
                        $from_date = $_POST['fromDate'];
                        $to_date = $_POST['toDate'];   
                        $query = "DELETE FROM billsPayment WHERE date_time >= '".$_POST["fromDate"]."' AND date_time <= '".$_POST["toDate"]."' ";
                        $query_run = mysqli_query($conn, $query);
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
			console.log("Records Successfully Deleted!");
			closeModal();
		}
	</script>
</body>
</html>