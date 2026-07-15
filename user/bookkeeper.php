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
    <title>M Lhuillier Book Keeper Import</title>
    <link href="../css/bookkeeper.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col">
                <?php
                    if(isset($_SESSION['message']))
                    {
                        echo "<h1 class='msg'>".$_SESSION['message']."</h1>";
                        unset($_SESSION['message']);
                    }
                ?>
                <div class="card">
                    <div class="card-header">
                        <h4>Book Keeper Data</h4>
                    </div>
                    <div class="card-body">
                        <form action="phpSearch.php" method="POST"  id="search-data">
                            <input type="text" class="search" name="search" id="search" placeholder="Search Data">
                            <button name="save" class="search-btn">Search</button>
                        </form>
                        <form action="code.php" method="POST" enctype="multipart/form-data">
                            <div class="choose-file">
                                <input type="file" name="import_file" class="form-control" />
                                <button type="submit" name="save_excel_data" class="btn">Import</button>
                            </div>
                        </form>
                        <i style="padding-left:8px; font-size:12px;">Import .csv, .xls, and .xlsx file <b style="color:red;">ONLY</b>!</i>
                        <div class="display-data">
                            <a class="display-btn" onclick="formToggle('tbl');">Display/Hide</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Data list table --> 
        <div class="tbl-container">
            <table class="table" id="tbl">
                <thead>
                    <tr>
                        <th>Date</th>
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
                // Get member rows
                $result = $conn->query("SELECT * FROM bookkeeper ORDER BY id ASC");
                if($result->num_rows > 0){
                    while($row = $result->fetch_assoc()){
            ?>
                         <tr>
                            <td style="text-align:center;"><?php echo $row['date']; ?></td>
                            <td style="text-align:left;"><?php echo $row['zone_name']; ?></td>
                            <td style="text-align:left;"><?php echo $row['region_name']; ?></td>
                            <td style="text-align:left;"><?php echo $row['area_name']; ?></td>
                            <td style="text-align:left;"><?php echo $row['branch_code']; ?></td>
                            <td style="text-align:left;"><?php echo $row['branch_name']; ?></td>
                            <td style="text-align:right;"><?php echo $row['entry_number']; ?></td>
                            <td style="text-align:left;"><?php echo $row['your_reference']; ?></td>
                            <td style="text-align:center;"><?php echo $row['resource']; ?></td>
                            <td style="text-align:right;"><?php echo $row['journal']; ?></td>
                            <td style="text-align:right;"><?php echo $row['gl_code']; ?></td>
                            <td style="text-align:left;"><?php echo $row['gl_code_name']; ?></td>
                            <td style="text-align:left;"><?php echo $row['description']; ?></td>
                            <td style="text-align:right;"><?php echo $row['item_code']; ?></td>
                            <td style="text-align:center;"><?php echo $row['quantity']; ?></td>
                            <td style="text-align:right;"><?php echo $row['debit']; ?></td>
                            <td style="text-align:right;"><?php echo $row['credit']; ?></td>
                            <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                        </tr>
                    <?php } }else{ ?>
                        <tr><td colspan="19" id="no_data">No data(s) found...</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <script type="text/javascript">
        function formToggle(ID){
            var element = document.getElementById(ID);
            if(element.style.display === "none"){
                element.style.display = "block";
            }else{
                element.style.display = "none";
            }
        }
    </script>
</body>
</html>