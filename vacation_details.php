<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');
vacation_ensure_schema($conn);

$search = $_GET['search'] ?? '';
$today = date('Y-m-d');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$current_month_title = date('F Y');

$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));
$last_month_title = date('F Y', strtotime('-1 month'));

$search_safe = mysqli_real_escape_string($conn, $search);

$vacation_where = "";
if($search != ""){
    $vacation_where = "
    AND (
        user_no LIKE '%$search_safe%'
        OR employee_name LIKE '%$search_safe%'
    )";
}

$vacation_where .= "
    AND (reason IS NULL OR (
        reason NOT LIKE '%Compensatory Off%'
        AND reason NOT LIKE '%swapped with%'
        AND reason NOT LIKE '%day swap%'
        AND reason NOT LIKE '%compensatory work day%'
    ))";


/* ===== SUMMARY COUNTS ===== */

// Total Vacation Records
$total_query = mysqli_query($conn,"SELECT COUNT(*) AS total FROM vacations WHERE 1=1 $vacation_where");
$total_vacation = (int)(mysqli_fetch_assoc($total_query)['total'] ?? 0);

// Currently On Vacation (Now)
$now_on_vacation_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE from_date <= '$today'
      AND to_date >= '$today'
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
      $vacation_where
");
$now_on_vacation = (int)(mysqli_fetch_assoc($now_on_vacation_query)['total'] ?? 0);

// This Month Total (vacation active anytime this month)
$this_month_total_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE from_date <= '$current_month_end'
      AND to_date >= '$current_month_start'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$this_month_total = (int)(mysqli_fetch_assoc($this_month_total_query)['total'] ?? 0);

// Going This Month (from_date falls in this month)
$going_this_month_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE from_date BETWEEN '$current_month_start' AND '$current_month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$going_this_month = (int)(mysqli_fetch_assoc($going_this_month_query)['total'] ?? 0);


// Returning This Month (to_date falls in this month)
$returning_this_month_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE to_date BETWEEN '$current_month_start' AND '$current_month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$returning_this_month = (int)(mysqli_fetch_assoc($returning_this_month_query)['total'] ?? 0);

// Returned This Month (actual return_date in this month)
$returned_this_month_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE return_date BETWEEN '$current_month_start' AND '$current_month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$returned_this_month = (int)(mysqli_fetch_assoc($returned_this_month_query)['total'] ?? 0);

// Overdue Return
$overdue_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE to_date < '$today'
      AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00')
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
      $vacation_where
");
$overdue_return = (int)(mysqli_fetch_assoc($overdue_query)['total'] ?? 0);

// Last Month Returned
$last_month_returned_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE return_date BETWEEN '$last_month_start' AND '$last_month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$last_month_returned = (int)(mysqli_fetch_assoc($last_month_returned_query)['total'] ?? 0);


/* ===== TAB DATA QUERIES ===== */

