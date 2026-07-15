<?php
session_start(); 
$conn = mysqli_connect('localhost', 'root', 'Password1','mldb');
    $query ="SELECT partner_name,partner_id FROM partner_masterfile";
    $result = $conn->query($query);
    if($result->num_rows> 0){
      $options= mysqli_fetch_all($result, MYSQLI_ASSOC);
    }
?>