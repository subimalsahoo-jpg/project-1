<?php
include 'auth.php';
require_once 'memo_helper.php';
requireAnyPermission(['memo_manage']);

memo_ensure_schema($conn);

$is_admin = function_exists('is_admin_user') ? is_admin_user() : false;

function memo_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function memo_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-M-Y', $t) : $v;
}

/* ─────────────────────────────────────────────
   AJAX: look up an employee by User No / Employee ID
───────────────────────────────────────────── */
if (isset($_GET['lookup'])) {
    header('Content-Type: application/json');
    $snap = memo_employee_snapshot($conn, trim((string)$_GET['lookup']));
    $found = ($snap['name'] !== '' || $snap['designation'] !== '' || $snap['employee_id'] !== '');
    echo json_encode([
        'found'       => $found,
        'user_no'     => $snap['user_no'],
        'name'        => $snap['name'],
        'designation' => $snap['designation'],
        'employee_id' => $snap['employee_id'],
    ]);
    exit();
}

$message = '';

/* ─────────────────────────────────────────────
   Delete a memo — Admin only
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_memo']) && $is_admin) {
    $mid = (int)($_POST['delete_memo'] ?? 0);
    if ($mid > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM employee_memos WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'i', $mid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    if (($_POST['origin'] ?? '') === 'overview') {
        $os = urlencode(trim((string)($_POST['origin_search'] ?? '')));
        header("Location: employee_overview.php?search=$os&tab=memo&memo_deleted=1");
    } else {
        header("Location: employee_memo.php?deleted=1");
    }
    exit();
}

/* ─────────────────────────────────────────────
   Save a new memo
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_memo'])) {
    $user_no   = trim($_POST['user_no'] ?? '');
    $memo_type = trim($_POST['memo_type'] ?? '');
    $memo_date = trim($_POST['memo_date'] ?? '');
    $subject   = trim($_POST['subject'] ?? '');
    $body      = trim($_POST['body'] ?? '');
    $issued_by = trim($_POST['issued_by'] ?? '') ?: 'ADMIN In-charge';
    $origin    = $_POST['origin'] ?? '';

    if (!in_array($memo_type, memo_types(), true)) $memo_type = 'General Notice';
    if ($memo_date === '') $memo_date = date('Y-m-d');

    /* Snapshot the employee — prefer live employee data, fall back to posted. */
    $snap = memo_employee_snapshot($conn, $user_no, trim($_POST['employee_name'] ?? ''));
    $emp_name = $snap['name'] !== '' ? $snap['name'] : trim($_POST['employee_name'] ?? '');
    $emp_id   = $snap['employee_id'] !== '' ? $snap['employee_id'] : trim($_POST['employee_id'] ?? '');
    $desig    = trim($_POST['designation'] ?? '');
    if ($desig === '') $desig = $snap['designation'];
    if ($user_no === '') $user_no = $snap['user_no'];

    if ($user_no === '' && $emp_name === '') {
        $message = "<div class='memo-msg err'>Please select an employee (User No or Name).</div>";
    } elseif ($body === '') {
        $message = "<div class='memo-msg err'>The memo body cannot be empty.</div>";
    } else {
        $memo_no    = memo_next_no($conn);
        $created_by = memo_current_user_name();

        $stmt = mysqli_prepare($conn, "INSERT INTO employee_memos
            (memo_no, user_no, employee_id, employee_name, designation, memo_date, memo_type, subject, body, issued_by, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'sssssssssss',
            $memo_no, $user_no, $emp_id, $emp_name, $desig, $memo_date, $memo_type, $subject, $body, $issued_by, $created_by);
        mysqli_stmt_execute($stmt);
        $new_id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($origin === 'overview') {
            $os = urlencode(trim((string)($_POST['origin_search'] ?? $user_no)));
            header("Location: employee_overview.php?search=$os&tab=memo&memo_print=" . $new_id);
        } else {
            header("Location: employee_memo.php?saved=" . $new_id);
        }
        exit();
    }
}

