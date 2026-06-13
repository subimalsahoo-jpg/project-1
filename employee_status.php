<?php
include 'auth.php';
requirePermission('employee_add');

if(isset($_GET['id']) && isset($_GET['status'])){

    $id = intval($_GET['id']);

    $status = $_GET['status'];

    if($status != 'Active' && $status != 'Inactive'){
        die("Invalid Status");
    }

    mysqli_query($conn, "
        UPDATE employees
        SET status='$status'
        WHERE id='$id'
    ");

    header("Location: employee_list.php");
    exit();
}

echo "Invalid Request";
?>
