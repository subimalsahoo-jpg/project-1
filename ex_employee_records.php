<?php
/* ─────────────────────────────────────────────
   Ex-Employee Records (archive)

   Full historical record of employees who have LEFT — i.e. whose visa
   cancellation Status is 'Completed'. Shows everything ever entered under
   their name (profile, biometric, accommodation room, salary history, air
   tickets, visa renewals, cancellation details) and allows an Excel download.
───────────────────────────────────────────── */
include 'auth.php';
requireAnyPermission(['employee_view', 'reports_view']);
include_once 'accommodation_helper.php';
include_once 'visa_cancellation_helper.php';
acc_ensure_schema($conn);
if (function_exists('vc_ensure_schema')) { vc_ensure_schema($conn); }

function ex_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ex_money($v) { return number_format((float)$v, 2); }
function ex_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-M-Y', $t) : $v;
}
function ex_val($row, $keys, $d = '') {
    foreach ((array)$keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return $row[$k];
    }
    return $d;
}

/* Departed employees = visa cancellation Completed (latest per user). */
function ex_departed_list($conn, $search = '', $gender = '') {
    $rows = [];
    $vt = mysqli_query($conn, "SHOW TABLES LIKE 'visa_cancellations'");
    if (!$vt || mysqli_num_rows($vt) === 0) return $rows;
    $s = mysqli_real_escape_string($conn, $search);
    $cond = $search !== '' ? "AND (vc.user_no LIKE '%$s%' OR e.full_name LIKE '%$s%')" : '';
    if ($gender !== '') {
        $g = mysqli_real_escape_string($conn, $gender);
        $cond .= " AND e.gender = '$g'";
    }
    $q = mysqli_query($conn, "
        SELECT vc.user_no,
               MAX(vc.cancellation_reason) AS cancellation_reason,
               MAX(vc.last_working_date) AS last_working_date,
               e.full_name, e.employee_id, e.department, e.designation
        FROM visa_cancellations vc
        LEFT JOIN employees e ON e.user_no = vc.user_no
        WHERE vc.cancellation_status='Completed' $cond
        GROUP BY vc.user_no, e.full_name, e.employee_id, e.department, e.designation
        ORDER BY (last_working_date IS NULL OR last_working_date = '' OR last_working_date = '0000-00-00') ASC,
                 last_working_date DESC,
                 CAST(vc.user_no AS UNSIGNED)
    ");
    if ($q) { while ($r = mysqli_fetch_assoc($q)) { $rows[] = $r; } }
    return $rows;
}

function ex_employee($conn, $user_no) {
    $u = mysqli_real_escape_string($conn, $user_no);
    $r = mysqli_query($conn, "SELECT * FROM employees WHERE user_no='$u' LIMIT 1");
    return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
}
function ex_cancellation($conn, $user_no) {
    $vt = mysqli_query($conn, "SHOW TABLES LIKE 'visa_cancellations'");
    if (!$vt || mysqli_num_rows($vt) === 0) return null;
    $u = mysqli_real_escape_string($conn, $user_no);
    $r = mysqli_query($conn, "SELECT * FROM visa_cancellations WHERE user_no='$u' ORDER BY id DESC LIMIT 1");
    return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
}
function ex_salary_rows($conn, $user_no) {
    $u = mysqli_real_escape_string($conn, $user_no);
    $rows = [];
    $r = mysqli_query($conn, "SELECT * FROM employee_salary_records WHERE user_no='$u' ORDER BY salary_month");
    if ($r) { while ($x = mysqli_fetch_assoc($r)) { $rows[] = $x; } }
    return $rows;
}
function ex_table_rows($conn, $table, $user_no, $order = 'id') {
    $t = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$t || mysqli_num_rows($t) === 0) return [];
    $u = mysqli_real_escape_string($conn, $user_no);
    $rows = [];
    $r = mysqli_query($conn, "SELECT * FROM `$table` WHERE user_no='$u' ORDER BY $order");
    if ($r) { while ($x = mysqli_fetch_assoc($r)) { $rows[] = $x; } }
    return $rows;
}

