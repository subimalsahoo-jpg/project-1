<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');
vacation_ensure_schema($conn);

$search = $_GET['search'] ?? '';
$today = date('Y-m-d');
$month = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$month_title = date('F Y', strtotime($month . '-01'));
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$already_gone_until = $today < $month_start ? '' : min($today, $month_end);
$will_go_from = max(date('Y-m-d', strtotime($today . ' +1 day')), $month_start);

$search_safe = mysqli_real_escape_string($conn, $search);

$vacation_where = "";
if($search != ""){
    $vacation_where = "
    AND (
        user_no LIKE '%$search_safe%'
        OR employee_name LIKE '%$search_safe%'
    )";
}
$vacation_effective_to = "COALESCE(NULLIF(l.return_date,'0000-00-00'), l.to_date)";

/* Vacation Details - no month filter, show all or search only */
$vacation_query = mysqli_query($conn,"
SELECT 
    *,
    DATEDIFF(to_date, from_date) + 1 AS vacation_days
FROM vacations
WHERE 1=1
$vacation_where
ORDER BY from_date DESC
");

$vacation_count_query = mysqli_query($conn,"
SELECT COUNT(*) AS total FROM vacations WHERE 1=1 $vacation_where
");
$total_vacation_emp = mysqli_fetch_assoc($vacation_count_query)['total'] ?? 0;

/* Vacation month breakup */
$vacation_in_month_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE from_date <= '$month_end'
      AND to_date >= '$month_start'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$this_month_vacation_active = (int)(mysqli_fetch_assoc($vacation_in_month_query)['total'] ?? 0);

if ($already_gone_until !== '') {
    $already_gone_query = mysqli_query($conn, "
        SELECT COUNT(DISTINCT user_no) AS total
        FROM vacations
        WHERE from_date BETWEEN '$month_start' AND '$already_gone_until'
          AND COALESCE(vacation_status,'') != 'Cancelled'
          $vacation_where
    ");
    $this_month_already_gone = (int)(mysqli_fetch_assoc($already_gone_query)['total'] ?? 0);
} else {
    $this_month_already_gone = 0;
}

if ($will_go_from <= $month_end) {
    $will_go_query = mysqli_query($conn, "
        SELECT COUNT(DISTINCT user_no) AS total
        FROM vacations
        WHERE from_date BETWEEN '$will_go_from' AND '$month_end'
          AND COALESCE(vacation_status,'') != 'Cancelled'
          $vacation_where
    ");
    $this_month_will_go = (int)(mysqli_fetch_assoc($will_go_query)['total'] ?? 0);
} else {
    $this_month_will_go = 0;
}

$current_vacation_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE from_date <= '$today'
      AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00' OR return_date > '$today')
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
      $vacation_where
");
$current_on_vacation = (int)(mysqli_fetch_assoc($current_vacation_query)['total'] ?? 0);

$vacation_coming_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE to_date BETWEEN '$month_start' AND '$month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$this_month_vacation_coming = (int)(mysqli_fetch_assoc($vacation_coming_query)['total'] ?? 0);

$returned_month_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE return_date BETWEEN '$month_start' AND '$month_end'
      AND COALESCE(vacation_status,'') != 'Cancelled'
      $vacation_where
");
$this_month_returned = (int)(mysqli_fetch_assoc($returned_month_query)['total'] ?? 0);

$overdue_query = mysqli_query($conn, "
    SELECT COUNT(DISTINCT user_no) AS total
    FROM vacations
    WHERE to_date < '$today'
      AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00')
      AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')
      $vacation_where
");
$overdue_return = (int)(mysqli_fetch_assoc($overdue_query)['total'] ?? 0);

function display_vacation_date($date) {
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d-m-Y', $ts) : $date;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Vacation Details</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'DM Sans',sans-serif;
    background:#eef1f6;
    min-height:100vh;
    padding:28px 32px;
    color:#334155;
}
.page-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:22px;
}
.page-title{
    font-size:21px;
    font-weight:600;
    color:#1a2233;
    letter-spacing:-0.3px;
}
.btn{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:#1e293b;
    color:white;
    padding:9px 18px;
    text-decoration:none;
    border-radius:7px;
    font-size:13.5px;
    font-weight:500;
    border:none;
    cursor:pointer;
    font-family:'DM Sans',sans-serif;
    transition:background 0.15s,transform 0.1s;
}
.btn:hover{background:#0f172a;transform:translateY(-1px);}
.btn-search{background:#2563eb;}
.btn-search:hover{background:#1d4ed8;}
.btn-reset{background:#64748b;}
.btn-reset:hover{background:#475569;}
.btn-edit{background:#0284c7;padding:5px 11px;font-size:12px;border-radius:5px;}
.btn-edit:hover{background:#0369a1;}
.btn-delete{background:#dc2626;padding:5px 11px;font-size:12px;border-radius:5px;}
.btn-delete:hover{background:#b91c1c;}

/* Summary cards */
.summary-row{
    display:flex;
    gap:12px;
    margin-bottom:20px;
    flex-wrap:wrap;
}
.summary-card{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:14px 20px;
    display:flex;
    align-items:center;
    gap:14px;
    min-width:200px;
    flex:1;
}
.summary-icon{
    width:42px;height:42px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.summary-icon.teal{background:#e0f2fe;}
.summary-icon.red{background:#fee2e2;}
.summary-info{}
.summary-num{font-size:26px;font-weight:700;line-height:1;color:#1e293b;}
.summary-num.teal{color:#0369a1;}
.summary-num.red{color:#dc2626;}
.summary-label{font-size:12px;color:#64748b;margin-top:3px;font-weight:500;}
.summary-breakup{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:9px;
}
.break-chip{
    background:#f1f5f9;
    border:1px solid #e2e8f0;
    color:#475569;
    border-radius:999px;
    padding:4px 9px;
    font-size:12px;
    font-weight:700;
}
.break-chip strong{color:#0f172a;}
.break-chip.green{background:#dcfce7;color:#15803d;border-color:#bbf7d0;}
.break-chip.blue{background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe;}
.break-chip.orange{background:#ffedd5;color:#c2410c;border-color:#fed7aa;}
.break-chip.red{background:#fee2e2;color:#b91c1c;border-color:#fecaca;}

/* Search box */
.search-card{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:16px 20px;
    margin-bottom:20px;
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.search-card label{font-size:13px;font-weight:500;color:#475569;white-space:nowrap;}
input[type=text],input[type=month]{
    padding:8px 12px;
    border:1px solid #cbd5e1;
    border-radius:6px;
    font-family:'DM Sans',sans-serif;
    font-size:13.5px;
    color:#1e293b;
    outline:none;
    transition:border-color 0.15s;
}
input[type=text]:focus,input[type=month]:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,0.1);
}
input[type=text]{width:260px;}

/* Section blocks */
.section-block{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:12px;
    margin-bottom:20px;
    overflow:hidden;
}
.section-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:14px 20px;
    border-bottom:1px solid #e2e8f0;
}
.section-header-left{
    display:flex;
    align-items:center;
    gap:10px;
}
.section-icon{
    width:32px;height:32px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;font-size:16px;
}
.section-icon.teal{background:#e0f2fe;}
.section-icon.red{background:#fee2e2;}
.section-title-text{font-size:15px;font-weight:600;color:#1e293b;}
.section-badge{
    font-size:11.5px;font-weight:600;padding:3px 10px;
    border-radius:20px;
}
.section-badge.teal{background:#e0f2fe;color:#0369a1;}
.section-badge.red{background:#fee2e2;color:#dc2626;}
.absent-month-filter{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:13px;
    color:#475569;
}
.absent-month-filter label{font-weight:500;}

/* Table */
.table-wrap{overflow-x:auto;}
table{
    width:100%;
    border-collapse:collapse;
    font-size:13.5px;
}
thead th{
    background:#1e293b;
    color:#e2e8f0;
    padding:11px 14px;
    text-align:left;
    font-size:11.5px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.4px;
    white-space:nowrap;
    position:sticky;
    top:0;
}
tbody td{
    padding:11px 14px;
    border-bottom:1px solid #f1f5f9;
    color:#334155;
    vertical-align:middle;
}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:#f8fafc;}
.badge-absent{
    background:#fee2e2;color:#b91c1c;
    padding:3px 10px;border-radius:20px;
    font-weight:700;font-size:13px;display:inline-block;
}
.badge-vacation{
    background:#dcfce7;color:#15803d;
    padding:3px 10px;border-radius:20px;
    font-weight:700;font-size:13px;display:inline-block;
}
.status-badge{
    padding:4px 10px;
    border-radius:999px;
    font-weight:700;
    font-size:12px;
    display:inline-block;
    white-space:nowrap;
}
.status-running{background:#dcfce7;color:#15803d;}
.status-upcoming{background:#dbeafe;color:#1d4ed8;}
.status-returned{background:#f1f5f9;color:#475569;}
.status-overdue-return{background:#fee2e2;color:#b91c1c;}
.status-cancelled{background:#fef3c7;color:#92400e;}
.no-record{
    text-align:center;padding:36px 20px;
    color:#94a3b8;font-size:13.5px;
}
.dates-cell{
    color:#64748b;font-size:12.5px;
    font-family:'DM Mono',monospace;
    max-width:420px;
    white-space:normal;
    line-height:1.6;
}
.sl-cell{color:#94a3b8;font-family:'DM Mono',monospace;font-size:12.5px;}
.user-cell{color:#2563eb;font-family:'DM Mono',monospace;font-weight:500;}
.action-cell{white-space:nowrap;display:flex;gap:5px;align-items:center;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<div class="page-header">
    <h1 class="page-title">&#127965; Vacation Details</h1>
    <a href="dashboard.php" class="btn">&#9776; Dashboard</a>
</div>

<!-- Global Search -->
<div class="search-card">
    <form method="GET" style="display:contents;">
        <label>&#128269; Search:</label>
        <input type="text" name="search" placeholder="User No / Employee Name" value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-search">Search</button>
        <a href="vacation_details.php" class="btn btn-reset">&#10006; Reset</a>
    </form>
</div>

<!-- Summary Cards -->
<div class="summary-row">
    <div class="summary-card">
        <div class="summary-icon teal">&#127965;</div>
        <div class="summary-info">
            <div class="summary-num teal"><?php echo $total_vacation_emp; ?></div>
            <div class="summary-label">Total Vacation Records</div>
            <div class="summary-breakup">
                <span class="break-chip green">Now On Vacation: <strong><?php echo $current_on_vacation; ?></strong></span>
                <span class="break-chip green">Vacation This Month: <strong><?php echo $this_month_vacation_active; ?></strong></span>
                <span class="break-chip orange">Already Gone This Month: <strong><?php echo $this_month_already_gone; ?></strong></span>
                <span class="break-chip blue">Will Go This Month: <strong><?php echo $this_month_will_go; ?></strong></span>
                <span class="break-chip blue">Will Return This Month: <strong><?php echo $this_month_vacation_coming; ?></strong></span>
                <span class="break-chip blue">Returned This Month: <strong><?php echo $this_month_returned; ?></strong></span>
                <span class="break-chip red">Overdue Return: <strong><?php echo $overdue_return; ?></strong></span>
            </div>
        </div>
    </div>
</div>

<!-- ===== VACATION TABLE (first) ===== -->
<div class="section-block">
    <div class="section-header">
        <div class="section-header-left">
            <div class="section-icon teal">&#127965;</div>
            <span class="section-title-text">Vacation Details</span>
            <span class="section-badge teal"><?php echo $total_vacation_emp; ?> records</span>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">SL</th>
                    <th>User No</th>
                    <th>Employee Name</th>
                    <th>Leave Type</th>
                    <th>Paid</th>
                    <th>From Date</th>
                    <th>To Date</th>
                    <th>Return Date</th>
                    <th>Vacation Days</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sl = 1;
            if(mysqli_num_rows($vacation_query) > 0){
                while($row = mysqli_fetch_assoc($vacation_query)){
                    $return_date = $row['return_date'] ?? '';
                    $status_label = vacation_status_from_dates(
                        $row['from_date'],
                        $row['to_date'],
                        $return_date,
                        $row['vacation_status'] ?? ''
                    );
                    $status_class = 'status-' . strtolower(str_replace(' ', '-', $status_label));
                ?>
                <tr>
                    <td class="sl-cell"><?php echo $sl++; ?></td>
                    <td class="user-cell"><?php echo htmlspecialchars($row['user_no']); ?></td>
                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['leave_type'] ?? 'Annual Vacation'); ?></td>
                    <td><?php echo htmlspecialchars($row['paid_status'] ?? 'Paid'); ?></td>
                    <td><?php echo htmlspecialchars(display_vacation_date($row['from_date'])); ?></td>
                    <td><?php echo htmlspecialchars(display_vacation_date($row['to_date'])); ?></td>
                    <td><?php echo htmlspecialchars(display_vacation_date($return_date)); ?></td>
                    <td><span class="badge-vacation"><?php echo $row['vacation_days']; ?> days</span></td>
                    <td><span class="status-badge <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                    <td style="color:#475569;font-size:13px;"><?php echo htmlspecialchars($row['reason']); ?></td>
                    <td>
                        <div class="action-cell">
                            <a href="edit_vacation.php?id=<?php echo $row['id']; ?>" class="btn btn-edit">Edit</a>
                            <a href="delete_vacation.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this vacation?')" class="btn btn-delete">Delete</a>
                        </div>
                    </td>
                </tr>
                <?php }
            } else { ?>
                <tr><td colspan="12" class="no-record">&#9989; No vacation record found.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
