<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');
vacation_ensure_schema($conn);

$search = $_GET['search'] ?? '';
$dept_filter = $_GET['department'] ?? '';
$nationality_filter = $_GET['nationality'] ?? '';
$status_filter = $_GET['status'] ?? '';
$today = date('Y-m-d');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$current_month_title = date('F Y');

$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));
$last_month_title = date('F Y', strtotime('-1 month'));

$search_safe = mysqli_real_escape_string($conn, $search);
$dept_safe = mysqli_real_escape_string($conn, $dept_filter);
$nationality_safe = mysqli_real_escape_string($conn, $nationality_filter);
$status_safe = mysqli_real_escape_string($conn, $status_filter);

$vacation_where = "";
if ($search != "") {
    $vacation_where .= " AND (user_no LIKE '%$search_safe%' OR employee_name LIKE '%$search_safe%')";
}
if ($dept_filter != "") {
    $vacation_where .= " AND department = '$dept_safe'";
}
if ($nationality_filter != "") {
    $vacation_where .= " AND nationality = '$nationality_safe'";
}
if ($status_filter != "") {
    // Only filter by stored vacation_status for statuses that are actually stored in DB
    // For computed statuses (On Vacation, Over Stayed, etc.) use date-based logic
    $status_lower = strtolower($status_filter);
    if (in_array($status_lower, ['cancelled', 'ticket processing', 'travelled'])) {
        $vacation_where .= " AND LOWER(vacation_status) = '$status_lower'";
    } elseif ($status_filter === 'On Vacation') {
        $vacation_where .= " AND from_date <= '$today' AND to_date >= '$today' AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
    } elseif ($status_filter === 'Returned') {
        $vacation_where .= " AND ((return_date IS NOT NULL AND return_date != '' AND return_date != '0000-00-00' AND return_date <= '$today') OR LOWER(vacation_status) = 'returned')";
    } elseif ($status_filter === 'Over Stayed') {
        $vacation_where .= " AND to_date < '$today' AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00') AND (actual_return IS NULL OR actual_return='' OR actual_return='0000-00-00') AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
    } elseif ($status_filter === 'Pending Approval') {
        $vacation_where .= " AND from_date > '$today' AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
    } elseif ($status_filter === 'Approved') {
        // Show upcoming vacations (not yet started, not cancelled)
        $vacation_where .= " AND from_date > '$today' AND COALESCE(vacation_status,'') NOT IN ('Cancelled')";
    } else {
        // Fallback - try stored value
        $vacation_where .= " AND vacation_status = '$status_safe'";
    }
}

$vacation_where .= "
    AND (reason IS NULL OR (
        reason NOT LIKE '%Compensatory Off%'
        AND reason NOT LIKE '%swapped with%'
        AND reason NOT LIKE '%day swap%'
        AND reason NOT LIKE '%compensatory work day%'
    ))";

// Get departments and nationalities for filter dropdowns
$dept_list = mysqli_query($conn, "SELECT DISTINCT department FROM vacations WHERE department != '' ORDER BY department");
$nat_list = mysqli_query($conn, "SELECT DISTINCT nationality FROM vacations WHERE nationality != '' ORDER BY nationality");


/* ===== SUMMARY COUNTS ===== */

// Summary counts use a base filter WITHOUT status filter (to show true totals on cards)
$count_where_base = "";
if ($search != "") {
    $count_where_base .= " AND (user_no LIKE '%$search_safe%' OR employee_name LIKE '%$search_safe%')";
}
if ($dept_filter != "") {
    $count_where_base .= " AND department = '$dept_safe'";
}
if ($nationality_filter != "") {
    $count_where_base .= " AND nationality = '$nationality_safe'";
}
$count_where_base .= "
    AND (reason IS NULL OR (
        reason NOT LIKE '%Compensatory Off%'
        AND reason NOT LIKE '%swapped with%'
        AND reason NOT LIKE '%day swap%'
        AND reason NOT LIKE '%compensatory work day%'
    ))";

// Total Vacation Records
$total_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM vacations WHERE 1=1 $count_where_base");
$total_vacation = (int)(mysqli_fetch_assoc($total_query)['total'] ?? 0);

// Pending Approval (future vacations not yet started)
$pending_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE from_date > '$today'
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
      $count_where_base
");
$pending_approval = (int)(mysqli_fetch_assoc($pending_query)['total'] ?? 0);

// Approved Today
$approved_today_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE vacation_status = 'Approved'
      AND DATE(created_at) = '$today' $count_where_base
");
$approved_today = (int)(mysqli_fetch_assoc($approved_today_query)['total'] ?? 0);

// Going This Month (from_date falls in this month)
$going_this_month_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE from_date BETWEEN '$current_month_start' AND '$current_month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $count_where_base
");
$going_this_month = (int)(mysqli_fetch_assoc($going_this_month_query)['total'] ?? 0);

