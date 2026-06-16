<?php
include 'auth.php';
requirePermission('reports_view');

$month = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
// ── AJAX employee lookup endpoint ──
if (isset($_GET['ajax_lookup'])) {
    $lookup_no = trim($_GET['user_no'] ?? '');
    $safe = mysqli_real_escape_string($conn, $lookup_no);
    $emp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name FROM employees WHERE user_no='$safe' LIMIT 1"));
    header('Content-Type: application/json');
    echo json_encode($emp ? ['found'=>true,'name'=>$emp['full_name']] : ['found'=>false]);
    exit;
}

$user_no = trim($_GET['user_no'] ?? '');
$action = $_POST['action'] ?? '';
$msg = '';
$msg_type = '';

// ── Ensure table exists ──────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS overtime_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_no VARCHAR(50) NOT NULL,
        attendance_date DATE NOT NULL,
        ot_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
        note VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_no, attendance_date)
    )
");
// Add note column if missing
$nc = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'note'");
if ($nc && mysqli_num_rows($nc) == 0) {
    mysqli_query($conn, "ALTER TABLE overtime_records ADD note VARCHAR(255) DEFAULT '' AFTER ot_hours");
}

// ── Handle POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_user_no   = trim($_POST['post_user_no'] ?? '');
    $post_date      = trim($_POST['post_date'] ?? '');
    $post_ot_hours  = (float)($_POST['post_ot_hours'] ?? 0);
    $post_note      = trim($_POST['post_note'] ?? '');
    $post_id        = (int)($_POST['post_id'] ?? 0);

    $safe_user_no  = mysqli_real_escape_string($conn, $post_user_no);
    $safe_date     = mysqli_real_escape_string($conn, $post_date);
    $safe_ot       = (float)$post_ot_hours;
    $safe_note     = mysqli_real_escape_string($conn, $post_note);

    if ($action === 'add') {
        if ($post_user_no === '' || $post_date === '' || $post_ot_hours <= 0) {
            $msg = 'Please fill in User No, Date and OT Hours (must be > 0).';
            $msg_type = 'error';
        } else {
            // Check employee exists
            $emp_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name FROM employees WHERE user_no='$safe_user_no' LIMIT 1"));
            if (!$emp_check) {
                $msg = "User No '$post_user_no' not found in employees.";
                $msg_type = 'error';
            } else {
                $ins = mysqli_query($conn, "
                    INSERT INTO overtime_records (user_no, attendance_date, ot_hours, note)
                    VALUES ('$safe_user_no', '$safe_date', '$safe_ot', '$safe_note')
                    ON DUPLICATE KEY UPDATE ot_hours='$safe_ot', note='$safe_note'
                ");
                if ($ins) {
                    $msg = "OT record added/updated for {$emp_check['full_name']} on $post_date.";
                    $msg_type = 'success';
                } else {
                    $msg = 'Database error: ' . mysqli_error($conn);
                    $msg_type = 'error';
                }
            }
        }
    } elseif ($action === 'edit') {
        if ($post_id <= 0 || $post_ot_hours <= 0) {
            $msg = 'Invalid edit request.';
            $msg_type = 'error';
        } else {
            mysqli_query($conn, "
                UPDATE overtime_records
                SET ot_hours='$safe_ot', note='$safe_note', attendance_date='$safe_date'
                WHERE id='$post_id'
            ");
            $msg = 'OT record updated successfully.';
            $msg_type = 'success';
        }
    } elseif ($action === 'delete') {
        if ($post_id <= 0) {
            $msg = 'Invalid delete request.';
            $msg_type = 'error';
        } else {
            mysqli_query($conn, "DELETE FROM overtime_records WHERE id='$post_id'");
            $msg = 'OT record deleted.';
            $msg_type = 'warning';
        }
    }
}

// ── Queries ──────────────────────────────────────────────────────────
$safe_month  = mysqli_real_escape_string($conn, $month);
$safe_userno = mysqli_real_escape_string($conn, $user_no);

$where = "WHERE DATE_FORMAT(o.attendance_date, '%Y-%m') = '$safe_month'";
if ($user_no !== '') $where .= " AND o.user_no='$safe_userno'";

/* ─────────────────────────────────────────────
   Excel export: professional date-wise OT report with amounts.
   Per employee: OT days, each date + hours, OT rate (from basic), OT amount,
   subtotal; plus a grand total. Sunday/Holiday paid at 1.5x, else 1.25x.
───────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exp = mysqli_query($conn, "
        SELECT o.user_no, o.attendance_date, o.ot_hours, COALESCE(o.note,'') AS note,
               COALESCE(e.full_name,'') AS full_name, COALESCE(e.department,'') AS department,
               COALESCE(e.designation,'') AS designation, COALESCE(e.basic_salary,0) AS basic_salary
        FROM overtime_records o
        LEFT JOIN employees e ON e.user_no = o.user_no
        $where AND o.ot_hours > 0
        ORDER BY CAST(o.user_no AS UNSIGNED) ASC, o.attendance_date ASC
    ");

    $holiday_dates = [];
    $hq = mysqli_query($conn, "SELECT holiday_date FROM holidays WHERE DATE_FORMAT(holiday_date,'%Y-%m')='$safe_month'");
    if ($hq) { while ($h = mysqli_fetch_assoc($hq)) { $holiday_dates[$h['holiday_date']] = true; } }

    $emp_rows = [];
    if ($exp) { while ($r = mysqli_fetch_assoc($exp)) { $emp_rows[$r['user_no']][] = $r; } }

    $company  = defined('COMPANY_NAME') ? COMPANY_NAME : 'EURO TROUSERS MFG CO (FZC)';
    $fname    = 'overtime_report_' . $month . ($user_no !== '' ? '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $user_no) : '') . '.xls';
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=$fname");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo "\xEF\xBB\xBF";

    $m = function ($v) { return number_format((float)$v, 2); };
    $grand_hours = 0; $grand_amount = 0; $grand_days = 0;

    echo '<table border="1" cellspacing="0" cellpadding="5">';
    echo '<tr><td colspan="6" style="font-size:16px;font-weight:bold;background:#1a3a5c;color:#ffffff;">' . htmlspecialchars($company) . '</td></tr>';
    echo '<tr><td colspan="6" style="font-weight:bold;font-size:13px;">Employee Overtime Report &mdash; ' . htmlspecialchars($month_label) . '</td></tr>';
    echo '<tr><td colspan="6">Generated: ' . date('d-m-Y h:i A') . '</td></tr>';

    if (empty($emp_rows)) {
        echo '<tr><td colspan="6">No overtime records for this period.</td></tr>';
    } else {
        foreach ($emp_rows as $uno => $rows) {
            $info   = $rows[0];
            $basic  = (float)$info['basic_salary'];
            $hourly = $basic > 0 ? ($basic / 30 / 8) : 0;
            $rate_n = round($hourly * 1.25, 2);
            $rate_s = round($hourly * 1.50, 2);

            echo '<tr><td colspan="6"></td></tr>';
            echo '<tr style="background:#2563a8;color:#ffffff;font-weight:bold;">'
               . '<td>User No: ' . htmlspecialchars($uno) . '</td>'
               . '<td colspan="2">' . htmlspecialchars($info['full_name']) . '</td>'
               . '<td>Dept: ' . htmlspecialchars($info['department']) . '</td>'
               . '<td>' . htmlspecialchars($info['designation']) . '</td>'
               . '<td>Basic: ' . $m($basic) . ' AED</td></tr>';
            echo '<tr style="background:#eef3fb;"><td colspan="6">OT Rate / hour &mdash; Normal: ' . $m($rate_n) . ' AED&nbsp;&nbsp;|&nbsp;&nbsp;Sunday / Holiday: ' . $m($rate_s) . ' AED</td></tr>';
            echo '<tr style="background:#1a3a5c;color:#ffffff;font-weight:bold;">'
               . '<td>Date</td><td>Day</td><td>Type</td><td>OT Hours</td><td>OT Rate</td><td>OT Amount (AED)</td></tr>';

            $emp_hours = 0; $emp_amount = 0;
            foreach ($rows as $d) {
                $date  = $d['attendance_date'];
                $day   = date('l', strtotime($date));
                $isSun = ($day === 'Sunday');
                $isHol = isset($holiday_dates[$date]);
                $type  = $isHol ? 'Holiday' : ($isSun ? 'Sunday' : 'Normal');
                $rate  = ($isSun || $isHol) ? $rate_s : $rate_n;
                $hours = (float)$d['ot_hours'];
                $amount = round($hours * $rate, 2);
                $emp_hours += $hours; $emp_amount += $amount;
                echo '<tr>'
                   . '<td>' . date('d-m-Y', strtotime($date)) . '</td>'
                   . '<td>' . $day . '</td>'
                   . '<td>' . $type . '</td>'
                   . '<td>' . number_format($hours, 2) . '</td>'
                   . '<td>' . $m($rate) . '</td>'
                   . '<td>' . $m($amount) . '</td></tr>';
            }
            $days = count($rows);
            echo '<tr style="background:#fff3cd;font-weight:bold;">'
               . '<td colspan="3">Total &mdash; ' . $days . ' OT day(s)</td>'
               . '<td>' . number_format($emp_hours, 2) . '</td>'
               . '<td>Total AED</td>'
               . '<td>' . $m($emp_amount) . '</td></tr>';

            $grand_hours += $emp_hours; $grand_amount += $emp_amount; $grand_days += $days;
        }
        echo '<tr><td colspan="6"></td></tr>';
        echo '<tr style="background:#1a3a5c;color:#ffffff;font-weight:bold;">'
           . '<td colspan="3">GRAND TOTAL (' . $grand_days . ' OT day(s))</td>'
           . '<td>' . number_format($grand_hours, 2) . '</td>'
           . '<td></td>'
           . '<td>' . $m($grand_amount) . '</td></tr>';
    }
    echo '</table>';
    exit;
}

$summary_result = mysqli_query($conn, "
    SELECT o.user_no, COALESCE(e.full_name,'') AS full_name,
           SUM(o.ot_hours) AS total_ot
    FROM overtime_records o
    LEFT JOIN employees e ON e.user_no = o.user_no
    $where
    GROUP BY o.user_no, e.full_name
    ORDER BY CAST(o.user_no AS UNSIGNED) ASC
");

$details_result = mysqli_query($conn, "
    SELECT o.id, o.user_no, o.attendance_date, o.ot_hours,
           COALESCE(o.note,'') AS note
    FROM overtime_records o
    $where AND o.ot_hours > 0
    ORDER BY o.user_no ASC, o.attendance_date ASC
");

$details = [];
if ($details_result) {
    while ($row = mysqli_fetch_assoc($details_result)) {
        $details[$row['user_no']][] = $row;
    }
}

$total_month_ot = 0;
$month_label = date('F Y', strtotime($month . '-01'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OT Report — <?php echo htmlspecialchars($month_label); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --brand:      #1a3a5c;
    --brand-mid:  #2563a8;
    --accent:     #e8a020;
    --green:      #16a34a;
    --green-soft: #dcfce7;
    --red:        #dc2626;
    --red-soft:   #fee2e2;
    --yellow:     #d97706;
    --yellow-soft:#fef9c3;
    --gray-50:    #f8fafc;
    --gray-100:   #f1f5f9;
    --gray-200:   #e2e8f0;
    --gray-400:   #94a3b8;
    --gray-600:   #475569;
    --gray-800:   #1e293b;
    --radius:     8px;
    --shadow:     0 2px 12px rgba(0,0,0,0.10);
}
body { font-family:'Segoe UI',Arial,sans-serif; background:var(--gray-100); color:var(--gray-800); font-size:14px; min-height:100vh; }

/* ── Topbar ── */
.topbar {
    position:sticky; top:0; z-index:100;
    background:var(--brand); color:#fff;
    display:flex; align-items:center; justify-content:space-between;
    padding:0 24px; height:54px;
    box-shadow:0 2px 10px rgba(0,0,0,0.22);
}
.topbar-left { display:flex; align-items:center; gap:12px; }
.logo { font-size:15px; font-weight:700; letter-spacing:0.03em; }
.logo span { color:var(--accent); }
.tbtn {
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(255,255,255,0.12); color:#fff;
    border:1px solid rgba(255,255,255,0.25);
    padding:6px 14px; border-radius:6px;
    text-decoration:none; font-size:13px; font-weight:500; cursor:pointer;
    transition:background 0.15s;
}
.tbtn:hover { background:rgba(255,255,255,0.22); }
.tbtn.accent { background:var(--accent); color:#1a1a1a; border-color:var(--accent); }

/* ── Page ── */
.page { padding:24px; }
.page-title { font-size:22px; font-weight:700; color:var(--brand); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
.page-title .icon { font-size:24px; }

/* ── Alert ── */
.alert {
    padding:12px 18px; border-radius:var(--radius);
    margin-bottom:18px; font-weight:600; font-size:13px;
    display:flex; align-items:center; gap:8px;
}
.alert-success { background:var(--green-soft); color:var(--green); border:1px solid #86efac; }
.alert-error   { background:var(--red-soft);   color:var(--red);   border:1px solid #fca5a5; }
.alert-warning { background:var(--yellow-soft); color:var(--yellow); border:1px solid #fde68a; }

/* ── Cards ── */
.top-grid { display:grid; grid-template-columns:1fr 340px; gap:20px; margin-bottom:20px; }
@media(max-width:900px){ .top-grid { grid-template-columns:1fr; } }

.card {
    background:#fff; border-radius:var(--radius);
    box-shadow:var(--shadow); overflow:hidden;
}
.card-header {
    background:var(--brand); color:#fff;
    padding:12px 18px; font-size:14px; font-weight:700;
    display:flex; align-items:center; gap:8px;
}
.card-body { padding:18px; }

/* ── Filter ── */
.filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.fgroup { display:flex; flex-direction:column; gap:4px; }
.fgroup label { font-size:11px; font-weight:600; color:var(--gray-600); text-transform:uppercase; letter-spacing:0.05em; }
.fgroup input, .fgroup select {
    height:38px; border:1.5px solid var(--gray-200);
    border-radius:6px; padding:0 10px; font-size:13px;
    color:var(--gray-800); background:var(--gray-50); outline:none;
    transition:border-color 0.15s;
}
.fgroup input:focus, .fgroup select:focus { border-color:var(--brand-mid); background:#fff; }

/* ── Buttons ── */
.btn {
    display:inline-flex; align-items:center; gap:6px;
    height:38px; padding:0 16px; border-radius:6px;
    font-size:13px; font-weight:600; border:none; cursor:pointer;
    text-decoration:none; white-space:nowrap;
    transition:opacity 0.15s, transform 0.1s;
}
.btn:hover { opacity:0.88; transform:translateY(-1px); }
.btn-primary  { background:var(--brand-mid); color:#fff; }
.btn-success  { background:var(--green);     color:#fff; }
.btn-danger   { background:var(--red);       color:#fff; }
.btn-warning  { background:var(--yellow);    color:#fff; }
.btn-gray     { background:var(--gray-600);  color:#fff; }
.btn-sm       { height:30px; padding:0 10px; font-size:12px; }

/* ── Add form ── */
.add-form { display:flex; flex-direction:column; gap:14px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:11px; font-weight:700; color:var(--gray-600); text-transform:uppercase; letter-spacing:0.04em; }
.form-group input, .form-group textarea {
    border:1.5px solid var(--gray-200); border-radius:6px;
    padding:8px 10px; font-size:13px; color:var(--gray-800);
    background:var(--gray-50); outline:none; transition:border-color 0.15s;
    width:100%;
}
.form-group input:focus, .form-group textarea:focus { border-color:var(--brand-mid); background:#fff; }
.form-group textarea { resize:vertical; min-height:60px; font-family:inherit; }
.emp-preview {
    background:var(--green-soft); color:var(--green);
    border:1px solid #86efac; border-radius:6px;
    padding:6px 10px; font-size:12px; font-weight:600;
    min-height:32px; display:flex; align-items:center;
}
.emp-preview.error { background:var(--red-soft); color:var(--red); border-color:#fca5a5; }

/* ── Summary card ── */
.summary-pill {
    display:flex; justify-content:space-between; align-items:center;
    background:var(--gray-50); border:1px solid var(--gray-200);
    border-radius:var(--radius); padding:14px 18px; margin-bottom:12px;
}
.summary-pill .label { font-size:12px; color:var(--gray-600); font-weight:600; }
.summary-pill .value { font-size:24px; font-weight:800; color:var(--brand); }
.summary-pill .sub   { font-size:11px; color:var(--gray-400); margin-top:2px; }

/* ── Table ── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead th {
    background:var(--brand); color:#fff; font-weight:600;
    padding:10px 12px; text-align:center; white-space:nowrap;
    border-right:1px solid rgba(255,255,255,0.12);
    font-size:12px; text-transform:uppercase; letter-spacing:0.03em;
}
thead th:last-child { border-right:none; }
thead th.th-left { text-align:left; padding-left:16px; }
tbody tr { border-bottom:1px solid var(--gray-200); }
tbody tr:last-child { border-bottom:none; }
tbody tr:nth-child(even) { background:var(--gray-50); }
tbody tr:hover > td { background:#eef3fb !important; }
tbody td { padding:9px 12px; text-align:center; vertical-align:middle; }
tbody td.td-left { text-align:left; padding-left:16px; }
.userno-badge {
    display:inline-block; background:var(--brand-mid);
    color:#fff; border-radius:5px; padding:2px 8px;
    font-size:12px; font-weight:700;
}
.ot-total {
    display:inline-block; background:var(--accent);
    color:#1a1a1a; border-radius:12px; padding:3px 12px;
    font-size:13px; font-weight:800; cursor:pointer;
}
.ot-total:hover { opacity:0.85; }
.chevron { font-size:10px; margin-left:4px; transition:transform 0.2s; display:inline-block; }
.chevron.open { transform:rotate(180deg); }

/* ── Detail sub-table ── */
.detail-row { display:none; }
.detail-row.open { display:table-row; }
.detail-cell { padding:0 !important; background:#f0f5ff !important; }
.detail-inner { padding:14px 20px; }
.detail-inner h4 { font-size:13px; color:var(--brand); margin-bottom:10px; font-weight:700; }
.detail-table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; border-radius:6px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,0.08); }
.detail-table th { background:#3a5f8a; color:#fff; padding:7px 10px; text-align:center; font-size:11px; text-transform:uppercase; }
.detail-table th.th-left { text-align:left; padding-left:12px; }
.detail-table td { padding:7px 10px; text-align:center; border-bottom:1px solid #e8edf5; }
.detail-table td.td-left { text-align:left; padding-left:12px; color:var(--gray-600); }
.detail-table tr:last-child td { border-bottom:none; }
.detail-table tr:hover td { background:#f0f7ff; }
.note-text { color:var(--gray-600); font-style:italic; font-size:11px; }

/* ── Edit modal ── */
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,0.5); z-index:200;
    align-items:center; justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal {
    background:#fff; border-radius:var(--radius);
    box-shadow:0 8px 32px rgba(0,0,0,0.2);
    padding:24px; width:100%; max-width:420px;
    animation:slideIn 0.18s ease;
}
@keyframes slideIn { from { transform:translateY(-20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal h3 { font-size:17px; color:var(--brand); margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.modal-actions { display:flex; gap:10px; margin-top:18px; justify-content:flex-end; }

/* ── Empty ── */
.empty { padding:40px; text-align:center; color:var(--gray-400); font-size:14px; }
.empty .eicon { font-size:40px; margin-bottom:8px; }

@media print {
    .topbar, .top-grid > .card:last-child, .btn, .ot-total { }
    body { background:#fff; }
    @page { size:A4 landscape; margin:10mm; }
}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="tbtn">&#8592; Dashboard</a>
        <a href="ot_upload.php"  class="tbtn">&#8679; OT Upload</a>
        <?php echo company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;'); ?>
        <span class="logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
    <div>
        <button onclick="window.print()" class="tbtn">&#128438; Print</button>
    </div>
</div>

<div class="page">
<div class="page-title"><span class="icon">&#9201;</span> Overtime Report — <?php echo htmlspecialchars($month_label); ?></div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?>">
    <?php echo $msg_type === 'success' ? '&#10003;' : ($msg_type === 'error' ? '&#10005;' : '&#9888;'); ?>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="top-grid">

    <!-- Left: filter + table -->
    <div style="display:flex;flex-direction:column;gap:20px;">

        <!-- Filter card -->
        <div class="card">
            <div class="card-header">&#128269; Search / Filter</div>
            <div class="card-body">
                <form method="GET">
                    <div class="filter-row">
                        <div class="fgroup">
                            <label>Month</label>
                            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>">
                        </div>
                        <div class="fgroup">
                            <label>User No</label>
                            <input type="text" name="user_no" value="<?php echo htmlspecialchars($user_no); ?>" placeholder="All employees" style="width:160px;">
                        </div>
                        <button type="submit" class="btn btn-primary" style="align-self:flex-end;">&#128269; Search</button>
                        <a href="overtime_report.php" class="btn btn-gray" style="align-self:flex-end;">&#10005; Reset</a>
                        <a href="?month=<?php echo htmlspecialchars($month); ?>&user_no=<?php echo htmlspecialchars($user_no); ?>&export=excel" class="btn btn-success" style="align-self:flex-end;">&#8659; Download Excel</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary table card -->
        <div class="card">
            <div class="card-header">&#128202; Employee Wise Monthly OT Summary</div>
            <div class="table-wrap">
            <table>
            <thead>
                <tr>
                    <th style="width:44px;">SL</th>
                    <th>User No</th>
                    <th class="th-left">Employee Name</th>
                    <th>Total OT Hours</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sl = 1;
            $has_rows = false;
            if ($summary_result && mysqli_num_rows($summary_result) > 0):
                while ($row = mysqli_fetch_assoc($summary_result)):
                    $has_rows = true;
                    $u = $row['user_no'];
                    $total_ot = (float)$row['total_ot'];
                    $total_month_ot += $total_ot;
                    $did = 'd_' . preg_replace('/[^A-Za-z0-9]/', '_', $u);
            ?>
            <tr>
                <td style="color:var(--gray-400);font-size:12px;"><?php echo $sl++; ?></td>
                <td><span class="userno-badge"><?php echo htmlspecialchars($u); ?></span></td>
                <td class="td-left" style="font-weight:600;"><?php echo htmlspecialchars($row['full_name']); ?></td>
                <td>
                    <span class="ot-total" onclick="toggleDetail('<?php echo $did; ?>')">
                        <?php echo number_format($total_ot, 2); ?> hrs
                        <span class="chevron" id="chev_<?php echo $did; ?>">&#9660;</span>
                    </span>
                </td>
                <td>
                    <button class="btn btn-success btn-sm"
                        onclick="openAdd('<?php echo htmlspecialchars($u, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>')">
                        + Add OT
                    </button>
                    <a class="btn btn-primary btn-sm"
                       href="overtime_report_print.php?user_no=<?php echo urlencode($u); ?>&month=<?php echo urlencode($month); ?>"
                       target="_blank" rel="noopener">
                        &#128438; Report
                    </a>
                </td>
            </tr>
            <tr id="<?php echo $did; ?>" class="detail-row">
                <td colspan="5" class="detail-cell">
                    <div class="detail-inner">
                        <h4>&#128197; Date-wise OT Details — <?php echo htmlspecialchars($row['full_name']); ?></h4>
                        <table class="detail-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>OT Hours</th>
                                <th class="th-left">Note</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($details[$u])): ?>
                            <?php foreach ($details[$u] as $d):
                                $day_name = date('l', strtotime($d['attendance_date']));
                                $is_sunday = $day_name === 'Sunday';
                            ?>
                            <tr>
                                <td><?php echo date('d-m-Y', strtotime($d['attendance_date'])); ?></td>
                                <td style="<?php echo $is_sunday ? 'color:#e8a020;font-weight:700;' : ''; ?>"><?php echo $day_name; ?></td>
                                <td><strong><?php echo number_format((float)$d['ot_hours'], 2); ?></strong></td>
                                <td class="td-left"><span class="note-text"><?php echo htmlspecialchars($d['note'] ?? ''); ?></span></td>
                                <td style="white-space:nowrap;">
                                    <button class="btn btn-warning btn-sm"
                                        onclick="openEdit(<?php echo $d['id']; ?>,'<?php echo htmlspecialchars($u,ENT_QUOTES); ?>','<?php echo $d['attendance_date']; ?>',<?php echo $d['ot_hours']; ?>,'<?php echo htmlspecialchars($d['note']??'',ENT_QUOTES); ?>')">
                                        &#9998; Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm"
                                        onclick="confirmDelete(<?php echo $d['id']; ?>, '<?php echo date('d-m-Y', strtotime($d['attendance_date'])); ?>')">
                                        &#128465; Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="empty">No OT days found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="5"><div class="empty"><div class="eicon">&#128203;</div>No OT records found for <?php echo htmlspecialchars($month_label); ?>.</div></td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="3" style="text-align:right;font-weight:700;padding:12px 16px;background:var(--brand);color:#fff;font-size:13px;">
                    Total OT Hours — <?php echo htmlspecialchars($month_label); ?>
                </td>
                <td style="background:var(--accent);color:#1a1a1a;font-weight:800;font-size:16px;text-align:center;">
                    <?php echo number_format($total_month_ot, 2); ?>
                </td>
                <td style="background:var(--brand);"></td>
            </tr>
            </tfoot>
            </table>
            </div>
        </div>

    </div><!-- /left -->

    <!-- Right: Add OT form -->
    <div>
        <div class="card" id="addCard">
            <div class="card-header">&#43; Add OT Record</div>
            <div class="card-body">
                <form method="POST" class="add-form" id="addForm">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
                    <input type="hidden" name="user_no" value="<?php echo htmlspecialchars($user_no); ?>">

                    <div class="form-group">
                        <label>User No *</label>
                        <input type="text" name="post_user_no" id="addUserNo"
                            placeholder="e.g. 1023"
                            value=""
                            oninput="lookupEmployee(this.value)"
                            required>
                        <div class="emp-preview" id="empPreview">Enter a User No to look up employee</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" name="post_date" id="addDate"
                                value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>OT Hours *</label>
                            <input type="number" name="post_ot_hours" id="addHours"
                                min="0.5" max="24" step="0.5"
                                placeholder="e.g. 2.5" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Note (optional)</label>
                        <textarea name="post_note" placeholder="Reason / remarks..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success" style="width:100%;">&#43; Save OT Record</button>
                </form>

                <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--gray-200);">
                    <p style="font-size:11px;color:var(--gray-400);line-height:1.7;">
                        &#9432; If a record already exists for the same user + date, it will be <strong>overwritten</strong>.<br>
                        OT hours use decimal format: 1.5 = 1 hr 30 min.
                    </p>
                </div>
            </div>
        </div>
    </div><!-- /right -->

</div><!-- /top-grid -->
</div><!-- /page -->

<!-- ── Edit Modal ── -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>&#9998; Edit OT Record</h3>
        <form method="POST" class="add-form">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="month"  value="<?php echo htmlspecialchars($month); ?>">
            <input type="hidden" name="user_no" value="<?php echo htmlspecialchars($user_no); ?>">
            <input type="hidden" name="post_id" id="editId">

            <div class="form-group">
                <label>User No</label>
                <input type="text" id="editUserNo" readonly style="background:#eee;">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="post_date" id="editDate" required>
                </div>
                <div class="form-group">
                    <label>OT Hours *</label>
                    <input type="number" name="post_ot_hours" id="editHours" min="0.5" max="24" step="0.5" required>
                </div>
            </div>
            <div class="form-group">
                <label>Note</label>
                <textarea name="post_note" id="editNote" placeholder="Reason / remarks..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-success">&#10003; Update</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete Modal ── -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <h3 style="color:var(--red);">&#128465; Delete OT Record</h3>
        <p style="color:var(--gray-600);margin-bottom:6px;">Are you sure you want to delete the OT record for:</p>
        <p style="font-weight:700;font-size:16px;color:var(--brand);margin-bottom:18px;" id="deleteLabel"></p>
        <form method="POST">
            <input type="hidden" name="action"  value="delete">
            <input type="hidden" name="month"   value="<?php echo htmlspecialchars($month); ?>">
            <input type="hidden" name="user_no" value="<?php echo htmlspecialchars($user_no); ?>">
            <input type="hidden" name="post_id" id="deleteId">
            <div class="modal-actions">
                <button type="button" class="btn btn-gray" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">&#128465; Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Detail toggle ──
function toggleDetail(id) {
    var row  = document.getElementById(id);
    var chev = document.getElementById('chev_' + id);
    if (!row) return;
    var open = row.classList.toggle('open');
    if (chev) chev.classList.toggle('open', open);
}

// ── Employee lookup ──
var lookupTimer = null;
function lookupEmployee(val) {
    val = val.trim();
    var preview = document.getElementById('empPreview');
    clearTimeout(lookupTimer);
    if (val === '') {
        preview.className = 'emp-preview';
        preview.textContent = 'Enter a User No to look up employee';
        return;
    }
    preview.textContent = 'Looking up...';
    preview.className = 'emp-preview';
    lookupTimer = setTimeout(function() {
        fetch('overtime_report.php?ajax_lookup=1&user_no=' + encodeURIComponent(val))
            .then(r => r.json())
            .then(data => {
                if (data.found) {
                    preview.className = 'emp-preview';
                    preview.textContent = '✓ ' + data.name;
                } else {
                    preview.className = 'emp-preview error';
                    preview.textContent = '✗ Employee not found';
                }
            }).catch(() => {
                preview.className = 'emp-preview error';
                preview.textContent = 'Lookup failed';
            });
    }, 400);
}

// ── Open Add card pre-filled ──
function openAdd(userNo, name) {
    var inp     = document.getElementById('addUserNo');
    var preview = document.getElementById('empPreview');
    inp.value   = userNo;
    preview.className   = 'emp-preview';
    preview.textContent = '✓ ' + name;
    document.getElementById('addCard').scrollIntoView({ behavior:'smooth', block:'start' });
    document.getElementById('addHours').focus();
}

// ── Edit modal ──
function openEdit(id, userNo, date, hours, note) {
    document.getElementById('editId').value     = id;
    document.getElementById('editUserNo').value = userNo;
    document.getElementById('editDate').value   = date;
    document.getElementById('editHours').value  = hours;
    document.getElementById('editNote').value   = note;
    document.getElementById('editModal').classList.add('open');
}

// ── Delete modal ──
function confirmDelete(id, dateLabel) {
    document.getElementById('deleteId').value   = id;
    document.getElementById('deleteLabel').textContent = 'Date: ' + dateLabel;
    document.getElementById('deleteModal').classList.add('open');
}

function closeModal() {
    document.getElementById('editModal').classList.remove('open');
    document.getElementById('deleteModal').classList.remove('open');
}

// Close modal on overlay click
['editModal','deleteModal'].forEach(function(id) {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
});

// ── Auto-open detail if only one employee ──
<?php
if (isset($summary_result)) {
    mysqli_data_seek($summary_result, 0);
    $rows = [];
    while ($r = mysqli_fetch_assoc($summary_result)) $rows[] = $r;
    if (count($rows) === 1) {
        $only_u = 'd_' . preg_replace('/[^A-Za-z0-9]/', '_', $rows[0]['user_no']);
        echo "toggleDetail('$only_u');";
    }
}
?>
</script>


</body>
</html>