/* Profile fields to show (label => possible column names). */
$PROFILE_FIELDS = [
    'User No' => ['user_no'],
    'Employee ID' => ['employee_id'],
    'Full Name' => ['full_name', 'name'],
    'Bio-Metric / Card No' => ['card_no', 'bio_met_no'],
    'Gender' => ['gender'],
    'Date of Birth' => ['birthday', 'dob'],
    'Nationality' => ['nationality'],
    'Marital Status' => ['marital_status'],
    'Department' => ['department'],
    'Designation' => ['designation'],
    'Phone (UAE)' => ['phone'],
    'Phone (Home)' => ['home_phone', 'phone_home'],
    'Address' => ['address'],
    'Passport Number' => ['passport', 'passport_number'],
    'Passport Issue' => ['passport_issue', 'passport_issuing'],
    'Passport Expiry' => ['passport_expiry', 'passport_expire_date'],
    'Emirates ID' => ['emirates_id_number'],
    'SAIF Zone ID' => ['saif_zone_id'],
    'UID Number' => ['uid_number'],
    'Visa Issue' => ['visa_issuing_date'],
    'Visa Expiry' => ['visa_expiry_date'],
    'Insurance Number' => ['insurance_number'],
    'Insurance Expiry' => ['insurance_expiry_date'],
    'Joining Date' => ['joining_date'],
    'Resign Date' => ['resign_date'],
    'Email' => ['email'],
    'Previous Company' => ['previous_company'],
    'Employee Status' => ['employee_status', 'status'],
];
$DATE_LABELS = ['Date of Birth','Passport Issue','Passport Expiry','Visa Issue','Visa Expiry','Insurance Expiry','Joining Date','Resign Date'];

$view_user = trim($_GET['user_no'] ?? '');
$emp = $view_user !== '' ? ex_employee($conn, $view_user) : null;

