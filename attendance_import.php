<?php
include 'auth.php';
requirePermission('attendance_upload');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance Import</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; padding:30px; }
        .wrap { max-width:760px; margin:0 auto; background:#fff; padding:24px; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,.08); }
        .btn { background:#2c3e50; color:#fff; padding:10px 18px; border:none; border-radius:5px; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-blue { background:#3498db; }
        input[type=file] { padding:10px; border:1px solid #ccc; width:100%; margin:12px 0 18px; box-sizing:border-box; }
        .note { background:#eef6ff; border-left:4px solid #3498db; padding:12px 14px; line-height:1.6; margin:15px 0; }
    </style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="wrap">
    <a href="dashboard.php" class="btn">Dashboard</a>

    <h2>Upload Attendance Excel</h2>

    <div class="note">
        CrossChex Standard theke <b>Attendance &gt; Search &gt; Export data</b> kore file upload korun.
        Supported file: <b>.xls, .xlsx, .csv</b>.
        Import columns: <b>Department, User No, User ID, Name, Date, Timetable, On Duty, Off Duty, Schedule, In, Out, Late, Early, Overtime</b>.
    </div>

    <form action="import_attendance.php" method="POST" enctype="multipart/form-data">
        <input type="file" name="excel" accept=".xls,.xlsx,.csv" required>
        <button type="submit" class="btn btn-blue">Upload Attendance</button>
    </form>
</div>
</body>
</html>
