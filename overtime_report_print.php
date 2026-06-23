<?php
/* ─────────────────────────────────────────────
   Employee Overtime Report (formal, printable)

   Two modes:
   - Single employee  : ?user_no=1023&month=2026-05  -> detailed date-wise sheet
   - All employees     : ?month=2026-05 (no user_no)   -> management report:
                         a SUMMARY table (per employee totals + grand total)
                         followed by a per-employee DETAILED BREAKUP.

   Reads from overtime_records + attendance + employees + holidays.
───────────────────────────────────────────── */
include 'auth.php';
requireAnyPermission(['reports_view','overtime_view']);

$COMPANY_NAME = COMPANY_NAME;

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
$is_all = ($user_no === '');

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

/* ── Holiday dates in range ── */
$holiday_map = [];
$hq = mysqli_query($conn, "SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN '$safe_from' AND '$safe_to'");
if ($hq) { while ($h = mysqli_fetch_assoc($hq)) { $holiday_map[$h['holiday_date']] = $h['holiday_name'] ?? 'Holiday'; } }

$has_timetable = ot_col_exists($conn, 'attendance', 'timetable');
$timetable_sel = $has_timetable ? "a.timetable" : "''";

/* ── Compute a single employee's OT rows + totals ── */
function ot_compute($conn, $user_no, $safe_from, $safe_to, $holiday_map, $timetable_sel, $rate_regular, $rate_weekend) {
    $u = mysqli_real_escape_string($conn, $user_no);
    $res = mysqli_query($conn, "
        SELECT o.attendance_date, o.ot_hours, COALESCE(o.note,'') AS note,
               a.check_in, a.check_out, $timetable_sel AS timetable
        FROM overtime_records o
        LEFT JOIN attendance a ON a.user_no = o.user_no AND a.attendance_date = o.attendance_date
        WHERE o.user_no='$u' AND o.attendance_date BETWEEN '$safe_from' AND '$safe_to' AND o.ot_hours > 0
        ORDER BY o.attendance_date ASC
    ");
    $rows = [];
    $t = ['hours'=>0,'amount'=>0,'reg'=>0,'work'=>0,'holiday'=>0,'weekend'=>0,'days'=>0,'timetable'=>''];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $date = $r['attendance_date'];
            $day  = date('l', strtotime($date));
            $isSun = ($day === 'Sunday');
            $isHol = isset($holiday_map[$date]);
            $oth   = (float)$r['ot_hours'];
            $ci = t2s($r['check_in'] ?? ''); $co = t2s($r['check_out'] ?? '');
            $work_h = ($ci > 0 && $co > $ci) ? (($co - $ci) / 3600) : 0;
            if ($isSun || $isHol) {
                $reg = 0; $ovt = $oth;
                $hol = $isHol ? $oth : 0; $wke = (!$isHol && $isSun) ? $oth : 0;
                $rate = $rate_weekend; $tw = $work_h > 0 ? $work_h : $oth;
            } else {
                $ovt = $oth; $reg = $work_h > 0 ? min($work_h, 8) : 8;
                $hol = 0; $wke = 0; $rate = $rate_regular; $tw = $work_h > 0 ? $work_h : ($reg + $ovt);
            }
            $amt = round($oth * $rate, 2);
            $rows[] = [
                'date'=>$date, 'day'=>$day, 'isSun'=>$isSun, 'isHol'=>$isHol,
                'in'=>$r['check_in'] ?? '', 'out'=>$r['check_out'] ?? '',
                'work'=>$tw, 'reg'=>$reg, 'ovt'=>$ovt, 'hol'=>$hol, 'wke'=>$wke, 'amt'=>$amt,
            ];
            $t['hours'] += $oth; $t['amount'] += $amt; $t['reg'] += $reg; $t['work'] += $tw;
            $t['holiday'] += $hol; $t['weekend'] += $wke; $t['days']++;
            if ($t['timetable'] === '' && !empty($r['timetable'])) $t['timetable'] = $r['timetable'];
        }
    }
    return [$rows, $t];
}