/* ─────────────────────────────────────────────
   Excel export of one ex-employee's full record
───────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $emp) {
    $cancel  = ex_cancellation($conn, $view_user);
    $room    = acc_last_room($conn, $view_user);
    $salary  = ex_salary_rows($conn, $view_user);
    $tickets = ex_table_rows($conn, 'employee_airtickets', $view_user, 'id');
    $renews  = ex_table_rows($conn, 'employee_visa_renewals', $view_user, 'id');

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="ex_employee_' . preg_replace('/[^A-Za-z0-9_]/', '', $view_user) . '_' . date('Ymd_His') . '.xls"');
    echo "<html><head><meta charset=\"utf-8\"></head><body>";
    echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" style=\"border-collapse:collapse;font-family:Calibri,Arial;font-size:12px;\">";
    $hd = "background:#1a3a5c;color:#fff;font-weight:bold;";
    echo "<tr><td colspan=\"4\" style=\"font-size:15px;font-weight:bold;\">Ex-Employee Full Record — " . ex_h(ex_val($emp, ['full_name','name'])) . " (User No " . ex_h($view_user) . ")</td></tr>";

    echo "<tr><td colspan=\"4\" style=\"$hd\">Profile</td></tr>";
    foreach ($PROFILE_FIELDS as $label => $keys) {
        $v = ex_val($emp, $keys);
        if (in_array($label, $DATE_LABELS, true)) { $v = ex_dmy($v); }
        echo "<tr><td style=\"font-weight:bold;background:#f1f5f9;\">" . ex_h($label) . "</td><td colspan=\"3\">" . ex_h($v) . "</td></tr>";
    }

    echo "<tr><td colspan=\"4\" style=\"$hd\">Accommodation (last room)</td></tr>";
    if ($room) {
        echo "<tr><td style=\"font-weight:bold;background:#f1f5f9;\">Location</td><td>" . ex_h($room['main_location']) . "</td><td style=\"font-weight:bold;background:#f1f5f9;\">Tower/Block</td><td>" . ex_h($room['tower_block']) . "</td></tr>";
        echo "<tr><td style=\"font-weight:bold;background:#f1f5f9;\">Room Number</td><td>" . ex_h($room['room_number']) . "</td><td style=\"font-weight:bold;background:#f1f5f9;\">Room For</td><td>" . ex_h($room['room_for']) . "</td></tr>";
    } else {
        echo "<tr><td colspan=\"4\">No accommodation record.</td></tr>";
    }

    echo "<tr><td colspan=\"4\" style=\"$hd\">Visa Cancellation</td></tr>";
    if ($cancel) {
        echo "<tr><td style=\"font-weight:bold;background:#f1f5f9;\">Reason</td><td>" . ex_h(ex_val($cancel, ['cancellation_reason'])) . "</td><td style=\"font-weight:bold;background:#f1f5f9;\">Status</td><td>" . ex_h(ex_val($cancel, ['cancellation_status'])) . "</td></tr>";
        echo "<tr><td style=\"font-weight:bold;background:#f1f5f9;\">Last Working Date</td><td>" . ex_h(ex_dmy(ex_val($cancel, ['last_working_date']))) . "</td><td style=\"font-weight:bold;background:#f1f5f9;\">Final Settlement</td><td>" . ex_h(ex_val($cancel, ['final_settlement_amount','final_settlement'])) . "</td></tr>";
        echo "<tr><td style=\"font-weight:bold;background:#f1f5f9;\">Gratuity</td><td>" . ex_h(ex_val($cancel, ['gratuity_amount'])) . "</td><td style=\"font-weight:bold;background:#f1f5f9;\">Cancellation Date</td><td>" . ex_h(ex_dmy(ex_val($cancel, ['visa_cancellation_date','cancellation_date']))) . "</td></tr>";
    } else {
        echo "<tr><td colspan=\"4\">No cancellation record.</td></tr>";
    }

    echo "<tr><td colspan=\"4\" style=\"$hd\">Salary History</td></tr>";
    echo "<tr style=\"background:#e2e8f0;font-weight:bold;\"><td>Month</td><td>Gross</td><td>Deduction</td><td>Net</td></tr>";
    $tnet = 0;
    foreach ($salary as $s) {
        $net = (float) ex_val($s, ['net_payable','net_salary'], 0);
        $tnet += $net;
        $mlabel = ex_val($s, ['salary_month']);
        $mlabel = $mlabel !== '' ? date('M Y', strtotime($mlabel . '-01')) : '';
        echo "<tr><td>" . ex_h($mlabel) . "</td><td>" . ex_h(ex_val($s, ['gross_total','gross_salary'], 0)) . "</td><td>" . ex_h(ex_val($s, ['total_deduction'], 0)) . "</td><td>" . ex_h($net) . "</td></tr>";
    }
    echo "<tr style=\"font-weight:bold;\"><td colspan=\"3\">Total Net Paid</td><td>" . ex_h(number_format($tnet, 2, '.', '')) . "</td></tr>";

    if (!empty($tickets)) {
        echo "<tr><td colspan=\"4\" style=\"$hd\">Air Tickets</td></tr>";
        echo "<tr style=\"background:#e2e8f0;font-weight:bold;\"><td>From → To</td><td>Travel</td><td>Provided By</td><td>Amount</td></tr>";
        foreach ($tickets as $t) {
            echo "<tr><td>" . ex_h(ex_val($t, ['from_location']) . ' → ' . ex_val($t, ['to_location'])) . "</td><td>" . ex_h(ex_dmy(ex_val($t, ['travel_date']))) . "</td><td>" . ex_h(ex_val($t, ['provided_by'])) . "</td><td>" . ex_h(ex_val($t, ['amount'], 0)) . "</td></tr>";
        }
    }
    if (!empty($renews)) {
        echo "<tr><td colspan=\"4\" style=\"$hd\">Visa Renewals</td></tr>";
        echo "<tr style=\"background:#e2e8f0;font-weight:bold;\"><td>From</td><td>To</td><td>Cost</td><td>Remarks</td></tr>";
        foreach ($renews as $v) {
            echo "<tr><td>" . ex_h(ex_dmy(ex_val($v, ['renew_from']))) . "</td><td>" . ex_h(ex_dmy(ex_val($v, ['renew_to']))) . "</td><td>" . ex_h(ex_val($v, ['cost'], 0)) . "</td><td>" . ex_h(ex_val($v, ['remarks'])) . "</td></tr>";
        }
    }
    echo "</table></body></html>";
    exit;
}

$search = trim($_GET['search'] ?? '');
$gender = trim($_GET['gender'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ex-Employee Records</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
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
.btn:hover{opacity:.93;}
.fg input{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:260px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{background:var(--brand);color:#fff;padding:10px;text-align:center;font-size:12px;text-transform:uppercase;white-space:nowrap;}
tbody td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--gray-200);}
tbody tr:nth-child(even){background:var(--gray-50);}
tbody td.l{text-align:left;}
.table-wrap{overflow-x:auto;}
.muted{color:#94a3b8;}
.kv{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 24px;}
.kv .k{font-size:11px;color:var(--gray-600);text-transform:uppercase;}
.kv .v{font-size:14px;font-weight:600;color:var(--brand);border-bottom:1px solid var(--gray-100);padding-bottom:6px;}
@media(max-width:760px){.kv{grid-template-columns:repeat(2,1fr);}}
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
    <div class="page-title"><span>&#128188;</span> Ex-Employee Records</div>
    <div class="crumbs"><a href="ex_employee_records.php">All Ex-Employees</a><?php if ($emp): ?> &rsaquo; <?php echo ex_h(ex_val($emp, ['full_name','name'])); ?><?php endif; ?></div>

<?php if (!$emp): /* ── List ── */
    $list = ex_departed_list($conn, $search, $gender);
