<?php
/* ─────────────────────────────────────────────────────────────
   OT Sheet Export  (ot_sheet_export.php)

   Generates a real .xlsx in the SAME monthly-grid format that
   ot_upload.php expects, so the file round-trips (export → edit
   → re-upload).

   Format produced:
     Row 1 : Company name
     Row 2 : "Over Time Report  —  Month of <Month Year>"   (sets month)
     Row 3 : Generated timestamp
     Row 4 : Sl No | ID | NAME | 1 | 2 | … | <days> | TOTAL   (header)
     Row 5+: one row per employee, day cells prefilled with OT

   Cell values (match the upload legend):
     number  = Over Time hours
     A       = Absent
     GP      = Internal Gate Pass
     (blank) = 0

   Query string:
     ?month=YYYY-MM   month to export (default: current month)
     ?blank=1         produce an empty template (no OT prefilled)
   ───────────────────────────────────────────────────────────── */

include 'auth.php';
requireAnyPermission(['attendance_upload', 'reports_view', 'overtime_view']);
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/* ── Month ─────────────────────────────────────────────────── */
$month = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
[$yr, $mo]   = array_map('intval', explode('-', $month));
$daysInMonth = (int) date('t', mktime(0, 0, 0, $mo, 1, $yr));
$monthLabel  = date('F Y', mktime(0, 0, 0, $mo, 1, $yr)); // e.g. "June 2026"
$blank       = isset($_GET['blank']) && $_GET['blank'] == '1';

/* ── Which optional employee columns exist ─────────────────── */
$empCols = [];
$cr = mysqli_query($conn, "SHOW COLUMNS FROM employees");
if ($cr) { while ($c = mysqli_fetch_assoc($cr)) { $empCols[$c['Field']] = true; } }
$statusCol = isset($empCols['employee_status']) ? 'employee_status'
           : (isset($empCols['status']) ? 'status' : null);
$hasStatus = $statusCol !== null;
$hasDept   = isset($empCols['department']);

/* ── Employees (numeric user_no only → importable) ─────────── */
$selectCols = "user_no, COALESCE(full_name,'') AS full_name"
            . ($hasDept   ? ", COALESCE(department,'') AS department" : "")
            . ($hasStatus ? ", COALESCE($statusCol,'') AS status"     : "");
