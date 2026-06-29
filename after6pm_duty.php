<?php
include 'auth.php';
requireAnyPermission(['salary_view', 'reports_view', 'overtime_view']);
require_once 'after6pm_helper.php';

if (isset($_GET['generate']) && !hasPermission('salary_generate')) {
    requirePermission('salary_generate');
}

a6_ensure_table($conn);

/* ─────────────────────────────────────────────
   "After 6pm Duty Hours" — the second part of the two-part salary.
   After-6pm OT (1.25x) + Sunday OT (1.5x). Same calc as the salary sheet.
   No deductions. Can be Generated (saved) like the salary sheet, and has
   its own separate salary slip.
───────────────────────────────────────────── */

$month       = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$month_title = date('F Y', strtotime($month . '-01'));
$search_user = trim($_GET['user_no'] ?? '');
$is_excel    = isset($_GET['export']) && $_GET['export'] === 'excel';
$do_generate = isset($_GET['generate']);
$selected    = $_GET['selected_users'] ?? [];
if (!is_array($selected)) $selected = [$selected];
$selected    = array_values(array_filter(array_map('trim', $selected), fn($v) => $v !== ''));
$created_by  = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['role'] ?? 'User'));

/* Build the employee list. */
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

    $b = a6_breakdown($conn, $uno, $eid, $basic, $month);
    if ($b['after6pm_hours'] <= 0 && $b['sunday_hours'] <= 0) continue; // OT-only sheet

    /* Generate (save) for selected (or all listed if none selected). */
    $is_generated = false;
    if ($do_generate && (empty($selected) || in_array((string)$uno, $selected, true))) {
        $su = a6_esc($conn, $uno); $se = a6_esc($conn, $eid); $sm = a6_esc($conn, $month);
        $cb = a6_esc($conn, $created_by);
        mysqli_query($conn, "INSERT INTO after6pm_salary_records
            (user_no, employee_id, salary_month, basic_salary, after6pm_hours, after6pm_amount, sunday_hours, sunday_amount, total_amount, status, created_by)
            VALUES ('$su','$se','$sm'," . (float)$basic . "," . (float)$b['after6pm_hours'] . "," . (float)$b['after6pm_amount'] . "," . (float)$b['sunday_hours'] . "," . (float)$b['sunday_amount'] . "," . (float)$b['total'] . ",'Generated','$cb')
            ON DUPLICATE KEY UPDATE
                employee_id=VALUES(employee_id), basic_salary=VALUES(basic_salary),
                after6pm_hours=VALUES(after6pm_hours), after6pm_amount=VALUES(after6pm_amount),
                sunday_hours=VALUES(sunday_hours), sunday_amount=VALUES(sunday_amount),
                total_amount=VALUES(total_amount), status='Generated', created_by=VALUES(created_by)");
        $is_generated = true;
    } else {
        $rec = a6_get_record($conn, $uno, $month);
        $is_generated = $rec && strtolower((string)($rec['status'] ?? '')) === 'generated';
    }

    $rows[] = [
        'user_no' => $uno, 'name' => $emp['full_name'] ?? '', 'basic' => $basic,
        'after6pm_hrs' => $b['after6pm_hours'], 'after6pm_amt' => $b['after6pm_amount'],
        'sunday_hrs' => $b['sunday_hours'], 'sunday_amt' => $b['sunday_amount'], 'total' => $b['total'],
        'generated' => $is_generated,
    ];
    $tot_after6pm_hrs += $b['after6pm_hours']; $tot_after6pm_amt += $b['after6pm_amount'];
    $tot_sun_hrs += $b['sunday_hours']; $tot_sun_amt += $b['sunday_amount']; $tot_amt += $b['total'];
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
.btn-warning{background:var(--accent);color:#1a1a1a;}
.btn-gray{background:var(--gray-200);color:var(--gray-800);}
.btn-outline{background:#fff;color:var(--brand);}
.btn-sm{padding:5px 10px;font-size:12px;}
.page{max-width:1150px;margin:20px auto;padding:0 16px;}
.page-title{display:flex;align-items:center;gap:10px;font-size:21px;font-weight:700;color:var(--brand);margin-bottom:6px;}
.subtitle{color:var(--gray-600);margin-bottom:16px;font-size:13px;}
.filter{background:#fff;border:1px solid var(--gray-200);border-radius:8px;padding:14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.filter input[type=month],.filter input[type=text]{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:14px;}
.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:8px;padding:14px 18px;}
.card .l{color:var(--gray-600);font-size:12.5px;font-weight:600;}
.card .v{font-size:22px;font-weight:800;color:var(--accent);margin-top:4px;}
.actions-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--brand);padding:10px 14px;border-radius:8px 8px 0 0;color:#fff;}
.actions-bar label{font-size:13px;}
.table-wrap{background:#fff;border:1px solid var(--gray-200);border-radius:0 0 8px 8px;overflow:auto;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid var(--gray-200);text-align:center;font-size:13px;}
thead th{background:var(--brand-mid);color:#fff;position:sticky;top:0;}
td.l,th.l{text-align:left;}
tfoot td{font-weight:800;background:#f8fafc;}
.sun{background:var(--sunday);}
.badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;}
.badge-gen{background:#dcfce7;color:#166534;}
.badge-not{background:#e2e8f0;color:#475569;}
@media print{.topbar .btn,.filter,.actions-bar,.col-act,.col-sel{display:none!important;}body{background:#fff;}}
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

    <form method="GET">
    <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
    <input type="hidden" name="user_no" value="<?php echo htmlspecialchars($search_user); ?>">
    <div class="actions-bar">
        <label><input type="checkbox" id="selectAll"> Select All</label>
        <button type="submit" name="generate" value="1" class="btn btn-warning btn-sm">&#9654; Generate OT Salary</button>
        <span style="font-size:12px;opacity:.85;">(Generates &amp; saves the after-6pm / Sunday OT for the selected employees. If none selected, all listed are generated.)</span>
    </div>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th class="col-sel" style="width:34px;">&#9745;</th>
                <th>SL</th>
                <th>User No</th>
                <th class="l">Employee Name</th>
                <th>Basic</th>
                <th>After 6pm hrs</th>
                <th>After 6pm Amt</th>
                <th>Sunday OT hrs</th>
                <th>Sunday OT Amt</th>
                <th>Total Amount</th>
                <th>Status</th>
                <th class="col-act">Slip</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="12" style="color:#94a3b8;padding:24px;">No after-6pm or Sunday duty hours found for this month.</td></tr>
        <?php else: $sl = 1; foreach ($rows as $r): ?>
            <tr>
                <td class="col-sel"><input type="checkbox" class="emp-check" name="selected_users[]" value="<?php echo htmlspecialchars($r['user_no']); ?>"></td>
                <td><?php echo $sl++; ?></td>
                <td><strong><?php echo htmlspecialchars($r['user_no']); ?></strong></td>
                <td class="l"><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo a6_money($r['basic']); ?></td>
                <td><?php echo number_format($r['after6pm_hrs'], 2); ?></td>
                <td><?php echo a6_money($r['after6pm_amt']); ?></td>
                <td class="<?php echo $r['sunday_hrs'] > 0 ? 'sun' : ''; ?>"><?php echo number_format($r['sunday_hrs'], 2); ?></td>
                <td class="<?php echo $r['sunday_hrs'] > 0 ? 'sun' : ''; ?>"><?php echo a6_money($r['sunday_amt']); ?></td>
                <td><strong><?php echo a6_money($r['total']); ?></strong></td>
                <td><?php echo $r['generated'] ? '<span class="badge badge-gen">&#10003; Generated</span>' : '<span class="badge badge-not">Not Generated</span>'; ?></td>
                <td class="col-act"><a class="btn btn-primary btn-sm" href="after6pm_slip.php?user_no=<?php echo urlencode($r['user_no']); ?>&month=<?php echo urlencode($month); ?>&search_btn=1" target="_blank" rel="noopener">&#129534; Slip</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;">TOTAL</td>
                <td><?php echo number_format($tot_after6pm_hrs, 2); ?></td>
                <td><?php echo a6_money($tot_after6pm_amt); ?></td>
                <td><?php echo number_format($tot_sun_hrs, 2); ?></td>
                <td><?php echo a6_money($tot_sun_amt); ?></td>
                <td><?php echo a6_money($tot_amt); ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    </form>
</div>

<script>
document.getElementById('selectAll').addEventListener('change', function(){
    document.querySelectorAll('.emp-check').forEach(function(c){ c.checked = this.checked; }, this);
});
</script>
</body>
</html>
