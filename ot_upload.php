<?php
include 'auth.php';
requirePermission('attendance_upload');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$message = "";

/* Store per-day OT. A `note` column keeps markers like A (Absent) and
   GP (Internal Gate Pass) so they can be shown / used later. */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS overtime_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_no VARCHAR(50) NOT NULL,
        attendance_date DATE NOT NULL,
        ot_hours DECIMAL(10,2) DEFAULT 0,
        note VARCHAR(50) DEFAULT '',
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ot_day (user_no, attendance_date)
    )
");
$noteCol = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'note'");
if ($noteCol && mysqli_num_rows($noteCol) === 0) {
    mysqli_query($conn, "ALTER TABLE overtime_records ADD COLUMN note VARCHAR(50) DEFAULT '' AFTER ot_hours");
}

/* Month name / abbreviation -> number. */
function ot_month_num($name) {
    $name = strtolower(trim((string)$name));
    $map = [
        'jan'=>1,'january'=>1,'feb'=>2,'february'=>2,'mar'=>3,'march'=>3,
        'apr'=>4,'april'=>4,'may'=>5,'jun'=>6,'june'=>6,'jul'=>7,'july'=>7,
        'aug'=>8,'august'=>8,'sep'=>9,'sept'=>9,'september'=>9,'oct'=>10,'october'=>10,
        'nov'=>11,'november'=>11,'dec'=>12,'december'=>12,
    ];
    return $map[$name] ?? 0;
}

/* A cell value -> [ot_hours, note]. Numeric = OT hours; A = Absent;
   GP = Internal Gate Pass; blank / '-' = 0. */
function ot_parse_cell($raw) {
    $v = strtoupper(trim((string)$raw));
    if ($v === '' || $v === '-') return [0.0, ''];
    if ($v === 'A')  return [0.0, 'Absent'];
    if ($v === 'GP') return [0.0, 'Internal Gate Pass'];
    if (is_numeric($v)) return [(float)$v, ''];
    return [0.0, ''];
}
?>

<?php
/* Detect the month (as Y-m) from the sheet heading, e.g.
   "Over Time Report Month of June 2026", "Month of June 2026",
   "Jun-26", or a date like 01-06-26 / 01-06-2026. */
