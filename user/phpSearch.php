<?php
session_start(); 
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
    <title>Data Results</title>
    <link rel="stylesheet" href="css/bookkeeper.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="s-container">
        <table class="table2" id="tbl2">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date/Time <br> YYYY-MM-DD</th>
                    <th>Zone name</th>
                    <th>Region name</th>
                    <th>Area name</th>
                    <th>Branch code</th>
                    <th>Branch name</th>
                    <th>Entry number</th>
                    <th>Your reference</th>
                    <th>Resource</th>
                    <th>Journal</th>
                    <th>GL Code</th>
                    <th>GL Code Name</th>
                    <th>Description</th>
                    <th>Item Code</th>
                    <th>Quantity</th> 
                    <th>Debit</th>
                    <th>Credit</th>
                    <th>Imported Date</th>
                </tr>
            </thead>
        <tbody>
        <?php
            $search = $_POST['search'];
            if ($conn->connect_error){
                die("Connection failed: ". $conn->connect_error);
            }
            $sql = "select * from bookkeeper where date like '%$search%'
                    OR zone_name like '%$search%'
                    OR region_name like '%$search%'
                    OR area_name like '%$search%'
                    OR branch_code like '%$search%'
                    OR branch_name like '%$search%'
                    OR entry_number like '%$search%'
                    OR your_reference like '%$search%'
                    OR resource like '%$search%'
                    OR journal like '%$search%'
                    OR gl_code like '%$search%'
                    OR gl_code_name like '%$search%'
                    OR description like '%$search%'
                    OR item_code like '%$search%'
                    OR quantity like '%$search%'
                    OR debit like '%$search%'
                    OR credit like '%$search%'
                    OR imported_date like '%$search%'";
            $result = $conn->query($sql);
            if($result){
                if ($result->num_rows > 0){
                    while($row = $result->fetch_assoc() ){
        
        ?>
                       <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo $row['date']; ?></td>
                            <td><?php echo $row['zone_name']; ?></td>
                            <td><?php echo $row['region_name']; ?></td>
                            <td><?php echo $row['area_name']; ?></td>
                            <td><?php echo $row['branch_code']; ?></td>
                            <td><?php echo $row['branch_name']; ?></td>
                            <td><?php echo $row['entry_number']; ?></td>
                            <td><?php echo $row['your_reference']; ?></td>
                            <td><?php echo $row['resource']; ?></td>
                            <td><?php echo $row['journal']; ?></td>
                            <td><?php echo $row['gl_code']; ?></td>
                            <td><?php echo $row['gl_code_name']; ?></td>
                            <td><?php echo $row['description']; ?></td>
                            <td><?php echo $row['item_code']; ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td><?php echo $row['debit']; ?></td>
                            <td><?php echo $row['credit']; ?></td>
                            <td><?php echo $row['imported_date']; ?></td>
                       </tr>
                    <br>
                    <?php }} }else{ ?>
                        <tr><td colspan="19" id="no_data">No data(s) found...</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
</body>
</html>