// TAB 1: This Month On Vacation
$tab1_query = mysqli_query($conn, "
    SELECT *, DATEDIFF(to_date, from_date) + 1 AS vacation_days
    FROM vacations
    WHERE from_date <= '$current_month_end'
      AND to_date >= '$current_month_start'
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
      $vacation_where
    ORDER BY from_date DESC
");

// TAB 2: Last Month Returned
$tab2_query = mysqli_query($conn, "
    SELECT *, DATEDIFF(to_date, from_date) + 1 AS vacation_days
    FROM vacations
    WHERE return_date BETWEEN '$last_month_start' AND '$last_month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
    ORDER BY return_date DESC
");

// TAB 3: All Vacation Records
$tab3_query = mysqli_query($conn, "
    SELECT *, DATEDIFF(to_date, from_date) + 1 AS vacation_days
    FROM vacations
    WHERE 1=1
      $vacation_where
    ORDER BY from_date DESC
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
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:24px;
}
.page-title{
    font-size:22px;
    font-weight:700;
    color:#1a2233;
    display:flex;
    align-items:center;
    gap:10px;
}
.page-title i{color:#2563eb;font-size:20px;}
.header-actions{display:flex;gap:10px;align-items:center;}

.btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:9px 18px;text-decoration:none;border-radius:8px;
    font-size:13px;font-weight:600;border:none;cursor:pointer;
    font-family:'Inter',sans-serif;transition:all 0.2s;
}
.btn-primary{background:#2563eb;color:white;}
.btn-primary:hover{background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.3);}
.btn-secondary{background:#64748b;color:white;}
.btn-secondary:hover{background:#475569;}
.btn-success{background:#059669;color:white;}
.btn-success:hover{background:#047857;}
.btn-danger{background:#dc2626;color:white;padding:5px 12px;font-size:12px;border-radius:6px;}
.btn-danger:hover{background:#b91c1c;}
.btn-edit{background:#0284c7;color:white;padding:5px 12px;font-size:12px;border-radius:6px;}
.btn-edit:hover{background:#0369a1;}
.btn-sm{padding:6px 12px;font-size:12px;border-radius:6px;}

/* Summary Cards */
.summary-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));
    gap:14px;
    margin-bottom:24px;
}
.summary-card{
    background:white;
    border-radius:12px;
    padding:18px;
    display:flex;
    align-items:center;
    gap:14px;
    box-shadow:0 1px 3px rgba(0,0,0,0.06);
    border:1px solid #e2e8f0;
    transition:transform 0.15s, box-shadow 0.15s;
    cursor:pointer;
}
.summary-card:hover{
    transform:translateY(-2px);
    box-shadow:0 6px 20px rgba(0,0,0,0.08);
}

.card-icon{
    width:46px;height:46px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.card-icon.blue{background:#dbeafe;color:#2563eb;}
.card-icon.green{background:#dcfce7;color:#059669;}
.card-icon.orange{background:#ffedd5;color:#ea580c;}
.card-icon.red{background:#fee2e2;color:#dc2626;}
.card-icon.purple{background:#ede9fe;color:#7c3aed;}
.card-icon.teal{background:#ccfbf1;color:#0d9488;}
.card-icon.indigo{background:#e0e7ff;color:#4f46e5;}
.card-info .card-num{font-size:24px;font-weight:800;color:#1e293b;line-height:1;}
.card-info .card-label{font-size:11.5px;color:#64748b;margin-top:4px;font-weight:500;}

/* Search Bar */
.search-bar{
    background:white;
    border-radius:12px;
    padding:16px 20px;
    margin-bottom:20px;
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    box-shadow:0 1px 3px rgba(0,0,0,0.06);
    border:1px solid #e2e8f0;
}
.search-bar input[type=text]{
    padding:9px 14px;
    border:1.5px solid #e2e8f0;
    border-radius:8px;
    font-family:'Inter',sans-serif;
    font-size:13px;
    color:#1e293b;
    outline:none;
    width:280px;
    transition:border-color 0.2s, box-shadow 0.2s;
}
.search-bar input[type=text]:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,0.1);
}

/* Tabs */
.tabs-container{
    background:white;
    border-radius:12px;
    box-shadow:0 1px 3px rgba(0,0,0,0.06);
    border:1px solid #e2e8f0;
    overflow:hidden;
}
.tabs-header{
    display:flex;
    border-bottom:2px solid #e2e8f0;
    background:#f8fafc;
    padding:0 20px;
}
.tab-btn{
    padding:14px 24px;
    font-size:13.5px;
    font-weight:600;
    color:#64748b;
    cursor:pointer;
    border:none;
    background:transparent;
    border-bottom:3px solid transparent;
    margin-bottom:-2px;
    transition:all 0.2s;
    font-family:'Inter',sans-serif;
    display:flex;
    align-items:center;
    gap:8px;
    white-space:nowrap;
}
.tab-btn:hover{color:#2563eb;background:rgba(37,99,235,0.04);}
.tab-btn.active{
    color:#2563eb;
    border-bottom-color:#2563eb;
    background:white;
}
.tab-btn .tab-count{
    background:#e2e8f0;
    color:#475569;
    padding:2px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
}
.tab-btn.active .tab-count{
    background:#dbeafe;
    color:#2563eb;
}
.tab-content{display:none;padding:0;}
.tab-content.active{display:block;}

/* Table */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{
    background:#1e293b;
    color:#e2e8f0;
    padding:12px 14px;
    text-align:left;
    font-size:11px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.5px;
    white-space:nowrap;
    position:sticky;top:0;
}
tbody td{
    padding:11px 14px;
    border-bottom:1px solid #f1f5f9;
    color:#334155;
    vertical-align:middle;
}
tbody tr:hover td{background:#f8fafc;}
tbody tr{cursor:pointer;transition:background 0.1s;}

/* Status Badges */
.status-badge{
    padding:4px 10px;border-radius:999px;
    font-weight:700;font-size:11.5px;
    display:inline-block;white-space:nowrap;
}
.status-running{background:#dcfce7;color:#15803d;}
.status-upcoming{background:#dbeafe;color:#1d4ed8;}
.status-returned{background:#f1f5f9;color:#475569;}
.status-overdue-return{background:#fee2e2;color:#b91c1c;}
.status-cancelled{background:#fef3c7;color:#92400e;}

.days-badge{
    background:#ede9fe;color:#7c3aed;
    padding:3px 9px;border-radius:999px;
    font-weight:700;font-size:12px;display:inline-block;
}
.sl-cell{color:#94a3b8;font-size:12px;font-weight:600;}
.user-cell{color:#2563eb;font-weight:600;font-size:12.5px;}
.name-cell{font-weight:500;color:#1e293b;}
.date-cell{font-size:12px;color:#475569;font-family:'Inter',sans-serif;}
.action-cell{white-space:nowrap;display:flex;gap:5px;align-items:center;}
.no-record{text-align:center;padding:50px 20px;color:#94a3b8;font-size:14px;}
.no-record i{font-size:40px;display:block;margin-bottom:12px;color:#cbd5e1;}

/* Detail Panel (slide-in) */
.detail-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,0.4);z-index:2000;
    animation:fadeIn 0.2s;
}
.detail-overlay.open{display:block;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.detail-panel{
    position:fixed;top:0;right:0;
    width:520px;max-width:90vw;height:100vh;
    background:white;z-index:2001;
    box-shadow:-8px 0 30px rgba(0,0,0,0.15);
    overflow-y:auto;
    transform:translateX(100%);
    transition:transform 0.3s ease;
    padding:28px;
}
.detail-panel.open{transform:translateX(0);}
.detail-close{
    position:absolute;top:16px;right:16px;
    background:#f1f5f9;border:none;width:34px;height:34px;
    border-radius:8px;font-size:16px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    color:#475569;transition:background 0.15s;
}
.detail-close:hover{background:#e2e8f0;}
.detail-title{font-size:18px;font-weight:700;color:#1e293b;margin-bottom:20px;}
.detail-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:14px;
}
.detail-item{
    background:#f8fafc;border-radius:8px;padding:12px 14px;
    border:1px solid #e2e8f0;
}
.detail-item.full{grid-column:1/-1;}
.detail-item label{font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:4px;}
.detail-item span{font-size:14px;color:#1e293b;font-weight:500;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>


<!-- Page Header -->
<div class="page-header">
    <h1 class="page-title"><i class="fas fa-umbrella-beach"></i> Vacation Management</h1>
    <div class="header-actions">
        <a href="add_vacation.php" class="btn btn-success"><i class="fas fa-plus"></i> Add Vacation</a>
        <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="summary-grid">
    <div class="summary-card" onclick="switchTab(0)">
        <div class="card-icon blue"><i class="fas fa-suitcase-rolling"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $total_vacation; ?></div>
            <div class="card-label">Total Vacation</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(0)">
        <div class="card-icon green"><i class="fas fa-plane-departure"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $this_month_total; ?></div>
            <div class="card-label">This Month Vacation</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(0)">
        <div class="card-icon orange"><i class="fas fa-calendar-check"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $going_this_month; ?></div>
            <div class="card-label">Going This Month</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="card-icon teal"><i class="fas fa-user-clock"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $now_on_vacation; ?></div>
            <div class="card-label">Now On Vacation</div>
        </div>
    </div>

    <div class="summary-card" onclick="switchTab(0)">
        <div class="card-icon purple"><i class="fas fa-plane-arrival"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $returning_this_month; ?></div>
            <div class="card-label">Returning This Month</div>
        </div>
    </div>
    <div class="summary-card" onclick="switchTab(1)">
        <div class="card-icon indigo"><i class="fas fa-user-check"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $last_month_returned; ?></div>
            <div class="card-label">Last Month Returned</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="card-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="card-info">
            <div class="card-num"><?php echo $overdue_return; ?></div>
            <div class="card-label">Overdue / Overstay</div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="search-bar">
    <form method="GET" style="display:contents;">
        <i class="fas fa-search" style="color:#94a3b8;"></i>
        <input type="text" name="search" placeholder="Search by User No / Employee Name..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
        <a href="vacation_details.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Reset</a>
    </form>
</div>


<!-- Tabbed Section -->
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
            <i class="fas fa-list-alt"></i> All Vacation Records
            <span class="tab-count"><?php echo $total_vacation; ?></span>
        </button>
    </div>

    <!-- TAB 1: This Month On Vacation -->
    <div class="tab-content active" id="tab-0">
        <div style="padding:14px 20px 8px;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-calendar" style="color:#2563eb;"></i>
            <strong style="font-size:14px;color:#1e293b;"><?php echo $current_month_title; ?> - Employees On Vacation</strong>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SL</th><th>User No</th><th>Employee Name</th>
                        <th>Leave Type</th><th>From Date</th><th>To Date</th>
                        <th>Return Date</th><th>Days</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sl=1;
                if(mysqli_num_rows($tab1_query) > 0){
                    while($row = mysqli_fetch_assoc($tab1_query)){
                        $return_date = $row['return_date'] ?? '';
                        $status_label = vacation_status_from_dates($row['from_date'],$row['to_date'],$return_date,$row['vacation_status'] ?? '');
                        $status_class = 'status-'.strtolower(str_replace(' ','-',$status_label));
                        $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES);
                ?>
                <tr onclick='showDetail(<?php echo $row_json; ?>)'>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['from_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['to_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($return_date); ?></td>
                    <td><span class="days-badge"><?php echo $row['vacation_days']; ?></span></td>
                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                    <td>
                        <div class="action-cell" onclick="event.stopPropagation();">
                            <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this vacation?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php } } else { ?>
                <tr><td colspan="10" class="no-record"><i class="fas fa-check-circle"></i>No vacation records for <?php echo $current_month_title; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- TAB 2: Last Month Returned -->
    <div class="tab-content" id="tab-1">
        <div style="padding:14px 20px 8px;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-plane-arrival" style="color:#4f46e5;"></i>
            <strong style="font-size:14px;color:#1e293b;"><?php echo $last_month_title; ?> - Employees Returned From Vacation</strong>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SL</th><th>User No</th><th>Employee Name</th>
                        <th>Leave Type</th><th>From Date</th><th>To Date</th>
                        <th>Return Date</th><th>Days</th><th>Paid</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sl=1;
                if(mysqli_num_rows($tab2_query) > 0){
                    while($row = mysqli_fetch_assoc($tab2_query)){
                        $return_date = $row['return_date'] ?? '';
                        $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES);
                ?>
                <tr onclick='showDetail(<?php echo $row_json; ?>)'>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['from_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['to_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($return_date); ?></td>
                    <td><span class="days-badge"><?php echo $row['vacation_days']; ?></span></td>
                    <td><?php echo htmlspecialchars($row['paid_status'] ?? 'Paid'); ?></td>
                    <td>
                        <div class="action-cell" onclick="event.stopPropagation();">
                            <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this vacation?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php } } else { ?>
                <tr><td colspan="10" class="no-record"><i class="fas fa-check-circle"></i>No employees returned in <?php echo $last_month_title; ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- TAB 3: All Vacation Records -->
    <div class="tab-content" id="tab-2">
        <div style="padding:14px 20px 8px;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-list-alt" style="color:#059669;"></i>
            <strong style="font-size:14px;color:#1e293b;">All Vacation Records - Complete Report</strong>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SL</th><th>User No</th><th>Employee Name</th>
                        <th>Leave Type</th><th>Paid</th><th>From Date</th>
                        <th>To Date</th><th>Return Date</th><th>Days</th>
                        <th>Status</th><th>Reason</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sl=1;
                if(mysqli_num_rows($tab3_query) > 0){
                    while($row = mysqli_fetch_assoc($tab3_query)){
                        $return_date = $row['return_date'] ?? '';
                        $status_label = vacation_status_from_dates($row['from_date'],$row['to_date'],$return_date,$row['vacation_status'] ?? '');
                        $status_class = 'status-'.strtolower(str_replace(' ','-',$status_label));
                        $row_json = htmlspecialchars(json_encode($row), ENT_QUOTES);
                ?>
                <tr onclick='showDetail(<?php echo $row_json; ?>)'>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td><?php echo htmlspecialchars($row['paid_status'] ?? 'Paid'); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['from_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($row['to_date']); ?></td>
                    <td class="date-cell"><?php echo display_vacation_date($return_date); ?></td>
                    <td><span class="days-badge"><?php echo $row['vacation_days']; ?></span></td>
                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#64748b;"><?php echo htmlspecialchars($row['reason'] ?? ''); ?></td>
                    <td>
                        <div class="action-cell" onclick="event.stopPropagation();">
                            <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this vacation?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php } } else { ?>
                <tr><td colspan="12" class="no-record"><i class="fas fa-check-circle"></i>No vacation records found.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Detail Slide Panel -->
<div class="detail-overlay" id="detailOverlay" onclick="closeDetail()"></div>
<div class="detail-panel" id="detailPanel">
    <button class="detail-close" onclick="closeDetail()"><i class="fas fa-times"></i></button>
    <div class="detail-title" id="detailTitle">Vacation Details</div>
    <div class="detail-grid" id="detailGrid"></div>
    <div style="margin-top:20px;display:flex;gap:10px;" id="detailActions"></div>
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

// Detail panel
function showDetail(data) {
    var overlay = document.getElementById('detailOverlay');
    var panel = document.getElementById('detailPanel');
    var grid = document.getElementById('detailGrid');
    var title = document.getElementById('detailTitle');
    var actions = document.getElementById('detailActions');

    title.innerHTML = '<i class="fas fa-user" style="color:#2563eb;margin-right:8px;"></i>' +
        (data.employee_name || 'Employee') + ' <span style="font-size:13px;color:#64748b;font-weight:400;">(' + (data.user_no || '') + ')</span>';

    var status = getStatus(data.from_date, data.to_date, data.return_date, data.vacation_status || '');
    var days = 0;
    if(data.from_date && data.to_date) {
        var d1 = new Date(data.from_date), d2 = new Date(data.to_date);
        days = Math.round((d2 - d1) / (1000*60*60*24)) + 1;
    }


    grid.innerHTML = '' +
        makeItem('Employee Name', data.employee_name || '-') +
        makeItem('User No', data.user_no || '-') +
        makeItem('Leave Type', data.leave_type || 'Annual Vacation') +
        makeItem('Paid Status', data.paid_status || 'Paid') +
        makeItem('From Date', formatDate(data.from_date)) +
        makeItem('To Date', formatDate(data.to_date)) +
        makeItem('Return Date', formatDate(data.return_date)) +
        makeItem('Vacation Days', days + ' days') +
        makeItem('Status', '<span class="status-badge status-' + status.toLowerCase().replace(' ','-') + '">' + status + '</span>') +
        makeItem('Reason', data.reason || '-') +
        makeItemFull('Created', data.created_at || '-');

    actions.innerHTML = '<a href="edit_vacation.php?id=' + data.id + '" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>' +
        '<a href="delete_vacation.php?id=' + data.id + '" onclick="return confirm(\'Delete?\')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</a>';

    overlay.classList.add('open');
    setTimeout(function(){ panel.classList.add('open'); }, 10);
}

function closeDetail() {
    document.getElementById('detailPanel').classList.remove('open');
    setTimeout(function(){
        document.getElementById('detailOverlay').classList.remove('open');
    }, 300);
}

function makeItem(label, value) {
    return '<div class="detail-item"><label>' + label + '</label><span>' + (value||'-') + '</span></div>';
}
function makeItemFull(label, value) {
    return '<div class="detail-item full"><label>' + label + '</label><span>' + (value||'-') + '</span></div>';
}


function formatDate(d) {
    if(!d || d === '0000-00-00' || d === '') return '-';
    var dt = new Date(d);
    if(isNaN(dt)) return d;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return ('0'+dt.getDate()).slice(-2) + '-' + months[dt.getMonth()] + '-' + dt.getFullYear();
}

function getStatus(from, to, ret, stored) {
    var today = new Date().toISOString().slice(0,10);
    stored = (stored||'').toLowerCase().trim();
    if(stored === 'cancelled' || stored === 'closed') return stored.charAt(0).toUpperCase() + stored.slice(1);
    if(ret && ret !== '0000-00-00' && ret !== '' && ret <= today) return 'Returned';
    if(from <= today && to >= today) return 'Running';
    if(from > today) return 'Upcoming';
    if(to < today) return 'Overdue Return';
    return 'Upcoming';
}

// Close detail on Escape key
document.addEventListener('keydown', function(e) {
    if(e.key === 'Escape') closeDetail();
});
</script>

</body>
</html>
