<?php
include 'auth.php';
requirePermission('employee_view');

$user_no = $_GET['user_no'] ?? '';

$user_no = mysqli_real_escape_string($conn,$user_no);

$query = mysqli_query($conn,"
SELECT full_name 
FROM employees
WHERE user_no='$user_no'
LIMIT 1
");

if(mysqli_num_rows($query) > 0){

    $row = mysqli_fetch_assoc($query);

    echo $row['full_name'];

} else {

    echo '';
}
?>
