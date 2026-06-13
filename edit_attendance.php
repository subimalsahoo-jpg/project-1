<?php
include 'auth.php';
requirePermission('attendance_upload');

$id = intval($_GET['id'] ?? 0);
$return_url = $_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? 'attendance_report.php');
if (preg_match('/^\s*https?:\/\//i', $return_url) || strpos($return_url, '//') === 0) {
    $return_url = 'attendance_report.php';
}

function edit_time_to_seconds($time) {
    $time = trim((string)$time);
    if ($time === '') return 0;
    $parts = explode(':', $time);
    if (count($parts) < 2) return 0;
    $h = (int)$parts[0];
    $m = (int)$parts[1];
    $s = isset($parts[2]) ? (int)$parts[2] : 0;
    return ($h * 3600) + ($m * 60) + $s;
}

function edit_seconds_to_time($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function edit_calculate_late_time($check_in) {
    $check_in_seconds = edit_time_to_seconds($check_in);
    $office_start_seconds = edit_time_to_seconds('07:00:00');
    $late_after_seconds = edit_time_to_seconds('07:06:00');

    if ($check_in_seconds > $late_after_seconds) {
        return edit_seconds_to_time($check_in_seconds - $office_start_seconds);
    }

    return '';
}

function edit_calculate_early_time($check_out, $off_duty) {
    $check_out_seconds = edit_time_to_seconds($check_out);
    $off_duty_seconds = edit_time_to_seconds($off_duty);

    if ($check_out_seconds > 0 && $off_duty_seconds > 0 && $check_out_seconds < $off_duty_seconds) {
        return edit_seconds_to_time($off_duty_seconds - $check_out_seconds);
    }

    return '';
}

function edit_table_has_column($conn, $table, $column) {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && mysqli_num_rows($result) > 0;
}

if (!edit_table_has_column($conn, 'attendance', 'manual_entry_reason')) {
    mysqli_query($conn, "ALTER TABLE attendance ADD COLUMN manual_entry_reason VARCHAR(255) NULL DEFAULT '' AFTER overtime");
}

$result = mysqli_query($conn,"SELECT * FROM attendance WHERE id='$id'");
$row = mysqli_fetch_assoc($result);

if(!$row){
    echo "Attendance record not found.";
    exit();
}

$error_message = '';

if(isset($_POST['update'])){
    $attendance_date_value = normalize_input_date($_POST['attendance_date'] ?? '');
    $attendance_date = mysqli_real_escape_string($conn, $attendance_date_value);
    $check_in_raw = trim($_POST['check_in'] ?? '');
    $check_out_raw = trim($_POST['check_out'] ?? '');
    $late_time_raw = trim($_POST['late_time'] ?? '');
    $overtime_raw = trim($_POST['overtime'] ?? '');
    $absent_raw = trim($_POST['absent'] ?? 'False');
    $manual_reason_raw = trim($_POST['manual_entry_reason'] ?? '');

    $late_time_raw = $late_time_raw !== '' ? $late_time_raw : edit_calculate_late_time($check_in_raw);
    $early_time_raw = edit_calculate_early_time($check_out_raw, $row['off_duty'] ?? '');

    if ($check_in_raw !== '' || $check_out_raw !== '') {
        $absent_raw = 'False';
    }

    $manual_time_changed =
        trim((string)($row['attendance_date'] ?? '')) !== $attendance_date_value ||
        trim((string)($row['check_in'] ?? '')) !== $check_in_raw ||
        trim((string)($row['check_out'] ?? '')) !== $check_out_raw ||
        trim((string)($row['late_time'] ?? '')) !== $late_time_raw ||
        trim((string)($row['overtime'] ?? '')) !== $overtime_raw ||
        trim((string)($row['absent'] ?? '')) !== $absent_raw;

    if ($manual_time_changed && $manual_reason_raw === '') {
        $error_message = 'Manual Entry Reason is required when attendance time/date/status is changed.';
    } else {
        $sets = [
            "attendance_date='" . $attendance_date . "'",
            "check_in='" . mysqli_real_escape_string($conn, $check_in_raw) . "'",
            "check_out='" . mysqli_real_escape_string($conn, $check_out_raw) . "'",
            "late_time='" . mysqli_real_escape_string($conn, $late_time_raw) . "'",
            "overtime='" . mysqli_real_escape_string($conn, $overtime_raw) . "'",
            "absent='" . mysqli_real_escape_string($conn, $absent_raw) . "'"
        ];

        if (edit_table_has_column($conn, 'attendance', 'early_time')) {
            $sets[] = "early_time='" . mysqli_real_escape_string($conn, $early_time_raw) . "'";
        }

        if (edit_table_has_column($conn, 'attendance', 'manual_entry_reason')) {
            $sets[] = "manual_entry_reason='" . mysqli_real_escape_string($conn, $manual_reason_raw) . "'";
        }

        mysqli_query($conn, "UPDATE attendance SET " . implode(',', $sets) . " WHERE id='$id'");

        header("Location: " . $return_url);
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Attendance</title>
<style>
body{font-family:Arial;background:#f4f6f9;padding:30px}
.box{background:white;padding:25px;border-radius:10px;width:500px}
input,select,textarea{width:100%;padding:10px;margin:8px 0;box-sizing:border-box}
textarea{min-height:80px;resize:vertical}
.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:10px;border-radius:6px;margin-bottom:12px;font-weight:bold}
.help{font-size:12px;color:#6b7280;margin-top:-4px;margin-bottom:10px}
.btn{background:#3498db;color:white;padding:10px 18px;border:none;text-decoration:none;border-radius:5px}
</style>
</head>
<body>

<div class="box">
<h2>Edit Attendance</h2>

<?php if ($error_message !== '') { ?>
    <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
<?php } ?>

<form method="POST">
    <input type="hidden" name="return" value="<?php echo htmlspecialchars($return_url); ?>">
    Date:
    <input type="date" name="attendance_date" value="<?php echo $row['attendance_date']; ?>">

    Check In:
    <input type="text" name="check_in" value="<?php echo $row['check_in']; ?>">

    Check Out:
    <input type="text" name="check_out" value="<?php echo $row['check_out']; ?>">

    Late:
    <input type="text" name="late_time" value="<?php echo $row['late_time']; ?>">

    Overtime:
    <input type="text" name="overtime" value="<?php echo $row['overtime']; ?>">

    Manual Entry (Reason):
    <textarea name="manual_entry_reason" placeholder="Example: Employee forgot punch out / corrected by HR"><?php echo htmlspecialchars($row['manual_entry_reason'] ?? ''); ?></textarea>
    <div class="help">Time/date/attendance edit korle reason must.</div>

    Absent:
    <select name="absent">
        <option value="False" <?php if($row['absent']=='False') echo 'selected'; ?>>False</option>
        <option value="True" <?php if($row['absent']=='True') echo 'selected'; ?>>True</option>
    </select>

    <button type="submit" name="update" class="btn">Update</button>
    <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn">Back</a>
</form>
</div>

</body>
</html>
