<?php
include 'auth.php';
requireAnyPermission(['salary_view', 'reports_view', 'overtime_view']);

/* ─────────────────────────────────────────────
   "After 6pm Duty Hours" report — the second part of the two-part salary.
   Shows, per employee, the After-6pm OT and Sunday OT hours + amounts using
   the SAME calculation as the salary sheet. No deductions — OT only.
───────────────────────────────────────────── */

$month       = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$month_title = date('F Y', strtotime($month . '-01'));
$search_user = trim($_GET['user_no'] ?? '');
$is_excel    = isset($_GET['export']) && $_GET['export'] === 'excel';

function a6_esc($conn, $v) { return mysqli_real_escape_string($conn, (string)$v); }
function a6_money($a) { return number_format((float)$a, 0); }
function a6_month_range($month) {
    return [date('Y-m-01', strtotime($month . '-01')), date('Y-m-t', strtotime($month . '-01'))];
}
function a6_time_to_seconds($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '00:00:00') return 0;
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) return 0;
    return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
}

/* After-6pm OT from attendance (matches monthly_ot_hours in generate_salary.php) */
function a6_attendance_ot($conn, $user_no, $employee_id, $month) {
    [$ms, $me] = a6_month_range($month);
    $u = a6_esc($conn, $user_no); $e = a6_esc($conn, $employee_id);
    $cond = "user_no='$u'";
    if ($employee_id !== '') $cond = "(user_no='$u' OR employee_id='$e')";
    $res = mysqli_query($conn, "SELECT attendance_date, check_out FROM attendance
        WHERE $cond AND attendance_date BETWEEN '" . a6_esc($conn, $ms) . "' AND '" . a6_esc($conn, $me) . "'
        AND check_in IS NOT NULL AND TRIM(check_in)!='' AND check_out IS NOT NULL AND TRIM(check_out)!=''");
    $hours = 0;
    $normal_base = a6_time_to_seconds('18:20:00');
    $friday_base = a6_time_to_seconds('18:45:00');
    $grace = 120;
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $date = $row['attendance_date'] ?? '';
            if ($date === '') continue;
            $day = date('l', strtotime($date));
            if ($day === 'Sunday') continue;
            $out = a6_time_to_seconds($row['check_out'] ?? '');
            if ($day === 'Friday') {
                if ($out >= (a6_time_to_seconds('19:45:00') - $grace)) $hours += floor((($out + $grace) - $friday_base) / 3600);
            } elseif ($out >= (a6_time_to_seconds('19:20:00') - $grace)) {
                $hours += floor((($out + $grace) - $normal_base) / 3600);
            }
        }
    }
    return (float)$hours;
}

/* Uploaded OT (overtime_records) split into total + Sunday */
function a6_uploaded_ot($conn, $user_no, $employee_id, $month, $sunday_only = false) {
    $t = mysqli_query($conn, "SHOW TABLES LIKE 'overtime_records'");
    if (!$t || mysqli_num_rows($t) == 0) return 0;
    [$ms, $me] = a6_month_range($month);
    $u = a6_esc($conn, $user_no); $e = a6_esc($conn, $employee_id);
    $cond = "user_no='$u'";
    $ec = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'employee_id'");
    if ($ec && mysqli_num_rows($ec) > 0 && $employee_id !== '') $cond = "(user_no='$u' OR employee_id='$e')";
    $sun = $sunday_only ? " AND DAYNAME(attendance_date)='Sunday'" : "";
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(ot_hours),0) AS total FROM overtime_records
        WHERE $cond AND attendance_date BETWEEN '" . a6_esc($conn, $ms) . "' AND '" . a6_esc($conn, $me) . "'$sun"));
    return (float)($row['total'] ?? 0);
}

