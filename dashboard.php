<?php
include 'auth.php';
requirePermission('dashboard_view');
include_once 'visa_helper.php';
include_once 'passport_helper.php';

$loggedInName = trim((string)($_SESSION['full_name'] ?? ''));
$loggedInUser = trim((string)($_SESSION['username'] ?? ''));
$loggedInRole = trim((string)($_SESSION['role'] ?? ''));
if ($loggedInName === '') {
    $loggedInName = $loggedInUser !== '' ? $loggedInUser : 'User';
}
if ($loggedInRole === '') {
    $loggedInRole = 'User';
}

/* ─────────────────────────────────────────────
   Helper: safe query fetch
───────────────────────────────────────────── */
function safe_count($conn, $sql) {
    $q = mysqli_query($conn, $sql);
    if (!$q) return 0;
    $row = mysqli_fetch_assoc($q);
    return (int)($row['total'] ?? 0);
}

/* ─────────────────────────────────────────────
   Employee counts
───────────────────────────────────────────── */
$employee_columns_dashboard = [];
$q = mysqli_query($conn, "SHOW COLUMNS FROM employees");
if ($q) {
    while ($col = mysqli_fetch_assoc($q)) {
        $employee_columns_dashboard[$col['Field']] = true;
    }
}

$salary_columns_dashboard = [];
$q2 = mysqli_query($conn, "SHOW COLUMNS FROM employee_salary_records");
if ($q2) {
    while ($col = mysqli_fetch_assoc($q2)) {
        $salary_columns_dashboard[$col['Field']] = true;
    }
}

$status_col = isset($employee_columns_dashboard['employee_status'])
    ? 'employee_status'
    : (isset($employee_columns_dashboard['status']) ? 'status' : null);

/* "Departed" — an employee is dropped from the dashboard counts once they
   have effectively left, signalled by ANY of:
     • a Completed visa cancellation,
     • the visa Cancel Date being filled (visa already cancelled), or
     • an Absconding cancellation reason.
   An employee still in notice period with a BLANK cancel date keeps counting
   as Active (absent shows as absent). */
$vc_exists_dash = mysqli_query($conn, "SHOW TABLES LIKE 'visa_cancellations'");
$not_cancelled  = ($vc_exists_dash && mysqli_num_rows($vc_exists_dash) > 0)
    ? " AND user_no NOT IN (
            SELECT user_no FROM visa_cancellations
            WHERE cancellation_status='Completed'
               OR LOWER(TRIM(cancellation_reason))='absconding'
               OR (visa_cancellation_date IS NOT NULL AND TRIM(visa_cancellation_date) != '' AND visa_cancellation_date != '0000-00-00')
        )"
    : "";

$total_employee_condition = "1=1" . $not_cancelled;
$totalEmployees   = safe_count($conn, "SELECT COUNT(*) AS total FROM employees WHERE $total_employee_condition");

$active_employee_condition = "1=1" . $not_cancelled;
if ($status_col) {
    // Everyone who hasn't been visa-cancelled is active, except those
    // explicitly marked Inactive.
    $active_employee_condition .= " AND (`$status_col` IS NULL OR `$status_col`='' OR LOWER(`$status_col`)!='inactive')";
}
$activeEmployees = safe_count($conn, "SELECT COUNT(*) AS total FROM employees WHERE $active_employee_condition");

$inactiveEmployees = $status_col
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM employees WHERE LOWER(`$status_col`)='inactive'" . $not_cancelled)
    : 0;

/* ─────────────────────────────────────────────
   Previous month salary total
───────────────────────────────────────────── */
$currentMonth      = date('Y-m');
$previousMonth     = date('Y-m', strtotime('first day of previous month'));
$previousMonthName = date('F Y', strtotime($previousMonth . "-01"));
$safe_prev_month   = mysqli_real_escape_string($conn, $previousMonth);

$net_salary_expression = isset($salary_columns_dashboard['net_payable'])
    ? "COALESCE(NULLIF(s.net_payable,''), NULLIF(s.net_salary,''), 0)"
    : "COALESCE(NULLIF(s.net_salary,''), 0)";

$salary_generated_condition = isset($salary_columns_dashboard['salary_status'])
    ? "AND s.salary_status IN ('Generated', 'Paid', 'generated', 'paid')"
    : "";

$salary_active_condition = "1=1";
if ($status_col) {
    $salary_active_condition = "(e.user_no IS NULL OR LOWER(e.`$status_col`)='active' OR e.`$status_col`='' OR e.`$status_col` IS NULL)";
    if (isset($employee_columns_dashboard['resign_date'])) {
        $salary_active_condition = "($salary_active_condition OR (LOWER(e.`$status_col`) IN ('inactive', 'resign', 'resigned') AND e.resign_date IS NOT NULL AND e.resign_date!='' AND DATE_FORMAT(e.resign_date,'%Y-%m') >= '$safe_prev_month'))";
    }
}

