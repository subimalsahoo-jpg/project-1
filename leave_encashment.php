<?php
include 'auth.php';
requireAnyPermission(['vacation_manage', 'leave_encashment_manage']);
include_once 'vacation_helper.php';
include_once 'leave_encashment_helper.php';

le_ensure_schema($conn);

function le_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function le_money($v) { return number_format((float)$v, 2); }
function le_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-M-Y', $t) : $v;
}

$logged_in_name = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User')));
$flash = '';
$flash_type = 'ok';

/* ── Resolve an employee (for the form) by user_no / id / name ── */
function le_find_employee($conn, $term) {
    $t = le_esc($conn, $term);
    if ($t === '') return null;
    $r = mysqli_query($conn, "
        SELECT * FROM employees
        WHERE user_no='$t' OR employee_id='$t' OR full_name LIKE '%$t%'
        ORDER BY (user_no='$t') DESC, (employee_id='$t') DESC
        LIMIT 1
    ");
    return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
}

/* ─────────────────────────────────────────────
   POST: save (insert/update) or delete
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        if ($del_id > 0) {
            mysqli_query($conn, "DELETE FROM leave_encashment WHERE id=$del_id");
            $flash = 'Encashment record deleted.';
        }
    } elseif ($action === 'save') {
        $id            = (int)($_POST['id'] ?? 0);
        $user_no       = trim($_POST['user_no'] ?? '');
        $employee_id   = trim($_POST['employee_id'] ?? '');
        $employee_name = trim($_POST['employee_name'] ?? '');
        $basic_salary  = (float)($_POST['basic_salary'] ?? 0);
        $balance_days  = (float)($_POST['leave_balance_days'] ?? 0);
        $encash_days   = (float)($_POST['encash_days'] ?? 0);
        $auto_calc     = ($_POST['auto_calculate'] ?? 'Yes') === 'Yes' ? 'Yes' : 'No';
        $enc_month     = normalize_input_month($_POST['encashment_month'] ?? '', date('Y-m'));
        $status        = in_array($_POST['status'] ?? 'Pending', ['Pending', 'Paid'], true) ? $_POST['status'] : 'Pending';
        $remarks       = trim($_POST['remarks'] ?? '');

        $daily_wage    = le_daily_wage($basic_salary);
        // When Auto Calculate = Yes, always (re)derive the amount from days.
        $encash_amount = $auto_calc === 'Yes'
            ? le_calc_amount($basic_salary, $encash_days)
            : (float)($_POST['encash_amount'] ?? 0);

        if ($user_no === '') {
            $flash = 'Please search and select an employee first.';
            $flash_type = 'err';
        } elseif ($encash_days <= 0) {
            $flash = 'Encash Days must be greater than 0.';
            $flash_type = 'err';
        } else {
            $u  = le_esc($conn, $user_no);
            $ei = le_esc($conn, $employee_id);
            $en = le_esc($conn, $employee_name);
            $rk = le_esc($conn, $remarks);
            $cb = le_esc($conn, $logged_in_name);
            $em = le_esc($conn, $enc_month);
            $st = le_esc($conn, $status);
            $ac = le_esc($conn, $auto_calc);

            if ($id > 0) {
                mysqli_query($conn, "
                    UPDATE leave_encashment SET
                        user_no='$u', employee_id='$ei', employee_name='$en',
                        basic_salary='$basic_salary', daily_wage='$daily_wage',
                        leave_balance_days='$balance_days', encash_days='$encash_days',
                        encash_amount='$encash_amount', encashment_month='$em',
                        auto_calculate='$ac', status='$st', remarks='$rk'
                    WHERE id=$id
                ");
                $flash = 'Leave encashment updated.';
            } else {
                mysqli_query($conn, "
                    INSERT INTO leave_encashment
                        (user_no, employee_id, employee_name, basic_salary, daily_wage,
                         leave_balance_days, encash_days, encash_amount, encashment_month,
                         auto_calculate, status, remarks, created_by)
                    VALUES
                        ('$u','$ei','$en','$basic_salary','$daily_wage',
                         '$balance_days','$encash_days','$encash_amount','$em',
                         '$ac','$st','$rk','$cb')
                ");
                $flash = 'Leave encashment saved for ' . le_h($employee_name) . '.';
            }
        }
    }
}

/* ─────────────────────────────────────────────
   Form context: a searched employee (+ computed balance)
───────────────────────────────────────────── */
$search_term  = trim($_GET['emp'] ?? '');
$edit_id      = (int)($_GET['edit'] ?? 0);
$form_emp     = null;
$form_record  = null;
$bal          = null;

if ($edit_id > 0) {
    $er = mysqli_query($conn, "SELECT * FROM leave_encashment WHERE id=$edit_id LIMIT 1");
    if ($er && mysqli_num_rows($er) > 0) {
        $form_record = mysqli_fetch_assoc($er);
        $search_term = $form_record['user_no'];
    }
}
if ($search_term !== '') {
    $form_emp = le_find_employee($conn, $search_term);
    if ($form_emp) {
        $bal = le_leave_balance($conn, $form_emp['user_no'], $form_emp);
    } else {
        $flash = $flash !== '' ? $flash : 'No employee found for "' . le_h($search_term) . '".';
        if ($flash_type !== 'err') { $flash_type = 'err'; }
    }
}

$form_basic = 0.0;
if ($form_emp) {
    $form_basic = (float) gratuity_current_basic($conn, $form_emp['user_no'], $form_emp, date('Y-m'));
}
$form_daily = le_daily_wage($form_basic);

/* ─────────────────────────────────────────────
   List filters
───────────────────────────────────────────── */
$f_month  = trim($_GET['f_month'] ?? '');
$f_status = trim($_GET['f_status'] ?? '');
$where = "1=1";
if ($f_month !== '')  { $where .= " AND encashment_month='" . le_esc($conn, $f_month) . "'"; }
if ($f_status !== '') { $where .= " AND status='" . le_esc($conn, $f_status) . "'"; }

/* Summary */
$sum = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS cnt,
           COALESCE(SUM(encash_days),0) AS days,
           COALESCE(SUM(encash_amount),0) AS amt,
           COALESCE(SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END),0) AS pending
    FROM leave_encashment WHERE $where
