<?php
/* ─────────────────────────────────────────────────────────────
   Salary Increment Report  (read-only)

   Derives salary increments from the month-wise basic_salary stored
   in `employee_salary_records`. For each employee it walks the
   salary_month rows in order and treats every change in basic_salary
   as an event (increase = increment, decrease = decrement).

   Answers:
     • how many times an employee's salary was incremented
     • an increment-wise sheet (per employee + company-wide)
     • the relation to salary (from → to, +amount, +%)

   NOTE: This only sees changes that were captured in month-wise
   salary records. Pure "running base" edits (saved without a month)
   are overwrites and leave no history — for 100% accuracy a
   salary_increments log would be added (Path 2).
   ───────────────────────────────────────────────────────────── */

include 'auth.php';
requireAnyPermission(['reports_view', 'salary_view', 'employee_view']);

function inc_h($v)     { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function inc_money($v) { return number_format((float)$v, 2); }
function inc_month_label($ym) {
    $ym = trim((string)$ym);
    if ($ym === '') return '';
    $t = strtotime($ym . '-01');
    return $t ? date('M Y', $t) : $ym;
}

$search    = trim($_GET['search'] ?? '');
$is_export = isset($_GET['export']) && $_GET['export'] === 'excel';

/* ── Load month-wise basic salary per employee ─────────────── */
$safe = mysqli_real_escape_string($conn, $search);
$searchCond = $search !== ''
    ? "AND (s.user_no LIKE '%$safe%' OR e.full_name LIKE '%$safe%' OR e.employee_id LIKE '%$safe%')"
    : '';

$emps = [];
$has_table = mysqli_query($conn, "SHOW TABLES LIKE 'employee_salary_records'");
if ($has_table && mysqli_num_rows($has_table) > 0) {
    $res = mysqli_query($conn, "
        SELECT s.user_no, s.salary_month, s.basic_salary,
               e.full_name, e.employee_id, e.department, e.designation
        FROM employee_salary_records s
        LEFT JOIN employees e ON e.user_no = s.user_no
        WHERE s.salary_month IS NOT NULL AND s.salary_month <> '' $searchCond
        ORDER BY CAST(s.user_no AS UNSIGNED), s.user_no, s.salary_month ASC
    ");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $u = $r['user_no'];
            if (!isset($emps[$u])) {
                $emps[$u] = [
                    'user_no'     => $u,
                    'name'        => $r['full_name'] ?? '',
                    'employee_id' => $r['employee_id'] ?? '',
                    'department'  => $r['department'] ?? '',
                    'designation' => $r['designation'] ?? '',
                    'rows'        => [],
                ];
            }
            $emps[$u]['rows'][] = ['month' => $r['salary_month'], 'basic' => (float)$r['basic_salary']];
        }
    }
}

/* ── Compute increment events + summary for each employee ──── */
function inc_compute($rows) {
    // rows sorted by month asc
    $events = [];
    $prev = null;
    $first = null;
    foreach ($rows as $row) {
        $b = (float)$row['basic'];
        if ($prev === null) { $prev = $b; $first = $b; continue; }
        if (abs($b - $prev) > 0.001) {
            $events[] = [
                'month' => $row['month'],
                'from'  => $prev,
                'to'    => $b,
                'diff'  => $b - $prev,
                'pct'   => $prev > 0 ? (($b - $prev) / $prev) * 100 : 0,
            ];
            $prev = $b;
        }
    }
    $current = $prev;             // last known basic
    $inc = 0; $dec = 0; $totalUp = 0; $lastIncMonth = ''; $lastIncAmt = 0;
    foreach ($events as $e) {
        if ($e['diff'] > 0) { $inc++; $totalUp += $e['diff']; $lastIncMonth = $e['month']; $lastIncAmt = $e['diff']; }
        elseif ($e['diff'] < 0) { $dec++; }
    }
    return [
        'events'        => $events,
        'first'         => (float)$first,
        'current'       => (float)$current,
        'inc_count'     => $inc,
        'dec_count'     => $dec,
        'total_up'      => $totalUp,
        'net_change'    => (float)$current - (float)$first,
        'growth_pct'    => $first > 0 ? (((float)$current - (float)$first) / $first) * 100 : 0,
        'last_inc_month'=> $lastIncMonth,
        'last_inc_amt'  => $lastIncAmt,
    ];
}

