<?php
include 'auth.php';
requirePermission('attendance_upload');

$id = intval($_GET['id'] ?? 0);

if($id > 0){
    mysqli_query($conn,"DELETE FROM attendance WHERE id='$id'");
}

header("Location: attendance_report.php");
exit();
?>
