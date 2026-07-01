<?php
set_time_limit(0);
ini_set('memory_limit', '-1');

include 'auth.php';
requirePermission('attendance_upload');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// CrossChex export folder. Daily files can be 1.xls, 2.xls, 3.xls, etc.
$exportDir = 'C:\\Users\\Administrator\\Desktop\\Attendance Report';

$defaultDepartment = '';
$defaultTimetable = 'Anviz';
$defaultOnDuty = '07:00:00';
$defaultOffDuty = '20:00:00';
$defaultSchedule = '07:00-20:00';

function sync_sql($conn, $value) {
    return mysqli_real_escape_string($conn, (string)$value);
}

function normalize_header($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9]/', '', $value);
}

function get_cell_value($row, $columns, $key) {
    if (!isset($columns[$key])) return '';
    return $row[$columns[$key]] ?? '';
}

function clean_text_value($value) {
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    return trim((string)$value);
}

function format_excel_date_value($value) {
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d');
    if (is_numeric($value) && (float)$value > 20000) {
        try { return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d'); } catch (Exception $e) {}
    }

    $value = trim((string)$value);
    if ($value === '') return '';

    foreach (['d-m-Y', 'd-M-Y', 'Y-m-d', 'm-d-Y', 'd/m/Y', 'd/M/Y'] as $format) {
        $dt = DateTime::createFromFormat($format, str_replace('/', '-', $value));
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }

    $timestamp = strtotime(str_replace('/', '-', $value));
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function format_excel_time_value($value) {
    if ($value instanceof DateTimeInterface) return $value->format('H:i:s');
    if (is_numeric($value)) {
        try { return ExcelDate::excelToDateTimeObject($value)->format('H:i:s'); } catch (Exception $e) {}
    }

    $value = trim((string)$value);
    if ($value === '') return '';
    $timestamp = strtotime($value);
    return $timestamp ? date('H:i:s', $timestamp) : $value;
}

function ensure_crosschex_sync_table($conn) {
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS crosschex_sync_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT DEFAULT 0,
            file_mtime INT DEFAULT 0,
            imported_rows INT DEFAULT 0,
            updated_days INT DEFAULT 0,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mtime_size (file_mtime, file_size)
        )
    ");

    /* A file is now considered "already synced" purely by its date/time (the
       file's modified time) + size — NOT by its name. So a re-generated file
       reusing an old name (e.g. 1.xls) still syncs as long as its date/time is
       new. Migrate older tables that used the (file_name, file_size, file_mtime)
       key. */
    $hasOldKey = false;
    $hasNewKey = false;
    $idx = mysqli_query($conn, "SHOW INDEX FROM crosschex_sync_files");
    if ($idx) {
        while ($r = mysqli_fetch_assoc($idx)) {
            $keyName = $r['Key_name'] ?? '';
            if ($keyName === 'uniq_file')       $hasOldKey = true;
            if ($keyName === 'uniq_mtime_size')  $hasNewKey = true;
        }
    }
    if ($hasOldKey) {
        mysqli_query($conn, "ALTER TABLE crosschex_sync_files DROP INDEX uniq_file");
    }
    if (!$hasNewKey) {
        // Drop any exact (mtime,size) duplicates before enforcing the new key.
        mysqli_query($conn, "
            DELETE t1 FROM crosschex_sync_files t1
            INNER JOIN crosschex_sync_files t2
              ON t1.file_mtime = t2.file_mtime AND t1.file_size = t2.file_size AND t1.id > t2.id
        ");
        mysqli_query($conn, "ALTER TABLE crosschex_sync_files ADD UNIQUE KEY uniq_mtime_size (file_mtime, file_size)");
    }
}

function employee_by_device_id($conn, $deviceId) {
    $deviceId = ltrim(trim((string)$deviceId), '0');
    if ($deviceId === '') $deviceId = '0';

    $safe = sync_sql($conn, $deviceId);
    $query = mysqli_query($conn, "
        SELECT user_no, employee_id, full_name, department
        FROM employees
        WHERE user_no = '$safe'
           OR employee_id = '$safe'
           OR card_no = '$safe'
        LIMIT 1
    ");

    return $query ? mysqli_fetch_assoc($query) : null;
}

function save_attendance_day($conn, $values) {
    $safeUserNo = sync_sql($conn, $values['user_no']);
    $safeEmployeeId = sync_sql($conn, $values['employee_id']);
    $safeDate = sync_sql($conn, $values['attendance_date']);

    $existing = mysqli_query($conn, "
        SELECT id
        FROM attendance
        WHERE attendance_date = '$safeDate'
        AND (
            (user_no != '' AND user_no = '$safeUserNo')
            OR (employee_id != '' AND employee_id = '$safeEmployeeId')
        )
        LIMIT 1
    ");
    $existingRow = $existing ? mysqli_fetch_assoc($existing) : null;

    if ($existingRow) {
        $sets = [];
        foreach ($values as $column => $value) {
            $sets[] = "$column = '" . sync_sql($conn, $value) . "'";
        }
        return mysqli_query($conn, "UPDATE attendance SET " . implode(', ', $sets) . " WHERE id = " . (int)$existingRow['id']);
    }

    $columns = array_keys($values);
    $safeValues = [];
    foreach ($values as $value) {
        $safeValues[] = "'" . sync_sql($conn, $value) . "'";
    }

    return mysqli_query($conn, "INSERT INTO attendance (" . implode(',', $columns) . ") VALUES (" . implode(',', $safeValues) . ")");
}

function import_attendance_excel_file($conn, $filePath) {
    $spreadsheet = IOFactory::load($filePath);
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

        if (!$dateFound || (!$userFound && !$nameFound)) continue;

        $headerRowNumber = $rowNumber;

        foreach ($headerAliases as $field => $aliases) {
            foreach ($normalizedCells as $columnLetter => $normalizedHeader) {
                if (in_array($normalizedHeader, $aliases, true)) {
                    $columns[$field] = $columnLetter;
                    break;
                }
            }
        }

        if (!isset($columns['user_no'])) $columns['user_no'] = 'B';
        if (!isset($columns['employee_id'])) $columns['employee_id'] = 'C';
        if (!isset($columns['employee_name'])) $columns['employee_name'] = 'D';
        if (!isset($columns['schedule_time'])) $columns['schedule_time'] = 'I';
        if (!isset($columns['check_in'])) $columns['check_in'] = 'J';
        if (!isset($columns['check_out'])) $columns['check_out'] = 'K';
        if (!isset($columns['late_time'])) $columns['late_time'] = 'L';
        if (!isset($columns['early_time'])) $columns['early_time'] = 'M';
        if (!isset($columns['overtime'])) $columns['overtime'] = 'N';
        break;
    }

    if ($headerRowNumber === null || !isset($columns['attendance_date'])) {
        return [0, 0];
    }

    $importedRows = 0;
    $updatedDays = 0;

    for ($rowNumber = $headerRowNumber + 1; $rowNumber <= count($rows); $rowNumber++) {
        if (!isset($rows[$rowNumber])) continue;
        $row = $rows[$rowNumber];

        $department  = clean_text_value(get_cell_value($row, $columns, 'department'));
        $userNo      = clean_text_value(get_cell_value($row, $columns, 'user_no'));
        $employeeId  = clean_text_value(get_cell_value($row, $columns, 'employee_id'));
        $name        = clean_text_value(get_cell_value($row, $columns, 'employee_name'));
        $date        = format_excel_date_value(get_cell_value($row, $columns, 'attendance_date'));
        $timetable   = clean_text_value(get_cell_value($row, $columns, 'timetable'));
        $onDuty      = format_excel_time_value(get_cell_value($row, $columns, 'on_duty'));
        $offDuty     = format_excel_time_value(get_cell_value($row, $columns, 'off_duty'));
        $schedule    = clean_text_value(get_cell_value($row, $columns, 'schedule_time'));
        $checkIn     = format_excel_time_value(get_cell_value($row, $columns, 'check_in'));
        $checkOut    = format_excel_time_value(get_cell_value($row, $columns, 'check_out'));
        $late        = format_excel_time_value(get_cell_value($row, $columns, 'late_time'));
        $early       = format_excel_time_value(get_cell_value($row, $columns, 'early_time'));
        $overtime    = format_excel_time_value(get_cell_value($row, $columns, 'overtime'));

        if ($userNo === '' && $employeeId === '') continue;
        if ($date === '') continue;

        if ($userNo === '' && $employeeId !== '') {
            $employee = employee_by_device_id($conn, $employeeId);
            $userNo = $employee['user_no'] ?? $employeeId;
            $name = $name ?: ($employee['full_name'] ?? '');
            $department = $department ?: ($employee['department'] ?? '');
        }

        if (($onDuty === '' || $offDuty === '') && preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $schedule, $matches)) {
            if ($onDuty === '') $onDuty = format_excel_time_value($matches[1]);
            if ($offDuty === '') $offDuty = format_excel_time_value($matches[2]);
        }

        $ok = save_attendance_day($conn, [
            'department' => $department,
            'user_no' => $userNo,
            'employee_id' => $employeeId ?: $userNo,
            'employee_name' => $name,
            'attendance_date' => $date,
            'timetable' => $timetable ?: $GLOBALS['defaultTimetable'],
            'on_duty' => $onDuty ?: $GLOBALS['defaultOnDuty'],
            'off_duty' => $offDuty ?: $GLOBALS['defaultOffDuty'],
            'schedule_time' => $schedule ?: $GLOBALS['defaultSchedule'],
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'late_time' => $late,
            'early_time' => $early,
            'overtime' => $overtime,
            'status' => $checkIn === '' ? 'Absent' : 'Present',
        ]);

        if ($ok) {
            $importedRows++;
            $updatedDays++;
        }
    }

    return [$importedRows, $updatedDays];
}

ensure_crosschex_sync_table($conn);

$shouldRun = isset($_GET['run']) && $_GET['run'] === '1';
$messages = [];
$totalImportedRows = 0;
$totalUpdatedDays = 0;
$processedFiles = 0;

if ($shouldRun && !is_dir($exportDir)) {
    $messages[] = "Export folder not found: " . $exportDir;
} elseif ($shouldRun) {
    $files = glob($exportDir . DIRECTORY_SEPARATOR . '*.{xls,xlsx,csv}', GLOB_BRACE);
    sort($files, SORT_NATURAL);

    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        $fileSize = filesize($filePath);
        $fileMtime = filemtime($filePath);

        $safeFileName = sync_sql($conn, $fileName);
        $safeFileSize = (int)$fileSize;
        $safeFileMtime = (int)$fileMtime;

        /* Skip only if a file with this SAME date/time (modified time) + size
           was already synced. The file name is intentionally NOT part of this
           check, so a new file that reuses an old name still syncs. */
        $already = mysqli_query($conn, "
            SELECT id
            FROM crosschex_sync_files
            WHERE file_mtime = $safeFileMtime
              AND file_size = $safeFileSize
            LIMIT 1
        ");

        if ($already && mysqli_fetch_assoc($already)) continue;

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        [$importedRows, $updatedDays] = import_attendance_excel_file($conn, $filePath);

        mysqli_query($conn, "
            INSERT IGNORE INTO crosschex_sync_files
                (file_name, file_size, file_mtime, imported_rows, updated_days)
            VALUES
                ('$safeFileName', $safeFileSize, $safeFileMtime, $importedRows, $updatedDays)
        ");

        $processedFiles++;
        $totalImportedRows += $importedRows;
        $totalUpdatedDays += $updatedDays;
    }
}

$syncStatus = 'ready';
$syncTitle = 'Ready to Sync';
$syncMessage = 'Attendance sync will start now.';

if ($shouldRun) {
    if ($messages) {
        $syncStatus = 'error';
        $syncTitle = 'Sync Failed';
        $syncMessage = 'Please check the export folder and try again.';
    } elseif ($processedFiles > 0) {
        $syncStatus = 'success';
        $syncTitle = 'Attendance Sync Successful';
        $syncMessage = 'Attendance sync completed successfully.';
    } else {
        $syncStatus = 'already';
        $syncTitle = 'Already Synced';
        $syncMessage = 'No new file found. Attendance is already synced.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>CrossChex Auto Sync</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f4f6f9; padding:30px; }
        .card { max-width:820px; margin:0 auto; background:#fff; padding:24px; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,.08); }
        .btn { background:#2c3e50; color:#fff; padding:10px 18px; border:none; border-radius:5px; cursor:pointer; text-decoration:none; display:inline-block; margin-right:8px; }
        .btn-blue { background:#3498db; }
        .ok { color:#138a36; }
        .warn { color:#c2410c; }
        .stats { line-height:1.9; margin:15px 0; }
        code { background:#eef2f7; padding:2px 5px; border-radius:4px; }
        .modal-cover { position:fixed; inset:0; background:rgba(15,23,42,.45); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-box { width:380px; max-width:90vw; background:#fff; border-radius:12px; padding:26px; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.25); }
        .spinner { width:46px; height:46px; margin:0 auto 14px; border:5px solid #dbeafe; border-top-color:#2563eb; border-radius:50%; animation:spin .8s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .status-box { padding:12px 14px; border-radius:8px; margin:14px 0; font-weight:700; }
        .status-success { background:#dcfce7; color:#166534; }
        .status-already { background:#fef9c3; color:#854d0e; }
        .status-error { background:#fee2e2; color:#991b1b; }
    </style>
    <?php if (!$shouldRun): ?>
    <script>
        setTimeout(function () {
            window.location.href = 'auto_import_crosschex.php?run=1';
        }, 700);
    </script>
    <?php endif; ?>
</head>
<body>
<?php if (!$shouldRun): ?>
<div class="modal-cover">
    <div class="modal-box">
        <div class="spinner"></div>
        <h2>Please wait</h2>
        <p>Attendance sync running...</p>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>CrossChex Auto Sync</h2>
    <p>Export folder: <code><?php echo htmlspecialchars($exportDir); ?></code></p>
    <p>Supported files: <code>1.xls, 2.xls, 3.xls, .xlsx, .csv</code></p>

    <?php if ($shouldRun): ?>
        <div class="status-box status-<?php echo htmlspecialchars($syncStatus); ?>">
            <?php echo htmlspecialchars($syncTitle); ?> - <?php echo htmlspecialchars($syncMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($shouldRun && $messages): ?>
        <?php foreach ($messages as $msg): ?>
            <p class="warn"><?php echo htmlspecialchars($msg); ?></p>
        <?php endforeach; ?>
    <?php elseif ($shouldRun): ?>
        <div class="stats ok">
            <b>Processed files:</b> <?php echo (int)$processedFiles; ?><br>
            <b>Imported/updated rows:</b> <?php echo (int)$totalImportedRows; ?><br>
            <b>Updated attendance days:</b> <?php echo (int)$totalUpdatedDays; ?>
        </div>
    <?php else: ?>
        <div class="stats ok">
            <b>Please wait...</b><br>
            Attendance sync is starting now.
        </div>
    <?php endif; ?>

    <a href="auto_import_crosschex.php" class="btn btn-blue">Run Sync Again</a>
    <a href="attendance_report.php" class="btn">View Attendance Report</a>
    <a href="dashboard.php" class="btn">Dashboard</a>
</div>
</body>
</html>
