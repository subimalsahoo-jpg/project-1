<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');
vacation_ensure_schema($conn);

$id = (int)($_GET['id'] ?? 0);
$q = mysqli_query($conn, "SELECT * FROM vacations WHERE id=$id LIMIT 1");
$row = $q ? mysqli_fetch_assoc($q) : null;

if (!$row) {
    die("Vacation record not found.");
}

if (isset($_POST['update'])) {
    $date_order = detect_input_date_order([
        $_POST['from_date'] ?? '',
        $_POST['to_date'] ?? '',
        $_POST['return_date'] ?? ''
    ]);
    $from_date = vacation_esc($conn, normalize_input_date($_POST['from_date'] ?? '', $date_order));
    $to_date = vacation_esc($conn, normalize_input_date($_POST['to_date'] ?? '', $date_order));
    $return_date = vacation_esc($conn, normalize_input_date($_POST['return_date'] ?? '', $date_order));
    $leave_type = vacation_esc($conn, $_POST['leave_type'] ?? 'Annual Vacation');
    $paid_status = vacation_esc($conn, $_POST['paid_status'] ?? 'Paid');
    $vacation_status = vacation_esc($conn, $_POST['vacation_status'] ?? 'Upcoming');
    $reason = vacation_esc($conn, $_POST['reason'] ?? '');
    $return_sql = $return_date !== '' ? "'$return_date'" : "NULL";

    mysqli_query($conn, "
        UPDATE vacations SET
            from_date='$from_date',
            to_date='$to_date',
            return_date=$return_sql,
            leave_type='$leave_type',
            paid_status='$paid_status',
            vacation_status='$vacation_status',
            reason='$reason'
        WHERE id=$id
    ");

    header("Location: vacation_details.php");
    exit;
}

function selected($current, $value) {
    return trim((string)$current) === $value ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Vacation</title>
<style>
body{font-family:Arial;background:#eef2f7;padding:30px;color:#102033;}
.box{max-width:760px;margin:auto;background:white;padding:28px;border-radius:12px;box-shadow:0 3px 16px rgba(15,23,42,.08);}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.btn{background:#1f3349;color:white;padding:10px 16px;text-decoration:none;border-radius:7px;border:0;cursor:pointer;font-weight:700;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;}
label{font-weight:700;font-size:14px;display:block;margin-bottom:6px;}
input,textarea,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;}
textarea{min-height:76px;resize:vertical;}
.full{grid-column:1 / -1;}
@media(max-width:720px){.grid{grid-template-columns:1fr;}.full{grid-column:auto;}}
</style>
</head>
<body>
<div class="box">
    <div class="top">
        <h2>Edit Vacation</h2>
        <a href="vacation_details.php" class="btn">Vacation Details</a>
    </div>

    <form method="POST">
        <div class="grid">
            <div>
                <label>User No</label>
                <input type="text" value="<?php echo htmlspecialchars($row['user_no'] ?? ''); ?>" readonly>
            </div>
            <div>
                <label>Employee Name</label>
                <input type="text" value="<?php echo htmlspecialchars($row['employee_name'] ?? ''); ?>" readonly>
            </div>
            <div>
                <label>Leave Type</label>
                <select name="leave_type">
                    <option <?php echo selected($row['leave_type'] ?? '', 'Annual Vacation'); ?>>Annual Vacation</option>
                    <option <?php echo selected($row['leave_type'] ?? '', 'Emergency Leave'); ?>>Emergency Leave</option>
                    <option <?php echo selected($row['leave_type'] ?? '', 'Sick Leave'); ?>>Sick Leave</option>
                    <option <?php echo selected($row['leave_type'] ?? '', 'Unpaid Leave'); ?>>Unpaid Leave</option>
                    <option <?php echo selected($row['leave_type'] ?? '', 'Other'); ?>>Other</option>
                </select>
            </div>
            <div>
                <label>Paid Status</label>
                <select name="paid_status">
                    <option <?php echo selected($row['paid_status'] ?? '', 'Paid'); ?>>Paid</option>
                    <option <?php echo selected($row['paid_status'] ?? '', 'Unpaid'); ?>>Unpaid</option>
                    <option <?php echo selected($row['paid_status'] ?? '', 'Half Paid'); ?>>Half Paid</option>
                </select>
            </div>
            <div>
                <label>From Date</label>
                <input type="date" name="from_date" value="<?php echo htmlspecialchars($row['from_date'] ?? ''); ?>" required>
            </div>
            <div>
                <label>To Date</label>
                <input type="date" name="to_date" value="<?php echo htmlspecialchars($row['to_date'] ?? ''); ?>" required>
            </div>
            <div>
                <label>Actual Return Date</label>
                <input type="date" name="return_date" value="<?php echo htmlspecialchars($row['return_date'] ?? ''); ?>">
            </div>
            <div>
                <label>Status</label>
                <select name="vacation_status">
                    <option <?php echo selected($row['vacation_status'] ?? '', 'Upcoming'); ?>>Upcoming</option>
                    <option <?php echo selected($row['vacation_status'] ?? '', 'Running'); ?>>Running</option>
                    <option <?php echo selected($row['vacation_status'] ?? '', 'Returned'); ?>>Returned</option>
                    <option <?php echo selected($row['vacation_status'] ?? '', 'Cancelled'); ?>>Cancelled</option>
                </select>
            </div>
            <div class="full">
                <label>Reason / Country / Note</label>
                <textarea name="reason"><?php echo htmlspecialchars($row['reason'] ?? ''); ?></textarea>
            </div>
        </div>
        <div style="margin-top:18px;">
            <button type="submit" name="update" class="btn">Update Vacation</button>
        </div>
    </form>
</div>
</body>
</html>
