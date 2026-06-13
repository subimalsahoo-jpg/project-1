<?php
include 'auth.php';
requirePermission('attendance_report');

$from_date_raw = trim($_GET['from_date'] ?? '');
$to_date_raw   = trim($_GET['to_date']   ?? '');
$date_order    = detect_input_date_order([$from_date_raw, $to_date_raw]);
$from_date     = normalize_input_date($from_date_raw, $date_order);
$to_date       = normalize_input_date($to_date_raw, $date_order);

/* ─────────────────────────────────────────────
   Detect which status column exists
───────────────────────────────────────────── */
$emp_col_q = mysqli_query($conn, "SHOW COLUMNS FROM employees");
$emp_cols  = [];
if ($emp_col_q) {
    while ($c = mysqli_fetch_assoc($emp_col_q)) $emp_cols[$c['Field']] = true;
}
$status_col = isset($emp_cols['employee_status']) ? 'employee_status'
            : (isset($emp_cols['status'])         ? 'status' : null);
$employee_join = "LEFT JOIN employees ON TRIM(employees.user_no) = TRIM(attendance.user_no)";
$active_cond = "employees.user_no IS NOT NULL";
if ($status_col) {
    $active_cond .= " AND (
        employees.`$status_col` IS NULL
        OR TRIM(employees.`$status_col`) = ''
        OR LOWER(TRIM(employees.`$status_col`)) NOT IN ('inactive', 'resign', 'resigned')
    )";
}
if (isset($emp_cols['resign_date'])) {
    $active_cond .= " AND (
        employees.resign_date IS NULL
        OR employees.resign_date = ''
        OR employees.resign_date >= attendance.attendance_date
    )";
}

$manual_reason_col_q = mysqli_query($conn, "SHOW COLUMNS FROM attendance LIKE 'manual_entry_reason'");
if ($manual_reason_col_q && mysqli_num_rows($manual_reason_col_q) == 0) {
    mysqli_query($conn, "ALTER TABLE attendance ADD COLUMN manual_entry_reason VARCHAR(255) NULL DEFAULT '' AFTER overtime");
}

/* ─────────────────────────────────────────────
   Filters & WHERE
───────────────────────────────────────────── */
$where      = "WHERE 1=1 AND $active_cond";
$has_filter = false;

// FIX: escape date inputs before using in SQL
$safe_from = mysqli_real_escape_string($conn, $from_date);
$safe_to   = mysqli_real_escape_string($conn, $to_date);

if ($from_date !== '' && $to_date !== '') {
    $where     .= " AND attendance.attendance_date BETWEEN '$safe_from' AND '$safe_to'";
    $has_filter = true;
}

$employee_name_raw = trim($_GET['employee_name'] ?? '');
$user_no_raw       = trim($_GET['user_no']       ?? '');

if ($employee_name_raw !== '') {
    $safe_name = mysqli_real_escape_string($conn, $employee_name_raw);
    $where    .= " AND (attendance.employee_name LIKE '%$safe_name%' OR employees.full_name LIKE '%$safe_name%')";
    $has_filter = true;
}

if ($user_no_raw !== '') {
    $safe_uno  = mysqli_real_escape_string($conn, $user_no_raw);
    $where    .= " AND attendance.user_no='$safe_uno'";
    $has_filter = true;
}

/* ─────────────────────────────────────────────
   Sort
───────────────────────────────────────────── */
$sort  = $_GET['sort']  ?? '';
$order = strtoupper($_GET['order'] ?? 'ASC');
if (!in_array($order, ['ASC', 'DESC'])) $order = 'ASC';

// FIX: default sort is user_no (numeric) then date — was date DESC before
$order_by = "CAST(attendance.user_no AS UNSIGNED) ASC, attendance.attendance_date ASC";

