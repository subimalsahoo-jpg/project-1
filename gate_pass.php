<?php
include 'auth.php';
requireAnyPermission(['gate_pass_manage']);

$is_admin = function_exists('is_admin_user') ? is_admin_user() : false;

/* ─────────────────────────────────────────────
   AJAX: look up an employee's name by User No / Employee ID
   (used to auto-fill the name box as you type the User No)
───────────────────────────────────────────── */
if (isset($_GET['lookup'])) {
    header('Content-Type: application/json');
    $key = trim((string)$_GET['lookup']);
    $out = ['found' => false, 'name' => '', 'user_no' => ''];
    if ($key !== '') {
        $stmt = mysqli_prepare($conn, "SELECT user_no, full_name FROM employees
            WHERE user_no = ? OR employee_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ss', $key, $key);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($e = mysqli_fetch_assoc($res)) {
            $out = ['found' => true, 'name' => (string)$e['full_name'], 'user_no' => (string)$e['user_no']];
        }
        mysqli_stmt_close($stmt);
    }
    echo json_encode($out);
    exit();
}

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

/* Render a 12-hour time picker (hour 1-12 / minute / AM-PM) as three selects,
   so the time always displays in 12-hour format regardless of OS locale. */
function gp_time_selects($prefix, $defHour, $defMin, $defAp) {
    $h  = '<div class="time-pick">';
    $h .= '<select name="' . $prefix . '_hour">';
    for ($i = 1; $i <= 12; $i++) {
        $sel = ((int)$i === (int)$defHour) ? 'selected' : '';
        $h  .= '<option value="' . $i . '" ' . $sel . '>' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '</option>';
    }
    $h .= '</select><span class="time-colon">:</span>';
    $h .= '<select name="' . $prefix . '_min">';
    for ($m = 0; $m < 60; $m += 5) {
        $mm  = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        $sel = ($mm === $defMin) ? 'selected' : '';
        $h  .= '<option value="' . $mm . '" ' . $sel . '>' . $mm . '</option>';
    }
    $h .= '</select>';
    $h .= '<select name="' . $prefix . '_ampm">';
    foreach (['AM', 'PM'] as $ap) {
        $sel = ($ap === $defAp) ? 'selected' : '';
        $h  .= '<option value="' . $ap . '" ' . $sel . '>' . $ap . '</option>';
    }
    $h .= '</select></div>';
    return $h;
}