$summaries = [];
foreach ($emps as $u => $info) {
    $summaries[$u] = inc_compute($info['rows']);
}

/* ── Excel (CSV/HTML .xls) export of the summary ───────────── */
if ($is_export) {
    $company = defined('COMPANY_NAME') ? COMPANY_NAME : 'EURO TROUSERS MFG CO (FZC)';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="salary_increment_report_' . date('Ymd_His') . '.xls"');
    echo "<html><head><meta charset=\"utf-8\"></head><body>";
    echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" style=\"border-collapse:collapse;font-family:Calibri,Arial;font-size:12px;\">";
    echo "<tr><td colspan=\"10\" style=\"font-size:15px;font-weight:bold;\">" . inc_h($company) . " — Salary Increment Report</td></tr>";
    $th = "background:#1a3a5c;color:#fff;font-weight:bold;text-align:center;";
    echo "<tr>";
    foreach (['SL','User No','Employee ID','Name','Department','First Basic','Current Basic','Increments','Total Increase','Growth %'] as $h) {
        echo "<td style=\"$th\">" . inc_h($h) . "</td>";
    }
    echo "</tr>";
    $sl = 1;
    foreach ($emps as $u => $info) {
        $s = $summaries[$u];
        echo "<tr>";
        echo "<td style=\"text-align:center;\">" . ($sl++) . "</td>";
        echo "<td>" . inc_h($u) . "</td>";
        echo "<td>" . inc_h($info['employee_id']) . "</td>";
        echo "<td>" . inc_h($info['name']) . "</td>";
        echo "<td>" . inc_h($info['department']) . "</td>";
        echo "<td style=\"text-align:right;\">" . inc_money($s['first']) . "</td>";
        echo "<td style=\"text-align:right;\">" . inc_money($s['current']) . "</td>";
        echo "<td style=\"text-align:center;\">" . (int)$s['inc_count'] . "</td>";
        echo "<td style=\"text-align:right;\">" . inc_money($s['total_up']) . "</td>";
        echo "<td style=\"text-align:right;\">" . number_format($s['growth_pct'], 1) . "%</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
    exit;
}

// Detailed timeline shown when exactly one employee matches.
$detail_user = (count($emps) === 1) ? array_key_first($emps) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Salary Increment Report</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--red:#b91c1c;--red-soft:#fee2e2;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;}
.page{padding:22px;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:6px;}
.crumbs{font-size:13px;color:var(--gray-600);margin-bottom:16px;}.crumbs a{color:var(--brand-mid);text-decoration:none;}
.panel{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;}
.panel-head{background:var(--brand);color:#fff;padding:11px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;}
.panel-body{padding:16px;}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--brand-mid);color:#fff;}.btn-success{background:var(--green);color:#fff;}.btn-gray{background:var(--gray-200);color:#334155;}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px;}
.fg input{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:260px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{background:var(--brand);color:#fff;padding:10px;text-align:center;font-size:12px;text-transform:uppercase;white-space:nowrap;}
tbody td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--gray-200);}
tbody tr:nth-child(even){background:var(--gray-50);}
tbody td.l{text-align:left;}tbody td.r{text-align:right;}
.table-wrap{overflow-x:auto;}
.muted{color:#94a3b8;}
.pill{display:inline-block;padding:2px 9px;border-radius:12px;font-weight:700;font-size:12px;}
.pill.up{background:var(--green-soft);color:var(--green);}
.pill.down{background:var(--red-soft);color:var(--red);}
.note{font-size:12px;color:var(--gray-600);margin-top:10px;line-height:1.6;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="btn-back">&#8592; Dashboard</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;') : ''; ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
</div>

<div class="page">
    <div class="page-title"><span>&#128200;</span> Salary Increment Report</div>
    <div class="crumbs"><a href="dashboard.php">Dashboard</a> &rsaquo; Salary Increment Report</div>

    <div class="panel">
        <div class="panel-head">
            <span>Increment Summary<?php echo $search !== '' ? ' &middot; "' . inc_h($search) . '"' : ''; ?></span>
            <a class="btn btn-sm btn-success" href="salary_increment_report.php?export=excel<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">&#11015; Export Excel</a>
        </div>
        <div class="panel-body">
            <form method="GET" style="display:flex;gap:10px;margin-bottom:14px;" class="fg">
                <input type="text" name="search" value="<?php echo inc_h($search); ?>" placeholder="Search User No / Employee ID / Name">
                <button class="btn btn-primary" type="submit">&#128269; Search</button>
                <?php if ($search !== ''): ?><a class="btn btn-gray" href="salary_increment_report.php">Clear</a><?php endif; ?>
            </form>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SL</th><th>User No</th><th>Name</th><th>Department</th>
                        <th>First Basic</th><th>Current Basic</th><th>Increments</th>
                        <th>Total Increase</th><th>Growth %</th><th>Last Increment</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($emps)): $sl = 1; foreach ($emps as $u => $info): $s = $summaries[$u]; ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><b><?php echo inc_h($u); ?></b></td>
                        <td class="l">
                            <a href="salary_increment_report.php?search=<?php echo urlencode($u); ?>" style="color:var(--brand-mid);text-decoration:none;"><?php echo inc_h($info['name']); ?></a>
                        </td>
                        <td><?php echo inc_h($info['department']); ?></td>
                        <td class="r"><?php echo inc_money($s['first']); ?></td>
                        <td class="r"><b><?php echo inc_money($s['current']); ?></b></td>
                        <td><span class="pill <?php echo $s['inc_count'] > 0 ? 'up' : ''; ?>"><?php echo (int)$s['inc_count']; ?></span></td>
                        <td class="r" style="color:var(--green);font-weight:700;"><?php echo $s['total_up'] > 0 ? '+' . inc_money($s['total_up']) : '0.00'; ?></td>
                        <td class="r"><?php echo number_format($s['growth_pct'], 1); ?>%</td>
                        <td><?php echo $s['last_inc_month'] !== '' ? inc_h(inc_month_label($s['last_inc_month'])) . ' (+' . inc_money($s['last_inc_amt']) . ')' : '<span class="muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="muted" style="padding:20px;">No month-wise salary records found<?php echo $search !== '' ? ' for "' . inc_h($search) . '"' : ''; ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <div class="note">
                &#8505; "Increment" = a month where an employee's <b>basic salary increased</b> compared to the previous month-wise record.
                Growth % = (Current &minus; First) &divide; First. Click a name to see that employee's full increment timeline.
            </div>
        </div>
    </div>

    <?php if ($detail_user !== '' && !empty($summaries[$detail_user]['events'])):
        $info = $emps[$detail_user]; $s = $summaries[$detail_user];
    ?>
    <div class="panel">
        <div class="panel-head"><span>Increment Timeline &middot; <?php echo inc_h($info['name']); ?> (User No <?php echo inc_h($detail_user); ?>)</span></div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>Effective Month</th><th>Previous Basic</th><th>New Basic</th><th>Change</th><th>%</th><th>Type</th></tr></thead>
                <tbody>
                <?php $sl = 1; foreach ($summaries[$detail_user]['events'] as $e): $up = $e['diff'] > 0; ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo inc_h(inc_month_label($e['month'])); ?></td>
                        <td class="r"><?php echo inc_money($e['from']); ?></td>
                        <td class="r"><b><?php echo inc_money($e['to']); ?></b></td>
                        <td class="r" style="color:<?php echo $up ? 'var(--green)' : 'var(--red)'; ?>;font-weight:700;"><?php echo ($up ? '+' : '') . inc_money($e['diff']); ?></td>
                        <td class="r" style="color:<?php echo $up ? 'var(--green)' : 'var(--red)'; ?>;"><?php echo ($up ? '+' : '') . number_format($e['pct'], 1); ?>%</td>
                        <td><span class="pill <?php echo $up ? 'up' : 'down'; ?>"><?php echo $up ? 'Increment' : 'Decrement'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="note">
                Total increments: <b><?php echo (int)$s['inc_count']; ?></b>
                &nbsp;|&nbsp; First basic: <b><?php echo inc_money($s['first']); ?></b>
                &nbsp;|&nbsp; Current basic: <b><?php echo inc_money($s['current']); ?></b>
                &nbsp;|&nbsp; Overall growth: <b><?php echo number_format($s['growth_pct'], 1); ?>%</b>
            </div>
        </div>
    </div>
    <?php elseif ($detail_user !== ''): ?>
    <div class="panel"><div class="panel-body muted">No salary change recorded for <b><?php echo inc_h($emps[$detail_user]['name']); ?></b> — basic salary has stayed the same across the available month-wise records.</div></div>
    <?php endif; ?>

</div>
</body>
</html>
