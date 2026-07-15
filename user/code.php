<?php
session_start();
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
@require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if(isset($_POST['save_excel_data']))
{
    $fileName = $_FILES['import_file']['name'];
    $file_ext = pathinfo($fileName, PATHINFO_EXTENSION);
    $allowed_ext = ['xls','csv','xlsx'];

    if(in_array($file_ext, $allowed_ext))
    {
        $inputFileNamePath = $_FILES['import_file']['tmp_name'];
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inputFileNamePath);
        $data = $spreadsheet->getActiveSheet()->toArray();

        $count = "0";
        foreach($data as $row)
        {
            if($count > 0)
            {
                $date = $row['0'];
                $zone_name = $row['1'];
                $region_name = $row['2'];
                $area_name = $row['3'];
                $branch_code = $row['4'];
                $branch_name = $row['5'];
                $entry_number = $row['6'];
                $your_reference = $row['7'];
                $resource = $row['8'];
                $journal = $row['9'];
                $gl_code = $row['10'];
                $gl_code_name = $row['11'];
                $description = $row['12'];
                $item_code = $row['13'];
                $quantity = $row['14'];
                $debit = $row['15'];
                $credit = $row['16'];
                $imported_date = $row['17'];

                $bookkeeper_query = "INSERT INTO bookkeeper (date,zone_name,region_name,area_name,branch_code,branch_name,entry_number,your_reference,resource,journal,gl_code,gl_code_name,description,item_code,quantity,debit,credit,imported_date) VALUES ('$date','$zone_name','$region_name','$area_name','$branch_code','$branch_name','$entry_number','$your_reference','$resource','$journal','$gl_code','$gl_code_name','$description','$item_code','$quantity','$debit','$credit',NOW())";
                $result = mysqli_query($conn, $bookkeeper_query);
                $msg = true;
            }
            else
            {
                $count = "1";
            }
        }
        if(isset($msg))
        {
            $_SESSION['message'] = "Successfully Imported";
            header('Location: bookkeeper.php');
            exit(0);
        }
        else
        {
            $_SESSION['message'] = "Not Imported";
            header('Location: bookkeeper.php');
            exit(0);
        }
    }
    else
    {
        $_SESSION['message'] = "Invalid File";
        header('Location: bookkeeper.php');
        exit(0);
    }
}
?>