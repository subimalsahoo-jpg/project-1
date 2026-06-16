<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');
vacation_ensure_schema($conn);

$message = "";
$error = "";

if (isset($_POST['save_vacations'])) {
    $user_no = vacation_esc($conn, $_POST['user_no'] ?? '');
    $employee_name = vacation_esc($conn, $_POST['employee_name'] ?? '');
    $date_order = detect_input_date_order([
        $_POST['from_date'] ?? '',
        $_POST['to_date'] ?? '',
        $_POST['return_date'] ?? ''
    ]);
    $from_date_raw = normalize_input_date($_POST['from_date'] ?? '', $date_order);
    $to_date_raw = normalize_input_date($_POST['to_date'] ?? '', $date_order);
    $return_date_raw = normalize_input_date($_POST['return_date'] ?? '', $date_order);

    $from_date = vacation_esc($conn, $from_date_raw);
    $to_date = vacation_esc($conn, $to_date_raw);
    $return_date = vacation_esc($conn, $return_date_raw);
    $leave_type = vacation_esc($conn, $_POST['leave_type'] ?? 'Annual Vacation');
    $paid_status = vacation_esc($conn, $_POST['paid_status'] ?? 'Paid');
    $vacation_status = vacation_esc($conn, $_POST['vacation_status'] ?? 'Upcoming');
    $reason = vacation_esc($conn, $_POST['reason'] ?? '');

    if ($user_no === '' || $from_date === '' || $to_date === '') {
        $error = "User No, From Date and To Date required.";
    } elseif (strtotime($from_date) > strtotime($to_date)) {
        $error = "From Date cannot be after To Date.";
    } else {
        $return_sql = $return_date !== '' ? "'$return_date'" : "NULL";
        mysqli_query($conn, "
            INSERT INTO vacations
                (user_no, employee_name, from_date, to_date, return_date, leave_type, paid_status, vacation_status, reason)
            VALUES
                ('$user_no', '$employee_name', '$from_date', '$to_date', $return_sql, '$leave_type', '$paid_status', '$vacation_status', '$reason')
        ");
        $message = "Vacation added successfully.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Add Vacation</title>
<style>
body{font-family:Arial;background:#eef2f7;padding:30px;color:#102033;}
.box{max-width:760px;margin:auto;background:white;padding:28px;border-radius:12px;box-shadow:0 3px 16px rgba(15,23,42,.08);}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.btn{background:#1f3349;color:white;padding:10px 16px;text-decoration:none;border-radius:7px;border:0;cursor:pointer;font-weight:700;}
.btn-blue{background:#3498db;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;}
label{font-weight:700;font-size:14px;display:block;margin-bottom:6px;}
input,textarea,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;}
textarea{min-height:76px;resize:vertical;}
.full{grid-column:1 / -1;}
.msg{padding:12px 14px;border-radius:8px;margin-bottom:15px;font-weight:700;}
.ok{background:#dcfce7;color:#166534;}
.err{background:#fee2e2;color:#991b1b;}
@media(max-width:720px){.grid{grid-template-columns:1fr;}.full{grid-column:auto;}}
</style>
<script>
function getEmployeeName(){
    var userNo = document.getElementById("user_no").value;
    if(userNo === ''){
        document.getElementById("employee_name").value = '';
        return;
    }
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "search_employee_name.php?user_no=" + encodeURIComponent(userNo), true);
    xhr.onload = function(){
        if(this.status === 200){
            document.getElementById("employee_name").value = this.responseText;
        }
    };
    xhr.send();
}
</script>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="box">
    <div class="top">
        <h2>Add Vacation</h2>
        <div>
            <a href="dashboard.php" class="btn">Dashboard</a>
            <a href="vacation_details.php" class="btn btn-blue">Vacation Details</a>
        </div>
    </div>

    <?php if($message): ?><div class="msg ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST">
        <div class="grid">
            <div>
                <label>User No</label>
                <input type="text" name="user_no" id="user_no" onkeyup="getEmployeeName()" required>
            </div>
            <div>
                <label>Employee Name</label>
                <input type="text" name="employee_name" id="employee_name" readonly style="background:#f8fafc;">
            </div>
            <div>
                <label>Leave Type</label>
                <select name="leave_type">
                    <option>Annual Vacation</option>
                    <option>Emergency Leave</option>
                    <option>Sick Leave</option>
                    <option>Unpaid Leave</option>
                    <option>Other</option>
                </select>
            </div>
            <div>
                <label>Paid Status</label>
                <select name="paid_status">
                    <option>Paid</option>
                    <option>Unpaid</option>
                    <option>Half Paid</option>
                </select>
            </div>
            <div>
                <label>From Date</label>
                <input type="date" name="from_date" required>
            </div>
            <div>
                <label>To Date</label>
                <input type="date" name="to_date" required>
            </div>
            <div>
                <label>Actual Return Date</label>
                <input type="date" name="return_date">
            </div>
            <div>
                <label>Status</label>
                <select name="vacation_status">
                    <option>Upcoming</option>
                    <option>Running</option>
                    <option>Returned</option>
                    <option>Cancelled</option>
                </select>
            </div>
            <div class="full">
                <label>Reason / Country / Note</label>
                <textarea name="reason"></textarea>
            </div>
        </div>
        <div style="margin-top:18px;">
            <button type="submit" name="save_vacations" class="btn">Save Vacation</button>
        </div>
    </form>
</div>
</body>
</html>