function ot_detect_month_from_sheet($sheet) {
    $maxRow = min(15, $sheet->getHighestRow());
    $maxColIdx = min(40, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
    for ($r = 1; $r <= $maxRow; $r++) {
        for ($c = 1; $c <= $maxColIdx; $c++) {
            $val = trim((string)$sheet->getCell([$c, $r])->getValue());
            if ($val === '') continue;
            // "Month of June 2026"
            if (preg_match('/month\s+of\s+([A-Za-z]+)\s+(\d{4})/i', $val, $m)) {
                $mn = ot_month_num($m[1]);
                if ($mn) return sprintf('%04d-%02d', (int)$m[2], $mn);
            }
            // "Jun-26" / "June-2026"
            if (preg_match('/^([A-Za-z]{3,9})[\-\s\/](\d{2,4})$/', $val, $m)) {
                $mn = ot_month_num($m[1]);
                if ($mn) {
                    $yr = (int)$m[2]; if ($yr < 100) $yr += 2000;
                    return sprintf('%04d-%02d', $yr, $mn);
                }
            }
            // "01-06-26" / "01-06-2026" / "01/06/2026"
            if (preg_match('#^\d{1,2}[\-/](\d{1,2})[\-/](\d{2,4})$#', $val, $m)) {
                $mn = (int)$m[1]; $yr = (int)$m[2];
                if ($yr < 100) $yr += 2000;
                if ($mn >= 1 && $mn <= 12) return sprintf('%04d-%02d', $yr, $mn);
            }
        }
    }
    return '';
}

if (isset($_POST['upload_ot']) && isset($_FILES['ot_file']) && $_FILES['ot_file']['tmp_name'] !== '') {
    try {
        $spreadsheet = IOFactory::load($_FILES['ot_file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();

        // Month: auto-detect from the sheet heading first (source of truth),
        // fall back to the Month picker only if the heading isn't found.
        $month = ot_detect_month_from_sheet($sheet);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = trim((string)($_POST['ot_month'] ?? ''));
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $message = "<div class='error'>Could not determine the month. Please pick the Month above (or add a \"Month of ...\" heading in the sheet).</div>";
        } else {
            [$yr, $mo] = array_map('intval', explode('-', $month));
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $mo, 1, $yr));

            $rows = $sheet->toArray(null, true, true, true); // keyed by column letter


            // Find the header row (contains ID and NAME) and the day columns.
            $userCol = null; $nameCol = null; $dayColumns = []; $headerRow = null;
            foreach ($rows as $rowNumber => $row) {
                $norm = [];
                foreach ($row as $col => $val) {
                    $norm[$col] = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$val)));
                }
                $hasId = in_array('id', $norm, true);
                $hasName = in_array('name', $norm, true);
                if (!$hasId || !$hasName) continue;

                $headerRow = $rowNumber;
                foreach ($norm as $col => $n) {
                    if ($n === 'id' && $userCol === null)   $userCol = $col;
                    if ($n === 'name' && $nameCol === null) $nameCol = $col;
                }
                // Day columns = numeric headers 1..31, stop once TOTAL is reached.
                foreach ($row as $col => $val) {
                    $t = trim((string)$val);
                    if (preg_replace('/[^a-z0-9]/', '', strtolower($t)) === 'total') break;
                    if (ctype_digit($t) && (int)$t >= 1 && (int)$t <= 31 && (int)$t <= $daysInMonth) {
                        $dayColumns[$col] = (int)$t;
                    }
                }
                break;
            }

            if ($headerRow === null || $userCol === null || empty($dayColumns)) {
                $message = "<div class='error'>Could not read the grid. Make sure there is a header row with <b>ID</b>, <b>NAME</b> and day columns 1..31.</div>";
            } else {
                $saved = 0; $otDays = 0; $gp = 0; $absent = 0; $employeesSeen = 0;
                foreach ($rows as $rowNumber => $row) {
                    if ($rowNumber <= $headerRow) continue;
                    $user_no = trim((string)($row[$userCol] ?? ''));
                    if ($user_no === '' || !ctype_digit($user_no)) continue; // skips day-name / blank / total rows
                    $employeesSeen++;

                    foreach ($dayColumns as $col => $day) {
                        $date = sprintf('%04d-%02d-%02d', $yr, $mo, $day);
                        [$ot, $note] = ot_parse_cell($row[$col] ?? '');
                        if ($note === 'Internal Gate Pass') $gp++;
                        if ($note === 'Absent') $absent++;
                        if ($ot > 0) $otDays++;

                        $su = mysqli_real_escape_string($conn, $user_no);
                        $sd = mysqli_real_escape_string($conn, $date);
                        $sn = mysqli_real_escape_string($conn, $note);
                        $so = (float)$ot;
                        mysqli_query($conn, "
                            INSERT INTO overtime_records (user_no, attendance_date, ot_hours, note)
                            VALUES ('$su', '$sd', '$so', '$sn')
                            ON DUPLICATE KEY UPDATE ot_hours='$so', note='$sn'
                        ");
                        $saved++;
                    }
                }
                $monthLabel = date('F Y', mktime(0, 0, 0, $mo, 1, $yr));
                $message = "<div class='success'>OT uploaded for <b>$monthLabel</b>. Employees: $employeesSeen, Cells saved: $saved, OT days: $otDays, Internal Gate Pass: $gp, Absent: $absent.</div>";
            }
        }
    } catch (Exception $e) {
        $message = "<div class='error'>Upload error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>


<!DOCTYPE html>
<html>
<head>
<title>OT Upload</title>
<style>
body{font-family:Arial;background:#f4f6f9;padding:30px;}
.box{background:#fff;padding:25px;border-radius:8px;max-width:860px;}
.btn{background:#2c3e50;color:#fff;padding:10px 18px;border:none;text-decoration:none;cursor:pointer;display:inline-block;margin-right:6px;border-radius:5px;}
.success{background:#e9f8ee;color:green;padding:12px;margin:15px 0;font-weight:bold;border-radius:6px;}
.error{background:#fdecec;color:#b91c1c;padding:12px;margin:15px 0;font-weight:bold;border-radius:6px;}
.note{line-height:1.8;}
.legend{margin:10px 0;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;line-height:1.9;}
.chip{display:inline-block;padding:1px 8px;border-radius:5px;font-weight:700;font-size:12px;}
.chip.num{background:#dbeafe;color:#1e40af;}
.chip.a{background:#e2e8f0;color:#334155;}
.chip.gp{background:#fde68a;color:#92400e;}
.chip.yellow{background:#fef08a;color:#854d0e;}
.chip.red{background:#fecaca;color:#991b1b;}
label{font-weight:700;}
input[type=month]{padding:8px;border:1px solid #cbd5e1;border-radius:6px;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<a href="dashboard.php" class="btn">Dashboard</a>
<a href="overtime_report.php" class="btn">Over Time Report</a>
<a href="#" id="dlOtSheet" class="btn" style="background:#16a34a;">&#8681; Download OT Excel</a>
<a href="#" id="dlOtBlank" class="btn" style="background:#2563a8;">&#8681; Blank Template</a>

<h2>OT Excel Upload</h2>

<?php echo $message; ?>

<div class="box">
    <form method="POST" enctype="multipart/form-data">
        <div class="note">
            <b>Excel format (monthly grid):</b><br>
            &bull; A heading like <code>Month of June 2026</code> sets the month; day columns are just <code>1 2 3 … 31</code>.<br>
            &bull; Columns: <code>Sl No | ID (User No) | NAME | 1 … 31 | TOTAL</code>.<br>
            &bull; Each day cell = that day's Over Time hours.<br>
            &bull; Use the <b>&#8681; Download OT Excel</b> button above to get this exact sheet (pre-filled with the selected month's OT) &mdash; edit it and upload it back.
        </div>

        <div class="legend">
            <b>Cell values:</b><br>
            <span class="chip num">2 / 1.5</span> = Over Time hours &nbsp;
            <span class="chip a">A</span> = Absent &nbsp;
            <span class="chip gp">GP</span> = Internal Gate Pass &nbsp;
            (blank / <code>-</code> = 0)<br>
            <span class="chip yellow">Yellow</span> = on Vacation &nbsp;
            <span class="chip red">Red</span> = Resigned &nbsp;
            <span style="color:#64748b;">(colours are for reference; only numbers are imported as OT)</span>
        </div>

        <p>
            <label>Month (fallback — used only if the sheet has no "Month of ..." heading):</label><br>
            <input type="month" name="ot_month" value="">
        </p>

        <input type="file" name="ot_file" accept=".xlsx,.xls,.csv" required>
        <br><br>
        <button type="submit" name="upload_ot" class="btn">Upload OT Excel</button>
    </form>
</div>

<script>
(function () {
    function currentMonth() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }
    function monthVal() {
        var el = document.querySelector('input[name="ot_month"]');
        var v = el && el.value ? el.value : '';
        return /^\d{4}-\d{2}$/.test(v) ? v : currentMonth();
    }
    var withData = document.getElementById('dlOtSheet');
    var blank    = document.getElementById('dlOtBlank');
    if (withData) withData.addEventListener('click', function (e) {
        e.preventDefault();
        window.location.href = 'ot_sheet_export.php?month=' + encodeURIComponent(monthVal());
    });
    if (blank) blank.addEventListener('click', function (e) {
        e.preventDefault();
        window.location.href = 'ot_sheet_export.php?month=' + encodeURIComponent(monthVal()) + '&blank=1';
    });
})();
</script>

</body>
</html>
