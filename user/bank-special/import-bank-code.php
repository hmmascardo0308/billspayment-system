<?php
session_start();
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
require '../../vendor/autoload.php';
ini_set('memory_limit', '10000M');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
   
if(isset($_POST['save_excel_data']))
{   
    $fileName = $_FILES['import_file']['name'];
    $file_ext = pathinfo($fileName, PATHINFO_EXTENSION);
    $allowed_ext = ['xls','csv','xlsx'];

    $max_filesize = 41943040;
   
    if(in_array($file_ext, $allowed_ext))
    {
        $inputFileNamePath = $_FILES['import_file']['tmp_name'];
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inputFileNamePath);
        $data = $spreadsheet->getActiveSheet()->toArray();
        $count = "0";
        if($conn->query("TRUNCATE Table temp_billsPayment_others")){
            header('Location: import-bank-special.php');
        }
        foreach($data as $row)
        {
            if($count > 0)
            {   
                // $controlNumber = $row['3'];
                // $verify_query = "SELECT * FROM billsPayment_others WHERE control_number = '$controlNumber'";
                // $verify_record = mysqli_query($conn, $verify_query );
                // if($verify_record->num_rows == 0){
                    $status = $row['0'];
                    $blank =  $row['1'];
                    $date_time = $row['2'];
                    $control_number = $row['3'];
                    $reference_number = $row['4'];
                    $payor = str_replace("'", "\'", $row[5]);
                    $address = str_replace("'", "\'", $row[6]);
                    $account_number = $row['7'];
                    $account_name = str_replace("'", "\'", $row[8]);
                    $amount_paid = str_replace(",", "", $row['9']);
                    $charge_to_partner = str_replace(",", "", $row['10']);
                    $charge_to_customer = str_replace(",", "", $row['11']);
                    $contact_number = $row['12'];
                    $other_details =str_replace("'", "\'", $row[13]);
                    $ml_outlet = str_replace("'", "\'", $row[14]);
                    $region = str_replace("'", "\'", $row[15]);
                    $operator = str_replace("'", "\'", $row[16]);
                    $partner_name = $_POST['partnerName'];
                    $partner_id = $_POST['partnerID'];
                    $name = $_POST['importedby'];

                    $securityBank_query = "INSERT INTO billsPayment_others (status,blank,date_time,control_number,reference_number,payor,address,account_number,account_name,amount_paid,charge_to_partner,charge_to_customer,contact_number,other_details,ml_outlet,region,operator,partner_name,partner_id,imported_date,imported_by) 
                    VALUES ('$status','$blank','$date_time','$control_number','$reference_number','$payor','$address','$account_number','$account_name','$amount_paid','$charge_to_partner','$charge_to_customer','$contact_number','$other_details','$ml_outlet','$region','$operator','$partner_name','$partner_id',NOW(),'$name')";
                    $temp_securityBank_query = "INSERT INTO temp_billsPayment_others (status,blank,date_time,control_number,reference_number,payor,address,account_number,account_name,amount_paid,charge_to_partner,charge_to_customer,contact_number,other_details,ml_outlet,region,operator,partner_name,partner_id,imported_date,imported_by) 
                    VALUES ('$status','$blank','$date_time','$control_number','$reference_number','$payor','$address','$account_number','$account_name','$amount_paid','$charge_to_partner','$charge_to_customer','$contact_number','$other_details','$ml_outlet','$region','$operator','$partner_name','$partner_id',NOW(),'$name')";
                    $delete_query = "DELETE FROM billsPayment_others WHERE blank IS NULL";
                    $result = mysqli_query($conn, $securityBank_query);
                    $temp_securityBank_result = mysqli_query($conn, $temp_securityBank_query);
                //     $results = mysqli_query($conn,$delete_query);
                // }
                // else{
                //     $_SESSION['alert-message'] = "Already Existed";
                //     header('Location: import-bank-special.php');
                //     exit(0);
                // }
            }    
            else
            {
                $count = "1";             
            }
        } 
        if(isset($msg))
        {
            $_SESSION['succ-message'] = "Successfully Imported";
            header('Location: import-bank-special.php');
            exit(0);
        }
    }
   
    else
    {
        $_SESSION['alert-message'] = "Invalid File";
        header('Location: import-bank-special.php');
        exit(0);
    }
}

?>
