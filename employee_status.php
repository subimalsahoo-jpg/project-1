<?php
include 'auth.php';
requirePermission('employee_add');

if(isset($_GET['id']) && isset($_GET['status'])){

    $id = intval($_GET['id']);

    $status = $_GET['status'];

    $allowed_statuses = ['Active', 'Inactive', 'Resigned', 'Absconding', 'Terminated', 'End of Contract'];
    if(!in_array($status, $allowed_statuses, true)){
        die("Invalid Status");
    }

    $safe_status = mysqli_real_escape_string($conn, $status);
    mysqli_query($conn, "
        UPDATE employees
        SET status='$safe_status'
        WHERE id='$id'
    ");

    header("Location: employee_list.php");
    exit();
}

echo "Invalid Request";
?>
