<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "payroll_system";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Database Connection Failed");
}

// echo "Database Connected Successfully";

?>