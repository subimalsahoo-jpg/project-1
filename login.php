<?php
include 'auth.php';

if (isset($_SESSION['username'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login_user($conn, $username, $password)) {
        header("Location: dashboard.php");
        exit();
    }

    $error = "Invalid username/password or inactive user.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Login</title>
    <style>
        body{font-family:Arial;background:#f3f6fb;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;}
        .box{width:360px;background:white;padding:28px;border-radius:8px;box-shadow:0 6px 22px rgba(0,0,0,.12);}
        h2{margin:0 0 20px;color:#1d3557;text-align:center;}
        label{font-weight:bold;display:block;margin-top:12px;}
        input{width:100%;padding:11px;margin-top:6px;box-sizing:border-box;border:1px solid #ccd4e0;border-radius:4px;}
        button{width:100%;margin-top:18px;background:#23364d;color:white;border:0;padding:12px;border-radius:4px;cursor:pointer;font-weight:bold;}
        .error{background:#ffe4e4;color:#b00020;padding:10px;border-radius:4px;margin-bottom:14px;}
    </style>
</head>
<body>
<div class="box">
    <h2>Payroll Login</h2>
    <?php if($error !== ''){ ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php } ?>
    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" required autofocus>

        <label>Password</label>
        <input type="password" name="password" required>

        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>
