<?php
include 'auth.php';
include_once 'vacation_helper.php';
requirePermission('vacation_manage');
vacation_ensure_schema($conn);

$search = $_GET['search'] ?? '';
$dept_filter = $_GET['department'] ?? '';
$view = $_GET['view'] ?? '';
$today = date('Y-m-d');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$current_month_title = date('F Y');
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));
$last_month_title = date('F Y', strtotime('-1 month'));
$search_safe = mysqli_real_escape_string($conn, $search);
$dept_safe = mysqli_real_escape_string($conn, $dept_filter);

$base_where = "";
if ($search != "") $base_where .= " AND (user_no LIKE '%$search_safe%' OR employee_name LIKE '%$search_safe%')";
if ($dept_filter != "") $base_where .= " AND user_no IN (SELECT user_no FROM employees WHERE department = '$dept_safe')";
$base_where .= " AND (reason IS NULL OR (reason NOT LIKE '%Compensatory Off%' AND reason NOT LIKE '%swapped with%' AND reason NOT LIKE '%day swap%' AND reason NOT LIKE '%compensatory work day%'))";

// COUNTS - same logic as dashboard (uses return_date check, NOT to_date)
$now_on_vacation = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_no) AS total FROM vacations WHERE from_date <= '$today' AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00' OR return_date > '$today') AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $base_where"))['total'] ?? 0);
$going_this_month = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_no) AS total FROM vacations WHERE from_date BETWEEN '$current_month_start' AND '$current_month_end' AND COALESCE(vacation_status,'') NOT IN ('Cancelled') $base_where"))['total'] ?? 0);
$returning_this_month = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_no) AS total FROM vacations WHERE to_date BETWEEN '$current_month_start' AND '$current_month_end' AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $base_where"))['total'] ?? 0);
$overdue_return = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_no) AS total FROM vacations WHERE to_date < '$today' AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00') AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $base_where"))['total'] ?? 0);
$this_month_total = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_no) AS total FROM vacations WHERE from_date <= '$current_month_end' AND to_date >= '$current_month_start' AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned') $base_where"))['total'] ?? 0);
$last_month_returned = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_no) AS total FROM vacations WHERE return_date BETWEEN '$last_month_start' AND '$last_month_end' AND COALESCE(vacation_status,'') NOT IN ('Cancelled') $base_where"))['total'] ?? 0);
$total_vacation = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM (SELECT MIN(id) FROM vacations WHERE 1=1 $base_where GROUP BY user_no, from_date, to_date) t"))['total'] ?? 0);

// VIEW QUERIES
switch ($view) {
    case 'now_on_vacation':
        $view_title = "Currently On Vacation";
        $view_where = "from_date <= '$today' AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00' OR return_date > '$today') AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
        break;
    case 'going_this_month':
        $view_title = "Going This Month ($current_month_title)";
        $view_where = "from_date BETWEEN '$current_month_start' AND '$current_month_end' AND COALESCE(vacation_status,'') NOT IN ('Cancelled')";
        break;
    case 'returning_this_month':
        $view_title = "Returning This Month ($current_month_title)";
        $view_where = "to_date BETWEEN '$current_month_start' AND '$current_month_end' AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
        break;
    case 'overdue':
        $view_title = "Overdue / Overstay";
        $view_where = "to_date < '$today' AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00') AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
        break;
    case 'last_month_returned':
        $view_title = "Last Month Returned ($last_month_title)";
        $view_where = "return_date BETWEEN '$last_month_start' AND '$last_month_end' AND COALESCE(vacation_status,'') NOT IN ('Cancelled')";
        break;
    case 'all_records':
        $view_title = "All Records";
        $view_where = "1=1";
        break;
    default:
        $view_title = "Active This Month ($current_month_title)";
        $view_where = "from_date <= '$current_month_end' AND to_date >= '$current_month_start' AND (return_date IS NULL OR return_date='' OR return_date='0000-00-00' OR return_date > '$today') AND COALESCE(vacation_status,'') NOT IN ('Cancelled','Returned')";
        break;
}


$tab1_sql = "SELECT v.*, DATEDIFF(v.to_date, v.from_date)+1 AS vacation_days,
    COALESCE(e.department, v.department, '') AS emp_department,
    COALESCE(e.designation, v.designation, '') AS emp_designation
FROM vacations v
LEFT JOIN employees e ON e.user_no = v.user_no
INNER JOIN (SELECT MIN(id) AS id FROM vacations WHERE $view_where $base_where GROUP BY user_no, from_date, to_date) dup ON v.id = dup.id
ORDER BY v.from_date DESC";

$dept_list = mysqli_query($conn, "SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");