// Now On Vacation (count records, not distinct - same as dashboard)
$now_on_vacation_query = mysqli_query($conn, "
    SELECT COUNT(*) AS total FROM vacations
    WHERE from_date <= '$today' AND to_date >= '$today'
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $count_where_base
");
$now_on_vacation = (int)(mysqli_fetch_assoc($now_on_vacation_query)['total'] ?? 0);

// Returning Today
$returning_today_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE to_date = '$today'
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $count_where_base
");
$returning_today = (int)(mysqli_fetch_assoc($returning_today_query)['total'] ?? 0);

// Overdue / Overstay
$overdue_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE to_date < '$today'
      AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00')
      AND (actual_return IS NULL OR actual_return='' OR actual_return='0000-00-00')
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $count_where_base
");
$overdue_return = (int)(mysqli_fetch_assoc($overdue_query)['total'] ?? 0);

// This Month Total
$this_month_total_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE from_date <= '$current_month_end' AND to_date >= '$current_month_start'
      AND COALESCE(vacation_status,'') != 'Cancelled' $count_where_base
");
$this_month_total = (int)(mysqli_fetch_assoc($this_month_total_query)['total'] ?? 0);

// Last Month Returned
$last_month_returned_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total FROM vacations
    WHERE (return_date BETWEEN '$last_month_start' AND '$last_month_end'
       OR actual_return BETWEEN '$last_month_start' AND '$last_month_end')
      AND COALESCE(vacation_status,'') != 'Cancelled' $count_where_base
");
$last_month_returned = (int)(mysqli_fetch_assoc($last_month_returned_query)['total'] ?? 0);


/* ===== TAB DATA QUERIES ===== */

// Base where clause (without status filter) for Tab 1 and Tab 2
$tab_where_base = "";
if ($search != "") {
    $tab_where_base .= " AND (user_no LIKE '%$search_safe%' OR employee_name LIKE '%$search_safe%')";
}
if ($dept_filter != "") {
    $tab_where_base .= " AND department = '$dept_safe'";
}
if ($nationality_filter != "") {
    $tab_where_base .= " AND nationality = '$nationality_safe'";
}
$tab_where_base .= "
    AND (reason IS NULL OR (
        reason NOT LIKE '%Compensatory Off%'
        AND reason NOT LIKE '%swapped with%'
        AND reason NOT LIKE '%day swap%'
        AND reason NOT LIKE '%compensatory work day%'
    ))";

// TAB 1: This Month On Vacation (no status filter — shows all active this month)
// Group by user_no + from_date + to_date to avoid duplicate rows
$tab1_query = mysqli_query($conn, "
    SELECT v.*, DATEDIFF(v.to_date, v.from_date) + 1 AS vacation_days
    FROM vacations v
    INNER JOIN (
        SELECT MIN(id) AS id FROM vacations
        WHERE from_date <= '$current_month_end'
          AND to_date >= '$current_month_start'
          AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
          $tab_where_base
        GROUP BY user_no, from_date, to_date
    ) dup ON v.id = dup.id
    ORDER BY v.from_date DESC
");

// TAB 2: Last Month Returned (no status filter)
$tab2_query = mysqli_query($conn, "
    SELECT v.*, DATEDIFF(v.to_date, v.from_date) + 1 AS vacation_days
    FROM vacations v
    INNER JOIN (
        SELECT MIN(id) AS id FROM vacations
        WHERE (return_date BETWEEN '$last_month_start' AND '$last_month_end'
           OR actual_return BETWEEN '$last_month_start' AND '$last_month_end')
          AND COALESCE(vacation_status,'') != 'Cancelled'
          $tab_where_base
        GROUP BY user_no, from_date, to_date
    ) dup ON v.id = dup.id
    ORDER BY v.return_date DESC
");

// TAB 3: All Vacation Records (no duplicates)
$tab3_query = mysqli_query($conn, "
    SELECT v.*, DATEDIFF(v.to_date, v.from_date) + 1 AS vacation_days
    FROM vacations v
    INNER JOIN (
        SELECT MIN(id) AS id FROM vacations
        WHERE 1=1 $vacation_where
        GROUP BY user_no, from_date, to_date
    ) dup ON v.id = dup.id
    ORDER BY v.from_date DESC
");

function display_vacation_date($date) {
    if ($date === null || $date === '' || $date === '0000-00-00') return '-';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : $date;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Vacation Management</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'Inter',sans-serif;
    background:#f0f2f5;
    min-height:100vh;
    padding:24px 28px;
    color:#1e293b;
}
.page-header{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:24px;
}
.page-title{
    font-size:22px;font-weight:700;color:#1a2233;
    display:flex;align-items:center;gap:10px;
}
.page-title i{color:#2563eb;font-size:20px;}
.header-actions{display:flex;gap:10px;align-items:center;}
.header-date{font-size:13px;color:#64748b;font-weight:500;}


.btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:9px 18px;text-decoration:none;border-radius:8px;
    font-size:13px;font-weight:600;border:none;cursor:pointer;
    font-family:'Inter',sans-serif;transition:all 0.2s;color:white;
}
.btn-primary{background:#2563eb;}
.btn-primary:hover{background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.3);}
.btn-secondary{background:#64748b;}
.btn-secondary:hover{background:#475569;}
.btn-success{background:#059669;}
.btn-success:hover{background:#047857;}
.btn-danger{background:#dc2626;padding:5px 12px;font-size:12px;border-radius:6px;}
.btn-danger:hover{background:#b91c1c;}
.btn-edit{background:#0284c7;padding:5px 12px;font-size:12px;border-radius:6px;}
.btn-edit:hover{background:#0369a1;}
.btn-sm{padding:6px 12px;font-size:12px;border-radius:6px;}
.btn-icon{padding:6px 10px;border-radius:6px;font-size:13px;}
.btn-outline{background:transparent;border:1.5px solid #e2e8f0;color:#475569;}
.btn-outline:hover{border-color:#2563eb;color:#2563eb;background:#eff6ff;}


/* Summary Cards */
.summary-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(155px, 1fr));
    gap:12px;margin-bottom:22px;
}
.summary-card{
    background:white;border-radius:12px;padding:16px;
    display:flex;align-items:center;gap:12px;
    box-shadow:0 1px 3px rgba(0,0,0,0.05);
    border:1px solid #e2e8f0;
    transition:transform 0.15s, box-shadow 0.15s, border-color 0.15s;
    cursor:pointer;position:relative;overflow:hidden;
}
.summary-card:hover{
    transform:translateY(-3px);
    box-shadow:0 8px 24px rgba(0,0,0,0.08);
    border-color:#bfdbfe;
}
.summary-card.active-card{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,0.15);}
.card-icon{
    width:44px;height:44px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:18px;flex-shrink:0;
}
.card-icon.blue{background:#dbeafe;color:#2563eb;}
.card-icon.green{background:#dcfce7;color:#059669;}
.card-icon.orange{background:#ffedd5;color:#ea580c;}
.card-icon.red{background:#fee2e2;color:#dc2626;}
.card-icon.purple{background:#ede9fe;color:#7c3aed;}
.card-icon.teal{background:#ccfbf1;color:#0d9488;}
.card-icon.indigo{background:#e0e7ff;color:#4f46e5;}
.card-icon.amber{background:#fef3c7;color:#d97706;}
.card-info .card-num{font-size:22px;font-weight:800;color:#1e293b;line-height:1;}
.card-info .card-label{font-size:11px;color:#64748b;margin-top:3px;font-weight:500;}


/* Search / Filter Bar */
.filter-bar{
    background:white;border-radius:12px;padding:14px 20px;
    margin-bottom:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
    box-shadow:0 1px 3px rgba(0,0,0,0.05);border:1px solid #e2e8f0;
}
.filter-bar label{font-size:12px;font-weight:600;color:#475569;white-space:nowrap;}
.filter-bar input[type=text],.filter-bar select{
    padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:7px;
    font-family:'Inter',sans-serif;font-size:12.5px;color:#1e293b;outline:none;
    transition:border-color 0.2s,box-shadow 0.2s;
}
.filter-bar input[type=text]{width:220px;}
.filter-bar select{min-width:130px;}
.filter-bar input:focus,.filter-bar select:focus{
    border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.08);
}

/* Tabs */
.tabs-container{
    background:white;border-radius:12px;
    box-shadow:0 1px 3px rgba(0,0,0,0.05);
    border:1px solid #e2e8f0;overflow:hidden;
}
.tabs-header{
    display:flex;border-bottom:2px solid #e2e8f0;
    background:#f8fafc;padding:0 16px;overflow-x:auto;
}
.tab-btn{
    padding:13px 20px;font-size:13px;font-weight:600;color:#64748b;
    cursor:pointer;border:none;background:transparent;
    border-bottom:3px solid transparent;margin-bottom:-2px;
    transition:all 0.2s;font-family:'Inter',sans-serif;
    display:flex;align-items:center;gap:7px;white-space:nowrap;
}
.tab-btn:hover{color:#2563eb;background:rgba(37,99,235,0.03);}
.tab-btn.active{color:#2563eb;border-bottom-color:#2563eb;background:white;}
.tab-btn .tab-count{
    background:#e2e8f0;color:#475569;padding:2px 7px;
    border-radius:999px;font-size:10.5px;font-weight:700;
}
.tab-btn.active .tab-count{background:#dbeafe;color:#2563eb;}
.tab-content{display:none;padding:0;}
.tab-content.active{display:block;}


/* Table */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;}
thead th{
    background:#1e293b;color:#e2e8f0;
    padding:10px 10px;text-align:left;
    font-size:10.5px;font-weight:600;text-transform:uppercase;
    letter-spacing:0.4px;white-space:nowrap;position:sticky;top:0;
}
tbody td{
    padding:9px 10px;border-bottom:1px solid #f1f5f9;
    color:#334155;vertical-align:middle;white-space:nowrap;
}
tbody tr:hover td{background:#f0f7ff;}
tbody tr{cursor:pointer;transition:background 0.1s;}
.sl-cell{color:#94a3b8;font-size:11px;font-weight:600;}
.user-cell{color:#2563eb;font-weight:600;font-size:12px;}
.name-cell{font-weight:500;color:#1e293b;max-width:150px;overflow:hidden;text-overflow:ellipsis;}
.date-cell{font-size:11.5px;color:#475569;}
.dept-cell{font-size:11.5px;color:#6366f1;font-weight:500;}
.action-cell{white-space:nowrap;display:flex;gap:4px;align-items:center;}
.no-record{text-align:center;padding:50px 20px;color:#94a3b8;font-size:14px;}
.no-record i{font-size:36px;display:block;margin-bottom:10px;color:#cbd5e1;}


/* Status Badges - New System */
.status-badge{
    padding:4px 9px;border-radius:999px;font-weight:700;
    font-size:10.5px;display:inline-block;white-space:nowrap;
}
.status-pending{background:#dcfce7;color:#15803d;}
.status-approved{background:#dcfce7;color:#059669;}
.status-ticket{background:#fef3c7;color:#92400e;}
.status-travelled{background:#ede9fe;color:#6d28d9;}
.status-on-vacation{background:#dbeafe;color:#1d4ed8;}
.status-returned{background:#dcfce7;color:#166534;}
.status-return-today{background:#ffedd5;color:#c2410c;}
.status-overstayed{background:#fee2e2;color:#b91c1c;}
.status-cancelled{background:#f1f5f9;color:#475569;}

.days-badge{
    background:#ede9fe;color:#7c3aed;
    padding:2px 8px;border-radius:999px;
    font-weight:700;font-size:11px;display:inline-block;
}
.late-badge{
    background:#fee2e2;color:#dc2626;
    padding:2px 8px;border-radius:999px;
    font-weight:700;font-size:11px;display:inline-block;
}
.salary-paid{background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:999px;font-weight:700;font-size:10.5px;}
.salary-pending{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-weight:700;font-size:10.5px;}
.payroll-yes{background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:999px;font-weight:700;font-size:10.5px;}
.payroll-no{background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-weight:700;font-size:10.5px;}
.ticket-badge{font-size:11px;color:#475569;font-weight:500;}
.amount-cell{font-size:11.5px;color:#1e293b;font-weight:600;}


/* Detail Panel (slide-in) */
.detail-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,0.45);z-index:2000;
    animation:fadeIn 0.2s;
}
.detail-overlay.open{display:block;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.detail-panel{
    position:fixed;top:0;right:0;
    width:680px;max-width:92vw;height:100vh;
    background:#f8fafc;z-index:2001;
    box-shadow:-8px 0 30px rgba(0,0,0,0.15);
    overflow-y:auto;
    transform:translateX(100%);
    transition:transform 0.3s ease;
}
.detail-panel.open{transform:translateX(0);}
.detail-top{
    background:white;padding:20px 24px;
    border-bottom:1px solid #e2e8f0;
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:10;
}
.detail-close{
    background:#f1f5f9;border:none;width:34px;height:34px;
    border-radius:8px;font-size:16px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    color:#475569;transition:background 0.15s;
}
.detail-close:hover{background:#e2e8f0;}
.detail-emp-info{display:flex;align-items:center;gap:12px;}
.detail-emp-info .emp-id{font-size:12px;color:#64748b;font-weight:500;}
.detail-emp-info .emp-name{font-size:16px;font-weight:700;color:#1e293b;}
.detail-body{padding:20px 24px;}
.detail-section{
    background:white;border-radius:10px;padding:18px;
    border:1px solid #e2e8f0;margin-bottom:16px;
}
.detail-section-title{
    font-size:13px;font-weight:700;color:#2563eb;
    margin-bottom:14px;display:flex;align-items:center;gap:6px;
}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.detail-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.detail-item label{
    font-size:10.5px;color:#64748b;font-weight:600;
    text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:2px;
}
.detail-item span{font-size:13px;color:#1e293b;font-weight:500;}
.detail-actions{
    display:flex;gap:10px;padding:16px 24px;
    background:white;border-top:1px solid #e2e8f0;
    position:sticky;bottom:0;
}

/* Timeline in detail */
.timeline{
    display:flex;align-items:center;gap:0;
    padding:10px 0;overflow-x:auto;
}
.timeline-step{
    display:flex;flex-direction:column;align-items:center;
    min-width:80px;position:relative;
}
.timeline-step .step-dot{
    width:32px;height:32px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:14px;background:#e2e8f0;
    position:relative;z-index:1;
}
.timeline-step .step-dot.active{background:#dcfce7;}
.timeline-step .step-dot.current{background:#dbeafe;box-shadow:0 0 0 3px rgba(37,99,235,0.2);}
.timeline-step .step-dot.warning{background:#fee2e2;}
.timeline-step .step-label{font-size:9.5px;color:#64748b;margin-top:6px;text-align:center;font-weight:500;}
.timeline-step .step-date{font-size:9px;color:#94a3b8;margin-top:2px;}
.timeline-line{
    height:2px;flex:1;min-width:20px;
    background:#e2e8f0;margin:0 -4px;
    position:relative;top:-10px;
}
.timeline-line.active{background:#86efac;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>


<!-- Page Header -->
<div class="page-header">
    <h1 class="page-title"><i class="fas fa-umbrella-beach"></i> Vacation Management</h1>
    <div class="header-actions">
        <span class="header-date"><i class="fas fa-calendar"></i> <?php echo date('d F Y'); ?></span>
        <a href="add_vacation.php" class="btn btn-success"><i class="fas fa-plus"></i> Add Vacation</a>
        <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="summary-grid">
    <div class="summary-card" onclick="switchTab(2)" id="card-total">
        <div class="card-icon blue"><i class="fas fa-suitcase-rolling"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $total_vacation; ?></div>
            <div class="card-label">Total Vacation</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(2)" id="card-pending">
        <div class="card-icon orange"><i class="fas fa-clock"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $pending_approval; ?></div>
            <div class="card-label">Pending Approval</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(2)" id="card-approved">
        <div class="card-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $approved_today; ?></div>
            <div class="card-label">Approved Today</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(0)" id="card-going">
        <div class="card-icon amber"><i class="fas fa-plane-departure"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $going_this_month; ?></div>
            <div class="card-label">Going This Month</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(0)" id="card-on-vacation">
        <div class="card-icon teal"><i class="fas fa-user-clock"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $now_on_vacation; ?></div>
            <div class="card-label">Now On Vacation</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(0)" id="card-returning">
        <div class="card-icon purple"><i class="fas fa-plane-arrival"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $returning_today; ?></div>
            <div class="card-label">Returning Today</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(2)" id="card-overdue">
        <div class="card-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $overdue_return; ?></div>
            <div class="card-label">Overdue / Overstay</div>
        </div>
    </div>
</div>


<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" style="display:contents;" id="filterForm">
        <label><i class="fas fa-search"></i></label>
        <input type="text" name="search" placeholder="User No / Employee Name" value="<?php echo htmlspecialchars($search); ?>">
        <label>Department</label>
        <select name="department">
            <option value="">All Department</option>
            <?php while($d = mysqli_fetch_assoc($dept_list)): ?>
            <option value="<?php echo htmlspecialchars($d['department']); ?>" <?php echo $dept_filter === $d['department'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department']); ?></option>
            <?php endwhile; ?>
        </select>
        <label>Nationality</label>
        <select name="nationality">
            <option value="">All Nationality</option>
            <?php while($n = mysqli_fetch_assoc($nat_list)): ?>
            <option value="<?php echo htmlspecialchars($n['nationality']); ?>" <?php echo $nationality_filter === $n['nationality'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($n['nationality']); ?></option>
            <?php endwhile; ?>
        </select>
        <label>Status</label>
        <select name="status">
            <option value="">All Status</option>
            <option value="Pending Approval" <?php echo $status_filter==='Pending Approval'?'selected':''; ?>>Pending Approval</option>
            <option value="Approved" <?php echo $status_filter==='Approved'?'selected':''; ?>>Approved</option>
            <option value="Ticket Processing" <?php echo $status_filter==='Ticket Processing'?'selected':''; ?>>Ticket Processing</option>
            <option value="Travelled" <?php echo $status_filter==='Travelled'?'selected':''; ?>>Travelled</option>
            <option value="On Vacation" <?php echo $status_filter==='On Vacation'?'selected':''; ?>>On Vacation</option>
            <option value="Returned" <?php echo $status_filter==='Returned'?'selected':''; ?>>Returned</option>
            <option value="Over Stayed" <?php echo $status_filter==='Over Stayed'?'selected':''; ?>>Over Stayed</option>
            <option value="Cancelled" <?php echo $status_filter==='Cancelled'?'selected':''; ?>>Cancelled</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
        <a href="vacation_details.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Reset</a>
    </form>
</div>


<!-- Tabs Container -->
<div class="tabs-container">
    <div class="tabs-header">
        <button class="tab-btn active" onclick="switchTab(0)">
            <i class="fas fa-plane-departure"></i> This Month Vacation
            <span class="tab-count"><?php echo $this_month_total; ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab(1)">
            <i class="fas fa-plane-arrival"></i> Last Month Returned
            <span class="tab-count"><?php echo $last_month_returned; ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab(2)">
            <i class="fas fa-list-alt"></i> All Records
            <span class="tab-count"><?php echo $total_vacation; ?></span>
        </button>
    </div>

    <!-- TAB 1: This Month Vacation -->
    <div class="tab-content active" id="tab-0">
        <div style="padding:12px 18px 6px;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:13px;color:#1e293b;"><i class="fas fa-calendar" style="color:#2563eb;margin-right:6px;"></i><?php echo $current_month_title; ?> - Employees On Vacation</strong>
            <span style="font-size:12px;color:#64748b;"><?php echo mysqli_num_rows($tab1_query); ?> records</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>SL</th><th>User No</th><th>Employee Name</th><th>Department</th>
                    <th>Designation</th><th>Nationality</th><th>Leave Type</th>
                    <th>From Date</th><th>To Date</th><th>Return Date</th>
                    <th>Days</th><th>Leave Balance</th><th>Ticket</th>
                    <th>Leave Salary</th><th>Air Ticket (AED)</th><th>Status</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php
                $sl=1;
                if(mysqli_num_rows($tab1_query) > 0){
                    while($row = mysqli_fetch_assoc($tab1_query)){
                        $status_label = vacation_status_from_dates($row['from_date'],$row['to_date'],$row['return_date'] ?? '',$row['vacation_status'] ?? '');
                        $status_class = vacation_status_class($status_label);
                        $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES);
                ?>
                <tr onclick='showDetail(<?php echo $row_json; ?>)'>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td class="dept-cell"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['designation'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['nationality'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['from_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['to_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['return_date'] ?? ''); ?></td>
                    <td><span class="days-badge"><?php echo $row['vacation_days']; ?></span></td>
                    <td><?php echo (int)($row['leave_balance'] ?? 0); ?></td>
                    <td class="ticket-badge"><?php echo htmlspecialchars($row['ticket_type'] ?? 'Company'); ?></td>
                    <td><span class="<?php echo ($row['leave_salary_status'] ?? 'Paid')==='Paid'?'salary-paid':'salary-pending'; ?>"><?php echo htmlspecialchars($row['leave_salary_status'] ?? 'Paid'); ?></span></td>
                    <td class="amount-cell"><?php echo number_format((float)($row['air_ticket_amount'] ?? 0), 2); ?></td>
                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo vacation_status_icon($status_label).' '.$status_label; ?></span></td>
                    <td><div class="action-cell" onclick="event.stopPropagation();">
                        <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit btn-icon"><i class="fas fa-edit"></i></a>
                        <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete?')" class="btn btn-danger btn-icon"><i class="fas fa-trash"></i></a>
                    </div></td>
                </tr>
                <?php } } else { ?>
                <tr><td colspan="17" class="no-record"><i class="fas fa-check-circle"></i><br>No vacation records for <?php echo $current_month_title; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- TAB 2: Last Month Returned -->
    <div class="tab-content" id="tab-1">
        <div style="padding:12px 18px 6px;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:13px;color:#1e293b;"><i class="fas fa-plane-arrival" style="color:#4f46e5;margin-right:6px;"></i><?php echo $last_month_title; ?> - Returned From Vacation</strong>
            <span style="font-size:12px;color:#64748b;"><?php echo mysqli_num_rows($tab2_query); ?> records</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>SL</th><th>User No</th><th>Employee Name</th><th>Department</th>
                    <th>Designation</th><th>Nationality</th><th>Leave Type</th>
                    <th>From Date</th><th>To Date</th><th>Actual Return</th>
                    <th>Days</th><th>Late Days</th><th>Leave Salary</th>
                    <th>Air Ticket (AED)</th><th>Payroll</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php
                $sl=1;
                if(mysqli_num_rows($tab2_query) > 0){
                    while($row = mysqli_fetch_assoc($tab2_query)){
                        $actual_ret = $row['actual_return'] ?? $row['return_date'] ?? '';
                        $late = vacation_calculate_late_days($row['to_date'], $actual_ret);
                        $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES);
                ?>
                <tr onclick='showDetail(<?php echo $row_json; ?>)'>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td class="dept-cell"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['designation'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['nationality'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['from_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['to_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($actual_ret); ?></td>
                    <td><span class="days-badge"><?php echo $row['vacation_days']; ?></span></td>
                    <td><?php if($late > 0): ?><span class="late-badge"><?php echo $late; ?> days</span><?php else: ?>0<?php endif; ?></td>
                    <td><span class="<?php echo ($row['leave_salary_status'] ?? 'Paid')==='Paid'?'salary-paid':'salary-pending'; ?>"><?php echo htmlspecialchars($row['leave_salary_status'] ?? 'Paid'); ?></span></td>
                    <td class="amount-cell"><?php echo number_format((float)($row['air_ticket_amount'] ?? 0), 2); ?></td>
                    <td><span class="<?php echo ($row['payroll_processed'] ?? 'No')==='Yes'?'payroll-yes':'payroll-no'; ?>"><?php echo htmlspecialchars($row['payroll_processed'] ?? 'No'); ?></span></td>
                    <td><div class="action-cell" onclick="event.stopPropagation();">
                        <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit btn-icon"><i class="fas fa-edit"></i></a>
                        <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete?')" class="btn btn-danger btn-icon"><i class="fas fa-trash"></i></a>
                    </div></td>
                </tr>
                <?php } } else { ?>
                <tr><td colspan="16" class="no-record"><i class="fas fa-check-circle"></i><br>No employees returned in <?php echo $last_month_title; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- TAB 3: All Records -->
    <div class="tab-content" id="tab-2">
        <div style="padding:12px 18px 6px;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:13px;color:#1e293b;"><i class="fas fa-list-alt" style="color:#059669;margin-right:6px;"></i>All Vacation Records - Complete Report</strong>
            <span style="font-size:12px;color:#64748b;"><?php echo mysqli_num_rows($tab3_query); ?> records</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>SL</th><th>User No</th><th>Employee Name</th><th>Department</th>
                    <th>Designation</th><th>Nationality</th><th>Leave Type</th>
                    <th>From Date</th><th>To Date</th><th>Return Date</th>
                    <th>Days</th><th>Leave Balance</th><th>Ticket</th>
                    <th>Leave Salary</th><th>Air Ticket (AED)</th><th>Advance</th>
                    <th>Status</th><th>Approved By</th><th>Late Days</th>
                    <th>Payroll</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php
                $sl=1;
                if(mysqli_num_rows($tab3_query) > 0){
                    while($row = mysqli_fetch_assoc($tab3_query)){
                        $status_label = vacation_status_from_dates($row['from_date'],$row['to_date'],$row['return_date'] ?? '',$row['vacation_status'] ?? '');
                        $status_class = vacation_status_class($status_label);
                        $actual_ret = $row['actual_return'] ?? $row['return_date'] ?? '';
                        $late = vacation_calculate_late_days($row['to_date'], $actual_ret);
                        $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES);
                ?>
                <tr onclick='showDetail(<?php echo $row_json; ?>)'>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td class="dept-cell"><?php echo htmlspecialchars($row['department'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['designation'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['nationality'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['from_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['to_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['return_date'] ?? ''); ?></td>
                    <td><span class="days-badge"><?php echo $row['vacation_days']; ?></span></td>
                    <td><?php echo (int)($row['leave_balance'] ?? 0); ?></td>
                    <td class="ticket-badge"><?php echo htmlspecialchars($row['ticket_type'] ?? 'Company'); ?></td>
                    <td><span class="<?php echo ($row['leave_salary_status'] ?? 'Paid')==='Paid'?'salary-paid':'salary-pending'; ?>"><?php echo htmlspecialchars($row['leave_salary_status'] ?? 'Paid'); ?></span></td>
                    <td class="amount-cell"><?php echo number_format((float)($row['air_ticket_amount'] ?? 0), 2); ?></td>
                    <td class="amount-cell"><?php echo number_format((float)($row['advance_salary'] ?? 0), 2); ?></td>
                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo vacation_status_icon($status_label).' '.$status_label; ?></span></td>
                    <td style="font-size:11px;color:#475569;"><?php echo htmlspecialchars($row['approved_by'] ?? '-'); ?></td>
                    <td><?php if($late > 0): ?><span class="late-badge"><?php echo $late; ?></span><?php else: ?>0<?php endif; ?></td>
                    <td><span class="<?php echo ($row['payroll_processed'] ?? 'No')==='Yes'?'payroll-yes':'payroll-no'; ?>"><?php echo htmlspecialchars($row['payroll_processed'] ?? 'No'); ?></span></td>
                    <td><div class="action-cell" onclick="event.stopPropagation();">
                        <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit btn-icon"><i class="fas fa-edit"></i></a>
                        <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete?')" class="btn btn-danger btn-icon"><i class="fas fa-trash"></i></a>
                    </div></td>
                </tr>
                <?php } } else { ?>
                <tr><td colspan="21" class="no-record"><i class="fas fa-check-circle"></i><br>No vacation records found.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Detail Slide Panel -->
<div class="detail-overlay" id="detailOverlay" onclick="closeDetail()"></div>
<div class="detail-panel" id="detailPanel">
    <div class="detail-top">
        <div class="detail-emp-info">
            <div>
                <div class="emp-id" id="detailEmpId"></div>
                <div class="emp-name" id="detailEmpName"></div>
            </div>
        </div>
        <button class="detail-close" onclick="closeDetail()"><i class="fas fa-times"></i></button>
    </div>
    <div class="detail-body" id="detailBody"></div>
    <div class="detail-actions" id="detailActions"></div>
</div>


<script>
// Tab switching
function switchTab(idx) {
    document.querySelectorAll('.tab-btn').forEach(function(btn, i) {
        btn.classList.toggle('active', i === idx);
    });
    document.querySelectorAll('.tab-content').forEach(function(content, i) {
        content.classList.toggle('active', i === idx);
    });
}

// Filter by status via card click
function filterByStatus(status) {
    var form = document.getElementById('filterForm');
    var sel = form.querySelector('select[name=status]');
    if (sel) { sel.value = status; }
    form.submit();
}

// Detail panel
function showDetail(data) {
    var overlay = document.getElementById('detailOverlay');
    var panel = document.getElementById('detailPanel');
    var body = document.getElementById('detailBody');
    var actions = document.getElementById('detailActions');

    document.getElementById('detailEmpId').textContent = data.user_no || '';
    document.getElementById('detailEmpName').textContent = data.employee_name || 'Employee';

    var status = getStatus(data);
    var days = 0;
    if (data.from_date && data.to_date) {
        var d1 = new Date(data.from_date), d2 = new Date(data.to_date);
        days = Math.round((d2 - d1) / (1000*60*60*24)) + 1;
    }
    var lateDays = 0;
    var actualRet = data.actual_return || data.return_date || '';
    if (actualRet && actualRet !== '0000-00-00' && data.to_date) {
        var diff = (new Date(actualRet) - new Date(data.to_date)) / (1000*60*60*24);
        if (diff > 0) lateDays = Math.round(diff);
    }

    var html = '';

    // Leave Information Section
    html += '<div class="detail-section">';
    html += '<div class="detail-section-title"><i class="fas fa-file-alt"></i> Leave Information</div>';
    html += '<div class="detail-grid">';
    html += item('Leave Type', data.leave_type || 'Annual Vacation');
    html += item('Leave Balance', (data.leave_balance || 0) + ' Days');
    html += item('Applied Date', fmtDate(data.applied_date));
    html += item('From Date', fmtDate(data.from_date));
    html += item('To Date', fmtDate(data.to_date));
    html += item('Expected Return', fmtDate(data.to_date));
    html += item('Total Vacation Days', days + ' Days');
    html += item('Leave Salary', data.leave_salary_status || 'Paid');
    html += item('Paid Status', data.paid_status || 'Paid');
    html += item('Reason', data.reason || '-');
    html += '</div></div>';


    // Employee Information Section
    html += '<div class="detail-section">';
    html += '<div class="detail-section-title"><i class="fas fa-user"></i> Employee Information</div>';
    html += '<div class="detail-grid">';
    html += item('Employee Name', data.employee_name || '-');
    html += item('User No', data.user_no || '-');
    html += item('Department', data.department || '-');
    html += item('Designation', data.designation || '-');
    html += item('Nationality', data.nationality || '-');
    html += item('Passport Expiry', fmtDate(data.passport_expiry));
    html += item('Visa Expiry', fmtDate(data.visa_expiry));
    html += '</div></div>';

    // Payroll Information Section
    html += '<div class="detail-section">';
    html += '<div class="detail-section-title"><i class="fas fa-money-bill-wave"></i> Payroll Information</div>';
    html += '<div class="detail-grid">';
    html += item('Leave Salary', data.leave_salary_status || 'Paid');
    html += item('Air Ticket Amount', 'AED ' + Number(data.air_ticket_amount || 0).toFixed(2));
    html += item('Ticket Type', data.ticket_type || 'Company');
    html += item('Advance Salary', 'AED ' + Number(data.advance_salary || 0).toFixed(2));
    html += item('Payroll Processed', data.payroll_processed || 'No');
    html += '</div></div>';

    // Status & Summary Section
    html += '<div class="detail-section">';
    html += '<div class="detail-section-title"><i class="fas fa-info-circle"></i> Status & Summary</div>';
    html += '<div class="detail-grid">';
    html += item('Current Status', '<span class="status-badge ' + getStatusClass(status) + '">' + getStatusIcon(status) + ' ' + status + '</span>');
    html += item('Approved By', data.approved_by || '-');
    html += item('Actual Return Date', fmtDate(actualRet));
    html += item('Late Days', lateDays > 0 ? '<span class="late-badge">' + lateDays + ' days</span>' : '0');
    html += '</div></div>';


    // Timeline Section
    html += '<div class="detail-section">';
    html += '<div class="detail-section-title"><i class="fas fa-stream"></i> Timeline</div>';
    html += '<div class="timeline">';
    var steps = [
        {label:'Apply', icon:'&#128221;', date: data.applied_date || data.created_at},
        {label:'Approved', icon:'&#9989;', date: ''},
        {label:'Ticket Booked', icon:'&#9992;', date: ''},
        {label:'Travelled', icon:'&#128747;', date: data.from_date},
        {label:'On Vacation', icon:'&#127965;', date: data.from_date},
        {label:'Returned', icon:'&#128100;', date: actualRet},
        {label:'Payroll Closed', icon:'&#128176;', date: ''}
    ];
    var activeIdx = getTimelineIdx(status);
    for (var i = 0; i < steps.length; i++) {
        var dotClass = i < activeIdx ? 'active' : (i === activeIdx ? 'current' : '');
        if (status === 'Over Stayed' && i === activeIdx) dotClass = 'warning';
        html += '<div class="timeline-step">';
        html += '<div class="step-dot ' + dotClass + '">' + steps[i].icon + '</div>';
        html += '<div class="step-label">' + steps[i].label + '</div>';
        if (steps[i].date) html += '<div class="step-date">' + fmtDate(steps[i].date) + '</div>';
        html += '</div>';
        if (i < steps.length - 1) {
            html += '<div class="timeline-line ' + (i < activeIdx ? 'active' : '') + '"></div>';
        }
    }
    html += '</div></div>';

    body.innerHTML = html;

    actions.innerHTML = '' +
        '<a href="edit_vacation.php?id=' + data.id + '" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit Vacation</a>' +
        '<a href="delete_vacation.php?id=' + data.id + '" onclick="return confirm(\'Delete this record?\')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</a>' +
        '<button onclick="closeDetail()" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to List</button>';

    overlay.classList.add('open');
    setTimeout(function(){ panel.classList.add('open'); }, 10);
}

function closeDetail() {
    document.getElementById('detailPanel').classList.remove('open');
    setTimeout(function(){
        document.getElementById('detailOverlay').classList.remove('open');
    }, 300);
}


function item(label, value) {
    return '<div class="detail-item"><label>' + label + '</label><span>' + (value||'-') + '</span></div>';
}

function fmtDate(d) {
    if (!d || d === '0000-00-00' || d === '') return '-';
    var dt = new Date(d);
    if (isNaN(dt)) return d;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return ('0'+dt.getDate()).slice(-2) + '-' + months[dt.getMonth()] + '-' + dt.getFullYear();
}

function getStatus(data) {
    var today = new Date().toISOString().slice(0,10);
    var stored = (data.vacation_status || '').toLowerCase().trim();
    if (stored === 'cancelled') return 'Cancelled';
    if (stored === 'pending approval') return 'Pending Approval';
    if (stored === 'approved') return 'Approved';
    if (stored === 'ticket processing') return 'Ticket Processing';
    if (stored === 'travelled') return 'Travelled';
    var ret = data.return_date || '';
    if (ret && ret !== '0000-00-00' && ret <= today) return 'Returned';
    if (data.to_date === today && (!ret || ret === '0000-00-00')) return 'Return Today';
    if (data.from_date <= today && data.to_date >= today) return 'On Vacation';
    if (data.to_date < today && (!ret || ret === '0000-00-00')) return 'Over Stayed';
    if (data.from_date > today) return 'Pending Approval';
    return 'On Vacation';
}

function getStatusClass(s) {
    var map = {
        'Pending Approval':'status-pending','Approved':'status-approved',
        'Ticket Processing':'status-ticket','Travelled':'status-travelled',
        'On Vacation':'status-on-vacation','Returned':'status-returned',
        'Return Today':'status-return-today','Over Stayed':'status-overstayed',
        'Cancelled':'status-cancelled'
    };
    return map[s] || 'status-pending';
}

function getStatusIcon(s) {
    var map = {
        'Pending Approval':'&#128994;','Approved':'&#128994;',
        'Ticket Processing':'&#128993;','Travelled':'&#128995;',
        'On Vacation':'&#128309;','Returned':'&#128994;',
        'Return Today':'&#128992;','Over Stayed':'&#128308;',
        'Cancelled':'&#9899;'
    };
    return map[s] || '&#9898;';
}

function getTimelineIdx(status) {
    var map = {
        'Pending Approval':0,'Approved':1,'Ticket Processing':2,
        'Travelled':3,'On Vacation':4,'Return Today':5,
        'Returned':5,'Over Stayed':5,'Cancelled':0
    };
    return map[status] !== undefined ? map[status] : 0;
}

// Escape key to close detail
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDetail();
});
</script>

</body>
</html>
