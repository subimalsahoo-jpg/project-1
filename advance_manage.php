<?php
include 'auth.php';
include_once 'advance_helper.php';
requirePermission('salary_generate');
payroll_ensure_advance_schema($conn);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money2($value) {
    return number_format((float)$value, 2);
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_advance') {
        $user_no = trim($_POST['user_no'] ?? '');
        $advance_date = normalize_input_date($_POST['advance_date'] ?? date('Y-m-d'), 'dmy');
        $start_month = normalize_input_month($_POST['start_month'] ?? '', '');
        $total_advance = (float)($_POST['total_advance'] ?? 0);
        $monthly_deduction = (float)($_POST['monthly_deduction'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if ($user_no === '') {
            $error = 'User No required.';
        } elseif ($start_month === '') {
            $error = 'Start month required.';
        } elseif ($total_advance <= 0) {
            $error = 'Advance amount must be greater than 0.';
        } elseif ($monthly_deduction <= 0) {
            $error = 'Monthly deduction must be greater than 0.';
        } else {
            $employee_name = payroll_get_employee_name($conn, $user_no);
            $total_months = (int)ceil($total_advance / $monthly_deduction);
            $safe_user = payroll_esc($conn, $user_no);
            $safe_name = payroll_esc($conn, $employee_name);
            $safe_date = payroll_esc($conn, $advance_date);
            $safe_month = payroll_esc($conn, $start_month);
            $safe_note = payroll_esc($conn, $note);

            mysqli_query($conn, "
                INSERT INTO employee_advances
                    (user_no, employee_name, advance_date, total_advance, monthly_deduction, start_month, total_months, paid_amount, balance_amount, status, note)
                VALUES
                    ('$safe_user', '$safe_name', '$safe_date', $total_advance, $monthly_deduction, '$safe_month', $total_months, 0, $total_advance, 'Active', '$safe_note')
            ");
            $message = 'Advance saved successfully.';
        }
    } elseif ($action === 'close_advance') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($conn, "UPDATE employee_advances SET status='Closed' WHERE id=$id");
            $message = 'Advance closed.';
        }
    } elseif ($action === 'reopen_advance') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($conn, "UPDATE employee_advances SET status='Active' WHERE id=$id AND balance_amount > 0");
            $message = 'Advance reopened.';
        }
    }
}

$search_user = trim($_GET['user_no'] ?? '');
$status_filter = trim($_GET['status'] ?? 'Active');
$where = "WHERE 1=1";
if ($search_user !== '') {
    $safe_search = payroll_esc($conn, $search_user);
    $where .= " AND (a.user_no LIKE '%$safe_search%' OR a.employee_name LIKE '%$safe_search%')";
}
if ($status_filter !== '') {
    $safe_status = payroll_esc($conn, $status_filter);
    $where .= " AND a.status='$safe_status'";
}