if ($sort === 'in')   $order_by = "attendance.check_in $order, CAST(attendance.user_no AS UNSIGNED) ASC";
if ($sort === 'out')  $order_by = "attendance.check_out $order, CAST(attendance.user_no AS UNSIGNED) ASC";
if ($sort === 'date') $order_by = "attendance.attendance_date $order, CAST(attendance.user_no AS UNSIGNED) ASC";

/* ─────────────────────────────────────────────
   Time helpers
───────────────────────────────────────────── */
function time_to_seconds($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '00:00:00' || $value === '00:00') return 0;
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) return 0;
    return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
}

function seconds_to_hm($seconds) {
    $seconds = max(0, (int)round($seconds));
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

function shortage_seconds_to_round_seconds($seconds) {
    $seconds = max(0, (int)round($seconds));
    $hours = intdiv($seconds, 3600);
    $remaining = $seconds % 3600;
    if ($remaining > 1800) {
        $hours++;
    }
    return $hours * 3600;
}

function seconds_to_time($seconds) {
    $seconds = max(0, (int)round($seconds));
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function display_date_dmy($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return '';
    $time = strtotime($value);
    return $time ? date('d-m-Y', $time) : $value;
}

function calculated_late_seconds($check_in, $late_time = '') {
    $saved_late = time_to_seconds($late_time);
    if ($saved_late > 0) {
        return $saved_late;
    }

    $check_in_seconds = time_to_seconds($check_in);
    $office_start_seconds = time_to_seconds('07:00:00');
    $late_after_seconds = time_to_seconds('07:06:00');

    if ($check_in_seconds > $late_after_seconds) {
        return max(0, $check_in_seconds - $office_start_seconds);
    }

    return 0;
}

$daily_duty_seconds      = 8 * 3600;
$normal_duty_end_seconds = time_to_seconds('15:50:00');
$normal_regular_ot_base_seconds = time_to_seconds('16:05:00');
$friday_regular_ot_base_seconds = time_to_seconds('16:45:00');
$regular_ot_end_seconds  = time_to_seconds('18:05:00');
$friday_duty_end_seconds = time_to_seconds('16:30:00');
$friday_ot_end_seconds   = time_to_seconds('18:45:00');
$normal_extra_ot_base_seconds = time_to_seconds('18:20:00');
$friday_extra_ot_base_seconds = time_to_seconds('18:45:00');
$time_grace_seconds      = 120;

/* ─────────────────────────────────────────────
   Cache holiday dates (avoid repeated subquery)
───────────────────────────────────────────── */
$holiday_dates_arr = [];
$hq = mysqli_query($conn, "SELECT holiday_date FROM holidays");
if ($hq) while ($hr = mysqli_fetch_assoc($hq)) $holiday_dates_arr[] = $hr['holiday_date'];
$holiday_in_sql = !empty($holiday_dates_arr)
    ? implode(',', array_map(fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'", $holiday_dates_arr))
    : "'0000-00-00'";

/* ─────────────────────────────────────────────
   Summary counts
───────────────────────────────────────────── */
$count_query = "
SELECT
    COUNT(*) AS total_employee,
    SUM(CASE
        WHEN attendance.check_in IS NOT NULL AND TRIM(attendance.check_in) != '' THEN 1
        ELSE 0
    END) AS total_in,
    SUM(CASE
        WHEN EXISTS (
            SELECT 1 FROM vacations l
            WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
            AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
        ) THEN 0
        WHEN (attendance.check_in IS NULL OR TRIM(attendance.check_in) = '')
            AND DAYNAME(attendance.attendance_date) != 'Sunday'
            AND attendance.attendance_date NOT IN ($holiday_in_sql) THEN 1
        ELSE 0
    END) AS total_not_in,
    COUNT(DISTINCT CASE
        WHEN attendance.attendance_date IN ($holiday_in_sql) THEN attendance.attendance_date
        ELSE NULL
    END) AS total_holidays,
    COUNT(DISTINCT CASE
        WHEN DAYNAME(attendance.attendance_date) = 'Sunday' THEN attendance.attendance_date
        ELSE NULL
    END) AS total_sundays
FROM attendance
$employee_join
$where
";
$count_result = mysqli_query($conn, $count_query);
$count_data   = $count_result ? mysqli_fetch_assoc($count_result) : [];

/* ─────────────────────────────────────────────
   Per-employee total working days (when filtered)
───────────────────────────────────────────── */
$employee_total_work_days = null;

if ($employee_name_raw !== '' || $user_no_raw !== '') {
    $work_from = $from_date !== '' ? $from_date : date('Y-m-01');
    $work_to   = $to_date   !== '' ? $to_date   : date('Y-m-t', strtotime($work_from));
    $safe_wf   = mysqli_real_escape_string($conn, $work_from);
    $safe_wt   = mysqli_real_escape_string($conn, $work_to);

    $emp_filter = "WHERE $active_cond";
    if ($employee_name_raw !== '') {
        $sn = mysqli_real_escape_string($conn, $employee_name_raw);
        $emp_filter .= " AND (attendance.employee_name LIKE '%$sn%' OR employees.full_name LIKE '%$sn%')";
    }
    if ($user_no_raw !== '') {
        $su = mysqli_real_escape_string($conn, $user_no_raw);
        $emp_filter .= " AND attendance.user_no='$su'";
    }

    $emp_users_q = mysqli_query($conn, "
        SELECT DISTINCT attendance.user_no
        FROM attendance
        $employee_join
        $emp_filter
        AND attendance.attendance_date BETWEEN '$safe_wf' AND '$safe_wt'
    ");

    $employee_total_work_days = 0;
    if ($emp_users_q) {
        while ($eu = mysqli_fetch_assoc($emp_users_q)) {
            $wu = $eu['user_no'] ?? '';
            if ($wu === '') continue;
            $swu    = mysqli_real_escape_string($conn, $wu);
            $cutoff = $work_to;

            // Vacation cutoff
            $vq = mysqli_query($conn, "
                SELECT MIN(from_date) AS first_vacation_date FROM vacations
                WHERE user_no='$swu' AND from_date <= '$safe_wt' AND to_date >= '$safe_wf'
            ");
            if ($vq && ($vr = mysqli_fetch_assoc($vq)) && !empty($vr['first_vacation_date'])) {
                $vc = date('Y-m-d', strtotime($vr['first_vacation_date'] . ' -1 day'));
                if ($vc < $cutoff) $cutoff = $vc;
            }

            // Resign cutoff
            $rq = mysqli_query($conn, "
                SELECT resign_date FROM employees
                WHERE user_no='$swu' AND resign_date IS NOT NULL AND resign_date != '' LIMIT 1
            ");
            if ($rq && ($rr = mysqli_fetch_assoc($rq)) && !empty($rr['resign_date'])
                && $rr['resign_date'] >= $work_from && $rr['resign_date'] < $cutoff) {
                $cutoff = $rr['resign_date'];
            }

            if ($cutoff < $work_from) continue;
            $sc = mysqli_real_escape_string($conn, $cutoff);

            $present_dates = [];
            $ciq = mysqli_query($conn, "
                SELECT DISTINCT attendance_date FROM attendance
                WHERE user_no='$swu'
                AND attendance_date BETWEEN '$safe_wf' AND '$sc'
                AND check_in IS NOT NULL AND TRIM(check_in) != ''
            ");
            if ($ciq) while ($cr = mysqli_fetch_assoc($ciq)) $present_dates[$cr['attendance_date']] = true;

            for ($day = strtotime($work_from); $day <= strtotime($cutoff); $day = strtotime('+1 day', $day)) {
                if (date('l', $day) === 'Sunday') $present_dates[date('Y-m-d', $day)] = true;
            }

            $hqq = mysqli_query($conn, "SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN '$safe_wf' AND '$sc'");
            if ($hqq) while ($hqr = mysqli_fetch_assoc($hqq)) $present_dates[$hqr['holiday_date']] = true;

            $employee_total_work_days += count($present_dates);
        }
    }
}

/* ─────────────────────────────────────────────
   Build sort URL helper
───────────────────────────────────────────── */
function sort_url($col, $current_sort, $current_order, $params) {
    $new_order = ($current_sort === $col && $current_order === 'ASC') ? 'DESC' : 'ASC';
    $arrow     = ($current_sort === $col) ? ($current_order === 'ASC' ? ' ▲' : ' ▼') : ' ⇅';
    $qs = http_build_query(array_merge($params, ['sort' => $col, 'order' => $new_order]));
    return ['url' => '?' . $qs, 'arrow' => $arrow];
}

$base_params = [
    'from_date'     => $from_date,
    'to_date'       => $to_date,
    'employee_name' => $employee_name_raw,
    'user_no'       => $user_no_raw,
];

$sl_date = sort_url('date', $sort, $order, $base_params);
$sl_in   = sort_url('in',   $sort, $order, $base_params);
$sl_out  = sort_url('out',  $sort, $order, $base_params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Report</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #eef2f7;
    color: #1a2533;
    font-size: 13px;
}

/* ── Header ── */
.header {
    background: #1e293b;
    color: #fff;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.header h2 { font-size: 18px; font-weight: 700; }

.btn {
    display: inline-block;
    background: #2563eb;
    color: #fff;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: background .2s;
}
.btn:hover { background: #1d4ed8; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.btn-reset { background: #64748b; }
.btn-reset:hover { background: #475569; }

/* ── Filter ── */
.filter-box {
    background: #fff;
    margin: 16px auto;
    width: 97%;
    padding: 14px 18px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e2e8f0;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-box label {
    font-weight: 600;
    font-size: 12px;
    color: #374151;
    display: block;
    margin-bottom: 4px;
}
.filter-box input {
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #f8fafc;
}
.filter-box input:focus { border-color: #2563eb; outline: none; background: #fff; }

/* ── Summary cards ── */
.summary {
    width: 97%;
    margin: 0 auto 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
.card {
    background: #fff;
    padding: 12px 18px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e2e8f0;
    font-size: 14px;
    font-weight: 600;
    flex: 1;
    min-width: 130px;
}
.card b { display: block; font-size: 11px; color: #64748b; margin-bottom: 4px; font-weight: 600; }
.card .val { font-size: 22px; font-weight: 800; color: #1e293b; }
.green  { border-top: 3px solid #22c55e; }
.green  .val { color: #16a34a; }
.red    { border-top: 3px solid #ef4444; }
.red    .val { color: #dc2626; }
.yellow { border-top: 3px solid #f59e0b; }
.yellow .val { color: #d97706; }
.blue-card  { border-top: 3px solid #3b82f6; }
.blue-card  .val { color: #2563eb; }
.orange-card { border-top: 4px solid #f97316; }
.orange-card .val { color: #ea580c; }

/* ── Table wrapper ── */
.table-wrap {
    width: 97%;
    margin: 0 auto 20px;
    overflow-x: auto;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,.07);
}

table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    min-width: 1480px;
}

thead th {
    background: #1e293b;
    color: #fff;
    padding: 10px 8px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 2;
}
thead th a {
    color: #93c5fd;
    text-decoration: none;
    font-size: 12px;
}
thead th a:hover { color: #fff; }

tbody tr:nth-child(even) { background: #f8fafc; }
tbody tr:hover { background: #e0f2fe; }

tbody td {
    padding: 8px 8px;
    border-bottom: 1px solid #e9ecef;
    text-align: center;
    font-size: 12.5px;
    white-space: nowrap;
}

/* Serial no column */
td.sl { font-weight: 700; color: #64748b; width: 40px; }

/* Highlight columns */
td.check-in  { color: #16a34a; font-weight: 600; }
td.check-out { color: #2563eb; font-weight: 600; }
td.late-col  { color: #dc2626; font-weight: 600; }
td.ot-col    { color: #9333ea; font-weight: 600; }
td.sunday-row { background: #fef9c3 !important; }
td.holiday-row { background: #dcfce7 !important; }
td.absent-row  { background: #fee2e2 !important; }
.action-cell { min-width: 112px; }
.manual-reason { max-width: 180px; white-space: normal; line-height: 1.35; color: #92400e; font-weight: 700; font-size: 12px; }
.action-btn {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 5px;
    color: #fff;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
    margin: 0 2px;
}
.action-edit { background: #2563eb; }
.action-delete { background: #dc2626; }
.action-edit:hover { background: #1d4ed8; }
.action-delete:hover { background: #b91c1c; }

/* ── Bottom summary ── */
.bottom-summary {
    width: 97%;
    margin: 0 auto 30px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.bottom-summary h3 {
    margin-bottom: 14px;
    color: #1e293b;
    font-size: 15px;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
}
.summary-box {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px;
    background: #f8fafc;
}
.summary-box b { display: block; margin-bottom: 6px; color: #374151; font-size: 12px; }
.summary-box .sv { color: #f97316; font-weight: 800; font-size: 16px; }
.summary-note {
    margin-top: 14px;
    color: #64748b;
    font-size: 12px;
    line-height: 1.6;
    border-top: 1px solid #e9ecef;
    padding-top: 10px;
}

/* ── Empty state ── */
.empty-msg {
    text-align: center;
    padding: 40px;
    color: #94a3b8;
    font-size: 14px;
    font-weight: 600;
}

/* ── Responsive ── */
@media (max-width: 700px) {
    .summary-grid { grid-template-columns: 1fr 1fr; }
    .summary { flex-direction: column; }
}
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <h2>&#128337; Attendance Report</h2>
    <a href="dashboard.php" class="btn">&#8592; Dashboard</a>
</div>

<!-- Filter -->
<div class="filter-box">
    <form method="GET" style="display:contents;">
        <div>
            <label>From Date</label>
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
        </div>
        <div>
            <label>To Date</label>
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
        </div>
        <div>
            <label>Employee Name</label>
            <input type="text" name="employee_name" placeholder="Search name..."
                   value="<?php echo htmlspecialchars($employee_name_raw); ?>">
        </div>
        <div>
            <label>User No.</label>
            <input type="text" name="user_no" placeholder="User No."
                   value="<?php echo htmlspecialchars($user_no_raw); ?>">
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <button type="submit" class="btn">&#128269; Filter</button>
            <a href="attendance_report.php" class="btn btn-reset">&#10005; Reset</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="summary">
    <div class="card">
        <b>Total Records</b>
        <div class="val"><?php echo number_format($count_data['total_employee'] ?? 0); ?></div>
    </div>
    <div class="card green">
        <b>Present</b>
        <div class="val"><?php echo number_format($count_data['total_in'] ?? 0); ?></div>
    </div>
    <div class="card red">
        <b>Absent</b>
        <div class="val"><?php echo number_format($count_data['total_not_in'] ?? 0); ?></div>
    </div>
    <div class="card yellow">
        <b>Holidays</b>
        <div class="val"><?php echo number_format($count_data['total_holidays'] ?? 0); ?></div>
    </div>
    <div class="card blue-card">
        <b>Sundays</b>
        <div class="val"><?php echo number_format($count_data['total_sundays'] ?? 0); ?></div>
    </div>
    <?php if ($employee_total_work_days !== null): ?>
    <div class="card orange-card">
        <b>Employee Total Working Days</b>
        <div class="val"><?php echo $employee_total_work_days; ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Attendance Table -->
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>SL</th>
    <th>User No.</th>
    <th>User ID</th>
    <th>Name</th>
    <th>Department</th>
    <th>
        <a href="<?php echo $sl_date['url']; ?>">Date<?php echo $sl_date['arrow']; ?></a>
    </th>
    <th>Timetable</th>
    <th>On Duty</th>
    <th>Off Duty</th>
    <th>Schedule</th>
    <th>
        <a href="<?php echo $sl_in['url']; ?>">Check In<?php echo $sl_in['arrow']; ?></a>
    </th>
    <th>
        <a href="<?php echo $sl_out['url']; ?>">Check Out<?php echo $sl_out['arrow']; ?></a>
    </th>
    <th>Late</th>
    <th>Early</th>
    <th>Overtime</th>
    <th>Manual Entry<br>(Reason)</th>
    <th>Action</th>
</tr>
</thead>
<tbody>

<?php if ($has_filter):

$query = "
SELECT
    attendance.*,
    employees.department   AS emp_department,
    employees.full_name    AS emp_full_name,
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM vacations l
            WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
              AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
        ) THEN 1
        ELSE 0
    END AS is_vacation
FROM attendance
$employee_join
$where
ORDER BY $order_by
";
$result = mysqli_query($conn, $query);

$attendance_rows          = [];
$bottom_present_count     = 0;
$bottom_regular_ot_count  = 0;
$bottom_extra_ot_count    = 0;
$bottom_sunday_ot_count   = 0;
$bottom_duty_seconds      = 0;
$bottom_regular_ot_seconds = 0;
$bottom_regular_ot_deduct_seconds = 0;
$bottom_extra_ot_seconds  = 0;
$bottom_sunday_ot_seconds = 0;
$bottom_late_seconds      = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $attendance_rows[] = $row;

        $ci_sec        = time_to_seconds($row['check_in']  ?? '');
        $co_sec        = time_to_seconds($row['check_out'] ?? '');
        $has_ci        = trim((string)($row['check_in'] ?? '')) !== '';
        $att_date      = $row['attendance_date'] ?? '';
        $day_name      = $att_date !== '' ? date('l', strtotime($att_date)) : '';
        $is_sunday     = $day_name === 'Sunday';
        $is_friday     = $day_name === 'Friday';
        $valid_duty    = $has_ci && (!$is_friday || $co_sec >= $friday_duty_end_seconds);

        if ($valid_duty) {
            $bottom_present_count++;
            $bottom_duty_seconds += $daily_duty_seconds;
        }

        if ($ci_sec > 0 && $co_sec > 0) {
            if ($is_sunday) {
                $sot = max(0, $co_sec - $ci_sec);
                if ($sot > 0) { $bottom_sunday_ot_count++; $bottom_sunday_ot_seconds += $sot; }
            } elseif ($is_friday) {
                if ($co_sec < ($friday_duty_end_seconds - $time_grace_seconds)) {
                    $short_seconds = max(0, ($friday_duty_end_seconds - $co_sec) - $time_grace_seconds);
                    $bottom_regular_ot_deduct_seconds += shortage_seconds_to_round_seconds($short_seconds);
                }
                $regular_hours = floor((($co_sec + $time_grace_seconds) - $friday_regular_ot_base_seconds) / 3600);
                $regular_hours = max(0, min(2, $regular_hours));
                if ($regular_hours > 0) {
                    $bottom_regular_ot_count++;
                    $bottom_regular_ot_seconds += $regular_hours * 3600;
                }
                if ($co_sec >= (time_to_seconds('19:45:00') - $time_grace_seconds)) {
                    $extra_hours = floor((($co_sec + $time_grace_seconds) - $friday_extra_ot_base_seconds) / 3600);
                    if ($extra_hours > 0) {
                        $bottom_extra_ot_count++;
                        $bottom_extra_ot_seconds += $extra_hours * 3600;
                    }
                }
            } elseif (!$is_friday) {
                if ($co_sec < ($normal_duty_end_seconds - $time_grace_seconds)) {
                    $short_seconds = max(0, ($normal_duty_end_seconds - $co_sec) - $time_grace_seconds);
                    $bottom_regular_ot_deduct_seconds += shortage_seconds_to_round_seconds($short_seconds);
                }
                $regular_hours = floor((($co_sec + $time_grace_seconds) - $normal_regular_ot_base_seconds) / 3600);
                $regular_hours = max(0, min(2, $regular_hours));
                if ($regular_hours > 0) {
                    $bottom_regular_ot_count++;
                    $bottom_regular_ot_seconds += $regular_hours * 3600;
                }
                if ($co_sec >= (time_to_seconds('19:20:00') - $time_grace_seconds)) {
                    $extra_hours = floor((($co_sec + $time_grace_seconds) - $normal_extra_ot_base_seconds) / 3600);
                    if ($extra_hours > 0) {
                        $bottom_extra_ot_count++;
                        $bottom_extra_ot_seconds += $extra_hours * 3600;
                    }
                }
            }
        }

        $bottom_late_seconds += calculated_late_seconds($row['check_in'] ?? '', $row['late_time'] ?? '');
    }
}

$bottom_regular_ot_seconds = max(0, $bottom_regular_ot_seconds - $bottom_regular_ot_deduct_seconds);

if (empty($attendance_rows)):
?>
    <tr><td colspan="17" class="empty-msg">No records found for the selected filters.</td></tr>
<?php else:
    $sl = 1;
    $current_report_url = basename($_SERVER['PHP_SELF']) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    foreach ($attendance_rows as $row):
        $att_date  = $row['attendance_date'] ?? '';
        $day_name  = $att_date !== '' ? date('l', strtotime($att_date)) : '';
        $is_sunday  = $day_name === 'Sunday';
        $is_holiday = in_array($att_date, $holiday_dates_arr);
        $is_vacation = (int)($row['is_vacation'] ?? 0) === 1;
        $has_ci     = trim((string)($row['check_in'] ?? '')) !== '';
        $is_absent  = !$has_ci && !$is_sunday && !$is_holiday && !$is_vacation;
        $late_seconds = calculated_late_seconds($row['check_in'] ?? '', $row['late_time'] ?? '');
        $late_display = $late_seconds > 0 ? seconds_to_time($late_seconds) : '';
        $can_edit_attendance = hasPermission('attendance_upload');

        // Row background class (applied per-cell via a data approach)
        $row_class = $is_sunday ? 'sunday-row' : ($is_holiday ? 'holiday-row' : ($is_absent ? 'absent-row' : ''));
?>
    <tr>
        <td class="sl <?php echo $row_class; ?>"><?php echo $sl++; ?></td>
        <td class="<?php echo $row_class; ?>" style="font-weight:700;"><?php echo htmlspecialchars($row['user_no'] ?? ''); ?></td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['employee_id'] ?? ''); ?></td>
        <td class="<?php echo $row_class; ?>" style="text-align:left;font-weight:600;">
            <?php echo htmlspecialchars($row['employee_name'] ?: ($row['emp_full_name'] ?? 'Unknown')); ?>
        </td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['emp_department'] ?? ($row['department'] ?? '')); ?></td>
        <td class="<?php echo $row_class; ?>">
            <?php echo htmlspecialchars(display_date_dmy($att_date)); ?>
            <?php if ($is_sunday):  ?> <span style="color:#d97706;font-size:11px;">(Sun)</span><?php endif; ?>
            <?php if ($is_holiday): ?> <span style="color:#16a34a;font-size:11px;">(Holiday)</span><?php endif; ?>
        </td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['timetable']     ?? ''); ?></td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['on_duty']       ?? ''); ?></td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['off_duty']      ?? ''); ?></td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['schedule_time'] ?? ''); ?></td>
        <td class="check-in <?php echo $row_class; ?>"><?php echo htmlspecialchars($row['check_in']  ?? ''); ?></td>
        <td class="check-out <?php echo $row_class; ?>"><?php echo htmlspecialchars($row['check_out'] ?? ''); ?></td>
        <td class="late-col <?php echo $row_class; ?>"><?php echo htmlspecialchars($late_display); ?></td>
        <td class="<?php echo $row_class; ?>"><?php echo htmlspecialchars($row['early_time']    ?? ''); ?></td>
        <td class="ot-col <?php echo $row_class; ?>"><?php echo htmlspecialchars($row['overtime']    ?? ''); ?></td>
        <td class="manual-reason <?php echo $row_class; ?>"><?php echo htmlspecialchars($row['manual_entry_reason'] ?? ''); ?></td>
        <td class="action-cell <?php echo $row_class; ?>">
            <?php if ($can_edit_attendance): ?>
                <a class="action-btn action-edit" href="edit_attendance.php?id=<?php echo (int)($row['id'] ?? 0); ?>&return=<?php echo urlencode($current_report_url); ?>">Edit</a>
                <a class="action-btn action-delete" href="delete_attendance.php?id=<?php echo (int)($row['id'] ?? 0); ?>" onclick="return confirm('Delete this attendance record?');">Delete</a>
            <?php endif; ?>
        </td>
    </tr>
<?php
    endforeach;
endif;

else: ?>
    <tr>
        <td colspan="17" class="empty-msg">
            Please select a date range or search by employee name / user no to view the attendance report.
        </td>
    </tr>
<?php endif; ?>

</tbody>
</table>
</div>

<!-- Bottom summary -->
<?php if ($has_filter && !empty($attendance_rows)): ?>
<div class="bottom-summary">
    <h3>&#128202; Attendance Hours Summary</h3>
    <div class="summary-grid">
        <div class="summary-box">
            <b>Duty Hours</b>
            Present <strong><?php echo $bottom_present_count; ?></strong> days &times; 8h =
            <div class="sv"><?php echo seconds_to_hm($bottom_duty_seconds); ?></div>
        </div>
        <div class="summary-box">
            <b>Regular OT Hours</b>
            OT Entries <strong><?php echo $bottom_regular_ot_count; ?></strong> =
            <div class="sv"><?php echo seconds_to_hm($bottom_regular_ot_seconds); ?></div>
            <?php if ($bottom_regular_ot_deduct_seconds > 0): ?>
                <small>Short duty deducted: <?php echo seconds_to_hm($bottom_regular_ot_deduct_seconds); ?></small>
            <?php endif; ?>
        </div>
        <div class="summary-box">
            <b>After 6 pm Hours</b>
            OT Days <strong><?php echo $bottom_extra_ot_count; ?></strong> =
            <div class="sv"><?php echo seconds_to_hm($bottom_extra_ot_seconds); ?></div>
        </div>
        <div class="summary-box">
            <b>Sunday OT Hours</b>
            Sunday OT Days <strong><?php echo $bottom_sunday_ot_count; ?></strong> =
            <div class="sv"><?php echo seconds_to_hm($bottom_sunday_ot_seconds); ?></div>
        </div>
        <div class="summary-box">
            <b>Total Late</b>
            Sum of all late times =
            <div class="sv"><?php echo seconds_to_hm($bottom_late_seconds); ?></div>
        </div>
    </div>
    <div class="summary-note">
        <strong>Rules:</strong>
        Normal duty = 8h per present day.
        Regular OT = completed hours after 16:05 on normal days, max 2h.
        If Check-Out is before normal 8h duty end, shortage is rounded to the nearest hour and deducted from Regular OT.
        Extra OT = completed hours after 18:20 on normal days, starting from Check-Out &ge; 19:20.
        Friday duty counted if Check-Out &ge; 16:30; Friday Regular OT = completed hours after 16:45, max 2h; Friday After 6 pm = completed hours after 18:45.
        Sunday OT = total time from Check-In to Check-Out.
        Late = saved Late value if available; otherwise Check-In after 07:06 counts from 07:00.
    </div>
</div>
<?php endif; ?>

<script>
/* Sync horizontal scroll between a sticky top bar and the table */
const tableWrap = document.querySelector('.table-wrap');
// No extra sync div needed — table-wrap handles it natively.
</script>

</body>
</html>
