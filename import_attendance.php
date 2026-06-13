<?php
set_time_limit(0);
ini_set('memory_limit', '-1');

include 'auth.php';
requirePermission('attendance_upload');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

if (!isset($_FILES['excel']) || $_FILES['excel']['error'] != 0) {
    die("Select Excel File");
}

$file = $_FILES['excel']['tmp_name'];

function normalize_header($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9]/', '', $value);
}

function get_cell_value($row, $columns, $key) {
    if (!isset($columns[$key])) {
        return '';
    }

    $column = $columns[$key];
    return $row[$column] ?? '';
}

function format_excel_date_value($value) {
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_numeric($value) && (float)$value > 20000) {
        try {
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        } catch (Exception $e) {
            return '';
        }
    }

    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : $value;
}

function format_excel_time_value($value) {
    if ($value instanceof DateTimeInterface) {
        return $value->format('H:i:s');
    }

    if (is_numeric($value)) {
        try {
            return ExcelDate::excelToDateTimeObject($value)->format('H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('H:i:s', $timestamp) : $value;
}

function clean_text_value($value) {
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    return trim((string)$value);
}

function sql_value($conn, $value) {
    return mysqli_real_escape_string($conn, (string)$value);
}

$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);

$headerAliases = [
    'department' => ['department', 'dept'],
    'user_no' => ['userno', 'usernumber', 'uno'],
    'employee_id' => ['userid', 'employeeid', 'idondevice', 'id'],
    'employee_name' => ['name', 'employeename', 'fullname'],
    'attendance_date' => ['date', 'attendancedate'],
    'timetable' => ['timetable'],
    'on_duty' => ['onduty'],
    'off_duty' => ['offduty'],
    'schedule_time' => ['schedule'],
    'check_in' => ['in', 'checkin', 'cin'],
    'check_out' => ['out', 'checkout', 'cout'],
    'late_time' => ['late'],
    'early_time' => ['early'],
    'overtime' => ['overtime', 'overtimehours'],
];

$headerRowNumber = null;
$columns = [];

foreach ($rows as $rowNumber => $row) {
    $normalizedCells = [];
    foreach ($row as $columnLetter => $value) {
        $normalizedCells[$columnLetter] = normalize_header($value);
    }

    $dateFound = in_array('date', $normalizedCells, true) || in_array('attendancedate', $normalizedCells, true);
    $userFound = in_array('userno', $normalizedCells, true) || in_array('userid', $normalizedCells, true);
    $nameFound = in_array('name', $normalizedCells, true) || in_array('employeename', $normalizedCells, true);

    if (!$dateFound || (!$userFound && !$nameFound)) {
        continue;
    }

    $headerRowNumber = $rowNumber;

    foreach ($headerAliases as $field => $aliases) {
        foreach ($normalizedCells as $columnLetter => $normalizedHeader) {
            if (in_array($normalizedHeader, $aliases, true)) {
                $columns[$field] = $columnLetter;
                break;
            }
        }
    }

    // CrossChex sometimes exports a compact XLS where only a few headers are
    // visible because of merged/hidden cells. The common layout is:
    // B User No, C User ID, D Name, E Date, I Schedule, J In, K Out, L Late.
    if (!isset($columns['user_no']) && isset($row['B'])) {
        $columns['user_no'] = 'B';
    }
    if (!isset($columns['employee_id']) && isset($row['C'])) {
        $columns['employee_id'] = 'C';
    }
    if (!isset($columns['employee_name']) && isset($row['D'])) {
        $columns['employee_name'] = 'D';
    }
    if (!isset($columns['schedule_time']) && isset($row['I'])) {
        $columns['schedule_time'] = 'I';
    }
    if (!isset($columns['check_in']) && isset($row['J'])) {
        $columns['check_in'] = 'J';
    }
    if (!isset($columns['check_out']) && isset($row['K'])) {
        $columns['check_out'] = 'K';
    }
    if (!isset($columns['late_time']) && isset($row['L'])) {
        $columns['late_time'] = 'L';
    }
    if (!isset($columns['early_time']) && isset($row['M'])) {
        $columns['early_time'] = 'M';
    }
    if (!isset($columns['overtime']) && isset($row['N'])) {
        $columns['overtime'] = 'N';
    }

    break;
}

if ($headerRowNumber === null) {
    die("Attendance header row not found. Please export CrossChex Attendance Analysis file with column headers.");
}

$required = ['attendance_date'];
foreach ($required as $field) {
    if (!isset($columns[$field])) {
        die("Required column missing: " . htmlspecialchars($field));
    }
}

if (!isset($columns['user_no']) && !isset($columns['employee_id'])) {
    die("Required column missing: user_no or employee_id");
}

$imported = 0;
$updated = 0;
$skipped = 0;

for ($rowNumber = $headerRowNumber + 1; $rowNumber <= count($rows); $rowNumber++) {
    if (!isset($rows[$rowNumber])) {
        continue;
    }

    $row = $rows[$rowNumber];

    $department   = clean_text_value(get_cell_value($row, $columns, 'department'));
    $user_no      = clean_text_value(get_cell_value($row, $columns, 'user_no'));
    $employee_id  = clean_text_value(get_cell_value($row, $columns, 'employee_id'));
    $name         = clean_text_value(get_cell_value($row, $columns, 'employee_name'));
    $date         = format_excel_date_value(get_cell_value($row, $columns, 'attendance_date'));
    $timetable    = clean_text_value(get_cell_value($row, $columns, 'timetable'));
    $on_duty      = format_excel_time_value(get_cell_value($row, $columns, 'on_duty'));
    $off_duty     = format_excel_time_value(get_cell_value($row, $columns, 'off_duty'));
    $schedule     = clean_text_value(get_cell_value($row, $columns, 'schedule_time'));
    $check_in     = format_excel_time_value(get_cell_value($row, $columns, 'check_in'));
    $check_out    = format_excel_time_value(get_cell_value($row, $columns, 'check_out'));
    $late         = format_excel_time_value(get_cell_value($row, $columns, 'late_time'));
    $early        = format_excel_time_value(get_cell_value($row, $columns, 'early_time'));
    $overtime     = format_excel_time_value(get_cell_value($row, $columns, 'overtime'));

    if ($user_no === '' && $employee_id !== '') {
        $safe_lookup_employee_id = sql_value($conn, $employee_id);
        $employeeLookup = mysqli_query($conn, "
            SELECT user_no
            FROM employees
            WHERE employee_id = '$safe_lookup_employee_id'
               OR card_no = '$safe_lookup_employee_id'
            LIMIT 1
        ");
        $employeeLookupRow = $employeeLookup ? mysqli_fetch_assoc($employeeLookup) : null;
        $user_no = $employeeLookupRow['user_no'] ?? $employee_id;
    }

    if (($on_duty === '' || $off_duty === '') && preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $schedule, $matches)) {
        if ($on_duty === '') {
            $on_duty = format_excel_time_value($matches[1]);
        }
        if ($off_duty === '') {
            $off_duty = format_excel_time_value($matches[2]);
        }
    }

    if ($user_no === '' && $employee_id === '') {
        $skipped++;
        continue;
    }

    if ($date === '') {
        $skipped++;
        continue;
    }

    $status = ($check_in === '') ? 'Absent' : 'Present';

    $values = [
        'department' => $department,
        'user_no' => $user_no,
        'employee_id' => $employee_id,
        'employee_name' => $name,
        'attendance_date' => $date,
        'timetable' => $timetable,
        'on_duty' => $on_duty,
        'off_duty' => $off_duty,
        'schedule_time' => $schedule,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'late_time' => $late,
        'early_time' => $early,
        'overtime' => $overtime,
        'status' => $status,
    ];

    $safe_user_no = sql_value($conn, $user_no);
    $safe_employee_id = sql_value($conn, $employee_id);
    $safe_date = sql_value($conn, $date);

    $findSql = "
        SELECT id
        FROM attendance
        WHERE attendance_date = '$safe_date'
        AND (
            (user_no != '' AND user_no = '$safe_user_no')
            OR (employee_id != '' AND employee_id = '$safe_employee_id')
        )
        LIMIT 1
    ";

    $existing = mysqli_query($conn, $findSql);
    $existingRow = $existing ? mysqli_fetch_assoc($existing) : null;

    if ($existingRow) {
        $sets = [];
        foreach ($values as $column => $value) {
            $sets[] = "$column = '" . sql_value($conn, $value) . "'";
        }

        $id = (int)$existingRow['id'];
        $updateSql = "UPDATE attendance SET " . implode(', ', $sets) . " WHERE id = $id";
        if (mysqli_query($conn, $updateSql)) {
            $updated++;
        } else {
            $skipped++;
        }
    } else {
        $columnNames = array_keys($values);
        $safeValues = [];
        foreach ($values as $value) {
            $safeValues[] = "'" . sql_value($conn, $value) . "'";
        }

        $insertSql = "INSERT INTO attendance (" . implode(', ', $columnNames) . ") VALUES (" . implode(', ', $safeValues) . ")";
        if (mysqli_query($conn, $insertSql)) {
            $imported++;
        } else {
            $skipped++;
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Attendance Imported</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; text-align:center; padding-top:50px; }
        .card { display:inline-block; background:#fff; padding:25px 35px; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,.08); }
        h2 { color:#138a36; margin-top:0; }
        .stats { margin:18px 0; line-height:1.9; text-align:left; }
        .btn { padding:10px 20px; background:#3498db; color:white; border:none; border-radius:5px; text-decoration:none; display:inline-block; margin:5px; }
        .btn-dark { background:#2c3e50; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Attendance Imported Successfully</h2>
        <div class="stats">
            <b>New rows:</b> <?php echo (int)$imported; ?><br>
            <b>Updated rows:</b> <?php echo (int)$updated; ?><br>
            <b>Skipped rows:</b> <?php echo (int)$skipped; ?>
        </div>
        <a href="attendance_report.php" class="btn">View Attendance Report</a>
        <a href="attendance_import.php" class="btn btn-dark">Upload Another File</a>
    </div>
</body>
</html>