$advances = mysqli_query($conn, "
    SELECT a.*
    FROM employee_advances a
    $where
    ORDER BY
        CASE a.status WHEN 'Active' THEN 1 WHEN 'Completed' THEN 2 ELSE 3 END,
        CAST(a.user_no AS UNSIGNED) ASC,
        a.start_month DESC
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Advance Salary</title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; font-family: Arial, sans-serif; background:#eef2f7; color:#0f172a; }
        .page { padding:28px; }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:20px; }
        h2 { margin:0; font-size:26px; }
        .btn { border:0; border-radius:7px; padding:11px 16px; background:#2c3e50; color:#fff; text-decoration:none; cursor:pointer; font-weight:700; display:inline-block; }
        .btn-blue { background:#3498db; }
        .btn-red { background:#e11d48; }
        .btn-green { background:#16a34a; }
        .card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 3px 14px rgba(15,23,42,.08); margin-bottom:18px; }
        .grid { display:grid; grid-template-columns:repeat(4, minmax(160px, 1fr)); gap:14px; }
        label { font-weight:700; font-size:13px; display:block; margin-bottom:6px; }
        input, select, textarea { width:100%; padding:11px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; }
        textarea { min-height:42px; resize:vertical; }
        .full { grid-column:1 / -1; }
        .alert { padding:13px 16px; border-radius:8px; margin-bottom:16px; font-weight:700; }
        .ok { background:#dcfce7; color:#166534; }
        .err { background:#fee2e2; color:#991b1b; }
        table { width:100%; border-collapse:collapse; background:#fff; }
        th, td { border:1px solid #d6dde8; padding:10px; text-align:center; vertical-align:middle; }
        th { background:#1f3349; color:#fff; font-size:13px; }
        td { font-size:14px; }
        .left { text-align:left; }
        .badge { padding:6px 10px; border-radius:7px; font-weight:800; font-size:12px; display:inline-block; }
        .active { background:#dcfce7; color:#15803d; }
        .completed { background:#dbeafe; color:#1d4ed8; }
        .closed { background:#f1f5f9; color:#475569; }
        .muted { color:#64748b; font-size:12px; }
        .actions { display:flex; gap:7px; justify-content:center; align-items:center; flex-wrap:wrap; }
        @media (max-width: 900px) { .grid { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <h2>Advance Salary</h2>
        <div>
            <a class="btn" href="dashboard.php">Dashboard</a>
            <a class="btn btn-blue" href="generate_salary.php">Salary Sheet</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
        <h3 style="margin-top:0;">Add Employee Advance</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_advance">
            <div class="grid">
                <div>
                    <label>User No.</label>
                    <input type="text" name="user_no" placeholder="Employee User No" required>
                </div>
                <div>
                    <label>Advance Date</label>
                    <input type="date" name="advance_date" value="<?= h(date('Y-m-d')) ?>" required>
                </div>
                <div>
                    <label>Start Month</label>
                    <input type="month" name="start_month" required>
                </div>
                <div>
                    <label>Total Advance</label>
                    <input type="number" step="0.01" min="0" name="total_advance" placeholder="500.00" required>
                </div>
                <div>
                    <label>Monthly Deduction</label>
                    <input type="number" step="0.01" min="0" name="monthly_deduction" placeholder="100.00" required>
                </div>
                <div class="full">
                    <label>Note</label>
                    <textarea name="note" placeholder="Optional note"></textarea>
                </div>
            </div>
            <div style="margin-top:14px;">
                <button class="btn btn-green" type="submit">Save Advance</button>
            </div>
        </form>
    </div>

    <div class="card">
        <form method="GET" class="grid" style="align-items:end;">
            <div>
                <label>Search User / Name</label>
                <input type="text" name="user_no" value="<?= h($search_user) ?>" placeholder="User No or Name">
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="" <?= $status_filter===''?'selected':'' ?>>All Status</option>
                    <option value="Active" <?= $status_filter==='Active'?'selected':'' ?>>Active</option>
                    <option value="Completed" <?= $status_filter==='Completed'?'selected':'' ?>>Completed</option>
                    <option value="Closed" <?= $status_filter==='Closed'?'selected':'' ?>>Closed</option>
                </select>
            </div>
            <div>
                <button class="btn btn-blue" type="submit">Search</button>
                <a class="btn" href="advance_manage.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Advance List</h3>
        <table>
            <thead>
                <tr>
                    <th>User No</th>
                    <th>Employee Name</th>
                    <th>Advance Date</th>
                    <th>Start Month</th>
                    <th>Total Advance</th>
                    <th>Monthly Deduction</th>
                    <th>Total Months</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($advances && mysqli_num_rows($advances) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($advances)): ?>
                    <?php $status_class = strtolower($row['status'] ?? 'active'); ?>
                    <tr>
                        <td><?= h($row['user_no']) ?></td>
                        <td class="left">
                            <strong><?= h($row['employee_name'] ?: payroll_get_employee_name($conn, $row['user_no'])) ?></strong>
                            <?php if (!empty($row['note'])): ?><div class="muted"><?= h($row['note']) ?></div><?php endif; ?>
                        </td>
                        <td><?= h($row['advance_date']) ?></td>
                        <td><?= h(date('F Y', strtotime($row['start_month'] . '-01'))) ?></td>
                        <td><?= money2($row['total_advance']) ?> AED</td>
                        <td><?= money2($row['monthly_deduction']) ?> AED</td>
                        <td><?= (int)$row['total_months'] ?></td>
                        <td><?= money2($row['paid_amount']) ?> AED</td>
                        <td><strong><?= money2($row['balance_amount']) ?> AED</strong></td>
                        <td><span class="badge <?= h($status_class) ?>"><?= h($row['status']) ?></span></td>
                        <td>
                            <div class="actions">
                                <?php if (($row['status'] ?? '') === 'Active'): ?>
                                    <form method="POST" onsubmit="return confirm('Close this advance?');">
                                        <input type="hidden" name="action" value="close_advance">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="btn btn-red" type="submit">Close</button>
                                    </form>
                                <?php elseif (($row['status'] ?? '') === 'Closed' && (float)$row['balance_amount'] > 0): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="reopen_advance">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="btn btn-green" type="submit">Reopen</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">No action</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="11">No advance found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
