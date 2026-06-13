<?php
include 'auth.php';
requirePermission('vacation_manage');

$id = $_GET['id'];

mysqli_query($conn,"
DELETE FROM vacations
WHERE id='$id'
");

header("Location: vacation_details.php");
?>
