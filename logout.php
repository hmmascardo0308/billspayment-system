<?php
// Connect to the database
include '../config/config.php';

session_start();
session_destroy();
if(empty($_SESSION) && !isset($_SESSION['user_type'])){
    header('location: index.php');
}else{
    header('location: index.php');
}
exit(1);
?>