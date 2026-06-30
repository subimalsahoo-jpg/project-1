<?php
include 'auth.php';
include_once 'advance_helper.php';
require_once 'accommodation_helper.php';
require_once 'department_helper.php';
requirePermission('employee_view');
payroll_ensure_advance_schema($conn);
acc_ensure_schema($conn);

/* Air-ticket & Visa-renewal history tables (auto-created). */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS employee_airtickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_no VARCHAR(50) NOT NULL,
    employee_id VARCHAR(50) DEFAULT '',
    provided_by VARCHAR(20) DEFAULT 'Company',
    from_location VARCHAR(120) DEFAULT '',
    to_location VARCHAR(120) DEFAULT '',
    travel_date DATE NULL,
    return_date DATE NULL,
    airline VARCHAR(120) DEFAULT '',
    ticket_no VARCHAR(80) DEFAULT '',
    amount DECIMAL(10,2) DEFAULT 0,
    remarks VARCHAR(255) DEFAULT '',
    created_by VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS employee_visa_renewals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_no VARCHAR(50) NOT NULL,
    employee_id VARCHAR(50) DEFAULT '',
    renew_from DATE NULL,
    renew_to DATE NULL,
    cost DECIMAL(10,2) DEFAULT 0,
    remarks VARCHAR(255) DEFAULT '',
    created_by VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Employee documents (Visa / Emirates ID / Passport / Other) — pdf or image. */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_no VARCHAR(50) NOT NULL,
    employee_id VARCHAR(50) DEFAULT '',
    doc_type VARCHAR(50) DEFAULT 'Other',
    doc_number VARCHAR(100) DEFAULT '',
    file_name VARCHAR(255) DEFAULT '',
    original_name VARCHAR(255) DEFAULT '',
    file_ext VARCHAR(10) DEFAULT '',
    remarks VARCHAR(255) DEFAULT '',
    created_by VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ─── Auto-add missing columns (employees) ────────────────────────────────────
$extra_columns = [
    "nationality         VARCHAR(100) DEFAULT ''",
    "marital_status      VARCHAR(50)  DEFAULT ''",
    "passport            VARCHAR(100) DEFAULT ''",
    "passport_number     VARCHAR(100) DEFAULT ''",
    "passport_issue      DATE NULL",
    "passport_expiry     DATE NULL",
    "emirates_id_number  VARCHAR(100) DEFAULT ''",
    "saif_zone_id        VARCHAR(100) DEFAULT ''",
    "visa_id_number      VARCHAR(100) DEFAULT ''",
    "visa_issuing_date   DATE NULL",
    "visa_expiry_date    DATE NULL",
    "uid_number          VARCHAR(100) DEFAULT ''",
    "insurance_number    VARCHAR(100) DEFAULT ''",
    "insurance_issuing_date DATE NULL",
    "insurance_expiry_date DATE NULL",
    "home_phone          VARCHAR(50)  DEFAULT ''",
    "previous_company    VARCHAR(150) DEFAULT ''",
    "resign_date         DATE NULL",
];
foreach ($extra_columns as $col) {
    $col_name = explode(" ", trim($col))[0];
    $check = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE '$col_name'");
    if (mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE employees ADD $col");
    }
}

// ─── Auto-add missing columns (employee_salary_records) ──────────────────────
$salary_extra_columns = [
    "advance_date    DATE NULL",
    "penalty_amount  DECIMAL(10,2) NOT NULL DEFAULT 0",
    "salary_status   VARCHAR(20)   NOT NULL DEFAULT 'Unpaid'",
];
foreach ($salary_extra_columns as $col) {
    $col_name = explode(" ", trim($col))[0];
    $check = mysqli_query($conn, "SHOW COLUMNS FROM employee_salary_records LIKE '$col_name'");
    if (mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "ALTER TABLE employee_salary_records ADD $col");
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function val(array $row, string $key): string {
    return htmlspecialchars($row[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

function fmt(float $n): string {
    return number_format($n, 2);
}

/**
 * Sum OT hours for a user in a given YYYY-MM month.
 */
function monthly_ot_hours($conn, string $user_no, string $employee_id, string $month): float {
    $t = mysqli_query($conn, "SHOW TABLES LIKE 'overtime_records'");
    if (!$t || mysqli_num_rows($t) === 0) return 0.0;

    $hc = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'ot_hours'");
    $dc = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'attendance_date'");
    if (!$hc || mysqli_num_rows($hc) === 0 || !$dc || mysqli_num_rows($dc) === 0) return 0.0;

    $su = mysqli_real_escape_string($conn, $user_no);
    $sm = mysqli_real_escape_string($conn, $month);
    $cond = "user_no='$su'";

    $ec = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'employee_id'");
    if ($ec && mysqli_num_rows($ec) > 0 && $employee_id !== '') {
        $se = mysqli_real_escape_string($conn, $employee_id);
        $cond = "(user_no='$su' OR employee_id='$se')";
    }

    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(ot_hours), 0) AS total
        FROM overtime_records
        WHERE $cond
          AND DATE_FORMAT(attendance_date, '%Y-%m') = '$sm'
    "));
    return (float)($row['total'] ?? 0);
}

// ─── Save employee ────────────────────────────────────────────────────────────
$message  = '';
$employee = null;
$isViewerRole = strtolower(current_user_role()) === 'viewer';
$canEditEmployee = hasPermission('employee_add') && !$isViewerRole;

if (isset($_POST['save_employee']) && !$canEditEmployee) {
    $message = "<div class='msg error'><span>!</span> You can view employee details, but you do not have permission to edit or save.</div>";
}

if (isset($_POST['save_employee']) && $canEditEmployee) {
    $id = intval($_POST['id']);

    $fields = [
        'user_no','card_no','full_name','department','gender','designation',
        'birthday','joining_date','phone','home_phone','address','nationality',
        'marital_status','passport','passport_issue','passport_expiry',
        'emirates_id_number','saif_zone_id','visa_id_number',
        'visa_issuing_date','visa_expiry_date','uid_number',
        'insurance_number','insurance_issuing_date','insurance_expiry_date','email',
        'previous_company','resign_date','employee_status',
    ];

    // Use prepared statement to avoid SQL injection
    $set_parts = [];
    $bind_types = '';
    $bind_vals  = [];

    foreach ($fields as $f) {
        $set_parts[] = "$f = ?";
        $bind_types .= 's';
        $bind_vals[] = $_POST[$f] ?? '';
    }

    // Photo upload
    $photo_sql = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['name'] !== '') {
        $allowed_ext = ['jpg','jpeg','png','gif','webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            if (!is_dir("uploads")) mkdir("uploads", 0755, true);
            $photo = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['photo']['name']));
            if (move_uploaded_file($_FILES['photo']['tmp_name'], "uploads/" . $photo)) {
                $photo_sql = ", photo = '$photo'";
            }
        }
    }

    $stmt = mysqli_prepare($conn, "UPDATE employees SET " . implode(', ', $set_parts) . "$photo_sql WHERE id = ?");
    $bind_types .= 'i';
    $bind_vals[] = $id;
    mysqli_stmt_bind_param($stmt, $bind_types, ...$bind_vals);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Auto-mark Resigned when the resign date has passed (status isn't a
    // field on this form, so set it here). Keeps an explicit departed
    // status (Absconding/Terminated/End of Contract) untouched.
    $ov_rd = trim($_POST['resign_date'] ?? '');
    if ($ov_rd !== '' && $ov_rd !== '0000-00-00' && $ov_rd <= date('Y-m-d')) {
        mysqli_query($conn, "UPDATE employees SET employee_status='Resigned'
            WHERE id=" . (int)$id . "
            AND (employee_status IS NULL OR employee_status='' OR LOWER(employee_status)='active')");
    }

    $message = "<div class='msg success'><span>✓</span> Employee Details Updated Successfully</div>";
}

/* ─── Air-ticket & Visa-renewal actions ─────────────────────────────────────── */
$ov_user = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User')));

if ($canEditEmployee && isset($_POST['save_airticket'])) {
    $u  = mysqli_real_escape_string($conn, trim($_POST['user_no'] ?? ''));
    $ei = mysqli_real_escape_string($conn, trim($_POST['employee_id'] ?? ''));
    $pb = mysqli_real_escape_string($conn, in_array($_POST['provided_by'] ?? 'Company', ['Company','Self','Other'], true) ? $_POST['provided_by'] : 'Company');
    $fl = mysqli_real_escape_string($conn, trim($_POST['from_location'] ?? ''));
    $tl = mysqli_real_escape_string($conn, trim($_POST['to_location'] ?? ''));
    $td = mysqli_real_escape_string($conn, normalize_input_date($_POST['travel_date'] ?? ''));
    $rd = mysqli_real_escape_string($conn, normalize_input_date($_POST['return_date'] ?? ''));
    $al = mysqli_real_escape_string($conn, trim($_POST['airline'] ?? ''));
    $tn = mysqli_real_escape_string($conn, trim($_POST['ticket_no'] ?? ''));
    $am = (float)($_POST['amount'] ?? 0);
    $rk = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $cb = mysqli_real_escape_string($conn, $ov_user);
    if ($u !== '') {
        mysqli_query($conn, "INSERT INTO employee_airtickets
            (user_no, employee_id, provided_by, from_location, to_location, travel_date, return_date, airline, ticket_no, amount, remarks, created_by)
            VALUES ('$u','$ei','$pb','$fl','$tl'," . ($td !== '' ? "'$td'" : "NULL") . "," . ($rd !== '' ? "'$rd'" : "NULL") . ",'$al','$tn','$am','$rk','$cb')");
        $message = "<div class='msg success'><span>✓</span> Air ticket added.</div>";
    }
}
if ($canEditEmployee && isset($_POST['delete_airticket'])) {
    $aid = (int)$_POST['delete_airticket'];
    if ($aid > 0) { mysqli_query($conn, "DELETE FROM employee_airtickets WHERE id=$aid"); }
    $message = "<div class='msg success'><span>✓</span> Air ticket removed.</div>";
}
if ($canEditEmployee && isset($_POST['save_visarenewal'])) {
    $u  = mysqli_real_escape_string($conn, trim($_POST['user_no'] ?? ''));
    $ei = mysqli_real_escape_string($conn, trim($_POST['employee_id'] ?? ''));
    $rf = mysqli_real_escape_string($conn, normalize_input_date($_POST['renew_from'] ?? ''));
    $rt = mysqli_real_escape_string($conn, normalize_input_date($_POST['renew_to'] ?? ''));
    $co = (float)($_POST['cost'] ?? 0);
    $rk = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $cb = mysqli_real_escape_string($conn, $ov_user);
    if ($u !== '' && ($rf !== '' || $rt !== '')) {
        mysqli_query($conn, "INSERT INTO employee_visa_renewals
            (user_no, employee_id, renew_from, renew_to, cost, remarks, created_by)
            VALUES ('$u','$ei'," . ($rf !== '' ? "'$rf'" : "NULL") . "," . ($rt !== '' ? "'$rt'" : "NULL") . ",'$co','$rk','$cb')");
        $message = "<div class='msg success'><span>✓</span> Visa renewal added.</div>";
    } else {
        $message = "<div class='msg error'><span>!</span> Please provide the renewal dates.</div>";
    }
}
if ($canEditEmployee && isset($_POST['delete_visarenewal'])) {
    $vid = (int)$_POST['delete_visarenewal'];
    if ($vid > 0) { mysqli_query($conn, "DELETE FROM employee_visa_renewals WHERE id=$vid"); }
    $message = "<div class='msg success'><span>✓</span> Visa renewal removed.</div>";
}
/* Document types (alphabetical, with "Other" last) — shared by the upload
   handler and the dropdown in the Documents tab. */
$document_type_options = ['Contract Paper','Emirates ID','Entry Permit','Insurance','Medical Certificate','Old Emirates ID','Old Visa','Passport','Photo','Saif Zone ID','Status Change Paper','Visa','Visit Visa'];
sort($document_type_options);
$document_type_options[] = 'Other';

if ($canEditEmployee && isset($_POST['save_document'])) {
    $u  = mysqli_real_escape_string($conn, trim($_POST['user_no'] ?? ''));
    $ei = mysqli_real_escape_string($conn, trim($_POST['employee_id'] ?? ''));
    $dt_raw = in_array(($_POST['doc_type'] ?? 'Other'), $document_type_options, true) ? $_POST['doc_type'] : 'Other';
    $dt = mysqli_real_escape_string($conn, $dt_raw);
    $dn = mysqli_real_escape_string($conn, trim($_POST['doc_number'] ?? ''));
    $rk = mysqli_real_escape_string($conn, trim($_POST['doc_remarks'] ?? ''));
    $cb = mysqli_real_escape_string($conn, $ov_user);
    if ($u === '') {
        $message = "<div class='msg error'><span>!</span> Employee not selected.</div>";
    } elseif (!isset($_FILES['doc_file']) || $_FILES['doc_file']['name'] === '' || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "<div class='msg error'><span>!</span> Please choose a PDF or image file to upload.</div>";
    } else {
        $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            $message = "<div class='msg error'><span>!</span> Only PDF, JPG or PNG files are allowed.</div>";
        } elseif ((int)$_FILES['doc_file']['size'] > 10 * 1024 * 1024) {
            $message = "<div class='msg error'><span>!</span> File too large (max 10 MB).</div>";
        } else {
            if (!is_dir("uploads/documents")) { mkdir("uploads/documents", 0755, true); }
            $orig = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['doc_file']['name']));
            $dt_slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $dt_raw), '-');
            if ($dt_slug === '') { $dt_slug = 'Document'; }
            $stored = $dt_slug . '_' . trim($_POST['user_no'] ?? '') . '_' . time() . '_' . mt_rand(100, 999) . '.' . $ext;
            $stored = preg_replace('/[^a-zA-Z0-9._-]/', '_', $stored);
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], "uploads/documents/" . $stored)) {
                $sf = mysqli_real_escape_string($conn, $stored);
                $so = mysqli_real_escape_string($conn, $orig);
                $sx = mysqli_real_escape_string($conn, $ext);
                mysqli_query($conn, "INSERT INTO employee_documents
                    (user_no, employee_id, doc_type, doc_number, file_name, original_name, file_ext, remarks, created_by)
                    VALUES ('$u','$ei','$dt','$dn','$sf','$so','$sx','$rk','$cb')");
                $message = "<div class='msg success'><span>✓</span> Document uploaded.</div>";
            } else {
                $message = "<div class='msg error'><span>!</span> Upload failed. Please try again.</div>";
            }
        }
    }
}
if ($canEditEmployee && isset($_POST['delete_document'])) {
    $did = (int)$_POST['delete_document'];
    if ($did > 0) {
        $dq = mysqli_query($conn, "SELECT file_name FROM employee_documents WHERE id=$did LIMIT 1");
        if ($dq && ($drow = mysqli_fetch_assoc($dq))) {
            $fp = "uploads/documents/" . $drow['file_name'];
            if ($drow['file_name'] !== '' && is_file($fp)) { @unlink($fp); }
        }
        mysqli_query($conn, "DELETE FROM employee_documents WHERE id=$did");
    }
    $message = "<div class='msg success'><span>✓</span> Document removed.</div>";
}

