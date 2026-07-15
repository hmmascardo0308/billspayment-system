<?php
require_once __DIR__ . '/../config/config.php';

  $query ="SELECT * FROM service_type";
  $result = $conn->query($query);
  if($result->num_rows> 0){
    $type= mysqli_fetch_all($result, MYSQLI_ASSOC);
  }
?>