/* ─────────────────────────────────────────────
   Prefill from ?search=user_no (single-employee mode)
───────────────────────────────────────────── */
$prefill = ['user_no' => '', 'name' => '', 'designation' => '', 'employee_id' => ''];
$searchVal = trim($_GET['search'] ?? '');
if ($searchVal !== '') {
    $prefill = memo_employee_snapshot($conn, $searchVal);
}
$from_overview = ($searchVal !== '' && $prefill['user_no'] !== '');

$saved_id = (int)($_GET['saved'] ?? 0);
if ($saved_id > 0) {
    $message = "<div class='memo-msg ok'>&#10004; Memo saved. Opening the printable copy in a new tab&hellip;</div>";
}
if (isset($_GET['deleted'])) {
    $message = "<div class='memo-msg ok'>Memo deleted.</div>";
}

/* ─────────────────────────────────────────────
   History list
───────────────────────────────────────────── */
$f_search = trim($_GET['q'] ?? '');
$conds = [];
if ($from_overview) {
    $uno_esc = mysqli_real_escape_string($conn, $prefill['user_no']);
    $conds[] = "user_no = '$uno_esc'";
}
if ($f_search !== '') {
    $s = mysqli_real_escape_string($conn, $f_search);
    $conds[] = "(memo_no LIKE '%$s%' OR user_no LIKE '%$s%' OR employee_name LIKE '%$s%' OR subject LIKE '%$s%' OR memo_type LIKE '%$s%')";
}
$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
$rows = [];
$lq = mysqli_query($conn, "SELECT * FROM employee_memos $where ORDER BY id DESC LIMIT 500");
if ($lq) { while ($r = mysqli_fetch_assoc($lq)) { $rows[] = $r; } }

/* Counts */
$total_memos = 0; $this_month = 0;
$cur_month = date('Y-m');
$cq = mysqli_query($conn, "SELECT memo_date" . ($from_overview ? "" : "") . " FROM employee_memos" . ($from_overview ? " WHERE user_no='" . mysqli_real_escape_string($conn, $prefill['user_no']) . "'" : ""));
if ($cq) {
    while ($c = mysqli_fetch_assoc($cq)) {
        $total_memos++;
        if (substr((string)$c['memo_date'], 0, 7) === $cur_month) $this_month++;
    }
}