/* Build the employee list. */
$emp_cols = [];
$cq = mysqli_query($conn, "SHOW COLUMNS FROM employees");
if ($cq) { while ($c = mysqli_fetch_assoc($cq)) { $emp_cols[$c['Field']] = true; } }
$status_col = isset($emp_cols['employee_status']) ? 'employee_status' : (isset($emp_cols['status']) ? 'status' : null);

$where = "1=1";
if ($search_user !== '') {
    $s = a6_esc($conn, $search_user);
    $where = "(user_no='$s' OR employee_id='$s' OR full_name LIKE '%$s%')";
}
$emps = [];
$eq = mysqli_query($conn, "SELECT user_no, employee_id, full_name, basic_salary FROM employees WHERE $where ORDER BY CAST(user_no AS UNSIGNED) ASC, user_no ASC");
if ($eq) { while ($r = mysqli_fetch_assoc($eq)) { $emps[] = $r; } }

/* Compute rows. */
$rows = [];
$tot_after6pm_hrs = 0; $tot_after6pm_amt = 0; $tot_sun_hrs = 0; $tot_sun_amt = 0; $tot_amt = 0;
foreach ($emps as $emp) {
    $uno = $emp['user_no'];
    $eid = $emp['employee_id'] ?? '';
    $basic = (float)($emp['basic_salary'] ?? 0);

    $att_after6pm   = a6_attendance_ot($conn, $uno, $eid, $month);
    $uploaded_total = a6_uploaded_ot($conn, $uno, $eid, $month, false);
    $sunday_hrs     = a6_uploaded_ot($conn, $uno, $eid, $month, true);
    $nonsun_upload  = max(0, $uploaded_total - $sunday_hrs);
    $after6pm_hrs   = $att_after6pm + $nonsun_upload;

    if ($after6pm_hrs <= 0 && $sunday_hrs <= 0) continue; // OT-only sheet

    $rate = $basic / 30 / 8;
    $after6pm_amt = $rate * 1.25 * $after6pm_hrs;
    $sunday_amt   = $rate * 1.5 * $sunday_hrs;
    $row_total    = $after6pm_amt + $sunday_amt;

    $rows[] = [
        'user_no' => $uno, 'name' => $emp['full_name'] ?? '', 'basic' => $basic,
        'after6pm_hrs' => $after6pm_hrs, 'after6pm_amt' => $after6pm_amt,
        'sunday_hrs' => $sunday_hrs, 'sunday_amt' => $sunday_amt, 'total' => $row_total,
    ];
    $tot_after6pm_hrs += $after6pm_hrs; $tot_after6pm_amt += $after6pm_amt;
    $tot_sun_hrs += $sunday_hrs; $tot_sun_amt += $sunday_amt; $tot_amt += $row_total;
}

if ($is_excel) {
    $fn = 'after6pm_duty_' . date('F_Y', strtotime($month . '-01'));
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . $fn . ".xls");
    header("Pragma: no-cache"); header("Expires: 0");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>After 6pm Duty Hours — <?php echo htmlspecialchars($month_title); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--sunday:#fff3cd;--radius:6px;}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:13px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-weight:700;}