$empRes = mysqli_query($conn, "
    SELECT $selectCols
    FROM employees
    WHERE user_no IS NOT NULL AND user_no <> ''
    ORDER BY CAST(user_no AS UNSIGNED) ASC, user_no ASC
");
$employees = [];
if ($empRes) { while ($e = mysqli_fetch_assoc($empRes)) { $employees[] = $e; } }

/* ── Existing OT for the month  ($otMap[user_no][day]) ─────── */
$otMap = [];
if (!$blank) {
    $safeMonth = mysqli_real_escape_string($conn, $month);
    $otRes = mysqli_query($conn, "
        SELECT user_no, DAY(attendance_date) AS d,
               ot_hours, COALESCE(note,'') AS note
        FROM overtime_records
        WHERE DATE_FORMAT(attendance_date, '%Y-%m') = '$safeMonth'
    ");
    if ($otRes) {
        while ($r = mysqli_fetch_assoc($otRes)) {
            $otMap[$r['user_no']][(int) $r['d']] = $r;
        }
    }
}

/* ── Absence & vacation info for the month (prefill / colour) ──
   $absentMap[user_no][day]   = true → show "A"
   $vacationMap[user_no][day] = true → colour the cell yellow      */
$absentMap   = [];
$vacationMap = [];

$otExportTableExists = function ($conn, $t) {
    $r = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $r && mysqli_num_rows($r) > 0;
};

if (!$blank) {
    $safeMonth  = mysqli_real_escape_string($conn, $month);
    $monthStart = sprintf('%04d-%02d-01', $yr, $mo);
    $monthEnd   = sprintf('%04d-%02d-%02d', $yr, $mo, $daysInMonth);

    $hasVacations = $otExportTableExists($conn, 'vacations');
    $hasHolidays  = $otExportTableExists($conn, 'holidays');

    // Vacation days → yellow. Effective end = return_date or to_date.
    if ($hasVacations) {
        $vres = mysqli_query($conn, "
            SELECT user_no, from_date,
                   COALESCE(NULLIF(return_date,'0000-00-00'), to_date) AS eff_to
            FROM vacations
            WHERE COALESCE(vacation_status,'') <> 'Cancelled'
              AND from_date <= '$monthEnd'
              AND COALESCE(NULLIF(return_date,'0000-00-00'), to_date) >= '$monthStart'
        ");
        if ($vres) {
            while ($v = mysqli_fetch_assoc($vres)) {
                $uno  = trim((string) $v['user_no']);
                $from = strtotime((string) $v['from_date']);
                $to   = strtotime((string) $v['eff_to']);
                if ($uno === '' || !$from || !$to) continue;
                $from = max($from, strtotime($monthStart));
                $to   = min($to,   strtotime($monthEnd));
                for ($t = $from; $t <= $to; $t += 86400) {
                    $vacationMap[$uno][(int) date('j', $t)] = true;
                }
            }
        }
    }

    // Absent days → "A". Empty check-in, excluding Sundays, holidays and vacation.
    if ($otExportTableExists($conn, 'attendance')) {
        $holClause = $hasHolidays
            ? " AND a.attendance_date NOT IN (SELECT holiday_date FROM holidays)"
            : "";
        $ares = mysqli_query($conn, "
            SELECT a.user_no, DAY(a.attendance_date) AS d
            FROM attendance a
            WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = '$safeMonth'
              AND (a.check_in IS NULL OR TRIM(a.check_in) = '')
              AND DAYNAME(a.attendance_date) <> 'Sunday'
              $holClause
        ");
        if ($ares) {
            while ($a = mysqli_fetch_assoc($ares)) {
                $uno = trim((string) $a['user_no']);
                $day = (int) $a['d'];
                if ($uno === '' || isset($vacationMap[$uno][$day])) continue; // vacation wins over absent
                $absentMap[$uno][$day] = true;
            }
        }
    }
}

/* Convert a stored OT record to the cell value used in the sheet. */
function ot_cell_value($rec) {
    if (!$rec) return null;
    $note = strtolower((string) $rec['note']);
    if (strpos($note, 'absent') !== false)    return 'A';
    if (strpos($note, 'gate pass') !== false) return 'GP';
    $h = (float) $rec['ot_hours'];
    return $h > 0 ? $h : null;
}

/* ── Build the spreadsheet ─────────────────────────────────── */
$company = defined('COMPANY_NAME') ? COMPANY_NAME : 'EURO TROUSERS MFG CO (FZC)';

$COL_SL    = 1;
$COL_ID    = 2;
$COL_NAME  = 3;
$COL_DAY1  = 4;
$COL_LAST  = $COL_DAY1 + $daysInMonth;            // TOTAL column index
$col       = fn($i) => Coordinate::stringFromColumnIndex($i); // column index → letter
$lastColL  = Coordinate::stringFromColumnIndex($COL_LAST);
$day1ColL  = Coordinate::stringFromColumnIndex($COL_DAY1);
$dayNColL  = Coordinate::stringFromColumnIndex($COL_DAY1 + $daysInMonth - 1);

$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('OT ' . date('M Y', mktime(0, 0, 0, $mo, 1, $yr)));

/* Row 1 – company */
$sheet->setCellValue('A1', $company);
$sheet->mergeCells('A1:' . $lastColL . '1');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A5C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

/* Row 2 – "Month of June 2026"  (this is what sets the month on import) */
$sheet->setCellValue('A2', 'Over Time Report  —  Month of ' . $monthLabel);
$sheet->mergeCells('A2:' . $lastColL . '2');
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '1A3A5C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

/* Row 3 – generated */
$sheet->setCellValue('A3', 'Generated: ' . date('d-m-Y h:i A') . ($blank ? '  (blank template)' : ''));
$sheet->mergeCells('A3:' . $lastColL . '3');
$sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');

/* Row 4 – header */
$HEADER_ROW = 4;
$sheet->setCellValue($col($COL_SL)   . $HEADER_ROW, 'Sl No');
$sheet->setCellValue($col($COL_ID)   . $HEADER_ROW, 'ID');
$sheet->setCellValue($col($COL_NAME) . $HEADER_ROW, 'NAME');
for ($d = 1; $d <= $daysInMonth; $d++) {
    $sheet->setCellValueExplicit(
        $col($COL_DAY1 + $d - 1) . $HEADER_ROW,
        (string) $d,
        DataType::TYPE_STRING
    );
}
$sheet->setCellValue($lastColL . $HEADER_ROW, 'TOTAL');

$sheet->getStyle($col($COL_SL) . $HEADER_ROW . ':' . $lastColL . $HEADER_ROW)->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A5C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension($HEADER_ROW)->setRowHeight(20);

/* Row 5 – day-of-week row (Su, Mo, Tu, … under each date) */
$DAY_ROW    = $HEADER_ROW + 1;
$SUNDAY_RED = 'FFD5D5';
$sheet->setCellValue($col($COL_NAME) . $DAY_ROW, 'Day');
for ($d = 1; $d <= $daysInMonth; $d++) {
    $lbl = substr(date('D', mktime(0, 0, 0, $mo, $d, $yr)), 0, 2); // Su, Mo, Tu, …
    $sheet->setCellValueExplicit($col($COL_DAY1 + $d - 1) . $DAY_ROW, $lbl, DataType::TYPE_STRING);
}
// Base style for the whole day row
$sheet->getStyle($col($COL_SL) . $DAY_ROW . ':' . $lastColL . $DAY_ROW)->applyFromArray([
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '334155']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2F7']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle($col($COL_NAME) . $DAY_ROW)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getRowDimension($DAY_ROW)->setRowHeight(16);
// Sunday → light red (date header cell + day-of-week cell)
for ($d = 1; $d <= $daysInMonth; $d++) {
    if ((int) date('N', mktime(0, 0, 0, $mo, $d, $yr)) !== 7) continue; // 7 = Sunday
    $dc = $col($COL_DAY1 + $d - 1);
    $sheet->getStyle($dc . $HEADER_ROW)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($SUNDAY_RED);
    $sheet->getStyle($dc . $HEADER_ROW)->getFont()->getColor()->setRGB('991B1B');
    $sheet->getStyle($dc . $DAY_ROW)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($SUNDAY_RED);
}

/* ── Data rows ─────────────────────────────────────────────── */
$VAC_YELLOW = 'FEF08A';   // on Vacation
$RESIGN_RED = 'FECACA';   // Resigned
$rowNum = $HEADER_ROW + 2;
$sl     = 1;
foreach ($employees as $emp) {
    $uno      = trim((string) $emp['user_no']);
    $resigned = $hasStatus && strcasecmp(trim((string) $emp['status']), 'Resigned') === 0;

    $sheet->setCellValueExplicit($col($COL_ID) . $rowNum, (string) $uno, DataType::TYPE_STRING);
    $sheet->setCellValue($col($COL_SL)   . $rowNum, $sl);
    $sheet->setCellValue($col($COL_NAME) . $rowNum, $emp['full_name']);

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $cellRef  = $col($COL_DAY1 + $d - 1) . $rowNum;
        $isSunday = (int) date('N', mktime(0, 0, 0, $mo, $d, $yr)) === 7;
        $onLeave  = isset($vacationMap[$uno][$d]);

        // Cell text: OT number / A / GP from a prior upload; else "A" from attendance.
        $val = ot_cell_value($otMap[$uno][$d] ?? null);
        if ($val === null && isset($absentMap[$uno][$d])) $val = 'A';

        if (is_string($val)) {
            $sheet->setCellValueExplicit($cellRef, $val, DataType::TYPE_STRING);
        } elseif ($val !== null) {
            $sheet->setCellValue($cellRef, $val);
        }

        // Cell fill (a resigned row is painted red afterwards and overrides these).
        $fill = null;
        if ($onLeave)          $fill = $VAC_YELLOW; // Vacation → yellow
        elseif ($val === 'A')  $fill = 'E2E8F0';    // Absent → light gray
        elseif ($val === 'GP') $fill = 'FDE68A';    // Internal Gate Pass → amber
        elseif ($isSunday)     $fill = $SUNDAY_RED; // Sunday column → light red
        if ($fill !== null) {
            $sheet->getStyle($cellRef)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
    }

    // TOTAL = numeric sum of the day cells (text like A / GP is ignored by SUM)
    $sheet->setCellValue(
        $lastColL . $rowNum,
        '=SUM(' . $day1ColL . $rowNum . ':' . $dayNColL . $rowNum . ')'
    );

    // Resigned → whole row red (reference only, not imported)
    if ($resigned) {
        $sheet->getStyle($col($COL_SL) . $rowNum . ':' . $lastColL . $rowNum)
              ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($RESIGN_RED);
    }

    $rowNum++;
    $sl++;
}
$lastDataRow = $rowNum - 1;

/* ── Grid styling: borders + alignment ─────────────────────── */
if ($lastDataRow >= $HEADER_ROW) {
    $range = $col($COL_SL) . $HEADER_ROW . ':' . $lastColL . $lastDataRow;
    $sheet->getStyle($range)->getBorders()->getAllBorders()
          ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
    // Center Sl No, ID, day cells, TOTAL
    $sheet->getStyle($col($COL_SL) . ($HEADER_ROW + 2) . ':' . $col($COL_ID) . $lastDataRow)
          ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($day1ColL . ($HEADER_ROW + 2) . ':' . $lastColL . $lastDataRow)
          ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($lastColL . ($HEADER_ROW + 2) . ':' . $lastColL . $lastDataRow)
          ->getFont()->setBold(true);
}

/* ── Legend (reference only; skipped on import) ────────────── */
$legendRow = $lastDataRow + 2;
$sheet->setCellValue('A' . $legendRow,
    'Legend:  number = OT hours   |   A = Absent   |   GP = Internal Gate Pass   |   blank = 0   '
    . '|   Yellow = on Vacation   |   Red = Resigned   (colours are for reference; only numbers import as OT)');
$sheet->mergeCells('A' . $legendRow . ':' . $lastColL . $legendRow);
$sheet->getStyle('A' . $legendRow)->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('475569');

/* ── Column widths + freeze panes ──────────────────────────── */
$sheet->getColumnDimension($col($COL_SL))->setWidth(6);
$sheet->getColumnDimension($col($COL_ID))->setWidth(10);
$sheet->getColumnDimension($col($COL_NAME))->setWidth(26);
for ($d = 1; $d <= $daysInMonth; $d++) {
    $sheet->getColumnDimension($col($COL_DAY1 + $d - 1))->setWidth(4.5);
}
$sheet->getColumnDimension($lastColL)->setWidth(8);
$sheet->freezePane($day1ColL . ($HEADER_ROW + 2)); // freeze Sl/ID/NAME + header + day row

/* ── Stream the file ───────────────────────────────────────── */
$fname = 'ot_sheet_' . $month . ($blank ? '_blank' : '') . '.xlsx';

// Clear any accidental output so the file isn't corrupted
if (ob_get_length()) { ob_end_clean(); }

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
