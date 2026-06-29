<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');

$month = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$search = trim($_GET['search'] ?? '');
$safe_month = mysqli_real_escape_string($conn, $month);
$safe_search = mysqli_real_escape_string($conn, $search);
$month_title = date('F Y', strtotime($month . '-01'));
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

$employee_columns = [];
$col_result = mysqli_query($conn, "SHOW COLUMNS FROM employees");
if ($col_result) {
    while ($col = mysqli_fetch_assoc($col_result)) {
        $employee_columns[$col['Field']] = true;
    }
}

$status_col = isset($employee_columns['employee_status'])
    ? 'employee_status'
    : (isset($employee_columns['status']) ? 'status' : null);

/* Resigned employees — and those serving notice — must still appear so their
   absent days (up to their last working day) are counted. So we DO NOT filter
   by status; we only cap rows at the effective last-working day, which is the
   LATEST of resign_date, visa_cancellations.last_working_date and
   notice_period_end. */
$active_employee_condition = "e.user_no IS NOT NULL";

$absent_vc_join = "";
$cutoff_exprs   = [];
if (isset($employee_columns['resign_date'])) {
    $cutoff_exprs[] = "NULLIF(NULLIF(e.resign_date,''),'0000-00-00')";
}
$vc_check = mysqli_query($conn, "SHOW TABLES LIKE 'visa_cancellations'");
if ($vc_check && mysqli_num_rows($vc_check) > 0) {
    $vc_cols  = [];
    $vc_col_q = mysqli_query($conn, "SHOW COLUMNS FROM visa_cancellations");
    if ($vc_col_q) while ($c = mysqli_fetch_assoc($vc_col_q)) $vc_cols[$c['Field']] = true;
    $vc_sel = [];
    if (isset($vc_cols['last_working_date'])) $vc_sel[] = "MAX(NULLIF(NULLIF(last_working_date,''),'0000-00-00')) AS lwd";
    if (isset($vc_cols['notice_period_end'])) $vc_sel[] = "MAX(NULLIF(NULLIF(notice_period_end,''),'0000-00-00')) AS npe";
    if (!empty($vc_sel)) {
        $absent_vc_join = "
    LEFT JOIN (
        SELECT TRIM(user_no) AS user_no, " . implode(', ', $vc_sel) . "
        FROM visa_cancellations
        GROUP BY TRIM(user_no)
    ) vc ON vc.user_no = TRIM(a.user_no)";
        if (isset($vc_cols['last_working_date'])) $cutoff_exprs[] = "vc.lwd";
        if (isset($vc_cols['notice_period_end'])) $cutoff_exprs[] = "vc.npe";
    }

    /* Exit signals — an employee is dropped from the absent figures once they
       have effectively left. Either:
         • the visa has been cancelled (Cancel Date is filled), or
         • the cancellation reason is Absconding.
       Anyone with a blank (not-yet-cancelled) visa is still on duty and keeps
       counting normally (absent shows as absent). */
    $exit_conds = [];
    if (isset($vc_cols['cancellation_reason'])) {
        $exit_conds[] = "LOWER(TRIM(vc_x.cancellation_reason)) = 'absconding'";
    }
    if (isset($vc_cols['visa_cancellation_date'])) {
        $exit_conds[] = "(vc_x.visa_cancellation_date IS NOT NULL AND TRIM(vc_x.visa_cancellation_date) != '' AND vc_x.visa_cancellation_date != '0000-00-00')";
    }
    if (!empty($exit_conds)) {
        $active_employee_condition .= "
        AND NOT EXISTS (
            SELECT 1 FROM visa_cancellations vc_x
            WHERE TRIM(vc_x.user_no) = TRIM(a.user_no)
              AND (" . implode(' OR ', $exit_conds) . ")
        )";
    }
}
if (!empty($cutoff_exprs)) {
    $no_cutoff = '(' . implode(' AND ', array_map(fn($e) => "$e IS NULL", $cutoff_exprs)) . ')';
    $within    = '(' . implode(' OR ', array_map(fn($e) => "a.attendance_date <= $e", $cutoff_exprs)) . ')';
    $active_employee_condition .= " AND ($no_cutoff OR $within)";
}

$where_search = "";
if ($search !== '') {
    $where_search = "
        AND (
            a.user_no LIKE '%$safe_search%'
            OR a.employee_name LIKE '%$safe_search%'
            OR e.full_name LIKE '%$safe_search%'
            OR e.employee_id LIKE '%$safe_search%'
        )
    ";
}

$vacation_effective_to = "COALESCE(NULLIF(v.return_date,'0000-00-00'), v.to_date)";

$absent_sql = "
    SELECT
        a.user_no,
        COALESCE(NULLIF(e.full_name,''), NULLIF(a.employee_name,''), a.user_no) AS employee_name,
        COALESCE(e.department, '') AS department,
        GROUP_CONCAT(DATE_FORMAT(a.attendance_date, '%d-%m-%Y') ORDER BY a.attendance_date ASC SEPARATOR ', ') AS absent_dates,
        COUNT(*) AS absent_days
    FROM attendance a
    INNER JOIN employees e ON TRIM(e.user_no) = TRIM(a.user_no)$absent_vc_join
    WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = '$safe_month'
      AND (a.check_in IS NULL OR TRIM(a.check_in) = '')
      AND DAYNAME(a.attendance_date) != 'Sunday'
      AND a.attendance_date NOT IN (SELECT holiday_date FROM holidays)
      AND $active_employee_condition
      AND NOT EXISTS (
          SELECT 1
          FROM vacations v
          WHERE TRIM(v.user_no) = TRIM(a.user_no)
            AND a.attendance_date BETWEEN v.from_date AND $vacation_effective_to
            AND COALESCE(v.vacation_status,'') != 'Cancelled'
      )
      $where_search
    GROUP BY a.user_no, employee_name, department
    HAVING absent_days > 0
    ORDER BY absent_days DESC, CAST(a.user_no AS UNSIGNED) ASC
