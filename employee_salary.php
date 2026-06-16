<?php
/* ─────────────────────────────────────────────
   Employee Salary Details (standalone page)

   This page manages ONLY an employee's salary details. It reuses the exact
   same database tables (employees + employee_salary_records) and the same
   month-based salary logic as add_employee.php, so all existing payroll
   logic (generate_salary.php, salary_slip.php, etc.) keeps working unchanged.

   add_employee.php is left completely untouched.
───────────────────────────────────────────── */
include 'auth.php';
requirePermission('employee_add');

$message = "";
$search_employee = null;
$salary_columns = [];
$employees_columns = [];

$salary_month_input  = $_POST['salary_month'] ?? ($_GET['salary_month'] ?? '');
$salary_month        = normalize_input_month($salary_month_input, '');
$salary_lookup_month = $salary_month !== '' ? $salary_month : date('Y-m');

/* ─────────────────────────────────────────────
   Helper functions (self-contained — no conflict with auth.php)
───────────────────────────────────────────── */
function table_columns($conn, $table) {
    $columns = [];
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[$row['Field']] = true;
        }
    }
    return $columns;
}

function has_col($columns, $name) {
    return isset($columns[$name]);
}

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, $value);
}

function post_val($key, $default = '') {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function num_val($key) {
    $value = post_val($key);
    return $value === '' ? 0 : (float)$value;
}

function money($value) {
    return number_format((float)($value ?: 0), 2);
}

function val($employee, $key, $fallback = '') {
    return $employee[$key] ?? $fallback;
}

function first_val($employee, $keys, $fallback = '') {
    if (!$employee) return $fallback;
    foreach ($keys as $key) {
        if (isset($employee[$key]) && $employee[$key] !== '') {
            return $employee[$key];
        }
    }
    return $fallback;
}

function selected($employee, $key, $value) {
    return val($employee, $key) == $value ? 'selected' : '';
}

function sql_set($conn, $columns, $name, $value, &$sets) {
    if (has_col($columns, $name)) {
        $sets[] = "`$name`='" . esc($conn, $value) . "'";
    }
}

function sql_insert($conn, $columns, $name, $value, &$fields, &$values) {
    if (has_col($columns, $name)) {
        $fields[] = "`$name`";
        $values[] = "'" . esc($conn, $value) . "'";
    }
}

function employee_salary_record_count($conn, $user_no) {
    $safe_user_no = esc($conn, $user_no);
    $result = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM employee_salary_records
        WHERE user_no='$safe_user_no'
    ");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

/* ─────────────────────────────────────────────
   Salary numbers from POST (identical logic to add_employee.php)
───────────────────────────────────────────── */
function salary_numbers_from_post() {
    $basic_salary  = num_val('basic_salary');
    $allowance     = num_val('allowance');
    $att_allowance = num_val('att_allowance');
    $ot            = num_val('ot');               // OT amount (calculated)
    $food_company  = num_val('food_allowance_company');
    $food_won      = num_val('food_allowance_won');
    $fixed_salary  = num_val('fixed_salary');     // flat monthly salary (overrides attendance-based pay)
    $advance       = 0;
    $insurance     = num_val('insurance_amount');
    $other         = num_val('other_deduction');

    // Fixed salary employees: flat amount + food, no basic/allowance/att/OT.
    // Otherwise gross = all earnings including food_won (employee keeps this)
    if ($fixed_salary > 0) {
        $gross = $fixed_salary + $food_won + $food_company;
    } else {
        $gross = $basic_salary + $allowance + $att_allowance + $ot + $food_won + $food_company;
    }
    $total_deduction = $advance + $insurance + $other;
    $net_salary      = $gross - $total_deduction;

    return [
        'basic_salary'           => $basic_salary,
        'allowance'              => $allowance,
        'att_allowance'          => $att_allowance,
        'ot'                     => $ot,
        'fixed_salary'           => $fixed_salary,
        'food_allowance_company' => $food_company,
        'food_allowance_won'     => $food_won,
        'food_allowance'         => $food_company + $food_won,
        'advance_amount'         => $advance,
        'insurance_amount'       => $insurance,
        'other_deduction'        => $other,
        'salary_by'              => post_val('salary_by'),
        'gross_salary'           => $gross,
        'total_deduction'        => $total_deduction,
        'net_salary'             => $net_salary,
    ];
}

/* ─────────────────────────────────────────────
   Save salary record (INSERT or UPDATE) — identical to add_employee.php
───────────────────────────────────────────── */
function save_salary_values($conn, $employee_columns, $salary_columns, $id, $user_no, $employee_id, $salary, $salary_month) {
    if (empty($salary_columns)) return true;

    $safe_user_no      = esc($conn, $user_no);
    $safe_salary_month = esc($conn, $salary_month);

    $check = mysqli_query($conn, "
        SELECT id FROM employee_salary_records
        WHERE user_no='$safe_user_no' AND salary_month='$safe_salary_month'
        LIMIT 1
    ");

    $record_data = array_merge([
        'employee_id'  => $employee_id ?: $id,
        'user_no'      => $user_no,
        'salary_month' => $salary_month,
    ], $salary);

    if ($check && mysqli_num_rows($check) > 0) {
        $sets = [];
        foreach ($record_data as $key => $value) {
            if ($key !== 'user_no' && $key !== 'salary_month') {
                sql_set($conn, $salary_columns, $key, $value, $sets);
            }
        }
        if (!empty($sets)) {
            return mysqli_query($conn, "
                UPDATE employee_salary_records
                SET " . implode(',', $sets) . "
                WHERE user_no='$safe_user_no' AND salary_month='$safe_salary_month'
            ");
        }
        return true;
    }

    $fields = [];
    $values = [];
    foreach ($record_data as $key => $value) {
        sql_insert($conn, $salary_columns, $key, $value, $fields, $values);
    }
    if (empty($fields)) return true;

    return mysqli_query($conn, "
        INSERT INTO employee_salary_records (" . implode(',', $fields) . ")
        VALUES (" . implode(',', $values) . ")
    ");
}

/* ─────────────────────────────────────────────
   Merge most-recent salary record into employee array
───────────────────────────────────────────── */
function merge_salary_for_month($conn, $employee, $salary_columns, $salary_month) {
    if (!$employee || empty($employee['user_no']) || empty($salary_columns)) return $employee;

    $safe_user_no = esc($conn, $employee['user_no']);
    $safe_month   = esc($conn, $salary_month);

    $salary_result = mysqli_query($conn, "
        SELECT * FROM employee_salary_records
        WHERE user_no='$safe_user_no'
        AND salary_month <= '$safe_month'
        ORDER BY salary_month DESC
        LIMIT 1
    ");

    if ($salary_result && mysqli_num_rows($salary_result) > 0) {
        $salary_row = mysqli_fetch_assoc($salary_result);
        foreach ($salary_row as $key => $value) {
            if ($value !== null && $value !== '') {
                $employee[$key] = $value;
            }
        }
        $employee['selected_salary_month'] = $salary_row['salary_month'] ?? $salary_month;
    }

    return $employee;
}

/* ─────────────────────────────────────────────
   Ensure salary columns exist (same set as add_employee.php + fixed_salary)
───────────────────────────────────────────── */
$required_salary_columns = [
    'salary_month'           => "VARCHAR(7) DEFAULT ''",
    'salary_by'              => "VARCHAR(20) DEFAULT ''",
    'basic_salary'           => "DECIMAL(10,2) DEFAULT 0",
    'allowance'              => "DECIMAL(10,2) DEFAULT 0",
    'att_allowance'          => "DECIMAL(10,2) DEFAULT 0",
    'ot'                     => "DECIMAL(10,2) DEFAULT 0",
    'fixed_salary'           => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won'     => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance'         => "DECIMAL(10,2) DEFAULT 0",
    'insurance_amount'       => "DECIMAL(10,2) DEFAULT 0",
    'other_deduction'        => "DECIMAL(10,2) DEFAULT 0",
    'gross_salary'           => "DECIMAL(10,2) DEFAULT 0",
    'total_deduction'        => "DECIMAL(10,2) DEFAULT 0",
    'net_salary'             => "DECIMAL(10,2) DEFAULT 0",
];
foreach ($required_salary_columns as $col_name => $definition) {
    $check_col = mysqli_query($conn, "SHOW COLUMNS FROM employee_salary_records LIKE '" . esc($conn, $col_name) . "'");
    if ($check_col && mysqli_num_rows($check_col) == 0) {
        mysqli_query($conn, "ALTER TABLE employee_salary_records ADD `$col_name` $definition");
    }
}
// Make sure the employees master has a fixed_salary column too
$check_fixed = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE 'fixed_salary'");
if ($check_fixed && mysqli_num_rows($check_fixed) == 0) {
    mysqli_query($conn, "ALTER TABLE employees ADD `fixed_salary` DECIMAL(10,2) DEFAULT 0");
}

$salary_columns    = table_columns($conn, 'employee_salary_records');
$employees_columns = table_columns($conn, 'employees');

/* ─────────────────────────────────────────────
   GET: Search employee
───────────────────────────────────────────── */
$search_input = $_GET['user_no'] ?? ($_GET['search_user_no'] ?? '');
if (trim($search_input) !== '') {
    $search_val = esc($conn, trim($search_input));
    $search_result = mysqli_query($conn, "
        SELECT * FROM employees
        WHERE user_no='$search_val'
        OR employee_id='$search_val'
        OR card_no='$search_val'
        OR full_name LIKE '%$search_val%'
        LIMIT 1
    ");

    if ($search_result && mysqli_num_rows($search_result) > 0) {
        $search_employee = mysqli_fetch_assoc($search_result);
        $search_employee = merge_salary_for_month($conn, $search_employee, $salary_columns, $salary_lookup_month);
    } else {
        $message = "<div class='message error'>No employee found.</div>";
    }
}

/* ─────────────────────────────────────────────
   POST: Save Salary
───────────────────────────────────────────── */
if (isset($_POST['save_salary'])) {
    $id          = post_val('id');
    $user_no     = post_val('user_no');
    $employee_id = post_val('employee_id');
    $salary      = salary_numbers_from_post();

    $save_employee = null;
    if ($user_no !== '') {
        $safe_post_user_no = esc($conn, $user_no);
        $emp_result = mysqli_query($conn, "
            SELECT * FROM employees WHERE user_no='$safe_post_user_no' LIMIT 1
        ");
        if ($emp_result && mysqli_num_rows($emp_result) > 0) {
            $save_employee = mysqli_fetch_assoc($emp_result);
            $id          = $save_employee['id']          ?? $id;
            $employee_id = $save_employee['employee_id'] ?? $employee_id;
            $user_no     = $save_employee['user_no']     ?? $user_no;
        }
    }

    $existing_salary_count = $user_no !== '' ? employee_salary_record_count($conn, $user_no) : 0;

    if ($user_no === '' || !$save_employee) {
        $message = "<div class='message error'>Please search the employee again, then save salary details.</div>";
    } elseif ($id === '') {
        $message = "<div class='message error'>Please search the employee first, then save salary details.</div>";
    } elseif ($existing_salary_count > 0 && $salary_month === '') {
        $message = "<div class='message error'>Please select Month before changing salary details.</div>";
    } else {
        $ok = save_salary_values($conn, $employees_columns, $salary_columns, $id, $user_no, $employee_id, $salary, $salary_month);
        if ($ok) {
            // Sync the edited base salary components into the employees master,
            // because salary generation reads the employees table first for these
            // values (e.g. Good Attendance / att_allowance). Without this, edits made
            // here were ignored and the old master value was used on generate.
            $sync_fields = ['basic_salary', 'allowance', 'att_allowance', 'fixed_salary',
                            'food_allowance_company', 'food_allowance_won', 'food_allowance',
                            'insurance_amount', 'other_deduction', 'salary_by'];
            $emp_sets = [];
            foreach ($sync_fields as $sf) {
                if (array_key_exists($sf, $salary)) {
                    sql_set($conn, $employees_columns, $sf, $salary[$sf], $emp_sets);
                }
            }
            if (!empty($emp_sets)) {
                mysqli_query($conn, "UPDATE employees SET " . implode(',', $emp_sets) . " WHERE user_no='" . esc($conn, $user_no) . "'");
            }

            $month_label = $salary_month !== '' ? date('F Y', strtotime($salary_month . "-01")) : "Running Salary";
            $message = "<div class='message success'>Salary saved for $month_label. Net Salary: " . money($salary['net_salary']) . " AED</div>";
            $res = mysqli_query($conn, "SELECT * FROM employees WHERE user_no='" . esc($conn, $user_no) . "' LIMIT 1");
            if ($res && mysqli_num_rows($res) > 0) {
                $search_employee = mysqli_fetch_assoc($res);
                $search_employee = merge_salary_for_month($conn, $search_employee, $salary_columns, $salary_lookup_month);
            }
        } else {
            $message = "<div class='message error'>Error saving salary: " . htmlspecialchars(mysqli_error($conn)) . "</div>";
        }
    }
}

/* ─────────────────────────────────────────────
   Computed display values
───────────────────────────────────────────── */
$basic_salary_value    = (float)first_val($search_employee, ['basic_salary'], 0);
$allowance_value       = (float)first_val($search_employee, ['allowance'], 0);
$att_allowance_value   = (float)first_val($search_employee, ['att_allowance'], 0);
$fixed_salary_value    = (float)first_val($search_employee, ['fixed_salary'], 0);
$insurance_value       = (float)first_val($search_employee, ['insurance_amount'], 0);
$other_deduction_value = (float)first_val($search_employee, ['other_deduction'], 0);
$food_company_value    = (float)first_val($search_employee, ['food_allowance_company', 'food_allowance'], 0);
$food_won_value        = (float)first_val($search_employee, ['food_allowance_won'], 0);
$salary_by_value       = first_val($search_employee, ['salary_by'], '');

// OT from overtime_records for the selected month (auto)
$monthly_ot_hours  = 0;
$monthly_ot_amount = 0;
if ($search_employee && !empty($search_employee['user_no'])) {
    $safe_ot_user  = esc($conn, $search_employee['user_no']);
    $safe_ot_month = esc($conn, $salary_lookup_month);
    $ot_query = mysqli_query($conn, "
        SELECT COALESCE(SUM(ot_hours), 0) AS total_ot_hours
        FROM overtime_records
        WHERE user_no='$safe_ot_user'
        AND DATE_FORMAT(attendance_date, '%Y-%m')='$safe_ot_month'
    ");
    if ($ot_query && ($ot_row = mysqli_fetch_assoc($ot_query))) {
        $monthly_ot_hours = (float)($ot_row['total_ot_hours'] ?? 0);
    }
    if ($basic_salary_value > 0 && $monthly_ot_hours > 0) {
        $monthly_ot_amount = (($basic_salary_value / 30) / 8) * 1.25 * $monthly_ot_hours;
    }
}

// Gross / Net (mirror add_employee + fixed-salary rule)
if ($fixed_salary_value > 0) {
    $gross_salary_value = $fixed_salary_value + $food_won_value + $food_company_value;
} else {
    $gross_salary_value = $basic_salary_value + $allowance_value + $att_allowance_value
                        + $monthly_ot_amount + $food_won_value + $food_company_value;
}
$total_deduction_value = $insurance_value + $other_deduction_value;
$net_salary_value      = $gross_salary_value - $total_deduction_value;

$selected_salary_month = $search_employee['selected_salary_month'] ?? $salary_month;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Salary Details</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #eef2f7; color: #1a2533; font-size: 14px; }

.header {
    background: #1e293b; color: #fff; padding: 18px 24px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
}
.header h2 { font-size: 18px; font-weight: 700; }
.header .nav-links { display: flex; gap: 8px; flex-wrap: wrap; }

.btn {
    display: inline-block; background: #2563eb; color: #fff; padding: 9px 16px;
    text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600;
    border: none; cursor: pointer; transition: background .2s;
}
.btn:hover { background: #1d4ed8; }
.btn-light { background: #475569; }
.btn-light:hover { background: #334155; }
.btn-green { background: #16a34a; }
.btn-green:hover { background: #15803d; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }

.wrap { max-width: 1100px; margin: 18px auto; padding: 0 16px; }

.message { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
.message.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.message.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); padding: 18px 20px; margin-bottom: 16px; }
.card h3 { font-size: 15px; color: #1e293b; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #f1f5f9; }

.search-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.search-row input[type=text] { flex: 1; min-width: 220px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }

.emp-banner { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; }
.emp-banner .name { font-size: 17px; font-weight: 700; color: #0c4a6e; }
.emp-banner .meta { color: #475569; font-size: 13px; }
.emp-banner .meta b { color: #1e293b; }

.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }
.form-group { display: flex; flex-direction: column; }
.form-group label { font-weight: 600; font-size: 12.5px; color: #374151; margin-bottom: 5px; }
.form-group label small { color: #64748b; font-weight: 400; }
.form-group input, .form-group select {
    padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: #fff;
}
.form-group input[readonly] { background: #eef2f7; color: #64748b; }
.form-group input:focus, .form-group select:focus { border-color: #2563eb; outline: none; }

.totals { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 6px; }
.total-box { flex: 1; min-width: 200px; border-radius: 10px; padding: 14px 16px; }
.total-box .lbl { font-size: 12px; font-weight: 600; opacity: .85; }
.total-box .amt { font-size: 22px; font-weight: 800; margin-top: 4px; }
.total-gross { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
.total-net   { background: #eff6ff; border: 1px solid #93c5fd; color: #1d4ed8; }

.action-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-top: 16px; }
.month-box { display: flex; flex-direction: column; }
.month-box label { font-weight: 600; font-size: 12.5px; color: #374151; margin-bottom: 5px; }
.month-box input { padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }

.empty-state { text-align: center; color: #94a3b8; padding: 40px; font-weight: 600; }
.note { font-size: 12.5px; color: #64748b; margin-top: 6px; }

@media (max-width: 680px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<div class="header">
    <h2>&#128176; Salary Details</h2>
    <div class="nav-links">
        <a href="dashboard.php" class="btn btn-light">&#9776; Dashboard</a>
        <a href="employee_list.php" class="btn btn-light">Employee List</a>
        <?php if ($search_employee): ?>
        <a href="add_employee.php?search_user_no=<?php echo urlencode($search_employee['user_no'] ?? ''); ?>" class="btn btn-light">&#9998; Edit Profile</a>
        <?php endif; ?>
    </div>
</div>

<div class="wrap">

    <?php echo $message; ?>

    <!-- Search -->
    <div class="card">
        <h3>&#128269; Find Employee</h3>
        <form method="GET" class="search-row">
            <input type="text" name="user_no" placeholder="User No / Employee ID / Card No / Name"
                   value="<?php echo htmlspecialchars(trim($search_input)); ?>" autofocus>
            <button type="submit" class="btn">Search</button>
            <a href="employee_salary.php" class="btn btn-danger">&#10005; Clear</a>
        </form>
        <div class="note">Search an employee to view and edit their salary details.</div>
    </div>

    <?php if ($search_employee): ?>

    <!-- Employee banner -->
    <div class="emp-banner">
        <div>
            <div class="name"><?php echo htmlspecialchars(val($search_employee, 'full_name')); ?></div>
            <div class="meta">
                <b>User No:</b> <?php echo htmlspecialchars(val($search_employee, 'user_no')); ?> &nbsp;|&nbsp;
                <b>Emp ID:</b> <?php echo htmlspecialchars(val($search_employee, 'employee_id')); ?> &nbsp;|&nbsp;
                <b>Dept:</b> <?php echo htmlspecialchars(val($search_employee, 'department')); ?> &nbsp;|&nbsp;
                <b>Designation:</b> <?php echo htmlspecialchars(val($search_employee, 'designation')); ?>
            </div>
        </div>
    </div>

    <!-- Salary form -->
    <form method="POST">
        <input type="hidden" name="id"          value="<?php echo htmlspecialchars(val($search_employee, 'id')); ?>">
        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars(val($search_employee, 'employee_id')); ?>">
        <input type="hidden" name="user_no"     value="<?php echo htmlspecialchars(val($search_employee, 'user_no')); ?>">

        <div class="card">
            <h3>&#128181; Salary Components</h3>
            <div class="grid">
                <div class="form-group">
                    <label>Basic Salary (AED)</label>
                    <input type="number" step="0.01" min="0" name="basic_salary" id="basic_salary"
                           value="<?php echo htmlspecialchars(number_format($basic_salary_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>Allowance (AED)</label>
                    <input type="number" step="0.01" min="0" name="allowance" id="allowance"
                           value="<?php echo htmlspecialchars(number_format($allowance_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>Attendance Allowance (AED)</label>
                    <input type="number" step="0.01" min="0" name="att_allowance" id="att_allowance"
                           value="<?php echo htmlspecialchars(number_format($att_allowance_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>Fixed Salary (AED) <small>— if &gt; 0, monthly pay (no OT; reduced for absent days)</small></label>
                    <input type="number" step="0.01" min="0" name="fixed_salary" id="fixed_salary"
                           value="<?php echo htmlspecialchars(number_format($fixed_salary_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>OT Hours (auto from records)</label>
                    <input type="number" step="0.01" name="ot_hours" id="ot_hours"
                           value="<?php echo htmlspecialchars(number_format($monthly_ot_hours, 2, '.', '')); ?>" readonly>
                </div>

                <div class="form-group">
                    <label>OT Amount (auto calculated)</label>
                    <input type="number" step="0.01" name="ot" id="ot"
                           value="<?php echo htmlspecialchars(number_format($monthly_ot_amount, 2, '.', '')); ?>" readonly>
                </div>

                <div class="form-group">
                    <label>Food Allowance — Own (AED)</label>
                    <input type="number" step="0.01" min="0" name="food_allowance_won" id="food_allowance_won"
                           value="<?php echo htmlspecialchars(number_format($food_won_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>Food Allowance — Company (AED)</label>
                    <input type="number" step="0.01" min="0" name="food_allowance_company" id="food_allowance_company"
                           value="<?php echo htmlspecialchars(number_format($food_company_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>Salary By</label>
                    <select name="salary_by" id="salary_by">
                        <option value="">Select Payment Method</option>
                        <option value="Cash" <?php echo selected($search_employee, 'salary_by', 'Cash'); ?>>Cash</option>
                        <option value="Bank" <?php echo selected($search_employee, 'salary_by', 'Bank'); ?>>Bank</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>&#9940; Deductions</h3>
            <div class="grid">
                <div class="form-group">
                    <label>Insurance (AED)</label>
                    <input type="number" step="0.01" min="0" name="insurance_amount" id="insurance_amount"
                           value="<?php echo htmlspecialchars(number_format($insurance_value, 2, '.', '')); ?>">
                </div>

                <div class="form-group">
                    <label>Other Deduction (AED)</label>
                    <input type="number" step="0.01" min="0" name="other_deduction" id="other_deduction"
                           value="<?php echo htmlspecialchars(number_format($other_deduction_value, 2, '.', '')); ?>">
                </div>
            </div>

            <div class="totals">
                <div class="total-box total-gross">
                    <div class="lbl">&#128200; Gross Salary</div>
                    <div class="amt"><span id="gross_salary_text"><?php echo money($gross_salary_value); ?></span> AED</div>
                </div>
                <div class="total-box total-net">
                    <div class="lbl">&#9989; Net Salary</div>
                    <div class="amt"><span id="net_salary_text"><?php echo money($net_salary_value); ?></span> AED</div>
                </div>
            </div>

            <input type="hidden" name="gross_salary" id="gross_salary" value="<?php echo htmlspecialchars(number_format($gross_salary_value, 2, '.', '')); ?>">
            <input type="hidden" name="net_salary"   id="net_salary"   value="<?php echo htmlspecialchars(number_format($net_salary_value, 2, '.', '')); ?>">

            <div class="action-bar">
                <button type="submit" name="save_salary" class="btn btn-green">&#128190; Save Salary Details</button>
                <div class="month-box">
                    <label>Month <small style="color:#64748b;font-weight:400;">(leave blank for running/base salary)</small></label>
                    <input type="month" name="salary_month" value="<?php echo htmlspecialchars($selected_salary_month); ?>">
                </div>
            </div>
            <div class="note">Saving with a month stores the salary for that specific month. The base (running) salary is used when no month-specific record exists.</div>
        </div>
    </form>

    <?php else: ?>
    <div class="card">
        <div class="empty-state">Search by User No / Name / Employee ID to manage an employee's salary.</div>
    </div>
    <?php endif; ?>

</div>

<script>
function numberValue(id) {
    var el = document.getElementById(id);
    if (!el) return 0;
    var v = parseFloat(el.value);
    return isNaN(v) ? 0 : v;
}

function calculateNetSalary() {
    var basic       = numberValue('basic_salary');
    var allowance   = numberValue('allowance');
    var attAllow    = numberValue('att_allowance');
    var otHours     = numberValue('ot_hours');
    var foodWon     = numberValue('food_allowance_won');
    var foodCompany = numberValue('food_allowance_company');
    var fixedSalary = numberValue('fixed_salary');

    // OT amount = (basic / 30 / 8) * 1.25 * hours
    var otAmount = basic > 0 ? ((basic / 30 / 8) * 1.25 * otHours) : 0;
    if (!isFinite(otAmount)) otAmount = 0;

    var otField = document.getElementById('ot');
    if (otField) otField.value = otAmount.toFixed(2);

    // Fixed salary employees: flat amount + food only (no basic/allowance/att/OT).
    var gross;
    if (fixedSalary > 0) {
        gross = fixedSalary + foodWon + foodCompany;
    } else {
        gross = basic + allowance + attAllow + otAmount + foodWon + foodCompany;
    }
    var deductions = numberValue('insurance_amount') + numberValue('other_deduction');
    var net = gross - deductions;

    var gs = document.getElementById('gross_salary');
    var ns = document.getElementById('net_salary');
    var gt = document.getElementById('gross_salary_text');
    var nt = document.getElementById('net_salary_text');
    if (gs) gs.value = gross.toFixed(2);
    if (ns) ns.value = net.toFixed(2);
    if (gt) gt.innerText = gross.toFixed(2);
    if (nt) nt.innerText = net.toFixed(2);
}

[
    'basic_salary', 'allowance', 'att_allowance', 'fixed_salary',
    'food_allowance_company', 'food_allowance_won',
    'insurance_amount', 'other_deduction'
].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', calculateNetSalary);
});

calculateNetSalary();
</script>
</body>
</html>
