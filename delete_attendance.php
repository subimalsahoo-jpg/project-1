<?php
include 'auth.php';
requireLogin();

/* Only an Admin may delete an attendance record. */
if (!is_admin_user()) {
    http_response_code(403);
    echo "<h2 style='font-family:Arial;color:#c0392b;text-align:center;margin-top:60px;'>Access Denied</h2>";
    echo "<p style='font-family:Arial;text-align:center;'>Only an Admin can delete an attendance record.</p>";
    echo "<p style='font-family:Arial;text-align:center;'><a href='attendance_report.php'>&#8592; Back to Attendance Report</a></p>";
    exit();
}

$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = mysqli_prepare($conn, "DELETE FROM attendance WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/* Return to the report the user came from, when provided. */
$return = $_GET['return'] ?? '';
$safe_return = (is_string($return) && strpos($return, 'attendance_report.php') !== false) ? $return : 'attendance_report.php';
header("Location: " . $safe_return);
exit();
?>