/* Ensure a column exists on a table (simple migration helper). */
function gp_ensure_column($conn, $table, $column, $definition) {
    $safe_t = mysqli_real_escape_string($conn, $table);
    $safe_c = mysqli_real_escape_string($conn, $column);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_t` LIKE '$safe_c'");
    if ($r && mysqli_num_rows($r) === 0) {
        mysqli_query($conn, "ALTER TABLE `$safe_t` ADD `$column` $definition");
    }
}

/* ─────────────────────────────────────────────
   Ensure tables exist
───────────────────────────────────────────── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gate_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pass_no VARCHAR(40) DEFAULT '',
    pass_date DATE NULL,
    leave_date DATE NULL,
    return_date DATE NULL,
    depart_time VARCHAR(20) DEFAULT '',
    return_time VARCHAR(20) DEFAULT '',
    subject VARCHAR(200) DEFAULT 'Request for Permission',
    reason VARCHAR(255) DEFAULT '',
    employees_json TEXT,
    employee_summary VARCHAR(500) DEFAULT '',
    employee_count INT DEFAULT 0,
    created_by VARCHAR(150) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Migration for tables created before the subject column existed. */
gp_ensure_column($conn, 'gate_passes', 'subject', "VARCHAR(200) DEFAULT 'Request for Permission'");
gp_ensure_column($conn, 'gate_passes', 'return_date', "DATE NULL");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gate_pass_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason_text VARCHAR(120) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Seed default reasons once. */
$seed_chk = mysqli_query($conn, "SELECT COUNT(*) AS c FROM gate_pass_reasons");
if ($seed_chk && (int)(mysqli_fetch_assoc($seed_chk)['c'] ?? 0) === 0) {
    $defaults = ['Leave', 'Visa Renewal', 'Personal Work', 'Medical Purpose', 'Passport Renewal', 'Holiday', 'Vacation', 'Country Exit'];
    $i = 0;
    foreach ($defaults as $d) {
        $sd = mysqli_real_escape_string($conn, $d);
        mysqli_query($conn, "INSERT INTO gate_pass_reasons (reason_text, sort_order) VALUES ('$sd', $i)");
        $i++;
    }
}

$message = '';

/* ─────────────────────────────────────────────
   Reason management — Admin only (add / edit / delete)
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['add_reason'])) {
    $rt = trim($_POST['reason_text'] ?? '');
    if ($rt !== '') {
        $dup = mysqli_query($conn, "SELECT id FROM gate_pass_reasons WHERE reason_text='" . mysqli_real_escape_string($conn, $rt) . "' LIMIT 1");
        if (!$dup || mysqli_num_rows($dup) === 0) {
            $ord_r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM gate_pass_reasons"));
            $ord = (int)($ord_r['n'] ?? 0);
            $stmt = mysqli_prepare($conn, "INSERT INTO gate_pass_reasons (reason_text, sort_order) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, 'si', $rt, $ord);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    header("Location: gate_pass.php?reason_saved=1");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['edit_reason'])) {
    $rid = (int)($_POST['reason_id'] ?? 0);
    $rt  = trim($_POST['reason_text'] ?? '');
    if ($rid > 0 && $rt !== '') {
        $stmt = mysqli_prepare($conn, "UPDATE gate_pass_reasons SET reason_text=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'si', $rt, $rid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: gate_pass.php?reason_saved=1");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && isset($_POST['delete_reason'])) {
    $rid = (int)($_POST['delete_reason'] ?? 0);
    if ($rid > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM gate_pass_reasons WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'i', $rid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: gate_pass.php?reason_saved=1");
    exit();
}

/* Load reasons for the dropdown */
$reasons = [];
$rq = mysqli_query($conn, "SELECT * FROM gate_pass_reasons ORDER BY sort_order, reason_text");
if ($rq) { while ($r = mysqli_fetch_assoc($rq)) { $reasons[] = $r; } }

/* ─────────────────────────────────────────────
   Delete a gate pass — Admin only
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pass']) && $is_admin) {
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
   Look up an employee snapshot (for the printed pass)
───────────────────────────────────────────── */
function gp_employee_snapshot($conn, $user_no, $fallback_name = '') {
    $snap = [
        'user_no'      => $user_no,
        'emp_id'       => '',
        'name'         => $fallback_name,
        'saif_zone_id' => '',
        'emirates_id'  => '',
        'photo'        => '',
    ];
    $user_no = trim((string)$user_no);
    if ($user_no === '') return $snap;

    $stmt = mysqli_prepare($conn, "SELECT user_no, employee_id, full_name, saif_zone_id, emirates_id_number, photo
        FROM employees WHERE user_no = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $user_no);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($e = mysqli_fetch_assoc($res)) {
        $snap['emp_id']       = trim((string)($e['employee_id'] ?: $e['user_no']));
        $snap['name']         = trim((string)$e['full_name']) ?: $fallback_name;
        $snap['saif_zone_id'] = trim((string)($e['saif_zone_id'] ?? ''));
        $snap['emirates_id']  = trim((string)($e['emirates_id_number'] ?? ''));
        $snap['photo']        = trim((string)($e['photo'] ?? ''));
    }
    mysqli_stmt_close($stmt);
    return $snap;
}

/* ─────────────────────────────────────────────
   Save a new gate pass
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pass'])) {
    $leave_date  = trim($_POST['leave_date'] ?? '');
    $return_date = trim($_POST['return_date'] ?? '');
    $depart_time = gp_time_ampm(trim(($_POST['depart_hour'] ?? '') . ':' . ($_POST['depart_min'] ?? '') . ' ' . ($_POST['depart_ampm'] ?? '')));
    $return_time = gp_time_ampm(trim(($_POST['return_hour'] ?? '') . ':' . ($_POST['return_min'] ?? '') . ' ' . ($_POST['return_ampm'] ?? '')));
    $subject     = trim($_POST['subject'] ?? '') ?: 'Request for Permission';
    $reason      = trim($_POST['reason'] ?? '');

    $emp_names = $_POST['emp_name'] ?? [];
    $user_nos  = $_POST['user_no']  ?? [];

    $employees = [];
    $summary_parts = [];
    if (is_array($emp_names)) {
        foreach ($emp_names as $i => $nm) {
            $nm  = trim((string)$nm);
            $uno = trim((string)($user_nos[$i] ?? ''));
            if ($nm === '' && $uno === '') continue;
            $snap = gp_employee_snapshot($conn, $uno, $nm);
            if (trim((string)$snap['name']) === '') continue;
            $employees[] = $snap;
            $summary_parts[] = ($snap['user_no'] !== '' ? $snap['user_no'] . ' - ' : '') . $snap['name'];
        }
    }

    if ($leave_date === '' || empty($employees)) {
        $message = "<div class='gp-msg err'>Please provide a leave date and at least one employee.</div>";
    } else {
        $pass_date     = date('Y-m-d');
        $return_date_db = ($return_date !== '') ? $return_date : null;
        $employees_json = json_encode($employees, JSON_UNESCAPED_UNICODE);
        $employee_summary = mb_substr(implode(', ', $summary_parts), 0, 500);
        $employee_count = count($employees);
        $created_by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $pass_no = '';

        $stmt = mysqli_prepare($conn, "INSERT INTO gate_passes
            (pass_no, pass_date, leave_date, return_date, depart_time, return_time, subject, reason, employees_json, employee_summary, employee_count, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param(
            $stmt, 'ssssssssssis',
            $pass_no, $pass_date, $leave_date, $return_date_db, $depart_time, $return_time,
            $subject, $reason, $employees_json, $employee_summary, $employee_count, $created_by
        );
        mysqli_stmt_execute($stmt);
        $new_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($new_id > 0) {
            /* Monthly serial: GP-YYYY-MM-NNN where NNN restarts each month. */
            $ym = date('Y-m');
            $cnt_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS c FROM gate_passes WHERE DATE_FORMAT(pass_date,'%Y-%m') = '" . mysqli_real_escape_string($conn, $ym) . "'"));
            $serial = max(1, (int)($cnt_row['c'] ?? 1));
            $pass_no = 'GP-' . date('Y') . '-' . date('m') . '-' . str_pad((string)$serial, 3, '0', STR_PAD_LEFT);
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
$prefill = ['name' => '', 'user_no' => ''];
$searchVal = trim($_GET['search'] ?? '');
if ($searchVal !== '') {
    $stmt = mysqli_prepare($conn, "SELECT user_no, full_name FROM employees
        WHERE user_no = ? OR employee_id = ? OR full_name LIKE ? LIMIT 1");
    $like = '%' . $searchVal . '%';
    mysqli_stmt_bind_param($stmt, 'sss', $searchVal, $searchVal, $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($emp = mysqli_fetch_assoc($res)) {
        $prefill = [
            'name'    => trim((string)$emp['full_name']),
            'user_no' => trim((string)$emp['user_no']),
        ];
    }
    mysqli_stmt_close($stmt);
}

/* When opened from Employee Overview (?search=user_no) the pass is for that
   one employee: prefill name + user no and hide the multi-employee controls. */
$from_overview = ($searchVal !== '' && $prefill['user_no'] !== '');

$saved_id = (int)($_GET['saved'] ?? 0);
if ($saved_id > 0) {
    $message = "<div class='gp-msg ok'>&#10004; Gate pass generated and saved. The printable copy is opening in a new tab&hellip;</div>";
}
if (isset($_GET['deleted'])) {
    $message = "<div class='gp-msg ok'>Gate pass deleted.</div>";
}
if (isset($_GET['reason_saved'])) {
    $message = "<div class='gp-msg ok'>Reason list updated.</div>";
}

/* ─────────────────────────────────────────────
   Load history (summary)
───────────────────────────────────────────── */
$f_search = trim($_GET['q'] ?? '');
$where = '';
if ($f_search !== '') {
    $s = mysqli_real_escape_string($conn, $f_search);
    $where = "WHERE pass_no LIKE '%$s%' OR employee_summary LIKE '%$s%' OR reason LIKE '%$s%' OR subject LIKE '%$s%'";
}
$rows = [];
$lq = mysqli_query($conn, "SELECT * FROM gate_passes $where ORDER BY id DESC LIMIT 500");
if ($lq) { while ($r = mysqli_fetch_assoc($lq)) { $rows[] = $r; } }

/* Counts */
$total_passes = 0; $this_month = 0; $today_count = 0;
$cur_month = date('Y-m'); $today = date('Y-m-d');
$cq = mysqli_query($conn, "SELECT pass_date FROM gate_passes");
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
.fg label{font-size:13px;font-weight:700;color:var(--brand);}
.fg input,.fg select{padding:10px 12px;border:1.6px solid #f1c27a;border-radius:8px;font-size:14px;font-family:inherit;background:#fffaf2;transition:border-color .15s,box-shadow .15s;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(232,160,32,.22);}
.time-pick{display:flex;align-items:center;gap:6px;}
.time-pick select{padding:10px 8px;border:1.6px solid #f1c27a;border-radius:8px;font-size:14px;font-family:inherit;background:#fffaf2;transition:border-color .15s,box-shadow .15s;}
.time-pick select:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(232,160,32,.22);}
.time-pick .time-colon{font-weight:800;color:var(--brand);}
.emp-table{width:100%;border-collapse:collapse;margin-top:6px;}
.emp-table th{background:var(--brand);color:#fff;font-size:12px;text-align:left;padding:8px 10px;}
.emp-table td{padding:6px 8px;border-bottom:1px solid var(--gray-200);}
.emp-table input{width:100%;padding:9px 11px;border:1.6px solid #f1c27a;border-radius:7px;font-size:13.5px;background:#fffaf2;transition:border-color .15s,box-shadow .15s;}
.emp-table input:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(232,160,32,.22);}
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
.reason-row{display:flex;gap:8px;align-items:center;padding:7px 0;border-bottom:1px dashed var(--gray-200);}
.reason-row input[type=text]{flex:1;padding:7px 10px;border:1px solid var(--gray-200);border-radius:6px;font-size:13.5px;background:#f8fafc;}
.reason-add{display:flex;gap:8px;margin-bottom:12px;}
.reason-add input{flex:1;padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:14px;}
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
                        <?php echo gp_time_selects('depart', 9, '00', 'AM'); ?>
                    </div>
                    <div class="fg">
                        <label>Return Date</label>
                        <input type="date" name="return_date" value="<?php echo gp_h(date('Y-m-d')); ?>">
                    </div>
                    <div class="fg">
                        <label>Return Time</label>
                        <?php echo gp_time_selects('return', 6, '00', 'PM'); ?>
                    </div>
                    <div class="fg">
                        <label>Reason</label>
                        <select name="reason">
                            <option value="">&mdash; Select reason &mdash;</option>
                            <?php foreach ($reasons as $rr): ?>
                            <option value="<?php echo gp_h($rr['reason_text']); ?>"><?php echo gp_h($rr['reason_text']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg full">
                        <label>Subject</label>
                        <input type="text" name="subject" value="Request for Permission" placeholder="Subject line of the letter">
                    </div>
                </div>

                <table class="emp-table" id="empTable">
                    <thead>
                        <tr>
                            <th style="width:180px;">User No</th>
                            <th>Employee Name</th>
                            <?php if (!$from_overview): ?><th style="width:70px;">&nbsp;</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="text" name="user_no[]" value="<?php echo gp_h($prefill['user_no']); ?>" placeholder="User No" onchange="gpLookup(this)" onblur="gpLookup(this)" <?php echo $from_overview ? 'readonly' : ''; ?>></td>
                            <td><input type="text" name="emp_name[]" value="<?php echo gp_h($prefill['name']); ?>" placeholder="Employee name" <?php echo $from_overview ? 'readonly' : ''; ?>></td>
                            <?php if (!$from_overview): ?>
                            <td><button type="button" class="btn btn-sm btn-rowdel" onclick="gpDelRow(this)">&#10005;</button></td>
                            <?php endif; ?>
                        </tr>
                    </tbody>
                </table>
                <div class="muted" style="margin-top:6px;">Type a User No (or Employee ID) and the name fills in automatically. Saif Zone ID, Emirates ID and the photo are pulled from the employee record.</div>

                <div class="actions">
                    <?php if (!$from_overview): ?>
                    <button type="button" class="btn btn-gray" onclick="gpAddRow()">&#10133; Add Employee</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-accent">&#128682; Generate &amp; Save Gate Pass</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- ── Manage reasons (Admin only) ── -->
    <div class="panel">
        <div class="panel-head"><span>&#9881; Manage Reasons (Admin)</span></div>
        <div class="panel-body">
            <form method="POST" class="reason-add">
                <input type="text" name="reason_text" placeholder="Add a new reason (e.g. Bank Work)" required>
                <button type="submit" name="add_reason" value="1" class="btn btn-primary">&#10133; Add</button>
            </form>
            <?php foreach ($reasons as $rr): ?>
            <form method="POST" class="reason-row">
                <input type="hidden" name="reason_id" value="<?php echo (int)$rr['id']; ?>">
                <input type="text" name="reason_text" value="<?php echo gp_h($rr['reason_text']); ?>">
                <button type="submit" name="edit_reason" value="1" class="btn btn-sm btn-gray">Save</button>
                <button type="submit" name="delete_reason" value="<?php echo (int)$rr['id']; ?>" class="btn btn-sm btn-rowdel" onclick="return confirm('Delete this reason?');">Delete</button>
            </form>
            <?php endforeach; ?>
            <?php if (empty($reasons)): ?><div class="muted">No reasons yet. Add one above.</div><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

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
                        <td>
                            <?php echo gp_h(gp_dmy($r['leave_date'])); ?>
                            <?php if (!empty($r['return_date']) && $r['return_date'] !== '0000-00-00'): ?>
                            <div class="muted">&#8594; <?php echo gp_h(gp_dmy($r['return_date'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo gp_h($r['depart_time']); ?> &ndash; <?php echo gp_h($r['return_time']); ?></td>
                        <td>
                            <?php echo gp_h($r['employee_summary']); ?>
                            <div class="muted"><?php echo (int)$r['employee_count']; ?> employee(s)</div>
                        </td>
                        <td><?php echo gp_h($r['reason']); ?></td>
                        <td><?php echo gp_h($r['created_by']); ?></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <a class="btn btn-sm btn-print" href="gate_pass_print.php?id=<?php echo (int)$r['id']; ?>&auto=1" target="_blank" rel="noopener">&#128438; Print</a>
                            <?php if ($is_admin): ?>
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
        '<td><input type="text" name="user_no[]" placeholder="User No" onchange="gpLookup(this)" onblur="gpLookup(this)"></td>' +
        '<td><input type="text" name="emp_name[]" placeholder="Employee name"></td>' +
        '<td><button type="button" class="btn btn-sm btn-rowdel" onclick="gpDelRow(this)">&#10005;</button></td>';
    tb.appendChild(tr);
}
function gpDelRow(btn) {
    var tb = document.querySelector('#empTable tbody');
    if (tb.rows.length <= 1) {
        btn.closest('tr').querySelectorAll('input').forEach(function(i){ i.value = ''; });
        return;
    }
    btn.closest('tr').remove();
}
/* Auto-fill the employee name from the typed User No / Employee ID. */
function gpLookup(input) {
    var val = (input.value || '').trim();
    var row = input.closest('tr');
    if (!row) return;
    var nameInput = row.querySelector('input[name="emp_name[]"]');
    if (!nameInput || val === '') return;
    fetch('gate_pass.php?lookup=' + encodeURIComponent(val))
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.found) {
                nameInput.value = d.name;
                if (d.user_no) { input.value = d.user_no; }
            }
        })
        .catch(function(){});
}
<?php if ($saved_id > 0): ?>
window.open('gate_pass_print.php?id=<?php echo $saved_id; ?>&auto=1', '_blank');
<?php endif; ?>
</script>
</body>
</html>
