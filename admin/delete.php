<?php
require_once __DIR__ . '/../config/config.php';

if (isset($_POST['id']) && isset($_POST['user']) && $_POST['user'] === 'true') {

    $id = $_POST['id'];

    $sql = "DELETE FROM mldb.user_form WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo 'success';
    } else {
        echo 'error';
    }

    $stmt->close();

}elseif (isset($_POST['id']) && isset($_POST['natureOfBusiness']) && $_POST['natureOfBusiness'] === 'true') {

    $id = $_POST['id'];

    $sql = "DELETE FROM mldb.nature_of_business WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo 'success';
    } else {
        echo 'error';
    }

    $stmt->close();

}else{
    echo 'None of the choices';
}

?>