";

$absent_query = mysqli_query($conn, $absent_sql);
$absent_rows = [];
$total_absent_days = 0;
if ($absent_query) {
    while ($row = mysqli_fetch_assoc($absent_query)) {
        $row['absent_days'] = (int)($row['absent_days'] ?? 0);
        $total_absent_days += $row['absent_days'];
        $absent_rows[] = $row;
    }
}
$total_absent_employees = count($absent_rows);

function absent_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Absent Details</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial, sans-serif;background:#eef1f6;color:#172033;padding:28px 32px;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;}
.page-title{font-size:24px;font-weight:700;color:#0f172a;}
.btn{display:inline-flex;align-items:center;gap:6px;background:#1e293b;color:#fff;padding:10px 18px;text-decoration:none;border-radius:8px;border:0;cursor:pointer;font-size:14px;font-weight:700;}
.btn:hover{background:#0f172a;}
.btn-search{background:#2563eb;}
.btn-reset{background:#64748b;}
.search-card,.summary-card,.section-block{background:#fff;border:1px solid #d9e2ef;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08);}
.search-card{padding:20px;margin-bottom:20px;}
.search-card form{display:flex;align-items:end;gap:12px;flex-wrap:wrap;}
label{font-weight:700;font-size:13px;color:#334155;display:block;margin-bottom:6px;}
input{height:42px;border:1px solid #cbd5e1;border-radius:7px;padding:0 12px;font-size:15px;min-width:220px;background:#fff;}
.summary-row{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.summary-card{padding:20px;display:flex;align-items:center;gap:14px;}
.summary-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;background:#fee2e2;}
.summary-num{font-size:30px;font-weight:800;color:#dc2626;line-height:1;}
.summary-num.blue{color:#2563eb;}
.summary-label{font-size:13px;color:#475569;margin-top:5px;}
.section-block{overflow:hidden;}
.section-header{display:flex;align-items:center;justify-content:space-between;background:#1e293b;color:#fff;padding:14px 18px;font-weight:800;}
.section-badge{background:#fee2e2;color:#b91c1c;border-radius:999px;padding:4px 10px;font-size:12px;margin-left:10px;}
table{width:100%;border-collapse:collapse;background:#fff;}
th{background:#1e293b;color:#fff;text-align:left;padding:13px 16px;font-size:13px;text-transform:uppercase;}
td{border-bottom:1px solid #e5e7eb;padding:13px 16px;font-size:14px;}
tr:nth-child(even){background:#f8fafc;}
.sl{color:#64748b;font-weight:700;}
.user-no{font-weight:800;color:#2563eb;}
.absent-badge{display:inline-block;background:#fee2e2;color:#b91c1c;border-radius:999px;padding:5px 11px;font-weight:800;}
.dates-cell{line-height:1.8;color:#334155;}
.no-record{text-align:center;padding:30px;color:#16a34a;font-weight:800;}
@media(max-width:900px){.summary-row{grid-template-columns:1fr}.search-card form{display:block}.btn,input{margin-top:8px;width:100%;}}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="page-header">
    <h1 class="page-title">&#128197; Absent Details</h1>
    <a href="dashboard.php" class="btn">&#9776; Dashboard</a>
</div>

<div class="search-card">
    <form method="GET">
        <div>
            <label>Month</label>
            <input type="month" name="month" value="<?php echo absent_h($month); ?>">
        </div>
        <div>
            <label>Search</label>
            <input type="text" name="search" value="<?php echo absent_h($search); ?>" placeholder="User No / Employee Name">
        </div>
        <button type="submit" class="btn btn-search">&#128269; Search</button>
        <a href="absent_details.php" class="btn btn-reset">&#10006; Reset</a>
    </form>
</div>

<div class="summary-row">
    <div class="summary-card">
        <div class="summary-icon">&#128197;</div>
        <div>
            <div class="summary-num"><?php echo $total_absent_employees; ?></div>
            <div class="summary-label">Absent Employees - <?php echo absent_h($month_title); ?></div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon">&#128198;</div>
        <div>
            <div class="summary-num blue"><?php echo $total_absent_days; ?></div>
            <div class="summary-label">Total Absent Days</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon">&#9989;</div>
        <div>
            <div class="summary-num blue"><?php echo absent_h($month_title); ?></div>
            <div class="summary-label">Selected Month</div>
        </div>
    </div>
</div>

<div class="section-block">
    <div class="section-header">
        <div>Absent Employee List <span class="section-badge"><?php echo $total_absent_employees; ?> employees</span></div>
    </div>
    <table>
        <thead>
            <tr>
                <th style="width:60px;">SL</th>
                <th>User No</th>
                <th>Employee Name</th>
                <th>Department</th>
                <th style="width:130px;">Absent Days</th>
                <th>Absent Dates</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($absent_rows)): ?>
                <?php $sl = 1; foreach ($absent_rows as $row): ?>
                <tr>
                    <td class="sl"><?php echo $sl++; ?></td>
                    <td class="user-no"><?php echo absent_h($row['user_no']); ?></td>
                    <td><?php echo absent_h($row['employee_name']); ?></td>
                    <td><?php echo absent_h($row['department']); ?></td>
                    <td><span class="absent-badge"><?php echo (int)$row['absent_days']; ?> days</span></td>
                    <td class="dates-cell"><?php echo absent_h($row['absent_dates']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="no-record">No absent employee found for <?php echo absent_h($month_title); ?>.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
