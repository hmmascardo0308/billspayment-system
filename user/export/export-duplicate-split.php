<?php
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
$query = "SELECT * FROM billsPayment ORDER BY date_time ASC";
$results = mysqli_query($conn, $query) or die("database error:". mysqli_error($conn));
$allRecord = array();
while( $records = mysqli_fetch_assoc($results) ) {
	$allRecord[] = $records;
}
$startDateMessage = '';
$endDate = '';
$noResult ='';
if(isset($_POST["export-duplicate"])){
 if(empty($_POST["fromDate"])){
  $startDateMessage = '<label class="text-danger">Select start date.</label>';
 }else if(empty($_POST["toDate"])){
  $endDate = '<label class="text-danger">Select end date.</label>';
 } else {  
  $recordQuery = "
  SELECT a.* FROM billspayment a JOIN (SELECT payor, address,account_number,account_name,amount_paid,charge_to_partner,charge_to_customer,contact_number,other_details,ml_outlet,region,operator,partner_name,partner_id, COUNT(*) FROM billspayment WHERE date_time >= '".$_POST["fromDate"]."' AND date_time <= '".$_POST["toDate"]."' GROUP BY payor HAVING count(*) > 1  )b ON a.payor = b.payor ORDER BY `a`.`payor` ASC";
  $recordResult = mysqli_query($conn, $recordQuery) or die("database error:". mysqli_error($conn));
  $filterRecord = array();
  while( $records = mysqli_fetch_assoc($recordResult) ) {
	$filterRecord[] = $records;
  }
  if(count($filterRecord)) {
	  $fileName = "Billspayment Duplicate/Split Transaction Records_".date('Ymd') . ".csv";
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
	   $recordData[] = $records["account_number"];
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