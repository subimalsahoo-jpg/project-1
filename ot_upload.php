<?php
include 'auth.php';
requirePermission('attendance_upload');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$message = "";

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS overtime_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_no VARCHAR(50) NOT NULL,
        attendance_date DATE NOT NULL,
        ot_hours DECIMAL(10,2) DEFAULT 0,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ot_day (user_no, attendance_date)
    )
");

function ot_excel_date_to_sql($value) {
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    if (is_numeric($value)) {
        return date('Y-m-d', Date::excelToTimestamp($value));
    }

    $value = trim((string)$value);

    $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'm/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt) {
            return $dt->format('Y-m-d');
        }
    }

    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : null;
}

function ot_hours_value($value) {
    $value = trim((string)$value);

    if ($value === '' || $value === '-' || strtoupper($value) === 'HOLIDAY') {
        return 0;
    }

    return is_numeric($value) ? (float)$value : 0;
}

if (isset($_POST['upload_ot']) && isset($_FILES['ot_file'])) {
    $file = $_FILES['ot_file']['tmp_name'];

    if ($file != '') {
        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();

            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            $dateColumns = [];

            for ($col = 2; $col <= $highestColumnIndex; $col++) {
                $cellAddress = Coordinate::stringFromColumnIndex($col) . '1';
                $headerValue = $sheet->getCell($cellAddress)->getValue();

                if (strtoupper(trim((string)$headerValue)) === 'TOTAL') {
                    break;
                }

                $sqlDate = ot_excel_date_to_sql($headerValue);
                if ($sqlDate) {
                    $dateColumns[$col] = $sqlDate;
                }
            }

            $saved = 0;
            $skipped = 0;

            for ($row = 3; $row <= $highestRow; $row++) {
                $user_no = trim((string)$sheet->getCell('A' . $row)->getValue());

                if ($user_no === '') {
                    $skipped++;
                    continue;
                }

                foreach ($dateColumns as $col => $attendance_date) {
                    $cellAddress = Coordinate::stringFromColumnIndex($col) . $row;
                    $cellValue = $sheet->getCell($cellAddress)->getCalculatedValue();
                    $ot_hours = ot_hours_value($cellValue);

                    $safe_user_no = mysqli_real_escape_string($conn, $user_no);
                    $safe_date = mysqli_real_escape_string($conn, $attendance_date);
                    $safe_ot = (float)$ot_hours;

                    mysqli_query($conn, "
                        INSERT INTO overtime_records (user_no, attendance_date, ot_hours)
                        VALUES ('$safe_user_no', '$safe_date', '$safe_ot')
                        ON DUPLICATE KEY UPDATE ot_hours='$safe_ot'
                    ");

                    $saved++;
                }
            }

            $message = "<div class='success'>OT Excel upload complete. Saved: $saved, Skipped rows: $skipped</div>";
        } catch (Exception $e) {
            $message = "<div class='error'>Upload error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>OT Upload</title>
<style>
body{font-family:Arial;background:#f4f6f9;padding:30px;}
.box{background:#fff;padding:25px;border-radius:8px;max-width:760px;}
.btn{background:#2c3e50;color:#fff;padding:10px 18px;border:none;text-decoration:none;cursor:pointer;display:inline-block;margin-right:6px;}
.success{background:#e9f8ee;color:green;padding:12px;margin:15px 0;font-weight:bold;}
.error{background:#fdecec;color:red;padding:12px;margin:15px 0;font-weight:bold;}
.progress-wrap{display:none;margin-top:15px;background:#ddd;height:18px;border-radius:10px;overflow:hidden;}
.progress-bar{height:18px;width:0;background:#1e90ff;}
.note{line-height:1.7;}
</style>
</head>
<body>

<a href="dashboard.php" class="btn">Dashboard</a>
<a href="overtime_report.php" class="btn">Over Time Report</a>

<h2>OT Excel Upload</h2>

<?php echo $message; ?>

<div class="box">
    <form method="POST" enctype="multipart/form-data" onsubmit="showProgress()">
        <div class="note">
            <b>Excel format:</b><br>
            Row 1: ID, 01-05-2026, 02-05-2026 ... TOTAL<br>
            Row 2: No problem if it's Day's name. <br>
            Row 3 There will be so much data from employees.<br>
            If blank / holiday / - it will be saved as 0.
        </div>

        <br>
        <input type="file" name="ot_file" accept=".xlsx,.xls,.csv" required>
        <br><br>

        <button type="submit" name="upload_ot" class="btn">Upload OT Excel</button>

        <div class="progress-wrap" id="progressWrap">
            <div class="progress-bar" id="progressBar"></div>
        </div>
    </form>
</div>

<script>
function showProgress(){
    document.getElementById('progressWrap').style.display = 'block';
    var bar = document.getElementById('progressBar');
    var width = 0;

    setInterval(function(){
        if(width < 90){
            width += 5;
            bar.style.width = width + '%';
        }
    }, 150);
}
</script>

</body>
</html>