")) ?: ['cnt' => 0, 'days' => 0, 'amt' => 0, 'pending' => 0];

/* ─────────────────────────────────────────────
   CSV export (respects filters)
───────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave_encashment_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Leave Encashment Report']);
    fputcsv($out, ['Generated', date('d-M-Y H:i')]);
    fputcsv($out, ['Total Records', (int)$sum['cnt']]);
    fputcsv($out, ['Total Encash Days', (float)$sum['days']]);
    fputcsv($out, ['Total Amount (AED)', number_format((float)$sum['amt'], 2, '.', '')]);
    fputcsv($out, ['Pending', (int)$sum['pending']]);
    fputcsv($out, []);
    fputcsv($out, ['User No', 'Employee ID', 'Name', 'Basic (AED)', 'Daily Wage (AED)',
                   'Leave Balance (Days)', 'Encash Days', 'Encash Amount (AED)',
                   'Encashment Month', 'Auto Calculate', 'Status', 'Remarks', 'Created By', 'Created']);
    $rx = mysqli_query($conn, "SELECT * FROM leave_encashment WHERE $where ORDER BY created_at DESC, id DESC");
    if ($rx) {
        while ($r = mysqli_fetch_assoc($rx)) {
            fputcsv($out, [
                $r['user_no'], $r['employee_id'], $r['employee_name'],
                number_format((float)$r['basic_salary'], 2, '.', ''),
                number_format((float)$r['daily_wage'], 2, '.', ''),
                (float)$r['leave_balance_days'], (float)$r['encash_days'],
                number_format((float)$r['encash_amount'], 2, '.', ''),
                $r['encashment_month'], $r['auto_calculate'], $r['status'],
                $r['remarks'], $r['created_by'],
                $r['created_at'] ? date('d-M-Y H:i', strtotime($r['created_at'])) : '',
            ]);
        }
    }
    fclose($out);
    exit;
}

$list = mysqli_query($conn, "SELECT * FROM leave_encashment WHERE $where ORDER BY created_at DESC, id DESC");

/* Preset values for the form */
$v_id       = $form_record['id'] ?? 0;
$v_balance  = $form_record['leave_balance_days'] ?? ($bal['balance'] ?? 0);
$v_days     = $form_record['encash_days'] ?? ($bal['balance'] ?? 0);
$v_auto     = $form_record['auto_calculate'] ?? 'Yes';
$v_month    = $form_record['encashment_month'] ?? date('Y-m');
$v_status   = $form_record['status'] ?? 'Pending';
$v_remarks  = $form_record['remarks'] ?? '';
$v_amount   = $form_record['encash_amount'] ?? le_calc_amount($form_basic, $v_days);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Encashment</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--amber:#d97706;--amber-soft:#fef3c7;--red:#b91c1c;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;}
.btn-back:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px;}
.card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:14px 16px;}
.card .label{font-size:12px;color:var(--gray-600);text-transform:uppercase;letter-spacing:.03em;}
.card .value{font-size:24px;font-weight:800;color:var(--brand);margin-top:4px;}
.card.amt .value{color:var(--green);}
.card.pend .value{color:var(--amber);}
.panel{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;}
.panel-head{background:var(--brand);color:#fff;padding:11px 16px;font-weight:600;font-size:14px;}
.panel-body{padding:16px;}
.row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fg .hint{font-size:11px;color:#94a3b8;}
.fg input,.fg select{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:150px;}
.fg input[readonly]{background:#f1f5f9;color:#475569;}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--brand-mid);color:#fff;}
.btn-success{background:var(--green);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px;}
.btn-danger{background:#fee2e2;color:var(--red);}
.btn:hover{opacity:.93;}
.flash{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600;}
.flash.ok{background:var(--green-soft);color:var(--green);border:1px solid #b6e3c9;}
.flash.err{background:#fdecea;color:var(--red);border:1px solid #f5c6c0;}
.emp-info{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;background:var(--gray-50);border:1px dashed var(--gray-200);border-radius:8px;padding:12px 14px;margin-bottom:14px;}
.emp-info div span{display:block;font-size:11px;color:var(--gray-600);}
.emp-info div b{font-size:15px;color:var(--brand);}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{background:var(--brand);color:#fff;padding:10px;text-align:center;font-size:12px;text-transform:uppercase;white-space:nowrap;}
tbody td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--gray-200);}
tbody tr:nth-child(even){background:var(--gray-50);}
tbody td.l{text-align:left;}
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;}
.badge.paid{background:var(--green-soft);color:var(--green);}
.badge.pending{background:var(--amber-soft);color:var(--amber);}
.table-wrap{overflow-x:auto;}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:space-between;margin-bottom:12px;}
.muted{color:#94a3b8;}
@media(max-width:900px){.cards,.emp-info{grid-template-columns:repeat(2,1fr);}}
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
    <div>
        <a class="btn-back" href="?export=csv<?php echo $f_month !== '' ? '&f_month=' . le_h($f_month) : ''; ?><?php echo $f_status !== '' ? '&f_status=' . le_h($f_status) : ''; ?>">&#11015; Export Excel</a>
    </div>
</div>

<div class="page">
    <div class="page-title"><span>&#128181;</span> Leave Encashment</div>

    <?php if ($flash !== ''): ?>
        <div class="flash <?php echo $flash_type; ?>"><?php echo le_h($flash); ?></div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="cards">
        <div class="card"><div class="label">Total Encashments</div><div class="value"><?php echo (int)$sum['cnt']; ?></div></div>
        <div class="card"><div class="label">Total Encash Days</div><div class="value"><?php echo rtrim(rtrim(number_format((float)$sum['days'], 1), '0'), '.'); ?></div></div>
        <div class="card amt"><div class="label">Total Amount (AED)</div><div class="value"><?php echo le_money($sum['amt']); ?></div></div>
        <div class="card pend"><div class="label">Pending</div><div class="value"><?php echo (int)$sum['pending']; ?></div></div>
    </div>

    <!-- Find employee -->
    <div class="panel">
        <div class="panel-head">1. Find Employee</div>
        <div class="panel-body">
            <form method="GET" class="row">
                <div class="fg" style="flex:1;">
                    <label>User No / Employee ID / Name</label>
                    <input type="text" name="emp" value="<?php echo le_h($search_term); ?>" placeholder="e.g. 1604 or name" style="min-width:280px;">
                </div>
                <button class="btn btn-primary" type="submit">&#128269; Search</button>
                <a class="btn btn-gray" href="leave_encashment.php">Clear</a>
            </form>
        </div>
    </div>

    <!-- Record encashment -->
    <div class="panel">
        <div class="panel-head"><?php echo $v_id ? '2. Edit Encashment' : '2. Record Encashment'; ?></div>
        <div class="panel-body">
            <?php if ($form_emp): ?>
            <div class="emp-info">
                <div><span>Employee</span><b><?php echo le_h(gratuity_pick($form_emp, ['full_name','name'], '')); ?></b></div>
                <div><span>User No</span><b><?php echo le_h($form_emp['user_no']); ?></b></div>
                <div><span>Basic Salary</span><b><?php echo le_money($form_basic); ?> AED</b></div>
                <div><span>Daily Wage (Basic&divide;30)</span><b><?php echo le_money($form_daily); ?> AED</b></div>
                <?php if ($bal): ?>
                <div><span>Accrued (days)</span><b><?php echo $bal['accrued']; ?></b></div>
                <div><span>Paid Leave Taken</span><b><?php echo $bal['taken']; ?></b></div>
                <div><span>Already Encashed</span><b><?php echo $bal['encashed']; ?></b></div>
                <div><span>Suggested Balance</span><b style="color:var(--green);"><?php echo $bal['balance']; ?> days</b></div>
                <?php endif; ?>
            </div>

            <form method="POST" id="encashForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int)$v_id; ?>">
                <input type="hidden" name="user_no" value="<?php echo le_h($form_emp['user_no']); ?>">
                <input type="hidden" name="employee_id" value="<?php echo le_h(gratuity_pick($form_emp, ['employee_id'], $form_emp['user_no'])); ?>">
                <input type="hidden" name="employee_name" value="<?php echo le_h(gratuity_pick($form_emp, ['full_name','name'], '')); ?>">
                <input type="hidden" name="basic_salary" value="<?php echo le_h($form_basic); ?>">
                <input type="hidden" id="dailyWage" value="<?php echo le_h($form_daily); ?>">

                <div class="row">
                    <div class="fg">
                        <label>Leave Balance (Days)</label>
                        <input type="number" step="0.5" name="leave_balance_days" value="<?php echo le_h($v_balance); ?>">
                        <span class="hint">Suggested from accrual; editable.</span>
                    </div>
                    <div class="fg">
                        <label>Leave Encash Days</label>
                        <input type="number" step="0.5" min="0" name="encash_days" id="encashDays" value="<?php echo le_h($v_days); ?>" oninput="leRecalc()">
                    </div>
                    <div class="fg">
                        <label>Auto Calculate</label>
                        <select name="auto_calculate" id="autoCalc" onchange="leRecalc()">
                            <option value="Yes" <?php echo $v_auto === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                            <option value="No" <?php echo $v_auto === 'No' ? 'selected' : ''; ?>>No</option>
                        </select>
                        <span class="hint">Yes = amount from Days &times; Daily Wage.</span>
                    </div>
                    <div class="fg">
                        <label>Leave Encash Amount (AED)</label>
                        <input type="number" step="0.01" min="0" name="encash_amount" id="encashAmount" value="<?php echo le_h($v_amount); ?>">
                    </div>
                </div>

                <div class="row" style="margin-top:14px;">
                    <div class="fg">
                        <label>Encashment Month</label>
                        <input type="month" name="encashment_month" value="<?php echo le_h($v_month); ?>">
                    </div>
                    <div class="fg">
                        <label>Status</label>
                        <select name="status">
                            <option value="Pending" <?php echo $v_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Paid" <?php echo $v_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    <div class="fg" style="flex:1;">
                        <label>Remarks</label>
                        <input type="text" name="remarks" value="<?php echo le_h($v_remarks); ?>" placeholder="optional" style="width:100%;">
                    </div>
                </div>

                <div style="margin-top:16px;display:flex;gap:10px;">
                    <button class="btn btn-success" type="submit">&#10003; <?php echo $v_id ? 'Update' : 'Save'; ?> Encashment</button>
                    <?php if ($v_id): ?><a class="btn btn-gray" href="leave_encashment.php">Cancel Edit</a><?php endif; ?>
                </div>
            </form>
            <?php else: ?>
            <p class="muted">Search an employee above to record a leave encashment.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Records -->
    <div class="panel">
        <div class="panel-head">3. Encashment Records</div>
        <div class="panel-body">
            <div class="toolbar">
                <form method="GET" class="row">
                    <div class="fg"><label>Month</label><input type="month" name="f_month" value="<?php echo le_h($f_month); ?>"></div>
                    <div class="fg"><label>Status</label>
                        <select name="f_status">
                            <option value="">All</option>
                            <option value="Pending" <?php echo $f_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Paid" <?php echo $f_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Filter</button>
                    <a class="btn btn-gray btn-sm" href="leave_encashment.php">Reset</a>
                </form>
            </div>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SL</th><th>User No</th><th>Name</th><th>Basic</th><th>Daily Wage</th>
                        <th>Balance</th><th>Encash Days</th><th>Amount (AED)</th><th>Month</th>
                        <th>Auto</th><th>Status</th><th>Remarks</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($list && mysqli_num_rows($list) > 0): $sl = 1; while ($r = mysqli_fetch_assoc($list)): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><b><?php echo le_h($r['user_no']); ?></b></td>
                        <td class="l"><?php echo le_h($r['employee_name']); ?></td>
                        <td><?php echo le_money($r['basic_salary']); ?></td>
                        <td><?php echo le_money($r['daily_wage']); ?></td>
                        <td><?php echo rtrim(rtrim(number_format((float)$r['leave_balance_days'], 1), '0'), '.'); ?></td>
                        <td><?php echo rtrim(rtrim(number_format((float)$r['encash_days'], 1), '0'), '.'); ?></td>
                        <td><b><?php echo le_money($r['encash_amount']); ?></b></td>
                        <td><?php echo $r['encashment_month'] ? le_h(date('M Y', strtotime($r['encashment_month'] . '-01'))) : ''; ?></td>
                        <td><?php echo le_h($r['auto_calculate']); ?></td>
                        <td><span class="badge <?php echo strtolower($r['status']) === 'paid' ? 'paid' : 'pending'; ?>"><?php echo le_h($r['status']); ?></span></td>
                        <td class="l"><?php echo le_h($r['remarks']); ?></td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-sm btn-primary" href="?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this encashment record?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="13" class="muted" style="padding:20px;">No leave encashment records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<script>
function leRecalc() {
    var auto   = document.getElementById('autoCalc');
    var days   = parseFloat(document.getElementById('encashDays').value) || 0;
    var wage   = parseFloat(document.getElementById('dailyWage').value) || 0;
    var amount = document.getElementById('encashAmount');
    if (!auto || !amount) return;
    if (auto.value === 'Yes') {
        amount.value = (days * wage).toFixed(2);
        amount.setAttribute('readonly', 'readonly');
    } else {
        amount.removeAttribute('readonly');
    }
}
document.addEventListener('DOMContentLoaded', leRecalc);
</script>
</body>
</html>
