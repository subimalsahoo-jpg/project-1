<?php
include 'auth.php';
requireAnyPermission(['gate_pass_manage']);

/* ─────────────────────────────────────────────
   Helpers
───────────────────────────────────────────── */
function gp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function gp_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-M-Y', $t) : $v;
}

/* Accepts "09:00" / "9:00 AM" / "18:30" and returns a friendly "9:00 AM". */
function gp_time_ampm($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    $t = strtotime($v);
    if ($t === false) {
        $t = strtotime('2000-01-01 ' . $v);
    }
    return $t ? date('g:i A', $t) : $v;
}

/* ─────────────────────────────────────────────
   Ensure the gate_passes table exists
───────────────────────────────────────────── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gate_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pass_no VARCHAR(40) DEFAULT '',
    pass_date DATE NULL,
    leave_date DATE NULL,
    depart_time VARCHAR(20) DEFAULT '',
    return_time VARCHAR(20) DEFAULT '',
    reason VARCHAR(255) DEFAULT '',
    employees_json TEXT,
    employee_summary VARCHAR(500) DEFAULT '',
    employee_count INT DEFAULT 0,
    created_by VARCHAR(150) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$message = '';

/* ─────────────────────────────────────────────
   Delete a gate pass — Admin only
───────────────────────────────────────────── */
$can_delete = function_exists('is_admin_user') ? is_admin_user() : false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pass']) && $can_delete) {
    $gid = (int)($_POST['delete_pass'] ?? 0);
    if ($gid > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM gate_passes WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'i', $gid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: gate_pass.php?deleted=1");
    exit();
}

/* ─────────────────────────────────────────────
   Save a new gate pass
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pass'])) {
    $leave_date  = trim($_POST['leave_date'] ?? '');
    $depart_time = gp_time_ampm($_POST['depart_time'] ?? '');
    $return_time = gp_time_ampm($_POST['return_time'] ?? '');
    $reason      = trim($_POST['reason'] ?? '');

    $emp_ids   = $_POST['emp_id']   ?? [];
    $emp_names = $_POST['emp_name'] ?? [];
    $user_nos  = $_POST['user_no']  ?? [];

    $employees = [];
    $summary_parts = [];
    if (is_array($emp_names)) {
        foreach ($emp_names as $i => $nm) {
            $nm = trim((string)$nm);
            if ($nm === '') continue;
            $eid = trim((string)($emp_ids[$i] ?? ''));
            $uno = trim((string)($user_nos[$i] ?? ''));
            $employees[] = ['emp_id' => $eid, 'name' => $nm, 'user_no' => $uno];
            $summary_parts[] = ($eid !== '' ? $eid . ' - ' : '') . $nm;
        }
    }

    if ($leave_date === '' || empty($employees)) {
        $message = "<div class='gp-msg err'>Please provide a leave date and at least one employee.</div>";
    } else {
        $pass_date     = date('Y-m-d');
        $employees_json = json_encode($employees, JSON_UNESCAPED_UNICODE);
        $employee_summary = mb_substr(implode(', ', $summary_parts), 0, 500);
        $employee_count = count($employees);
        $created_by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $pass_no = '';

        $stmt = mysqli_prepare($conn, "INSERT INTO gate_passes
            (pass_no, pass_date, leave_date, depart_time, return_time, reason, employees_json, employee_summary, employee_count, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param(
            $stmt, 'ssssssssis',
            $pass_no, $pass_date, $leave_date, $depart_time, $return_time,
            $reason, $employees_json, $employee_summary, $employee_count, $created_by
        );
        mysqli_stmt_execute($stmt);
        $new_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        /* Build a readable pass number from the new auto id. */
        if ($new_id > 0) {
            $pass_no = 'GP-' . date('Y') . '-' . str_pad((string)$new_id, 4, '0', STR_PAD_LEFT);
            $up = mysqli_prepare($conn, "UPDATE gate_passes SET pass_no=? WHERE id=?");
            mysqli_stmt_bind_param($up, 'si', $pass_no, $new_id);
            mysqli_stmt_execute($up);
            mysqli_stmt_close($up);
        }

        header("Location: gate_pass.php?saved=" . (int)$new_id);
        exit();
    }
}

