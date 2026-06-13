<?php
include 'auth.php';
requirePermission('employee_delete');

$id = $_GET['id'];

$query = "DELETE FROM employees WHERE id='$id'";

mysqli_query($conn, $query);

header("Location: employee_list.php");

?>