$salary_q = mysqli_query($conn, "
    SELECT COALESCE(SUM($net_salary_expression), 0) AS total
    FROM employee_salary_records s
    LEFT JOIN employees e ON s.user_no = e.user_no
    WHERE s.salary_month = '$safe_prev_month'
    $salary_generated_condition
    AND $salary_active_condition
");
$totalSalary = $salary_q ? (float)(mysqli_fetch_assoc($salary_q)['total'] ?? 0) : 0;

/* ─────────────────────────────────────────────
   Other counts
───────────────────────────────────────────── */
$totalHolidays   = safe_count($conn, "SELECT COUNT(*) AS total FROM holidays");
$today      = date('Y-m-d');
$three_months = date('Y-m-d', strtotime('+3 months'));
$safe_today      = mysqli_real_escape_string($conn, $today);
$safe_three_months = mysqli_real_escape_string($conn, $three_months);

/* Visa alert count — shared logic (visa_helper.php): active (not
   resigned/left) employees whose visa is already expired or expiring
   within 3 months. Keeps the dashboard, employee list, and report in sync. */
$visa_expire_count = visa_alert_count($conn);
$expired_visas = visa_expired_list($conn);

/* Passport alert count — shared logic (passport_helper.php): active (not
   resigned/left) employees whose passport is already expired or expiring
   within one month. */
$passport_expire_count = passport_alert_count($conn);

/* Open complaints (Pending / In Progress) — surfaced on login so HR can
   act on them. Closed = Solved / Rejected. */
$open_complaints_count = 0;
$complaints_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'complaints'");
if ($complaints_tbl && mysqli_num_rows($complaints_tbl) > 0) {
    $open_complaints_count = safe_count($conn, "
        SELECT COUNT(*) AS total FROM complaints
        WHERE complaint_status IS NULL OR complaint_status='' OR complaint_status IN ('Pending','In Progress')
    ");
}

$totalvacationToday = 0;
$vacationCheck = mysqli_query($conn, "SHOW TABLES LIKE 'vacations'");
if ($vacationCheck && mysqli_num_rows($vacationCheck) > 0) {
    $totalvacationToday = safe_count($conn, "
        SELECT COUNT(DISTINCT user_no) AS total FROM vacations
        WHERE from_date <= '$safe_today'
          AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00' OR return_date > '$safe_today')
          AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
    ");
}

if ($vacationCheck && mysqli_num_rows($vacationCheck) > 0) {
    $active_vacation_condition = "1=1";
    if ($status_col) {
        $active_vacation_condition = "(LOWER(e.`$status_col`)='active' OR e.`$status_col`='' OR e.`$status_col` IS NULL)";
    }
    if (isset($employee_columns_dashboard['resign_date'])) {
        $active_vacation_condition .= " AND (
            e.resign_date IS NULL
            OR e.resign_date=''
            OR e.resign_date >= '$safe_today'
        )";
    }

    $activeVacationToday = safe_count($conn, "
        SELECT COUNT(DISTINCT v.user_no) AS total
        FROM vacations v
        INNER JOIN employees e ON e.user_no = v.user_no
        WHERE v.from_date <= '$safe_today'
        AND (v.return_date IS NULL OR v.return_date='' OR v.return_date='0000-00-00' OR v.return_date > '$safe_today')
        AND COALESCE(v.vacation_status,'') NOT IN ('Cancelled','Returned')
        AND $active_vacation_condition
    ");
    $activeEmployees = max(0, $activeEmployees - $activeVacationToday);
}

/* ─────────────────────────────────────────────
   Reminder ribbon data: employees due back from vacation THIS MONTH
   who have NOT yet re-joined. "Joined" = an attendance check-in on or
   after their (effective) return date — so the moment their name shows
   up in the attendance sheet, they drop off this list automatically.
───────────────────────────────────────────── */
$vacation_return_list = [];
if ($vacationCheck && mysqli_num_rows($vacationCheck) > 0) {
    $month_start = date('Y-m-01');
    $month_end   = date('Y-m-t');
    $eff_return  = "COALESCE(NULLIF(v.return_date,'0000-00-00'), v.to_date)";

    /* Exclude employees who have left / resigned — they shouldn't show on the
       "back from vacation" ribbon even if they have no attendance, because
       they are no longer on duty. Signals: completed visa cancellation,
       a resigned/inactive employee status, or a set resign date. */
    $ribbon_exclude = "";
    if ($vc_exists_dash && mysqli_num_rows($vc_exists_dash) > 0) {
        $ribbon_exclude .= " AND v.user_no NOT IN (SELECT user_no FROM visa_cancellations WHERE cancellation_status='Completed')";
    }
    if ($status_col) {
        $ribbon_exclude .= " AND (e.`$status_col` IS NULL OR e.`$status_col`='' OR LOWER(e.`$status_col`) NOT IN ('inactive','resign','resigned','terminated','left','cancelled','end of contract','endofcontract','absconding'))";
    }
    if (isset($employee_columns_dashboard['resign_date'])) {
        $ribbon_exclude .= " AND (e.resign_date IS NULL OR e.resign_date='' OR e.resign_date='0000-00-00')";
    }

    $vr_q = mysqli_query($conn, "
        SELECT v.user_no,
               COALESCE(e.full_name, v.employee_name, '') AS full_name,
               COALESCE(e.department, '') AS department,
               $eff_return AS eff_return
        FROM vacations v
        LEFT JOIN employees e ON TRIM(e.user_no) = TRIM(v.user_no)
        WHERE $eff_return BETWEEN '$month_start' AND '$month_end'
          AND COALESCE(v.vacation_status,'') NOT IN ('Cancelled','Returned')
          $ribbon_exclude
          AND NOT EXISTS (
              SELECT 1 FROM attendance a
              WHERE TRIM(a.user_no) = TRIM(v.user_no)
                AND a.check_in IS NOT NULL
                AND TRIM(a.check_in) <> ''
                AND a.attendance_date >= $eff_return
          )
        ORDER BY eff_return ASC
    ");
    if ($vr_q) {
        $seen = [];
        while ($vr = mysqli_fetch_assoc($vr_q)) {
            $uno = trim((string)$vr['user_no']);
            if ($uno === '' || isset($seen[$uno])) continue;
            $seen[$uno] = true;
            $vacation_return_list[] = $vr;
        }
    }
}

$dashboard_active_employee_condition = "employees.user_no IS NOT NULL";
if ($status_col) {
    $dashboard_active_employee_condition .= " AND (
        LOWER(employees.`$status_col`)='active'
        OR employees.`$status_col`=''
        OR employees.`$status_col` IS NULL
    )";
}
if (isset($employee_columns_dashboard['resign_date'])) {
    $dashboard_active_employee_condition .= " AND (
        employees.resign_date IS NULL
        OR employees.resign_date=''
        OR employees.resign_date >= attendance.attendance_date
    )";
}

$dashboard_late_condition = "(
    (
        attendance.late_time IS NOT NULL
        AND TRIM(attendance.late_time) != ''
        AND TRIM(attendance.late_time) NOT IN ('00:00','00:00:00')
    )
    OR (
        attendance.check_in IS NOT NULL
        AND TRIM(attendance.check_in) != ''
        AND TIME_TO_SEC(attendance.check_in) > TIME_TO_SEC('07:06:00')
    )
)";

/* ─────────────────────────────────────────────
   This-month daily attendance chart
───────────────────────────────────────────── */
$holiday_dates = [];
$hq = mysqli_query($conn, "SELECT holiday_date FROM holidays");
if ($hq) {
    while ($hr = mysqli_fetch_assoc($hq)) {
        $holiday_dates[] = "'" . mysqli_real_escape_string($conn, $hr['holiday_date']) . "'";
    }
}
$holiday_in = !empty($holiday_dates) ? implode(',', $holiday_dates) : "'0000-00-00'";

$dailyChart   = [];
$daysInMonth  = (int)date('t', strtotime($currentMonth . "-01"));

for ($d = 1; $d <= $daysInMonth; $d++) {
    $date     = $currentMonth . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
    $label    = date('d-m-y', strtotime($date));
    $dayName  = date('l', strtotime($date));
    $safe_date = mysqli_real_escape_string($conn, $date);

    $q = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT
        SUM(CASE
            WHEN EXISTS (
                SELECT 1 FROM vacations l
                WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
                AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
            ) THEN 0
            WHEN $dashboard_active_employee_condition
                AND check_in IS NOT NULL
                AND TRIM(check_in) != '' THEN 1
            ELSE 0
        END) AS present,
        SUM(CASE
            WHEN EXISTS (
                SELECT 1 FROM vacations l
                WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
                AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
            ) THEN 0
            WHEN (check_in IS NULL OR TRIM(check_in) = '')
                AND $dashboard_active_employee_condition
                AND DAYNAME(attendance_date) != 'Sunday'
                AND attendance_date NOT IN ($holiday_in) THEN 1
            ELSE 0
        END) AS absent
        FROM attendance
        LEFT JOIN employees ON TRIM(employees.user_no) = TRIM(attendance.user_no)
        WHERE attendance_date = '$safe_date'
    "));

    $dailyChart[] = [
        'label'   => $label,
        'day'     => $dayName,
        'present' => (int)($q['present'] ?? 0),
        'absent'  => (int)($q['absent']  ?? 0),
    ];
}

/* ─────────────────────────────────────────────
   Today pie chart
───────────────────────────────────────────── */
$todayChart = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
    SUM(CASE
        WHEN EXISTS (
            SELECT 1 FROM vacations l
            WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
            AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
        ) THEN 0
        WHEN $dashboard_active_employee_condition
            AND check_in IS NOT NULL
            AND TRIM(check_in) != '' THEN 1
        ELSE 0
    END) AS present,
    SUM(CASE
        WHEN EXISTS (
            SELECT 1 FROM vacations l
            WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
            AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
        ) THEN 0
        WHEN (check_in IS NULL OR TRIM(check_in) = '')
            AND $dashboard_active_employee_condition
            AND DAYNAME(attendance.attendance_date) != 'Sunday'
            AND attendance.attendance_date NOT IN ($holiday_in)
            THEN 1
        ELSE 0
    END) AS absent,
    SUM(CASE
        WHEN EXISTS (
            SELECT 1 FROM vacations l
            WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
            AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
        ) THEN 0
        WHEN $dashboard_active_employee_condition
            AND $dashboard_late_condition THEN 1
        ELSE 0
    END) AS late
    FROM attendance
    LEFT JOIN employees ON TRIM(employees.user_no) = TRIM(attendance.user_no)
    WHERE attendance.attendance_date = '$safe_today'
"));

$todayPresent = (int)($todayChart['present'] ?? 0);
$todayAbsent  = (int)($todayChart['absent']  ?? 0);
$todayLate    = (int)($todayChart['late']    ?? 0);

function dashboard_people_list($conn, $sql) {
    $people = [];
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $people[] = [
                'user_no' => $row['user_no'] ?? '',
                'name' => $row['full_name'] ?? $row['employee_name'] ?? '',
            ];
        }
    }
    return $people;
}