/* ── Gather employees for the report ── */
$employees = [];
if ($is_all) {
    $eq = mysqli_query($conn, "
        SELECT o.user_no,
               COALESCE(e.full_name,'') AS full_name,
               COALESCE(NULLIF(e.employee_id,''), o.user_no) AS employee_id,
               COALESCE(e.department,'') AS department,
               COALESCE(e.designation,'') AS designation,
               COALESCE(e.basic_salary,0) AS basic_salary,
               COALESCE(e.day_shift,'') AS day_shift
        FROM overtime_records o
        LEFT JOIN employees e ON e.user_no = o.user_no
        WHERE o.attendance_date BETWEEN '$safe_from' AND '$safe_to' AND o.ot_hours > 0
        GROUP BY o.user_no
        ORDER BY CAST(o.user_no AS UNSIGNED) ASC
    ");
    if ($eq) { while ($e = mysqli_fetch_assoc($eq)) { $employees[] = $e; } }
} else {
    $e = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT * FROM employees WHERE user_no='$safe_user' OR employee_id='$safe_user' LIMIT 1
    "));
    if ($e) $employees[] = $e;
}

/* ── Compute data for each employee ── */
$report = [];     // [ ['emp'=>..., 'rows'=>..., 'totals'=>..., 'rate_n'=>, 'rate_w'=>, 'shift'=>] ]
$grand = ['hours'=>0,'amount'=>0,'days'=>0,'holiday'=>0,'weekend'=>0];
foreach ($employees as $emp) {
    $basic  = (float)($emp['basic_salary'] ?? 0);
    $hourly = $basic > 0 ? ($basic / 30 / 8) : 0;
    $rate_n = round($hourly * 1.25, 2);
    $rate_w = round($hourly * 1.50, 2);
    [$rows, $t] = ot_compute($conn, $emp['user_no'], $safe_from, $safe_to, $holiday_map, $timetable_sel, $rate_n, $rate_w);
    $shift = trim($emp['day_shift'] ?? '');
    if ($shift === '' && $t['timetable'] !== '') $shift = $t['timetable'];
    if ($shift === '') $shift = 'General';
    $report[] = ['emp'=>$emp, 'rows'=>$rows, 't'=>$t, 'rate_n'=>$rate_n, 'rate_w'=>$rate_w, 'shift'=>$shift, 'basic'=>$basic];
    $grand['hours'] += $t['hours']; $grand['amount'] += $t['amount']; $grand['days'] += $t['days'];
    $grand['holiday'] += $t['holiday']; $grand['weekend'] += $t['weekend'];
}

$printed_by   = $_SESSION['username'] ?? ($_SESSION['full_name'] ?? 'Admin');
$generated_at = date('d-m-Y h:i A');
$report_title = $is_all ? 'Employee Overtime Report — Summary' : 'Employee Overtime Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($report_title); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #eef2f7; color: #1e293b; font-size: 13px; }
.toolbar { background: #1a3a5c; color: #fff; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.toolbar .tbtn { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.3); color: #fff; padding: 7px 15px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.toolbar .tbtn:hover { background: rgba(255,255,255,.25); }
.toolbar .tbtn.accent { background: #e8a020; color: #1a1a1a; border-color: #e8a020; }

.sheet { background: #fff; max-width: 1180px; margin: 20px auto; padding: 28px 32px; box-shadow: 0 2px 14px rgba(0,0,0,.12); border-radius: 6px; }

.rpt-head { display: flex; align-items: center; gap: 18px; border-bottom: 3px solid #1a3a5c; padding-bottom: 16px; }
.rpt-logo { width: 66px; height: 66px; border-radius: 10px; background: #1a3a5c; flex-shrink: 0; overflow: hidden; }
.rpt-logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
.rpt-head .co-info { flex: 1; }
.rpt-head .co-name { font-size: 22px; font-weight: 800; color: #1a3a5c; letter-spacing: .02em; }
.rpt-head .rpt-title { font-size: 15px; font-weight: 700; color: #2563a8; margin-top: 3px; text-transform: uppercase; letter-spacing: .06em; }
.rpt-meta { text-align: right; font-size: 12px; color: #475569; line-height: 1.7; }
.rpt-meta b { color: #1e293b; }

.sec-title { margin: 22px 0 8px; font-size: 14px; font-weight: 800; color: #1a3a5c; text-transform: uppercase; letter-spacing: .04em; border-left: 4px solid #e8a020; padding-left: 9px; }

.emp-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 18px; margin: 18px 0; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
.emp-grid .item { font-size: 12.5px; }
.emp-grid .item .lbl { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
.emp-grid .item .val { font-weight: 700; color: #1e293b; font-size: 13.5px; margin-top: 2px; }
.emp-grid .item .val.accent { color: #b45309; }

table.ot { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
table.ot thead th { background: #1a3a5c; color: #fff; padding: 9px 8px; text-align: center; font-size: 11px; text-transform: uppercase; letter-spacing: .02em; border: 1px solid #16314e; }
table.ot tbody td { padding: 7px 8px; text-align: center; border: 1px solid #e2e8f0; }
table.ot tbody tr:nth-child(even) { background: #f8fafc; }
table.ot tbody td.l { text-align: left; }
.day-weekend { color: #d97706; font-weight: 700; }
.day-holiday { color: #16a34a; font-weight: 700; }
table.ot tfoot td { padding: 10px 8px; font-weight: 800; background: #fef9c3; border: 1px solid #e2e8f0; text-align: center; }
table.ot tfoot td.lbl { background: #1a3a5c; color: #fff; text-align: right; }

/* Summary table */
table.sum { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 6px; }
table.sum thead th { background: #1a3a5c; color: #fff; padding: 9px 8px; border: 1px solid #16314e; font-size: 11px; text-transform: uppercase; }
table.sum tbody td { padding: 8px; border: 1px solid #e2e8f0; text-align: center; }
table.sum tbody td.l { text-align: left; }
table.sum tbody tr:nth-child(even) { background: #f8fafc; }
table.sum tfoot td { padding: 10px 8px; font-weight: 800; background: #e8a020; color: #1a1a1a; border: 1px solid #d18d10; text-align: center; }
table.sum tfoot td.l { text-align: right; }

/* Breakup employee header */
.brk-emp { margin-top: 22px; background: #2563a8; color: #fff; padding: 9px 14px; border-radius: 6px 6px 0 0; font-size: 13px; display: flex; flex-wrap: wrap; gap: 4px 18px; align-items: center; }
.brk-emp b { font-weight: 800; }
.brk-emp .sep { opacity: .5; }
.brk-rates { font-size: 11.5px; background: #eef3fb; padding: 6px 14px; border: 1px solid #dbe6f5; border-top: none; }

.rpt-footer { display: flex; justify-content: space-between; gap: 40px; margin-top: 60px; }
.sign-box { flex: 1; text-align: center; }
.sign-line { border-top: 1.5px solid #1e293b; margin-top: 40px; padding-top: 6px; font-weight: 700; font-size: 12.5px; color: #475569; }
.empty-note { padding: 30px; text-align: center; color: #94a3b8; font-weight: 600; }

@media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; padding: 6mm 8mm; }
    .brk-emp { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: A4 landscape; margin: 8mm; }
}
</style>
</head>
<body>

<div class="toolbar">
    <a href="overtime_report.php?month=<?php echo htmlspecialchars(date('Y-m', strtotime($from_date))); ?><?php echo $is_all ? '' : '&user_no=' . urlencode($user_no); ?>" class="tbtn">&#8592; Back to OT Report</a>
    <button onclick="window.print()" class="tbtn accent">&#128438; Print Report</button>
</div>

<div class="sheet">

    <!-- ── Report Header ── -->
    <div class="rpt-head">
        <div class="rpt-logo"><img src="<?php echo htmlspecialchars(company_logo_url()); ?>" alt="<?php echo htmlspecialchars($COMPANY_NAME); ?>"></div>
        <div class="co-info">
            <div class="co-name"><?php echo htmlspecialchars($COMPANY_NAME); ?></div>
            <div class="rpt-title"><?php echo htmlspecialchars($report_title); ?></div>
        </div>
        <div class="rpt-meta">
            <div><b>Period:</b> <?php echo htmlspecialchars(date('d-m-Y', strtotime($from_date))); ?> &ndash; <?php echo htmlspecialchars(date('d-m-Y', strtotime($to_date))); ?></div>
            <div><b>Generated:</b> <?php echo htmlspecialchars($generated_at); ?></div>
            <div><b>Printed By:</b> <?php echo htmlspecialchars($printed_by); ?></div>
        </div>
    </div>

<?php if (empty($report)): ?>
    <div class="empty-note">No overtime records found for this period.</div>
<?php elseif ($is_all): ?>

    <!-- ════════ SUMMARY ════════ -->
    <div class="sec-title">Summary &mdash; All Employees</div>
    <table class="sum">
        <thead>
            <tr>
                <th style="width:42px;">SL</th>
                <th>User No</th>
                <th>Employee Name</th>
                <th>Department</th>
                <th>Designation</th>
                <th>OT Days</th>
                <th>Total OT Hrs</th>
                <th>OT Amount (AED)</th>
            </tr>
        </thead>
        <tbody>
        <?php $sl = 1; foreach ($report as $row): $emp = $row['emp']; $t = $row['t']; ?>
            <tr>
                <td><?php echo $sl++; ?></td>
                <td><?php echo htmlspecialchars($emp['user_no']); ?></td>
                <td class="l"><?php echo htmlspecialchars($emp['full_name']); ?></td>
                <td class="l"><?php echo htmlspecialchars($emp['department']); ?></td>
                <td class="l"><?php echo htmlspecialchars($emp['designation']); ?></td>
                <td><?php echo (int)$t['days']; ?></td>
                <td><strong><?php echo number_format($t['hours'], 2); ?></strong></td>
                <td><?php echo money2($t['amount']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="l" colspan="5">GRAND TOTAL (<?php echo count($report); ?> employees)</td>
                <td><?php echo (int)$grand['days']; ?></td>
                <td><?php echo number_format($grand['hours'], 2); ?></td>
                <td><?php echo money2($grand['amount']); ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- ════════ DETAILED BREAKUP ════════ -->
    <div class="sec-title" style="margin-top:30px;">Detailed Breakup</div>
    <?php foreach ($report as $row): $emp = $row['emp']; $t = $row['t']; ?>
        <div class="brk-emp">
            <span><b>User No:</b> <?php echo htmlspecialchars($emp['user_no']); ?></span><span class="sep">|</span>
            <span><b><?php echo htmlspecialchars($emp['full_name']); ?></b></span><span class="sep">|</span>
            <span><?php echo htmlspecialchars($emp['department']); ?></span><span class="sep">|</span>
            <span><?php echo htmlspecialchars($emp['designation']); ?></span><span class="sep">|</span>
            <span><b>Basic:</b> <?php echo money2($row['basic']); ?> AED</span>
        </div>
        <div class="brk-rates">OT Rate / hr &mdash; Normal: <b><?php echo money2($row['rate_n']); ?></b> AED &nbsp;|&nbsp; Sunday/Holiday: <b><?php echo money2($row['rate_w']); ?></b> AED &nbsp;|&nbsp; Shift: <?php echo htmlspecialchars($row['shift']); ?></div>
        <table class="ot">
            <thead>
                <tr>
                    <th>Date</th><th>Day</th><th>In</th><th>Out</th><th>Working Hrs</th>
                    <th>Regular Hrs</th><th>OT Hrs</th><th>Holiday OT</th><th>Weekend OT</th><th>OT Amount (AED)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($row['rows'] as $d):
                $cls = $d['isHol'] ? 'day-holiday' : ($d['isSun'] ? 'day-weekend' : '');
                $lbl = $d['day'] . ($d['isHol'] ? ' (Holiday)' : '');
            ?>
                <tr>
                    <td><?php echo date('d-m-Y', strtotime($d['date'])); ?></td>
                    <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($lbl); ?></td>
                    <td><?php echo hhmm($d['in']); ?></td>
                    <td><?php echo hhmm($d['out']); ?></td>
                    <td><?php echo hm($d['work'] * 3600); ?></td>
                    <td><?php echo hm($d['reg'] * 3600); ?></td>
                    <td><strong><?php echo number_format($d['ovt'], 2); ?></strong></td>
                    <td><?php echo $d['hol'] > 0 ? number_format($d['hol'], 2) : '—'; ?></td>
                    <td><?php echo $d['wke'] > 0 ? number_format($d['wke'], 2) : '—'; ?></td>
                    <td><?php echo money2($d['amt']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="lbl" colspan="4">TOTAL &mdash; <?php echo (int)$t['days']; ?> OT day(s)</td>
                    <td><?php echo hm($t['work'] * 3600); ?></td>
                    <td><?php echo hm($t['reg'] * 3600); ?></td>
                    <td><?php echo number_format($t['hours'], 2); ?></td>
                    <td><?php echo $t['holiday'] > 0 ? number_format($t['holiday'], 2) : '—'; ?></td>
                    <td><?php echo $t['weekend'] > 0 ? number_format($t['weekend'], 2) : '—'; ?></td>
                    <td><?php echo money2($t['amount']); ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endforeach; ?>

<?php else: $row = $report[0]; $emp = $row['emp']; $t = $row['t']; ?>

    <!-- ════════ SINGLE EMPLOYEE ════════ -->
    <div class="emp-grid">
        <div class="item"><div class="lbl">Employee ID</div><div class="val"><?php echo htmlspecialchars($emp['employee_id'] ?? $emp['user_no']); ?></div></div>
        <div class="item"><div class="lbl">Employee Name</div><div class="val"><?php echo htmlspecialchars($emp['full_name'] ?? ''); ?></div></div>
        <div class="item"><div class="lbl">Department</div><div class="val"><?php echo htmlspecialchars($emp['department'] ?? '—'); ?></div></div>
        <div class="item"><div class="lbl">Designation</div><div class="val"><?php echo htmlspecialchars($emp['designation'] ?? '—'); ?></div></div>
        <div class="item"><div class="lbl">Shift Name</div><div class="val"><?php echo htmlspecialchars($row['shift']); ?></div></div>
        <div class="item"><div class="lbl">Basic Salary</div><div class="val"><?php echo money2($row['basic']); ?> AED</div></div>
        <div class="item"><div class="lbl">OT Rate (Normal /hr)</div><div class="val accent"><?php echo money2($row['rate_n']); ?> AED</div></div>
        <div class="item"><div class="lbl">OT Rate (Weekend·Holiday /hr)</div><div class="val accent"><?php echo money2($row['rate_w']); ?> AED</div></div>
    </div>

    <table class="ot">
        <thead>
            <tr>
                <th>Date</th><th>Day</th><th>In Time</th><th>Out Time</th><th>Total Working Hrs</th>
                <th>Regular Hrs</th><th>Overtime Hrs</th><th>Holiday OT</th><th>Weekend OT</th><th>OT Amount (AED)</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($row['rows'])): ?>
            <tr><td colspan="10" class="empty-note">No overtime records found for this period.</td></tr>
        <?php else: foreach ($row['rows'] as $d):
            $cls = $d['isHol'] ? 'day-holiday' : ($d['isSun'] ? 'day-weekend' : '');
            $lbl = $d['day'] . ($d['isHol'] ? ' (Holiday)' : '');
        ?>
            <tr>
                <td><?php echo date('d-m-Y', strtotime($d['date'])); ?></td>
                <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($lbl); ?></td>
                <td><?php echo hhmm($d['in']); ?></td>
                <td><?php echo hhmm($d['out']); ?></td>
                <td><?php echo hm($d['work'] * 3600); ?></td>
                <td><?php echo hm($d['reg'] * 3600); ?></td>
                <td><strong><?php echo number_format($d['ovt'], 2); ?></strong></td>
                <td><?php echo $d['hol'] > 0 ? number_format($d['hol'], 2) : '—'; ?></td>
                <td><?php echo $d['wke'] > 0 ? number_format($d['wke'], 2) : '—'; ?></td>
                <td><?php echo money2($d['amt']); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="lbl" colspan="4">TOTAL</td>
                <td><?php echo hm($t['work'] * 3600); ?></td>
                <td><?php echo hm($t['reg'] * 3600); ?></td>
                <td><?php echo number_format($t['hours'], 2); ?></td>
                <td><?php echo $t['holiday'] > 0 ? number_format($t['holiday'], 2) : '—'; ?></td>
                <td><?php echo $t['weekend'] > 0 ? number_format($t['weekend'], 2) : '—'; ?></td>
                <td><?php echo money2($t['amount']); ?></td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top:14px;display:flex;gap:30px;font-size:13px;">
        <div><b>Total OT Hours:</b> <span style="color:#b45309;font-weight:800;"><?php echo number_format($t['hours'], 2); ?> hrs</span></div>
        <div><b>Total OT Amount:</b> <span style="color:#16a34a;font-weight:800;"><?php echo money2($t['amount']); ?> AED</span></div>
    </div>

<?php endif; ?>

<?php if (!empty($report)): ?>
    <!-- ── Footer signatures ── -->
    <div class="rpt-footer">
        <div class="sign-box"><div class="sign-line">HR Signature</div></div>
        <div class="sign-box"><div class="sign-line">Manager Signature</div></div>
    </div>
<?php endif; ?>

</div><!-- /sheet -->

</body>
</html>
