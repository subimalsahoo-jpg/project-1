<?php
/* ─────────────────────────────────────────────
   Employee Overtime Report (formal, printable)

   A single employee's overtime report in a proper payroll-document format:
   company header, employee details, date-wise OT details and a signature
   footer. Reads from the same overtime_records + attendance + employees data.

   Usage: overtime_report_print.php?user_no=1023&month=2026-05
          overtime_report_print.php?user_no=1023&from=2026-05-01&to=2026-05-31
───────────────────────────────────────────── */
include 'auth.php';
requirePermission('reports_view');

/* ── Company identity (edit here if it changes) ── */
$COMPANY_NAME = COMPANY_NAME;
$COMPANY_LOGO = company_logo_url();

/* ── Inputs / date range ── */
$user_no   = trim($_GET['user_no'] ?? '');
$month_in  = normalize_input_month($_GET['month'] ?? '', '');
$from_date = normalize_input_date($_GET['from'] ?? '');
$to_date   = normalize_input_date($_GET['to'] ?? '');

if ($from_date === '' || $to_date === '') {
    $base_month = $month_in !== '' ? $month_in : date('Y-m');
    $from_date  = $base_month . '-01';
    $to_date    = date('Y-m-t', strtotime($from_date));
}

$safe_user = mysqli_real_escape_string($conn, $user_no);
$safe_from = mysqli_real_escape_string($conn, $from_date);
$safe_to   = mysqli_real_escape_string($conn, $to_date);