$todayAbsentPeople = dashboard_people_list($conn, "
    SELECT attendance.user_no, COALESCE(employees.full_name, attendance.employee_name, '') AS full_name
    FROM attendance
    LEFT JOIN employees ON TRIM(employees.user_no) = TRIM(attendance.user_no)
    WHERE attendance.attendance_date = '$safe_today'
      AND (attendance.check_in IS NULL OR TRIM(attendance.check_in) = '')
      AND $dashboard_active_employee_condition
      AND DAYNAME(attendance.attendance_date) != 'Sunday'
      AND attendance.attendance_date NOT IN ($holiday_in)
      AND NOT EXISTS (
          SELECT 1 FROM vacations l
          WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
          AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
      )
    ORDER BY CAST(attendance.user_no AS UNSIGNED), attendance.user_no
");

/* Late seconds — mirrors calculated_late_seconds() in attendance_report.php:
   prefer the saved late_time; otherwise derive from check-in after the
   07:06 grace, measured from the 07:00 office start. */
$dashboard_late_seconds_expr = "
    CASE
        WHEN attendance.late_time IS NOT NULL
             AND TRIM(attendance.late_time) != ''
             AND TRIM(attendance.late_time) NOT IN ('00:00','00:00:00')
             THEN TIME_TO_SEC(attendance.late_time)
        WHEN attendance.check_in IS NOT NULL
             AND TRIM(attendance.check_in) != ''
             AND TIME_TO_SEC(attendance.check_in) > TIME_TO_SEC('07:06:00')
             THEN TIME_TO_SEC(attendance.check_in) - TIME_TO_SEC('07:00:00')
        ELSE 0
    END
";

$todayLatePeople = [];
$lateResult = mysqli_query($conn, "
    SELECT attendance.user_no,
           COALESCE(employees.full_name, attendance.employee_name, '') AS full_name,
           ($dashboard_late_seconds_expr) AS late_seconds
    FROM attendance
    LEFT JOIN employees ON TRIM(employees.user_no) = TRIM(attendance.user_no)
    WHERE attendance.attendance_date = '$safe_today'
      AND $dashboard_active_employee_condition
      AND $dashboard_late_condition
      AND NOT EXISTS (
          SELECT 1 FROM vacations l
          WHERE TRIM(l.user_no) = TRIM(attendance.user_no)
          AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
      )
    ORDER BY CAST(attendance.user_no AS UNSIGNED), attendance.user_no
");
if ($lateResult) {
    while ($row = mysqli_fetch_assoc($lateResult)) {
        $lateSeconds = max(0, (int)($row['late_seconds'] ?? 0));
        $lateMinutes = (int)round($lateSeconds / 60);
        $todayLatePeople[] = [
            'user_no'      => $row['user_no'] ?? '',
            'name'         => $row['full_name'] ?? '',
            'late_minutes' => $lateMinutes,
        ];
    }
}

$totalToday  = max($todayPresent + $todayAbsent + $todayLate, 1);
$presentDeg  = round(($todayPresent / $totalToday) * 360, 2);
$absentDeg   = round(($todayAbsent  / $totalToday) * 360, 2);
$lateDeg     = round(($todayLate / $totalToday) * 360, 2);
$absentStart = $presentDeg;
$lateStart   = $presentDeg + $absentDeg;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll Dashboard</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #eef2f7;
    color: #1a2533;
}

/* ── Sidebar ── */
.sidebar {
    width: 255px;
    height: 100vh;
    background: #1a2533;
    position: fixed;
    left: 0; top: 0;
    z-index: 1000;
    color: #fff;
    padding: 20px 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.sidebar-credit {
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,0.12);
    font-size: 11px;
    line-height: 1.5;
    color: rgba(255,255,255,0.55);
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 2px;
}

.logo {
    font-size: 15px;
    font-weight: 800;
    color: #f97316;
    margin-bottom: 24px;
    line-height: 1.4;
    letter-spacing: .3px;
}

.menu-title {
    padding: 11px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13.5px;
    font-weight: 600;
    color: #cbd5e1;
    transition: background .2s, color .2s;
    user-select: none;
}
.menu-title:hover, .menu-title.active {
    background: #f97316;
    color: #fff;
}

.submenu {
    display: none;
    margin: 2px 0 4px 10px;
    border-left: 2px solid #374151;
    padding-left: 10px;
}
.submenu.open { display: block; }

.submenu a {
    display: block;
    color: #94a3b8;
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 13px;
    transition: background .15s, color .15s;
}
.submenu a:hover { background: #2d3748; color: #fff; }

/* ── Main ── (offset handled by shared nav_sidebar's body padding) */
.main { margin-left: 0; padding: 22px; }

/* ── Topbar ── */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    padding: 16px 22px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    flex-wrap: wrap;
    gap: 12px;
}
.topbar h2 { font-size: 18px; color: #1e293b; }
.topbar small { color: #64748b; font-size: 13px; }

.topbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

/* ── Expired-visa reminder ribbon ── */
.visa-ribbon{display:flex;align-items:stretch;background:#b91c1c;color:#fff;border-radius:10px;margin:0 0 20px;overflow:hidden;box-shadow:0 3px 12px rgba(185,28,28,.35);}
.visa-ribbon-label{flex:0 0 auto;display:flex;align-items:center;background:#7f1414;padding:0 16px;font-weight:800;font-size:13px;letter-spacing:.6px;white-space:nowrap;}
.visa-ribbon-track{flex:1;overflow:hidden;position:relative;}
.visa-ribbon-content{display:inline-flex;align-items:center;white-space:nowrap;padding:11px 0;will-change:transform;animation:visaRibbonScroll 45s linear infinite;}
.visa-ribbon:hover .visa-ribbon-content{animation-play-state:paused;}
.visa-ribbon-item{padding:0 14px;font-size:13.5px;font-weight:600;}
.visa-ribbon-item b{color:#ffe08a;font-weight:800;}
.visa-ribbon-sep{color:rgba(255,255,255,.45);font-size:9px;}
@keyframes visaRibbonScroll{ from{transform:translateX(-50%);} to{transform:translateX(0);} }

/* ── Vacation-return reminder ribbon (scrolls right → left) ── */
.vac-ribbon{display:flex;align-items:stretch;background:#047857;color:#fff;border-radius:10px;margin:0 0 20px;overflow:hidden;box-shadow:0 3px 12px rgba(4,120,87,.35);}
.vac-ribbon-label{flex:0 0 auto;display:flex;align-items:center;background:#065f46;padding:0 16px;font-weight:800;font-size:13px;letter-spacing:.6px;white-space:nowrap;}
.vac-ribbon-track{flex:1;overflow:hidden;position:relative;}
.vac-ribbon-content{display:inline-flex;align-items:center;white-space:nowrap;padding:11px 0;will-change:transform;animation:vacRibbonScroll 45s linear infinite;}
.vac-ribbon:hover .vac-ribbon-content{animation-play-state:paused;}
.vac-ribbon-item{padding:0 14px;font-size:13.5px;font-weight:600;}
.vac-ribbon-item b{color:#bbf7d0;font-weight:800;}
.vac-ribbon-sep{color:rgba(255,255,255,.45);font-size:9px;}
@keyframes vacRibbonScroll{ from{transform:translateX(0);} to{transform:translateX(-50%);} }
@media (prefers-reduced-motion: reduce){ .visa-ribbon-content,.vac-ribbon-content{animation:none;} }

.user-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #dbe4ef;
    border-radius: 9px;
    padding: 8px 12px;
    color: #1e293b;
    line-height: 1.2;
}
.user-badge strong {
    display: block;
    font-size: 13px;
}
.user-badge span {
    display: block;
    margin-top: 2px;
    color: #f97316;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}

.btn-visa {
    background: #dc2626;
    color: #fff;
    padding: 9px 16px;
    text-decoration: none;
    border-radius: 7px;
    font-weight: 700;
    font-size: 13px;
    transition: background .2s;
}
.btn-visa:hover { background: #b91c1c; }

.btn-passport {
    background: #2563a8;
    color: #fff;
    padding: 9px 16px;
    text-decoration: none;
    border-radius: 7px;
    font-weight: 700;
    font-size: 13px;
    transition: background .2s;
}
.btn-passport:hover { background: #1a3a5c; }

.btn-logout {
    background: #1e293b;
    color: #fff;
    padding: 9px 16px;
    text-decoration: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    transition: background .2s;
}
.btn-logout:hover { background: #0f172a; }

/* ── Cards ── */
.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 22px;
}

.card-link { text-decoration: none; color: inherit; display: block; }

.card {
    background: #fff;
    padding: 20px 22px;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    border: 1px solid #e9ecef;
    transition: transform .15s, box-shadow .15s;
}
.card-link:hover .card {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,.10);
}

.card h1 {
    margin: 0 0 6px;
    color: #f97316;
    font-size: 28px;
    font-weight: 800;
}
.card p { color: #64748b; font-size: 13px; }

/* ── Section ── */
.section {
    background: #fff;
    margin-top: 22px;
    padding: 22px;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    border: 1px solid #e9ecef;
}
.section h3 { margin-bottom: 16px; color: #1e293b; font-size: 15px; }

/* ── Upload box ── */
.upload-box {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}
.upload-box input[type="file"] {
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #f8fafc;
}
.upload-box button {
    background: #f97316;
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: background .2s;
}
.upload-box button:hover { background: #ea6e00; }

/* ── Progress bar ── */
.loader-wrap { display: none; margin-top: 12px; }
.loader-bar-bg {
    width: 100%;
    background: #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    height: 22px;
}
.loader-bar {
    width: 0%;
    height: 22px;
    background: #2563eb;
    color: #fff;
    text-align: center;
    line-height: 22px;
    font-weight: 700;
    font-size: 12px;
    transition: width .1s;
}
.loader-text {
    margin-top: 8px;
    color: #2563eb;
    font-weight: 600;
    font-size: 13px;
}

/* ═══════════════════════════════════════════
   3D BAR CHART — complete redesign
═══════════════════════════════════════════ */
.charts-row {
    display: flex;
    gap: 22px;
    flex-wrap: wrap;
    align-items: flex-start;
}

.big-chart-box {
    flex: 1;
    order: 2;
    min-width: 0;
    border: 1px solid #dde3ec;
    border-radius: 14px;
    padding: 22px 18px 14px;
    background: linear-gradient(160deg, #f0f4fa 0%, #ffffff 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.9), 0 4px 18px rgba(0,0,0,.07);
}

.chart-title {
    text-align: center;
    color: #1e293b;
    font-weight: 700;
    margin: 0 0 18px;
    font-size: 14.5px;
    letter-spacing: .2px;
}

/* Outer scrollable container */
.chart-scroll-outer {
    overflow-x: auto;
    overflow-y: visible;
    padding-bottom: 4px;
}

/* Fixed-height area: grid + bars */
.chart-canvas {
    position: relative;
    height: 260px;          /* grid area height */
    min-width: max-content;
    display: flex;
    align-items: flex-end;  /* bars grow upward from bottom */
    gap: 10px;
    padding: 0 4px 0 4px;
    border-bottom: 2px solid #cbd5e1;
}

/* Dashed grid lines — positioned from bottom */
.grid-line {
    position: absolute;
    left: 0; right: 0;
    height: 0;
    border-top: 1px dashed rgba(148,163,184,.35);
    pointer-events: none;
    z-index: 0;
}

/* One day group */
.day-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 46px;
    position: relative;
    z-index: 1;
}

/* Pair of bars (present + absent) — grow from bottom */
.bar-pair {
    display: flex;
    align-items: flex-end;
    gap: 5px;
    height: 240px;          /* matches .chart-canvas height minus border */
}

/* Single bar column: label floats above bar via absolute */
.bar-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    height: 100%;
    position: relative;   /* anchor for .bar-val */
}

/* ── 3D bars ── */
.bar-3d {
    width: 18px;
    border-radius: 3px 3px 0 0;
    position: relative;
    cursor: pointer;
    min-height: 0;
    /* animation set by JS */
}
.bar-3d:hover {
    filter: brightness(1.15);
    transform: scaleY(1.04);
    transform-origin: bottom;
}

/* Present: deep green */
.bar-present {
    background: linear-gradient(
        to right,
        #0d4f28 0%,
        #1a7a40 18%,
        #27ae60 55%,
        #20964f 85%,
        #155e30 100%
    );
    box-shadow:
        inset -3px 0 7px rgba(0,0,0,.38),
        inset  2px 0 4px rgba(255,255,255,.14),
        3px 5px 10px rgba(10,70,35,.4),
        0 2px 0 #0a3319;
}
.bar-present::before {
    content: '';
    position: absolute;
    top: -6px; left: 0; right: 0;
    height: 7px;
    background: linear-gradient(to bottom right,
        #6ee7a0 0%, #27ae60 45%, #145a32 100%
    );
    border-radius: 3px 3px 0 0;
}

/* Absent: deep red */
.bar-absent {
    background: linear-gradient(
        to right,
        #6b0000 0%,
        #9c1010 18%,
        #c0392b 55%,
        #a93226 85%,
        #7b1818 100%
    );
    box-shadow:
        inset -3px 0 7px rgba(0,0,0,.42),
        inset  2px 0 4px rgba(255,255,255,.10),
        3px 5px 10px rgba(100,0,0,.42),
        0 2px 0 #430000;
}
.bar-absent::before {
    content: '';
    position: absolute;
    top: -6px; left: 0; right: 0;
    height: 7px;
    background: linear-gradient(to bottom right,
        #f1948a 0%, #c0392b 45%, #7b1818 100%
    );
    border-radius: 3px 3px 0 0;
}

/* Value label — absolutely positioned just above bar top */
.bar-val {
    position: absolute;
    bottom: 0;           /* will be overridden by JS after animation */
    left: 50%;
    transform: translateX(-50%);
    font-size: 9px;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
    pointer-events: none;
    transition: bottom 0.5s cubic-bezier(.22,.68,0,1.15);
}

/* Date label row (below the canvas border) */
.day-labels-row {
    display: flex;
    gap: 10px;
    padding: 6px 4px 0;
    min-width: max-content;
}
.day-lbl {
    min-width: 46px;
    text-align: center;
    font-size: 9.5px;
    color: #64748b;
    white-space: nowrap;
}

/* Legend row */
.chart-legend {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 14px;
    font-size: 12.5px;
    font-weight: 600;
    color: #374151;
}
.legend-swatch {
    display: inline-block;
    width: 14px; height: 14px;
    border-radius: 3px;
    margin-right: 5px;
    vertical-align: middle;
    box-shadow: 0 1px 4px rgba(0,0,0,.25);
}
.swatch-present { background: linear-gradient(135deg, #27ae60, #0d4f28); }
.swatch-absent  { background: linear-gradient(135deg, #c0392b, #6b0000); }

/* ══════════════════════════════
   3D PIE CHART (SVG-based)
══════════════════════════════ */
.today-box {
    width: 380px;
    order: 1;
    flex-shrink: 0;
    border: 1px solid #dde3ec;
    border-radius: 14px;
    padding: 18px;
    background: linear-gradient(160deg, #f0f4fa 0%, #ffffff 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.9), 0 4px 18px rgba(0,0,0,.07);
    text-align: center;
}

/* SVG pie wrapper */
#pie3dWrap {
    width: 240px;
    margin: 10px auto 0;
    cursor: pointer;
    filter: drop-shadow(0 10px 18px rgba(0,0,0,.22));
    transform-origin: center center;
    transform-style: preserve-3d;
}
#pie3dWrap svg { display: block; overflow: visible; }

/* Pie slice hover */
.pie-slice { transition: filter .18s; }
.pie-slice:hover { filter: brightness(1.15); }

/* Stats under pie */
.pie-stats {
    display: flex;
    justify-content: center;
    gap: 14px;
    margin-top: 14px;
    flex-wrap: wrap;
}
.pie-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    min-width: 58px;
}
.pie-stat-num {
    font-size: 22px;
    font-weight: 900;
    line-height: 1;
}
.pie-stat-lbl {
    font-size: 11px;
    color: #64748b;
    font-weight: 600;
}

.pie-detail-box {
    display: none;
    margin-top: 14px;
    text-align: left;
    border: 1px solid #dbe4ef;
    border-radius: 8px;
    background: #fff;
    padding: 12px;
    max-height: 230px;
    overflow: auto;
}
.pie-detail-box.open { display: block; }
.pie-detail-title {
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
}
.pie-person {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 6px 0;
    border-bottom: 1px solid #eef2f7;
    font-size: 13px;
}
.pie-person:last-child { border-bottom: 0; }
.pie-person small { color: #64748b; }
.pie-person-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    text-align: right;
}
.pie-person-right .late-mins {
    color: #c05000;
    font-weight: 700;
    white-space: nowrap;
}
.pie-person-right small { color: #64748b; }

/* legacy — kept empty intentionally */

/* ── Responsive ── */
@media (max-width: 1200px) { .cards { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 900px)  { .cards { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 700px)  {
    .cards { grid-template-columns: 1fr; }
    .sidebar { width: 200px; }
    .main { margin-left: 0; }
    .charts-row { flex-direction: column; }
    .today-box { width: 100%; }
}
</style>
</head>
<body>

<!-- ══════════════════════════════
     Sidebar
══════════════════════════════ -->
<?php include 'nav_sidebar.php'; ?>

<!-- ══════════════════════════════
     Main Content
══════════════════════════════ -->
<div class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <h2><?php echo company_logo_img(28, 'margin-right:6px;'); ?> Euro Trousers MFG. Co. FZC</h2>
            <small><?php echo date("l, d F Y"); ?></small>
        </div>
        <div class="topbar-right">
            <div class="user-badge" title="Logged in user">
                <div>
                    <strong><?php echo htmlspecialchars($loggedInName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span><?php echo htmlspecialchars($loggedInRole, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
            <?php if ($visa_expire_count > 0): ?>
            <a href="visa_expiring.php" target="_blank" class="btn-visa">
                &#9888; Visa Expiring Soon: <?php echo $visa_expire_count; ?>
            </a>
            <?php endif; ?>
            <?php if ($passport_expire_count > 0): ?>
            <a href="passport_expiring.php" target="_blank" class="btn-passport">
                &#128217; Passport Expiring Soon: <?php echo $passport_expire_count; ?>
            </a>
            <?php endif; ?>
            <?php if ($open_complaints_count > 0 && hasPermission('complaints_manage')): ?>
            <a href="complaints.php?view=open" class="btn-visa" style="background:#7c3aed;">
                &#128221; Open Complaints: <?php echo $open_complaints_count; ?>
            </a>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout">&#128682; Logout</a>
        </div>
    </div>

    <?php if (!empty($expired_visas)): ?>
    <!-- ── Expired-visa reminder ribbon (continuous scroll) ── -->
    <div class="visa-ribbon" title="Employees working on an already-expired visa">
        <span class="visa-ribbon-label">&#9888; VISA EXPIRED</span>
        <div class="visa-ribbon-track">
            <div class="visa-ribbon-content">
                <?php
                $__today_ts = strtotime(date('Y-m-d'));
                for ($__r = 0; $__r < 2; $__r++) { // duplicated for a seamless loop
                    foreach ($expired_visas as $__v) {
                        $__exp = $__v['visa_expiry_date'] ?? '';
                        $__days = $__exp ? (int)floor(($__today_ts - strtotime($__exp)) / 86400) : 0;
                        $__name = htmlspecialchars((string)($__v['full_name'] ?? $__v['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $__uno  = htmlspecialchars((string)($__v['user_no'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $__dept = htmlspecialchars((string)($__v['department'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $__expd = $__exp ? date('d-m-Y', strtotime($__exp)) : '';
                        echo '<span class="visa-ribbon-item">&#128100; <b>' . $__name . '</b> (' . $__uno . ')'
                           . ($__dept !== '' ? ' &middot; ' . $__dept : '')
                           . ' &mdash; Visa expired <b>' . $__expd . '</b>'
                           . ($__days > 0 ? ' (' . $__days . ' day' . ($__days > 1 ? 's' : '') . ' ago)' : '')
                           . '</span><span class="visa-ribbon-sep">&#9679;</span>';
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($vacation_return_list)): ?>
    <!-- ── Vacation-return reminder ribbon (continuous scroll, right → left) ── -->
    <div class="vac-ribbon" title="Employees due back from vacation this month — auto-hides once they check in">
        <span class="vac-ribbon-label">&#127965; BACK FROM VACATION &mdash; THIS MONTH</span>
        <div class="vac-ribbon-track">
            <div class="vac-ribbon-content">
                <?php
                for ($__r = 0; $__r < 2; $__r++) { // duplicated for a seamless loop
                    foreach ($vacation_return_list as $__v) {
                        $__name = htmlspecialchars((string)($__v['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $__uno  = htmlspecialchars((string)($__v['user_no'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $__dept = htmlspecialchars((string)($__v['department'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $__ret  = !empty($__v['eff_return']) ? date('d-m-Y', strtotime($__v['eff_return'])) : '';
                        echo '<span class="vac-ribbon-item">&#128100; <b>' . $__name . '</b> (' . $__uno . ')'
                           . ($__dept !== '' ? ' &middot; ' . $__dept : '')
                           . ($__ret !== '' ? ' &mdash; returns <b>' . $__ret . '</b>' : '')
                           . '</span><span class="vac-ribbon-sep">&#9679;</span>';
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ── -->
    <div class="cards">

        <a href="employee_list.php" class="card-link">
            <div class="card">
                <h1><?php echo number_format($totalEmployees); ?></h1>
                <p>Total Employees</p>
            </div>
        </a>

        <a href="employee_list.php?status=Active" class="card-link">
            <div class="card">
                <h1><?php echo number_format($activeEmployees); ?></h1>
                <p>&#9989; Active Employees</p>
            </div>
        </a>

        <a href="employee_list.php?status=Inactive" class="card-link">
            <div class="card">
                <h1><?php echo number_format($inactiveEmployees); ?></h1>
                <p>&#128683; Inactive Employees</p>
            </div>
        </a>

        <a href="generate_salary.php?month=<?php echo urlencode($previousMonth); ?>&search=1" class="card-link">
            <div class="card">
                <h1><?php echo number_format($totalSalary); ?> AED</h1>
                <p>&#128181; <?php echo $previousMonthName; ?> Net Salary</p>
            </div>
        </a>
        <a href="holidays.php" class="card-link">
            <div class="card">
                <h1><?php echo number_format($totalHolidays); ?></h1>
                <p>&#127881; Total Holidays</p>
            </div>
        </a>

        <a href="vacation_details.php?month=<?php echo urlencode(date('Y-m')); ?>" class="card-link">
            <div class="card">
                <h1><?php echo number_format($totalvacationToday); ?></h1>
                <p>&#127965; On Vacation Today</p>
            </div>
        </a>

        <?php if (hasPermission('complaints_manage')): ?>
        <a href="complaints.php?view=open" class="card-link">
            <div class="card">
                <h1><?php echo number_format($open_complaints_count); ?></h1>
                <p>&#128221; Open Complaints</p>
            </div>
        </a>
        <?php endif; ?>

    </div>

    <!-- ── Charts ── -->
    <div class="section">
        <div class="charts-row">

            <!-- ═══ 3D Daily bar chart ═══ -->
            <div class="big-chart-box">
                <h3 class="chart-title">This Month — Daily Present &amp; Absent</h3>

                <?php
                /* ── Scale: use a SINGLE global max so present & absent are proportional ── */
                $allVals = [];
                foreach ($dailyChart as $d) {
                    if ((int)$d['present'] > 0) $allVals[] = (int)$d['present'];
                    if ((int)$d['absent']  > 0) $allVals[] = (int)$d['absent'];
                }
                $globalMax = $allVals ? max($allVals) : 1;
                $MAX_H = 230;   /* pixel height at globalMax */
                $MIN_H = 4;     /* minimum visible pixel height for non-zero bars */

                function calcBarH3d($val, $gmax, $maxH, $minH) {
                    $v = (int)$val;
                    if ($v <= 0) return 0;
                    $h = (int)round(($v / $gmax) * $maxH);
                    return max($h, $minH);
                }

                /* Grid lines at 25 / 50 / 75 / 100 % of MAX_H (from bottom) */
                $gridPcts = [25, 50, 75, 100];
                ?>

                <div class="chart-scroll-outer">

                    <!-- Fixed canvas: grid behind, bars in front -->
                    <div class="chart-canvas">

                        <!-- Horizontal grid lines -->
                        <?php foreach ($gridPcts as $pct): ?>
                        <div class="grid-line" style="bottom:<?php echo round($pct/100*$MAX_H); ?>px;"></div>
                        <?php endforeach; ?>

                        <!-- Day columns -->
                        <?php foreach ($dailyChart as $d):
                            $ph = calcBarH3d($d['present'], $globalMax, $MAX_H, $MIN_H);
                            $ah = calcBarH3d($d['absent'],  $globalMax, $MAX_H, $MIN_H);
                            $isSun = ($d['day'] === 'Sunday');
                        ?>
                        <div class="day-col">
                            <div class="bar-pair">

                                <!-- Present bar -->
                                <div class="bar-col">
                                    <div class="bar-3d bar-present"
                                         data-h="<?php echo $ph; ?>"
                                         data-val="<?php echo $d['present'] > 0 ? $d['present'] : ''; ?>"
                                         data-color="#0d6b33"
                                         style="height:0px;"
                                         title="Present: <?php echo $d['present']; ?>">
                                    </div>
                                </div>

                                <!-- Absent bar -->
                                <div class="bar-col">
                                    <div class="bar-3d bar-absent"
                                         data-h="<?php echo $ah; ?>"
                                         data-val="<?php echo $d['absent'] > 0 ? $d['absent'] : ''; ?>"
                                         data-color="#8b0000"
                                         style="height:0px;"
                                         title="Absent: <?php echo $d['absent']; ?>">
                                    </div>
                                </div>

                            </div>
                        </div>
                        <?php endforeach; ?>

                    </div><!-- /.chart-canvas -->

                    <!-- Date labels below the border line -->
                    <div class="day-labels-row">
                        <?php foreach ($dailyChart as $d):
                            $isSun = ($d['day'] === 'Sunday');
                        ?>
                        <div class="day-lbl" style="<?php echo $isSun ? 'color:#f97316;font-weight:700;' : ''; ?>">
                            <?php echo $isSun ? 'Sun' : $d['label']; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div><!-- /.chart-scroll-outer -->

                <!-- Legend -->
                <div class="chart-legend">
                    <span>
                        <span class="legend-swatch swatch-present"></span> Present
                    </span>
                    <span>
                        <span class="legend-swatch swatch-absent"></span> Absent
                    </span>
                </div>
            </div>

            <!-- ═══ Today 3D SVG Pie chart ═══ -->
            <div class="today-box">
                <h3 class="chart-title">Today — Present / Absent / Late</h3>

                <!-- SVG 3D pie built by JS -->
                <div id="pie3dWrap"></div>

                <!-- Stat numbers -->
                <div class="pie-stats">
                    <div class="pie-stat">
                        <span class="pie-stat-num" style="color:#0d6b33;"><?php echo $todayPresent; ?></span>
                        <span class="pie-stat-lbl">&#9989; Present</span>
                    </div>
                    <div class="pie-stat">
                        <span class="pie-stat-num" style="color:#8b0000;"><?php echo $todayAbsent; ?></span>
                        <span class="pie-stat-lbl" style="cursor:pointer;" onclick="showPieDetails('absent')">&#10060; Absent</span>
                    </div>
                    <div class="pie-stat">
                        <span class="pie-stat-num" style="color:#c05000;"><?php echo $todayLate; ?></span>
                        <span class="pie-stat-lbl" style="cursor:pointer;" onclick="showPieDetails('late')">&#9200; Late</span>
                    </div>
                </div>

                <div class="pie-detail-box" id="pieDetailBox">
                    <div class="pie-detail-title" id="pieDetailTitle"></div>
                    <div id="pieDetailList"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Upload Employee Excel ── -->
    <div class="section" id="employee_upload">
        <h3>&#128100; Upload Employee Excel</h3>
        <form action="import_employees.php" method="POST" enctype="multipart/form-data" class="upload-box">
            <input type="file" name="employee_file" accept=".xlsx,.csv" required>
            <button type="submit">&#8679; Upload Employee Excel</button>
        </form>
    </div>

    <!-- ── Upload Attendance Excel ── -->
    <div class="section" id="attendance_upload">
        <h3>&#128337; Upload Attendance Excel</h3>
        <form action="import_attendance.php" method="POST" enctype="multipart/form-data"
              class="upload-box" onsubmit="showAttendanceLoader()">
            <input type="file" name="excel" accept=".xlsx,.xls,.csv" required>
            <button type="submit">&#8679; Upload Attendance</button>
        </form>

        <div class="loader-wrap" id="attendanceLoaderWrap">
            <div class="loader-bar-bg">
                <div class="loader-bar" id="attendanceLoaderBar">0%</div>
            </div>
            <p class="loader-text">Uploading attendance file... please wait.</p>
        </div>
    </div>

</div><!-- /.main -->

<script>
/* ── Sidebar toggle ── */
function toggleMenu(id) {
    var menu = document.getElementById(id);
    if (!menu) return;
    menu.classList.toggle('open');
}

/* ── Attendance upload progress ── */
function showAttendanceLoader() {
    var wrap = document.getElementById('attendanceLoaderWrap');
    var bar  = document.getElementById('attendanceLoaderBar');
    if (!wrap || !bar) return;
    wrap.style.display = 'block';
    var width = 0;
    if (window._attendanceInterval) clearInterval(window._attendanceInterval);
    window._attendanceInterval = setInterval(function () {
        if (width < 95) {
            width++;
            bar.style.width = width + '%';
            bar.innerText   = width + '%';
        } else {
            clearInterval(window._attendanceInterval);
        }
    }, 80);
}

/* ── Pie detail data ── */
var piePeople = {
    absent: <?php echo json_encode($todayAbsentPeople, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
    late:   <?php echo json_encode($todayLatePeople,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
};

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
}

function showPieDetails(type) {
    var box   = document.getElementById('pieDetailBox');
    var title = document.getElementById('pieDetailTitle');
    var list  = document.getElementById('pieDetailList');
    if (!box || !title || !list) return;
    var people = piePeople[type] || [];
    title.textContent = (type === 'late' ? 'Late Employees' : 'Absent Employees') + ' (' + people.length + ')';
    list.innerHTML = people.length === 0
        ? '<div class="pie-person">No employee found.</div>'
        : people.map(function(p) {
            var right = escapeHtml(p.user_no || '');
            if (type === 'late') {
                var mins = parseInt(p.late_minutes, 10) || 0;
                var lateTxt = mins > 0 ? formatLate(mins) + ' late' : '—';
                right = '<span class="late-mins">&#9200; ' + escapeHtml(lateTxt) + '</span>'
                      + '<small>' + escapeHtml(p.user_no || '') + '</small>';
                return '<div class="pie-person"><span>' + escapeHtml(p.name || 'No Name') + '</span>'
                     + '<span class="pie-person-right">' + right + '</span></div>';
            }
            return '<div class="pie-person"><span>' + escapeHtml(p.name || 'No Name') + '</span><small>' + right + '</small></div>';
          }).join('');
    box.classList.add('open');
}

/* Format late minutes into a friendly "Xh Ym" / "Ym" string */
function formatLate(mins) {
    mins = parseInt(mins, 10) || 0;
    if (mins < 60) return mins + ' min';
    var h = Math.floor(mins / 60);
    var m = mins % 60;
    return m > 0 ? (h + 'h ' + m + 'm') : (h + 'h');
}

/* ════════════════════════════════════════
   3D SVG Pie Chart Builder
   Creates an isometric-style 3D donut pie:
   - Top face  = conic gradient segments
   - Side wall = extruded depth polygons
   - Inner hole = white circle
════════════════════════════════════════ */
(function() {
    var pPresent = <?php echo (int)$todayPresent; ?>;
    var pAbsent  = <?php echo (int)$todayAbsent; ?>;
    var pLate    = <?php echo (int)$todayLate; ?>;
    var total    = pPresent + pAbsent + pLate;
    if (total === 0) { pPresent = 1; total = 1; } /* avoid empty pie */

    var segments = [
        { val: pPresent, topColor: '#27ae60', sideColor: '#145a32', lightColor: '#6ee7a0', label: 'Present' },
        { val: pAbsent,  topColor: '#c0392b', sideColor: '#7b0000', lightColor: '#f1948a', label: 'Absent',  onclick: "showPieDetails('absent')" },
        { val: pLate,    topColor: '#e67e22', sideColor: '#a04000', lightColor: '#f8c471', label: 'Late',    onclick: "showPieDetails('late')" }
    ];

    var cx = 120, cy = 115;   /* SVG centre */
    var R  = 100;              /* outer radius */
    var r  = 48;               /* inner hole radius */
    var depth = 28;            /* 3D extrusion depth */
    var tilt  = 0.42;          /* vertical squish factor (isometric) */
    var SVG_W = 240, SVG_H = 230;

    /* Convert polar → flat isometric ellipse coords */
    function pt(angle, radius, yOffset) {
        var rad = (angle - 90) * Math.PI / 180;
        var x = cx + radius * Math.cos(rad);
        var y = (cy + yOffset) + radius * Math.sin(rad) * tilt;
        return [x, y];
    }

    function ptStr(angle, radius, yOffset) {
        var p = pt(angle, radius, yOffset);
        return p[0].toFixed(2) + ',' + p[1].toFixed(2);
    }

    /* Arc path for top face of one segment */
    function topArcPath(startAngle, endAngle, outerR, innerR, yOff) {
        var large = (endAngle - startAngle) > 180 ? 1 : 0;
        var o1 = ptStr(startAngle, outerR, yOff);
        var o2 = ptStr(endAngle,   outerR, yOff);
        var i2 = ptStr(endAngle,   innerR, yOff);
        var i1 = ptStr(startAngle, innerR, yOff);
        /* SVG elliptical arc: rx,ry x-rotation large-flag sweep-flag x,y */
        var rx = outerR, ry = outerR * tilt;
        var irx = innerR, iry = innerR * tilt;
        return [
            'M', o1,
            'A', rx+','+ry, '0', large, '1', o2,
            'L', i2,
            'A', irx+','+iry, '0', large, '0', i1,
            'Z'
        ].join(' ');
    }

    /* Side wall polygon for one edge (start or end of segment) */
    function sideWallPath(angle, outerR, innerR, yTop, yBottom) {
        var to = ptStr(angle, outerR, yTop);
        var bo = ptStr(angle, outerR, yBottom);
        var bi = ptStr(angle, innerR, yBottom);
        var ti = ptStr(angle, innerR, yTop);
        return 'M ' + to + ' L ' + bo + ' L ' + bi + ' L ' + ti + ' Z';
    }

    /* Outer arc side wall (the ring's outer edge) */
    function outerSidePath(startAngle, endAngle, outerR, yTop, yBottom) {
        var large = (endAngle - startAngle) > 180 ? 1 : 0;
        var rx = outerR, ry = outerR * tilt;
        var t1 = ptStr(startAngle, outerR, yTop);
        var t2 = ptStr(endAngle,   outerR, yTop);
        var b2 = ptStr(endAngle,   outerR, yBottom);
        var b1 = ptStr(startAngle, outerR, yBottom);
        return [
            'M', t1,
            'A', rx+','+ry, '0', large, '1', t2,
            'L', b2,
            'A', rx+','+ry, '0', large, '0', b1,
            'Z'
        ].join(' ');
    }

    var svgParts = [];
    svgParts.push('<svg width="' + SVG_W + '" height="' + SVG_H + '" viewBox="0 0 ' + SVG_W + ' ' + SVG_H + '" xmlns="http://www.w3.org/2000/svg">');

    /* Unique defs ID prefix */
    var uid = 'pie3d';
    svgParts.push('<defs>');
    segments.forEach(function(seg, i) {
        svgParts.push(
            '<linearGradient id="' + uid + 'sg' + i + '" x1="0%" y1="0%" x2="100%" y2="0%">',
            '<stop offset="0%"   stop-color="' + seg.sideColor + '"/>',
            '<stop offset="50%"  stop-color="' + seg.topColor  + '" stop-opacity="0.75"/>',
            '<stop offset="100%" stop-color="' + seg.sideColor + '"/>',
            '</linearGradient>'
        );
        svgParts.push(
            '<radialGradient id="' + uid + 'tg' + i + '" cx="40%" cy="35%" r="65%">',
            '<stop offset="0%"   stop-color="' + seg.lightColor + '"/>',
            '<stop offset="100%" stop-color="' + seg.topColor    + '"/>',
            '</radialGradient>'
        );
    });
    svgParts.push('</defs>');

    /* Build segments — draw BOTTOM layers first, then TOP */
    var curAngle = 0;
    var segData = segments.map(function(seg) {
        var sweep  = (seg.val / total) * 360;
        var start  = curAngle;
        var end    = curAngle + sweep;
        curAngle   = end;
        return { seg: seg, start: start, end: end, sweep: sweep };
    });

    var yTop    = 0;
    var yBottom = depth;

    /* ── 1. Outer side walls (back half: 180–360°, drawn first so top covers them) ── */
    /* Draw only segments whose arc crosses the bottom half (start < 360, end can wrap) */
    segData.forEach(function(sd, i) {
        if (sd.sweep === 0) return;
        /* Only render outer wall for bottom-facing arcs (angles 0-180 = top of ellipse visually hidden) */
        /* In isometric view, angles 0..180 face "upward" (away from viewer), 180..360 face "down" (toward viewer) */
        var visStart = Math.max(sd.start, 0);
        var visEnd   = Math.min(sd.end, 360);

        /* full outer side */
        var p = outerSidePath(sd.start, sd.end, R, yTop, yBottom);
        svgParts.push('<path class="pie-slice" d="' + p + '" fill="url(#' + uid + 'sg' + i + ')" opacity="0.92"' +
            (sd.seg.onclick ? ' onclick="' + sd.seg.onclick + '" style="cursor:pointer"' : '') + '/>');
    });

    /* ── 2. Inner side walls (bottom of hole — same depth) ── */
    segData.forEach(function(sd, i) {
        if (sd.sweep === 0) return;
        var p = outerSidePath(sd.start, sd.end, r, yTop, yBottom);
        /* inner wall is darker */
        svgParts.push('<path d="' + p + '" fill="' + sd.seg.sideColor + '" opacity="0.55"' +
            (sd.seg.onclick ? ' onclick="' + sd.seg.onclick + '" style="cursor:pointer"' : '') + '/>');
    });

    /* ── 3. Top faces ── */
    segData.forEach(function(sd, i) {
        if (sd.sweep === 0) return;
        var p = topArcPath(sd.start, sd.end, R, r, yTop);
        svgParts.push('<path class="pie-slice" d="' + p + '" fill="url(#' + uid + 'tg' + i + ')"' +
            (sd.seg.onclick ? ' onclick="' + sd.seg.onclick + '" style="cursor:pointer"' : '') + '/>');
    });

    /* ── 4. Segment divider lines on top face ── */
    segData.forEach(function(sd) {
        if (sd.sweep === 0) return;
        var o = pt(sd.start, R, yTop);
        var inn = pt(sd.start, r, yTop);
        svgParts.push('<line x1="' + o[0].toFixed(1) + '" y1="' + o[1].toFixed(1) +
                      '" x2="' + inn[0].toFixed(1) + '" y2="' + inn[1].toFixed(1) +
                      '" stroke="rgba(255,255,255,0.5)" stroke-width="1.2"/>');
    });

    /* ── 5. Centre hole overlay (white circle = donut hole) ── */
    var hcTop = pt(0, 0, yTop);
    var ry = r * tilt;
    svgParts.push('<ellipse cx="' + cx + '" cy="' + (cy + yTop) + '" rx="' + r + '" ry="' + ry.toFixed(1) + '" fill="#ffffff"/>');
    /* depth ring of hole */
    svgParts.push('<ellipse cx="' + cx + '" cy="' + (cy + yBottom) + '" rx="' + r + '" ry="' + ry.toFixed(1) + '" fill="#e8ecf2"/>');

    /* ── 6. Centre text ── */
    var textY = cy + yTop + 2;
    svgParts.push(
        '<text x="' + cx + '" y="' + (textY - 8) + '" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="10" font-weight="800" fill="#0d6b33">P: ' + pPresent + '</text>',
        '<text x="' + cx + '" y="' + (textY + 5) + '" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="10" font-weight="800" fill="#8b0000">A: ' + pAbsent + '</text>',
        '<text x="' + cx + '" y="' + (textY + 18) + '" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="10" font-weight="800" fill="#c05000">L: ' + pLate + '</text>'
    );

    svgParts.push('</svg>');

    document.getElementById('pie3dWrap').innerHTML = svgParts.join('\n');
})();

/* ════════════════════════════════════════
   Bar entry animation + floating value labels
════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    var bars = document.querySelectorAll('.bar-3d');
    bars.forEach(function(bar, i) {
        var targetH = parseInt(bar.getAttribute('data-h'), 10) || 0;
        var val     = bar.getAttribute('data-val') || '';
        var color   = bar.getAttribute('data-color') || '#333';

        /* Create label span — absolutely positioned above the bar */
        if (val !== '') {
            var lbl = document.createElement('span');
            lbl.className        = 'bar-val';
            lbl.textContent      = val;
            lbl.style.color      = color;
            lbl.style.bottom     = '2px';   /* start just above 0 */
            lbl.style.opacity    = '0';
            lbl.style.transition = 'bottom 0.52s cubic-bezier(.22,.68,0,1.15), opacity 0.3s ease';
            bar.parentNode.insertBefore(lbl, bar);
        }

        /* Animate bar height */
        bar.style.height     = '0px';
        bar.style.transition = 'none';

        var delay = 80 + i * 15;
        setTimeout(function() {
            bar.style.transition = 'height 0.52s cubic-bezier(.22,.68,0,1.15)';
            bar.style.height     = targetH + 'px';

            /* After bar finishes growing, pop label up above the bar top */
            var lbl2 = bar.previousElementSibling;
            if (lbl2 && lbl2.classList.contains('bar-val')) {
                /* Show label right away at final position */
                lbl2.style.bottom  = (targetH + 10) + 'px';
                lbl2.style.opacity = '1';
            }
        }, delay);
    });
});

/* ════════════════════════════════════════
   Pie 3D — spin-in + fade animation
   Wraps the SVG in a container and uses
   CSS transform: rotateY + scaleX to
   create a "coin flip reveal" effect
════════════════════════════════════════ */
(function() {
    var wrap = document.getElementById('pie3dWrap');
    if (!wrap) return;

    /* Start: squashed flat (coin-edge view) and tilted */
    wrap.style.transform     = 'rotateY(85deg) scaleX(0.15)';
    wrap.style.opacity       = '0';
    wrap.style.transition    = 'none';

    /* One frame later: trigger the reveal */
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            wrap.style.transition = 'transform 0.9s cubic-bezier(.34,1.45,.64,1), opacity 0.5s ease';
            wrap.style.transform  = 'rotateY(0deg) scaleX(1)';
            wrap.style.opacity    = '1';
        });
    });

    /* After the spin lands, do a subtle continuous slow-rotation wobble */
    setTimeout(function() {
        wrap.style.transition = 'none';
        /* gentle float: use a CSS animation via a style tag */
        var s = document.createElement('style');
        s.textContent = [
            '@keyframes pieFloat {',
            '  0%   { transform: rotateY(0deg) translateY(0px); }',
            '  40%  { transform: rotateY(4deg) translateY(-4px); }',
            '  70%  { transform: rotateY(-3deg) translateY(-2px); }',
            '  100% { transform: rotateY(0deg) translateY(0px); }',
            '}',
            '#pie3dWrap { animation: pieFloat 4s ease-in-out infinite; }'
        ].join('\n');
        document.head.appendChild(s);
    }, 1050);
})();
</script>

</body>
</html>