?>
    <div class="panel">
        <div class="panel-head">Resigned / Left Employees (Visa Cancellation Completed)</div>
        <div class="panel-body">
            <form method="GET" style="display:flex;gap:10px;margin-bottom:14px;align-items:center;" class="fg">
                <input type="text" name="search" value="<?php echo ex_h($search); ?>" placeholder="Search User No / Name">
                <select name="gender" style="padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;">
                    <option value="">All Genders</option>
                    <option value="Male"    <?php echo $gender === 'Male'    ? 'selected' : ''; ?>>Male</option>
                    <option value="Female"  <?php echo $gender === 'Female'  ? 'selected' : ''; ?>>Female</option>
                    <option value="Shemale" <?php echo $gender === 'Shemale' ? 'selected' : ''; ?>>Shemale</option>
                </select>
                <button class="btn btn-primary" type="submit">&#128269; Search</button>
                <?php if ($search !== '' || $gender !== ''): ?><a class="btn btn-gray" href="ex_employee_records.php">Clear</a><?php endif; ?>
            </form>
            <div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>User No</th><th>Employee ID</th><th>Name</th><th>Department</th><th>Designation</th><th>Reason</th><th>Last Working</th><th>Record</th></tr></thead>
                <tbody>
                <?php if (!empty($list)): $sl = 1; foreach ($list as $r): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><b><?php echo ex_h($r['user_no']); ?></b></td>
                        <td><?php echo ex_h($r['employee_id'] ?? ''); ?></td>
                        <td class="l"><?php echo ex_h($r['full_name'] ?? ''); ?></td>
                        <td><?php echo ex_h($r['department'] ?? ''); ?></td>
                        <td><?php echo ex_h($r['designation'] ?? ''); ?></td>
                        <td><?php echo ex_h($r['cancellation_reason'] ?? ''); ?></td>
                        <td><?php echo ex_h(ex_dmy($r['last_working_date'] ?? '')); ?></td>
                        <td><a class="btn btn-sm btn-primary" href="ex_employee_records.php?user_no=<?php echo urlencode($r['user_no']); ?>">View</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="muted" style="padding:20px;">No ex-employees yet (none with a Completed visa cancellation).</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

<?php else: /* ── Detail ── */
    $cancel  = ex_cancellation($conn, $view_user);
    $room    = acc_last_room($conn, $view_user);
    $salary  = ex_salary_rows($conn, $view_user);
    $tickets = ex_table_rows($conn, 'employee_airtickets', $view_user, 'id');
    $renews  = ex_table_rows($conn, 'employee_visa_renewals', $view_user, 'id');
    $tnet = 0; foreach ($salary as $s) { $tnet += (float) ex_val($s, ['net_payable','net_salary'], 0); }