/* JS template data (subject + body per type) */
$memo_js_templates = [];
foreach (memo_types() as $t) {
    $memo_js_templates[$t] = [
        'subject' => memo_default_subject($t),
        'body'    => memo_default_body($t),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Memo</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--red:#b91c1c;--red-soft:#fee2e2;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.15);color:#fff;text-decoration:none;padding:7px 13px;border-radius:6px;font-size:13px;}
.btn-back:hover{background:rgba(255,255,255,.28);}
.page{max-width:1100px;margin:22px auto;padding:0 18px;}
.page-title{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:700;color:var(--brand);margin-bottom:16px;}
.cards{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:18px;max-width:520px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 18px;}
.card .label{color:var(--gray-600);font-size:12.5px;font-weight:600;}
.card .value{font-size:26px;font-weight:800;color:var(--brand);margin-top:4px;}
.memo-msg{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-weight:600;}
.memo-msg.ok{background:var(--green-soft);color:#166534;border:1px solid #86efac;}
.memo-msg.err{background:var(--red-soft);color:#991b1b;border:1px solid #fca5a5;}
.panel{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden;}
.panel-head{padding:14px 18px;border-bottom:1px solid var(--gray-200);font-weight:700;color:var(--brand);}
.panel-body{padding:18px;}
.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.full{grid-column:1/-1;}
.fg label{font-size:13px;font-weight:700;color:var(--brand);}
.fg input,.fg select,.fg textarea{padding:10px 12px;border:1.6px solid #f1c27a;border-radius:8px;font-size:14px;font-family:inherit;background:#fffaf2;}
.fg textarea{min-height:200px;resize:vertical;line-height:1.6;}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(232,160,32,.22);}
.btn{display:inline-flex;align-items:center;gap:6px;border:none;border-radius:7px;padding:10px 18px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;}
.btn-accent{background:var(--accent);color:#1a1a1a;}
.btn-accent:hover{background:#d4901a;}
.btn-gray{background:var(--gray-200);color:var(--gray-800);}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{background:var(--brand);color:#fff;text-align:left;padding:10px 12px;font-size:12.5px;}
td{border-bottom:1px solid var(--gray-200);padding:9px 12px;font-size:13.5px;vertical-align:top;}
.search-row{display:flex;gap:8px;margin-bottom:14px;}
.search-row input{flex:1;max-width:340px;padding:9px 12px;border:1px solid var(--gray-200);border-radius:7px;font-size:14px;}
.act-btn{display:inline-block;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;}
.act-print{background:#0f766e;color:#fff;}
.act-del{background:var(--red-soft);color:var(--red);border:1px solid #fca5a5;}
.type-pill{display:inline-block;background:#eef2f8;color:#1a3a5c;border-radius:999px;padding:2px 9px;font-size:11.5px;font-weight:700;}
@media(max-width:820px){.form-grid{grid-template-columns:1fr;}.cards{grid-template-columns:1fr;}}
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
    <div class="page-title"><span>&#128203;</span> Employee Memo / Warning Letter</div>

    <?php echo $message; ?>

    <div class="cards">
        <div class="card"><div class="label">Total Memos<?php echo $from_overview ? ' (this employee)' : ''; ?></div><div class="value"><?php echo (int)$total_memos; ?></div></div>
        <div class="card"><div class="label">This Month</div><div class="value"><?php echo (int)$this_month; ?></div></div>
    </div>

    <!-- Create form -->
    <div class="panel">
        <div class="panel-head">&#10133; Issue New Memo<?php echo $from_overview ? ' — ' . memo_h($prefill['name']) : ''; ?></div>
        <div class="panel-body">
            <form method="POST" action="employee_memo.php">
                <input type="hidden" name="save_memo" value="1">
                <div class="form-grid">
                    <div class="fg">
                        <label>User No.</label>
                        <input type="text" id="memo_user_no" name="user_no" value="<?php echo memo_h($prefill['user_no']); ?>"
                               placeholder="Type User No / Employee ID" <?php echo $from_overview ? 'readonly' : ''; ?>>
                    </div>
                    <div class="fg">
                        <label>Employee Name</label>
                        <input type="text" id="memo_emp_name" name="employee_name" value="<?php echo memo_h($prefill['name']); ?>">
                    </div>
                    <div class="fg">
                        <label>ID No.</label>
                        <input type="text" id="memo_emp_id" name="employee_id" value="<?php echo memo_h($prefill['employee_id']); ?>">
                    </div>
                    <div class="fg">
                        <label>Designation</label>
                        <input type="text" id="memo_designation" name="designation" value="<?php echo memo_h($prefill['designation']); ?>">
                    </div>
                    <div class="fg">
                        <label>Memo Date</label>
                        <input type="date" name="memo_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="fg">
                        <label>Memo Type</label>
                        <select id="memo_type" name="memo_type" onchange="memoApplyTemplate()">
                            <?php foreach (memo_types() as $t): ?>
                            <option value="<?php echo memo_h($t); ?>"><?php echo memo_h($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg full">
                        <label>Subject</label>
                        <input type="text" id="memo_subject" name="subject" value="<?php echo memo_h(memo_default_subject(memo_types()[0])); ?>">
                    </div>
                    <div class="fg full">
                        <label>Memo Body (editable)</label>
                        <textarea id="memo_body" name="body"><?php echo memo_h(memo_default_body(memo_types()[0])); ?></textarea>
                    </div>
                    <div class="fg">
                        <label>Issued By</label>
                        <input type="text" name="issued_by" value="ADMIN In-charge">
                    </div>
                </div>
                <div style="margin-top:14px;">
                    <button type="submit" class="btn btn-accent">&#128190; Save &amp; Print Memo</button>
                    <a href="employee_memo.php" class="btn btn-gray">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- History -->
    <div class="panel">
        <div class="panel-head">Memo History<?php echo $from_overview ? ' — ' . memo_h($prefill['name']) : ''; ?></div>
        <div class="panel-body">
            <?php if (!$from_overview): ?>
            <form method="GET" action="employee_memo.php" class="search-row">
                <input type="text" name="q" value="<?php echo memo_h($f_search); ?>" placeholder="Search memo no / name / subject / type...">
                <button type="submit" class="btn btn-gray">Search</button>
            </form>
            <?php endif; ?>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Memo No</th><th>Date</th><th>User No</th><th>Employee</th>
                        <th>Type</th><th>Subject</th><th>Issued By</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:20px;">No memos found.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo memo_h($r['memo_no'] ?: ('#' . $r['id'])); ?></td>
                        <td><?php echo memo_h(memo_dmy($r['memo_date'])); ?></td>
                        <td><?php echo memo_h($r['user_no']); ?></td>
                        <td><?php echo memo_h($r['employee_name']); ?></td>
                        <td><span class="type-pill"><?php echo memo_h($r['memo_type']); ?></span></td>
                        <td><?php echo memo_h($r['subject']); ?></td>
                        <td><?php echo memo_h($r['issued_by']); ?></td>
                        <td style="white-space:nowrap;">
                            <a class="act-btn act-print" href="employee_memo_print.php?id=<?php echo (int)$r['id']; ?>" target="_blank">Print</a>
                            <?php if ($is_admin): ?>
                            <form method="POST" action="employee_memo.php" style="display:inline;" onsubmit="return confirm('Delete this memo? This cannot be undone.');">
                                <input type="hidden" name="delete_memo" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="act-btn act-del">Delete</button>
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
</div>

<script>
var MEMO_TEMPLATES = <?php echo json_encode($memo_js_templates, JSON_UNESCAPED_UNICODE); ?>;

/* Remember the last template applied so we only overwrite the fields when the
   user has not manually edited them (or has another template loaded). */
var memoLastSubject = document.getElementById('memo_subject').value;
var memoLastBody    = document.getElementById('memo_body').value;

function memoApplyTemplate() {
    var type = document.getElementById('memo_type').value;
    var tpl  = MEMO_TEMPLATES[type];
    if (!tpl) return;
    var subjEl = document.getElementById('memo_subject');
    var bodyEl = document.getElementById('memo_body');

    var bodyUntouched = (bodyEl.value.trim() === '' || bodyEl.value === memoLastBody);
    var subjUntouched = (subjEl.value.trim() === '' || subjEl.value === memoLastSubject);

    if (bodyUntouched) { bodyEl.value = tpl.body; }
    if (subjUntouched) { subjEl.value = tpl.subject; }

    memoLastBody = bodyEl.value;
    memoLastSubject = subjEl.value;
}

<?php if (!$from_overview): ?>
/* Auto-fill name / designation / id from the typed User No. */
var memoLookupTimer = null;
var unoEl = document.getElementById('memo_user_no');
if (unoEl) {
    unoEl.addEventListener('input', function () {
        clearTimeout(memoLookupTimer);
        var v = this.value.trim();
        if (v === '') return;
        memoLookupTimer = setTimeout(function () {
            fetch('employee_memo.php?lookup=' + encodeURIComponent(v))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.found) {
                        document.getElementById('memo_emp_name').value    = d.name || '';
                        document.getElementById('memo_designation').value = d.designation || '';
                        document.getElementById('memo_emp_id').value      = d.employee_id || '';
                    }
                })
                .catch(function () {});
        }, 350);
    });
}
<?php endif; ?>
</script>
</body>
</html>
