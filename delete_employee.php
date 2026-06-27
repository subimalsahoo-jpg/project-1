<?php
include 'auth.php';
requireLogin();

/* Only an Admin may permanently delete an employee. */
if (!is_admin_user()) {
    http_response_code(403);
    echo "<h2 style='font-family:Arial;color:#c0392b;text-align:center;margin-top:60px;'>Access Denied</h2>";
    echo "<p style='font-family:Arial;text-align:center;'>Only an Admin can delete an employee.</p>";
    echo "<p style='font-family:Arial;text-align:center;'><a href='employee_list.php'>&#8592; Back to Employee Details</a></p>";
    exit();
}

$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = mysqli_prepare($conn, "DELETE FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

header("Location: employee_list.php?deleted=1");
exit();
?>