.btn{display:inline-flex;align-items:center;gap:6px;border:none;border-radius:7px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}
.btn-back{background:rgba(255,255,255,.15);color:#fff;}
.btn-primary{background:var(--brand-mid);color:#fff;}
.btn-success{background:var(--green);color:#fff;}
.btn-gray{background:var(--gray-200);color:var(--gray-800);}
.btn-outline{background:#fff;color:var(--brand);}
.page{max-width:1100px;margin:20px auto;padding:0 16px;}
.page-title{display:flex;align-items:center;gap:10px;font-size:21px;font-weight:700;color:var(--brand);margin-bottom:6px;}
.subtitle{color:var(--gray-600);margin-bottom:16px;font-size:13px;}
.filter{background:#fff;border:1px solid var(--gray-200);border-radius:8px;padding:14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.filter input[type=month],.filter input[type=text]{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:14px;}
.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:8px;padding:14px 18px;}
.card .l{color:var(--gray-600);font-size:12.5px;font-weight:600;}
.card .v{font-size:22px;font-weight:800;color:var(--accent);margin-top:4px;}
.table-wrap{background:#fff;border:1px solid var(--gray-200);border-radius:8px;overflow:auto;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid var(--gray-200);text-align:center;font-size:13px;}
thead th{background:var(--brand);color:#fff;position:sticky;top:0;}
td.l,th.l{text-align:left;}
tfoot td{font-weight:800;background:#f8fafc;}
.sun{background:var(--sunday);}
@media print{.topbar .btn,.filter{display:none!important;}body{background:#fff;}}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="btn btn-back">&#8592; Dashboard</a>
        <a href="generate_salary.php" class="btn btn-back">&#128181; Salary Sheet</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;') : ''; ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
    <button onclick="window.print()" class="btn btn-outline">&#128438; Print</button>
</div>

<div class="page">
    <div class="page-title"><span>&#127769;</span> After 6pm Duty Hours &mdash; <?php echo htmlspecialchars($month_title); ?></div>
    <div class="subtitle">Second part of the salary (paid separately): after-6pm OT (1.25&times;) and Sunday OT (1.5&times;). Same calculation as the salary sheet &mdash; OT hours and amount only, no deductions.</div>

    <form method="GET" class="filter">
        <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" required>
        <input type="text" name="user_no" value="<?php echo htmlspecialchars($search_user); ?>" placeholder="&#128269; Search User No / ID / Name">
        <button type="submit" class="btn btn-primary">Search</button>
        <a class="btn btn-success" href="after6pm_duty.php?month=<?php echo urlencode($month); ?>&user_no=<?php echo urlencode($search_user); ?>&export=excel">&#8659; Excel</a>
        <a class="btn btn-gray" href="after6pm_duty.php">&#10005; Clear</a>
    </form>

    <div class="cards">
        <div class="card"><div class="l">After 6pm OT Hours</div><div class="v"><?php echo number_format($tot_after6pm_hrs, 2); ?></div></div>
        <div class="card"><div class="l">Sunday OT Hours</div><div class="v"><?php echo number_format($tot_sun_hrs, 2); ?></div></div>
        <div class="card"><div class="l">Total Amount (AED)</div><div class="v"><?php echo a6_money($tot_amt); ?></div></div>
    </div>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>SL</th>
                <th>User No</th>
                <th class="l">Employee Name</th>
                <th>Basic</th>
                <th>After 6pm hrs</th>
                <th>After 6pm Amt</th>
                <th>Sunday OT hrs</th>
                <th>Sunday OT Amt</th>
                <th>Total Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="color:#94a3b8;padding:24px;">No after-6pm or Sunday duty hours found for this month.</td></tr>
        <?php else: $sl = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $sl++; ?></td>
                <td><strong><?php echo htmlspecialchars($r['user_no']); ?></strong></td>
                <td class="l"><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo a6_money($r['basic']); ?></td>
                <td><?php echo number_format($r['after6pm_hrs'], 2); ?></td>
                <td><?php echo a6_money($r['after6pm_amt']); ?></td>
                <td class="<?php echo $r['sunday_hrs'] > 0 ? 'sun' : ''; ?>"><?php echo number_format($r['sunday_hrs'], 2); ?></td>
                <td class="<?php echo $r['sunday_hrs'] > 0 ? 'sun' : ''; ?>"><?php echo a6_money($r['sunday_amt']); ?></td>
                <td><strong><?php echo a6_money($r['total']); ?></strong></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right;">TOTAL</td>
                <td><?php echo number_format($tot_after6pm_hrs, 2); ?></td>
                <td><?php echo a6_money($tot_after6pm_amt); ?></td>
                <td><?php echo number_format($tot_sun_hrs, 2); ?></td>
                <td><?php echo a6_money($tot_sun_amt); ?></td>
                <td><?php echo a6_money($tot_amt); ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
</body>
</html>