function display_vacation_date($date) {
    if (empty($date) || $date === '0000-00-00' || $date === null) return '-';
    return date('d-M-Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vacation Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; color: #1a1a2e; }
        .main-content { padding: 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .page-header h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; }
        .page-header .date-display { font-size: 14px; color: #6b7280; margin-top: 4px; }
        .header-actions { display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; transition: all 0.2s; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .btn-secondary:hover { background: #f9fafb; }


        /* Summary Cards */
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 25px; }
        .summary-card { background: #fff; border-radius: 12px; padding: 20px; text-decoration: none; color: inherit; border: 2px solid transparent; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .summary-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .summary-card.active { border-color: #2563eb; box-shadow: 0 4px 12px rgba(37,99,235,0.2); }
        .card-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 12px; }
        .card-icon.teal { background: #ccfbf1; color: #0d9488; }
        .card-icon.amber { background: #fef3c7; color: #d97706; }
        .card-icon.purple { background: #ede9fe; color: #7c3aed; }
        .card-icon.red { background: #fee2e2; color: #dc2626; }
        .card-icon.green { background: #dcfce7; color: #059669; }
        .card-icon.blue { background: #dbeafe; color: #2563eb; }
        .card-number { font-size: 28px; font-weight: 700; color: #1a1a2e; }
        .card-label { font-size: 13px; color: #6b7280; margin-top: 4px; }

        /* Filter Bar */
        .filter-bar { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .filter-bar input, .filter-bar select { padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; outline: none; transition: border-color 0.2s; }
        .filter-bar input:focus, .filter-bar select:focus { border-color: #2563eb; }
        .filter-bar input { width: 250px; }
        .filter-bar select { min-width: 180px; }
        .reset-link { color: #6b7280; text-decoration: none; font-size: 14px; margin-left: 8px; }
        .reset-link:hover { color: #dc2626; }

        /* Tabs */
        .tabs-container { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); overflow: hidden; }
        .tab-content { display: block; padding: 20px; }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table thead th { background: #1e293b; color: #fff; padding: 12px 10px; text-align: left; font-weight: 500; white-space: nowrap; }
        .data-table thead th:first-child { border-radius: 8px 0 0 0; }
        .data-table thead th:last-child { border-radius: 0 8px 0 0; }
        .data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .data-table tbody tr:hover { background: #f8fafc; }
        .data-table tbody td { padding: 10px; vertical-align: middle; }
        .table-wrapper { overflow-x: auto; }


        /* Status Badges */
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .status-pending { background: #dcfce7; color: #166534; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-ticket { background: #fef9c3; color: #854d0e; }
        .status-travelled { background: #ede9fe; color: #5b21b6; }
        .status-on-vacation { background: #dbeafe; color: #1e40af; }
        .status-returned { background: #d1fae5; color: #065f46; }
        .status-return-today { background: #ffedd5; color: #9a3412; }
        .status-overstayed { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f3f4f6; color: #374151; }

        /* Action buttons */
        .action-btn { padding: 5px 10px; border-radius: 6px; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-right: 4px; }
        .action-btn.edit { background: #eff6ff; color: #2563eb; }
        .action-btn.edit:hover { background: #dbeafe; }
        .action-btn.delete { background: #fef2f2; color: #dc2626; }
        .action-btn.delete:hover { background: #fee2e2; }

        .empty-state { text-align: center; padding: 40px; color: #6b7280; }
        .empty-state i { font-size: 40px; margin-bottom: 12px; color: #d1d5db; }

        @media (max-width: 1200px) {
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .summary-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-umbrella-beach"></i> Vacation Management</h1>
            <div class="date-display"><?= date('l, d F Y') ?></div>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="add_vacation.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Vacation</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <a href="?view=now_on_vacation<?= $search ? '&search='.urlencode($search) : '' ?><?= $dept_filter ? '&department='.urlencode($dept_filter) : '' ?>" class="summary-card<?= $view === 'now_on_vacation' ? ' active' : '' ?>">
            <div class="card-icon teal"><i class="fas fa-user-clock"></i></div>
            <div class="card-number"><?= $now_on_vacation ?></div>
            <div class="card-label">Now On Vacation</div>
        </a>
        <a href="?view=going_this_month<?= $search ? '&search='.urlencode($search) : '' ?><?= $dept_filter ? '&department='.urlencode($dept_filter) : '' ?>" class="summary-card<?= $view === 'going_this_month' ? ' active' : '' ?>">
            <div class="card-icon amber"><i class="fas fa-plane-departure"></i></div>
            <div class="card-number"><?= $going_this_month ?></div>
            <div class="card-label">Going This Month</div>
        </a>

        <a href="?view=returning_this_month<?= $search ? '&search='.urlencode($search) : '' ?><?= $dept_filter ? '&department='.urlencode($dept_filter) : '' ?>" class="summary-card<?= $view === 'returning_this_month' ? ' active' : '' ?>">
            <div class="card-icon purple"><i class="fas fa-plane-arrival"></i></div>
            <div class="card-number"><?= $returning_this_month ?></div>
            <div class="card-label">Returning This Month</div>
        </a>
        <a href="?view=overdue<?= $search ? '&search='.urlencode($search) : '' ?><?= $dept_filter ? '&department='.urlencode($dept_filter) : '' ?>" class="summary-card<?= $view === 'overdue' ? ' active' : '' ?>">
            <div class="card-icon red"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="card-number"><?= $overdue_return ?></div>
            <div class="card-label">Overdue / Overstay</div>
        </a>
        <a href="?view=last_month_returned<?= $search ? '&search='.urlencode($search) : '' ?><?= $dept_filter ? '&department='.urlencode($dept_filter) : '' ?>" class="summary-card<?= $view === 'last_month_returned' ? ' active' : '' ?>">
            <div class="card-icon green"><i class="fas fa-calendar-check"></i></div>
            <div class="card-number"><?= $last_month_returned ?></div>
            <div class="card-label">Last Month Returned</div>
        </a>
        <a href="?view=all_records<?= $search ? '&search='.urlencode($search) : '' ?><?= $dept_filter ? '&department='.urlencode($dept_filter) : '' ?>" class="summary-card<?= $view === 'all_records' ? ' active' : '' ?>">
            <div class="card-icon blue"><i class="fas fa-list-alt"></i></div>
            <div class="card-number"><?= $total_vacation ?></div>
            <div class="card-label">All Records</div>
        </a>
    </div>

    <!-- Filter Bar -->
    <form class="filter-bar" method="GET">
        <?php if ($view): ?><input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
        <input type="text" name="search" placeholder="Search by User No or Name..." value="<?= htmlspecialchars($search) ?>">
        <select name="department">
            <option value="">All Departments</option>
            <?php while ($dept_list && $dept_row = mysqli_fetch_assoc($dept_list)): ?>
                <option value="<?= htmlspecialchars($dept_row['department']) ?>" <?= $dept_filter === $dept_row['department'] ? 'selected' : '' ?>><?= htmlspecialchars($dept_row['department']) ?></option>
            <?php endwhile; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
        <a href="vacation_details.php" class="reset-link"><i class="fas fa-times"></i> Reset</a>
    </form>

    <!-- Table Container -->
    <div class="tabs-container">
        <div class="tab-content">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>SL</th>
                            <th>User No</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>From Date</th>
                            <th>To Date</th>
                            <th>Return Date</th>
                            <th>Days</th>
                            <th>Ticket</th>
                            <th>Status</th>
                            <?php if (hasPermission('vacation_manage')): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $result1 = mysqli_query($conn, $tab1_sql);
                    $sl = 0;
                    if ($result1 && mysqli_num_rows($result1) > 0):
                        while ($row = mysqli_fetch_assoc($result1)):
                            $sl++;
                            $status = vacation_status_from_dates($row['from_date'], $row['to_date'], $row['return_date'] ?? '', $row['vacation_status'] ?? '');
                            $status_class = vacation_status_class($status);
                            $status_icon = vacation_status_icon($status);
                    ?>

                        <tr>
                            <td><?= $sl ?></td>
                            <td><?= htmlspecialchars($row['user_no'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['employee_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['emp_department'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['emp_designation'] ?? '') ?></td>
                            <td><?= display_vacation_date($row['from_date'] ?? '') ?></td>
                            <td><?= display_vacation_date($row['to_date'] ?? '') ?></td>
                            <td><?= display_vacation_date($row['return_date'] ?? '') ?></td>
                            <td><?= $row['vacation_days'] ?? '' ?></td>
                            <td><?= htmlspecialchars($row['ticket_type'] ?? '') ?></td>
                            <td><span class="status-badge <?= $status_class ?>"><?= $status_icon ?> <?= $status ?></span></td>
                            <?php if (hasPermission('vacation_manage')): ?>
                            <td>
                                <a href="edit_vacation.php?id=<?= $row['id'] ?>" class="action-btn edit"><i class="fas fa-edit"></i> Edit</a>
                                <a href="delete_vacation.php?id=<?= $row['id'] ?>" class="action-btn delete" onclick="return confirm('Are you sure you want to delete this vacation record?')"><i class="fas fa-trash"></i> Delete</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="12" class="empty-state"><i class="fas fa-umbrella-beach"></i><p>No vacation records found.</p></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>