?>
    <div style="display:flex;gap:10px;margin-bottom:14px;">
        <a class="btn btn-gray" href="ex_employee_records.php">&#8592; Back to list</a>
        <a class="btn btn-success" href="ex_employee_records.php?user_no=<?php echo urlencode($view_user); ?>&export=excel">&#11015; Download Full Record (Excel)</a>
    </div>

    <div class="panel">
        <div class="panel-head">Profile &amp; Documents</div>
        <div class="panel-body">
            <div class="kv">
                <?php foreach ($PROFILE_FIELDS as $label => $keys):
                    $v = ex_val($emp, $keys);
                    if (in_array($label, $DATE_LABELS, true)) { $v = ex_dmy($v); }
                ?>
                <div><div class="k"><?php echo ex_h($label); ?></div><div class="v"><?php echo $v !== '' ? ex_h($v) : '<span class="muted">-</span>'; ?></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">Accommodation (last room)</div>
        <div class="panel-body">
            <?php if ($room): ?>
            <div class="kv">
                <div><div class="k">Main Location</div><div class="v"><?php echo ex_h($room['main_location']); ?></div></div>
                <div><div class="k">Tower / Block</div><div class="v"><?php echo ex_h($room['tower_block'] ?: '-'); ?></div></div>
                <div><div class="k">Room Number</div><div class="v"><?php echo ex_h($room['room_number'] ?: '-'); ?></div></div>
                <div><div class="k">Room For</div><div class="v"><?php echo ex_h($room['room_for'] ?: '-'); ?></div></div>
            </div>
            <?php else: ?><p class="muted">No accommodation record.</p><?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">Visa Cancellation</div>
        <div class="panel-body">
            <?php if ($cancel): ?>
            <div class="kv">
                <div><div class="k">Reason</div><div class="v"><?php echo ex_h(ex_val($cancel, ['cancellation_reason'])); ?></div></div>
                <div><div class="k">Status</div><div class="v"><?php echo ex_h(ex_val($cancel, ['cancellation_status'])); ?></div></div>
                <div><div class="k">Last Working Date</div><div class="v"><?php echo ex_h(ex_dmy(ex_val($cancel, ['last_working_date']))); ?></div></div>
                <div><div class="k">Gratuity</div><div class="v"><?php echo ex_h(ex_val($cancel, ['gratuity_amount'], '-')); ?></div></div>
                <div><div class="k">Final Settlement</div><div class="v"><?php echo ex_h(ex_val($cancel, ['final_settlement_amount','final_settlement'], '-')); ?></div></div>
                <div><div class="k">Cancellation Date</div><div class="v"><?php echo ex_h(ex_dmy(ex_val($cancel, ['visa_cancellation_date','cancellation_date']))); ?></div></div>
            </div>
            <?php else: ?><p class="muted">No cancellation record.</p><?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span>Salary History</span><span style="font-weight:700;">Total Net Paid: <?php echo ex_money($tnet); ?> AED</span></div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>Month</th><th>Gross</th><th>Deduction</th><th>Net Payable</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!empty($salary)): $sl = 1; foreach ($salary as $s):
                    $m = ex_val($s, ['salary_month']);
                ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo $m !== '' ? ex_h(date('M Y', strtotime($m . '-01'))) : '-'; ?></td>
                        <td><?php echo ex_money(ex_val($s, ['gross_total','gross_salary'], 0)); ?></td>
                        <td><?php echo ex_money(ex_val($s, ['total_deduction'], 0)); ?></td>
                        <td><b><?php echo ex_money(ex_val($s, ['net_payable','net_salary'], 0)); ?></b></td>
                        <td><?php echo ex_h(ex_val($s, ['salary_status'], '')); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="muted" style="padding:16px;">No salary records.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <?php if (!empty($tickets)): ?>
    <div class="panel">
        <div class="panel-head">Air Tickets</div>
        <div class="panel-body"><div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>Provided By</th><th>From → To</th><th>Travel</th><th>Return</th><th>Amount</th></tr></thead>
                <tbody>
                <?php $sl = 1; foreach ($tickets as $t): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo ex_h(ex_val($t, ['provided_by'])); ?></td>
                        <td class="l"><?php echo ex_h(ex_val($t, ['from_location']) . ' → ' . ex_val($t, ['to_location'])); ?></td>
                        <td><?php echo ex_h(ex_dmy(ex_val($t, ['travel_date']))); ?></td>
                        <td><?php echo ex_h(ex_dmy(ex_val($t, ['return_date']))); ?></td>
                        <td><?php echo ex_money(ex_val($t, ['amount'], 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($renews)): ?>
    <div class="panel">
        <div class="panel-head">Visa Renewals</div>
        <div class="panel-body"><div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>From</th><th>To</th><th>Cost</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php $sl = 1; foreach ($renews as $v): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo ex_h(ex_dmy(ex_val($v, ['renew_from']))); ?></td>
                        <td><?php echo ex_h(ex_dmy(ex_val($v, ['renew_to']))); ?></td>
                        <td><?php echo ex_money(ex_val($v, ['cost'], 0)); ?></td>
                        <td class="l"><?php echo ex_h(ex_val($v, ['remarks'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