/* ── Helpers ── */
function ot_col_exists($conn, $table, $col) {
    $col = mysqli_real_escape_string($conn, $col);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $q && mysqli_num_rows($q) > 0;
}
function t2s($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '00:00:00' || $v === '00:00') return 0;
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) return 0;
    return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
}
function hm($seconds) {
    $seconds = max(0, (int)round($seconds));
    return intdiv($seconds, 3600) . 'h ' . str_pad((string)intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
}
function hhmm($v) {
    $s = t2s($v);
    if ($s <= 0) return '—';
    return sprintf('%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
}
function money2($v) { return number_format((float)$v, 2); }

/* ── Employee ── */
$employee = null;
if ($user_no !== '') {
    $employee = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT * FROM employees WHERE user_no='$safe_user' OR employee_id='$safe_user' LIMIT 1
    "));
}

/* ── Holiday dates in range ── */
$holiday_map = [];
$hq = mysqli_query($conn, "SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN '$safe_from' AND '$safe_to'");
if ($hq) while ($h = mysqli_fetch_assoc($hq)) $holiday_map[$h['holiday_date']] = $h['holiday_name'] ?? 'Holiday';

/* ── OT rows (OT records joined with attendance for in/out times) ── */
$has_timetable = ot_col_exists($conn, 'attendance', 'timetable');
$timetable_sel = $has_timetable ? "a.timetable" : "''";

$rows = [];
if ($employee) {
    $emp_user = mysqli_real_escape_string($conn, $employee['user_no']);
    $res = mysqli_query($conn, "
        SELECT o.attendance_date, o.ot_hours, COALESCE(o.note,'') AS note,
               a.check_in, a.check_out, $timetable_sel AS timetable
        FROM overtime_records o
        LEFT JOIN attendance a
            ON a.user_no = o.user_no AND a.attendance_date = o.attendance_date
        WHERE o.user_no='$emp_user'
          AND o.attendance_date BETWEEN '$safe_from' AND '$safe_to'
          AND o.ot_hours > 0
        ORDER BY o.attendance_date ASC
    ");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
}

/* ── Rates ── */
$basic_salary  = (float)($employee['basic_salary'] ?? 0);
$hourly_base   = $basic_salary > 0 ? ($basic_salary / 30 / 8) : 0;
$rate_regular  = round($hourly_base * 1.25, 2); // normal-day OT
$rate_weekend  = round($hourly_base * 1.50, 2); // Sunday / holiday OT

/* ── Totals ── */
$tot_ot_hours = 0; $tot_ot_amount = 0; $tot_reg = 0; $tot_work = 0;
$tot_holiday = 0; $tot_weekend = 0;

$shift_name = trim($employee['day_shift'] ?? '');
if ($shift_name === '' && $has_timetable && !empty($rows[0]['timetable'])) $shift_name = $rows[0]['timetable'];
if ($shift_name === '') $shift_name = 'General';

$printed_by   = $_SESSION['username'] ?? ($_SESSION['full_name'] ?? 'Admin');
$generated_at = date('d-m-Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Overtime Report</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #eef2f7; color: #1e293b; font-size: 13px; }

.toolbar {
    background: #1a3a5c; color: #fff; padding: 10px 20px;
    display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;
}
.toolbar .tbtn {
    background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.3); color: #fff;
    padding: 7px 15px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer;
}
.toolbar .tbtn:hover { background: rgba(255,255,255,.25); }
.toolbar .tbtn.accent { background: #e8a020; color: #1a1a1a; border-color: #e8a020; }

/* Paper sheet */
.sheet {
    background: #fff; max-width: 1100px; margin: 20px auto; padding: 28px 32px;
    box-shadow: 0 2px 14px rgba(0,0,0,.12); border-radius: 6px;
}

/* Header */
.rpt-head { display: flex; align-items: center; gap: 18px; border-bottom: 3px solid #1a3a5c; padding-bottom: 16px; }
.rpt-logo {
    width: 66px; height: 66px; border-radius: 10px; background: #1a3a5c; color: #e8a020;
    display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 800; flex-shrink: 0;
    overflow: hidden;
}
.rpt-logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
.rpt-head .co-info { flex: 1; }
.rpt-head .co-name { font-size: 22px; font-weight: 800; color: #1a3a5c; letter-spacing: .02em; }
.rpt-head .rpt-title { font-size: 15px; font-weight: 700; color: #2563a8; margin-top: 3px; text-transform: uppercase; letter-spacing: .06em; }
.rpt-meta { text-align: right; font-size: 12px; color: #475569; line-height: 1.7; }
.rpt-meta b { color: #1e293b; }

/* Employee details */
.emp-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 18px;
    margin: 18px 0; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
}
.emp-grid .item { font-size: 12.5px; }
.emp-grid .item .lbl { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
.emp-grid .item .val { font-weight: 700; color: #1e293b; font-size: 13.5px; margin-top: 2px; }
.emp-grid .item .val.accent { color: #b45309; }

/* OT table */
table.ot { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
table.ot thead th {
    background: #1a3a5c; color: #fff; padding: 9px 8px; text-align: center; font-size: 11px;
    text-transform: uppercase; letter-spacing: .02em; border: 1px solid #16314e;
}
table.ot tbody td { padding: 7px 8px; text-align: center; border: 1px solid #e2e8f0; }
table.ot tbody tr:nth-child(even) { background: #f8fafc; }
table.ot tbody td.l { text-align: left; }
.day-weekend { color: #d97706; font-weight: 700; }
.day-holiday { color: #16a34a; font-weight: 700; }
table.ot tfoot td {
    padding: 10px 8px; font-weight: 800; background: #fef9c3; border: 1px solid #e2e8f0; text-align: center;
}
table.ot tfoot td.lbl { background: #1a3a5c; color: #fff; text-align: right; }

/* Footer / signatures */
.rpt-footer { display: flex; justify-content: space-between; gap: 40px; margin-top: 60px; }
.sign-box { flex: 1; text-align: center; }
.sign-line { border-top: 1.5px solid #1e293b; margin-top: 40px; padding-top: 6px; font-weight: 700; font-size: 12.5px; color: #475569; }

.empty-note { padding: 30px; text-align: center; color: #94a3b8; font-weight: 600; }

@media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; padding: 6mm 8mm; }
    @page { size: A4 landscape; margin: 8mm; }
}
</style>
</head>
<body>

<div class="toolbar">
    <a href="overtime_report.php?month=<?php echo htmlspecialchars(date('Y-m', strtotime($from_date))); ?>&user_no=<?php echo urlencode($user_no); ?>" class="tbtn">&#8592; Back to OT Report</a>
    <button onclick="window.print()" class="tbtn accent">&#128438; Print Report</button>
</div>

<div class="sheet">

<?php if (!$employee): ?>
    <div class="empty-note">No employee selected or found. Open this report from the Overtime Report page using an employee's <b>Print Report</b> button.</div>
<?php else: ?>

    <!-- ── Report Header ── -->
    <div class="rpt-head">
        <div class="rpt-logo">
            <img src="<?php echo htmlspecialchars(company_logo_url()); ?>" alt="<?php echo htmlspecialchars($COMPANY_NAME); ?>">
        </div>
        <div class="co-info">
            <div class="co-name"><?php echo htmlspecialchars($COMPANY_NAME); ?></div>
            <div class="rpt-title">Employee Overtime Report</div>
        </div>
        <div class="rpt-meta">
            <div><b>Period:</b> <?php echo htmlspecialchars(date('d-m-Y', strtotime($from_date))); ?> &ndash; <?php echo htmlspecialchars(date('d-m-Y', strtotime($to_date))); ?></div>
            <div><b>Generated:</b> <?php echo htmlspecialchars($generated_at); ?></div>
            <div><b>Printed By:</b> <?php echo htmlspecialchars($printed_by); ?></div>
        </div>
    </div>

    <!-- ── Employee Details ── -->
    <div class="emp-grid">
        <div class="item"><div class="lbl">Employee ID</div><div class="val"><?php echo htmlspecialchars($employee['employee_id'] ?? $employee['user_no']); ?></div></div>
        <div class="item"><div class="lbl">Employee Name</div><div class="val"><?php echo htmlspecialchars($employee['full_name'] ?? ''); ?></div></div>
        <div class="item"><div class="lbl">Department</div><div class="val"><?php echo htmlspecialchars($employee['department'] ?? '—'); ?></div></div>
        <div class="item"><div class="lbl">Designation</div><div class="val"><?php echo htmlspecialchars($employee['designation'] ?? '—'); ?></div></div>
        <div class="item"><div class="lbl">Shift Name</div><div class="val"><?php echo htmlspecialchars($shift_name); ?></div></div>
        <div class="item"><div class="lbl">Basic Salary</div><div class="val"><?php echo money2($basic_salary); ?> AED</div></div>
        <div class="item"><div class="lbl">OT Rate (Normal /hr)</div><div class="val accent"><?php echo money2($rate_regular); ?> AED</div></div>
        <div class="item"><div class="lbl">OT Rate (Weekend·Holiday /hr)</div><div class="val accent"><?php echo money2($rate_weekend); ?> AED</div></div>
    </div>

    <!-- ── Attendance / Overtime Details ── -->
    <table class="ot">
        <thead>
            <tr>
                <th>Date</th>
                <th>Day</th>
                <th>In Time</th>
                <th>Out Time</th>
                <th>Total Working Hrs</th>
                <th>Regular Hrs</th>
                <th>Overtime Hrs</th>
                <th>Holiday OT</th>
                <th>Weekend OT</th>
                <th>OT Amount (AED)</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="empty-note">No overtime records found for this period.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r):
                $date     = $r['attendance_date'];
                $day_name = date('l', strtotime($date));
                $is_sun   = ($day_name === 'Sunday');
                $is_hol   = isset($holiday_map[$date]);
                $ot_h     = (float)$r['ot_hours'];

                $ci_sec = t2s($r['check_in']  ?? '');
                $co_sec = t2s($r['check_out'] ?? '');
                $work_secs = ($ci_sec > 0 && $co_sec > $ci_sec) ? ($co_sec - $ci_sec) : 0;
                $work_h = $work_secs / 3600;

                if ($is_sun || $is_hol) {
                    // Whole day counts as OT (weekend/holiday)
                    $regular_h = 0;
                    $overtime_h = $ot_h;
                    $holiday_ot = $is_hol ? $ot_h : 0;
                    $weekend_ot = (!$is_hol && $is_sun) ? $ot_h : 0;
                    $rate = $rate_weekend;
                    $total_work_h = $work_h > 0 ? $work_h : $ot_h;
                } else {
                    $overtime_h = $ot_h;
                    $regular_h  = $work_h > 0 ? min($work_h, 8) : 8;
                    $holiday_ot = 0;
                    $weekend_ot = 0;
                    $rate = $rate_regular;
                    $total_work_h = $work_h > 0 ? $work_h : ($regular_h + $overtime_h);
                }
                $ot_amount = round($ot_h * $rate, 2);

                $tot_ot_hours += $ot_h; $tot_ot_amount += $ot_amount;
                $tot_reg += $regular_h; $tot_work += $total_work_h;
                $tot_holiday += $holiday_ot; $tot_weekend += $weekend_ot;

                $day_class = $is_hol ? 'day-holiday' : ($is_sun ? 'day-weekend' : '');
                $day_label = $day_name . ($is_hol ? ' (Holiday)' : '');
            ?>
            <tr>
                <td><?php echo date('d-m-Y', strtotime($date)); ?></td>
                <td class="<?php echo $day_class; ?>"><?php echo htmlspecialchars($day_label); ?></td>
                <td><?php echo hhmm($r['check_in']  ?? ''); ?></td>
                <td><?php echo hhmm($r['check_out'] ?? ''); ?></td>
                <td><?php echo hm($total_work_h * 3600); ?></td>
                <td><?php echo hm($regular_h * 3600); ?></td>
                <td><strong><?php echo number_format($overtime_h, 2); ?></strong></td>
                <td><?php echo $holiday_ot > 0 ? number_format($holiday_ot, 2) : '—'; ?></td>
                <td><?php echo $weekend_ot > 0 ? number_format($weekend_ot, 2) : '—'; ?></td>
                <td><?php echo money2($ot_amount); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="lbl" colspan="4">TOTAL</td>
                <td><?php echo hm($tot_work * 3600); ?></td>
                <td><?php echo hm($tot_reg * 3600); ?></td>
                <td><?php echo number_format($tot_ot_hours, 2); ?></td>
                <td><?php echo $tot_holiday > 0 ? number_format($tot_holiday, 2) : '—'; ?></td>
                <td><?php echo $tot_weekend > 0 ? number_format($tot_weekend, 2) : '—'; ?></td>
                <td><?php echo money2($tot_ot_amount); ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- ── Summary line ── -->
    <div style="margin-top:14px;display:flex;gap:30px;font-size:13px;">
        <div><b>Total OT Hours:</b> <span style="color:#b45309;font-weight:800;"><?php echo number_format($tot_ot_hours, 2); ?> hrs</span></div>
        <div><b>Total OT Amount:</b> <span style="color:#16a34a;font-weight:800;"><?php echo money2($tot_ot_amount); ?> AED</span></div>
    </div>

    <!-- ── Footer signatures ── -->
    <div class="rpt-footer">
        <div class="sign-box"><div class="sign-line">HR Signature</div></div>
        <div class="sign-box"><div class="sign-line">Manager Signature</div></div>
    </div>

<?php endif; ?>

</div><!-- /sheet -->

</body>
</html>