/* ─────────────────────────────────────────────
   Prefill from employee overview (?search=user_no)
───────────────────────────────────────────── */
$prefill = ['emp_id' => '', 'name' => '', 'user_no' => ''];
$searchVal = trim($_GET['search'] ?? '');
if ($searchVal !== '') {
    $stmt = mysqli_prepare($conn, "SELECT user_no, employee_id, full_name FROM employees
        WHERE user_no = ? OR employee_id = ? OR full_name LIKE ? LIMIT 1");
    $like = '%' . $searchVal . '%';
    mysqli_stmt_bind_param($stmt, 'sss', $searchVal, $searchVal, $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($emp = mysqli_fetch_assoc($res)) {
        $prefill = [
            'emp_id'  => trim((string)($emp['employee_id'] ?: $emp['user_no'])),
            'name'    => trim((string)$emp['full_name']),
            'user_no' => trim((string)$emp['user_no']),
        ];
    }
    mysqli_stmt_close($stmt);
}

$saved_id = (int)($_GET['saved'] ?? 0);
if ($saved_id > 0) {
    $message = "<div class='gp-msg ok'>&#10004; Gate pass generated and saved. The printable copy is opening in a new tab&hellip;</div>";
}
if (isset($_GET['deleted'])) {
    $message = "<div class='gp-msg ok'>Gate pass deleted.</div>";
}

/* ─────────────────────────────────────────────
   Load history (summary)
───────────────────────────────────────────── */
$f_search = trim($_GET['q'] ?? '');
$where = '';
if ($f_search !== '') {
    $s = mysqli_real_escape_string($conn, $f_search);
    $where = "WHERE pass_no LIKE '%$s%' OR employee_summary LIKE '%$s%' OR reason LIKE '%$s%'";
}
$rows = [];
$rq = mysqli_query($conn, "SELECT * FROM gate_passes $where ORDER BY id DESC LIMIT 500");
if ($rq) { while ($r = mysqli_fetch_assoc($rq)) { $rows[] = $r; } }

/* Counts */
$total_passes = 0; $this_month = 0; $today_count = 0;
$cur_month = date('Y-m'); $today = date('Y-m-d');
$cq = mysqli_query($conn, "SELECT pass_date, employee_count FROM gate_passes");
if ($cq) {
    while ($c = mysqli_fetch_assoc($cq)) {
        $total_passes++;
        if (substr((string)$c['pass_date'], 0, 7) === $cur_month) $this_month++;
        if ((string)$c['pass_date'] === $today) $today_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gate Pass</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--red:#b91c1c;--red-soft:#fee2e2;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.btn-back{background:rgba(255,255,255,.15);color:#fff;text-decoration:none;padding:7px 13px;border-radius:6px;font-size:13px;}
.btn-back:hover{background:rgba(255,255,255,.28);}
.page{max-width:1180px;margin:22px auto;padding:0 18px;}
.page-title{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:700;color:var(--brand);margin-bottom:16px;}
.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 18px;}
.card .label{color:var(--gray-600);font-size:12.5px;font-weight:600;}
.card .value{font-size:26px;font-weight:800;color:var(--brand);margin-top:4px;}
.gp-msg{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-weight:600;}
.gp-msg.ok{background:var(--green-soft);color:#166534;border:1px solid #86efac;}
.gp-msg.err{background:var(--red-soft);color:#991b1b;border:1px solid #fca5a5;}
.panel{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden;}
.panel-head{padding:14px 18px;border-bottom:1px solid var(--gray-200);font-weight:700;color:var(--brand);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.panel-body{padding:18px;}
.form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.full{grid-column:1/-1;}
.fg label{font-size:12.5px;font-weight:600;color:var(--gray-600);}
.fg input,.fg select{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:14px;font-family:inherit;background:#f8fafc;}
.fg input:focus{outline:none;border-color:var(--brand-mid);background:#fff;}
.emp-table{width:100%;border-collapse:collapse;margin-top:6px;}
.emp-table th{background:var(--brand);color:#fff;font-size:12px;text-align:left;padding:8px 10px;}
.emp-table td{padding:6px 8px;border-bottom:1px solid var(--gray-200);}
.emp-table input{width:100%;padding:8px 10px;border:1px solid var(--gray-200);border-radius:6px;font-size:13.5px;background:#f8fafc;}
.btn{display:inline-flex;align-items:center;gap:6px;border:none;border-radius:7px;padding:9px 16px;font-size:13.5px;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none;}
.btn-accent{background:var(--accent);color:#1a1a1a;}
.btn-accent:hover{background:#d4901a;}
.btn-primary{background:var(--brand-mid);color:#fff;}
.btn-primary:hover{background:#1d5390;}
.btn-gray{background:var(--gray-200);color:var(--gray-800);}
.btn-sm{padding:6px 11px;font-size:12px;}
.btn-rowdel{background:var(--red-soft);color:var(--red);border:1px solid #fca5a5;}
.btn-print{background:#0f766e;color:#fff;}
.btn-print:hover{background:#0c5e58;}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;}
table.list{width:100%;border-collapse:collapse;}
table.list th{background:#f1f5f9;color:var(--gray-600);font-size:12px;text-transform:uppercase;letter-spacing:.4px;text-align:left;padding:10px;border-bottom:2px solid var(--gray-200);}
table.list td{padding:10px;border-bottom:1px solid var(--gray-200);vertical-align:top;font-size:13px;}
table.list tr:hover td{background:#f8fafc;}
.muted{color:#94a3b8;font-size:11.5px;}
.pill{display:inline-block;background:#e0ecff;color:#1d4ed8;border-radius:999px;padding:2px 9px;font-weight:700;font-size:11.5px;}
.filters{display:flex;gap:8px;}
.filters input{padding:8px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;}
@media(max-width:900px){.cards{grid-template-columns:1fr;}.form-grid{grid-template-columns:1fr 1fr;}}
@media print{.topbar,.appnav,.appnav-toggle,.appnav-backdrop{display:none!important;}}
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
    <div class="page-title"><span>&#128682;</span> Gate Pass</div>

    <?php if ($message) echo $message; ?>

    <div class="cards">
        <div class="card"><div class="label">Total Gate Passes</div><div class="value"><?php echo $total_passes; ?></div></div>
        <div class="card"><div class="label">This Month</div><div class="value"><?php echo $this_month; ?></div></div>
        <div class="card"><div class="label">Issued Today</div><div class="value"><?php echo $today_count; ?></div></div>
    </div>

    <!-- ── Create form ── -->
    <div class="panel">
        <div class="panel-head"><span>&#10133; Generate New Gate Pass</span></div>
        <div class="panel-body">
            <form method="POST" id="gpForm">
                <input type="hidden" name="save_pass" value="1">
                <div class="form-grid">
                    <div class="fg">
                        <label>Date of Leaving Premises *</label>
                        <input type="date" name="leave_date" value="<?php echo gp_h(date('Y-m-d')); ?>" required>
                    </div>
                    <div class="fg">
                        <label>Departure Time</label>
                        <input type="time" name="depart_time" value="09:00">
                    </div>
                    <div class="fg">
                        <label>Return Time (same day)</label>
                        <input type="time" name="return_time" value="18:00">
                    </div>
                    <div class="fg">
                        <label>Reason (optional)</label>
                        <input type="text" name="reason" placeholder="e.g. Personal work">
                    </div>
                </div>

                <table class="emp-table" id="empTable">
                    <thead>
                        <tr><th style="width:160px;">EMP ID</th><th>EMPLOYEE NAME</th><th style="width:150px;">User No</th><th style="width:70px;">&nbsp;</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="text" name="emp_id[]" value="<?php echo gp_h($prefill['emp_id']); ?>" placeholder="EMP ID"></td>
                            <td><input type="text" name="emp_name[]" value="<?php echo gp_h($prefill['name']); ?>" placeholder="Employee name"></td>
                            <td><input type="text" name="user_no[]" value="<?php echo gp_h($prefill['user_no']); ?>" placeholder="User No"></td>
                            <td><button type="button" class="btn btn-sm btn-rowdel" onclick="gpDelRow(this)">&#10005;</button></td>
                        </tr>
                    </tbody>
                </table>

                <div class="actions">
                    <button type="button" class="btn btn-gray" onclick="gpAddRow()">&#10133; Add Employee</button>
                    <button type="submit" class="btn btn-accent">&#128682; Generate &amp; Save Gate Pass</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── History / summary ── -->
    <div class="panel">
        <div class="panel-head">
            <span>&#128203; Gate Pass History</span>
            <form method="GET" class="filters">
                <input type="text" name="q" value="<?php echo gp_h($f_search); ?>" placeholder="Search pass no / employee / reason">
                <button class="btn btn-primary btn-sm" type="submit">&#128269; Search</button>
                <?php if ($f_search !== ''): ?><a class="btn btn-gray btn-sm" href="gate_pass.php">Clear</a><?php endif; ?>
            </form>
        </div>
        <div class="panel-body" style="overflow-x:auto;">
            <table class="list">
                <thead>
                    <tr>
                        <th>Pass No</th><th>Issued</th><th>Leave Date</th><th>Time</th>
                        <th>Employees</th><th>Reason</th><th>By</th><th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:24px;">No gate passes yet.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="pill"><?php echo gp_h($r['pass_no'] ?: ('#' . $r['id'])); ?></span></td>
                        <td><?php echo gp_h(gp_dmy($r['pass_date'])); ?></td>
                        <td><?php echo gp_h(gp_dmy($r['leave_date'])); ?></td>
                        <td><?php echo gp_h($r['depart_time']); ?> &ndash; <?php echo gp_h($r['return_time']); ?></td>
                        <td>
                            <?php echo gp_h($r['employee_summary']); ?>
                            <div class="muted"><?php echo (int)$r['employee_count']; ?> employee(s)</div>
                        </td>
                        <td><?php echo gp_h($r['reason']); ?></td>
                        <td><?php echo gp_h($r['created_by']); ?></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <a class="btn btn-sm btn-print" href="gate_pass_print.php?id=<?php echo (int)$r['id']; ?>&auto=1" target="_blank" rel="noopener">&#128438; Print</a>
                            <?php if ($can_delete): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this gate pass?');">
                                <input type="hidden" name="delete_pass" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-rowdel">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function gpAddRow() {
    var tb = document.querySelector('#empTable tbody');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="text" name="emp_id[]" placeholder="EMP ID"></td>' +
        '<td><input type="text" name="emp_name[]" placeholder="Employee name"></td>' +
        '<td><input type="text" name="user_no[]" placeholder="User No"></td>' +
        '<td><button type="button" class="btn btn-sm btn-rowdel" onclick="gpDelRow(this)">&#10005;</button></td>';
    tb.appendChild(tr);
}
function gpDelRow(btn) {
    var tb = document.querySelector('#empTable tbody');
    if (tb.rows.length <= 1) {
        var inputs = btn.closest('tr').querySelectorAll('input');
        inputs.forEach(function(i){ i.value = ''; });
        return;
    }
    btn.closest('tr').remove();
}
<?php if ($saved_id > 0): ?>
/* Auto-open the printable copy of the just-saved gate pass. */
window.open('gate_pass_print.php?id=<?php echo $saved_id; ?>&auto=1', '_blank');
<?php endif; ?>
</script>
</body>
</html>
