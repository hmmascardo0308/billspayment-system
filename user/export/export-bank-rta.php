<?php
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
$query = "SELECT * FROM billsPayment_others WHERE account_number != '0061000128053' AND partner_id = 'MLBPP220836' ORDER BY date_time DESC";
$results = mysqli_query($conn, $query) or die("database error:". mysqli_error($conn));
$allRecord = array();
while( $records = mysqli_fetch_assoc($results) ) {
	$allRecord[] = $records;
}
$startDateMessage = '';
$endDate = '';
$noResult ='';
if(isset($_POST["export"])){
 if(empty($_POST["fromDate"])){
  $startDateMessage = '<label class="text-danger">Select start date.</label>';
 }else if(empty($_POST["toDate"])){
  $endDate = '<label class="text-danger">Select end date.</label>';
 } else {  
	$recordQuery = "SELECT * FROM billsPayment_others WHERE account_number LIKE '%0%0%6%1%0%0%0%1%2%8%0%5%3%' AND account_number != '0061000128053' AND partner_id = 'MLBPP180463' AND date_time BETWEEN '".$_POST["fromDate"]."' AND '".$_POST["toDate"]."' ORDER BY date_time ASC";
	$recordResult = mysqli_query($conn, $recordQuery) or die("database error:". mysqli_error($conn));
	$filterRecord = array();
  while( $records = mysqli_fetch_assoc($recordResult) ) {
	$filterRecord[] = $records;
  }
  if(count($filterRecord)) {
	  $fileName = "Generated Security Bank RTA Records_".date('Ymd') . ".csv";
	  header("Content-Description: File Transfer");
	  header("Content-Disposition: attachment; filename=$fileName");
	  header("Content-Type: application/csv;");
	  $file = fopen('php://output', 'w');
      $header = array('STATUS', 'BLANK', 'DATE/TIME', 'CONTROL NUMBER', 'REFERENCE NUMBER', 'PAYOR', 'ADDRESS', 'ACCOUNT NUMBER',
      'ACCOUNT NAME', 'AMOUNT PAID', 'CHARGE TO PARTNER', ' CHARGE TO CUSTOMER', 'CONTACT NUMBER', 'OTHER DETAILS', 'ML OUTLET', 'REGION',
      'OPERATOR', 'PARTNER NAME', 'PARTNER ID', 'IMPORTED DATE'); 
	  fputcsv($file, $header);  
	  foreach($filterRecord as $records) {
	   $recordData = array();
	   $recordData[] = $records["status"];
	   $recordData[] = $records["blank"];
	   $recordData[] = $records["date_time"];
	   $recordData[] = $records["control_number"];
       $recordData[] = $records["reference_number"];
       $recordData[] = $records["payor"];
	   $recordData[] = $records["address"];
	   $recordData[] = "'" . $records["account_number"]; // Preserve leading zeros by adding a single quote before the account number
	   $recordData[] = $records["account_name"];
       $recordData[] = $records["amount_paid"];
       $recordData[] = $records["charge_to_partner"];
	   $recordData[] = $records["charge_to_customer"];
	   $recordData[] = $records["contact_number"];
	   $recordData[] = $records["other_details"];
       $recordData[] = $records["ml_outlet"];
       $recordData[] = $records["region"];
	   $recordData[] = $records["operator"];
	   $recordData[] = $records["partner_name"];
	   $recordData[] = $records["partner_id"];
       $recordData[] = $records["imported_date"];
	   fputcsv($file, $recordData);
	  }
	  fclose($file);
	  exit;
  } else {
	 $noResult = '<label class="text-danger">There are no record exist with this date range to export. Please choose different date range.</label>';  
  }
 }
}
?>