// ─── Search employee ──────────────────────────────────────────────────────────
if (!empty($_GET['search'])) {
    $stmt = mysqli_prepare($conn, "
        SELECT * FROM employees
        WHERE user_no = ?
           OR employee_id = ?
           OR full_name LIKE ?
        LIMIT 1
    ");
    $like = '%' . $_GET['search'] . '%';
    $s    = $_GET['search'];
    mysqli_stmt_bind_param($stmt, 'sss', $s, $s, $like);
    mysqli_stmt_execute($stmt);
    $res      = mysqli_stmt_get_result($stmt);
    $employee = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
}

$currentTab = $_GET['tab'] ?? 'details';
$viewerAllowedTabs = ['details', 'attendance', 'vacation'];
if ($isViewerRole && !in_array($currentTab, $viewerAllowedTabs, true)) {
    $currentTab = 'details';
}
$searchVal  = htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Overview — Payroll</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
/* ── Reset & base ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --navy:       #0f1f3d;
    --navy-mid:   #1a3260;
    --navy-light: #243f7a;
    --accent:     #f07d1a;
    --accent-dim: #c9651200;
    --accent-bg:  #fff4ea;
    --sidebar-w:  260px;
    --white:      #ffffff;
    --bg:         #f0f3f9;
    --border:     #d0d8e8;
    --text:       #1a2340;
    --text-dim:   #5a6480;
    --success-bg: #e8f7ef;
    --success:    #1a7f4b;
    --error-bg:   #fdecea;
    --error:      #c0392b;
    --label-bg:   #e4ecf7;
    --field-bg:   #f6f9fe;
    --radius:     10px;
    --shadow:     0 2px 12px rgba(15,31,61,.10);
}

body {
    font-family: 'IBM Plex Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar {
    width: var(--sidebar-w);
    height: 100vh;
    background: var(--navy);
    position: fixed;
    left: 0; top: 0;
    z-index: 1000;
    overflow-y: auto;
    padding: 0 0 24px;
    box-shadow: 4px 0 18px rgba(0,0,0,.18);
}

.sidebar-logo {
    padding: 22px 20px 18px;
    font-size: 15px;
    font-weight: 700;
    color: var(--accent);
    letter-spacing: .3px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    line-height: 1.35;
}

.menu-item {
    padding: 11px 20px;
    cursor: pointer;
    color: #c5d0e8;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-left: 3px solid transparent;
    transition: background .18s, border-color .18s, color .18s;
}
.menu-item:hover, .menu-item.active {
    background: rgba(240,125,26,.12);
    border-left-color: var(--accent);
    color: #fff;
}
.menu-item a {
    color: inherit;
    text-decoration: none;
    display: block;
    width: 100%;
}

.submenu {
    display: none;
    background: rgba(0,0,0,.18);
}
.submenu.open { display: block; }
.submenu a {
    display: block;
    padding: 9px 20px 9px 34px;
    color: #9aabcb;
    font-size: 13.5px;
    text-decoration: none;
    transition: color .15s, background .15s;
    border-left: 3px solid transparent;
}
.submenu a:hover {
    color: #fff;
    background: rgba(255,255,255,.05);
    border-left-color: var(--accent);
}

/* ── Main content ─────────────────────────────────────────── */
.main {
    margin-left: 0;
    padding: 0 0 40px;
    min-height: 100vh;
}

.page-header {
    background: linear-gradient(100deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: #fff;
    padding: 16px 28px;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: .3px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-header::before {
    content: '';
    display: inline-block;
    width: 4px; height: 22px;
    background: var(--accent);
    border-radius: 3px;
    flex-shrink: 0;
}

.content-pad { padding: 22px 28px; }

/* ── Messages ─────────────────────────────────────────────── */
.msg {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 18px;
}
.msg span { font-size: 18px; }
.msg.success { background: var(--success-bg); color: var(--success); border: 1px solid #b2dfc6; }
.msg.error   { background: var(--error-bg);   color: var(--error);   border: 1px solid #f5c0bb; }

/* ── Search bar ───────────────────────────────────────────── */
.search-wrap {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    box-shadow: var(--shadow);
    margin-bottom: 18px;
}

.search-wrap form {
    display: flex;
    gap: 8px;
    flex: 0 1 430px;
    min-width: 220px;
    max-width: 460px;
}

.search-input {
    flex: 1;
    padding: 9px 14px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color .2s;
    color: var(--text);
}
.search-input:focus { outline: none; border-color: var(--accent); }

.employee-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: var(--white);
    border: 1px solid var(--border);
    border-left: 5px solid var(--accent);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin: -4px 0 18px;
    box-shadow: var(--shadow);
    flex-wrap: wrap;
}
.employee-summary-name {
    font-size: 22px;
    font-weight: 700;
    color: var(--navy);
    line-height: 1.2;
}
.employee-summary-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    color: var(--text-dim);
    font-size: 13px;
}
.employee-summary-meta span {
    background: #1a3a5c;
    color: #fff;
    border: 1px solid #14304d;
    border-radius: 999px;
    padding: 6px 12px;
    font-weight: 600;
    white-space: nowrap;
}
.status-big{
    font-size: 18px;
    font-weight: 800;
    padding: 9px 20px;
    border-radius: 10px;
    letter-spacing: .4px;
    white-space: nowrap;
    text-transform: uppercase;
}
.st-active{background:#dcfce7;color:#15803d;border:1px solid #86efac;}
.st-inactive{background:#e2e8f0;color:#475569;border:1px solid #cbd5e1;}
.st-resigned{background:#fef3c7;color:#b45309;border:1px solid #fcd34d;}
.st-absconding{background:#fee2e2;color:#b91c1c;border:2px solid #ef4444;box-shadow:0 0 0 2px rgba(239,68,68,.15);}
.st-terminated{background:#fde2e2;color:#991b1b;border:1px solid #f87171;}
.st-endofcontract{background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;}
.st-cancelled{background:#fee2e2;color:#b91c1c;border:1px solid #f87171;}
.summary-status{display:flex;flex-direction:column;gap:8px;align-items:flex-end;}
.on-vacation-box{border:2px solid #e8a020;color:#c97a10;background:#fff8ee;font-weight:800;font-size:18px;padding:8px 22px;border-radius:8px;letter-spacing:.5px;text-transform:uppercase;white-space:nowrap;}
.absconding-stamp{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-16deg);color:#c0212d;border:4px double #c0212d;border-radius:10px;font-size:46px;font-weight:900;letter-spacing:6px;padding:6px 30px;opacity:.5;pointer-events:none;text-transform:uppercase;white-space:nowrap;z-index:5;font-family:'Arial Black','Segoe UI',Arial,sans-serif;}

/* ── Buttons ──────────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 9px 18px;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: background .18s, transform .1s;
}
.btn:hover  { background: #d96b10; }
.btn:active { transform: scale(.97); }

.btn-outline {
    background: transparent;
    color: var(--navy);
    border: 1.5px solid var(--border);
}
.btn-outline:hover { background: var(--label-bg); }

/* ── Tab pills ────────────────────────────────────────────── */
.tabs-wrap {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.tab-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 22px;
    font-size: 13.5px;
    font-weight: 600;
    font-family: inherit;
    text-decoration: none;
    color: var(--text-dim);
    background: var(--white);
    border: 1.5px solid var(--border);
    transition: all .18s;
}
.tab-pill:hover { border-color: var(--accent); color: var(--accent); }
.tab-pill.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
    box-shadow: 0 3px 10px rgba(240,125,26,.25);
}

/* ── Card / form area ─────────────────────────────────────── */
.card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.card-header {
    background: linear-gradient(90deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: #fff;
    padding: 12px 18px;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: .2px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-body { padding: 18px; }

/* ── Employee form table ──────────────────────────────────── */
.emp-grid {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.emp-grid td {
    border: 1px solid #c8d5e8;
    padding: 7px 10px;
    vertical-align: middle;
    height: 46px;
}
.lbl {
    background: var(--label-bg);
    color: var(--navy);
    font-weight: 600;
    width: 170px;
    white-space: nowrap;
}
.fld { background: var(--field-bg); }
.gap-cell { border: none !important; background: transparent !important; width: 24px; }

.photo-box {
    background: #fffbf3;
    border-left: 1px solid #c8d5e8 !important;
    text-align: center;
    vertical-align: top;
    padding: 14px !important;
    width: 260px;
}
.photo-box h3 { font-size: 13px; color: var(--navy); margin-bottom: 10px; }
.photo-box img,
.photo-placeholder {
    width: 200px; height: 200px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    background: #eef2fa;
    color: var(--text-dim);
    font-size: 13px;
    margin: 0 auto 10px;
}

input[type=text], input[type=date], input[type=email],
input[type=month], input[type=file], select, textarea {
    width: 100%;
    padding: 7px 10px;
    border: 1.5px solid #c8d5e8;
    border-radius: 6px;
    font-size: 13.5px;
    font-family: inherit;
    color: var(--text);
    background: #fff;
    transition: border-color .18s;
}
input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--accent);
    background: #fffbf6;
}
textarea { height: 48px; resize: vertical; }

.save-row {
    text-align: center;
    padding: 18px 0 6px;
}

/* ── Finance grid ─────────────────────────────────────────── */
.finance-grid {
    display: grid;
    grid-template-columns: 2fr 1.1fr 1.1fr 1.1fr;
    gap: 16px;
    margin-bottom: 22px;
}

.fin-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
}
.fin-card-hd {
    background: linear-gradient(90deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: #fff;
    padding: 9px 14px;
    font-size: 13.5px;
    font-weight: 700;
}
.fin-card-bd { padding: 12px 14px; }

.fin-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid #edf1f9;
    font-size: 13.5px;
}
.fin-row:last-child { border-bottom: none; }
.fin-lbl { color: var(--text-dim); }
.fin-val { font-weight: 700; color: var(--navy); font-family: 'IBM Plex Mono', monospace; }
.fin-val.accent { color: var(--accent); }
.fin-val.green  { color: var(--success); }
.fin-val.red    { color: var(--error); }

/* salary info two-col table */
.sal-info-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.sal-info-table th {
    background: var(--navy);
    color: #fff;
    padding: 8px 10px;
    text-align: left;
    font-weight: 600;
}
.sal-info-table td {
    border: 1px solid #d8e3f0;
    padding: 7px 10px;
    vertical-align: middle;
}
.sal-info-table tr:nth-child(even) td { background: #f6f9fe; }
.amt { font-weight: 700; color: var(--accent); font-family: 'IBM Plex Mono', monospace; }

/* ── Section heading ──────────────────────────────────────── */
.section-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy);
    margin: 22px 0 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-title::before {
    content: '';
    display: inline-block;
    width: 4px; height: 16px;
    background: var(--accent);
    border-radius: 2px;
}

/* ── Finance history table ────────────────────────────────── */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    background: var(--white);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
}
.data-table th {
    background: var(--navy);
    color: #fff;
    padding: 11px 14px;
    text-align: center;
    font-weight: 600;
    font-size: 13.5px;
}
.data-table td {
    border-bottom: 1px solid #e8eef7;
    padding: 10px 14px;
    text-align: center;
}
.data-table tbody tr:hover { background: #f6f9fe; }
.data-table tbody tr:last-child td { border-bottom: none; }

/* ── Month selector ───────────────────────────────────────── */
.month-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.month-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--accent-bg);
    border: 2px solid var(--accent);
    padding: 7px 14px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 14px;
    color: var(--navy);
}

/* ── Status badge ─────────────────────────────────────────── */
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.badge-paid    { background: #d4f0e0; color: #1a7f4b; }
.badge-unpaid  { background: #fdecea; color: #c0392b; }
.badge-pending { background: #fff3e0; color: #e65100; }
.badge-generated { background: #e3f2fd; color: #1565c0; }

/* ── Attendance grid ──────────────────────────────────────── */
.att-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    background: var(--white);
    box-shadow: var(--shadow);
    border-radius: var(--radius);
    overflow: hidden;
}
.att-table th {
    background: var(--navy);
    color: #fff;
    padding: 9px 8px;
    font-size: 12.5px;
    text-align: center;
    border: 1px solid rgba(255,255,255,.1);
}
.att-table td {
    border: 1px solid #dce6f4;
    padding: 7px 8px;
    text-align: center;
    vertical-align: middle;
}
.att-table td:first-child {
    text-align: left;
    font-weight: 600;
    background: var(--label-bg);
    white-space: nowrap;
    padding-left: 12px;
}
.att-num {
    font-weight: 700;
    color: var(--accent);
    cursor: pointer;
    text-decoration: underline dotted;
}
.att-num:hover { color: var(--navy); }

.detail-row { display: none; }
.detail-row td {
    background: #fffbf3;
    text-align: left !important;
    padding: 12px 16px !important;
}
.detail-inner { line-height: 1.9; font-size: 13px; color: var(--text); }
.detail-inner b { display: block; color: var(--navy); margin-bottom: 6px; }

/* ── Complaint form ───────────────────────────────────────── */
.complaint-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: 5px;
}

.priority-badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; }
.priority-low    { background: #e8f5e9; color: #2e7d32; }
.priority-medium { background: #fff8e1; color: #f57f17; }
.priority-high   { background: #fce4ec; color: #c62828; }

.status-pending    { color: #e65100; font-weight: 700; }
.status-inprogress { color: #1565c0; font-weight: 700; }
.status-solved     { color: #2e7d32; font-weight: 700; }
.status-rejected   { color: #c62828; font-weight: 700; }

/* ── Empty / placeholder ──────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--text-dim);
}
.empty-state .icon { font-size: 48px; margin-bottom: 12px; opacity: .5; }
.empty-state p { font-size: 15px; }

/* ── Scrollable on small screens ─────────────────────────── */
.table-scroll { overflow-x: auto; }

@media (max-width: 1100px) {
    .finance-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 700px) {
    .main { margin-left: 0; }
    .sidebar { transform: translateX(-100%); }
    .finance-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════════════════ -->
<?php include 'nav_sidebar.php'; ?>

<!-- ══ MAIN ═══════════════════════════════════════════════════════════════════ -->
<main class="main">

<div class="page-header">Employee Overview</div>

<div class="content-pad">

<?php if ($message) echo $message; ?>

<!-- Search + Tab bar -->
<div class="search-wrap">
    <form method="GET" style="display:flex;gap:8px;flex:1;min-width:240px;">
        <input class="search-input"
               type="text"
               name="search"
               placeholder="Search by User No / Name / Employee ID"
               value="<?= $searchVal ?>">
        <?php if ($currentTab !== 'details'): ?>
            <input type="hidden" name="tab" value="<?= htmlspecialchars($currentTab, ENT_QUOTES) ?>">
        <?php endif; ?>
        <button type="submit" class="btn">🔍 Search</button>
    </form>

    <?php if ($employee): ?>
    <div class="tabs-wrap">
        <?php
        $tabs = [
            'details'    => ['📋', 'Details'],
            'attendance' => ['📅', 'Attendance'],
            'vacation'   => ['🏖️', 'Vacation'],
            'finance'    => ['💰', 'Finance'],
            'airticket'  => ['✈️', 'Air Ticket'],
            'visarenewal'=> ['🛂', 'Visa Renewal'],
            'complain'   => ['📝', 'Complaints'],
            'documents'  => ['📎', 'Documents'],
        ];
        foreach ($tabs as $key => [$icon, $label]):
            if ($isViewerRole && !in_array($key, $viewerAllowedTabs, true)) {
                continue;
            }
            $active = ($currentTab === $key) ? 'active' : '';
        ?>
        <a href="employee_overview.php?search=<?= $searchVal ?>&tab=<?= $key ?>"
           class="tab-pill <?= $active ?>">
            <?= $icon ?> <?= $label ?>
        </a>
        <?php endforeach; ?>
        <?php if (hasPermission('gate_pass_manage')): ?>
        <a href="employee_overview.php?search=<?= $searchVal ?>&tab=gatepass"
           class="tab-pill <?= $currentTab === 'gatepass' ? 'active' : '' ?>"
           style="<?= $currentTab === 'gatepass' ? 'background:#0f766e;color:#fff;border-color:#0f766e;' : 'color:#0f766e;border-color:#0f766e;' ?>"
           title="View / generate this employee's SAIF Zone gate passes">
            🚪 Gate Pass
        </a>
        <?php endif; ?>
        <?php if (hasPermission('memo_manage')): ?>
        <a href="employee_overview.php?search=<?= $searchVal ?>&tab=memo"
           class="tab-pill <?= $currentTab === 'memo' ? 'active' : '' ?>"
           style="<?= $currentTab === 'memo' ? 'background:#7c3aed;color:#fff;border-color:#7c3aed;' : 'color:#7c3aed;border-color:#7c3aed;' ?>"
           title="Issue / view this employee's memos &amp; warning letters">
            📋 Memo
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: DETAILS
════════════════════════════════════════════════════════════════════════════ -->
<?php if ($employee): ?>
<div class="employee-summary">
    <div>
        <div class="employee-summary-name"><?php echo val($employee, 'full_name'); ?></div>
        <div class="employee-summary-meta">
            <span>User No: <?php echo val($employee, 'user_no'); ?></span>
            <span>Employee ID: <?php echo val($employee, 'employee_id'); ?></span>
            <span>Department: <?php echo val($employee, 'department'); ?></span>
            <span>Designation: <?php echo val($employee, 'designation'); ?></span>
            <?php
            $ov_acc = function_exists('acc_employee_current') ? acc_employee_current($conn, $employee['user_no']) : null;
            if ($ov_acc):
            ?>
            <span style="background:#1565c0;color:#fff;border-color:#0d4a96;border-radius:999px;">
                🏠 Accom: <?php echo htmlspecialchars($ov_acc['main_location'] ?? '', ENT_QUOTES); ?>
                | Block/Tower: <?php echo htmlspecialchars($ov_acc['tower_block'] ?: '-', ENT_QUOTES); ?>
                | Room No.: <?php echo htmlspecialchars($ov_acc['room_number'] ?: '-', ENT_QUOTES); ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php
        // Departure is finalised by VISA CANCELLATION: the employee stays
        // ACTIVE here (and in Accommodation) until their visa cancellation
        // Status is 'Completed'. When Completed, the stamp / label is taken
        // from the cancellation Reason.
        $ov_uno = mysqli_real_escape_string($conn, (string)$employee['user_no']);
        $vc_completed = false; $vc_reason = '';
        $vc_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'visa_cancellations'");
        if ($vc_tbl && mysqli_num_rows($vc_tbl) > 0) {
            $vcq = mysqli_query($conn, "SELECT cancellation_status, cancellation_reason FROM visa_cancellations WHERE user_no='$ov_uno' ORDER BY id DESC LIMIT 1");
            if ($vcq && ($vcr = mysqli_fetch_assoc($vcq))) {
                $vc_completed = strcasecmp(trim((string)($vcr['cancellation_status'] ?? '')), 'Completed') === 0;
                $vc_reason    = trim((string)($vcr['cancellation_reason'] ?? ''));
            }
        }
        // Reason -> stamp / label text.
        $ov_stamp = 'CANCELLED';
        switch (strtolower($vc_reason)) {
            case 'termination': $ov_stamp = 'TERMINATED'; break;
            case 'absconding':  $ov_stamp = 'ABSCONDING'; break;
            // Resignation, End of Contract, Transfer, Other -> CANCELLED
        }
        if ($vc_completed) {
            $ov_status = $ov_stamp;
            $ov_cls = 'st-' . strtolower($ov_stamp);
        } else {
            $ov_status = 'Active';
            $ov_cls = 'st-active';
        }

        // Currently on vacation? (left and not yet returned; comp-off excluded)
        $ov_vq = mysqli_query($conn, "
            SELECT 1 FROM vacations
            WHERE user_no='$ov_uno' AND from_date <= CURDATE()
              AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00' OR return_date > CURDATE())
              AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
              AND (reason IS NULL OR (reason NOT LIKE '%Compensatory Off%' AND reason NOT LIKE '%swapped with%'))
            LIMIT 1");
        $ov_on_vacation = ($ov_vq && mysqli_num_rows($ov_vq) > 0);
    ?>
    <div class="summary-status">
        <div class="status-big <?php echo $ov_cls; ?>"><?php echo htmlspecialchars($ov_status, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($ov_on_vacation): ?>
        <div class="on-vacation-box">On Vacation</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($employee && $currentTab === 'details'): ?>

<form method="POST" enctype="multipart/form-data" id="employeeDetailsForm" class="<?php echo $canEditEmployee ? '' : 'viewer-readonly'; ?>">
<input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">

<div class="card" style="margin-bottom:18px;">
    <div class="card-header">👤 Personal & Job Information</div>
    <div class="card-body" style="position:relative;">
    <?php if (!empty($vc_completed)): ?>
        <div class="absconding-stamp"><?php echo htmlspecialchars($ov_stamp, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <div class="table-scroll">
    <table class="emp-grid">
        <tr>
            <td class="lbl">User No. / ID</td>
            <td class="fld"><input type="text" name="user_no" value="<?= val($employee,'user_no') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">Bio-Metric Card No.</td>
            <td class="fld"><input type="text" name="card_no" value="<?= val($employee,'card_no') ?>"></td>
            <td class="gap-cell"></td>
            <td class="photo-box" rowspan="6">
                <h3>Employee Photo</h3>
                <?php if (!empty($employee['photo'])): ?>
                    <img src="uploads/<?= htmlspecialchars($employee['photo'], ENT_QUOTES) ?>" alt="Photo">
                <?php else: ?>
                    <div class="photo-placeholder">📷 No Photo</div>
                <?php endif; ?>
                <?php if ($canEditEmployee): ?>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="lbl">Employee Full Name</td>
            <td class="fld"><textarea name="full_name"><?= val($employee,'full_name') ?></textarea></td>
            <td class="gap-cell"></td>
            <td class="lbl">Gender</td>
            <td class="fld">
                <select name="gender">
                    <option value="">— Select —</option>
                    <?php foreach (['Male','Female','Shemale'] as $g): ?>
                    <option value="<?= $g ?>" <?= val($employee,'gender') === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="gap-cell"></td>
        </tr>
        <tr>
            <td class="lbl">Department</td>
            <td class="fld">
                <select name="department">
                    <option value="">Select Department</option>
                    <?php
                    $departments = dept_get_departments($conn);
                    foreach ($departments as $dept):
                        $selected = val($employee, 'department') === $dept ? 'selected' : '';
                    ?>
                    <option value="<?= htmlspecialchars($dept, ENT_QUOTES) ?>" <?= $selected ?>><?= htmlspecialchars($dept, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="gap-cell"></td>
            <td class="lbl">Position / Designation</td>
            <td class="fld">
                <select name="designation">
                    <option value="">Select Designation</option>
                    <?php
                    $designations = dept_get_designations($conn);
                    foreach ($designations as $designation):
                        $selected = val($employee, 'designation') === $designation ? 'selected' : '';
                    ?>
                    <option value="<?= htmlspecialchars($designation, ENT_QUOTES) ?>" <?= $selected ?>><?= htmlspecialchars($designation, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="gap-cell"></td>
        </tr>
        <tr>
            <td class="lbl">Joining Date</td>
            <td class="fld"><input type="date" name="joining_date" value="<?= val($employee,'joining_date') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">Date of Birth</td>
            <td class="fld"><input type="date" name="birthday" value="<?= val($employee,'birthday') ?>"></td>
            <td class="gap-cell"></td>
        </tr>
        <tr>
            <td class="lbl">Phone No. (UAE)</td>
            <td class="fld"><input type="text" name="phone" value="<?= val($employee,'phone') ?>" placeholder="971..."></td>
            <td class="gap-cell"></td>
            <td class="lbl">Phone No. (Home Country)</td>
            <td class="fld"><input type="text" name="home_phone" value="<?= val($employee,'home_phone') ?>"></td>
            <td class="gap-cell"></td>
        </tr>
        <tr>
            <td class="lbl">Address</td>
            <td class="fld" colspan="5"><input type="text" name="address" value="<?= val($employee,'address') ?>"></td>
        </tr>
    </table>
    </div><!-- /table-scroll -->
    </div>
</div>

<div class="card">
    <div class="card-header">📄 Documents & Additional Info</div>
    <div class="card-body">
    <div class="table-scroll">
    <table class="emp-grid">
        <tr>
            <td class="lbl">Nationality</td>
            <td class="fld">
                <select name="nationality">
                    <?php
                    $countries = ['','Indian','Bangladeshi','Srilankan','Pakistani','Nepali','Bhutani','UAE'];
                    foreach ($countries as $c) {
                        $sel = val($employee,'nationality') === $c ? 'selected' : '';
                        echo "<option value='$c' $sel>" . ($c ?: '— Select —') . "</option>";
                    }
                    ?>
                </select>
            </td>
            <td class="gap-cell"></td>
            <td class="lbl">Marital Status</td>
            <td class="fld">
                <select name="marital_status">
                    <option value="">— Select —</option>
                    <option value="Single"  <?= val($employee,'marital_status') === 'Single'  ? 'selected' : '' ?>>Single</option>
                    <option value="Married" <?= val($employee,'marital_status') === 'Married' ? 'selected' : '' ?>>Married</option>
                </select>
            </td>
        </tr>
        <tr>
            <td class="lbl">Passport Number</td>
            <td class="fld"><input type="text" name="passport" value="<?= htmlspecialchars($employee['passport'] ?? $employee['passport_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">Passport Issue Date</td>
            <td class="fld"><input type="date" name="passport_issue" value="<?= val($employee,'passport_issue') ?>"></td>
        </tr>
        <tr>
            <td class="lbl">Passport Expiry Date</td>
            <td class="fld"><input type="date" name="passport_expiry" value="<?= val($employee,'passport_expiry') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">SAIF Zone ID</td>
            <td class="fld"><input type="text" name="saif_zone_id" value="<?= val($employee,'saif_zone_id') ?>"></td>
        </tr>
        <tr>
            <td class="lbl">Emirates ID Number</td>
            <td class="fld"><input type="text" name="emirates_id_number" value="<?= val($employee,'emirates_id_number') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">UID Number</td>
            <td class="fld"><input type="text" name="uid_number" value="<?= val($employee,'uid_number') ?>"></td>
        </tr>
        <tr>
            <td class="lbl">Visa Issuing Date</td>
            <td class="fld"><input type="date" name="visa_issuing_date" value="<?= val($employee,'visa_issuing_date') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">Visa Expiry Date</td>
            <td class="fld"><input type="date" name="visa_expiry_date" value="<?= val($employee,'visa_expiry_date') ?>"></td>
        </tr>
        <tr>
            <td class="lbl">Insurance Number</td>
            <td class="fld"><input type="text" name="insurance_number" value="<?= val($employee,'insurance_number') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">Insurance Expire Date</td>
            <td class="fld"><input type="date" name="insurance_expiry_date" value="<?= val($employee,'insurance_expiry_date') ?>"></td>
        </tr>
        <tr>
            <td class="lbl">Email</td>
            <td class="fld"><input type="email" name="email" value="<?= val($employee,'email') ?>"></td>
            <td class="gap-cell"></td>
            <td class="lbl">Previous Company</td>
            <td class="fld"><input type="text" name="previous_company" value="<?= val($employee,'previous_company') ?>"></td>
        </tr>
        <tr>
            <td class="lbl">Employee Status</td>
            <td class="fld">
                <?php $cur_status = trim((string)($employee['employee_status'] ?? '')); if ($cur_status === '') { $cur_status = 'Active'; } ?>
                <select name="employee_status">
                    <?php foreach (['Active','Inactive','Resigned','Absconding','Terminated','End of Contract'] as $st): ?>
                    <option value="<?= $st ?>" <?= strcasecmp($cur_status, $st) === 0 ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="gap-cell"></td>
            <td class="lbl">Resign Date</td>
            <td class="fld"><input type="date" name="resign_date" value="<?= val($employee,'resign_date') ?>"></td>
        </tr>
    </table>
    </div><!-- /table-scroll -->

    <div class="save-row">
        <?php if ($canEditEmployee): ?>
        <button type="submit" name="save_employee" class="btn">💾 Save Changes</button>
        <?php endif; ?>
    </div>
    </div>
</div>

</form>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: FINANCE
════════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($employee && $currentTab === 'finance'):

    $user_no      = $employee['user_no'];
    $emp_id       = $employee['employee_id'] ?? '';
    $currentMonth = normalize_input_month($_GET['finance_month'] ?? date('Y-m'), date('Y-m'));
    $showMonth    = date('F Y', strtotime($currentMonth . '-01'));
    $monthDays    = (int)date('t', strtotime($currentMonth . '-01'));
    $monthStart   = $currentMonth . '-01';
    $monthEnd     = date('Y-m-t', strtotime($monthStart));

    // Fetch salary record
    $sal_stmt = mysqli_prepare($conn, "SELECT * FROM employee_salary_records WHERE user_no=? AND salary_month=? LIMIT 1");
    mysqli_stmt_bind_param($sal_stmt, 'ss', $user_no, $currentMonth);
    mysqli_stmt_execute($sal_stmt);
    $salary = mysqli_fetch_assoc(mysqli_stmt_get_result($sal_stmt)) ?: [];
    mysqli_stmt_close($sal_stmt);

    $salary_status    = $salary['salary_status'] ?? 'Unpaid';
    $salary_generated = !empty($salary) && in_array($salary_status, ['Generated','Paid','generated','paid']);

    $basic_salary         = (float)($employee['basic_salary']        ?? $salary['basic_salary']        ?? 0);
    $allowance            = (float)($employee['allowance']           ?? $salary['allowance']            ?? 0);
    $att_allowance        = (float)($employee['att_allowance']       ?? $salary['att_allowance']        ?? 0);
    $food_allowance_won   = isset($salary['food_allowance_won']) && $salary['food_allowance_won'] !== ''
        ? (float)$salary['food_allowance_won']
        : (float)($employee['food_allowance_won'] ?? 0);
    $food_allowance_co    = isset($salary['food_allowance_company']) && $salary['food_allowance_company'] !== ''
        ? (float)$salary['food_allowance_company']
        : (float)($employee['food_allowance_company'] ?? 0);
    if ($food_allowance_co == 0 && $food_allowance_won == 0 && isset($salary['food_allowance']) && $salary['food_allowance'] !== '') {
        $food_allowance_co = (float)$salary['food_allowance'];
    }
    $food_allowance       = $food_allowance_co + $food_allowance_won;
    $ot_hours             = (float)($salary['ot'] ?? 0);
    $stored_ot_amount     = (float)($salary['ot_amount'] ?? 0);
    $advance              = (float)($salary['advance_amount']    ?? $employee['advance_amount']    ?? 0);
    $insurance            = (float)($employee['insurance_amount']  ?? $salary['insurance_amount']  ?? 0);
    $other_deduction      = (float)($employee['other_deduction']   ?? $salary['other_deduction']   ?? 0);
    $penalty              = (float)($salary['penalty_amount'] ?? 0);
    $advance_date         = $salary['advance_date'] ?? '';
    $advance_summary      = payroll_get_advance_summary($conn, $user_no);
    $advance_total        = (float)($advance_summary['total_advance'] ?? 0);
    $advance_paid         = (float)($advance_summary['paid_amount'] ?? 0);
    $advance_balance      = (float)($advance_summary['balance_amount'] ?? 0);
    $advance_monthly      = (float)($advance_summary['monthly_deduction'] ?? 0);
    if ($advance_date === '') {
        $advance_date = $advance_summary['last_advance_date'] ?? '';
    }

    // --- Present days (actual + Sunday + Holiday, excluding vacation) ---
    $present_dates = [];

    $pq = mysqli_prepare($conn, "
        SELECT DISTINCT attendance_date
        FROM attendance
        WHERE user_no = ?
          AND attendance_date BETWEEN ? AND ?
          AND check_in IS NOT NULL AND TRIM(check_in) != ''
          AND NOT EXISTS (
              SELECT 1 FROM vacations v
              WHERE v.user_no = attendance.user_no
                AND attendance.attendance_date BETWEEN v.from_date AND v.to_date
          )
    ");
    mysqli_stmt_bind_param($pq, 'sss', $user_no, $monthStart, $monthEnd);
    mysqli_stmt_execute($pq);
    $pres = mysqli_stmt_get_result($pq);
    while ($p = mysqli_fetch_assoc($pres)) $present_dates[$p['attendance_date']] = true;
    mysqli_stmt_close($pq);

    // Sundays
    $period = new DatePeriod(new DateTime($monthStart), new DateInterval('P1D'), (new DateTime($monthEnd))->modify('+1 day'));
    foreach ($period as $date) {
        if ($date->format('w') == 0) {
            $d = $date->format('Y-m-d');
            $vq = mysqli_prepare($conn, "SELECT id FROM vacations WHERE user_no=? AND ? BETWEEN from_date AND to_date LIMIT 1");
            mysqli_stmt_bind_param($vq, 'ss', $user_no, $d);
            mysqli_stmt_execute($vq);
            if (mysqli_num_rows(mysqli_stmt_get_result($vq)) === 0) $present_dates[$d] = true;
            mysqli_stmt_close($vq);
        }
    }

    // Holidays
    $hq = mysqli_prepare($conn, "SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    mysqli_stmt_bind_param($hq, 'ss', $monthStart, $monthEnd);
    mysqli_stmt_execute($hq);
    $hres = mysqli_stmt_get_result($hq);
    while ($h = mysqli_fetch_assoc($hres)) {
        $d  = $h['holiday_date'];
        $vq = mysqli_prepare($conn, "SELECT id FROM vacations WHERE user_no=? AND ? BETWEEN from_date AND to_date LIMIT 1");
        mysqli_stmt_bind_param($vq, 'ss', $user_no, $d);
        mysqli_stmt_execute($vq);
        if (mysqli_num_rows(mysqli_stmt_get_result($vq)) === 0) $present_dates[$d] = true;
        mysqli_stmt_close($vq);
    }
    mysqli_stmt_close($hq);

    $present_days = count($present_dates);

    // Absent
    $aq = mysqli_prepare($conn, "
        SELECT COUNT(*) AS total FROM attendance
        WHERE user_no=? AND attendance_date LIKE ?
          AND (check_in IS NULL OR TRIM(check_in)='')
          AND DAYNAME(attendance_date)!='Sunday'
          AND attendance_date NOT IN (SELECT holiday_date FROM holidays)
          AND NOT EXISTS (
              SELECT 1 FROM vacations l
              WHERE l.user_no=attendance.user_no
                AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
          )
    ");
    $like_month = $currentMonth . '%';
    mysqli_stmt_bind_param($aq, 'ss', $user_no, $like_month);
    mysqli_stmt_execute($aq);
    $absent_days = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($aq))['total'] ?? 0);
    mysqli_stmt_close($aq);

    // Vacation days
    $vq2 = mysqli_prepare($conn, "
        SELECT COALESCE(SUM(DATEDIFF(LEAST(to_date,?), GREATEST(from_date,?))+1),0) AS total
        FROM vacations WHERE user_no=? AND from_date<=? AND to_date>=?
    ");
    mysqli_stmt_bind_param($vq2, 'sssss', $monthEnd, $monthStart, $user_no, $monthEnd, $monthStart);
    mysqli_stmt_execute($vq2);
    $vacation_days = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($vq2))['total'] ?? 0);
    mysqli_stmt_close($vq2);

    // Late: use saved late_time when available; otherwise calculate from check-in after 07:06 grace.
    $lq = mysqli_prepare($conn, "
        SELECT
            SUM(CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations l
                    WHERE l.user_no=attendance.user_no
                      AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
                ) THEN 0
                WHEN late_time IS NOT NULL AND TRIM(late_time)!=''
                  AND TRIM(late_time) NOT IN ('00:00','00:00:00') THEN 1
                WHEN check_in IS NOT NULL AND TRIM(check_in)!=''
                  AND TIME_TO_SEC(check_in) > TIME_TO_SEC('07:06:00') THEN 1
                ELSE 0
            END) AS total,
            COALESCE(SUM(CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations l
                    WHERE l.user_no=attendance.user_no
                      AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
                ) THEN 0
                WHEN late_time IS NOT NULL AND TRIM(late_time)!=''
                  AND TRIM(late_time) NOT IN ('00:00','00:00:00') THEN TIME_TO_SEC(late_time)
                WHEN check_in IS NOT NULL AND TRIM(check_in)!=''
                  AND TIME_TO_SEC(check_in) > TIME_TO_SEC('07:06:00')
                    THEN TIME_TO_SEC(check_in) - TIME_TO_SEC('07:00:00')
                ELSE 0
            END),0) AS late_seconds
        FROM attendance
        WHERE user_no=? AND attendance_date LIKE ?
    ");
    mysqli_stmt_bind_param($lq, 'ss', $user_no, $like_month);
    mysqli_stmt_execute($lq);
    $late_row = mysqli_fetch_assoc(mysqli_stmt_get_result($lq)) ?: [];
    $late_days = (int)($late_row['total'] ?? 0);
    $late_seconds = (int)($late_row['late_seconds'] ?? 0);
    mysqli_stmt_close($lq);

    if ($ot_hours <= 0) $ot_hours = monthly_ot_hours($conn, $user_no, $emp_id, $currentMonth);
    $ot_hours = round($ot_hours, 2);
    if ($late_seconds > 0) $att_allowance = 0;

    // Calculations
    $salary_earned      = $monthDays > 0 ? ($basic_salary  / $monthDays) * $present_days : 0;
    $allowance_earned   = $monthDays > 0 ? ($allowance     / $monthDays) * $present_days : 0;
    $ot_rate            = $basic_salary > 0 ? ($basic_salary / 30 / 8) * 1.25 : 0;
    $ot_amount          = $stored_ot_amount > 0 ? $stored_ot_amount : ($ot_rate * $ot_hours);
    $regular_ot_hours   = (float)($salary['regular_ot_hours'] ?? 0);
    $after6_hours       = (float)($salary['ot'] ?? 0);
    $extra_ot_hours     = (float)($salary['extra_ot_hours'] ?? 0);
    $regular_ot_amount  = round($ot_rate * $regular_ot_hours, 2);
    $total_deduction    = $advance + $insurance + $other_deduction + $penalty;
    $final_net_pay      = $salary_earned + $allowance_earned + $att_allowance + $ot_amount + $food_allowance - $total_deduction;
    $remaining_advance  = $advance_balance;
    $no_work_days       = $present_days <= 0;

    $gen_salary_earned  = (float)($salary['salary_earned']   ?? $salary_earned);
    $gen_allow_earned   = (float)($salary['allowance_earned'] ?? $allowance_earned);
    $gen_ot_amount      = (float)($salary['ot_amount']       ?? $ot_amount);
    $after6_extra_amount = max($gen_ot_amount - $regular_ot_amount, 0);
    $gen_net            = (float)($salary['net_payable']     ?? $salary['net_salary'] ?? $final_net_pay);
    $salary_by          = $employee['salary_by'] ?? $salary['salary_by'] ?? '';

    if ($no_work_days) {
        $gen_salary_earned = $gen_allow_earned = $gen_ot_amount = $gen_net = 0;
        $regular_ot_amount = $after6_extra_amount = 0;
        $final_net_pay     = 0;
        $remaining_advance = $advance_balance;
    }

    // Salary info rows
    $salary_detail_rows = [
        ['Basic Salary',       $basic_salary],
        ['Allowance',          $allowance],
        ['Attend. Allowance',  $att_allowance],
    ];
    if ($food_allowance_co > 0) $salary_detail_rows[] = ['Food Allow. (Company)', $food_allowance_co];
    if ($food_allowance_won > 0) $salary_detail_rows[] = ['Food Allow. (Own)', $food_allowance_won];
    $salary_detail_rows[] = ['Gross Salary', $basic_salary + $allowance + $att_allowance + $food_allowance];

    $after_rows = [];
    if ($salary_generated) {
        $after_rows = [
            ['Salary Earned',     $gen_salary_earned],
            ['Allowance Earned',  $gen_allow_earned],
            ['Attend. Allowance', $att_allowance],
        ];
        if ($food_allowance_co > 0) $after_rows[] = ['Food Allow. (Company)', $food_allowance_co];
        if ($food_allowance_won > 0) $after_rows[] = ['Food Allow. (Own)', $food_allowance_won];
        $after_rows[] = ['Regular OT',            $regular_ot_amount];
        $after_rows[] = ['After 6pm + Extra OT',  $after6_extra_amount];
        $after_rows[] = ['Advance Deduction',     $no_work_days ? 0 : $advance];
        $after_rows[] = ['Insurance Deduction',   $no_work_days ? 0 : $insurance];
        $after_rows[] = ['Other Deduction',       $no_work_days ? 0 : ($other_deduction + $penalty)];
        $after_rows[] = ['Net Payable',           $gen_net];
    }

    $row_count = max(count($salary_detail_rows), count($after_rows), 6);

    // Status badge helper
    $status_badge = match(strtolower($salary_status)) {
        'paid'      => "<span class='badge badge-paid'>✓ Paid</span>",
        'generated' => "<span class='badge badge-generated'>Generated</span>",
        default     => "<span class='badge badge-unpaid'>Unpaid</span>",
    };
?>

<div class="month-bar">
    <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="search" value="<?= $searchVal ?>">
        <input type="hidden" name="tab"    value="finance">
        <strong style="color:var(--navy);">Select Month:</strong>
        <input type="month" name="finance_month"
               value="<?= htmlspecialchars($currentMonth, ENT_QUOTES) ?>"
               onchange="this.form.submit()"
               style="width:190px;">
    </form>
    <div class="month-label">📅 <?= strtoupper($showMonth) ?></div>
</div>

<!-- Finance cards -->
<div class="finance-grid">

    <!-- Salary information -->
    <div class="fin-card">
        <div class="fin-card-hd">💳 Salary Information</div>
        <div class="fin-card-bd" style="padding:0;">
            <table class="sal-info-table">
                <tr>
                    <th>Salary Details</th>
                    <th>After Deduction Earnings</th>
                </tr>
                <?php for ($i = 0; $i < $row_count; $i++): ?>
                <tr>
                    <td>
                        <?php if (isset($salary_detail_rows[$i])): ?>
                            <?= $salary_detail_rows[$i][0] ?>:
                            <span class="amt"><?= fmt($salary_detail_rows[$i][1]) ?> AED</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$salary_generated && $i === 0): ?>
                            <span style="color:var(--error);font-weight:700;">⚠ Generate salary first</span>
                        <?php elseif (isset($after_rows[$i])): ?>
                            <?= $after_rows[$i][0] ?>:
                            <span class="amt"><?= fmt($after_rows[$i][1]) ?> AED</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endfor; ?>
                <tr>
                    <td style="font-weight:700;">Status: <?= $status_badge ?></td>
                    <td style="font-weight:700;">By: <strong><?= htmlspecialchars($salary_by ?: 'N/A', ENT_QUOTES) ?></strong></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Attendance -->
    <div class="fin-card">
        <div class="fin-card-hd">📅 Attendance Record</div>
        <div class="fin-card-bd">
            <div class="fin-row"><span class="fin-lbl">Present Days</span>  <span class="fin-val green"><?= $present_days ?></span></div>
            <div class="fin-row"><span class="fin-lbl">Absent Days</span>   <span class="fin-val red"><?= $absent_days ?></span></div>
            <div class="fin-row"><span class="fin-lbl">Vacation Days</span> <span class="fin-val"><?= $vacation_days ?></span></div>
            <div class="fin-row"><span class="fin-lbl">OT Hours</span>      <span class="fin-val accent"><?= $ot_hours ?></span></div>
            <div class="fin-row"><span class="fin-lbl">Late Count</span>    <span class="fin-val <?= $late_days > 0 ? 'red' : '' ?>"><?= $late_days ?></span></div>
        </div>
    </div>

    <!-- Advance -->
    <div class="fin-card">
        <div class="fin-card-hd">💵 Advance Salary</div>
        <div class="fin-card-bd">
            <div class="fin-row"><span class="fin-lbl">Total Advance</span>     <span class="fin-val accent"><?= fmt($advance_total) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">This Month Deduction</span><span class="fin-val accent"><?= fmt($no_work_days ? 0 : $advance) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">Monthly Deduction</span> <span class="fin-val accent"><?= fmt($advance_monthly) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">Paid Amount</span>       <span class="fin-val green"><?= fmt($advance_paid) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">Advance Date</span>      <span class="fin-val"><?= $advance_date ?: 'N/A' ?></span></div>
            <div class="fin-row"><span class="fin-lbl">Remaining</span>         <span class="fin-val red"><?= fmt($remaining_advance) ?> AED</span></div>
        </div>
    </div>

    <!-- Deductions -->
    <div class="fin-card">
        <div class="fin-card-hd">➖ Deduction Details</div>
        <div class="fin-card-bd">
            <div class="fin-row"><span class="fin-lbl">Insurance</span>       <span class="fin-val red"><?= fmt($no_work_days ? 0 : $insurance) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">Penalty</span>         <span class="fin-val red"><?= fmt($no_work_days ? 0 : $penalty) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">Other Deduction</span> <span class="fin-val red"><?= fmt($no_work_days ? 0 : $other_deduction) ?> AED</span></div>
            <div class="fin-row"><span class="fin-lbl">Total Deduction</span> <span class="fin-val red"><?= fmt($no_work_days ? 0 : $total_deduction) ?> AED</span></div>
        </div>
    </div>

</div>

<?php
/* After 6pm Duty (separate payment) — shown for reference only; NOT added
   to the salary figures above. Fixed-salary employees get no OT at all. */
require_once 'after6pm_helper.php';
$a6_fixed = (float)($employee['fixed_salary'] ?? 0) > 0;
?>
<div class="section-title">After 6pm Duty (Separate Payment)</div>
<?php if ($a6_fixed): ?>
<div class="fin-card" style="max-width:360px;margin-bottom:18px;">
    <div class="fin-card-bd" style="color:var(--text-dim);">
        🌙 Fixed-salary employee &mdash; no After 6pm / OT applicable.
    </div>
</div>
<?php else: $a6 = a6_breakdown($conn, $user_no, $emp_id, $basic_salary, $currentMonth); ?>
<div class="fin-card" style="max-width:360px;margin-bottom:18px;">
    <div class="fin-card-hd">🌙 After 6pm Duty — <?= strtoupper($showMonth) ?></div>
    <div class="fin-card-bd">
        <div class="fin-row"><span class="fin-lbl">After 6pm OT</span> <span class="fin-val accent"><?= fmt($a6['after6pm_amount']) ?> AED</span></div>
        <div class="fin-row"><span class="fin-lbl">Sunday OT</span> <span class="fin-val accent"><?= fmt($a6['sunday_amount']) ?> AED</span></div>
        <div class="fin-row" style="border-top:1px solid var(--border);margin-top:4px;padding-top:6px;"><span class="fin-lbl" style="font-weight:700;">Total Amount</span> <span class="fin-val green" style="font-weight:800;"><?= fmt($a6['total']) ?> AED</span></div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">Not included in salary above. <a href="after6pm_slip.php?user_no=<?= urlencode($user_no) ?>&month=<?= urlencode($currentMonth) ?>&search_btn=1" target="_blank" rel="noopener">Open slip &rarr;</a></div>
    </div>
</div>
<?php endif; ?>

<!-- Salary history table -->
<div class="section-title">Salary History</div>

<div class="table-scroll">
<table class="data-table">
<thead>
    <tr>
        <th>Month</th>
        <th>Gross Salary</th>
        <th>Total Deduction</th>
        <th>Net Payable</th>
        <th>Status</th>
    </tr>
</thead>
<tbody>
<?php
$hist_stmt = mysqli_prepare($conn, "SELECT * FROM employee_salary_records WHERE user_no=? ORDER BY salary_month DESC");
mysqli_stmt_bind_param($hist_stmt, 's', $user_no);
mysqli_stmt_execute($hist_stmt);
$hist_res = mysqli_stmt_get_result($hist_stmt);

if (mysqli_num_rows($hist_res) > 0):
    while ($h = mysqli_fetch_assoc($hist_res)):
        $h_basic    = (float)($h['basic_salary'] ?? 0);
        $h_allow    = (float)($h['allowance']    ?? 0);
        $h_food     = (float)($h['food_allowance'] ?? 0);
        $h_att      = (float)($h['att_allowance']  ?? 0);
        $h_ot_h     = (float)($h['ot']             ?? 0);
        $h_deduct   = (float)($h['advance_amount']  ?? 0)
                    + (float)($h['insurance_amount'] ?? 0)
                    + (float)($h['other_deduction']  ?? 0)
                    + (float)($h['penalty_amount']   ?? 0);

        $sal_month  = $h['salary_month'];
        $m_days     = (int)date('t', strtotime($sal_month . '-01'));

        // Get present days for history month
        $hatt = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT SUM(
                CASE
                    WHEN EXISTS (SELECT 1 FROM vacations l WHERE l.user_no=attendance.user_no AND attendance.attendance_date BETWEEN l.from_date AND l.to_date) THEN 0
                    WHEN check_in IS NOT NULL AND TRIM(check_in)!='' THEN 1
                    WHEN DAYNAME(attendance_date)='Sunday' THEN 1
                    WHEN attendance_date IN (SELECT holiday_date FROM holidays) THEN 1
                    ELSE 0
                END
            ) AS present_days
            FROM attendance
            WHERE user_no='" . mysqli_real_escape_string($conn, $user_no) . "'
              AND attendance_date LIKE '" . mysqli_real_escape_string($conn, $sal_month) . "%'
        "));
        $h_pdays = (float)($hatt['present_days'] ?? 0);

        if (isset($h['gross_total']) && $h['gross_total'] !== '') {
            $h_gross = (float)$h['gross_total'];
        } elseif ($h_pdays <= 0) {
            $h_gross = 0;
        } else {
            $h_sal_e  = $m_days > 0 ? ($h_basic / $m_days) * $h_pdays : 0;
            $h_all_e  = $m_days > 0 ? ($h_allow / $m_days) * $h_pdays : 0;
            $h_ot_amt = (float)($h['ot_amount'] ?? (($h_basic / 30 / 8) * 1.25 * $h_ot_h));
            $h_gross  = $h_sal_e + $h_all_e + $h_food + $h_att + $h_ot_amt;
        }

        $h_deduct = isset($h['total_deduction']) && $h['total_deduction'] !== ''
            ? (float)$h['total_deduction']
            : $h_deduct;
        $h_net = isset($h['net_payable']) && $h['net_payable'] !== ''
            ? (float)$h['net_payable']
            : (isset($h['net_salary']) && $h['net_salary'] !== '' ? (float)$h['net_salary'] : ($h_gross - $h_deduct));
        $h_status = $h['salary_status'] ?? 'Unpaid';
        $h_badge  = match(strtolower($h_status)) {
            'paid'      => "<span class='badge badge-paid'>✓ Paid</span>",
            'generated' => "<span class='badge badge-generated'>Generated</span>",
            default     => "<span class='badge badge-unpaid'>Unpaid</span>",
        };
?>
        <tr>
            <td><?= date('F Y', strtotime($h['salary_month'] . '-01')) ?></td>
            <td class="amt"><?= fmt($h_gross) ?> AED</td>
            <td style="color:var(--error);font-weight:700;"><?= fmt($h_deduct) ?> AED</td>
            <td style="color:var(--success);font-weight:700;"><?= fmt($h_net) ?> AED</td>
            <td><?= $h_badge ?></td>
        </tr>
<?php
    endwhile;
else:
?>
    <tr><td colspan="5" style="color:var(--text-dim);padding:20px;">No salary history found.</td></tr>
<?php
endif;
mysqli_stmt_close($hist_stmt);
?>
</tbody>
</table>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: ATTENDANCE
════════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($employee && $currentTab === 'attendance'):

    $user_no     = $employee['user_no'];
    $currentYear = date('Y');
    $months_abbr = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $rows        = ['Present','Absent','Sunday','Holidays','Vacations','OT Hours','Late','Total Count'];
    $safe_un     = mysqli_real_escape_string($conn, $user_no);
?>

<div class="section-title">Year of <?= $currentYear ?></div>

<div class="table-scroll">
<table class="att-table">
<thead>
    <tr>
        <th style="width:140px;">Category</th>
        <?php foreach ($months_abbr as $i => $m): ?>
        <th><?= $m ?><br><span style="font-size:11px;opacity:.7;"><?= $currentYear ?></span></th>
        <?php endforeach; ?>
    </tr>
</thead>
<tbody>
<?php
foreach ($rows as $rowName):
    $detail_rows_html = [];
    echo "<tr>";
    echo "<td>{$rowName}</td>";

    for ($m = 1; $m <= 12; $m++):
        $month      = $currentYear . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        $monthStart = $month . '-01';
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $did        = 'att_' . preg_replace('/\W/', '_', $rowName) . '_' . $m;
        $detailHtml = '';

        switch ($rowName) {
            case 'Present':
                $q = mysqli_query($conn, "
                    SELECT DISTINCT attendance_date FROM attendance
                    WHERE user_no='$safe_un'
                      AND attendance_date BETWEEN '$monthStart' AND '$monthEnd'
                      AND check_in IS NOT NULL AND TRIM(check_in)!=''
                    ORDER BY attendance_date ASC
                ");
                $value = mysqli_num_rows($q);
                while ($p = mysqli_fetch_assoc($q)) $detailHtml .= "<div>{$p['attendance_date']}</div>";
                break;

            case 'Total Count':
                $pd = [];
                $q = mysqli_query($conn, "
                    SELECT DISTINCT attendance_date FROM attendance
                    WHERE user_no='$safe_un'
                      AND attendance_date BETWEEN '$monthStart' AND '$monthEnd'
                      AND check_in IS NOT NULL AND TRIM(check_in)!=''
                      AND NOT EXISTS (SELECT 1 FROM vacations v WHERE v.user_no=attendance.user_no AND attendance.attendance_date BETWEEN v.from_date AND v.to_date)
                ");
                while ($p = mysqli_fetch_assoc($q)) $pd[$p['attendance_date']] = true;

                $per = new DatePeriod(new DateTime($monthStart), new DateInterval('P1D'), (new DateTime($monthEnd))->modify('+1 day'));
                foreach ($per as $date) {
                    if ($date->format('w') == 0) {
                        $d  = $date->format('Y-m-d');
                        $vq = mysqli_query($conn, "SELECT id FROM vacations WHERE user_no='$safe_un' AND '$d' BETWEEN from_date AND to_date LIMIT 1");
                        if (!$vq || mysqli_num_rows($vq) == 0) $pd[$d] = true;
                    }
                }
                $hq = mysqli_query($conn, "SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN '$monthStart' AND '$monthEnd'");
                while ($hh = mysqli_fetch_assoc($hq)) {
                    $d  = $hh['holiday_date'];
                    $vq = mysqli_query($conn, "SELECT id FROM vacations WHERE user_no='$safe_un' AND '$d' BETWEEN from_date AND to_date LIMIT 1");
                    if (!$vq || mysqli_num_rows($vq) == 0) $pd[$d] = true;
                }
                $value = count($pd);
                break;

            case 'Absent':
                $q = mysqli_query($conn, "
                    SELECT DISTINCT attendance_date FROM attendance
                    WHERE user_no='$safe_un'
                      AND attendance_date BETWEEN '$monthStart' AND '$monthEnd'
                      AND (check_in IS NULL OR TRIM(check_in)='')
                      AND DAYNAME(attendance_date)!='Sunday'
                      AND attendance_date NOT IN (SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN '$monthStart' AND '$monthEnd')
                      AND NOT EXISTS (SELECT 1 FROM vacations v WHERE v.user_no=attendance.user_no AND attendance.attendance_date BETWEEN v.from_date AND v.to_date)
                    ORDER BY attendance_date ASC
                ");
                $value = mysqli_num_rows($q);
                while ($a = mysqli_fetch_assoc($q)) $detailHtml .= "<div>{$a['attendance_date']}</div>";
                break;

            case 'Sunday':
                $value = 0;
                $per = new DatePeriod(new DateTime($monthStart), new DateInterval('P1D'), (new DateTime($monthEnd))->modify('+1 day'));
                foreach ($per as $date) {
                    if ($date->format('w') == 0) { $value++; $detailHtml .= "<div>{$date->format('Y-m-d')}</div>"; }
                }
                break;

            case 'Holidays':
                $q = mysqli_query($conn, "SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN '$monthStart' AND '$monthEnd' ORDER BY holiday_date ASC");
                $value = mysqli_num_rows($q);
                while ($hh = mysqli_fetch_assoc($q)) $detailHtml .= "<div>{$hh['holiday_date']} — {$hh['holiday_name']}</div>";
                break;

            case 'Vacations':
                $r = mysqli_fetch_assoc(mysqli_query($conn, "
                    SELECT COALESCE(SUM(DATEDIFF(LEAST(to_date,'$monthEnd'),GREATEST(from_date,'$monthStart'))+1),0) AS total
                    FROM vacations WHERE user_no='$safe_un' AND from_date<='$monthEnd' AND to_date>='$monthStart'
                "));
                $value = (int)($r['total'] ?? 0);
                break;

            case 'OT Hours':
                $otCond = "user_no='$safe_un'";
                $ec = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'employee_id'");
                if ($ec && mysqli_num_rows($ec) > 0 && !empty($employee['employee_id'])) {
                    $sei = mysqli_real_escape_string($conn, $employee['employee_id']);
                    $otCond = "(user_no='$safe_un' OR employee_id='$sei')";
                }
                $q = mysqli_query($conn, "
                    SELECT attendance_date, ot_hours FROM overtime_records
                    WHERE $otCond AND attendance_date BETWEEN '$monthStart' AND '$monthEnd' AND ot_hours > 0
                    ORDER BY attendance_date ASC
                ");
                $value = 0;
                while ($o = mysqli_fetch_assoc($q)) {
                    $value += (float)$o['ot_hours'];
                    $detailHtml .= "<div>{$o['attendance_date']} — " . number_format((float)$o['ot_hours'], 2) . " hrs</div>";
                }
                $value = round($value, 2);
                break;

            case 'Late':
                $q = mysqli_query($conn, "
                    SELECT attendance_date,
                           CASE
                               WHEN late_time IS NOT NULL AND TRIM(late_time)!=''
                                 AND TRIM(late_time) NOT IN ('00:00','00:00:00') THEN TIME_TO_SEC(late_time)
                               WHEN check_in IS NOT NULL AND TRIM(check_in)!=''
                                 AND TIME_TO_SEC(check_in) > TIME_TO_SEC('07:06:00')
                                   THEN TIME_TO_SEC(check_in) - TIME_TO_SEC('07:00:00')
                               ELSE 0
                           END AS late_seconds
                    FROM attendance
                    WHERE user_no='$safe_un'
                      AND attendance_date BETWEEN '$monthStart' AND '$monthEnd'
                      AND NOT EXISTS (
                          SELECT 1 FROM vacations v
                          WHERE v.user_no=attendance.user_no
                            AND attendance.attendance_date BETWEEN v.from_date AND v.to_date
                      )
                      AND (
                          (late_time IS NOT NULL AND TRIM(late_time)!='' AND TRIM(late_time) NOT IN ('00:00','00:00:00'))
                          OR (check_in IS NOT NULL AND TRIM(check_in)!='' AND TIME_TO_SEC(check_in) > TIME_TO_SEC('07:06:00'))
                      )
                    ORDER BY attendance_date ASC
                ");
                $value = mysqli_num_rows($q);
                while ($l = mysqli_fetch_assoc($q)) {
                    $late_minutes = round(((int)$l['late_seconds']) / 60);
                    $detailHtml .= "<div>{$l['attendance_date']} - {$late_minutes} min late</div>";
                }
                break;
                $q = mysqli_query($conn, "
                    SELECT attendance_date, late_time,
                           ROUND(TIME_TO_SEC(late_time)/60) AS late_minutes
                    FROM attendance
                    WHERE user_no='$safe_un'
                      AND attendance_date BETWEEN '$monthStart' AND '$monthEnd'
                      AND late_time IS NOT NULL AND TRIM(late_time)!=''
                      AND TRIM(late_time) NOT IN ('00:00','00:00:00')
                    ORDER BY attendance_date ASC
                ");
                $value = mysqli_num_rows($q);
                while ($l = mysqli_fetch_assoc($q)) $detailHtml .= "<div>{$l['attendance_date']} — {$l['late_minutes']} min late</div>";
                break;

            default:
                $value = 0;
        }

        $clickable = in_array($rowName, ['Present','Absent','Sunday','Holidays','OT Hours','Late']) && $value > 0;
        if ($clickable) {
            echo "<td class='att-num' onclick=\"toggleDetail('$did')\">$value</td>";
            $detail_rows_html[] = "<tr class='detail-row' id='$did'>
                <td colspan='13'><div class='detail-inner'><b>{$rowName} — " . date('F Y', strtotime($monthStart)) . "</b>{$detailHtml}</div></td>
            </tr>";
        } else {
            echo "<td>$value</td>";
        }
    endfor;

    echo "</tr>";
    foreach ($detail_rows_html as $dr) echo $dr;

endforeach;
?>
</tbody>
</table>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: VACATION
════════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($employee && $currentTab === 'vacation'):

    $user_no = $employee['user_no'];
    $vac_q = mysqli_prepare($conn, "SELECT * FROM vacations WHERE user_no=? ORDER BY from_date DESC");
    mysqli_stmt_bind_param($vac_q, 's', $user_no);
    mysqli_stmt_execute($vac_q);
    $vac_res = mysqli_stmt_get_result($vac_q);
?>

<div class="section-title">Vacation History — <?= val($employee, 'full_name') ?></div>

<div class="table-scroll">
<table class="data-table">
<thead>
    <tr>
        <th>From</th>
        <th>To</th>
        <th>Days</th>
        <th>Reason</th>
        <th>Status</th>
    </tr>
</thead>
<tbody>
<?php if (mysqli_num_rows($vac_res) > 0):
    while ($v = mysqli_fetch_assoc($vac_res)):
        $days = (int)(abs(strtotime($v['to_date']) - strtotime($v['from_date'])) / 86400) + 1;
?>
    <tr>
        <td><?= htmlspecialchars($v['from_date'], ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($v['to_date'],   ENT_QUOTES) ?></td>
        <td><strong><?= $days ?></strong></td>
        <td><?= htmlspecialchars($v['reason'] ?? '', ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($v['status'] ?? 'N/A', ENT_QUOTES) ?></td>
    </tr>
<?php endwhile;
else: ?>
    <tr><td colspan="5" style="color:var(--text-dim);padding:20px;">No vacation records found.</td></tr>
<?php endif;
mysqli_stmt_close($vac_q); ?>
</tbody>
</table>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: COMPLAINTS
════════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($employee && $currentTab === 'complain'):

    $user_no = $employee['user_no'];

    // Save new complaint
    if (isset($_POST['save_complaint'])) {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO complaints (user_no, employee_name, complaint_type, complaint_subject, complaint_details, complaint_date, priority_level)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ct = $_POST['complaint_type'];
        $cs = $_POST['complaint_subject'];
        $cd = $_POST['complaint_details'];
        $cdate = $_POST['complaint_date'];
        $pl = $_POST['priority_level'];
        $fn = $employee['full_name'];
        mysqli_stmt_bind_param($stmt, 'sssssss', $user_no, $fn, $ct, $cs, $cd, $cdate, $pl);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "<div class='msg success'><span>✓</span> Complaint submitted successfully.</div>";
    }

    // Update complaint
    if (isset($_POST['update_complaint'])) {
        $stmt = mysqli_prepare($conn, "UPDATE complaints SET complaint_status=?, hr_reply=? WHERE id=?");
        $cstatus = $_POST['complaint_status'];
        $reply   = $_POST['hr_reply'];
        $cid     = (int)$_POST['complaint_id'];
        mysqli_stmt_bind_param($stmt, 'ssi', $cstatus, $reply, $cid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "<div class='msg success'><span>✓</span> Complaint updated.</div>";
    }
?>

<div class="card" style="margin-bottom:22px;">
    <div class="card-header">📝 Submit New Complaint</div>
    <div class="card-body">
    <form method="POST">
        <div class="complaint-form-grid">
            <div class="form-group">
                <label>Complaint Type</label>
                <select name="complaint_type" required>
                    <option value="">— Select —</option>
                    <?php foreach (['Salary','Attendance','HR','Vacation','Accommodation','Food','Other'] as $ct): ?>
                    <option value="<?= $ct ?>"><?= $ct ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Priority Level</label>
                <select name="priority_level" required>
                    <option value="Low">🟢 Low</option>
                    <option value="Medium" selected>🟡 Medium</option>
                    <option value="High">🔴 High</option>
                </select>
            </div>
            <div class="form-group">
                <label>Complaint Subject</label>
                <input type="text" name="complaint_subject" required>
            </div>
            <div class="form-group">
                <label>Complaint Date</label>
                <input type="date" name="complaint_date" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
            <label>Complaint Details</label>
            <textarea name="complaint_details" style="height:100px;" required></textarea>
        </div>
        <button type="submit" name="save_complaint" class="btn">📤 Submit Complaint</button>
    </form>
    </div>
</div>

<div class="section-title">Complaint History</div>

<div class="table-scroll">
<table class="data-table">
<thead>
    <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Subject</th>
        <th>Priority</th>
        <th>Status</th>
        <th>HR Reply</th>
        <th style="width:320px;">Action</th>
    </tr>
</thead>
<tbody>
<?php
$comp_stmt = mysqli_prepare($conn, "SELECT * FROM complaints WHERE user_no=? ORDER BY complaint_date DESC, id DESC");
mysqli_stmt_bind_param($comp_stmt, 's', $user_no);
mysqli_stmt_execute($comp_stmt);
$comp_res = mysqli_stmt_get_result($comp_stmt);

if (mysqli_num_rows($comp_res) > 0):
    while ($c = mysqli_fetch_assoc($comp_res)):
        $p_class = match($c['priority_level'] ?? '') {
            'Low'  => 'priority-low',
            'High' => 'priority-high',
            default => 'priority-medium',
        };
        $s_class = match($c['complaint_status'] ?? 'Pending') {
            'Solved'      => 'status-solved',
            'In Progress' => 'status-inprogress',
            'Rejected'    => 'status-rejected',
            default       => 'status-pending',
        };
?>
    <tr>
        <td><?= htmlspecialchars($c['complaint_date'] ?? '', ENT_QUOTES) ?></td>
        <td><?= htmlspecialchars($c['complaint_type'] ?? '', ENT_QUOTES) ?></td>
        <td style="text-align:left;"><?= htmlspecialchars($c['complaint_subject'] ?? '', ENT_QUOTES) ?></td>
        <td><span class="priority-badge <?= $p_class ?>"><?= htmlspecialchars($c['priority_level'] ?? '', ENT_QUOTES) ?></span></td>
        <td class="<?= $s_class ?>"><?= htmlspecialchars($c['complaint_status'] ?? 'Pending', ENT_QUOTES) ?></td>
        <td style="text-align:left;"><?= htmlspecialchars($c['hr_reply'] ?? 'No reply yet', ENT_QUOTES) ?></td>
        <td>
            <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="complaint_id" value="<?= (int)$c['id'] ?>">
                <select name="complaint_status" style="width:120px;font-size:13px;">
                    <?php foreach (['Pending','In Progress','Solved','Rejected'] as $st): ?>
                    <option value="<?= $st ?>" <?= ($c['complaint_status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="hr_reply"
                       value="<?= htmlspecialchars($c['hr_reply'] ?? '', ENT_QUOTES) ?>"
                       placeholder="HR Reply"
                       style="width:150px;font-size:13px;">
                <button type="submit" name="update_complaint" class="btn" style="padding:7px 12px;font-size:13px;">Update</button>
            </form>
        </td>
    </tr>
<?php endwhile;
else: ?>
    <tr><td colspan="7" style="color:var(--text-dim);padding:20px;">No complaints found.</td></tr>
<?php endif;
mysqli_stmt_close($comp_stmt); ?>
</tbody>
</table>
</div>

<?php
/* ══ TAB: AIR TICKET ══════════════════════════════════════════════════════ */
elseif ($employee && $currentTab === 'airticket'):
    $au = mysqli_real_escape_string($conn, $employee['user_no']);
    $at_res = mysqli_query($conn, "SELECT * FROM employee_airtickets WHERE user_no='$au' ORDER BY COALESCE(travel_date, created_at) DESC, id DESC");
    $at_total = 0.0; $at_company = 0.0; $at_rows = [];
    if ($at_res) { while ($r = mysqli_fetch_assoc($at_res)) { $at_rows[] = $r; $at_total += (float)$r['amount']; if (strtolower($r['provided_by']) === 'company') { $at_company += (float)$r['amount']; } } }
?>
<div class="card" style="margin-bottom:18px;">
    <div class="card-header">✈️ Air Ticket Details
        <span style="float:right;font-weight:600;font-size:13px;">Company Expense: <?= fmt($at_company) ?> AED &middot; Total: <?= fmt($at_total) ?> AED</span>
    </div>
    <div class="card-body">
        <?php if ($canEditEmployee): ?>
        <form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;">
            <input type="hidden" name="user_no" value="<?= val($employee,'user_no') ?>">
            <input type="hidden" name="employee_id" value="<?= val($employee,'employee_id') ?>">
            <div><label style="font-size:12px;display:block;">Provided By</label>
                <select name="provided_by"><option>Company</option><option>Self</option><option>Other</option></select></div>
            <div><label style="font-size:12px;display:block;">From</label><input type="text" name="from_location" placeholder="e.g. Sharjah" required></div>
            <div><label style="font-size:12px;display:block;">To</label><input type="text" name="to_location" placeholder="e.g. Kathmandu" required></div>
            <div><label style="font-size:12px;display:block;">Travel Date</label><input type="date" name="travel_date"></div>
            <div><label style="font-size:12px;display:block;">Return Date</label><input type="date" name="return_date"></div>
            <div><label style="font-size:12px;display:block;">Airline</label><input type="text" name="airline" placeholder="optional"></div>
            <div><label style="font-size:12px;display:block;">Ticket No</label><input type="text" name="ticket_no" placeholder="optional"></div>
            <div><label style="font-size:12px;display:block;">Amount (AED)</label><input type="number" step="0.01" name="amount" value="0"></div>
            <div><label style="font-size:12px;display:block;">Remarks</label><input type="text" name="remarks" placeholder="optional"></div>
            <button type="submit" name="save_airticket" class="btn">➕ Add Ticket</button>
        </form>
        <?php endif; ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th>#</th><th>Provided By</th><th>From → To</th><th>Travel</th><th>Return</th><th>Airline</th><th>Ticket No</th><th>Amount (AED)</th><th>Remarks</th><?php if ($canEditEmployee): ?><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (!empty($at_rows)): $i = 1; foreach ($at_rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($r['provided_by'], ENT_QUOTES) ?></td>
                    <td style="text-align:left;"><b><?= htmlspecialchars($r['from_location'], ENT_QUOTES) ?></b> → <b><?= htmlspecialchars($r['to_location'], ENT_QUOTES) ?></b></td>
                    <td><?= $r['travel_date'] ? date('d-M-Y', strtotime($r['travel_date'])) : '-' ?></td>
                    <td><?= $r['return_date'] ? date('d-M-Y', strtotime($r['return_date'])) : '-' ?></td>
                    <td><?= htmlspecialchars($r['airline'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($r['ticket_no'], ENT_QUOTES) ?></td>
                    <td><?= fmt((float)$r['amount']) ?></td>
                    <td style="text-align:left;"><?= htmlspecialchars($r['remarks'], ENT_QUOTES) ?></td>
                    <?php if ($canEditEmployee): ?>
                    <td><form method="POST" onsubmit="return confirm('Delete this air ticket?');"><button type="submit" name="delete_airticket" value="<?= (int)$r['id'] ?>" class="btn" style="background:#fdecea;color:#c0392b;padding:6px 10px;font-size:12px;">Delete</button></form></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="<?= $canEditEmployee ? 10 : 9 ?>" style="color:var(--text-dim);padding:20px;">No air ticket records yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
/* ══ TAB: VISA RENEWAL ════════════════════════════════════════════════════ */
elseif ($employee && $currentTab === 'visarenewal'):
    $vu = mysqli_real_escape_string($conn, $employee['user_no']);
    $vr_res = mysqli_query($conn, "SELECT * FROM employee_visa_renewals WHERE user_no='$vu' ORDER BY COALESCE(renew_from, created_at) ASC, id ASC");
    $vr_rows = []; $vr_cost = 0.0;
    if ($vr_res) { while ($r = mysqli_fetch_assoc($vr_res)) { $vr_rows[] = $r; $vr_cost += (float)$r['cost']; } }
    $vr_count = count($vr_rows);
?>
<div class="card" style="margin-bottom:18px;">
    <div class="card-header">🛂 Visa Renewal Details
        <span style="float:right;font-weight:600;font-size:13px;">Total Renewals: <?= $vr_count ?> &middot; Total Cost: <?= fmt($vr_cost) ?> AED</span>
    </div>
    <div class="card-body">
        <?php if ($canEditEmployee): ?>
        <form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;">
            <input type="hidden" name="user_no" value="<?= val($employee,'user_no') ?>">
            <input type="hidden" name="employee_id" value="<?= val($employee,'employee_id') ?>">
            <div><label style="font-size:12px;display:block;">Renewal From</label><input type="date" name="renew_from" required></div>
            <div><label style="font-size:12px;display:block;">Renewal To (Expiry)</label><input type="date" name="renew_to" required></div>
            <div><label style="font-size:12px;display:block;">Cost (AED)</label><input type="number" step="0.01" name="cost" value="0"></div>
            <div><label style="font-size:12px;display:block;">Remarks</label><input type="text" name="remarks" placeholder="optional"></div>
            <button type="submit" name="save_visarenewal" class="btn">➕ Add Renewal</button>
        </form>
        <?php endif; ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th>Renewal #</th><th>From</th><th>To (Expiry)</th><th>Duration</th><th>Cost (AED)</th><th>Remarks</th><th>Added By</th><?php if ($canEditEmployee): ?><th>Action</th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (!empty($vr_rows)): $i = 1; foreach ($vr_rows as $r):
                $dur = '';
                if (!empty($r['renew_from']) && !empty($r['renew_to'])) {
                    $d1 = strtotime($r['renew_from']); $d2 = strtotime($r['renew_to']);
                    if ($d1 && $d2 && $d2 >= $d1) { $dur = round(($d2 - $d1) / 86400 / 365, 1) . ' yr'; }
                }
            ?>
                <tr>
                    <td><b><?= $i++ ?></b></td>
                    <td><?= $r['renew_from'] ? date('d-M-Y', strtotime($r['renew_from'])) : '-' ?></td>
                    <td><?= $r['renew_to'] ? date('d-M-Y', strtotime($r['renew_to'])) : '-' ?></td>
                    <td><?= $dur !== '' ? $dur : '-' ?></td>
                    <td><?= fmt((float)$r['cost']) ?></td>
                    <td style="text-align:left;"><?= htmlspecialchars($r['remarks'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($r['created_by'], ENT_QUOTES) ?></td>
                    <?php if ($canEditEmployee): ?>
                    <td><form method="POST" onsubmit="return confirm('Delete this visa renewal?');"><button type="submit" name="delete_visarenewal" value="<?= (int)$r['id'] ?>" class="btn" style="background:#fdecea;color:#c0392b;padding:6px 10px;font-size:12px;">Delete</button></form></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="<?= $canEditEmployee ? 8 : 7 ?>" style="color:var(--text-dim);padding:20px;">No visa renewal records yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
/* ══ TAB: DOCUMENTS ═══════════════════════════════════════════════════════ */
elseif ($employee && $currentTab === 'documents'):
    $du = mysqli_real_escape_string($conn, $employee['user_no']);
    $doc_res = mysqli_query($conn, "SELECT * FROM employee_documents WHERE user_no='$du' ORDER BY id DESC");
    $doc_rows = [];
    if ($doc_res) { while ($r = mysqli_fetch_assoc($doc_res)) { $doc_rows[] = $r; } }
?>
<div class="card" style="margin-bottom:18px;">
    <div class="card-header">📎 Employee Documents
        <span style="float:right;font-weight:600;font-size:13px;">Total: <?= count($doc_rows) ?></span>
    </div>
    <div class="card-body">
        <?php if ($canEditEmployee): ?>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;">
            <input type="hidden" name="user_no" value="<?= val($employee,'user_no') ?>">
            <input type="hidden" name="employee_id" value="<?= val($employee,'employee_id') ?>">
            <div><label style="font-size:12px;display:block;">Document Type</label>
                <select name="doc_type">
                    <?php foreach ($document_type_options as $dt_opt): ?>
                    <option value="<?= htmlspecialchars($dt_opt, ENT_QUOTES) ?>"><?= htmlspecialchars($dt_opt, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;display:block;">Document No (optional)</label><input type="text" name="doc_number" placeholder="e.g. 784-xxxx"></div>
            <div><label style="font-size:12px;display:block;">File (PDF / JPG / PNG)</label><input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png" required></div>
            <div><label style="font-size:12px;display:block;">Remarks</label><input type="text" name="doc_remarks" placeholder="optional"></div>
            <button type="submit" name="save_document" class="btn">⬆️ Upload</button>
        </form>
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">JPG/PNG files are shown as a preview. PDF files are not previewed but can be downloaded. Max 10&nbsp;MB.</div>
        <?php endif; ?>

        <?php if (!empty($doc_rows)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:16px;">
            <?php foreach ($doc_rows as $r):
                $ext = strtolower($r['file_ext']);
                $url = 'uploads/documents/' . rawurlencode($r['file_name']);
                $is_img = in_array($ext, ['jpg','jpeg','png'], true);
                /* Download filename based on the document type (+ number) so the
                   file downloads as e.g. "Medical Certificate.pdf". */
                $dl_name = (string)$r['doc_type'];
                if (trim((string)$r['doc_number']) !== '') { $dl_name .= ' ' . $r['doc_number']; }
                $dl_name = trim(preg_replace('/[^A-Za-z0-9 _.\-]/', '', $dl_name));
                if ($dl_name === '') { $dl_name = 'document'; }
                $dl_name .= '.' . $ext;
            ?>
            <div style="width:230px;border:1px solid var(--border,#e2e8f0);border-radius:10px;overflow:hidden;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.06);">
                <div style="background:#1a3a5c;color:#fff;padding:7px 10px;font-size:12px;font-weight:700;display:flex;justify-content:space-between;align-items:center;">
                    <span><?= htmlspecialchars($r['doc_type'], ENT_QUOTES) ?></span>
                    <span style="text-transform:uppercase;font-size:10px;opacity:.85;"><?= htmlspecialchars($ext, ENT_QUOTES) ?></span>
                </div>
                <?php if ($is_img): ?>
                    <a href="<?= $url ?>" target="_blank" title="Open full image">
                        <img src="<?= $url ?>" alt="document" style="width:100%;height:150px;object-fit:cover;display:block;">
                    </a>
                <?php else: ?>
                    <div style="height:150px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#f8fafc;color:#b91c1c;">
                        <div style="font-size:46px;">📄</div>
                        <div style="font-size:12px;color:#475569;margin-top:4px;">PDF document</div>
                    </div>
                <?php endif; ?>
                <div style="padding:9px 10px;">
                    <?php if ($r['doc_number'] !== ''): ?><div style="font-size:12px;"><b>No:</b> <?= htmlspecialchars($r['doc_number'], ENT_QUOTES) ?></div><?php endif; ?>
                    <?php if ($r['remarks'] !== ''): ?><div style="font-size:12px;color:#475569;"><?= htmlspecialchars($r['remarks'], ENT_QUOTES) ?></div><?php endif; ?>
                    <div style="font-size:11px;color:#94a3b8;margin:4px 0 8px;"><?= $r['created_at'] ? date('d-M-Y', strtotime($r['created_at'])) : '' ?></div>
                    <div style="display:flex;gap:6px;">
                        <?php if ($is_img): ?>
                        <a href="<?= $url ?>" target="_blank" class="btn" style="padding:5px 10px;font-size:12px;flex:1;text-align:center;">🔍 View</a>
                        <?php endif; ?>
                        <a href="<?= $url ?>" download="<?= htmlspecialchars($dl_name, ENT_QUOTES) ?>" class="btn" style="padding:5px 10px;font-size:12px;flex:1;text-align:center;">⬇️ Download</a>
                        <?php if ($canEditEmployee): ?>
                        <form method="POST" onsubmit="return confirm('Delete this document?');" style="margin:0;">
                            <button type="submit" name="delete_document" value="<?= (int)$r['id'] ?>" class="btn" style="background:#fdecea;color:#c0392b;padding:5px 10px;font-size:12px;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div style="color:var(--text-dim);padding:20px;">No documents uploaded yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php
/* ══ TAB: GATE PASS ═══════════════════════════════════════════════════════ */
elseif ($employee && $currentTab === 'gatepass' && hasPermission('gate_pass_manage')):
    include 'gate_pass_panel.php';
?>

<?php
/* ══ TAB: MEMO ════════════════════════════════════════════════════════════ */
elseif ($employee && $currentTab === 'memo' && hasPermission('memo_manage')):
    include 'memo_panel.php';
?>

<?php else: ?>

<!-- ══ No employee searched yet ══════════════════════════════════════════════ -->
<div class="card">
    <div class="empty-state">
        <div class="icon">🔍</div>
        <p>Search an employee by <strong>User No</strong>, <strong>Name</strong>, or <strong>Employee ID</strong> to view their details.</p>
    </div>
</div>

<?php endif; ?>

</div><!-- /content-pad -->
</main>

<script>
function toggleMenu(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

function toggleDetail(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = (el.style.display === 'table-row') ? 'none' : 'table-row';
}

<?php if (!$canEditEmployee): ?>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#employeeDetailsForm input, #employeeDetailsForm textarea, #employeeDetailsForm select').forEach(function (el) {
        el.disabled = true;
    });
});
<?php endif; ?>
</script>

</body>
</html>
