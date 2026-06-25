<?php
include 'auth.php';
requirePermission('employee_add');

$message = "";
$search_employee = null;
$employees_columns = [];
$salary_columns = [];
$salary_month_input = $_POST['salary_month'] ?? ($_GET['salary_month'] ?? '');
$salary_month = normalize_input_month($salary_month_input, '');
$salary_lookup_month = $salary_month !== '' ? $salary_month : date('Y-m');

if (!is_dir("uploads")) {
    mkdir("uploads", 0777, true);
}

/* ─────────────────────────────────────────────
   Helper Functions
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

function uploadPhoto() {
    if (
        isset($_FILES['photo']) &&
        $_FILES['photo']['error'] === UPLOAD_ERR_OK &&
        $_FILES['photo']['name'] !== ''
    ) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            return "";  // silently skip non-image uploads
        }

        $ext        = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $photo_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], 'uploads/' . $photo_name)) {
            return $photo_name;
        }
    }
    return "";
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

function display_date_dmy($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') {
        return '';
    }

    $time = strtotime($value);
    return $time ? date('d-m-Y', $time) : $value;
}

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, $value);
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
   Salary numbers from POST
   FIX: gross calculation — food_company was incorrectly added to gross
        it should be EARNED (food_won), DEDUCTED (food_company)
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
    // Deductions
    $total_deduction = $advance + $insurance + $other;
    $net_salary      = $gross - $total_deduction;

    return [
        'basic_salary'          => $basic_salary,
        'allowance'             => $allowance,
        'att_allowance'         => $att_allowance,
        'ot'                    => $ot,
        'fixed_salary'          => $fixed_salary,
        'food_allowance_company'=> $food_company,
        'food_allowance_won'    => $food_won,
        'food_allowance'        => $food_company + $food_won,
        'advance_amount'        => $advance,
        'insurance_amount'      => $insurance,
        'other_deduction'       => $other,
        'salary_by'             => post_val('salary_by'),
        'gross_salary'          => $gross,
        'total_deduction'       => $total_deduction,
        'net_salary'            => $net_salary,
    ];
}

/* ─────────────────────────────────────────────
   Employee data from POST
───────────────────────────────────────────── */
function employee_data_from_post() {
    $phone_uae       = post_val('phone');
    $phone_home      = post_val('phone_home');
    $bio_met_no      = post_val('card_no');
    $birthday        = normalize_input_date(post_val('birthday'));
    $joining_date    = normalize_input_date(post_val('joining_date'));
    $passport_issue  = normalize_input_date(post_val('passport_issue'));
    $passport_expiry = normalize_input_date(post_val('passport_expiry'));
    $visa_issue      = normalize_input_date(post_val('visa_issuing_date'));
    $visa_expiry     = normalize_input_date(post_val('visa_expiry_date'));
    $insurance_issue = normalize_input_date(post_val('insurance_issuing_date'));
    $insurance_expiry = normalize_input_date(post_val('insurance_expiry_date'));
    $resign_date     = normalize_input_date(post_val('resign_date'));

    return [
        'user_no'          => post_val('user_no'),

        // Bio / Card No — multiple possible column names
        'card_no'          => $bio_met_no,
        'bio_met_no'       => $bio_met_no,
        'bio_met._no'      => $bio_met_no,
        'bio_met._No.'     => $bio_met_no,

        'full_name'        => post_val('full_name'),
        'gender'           => post_val('gender'),
        'department'       => post_val('department'),
        'employee_status'  => post_val('employee_status', 'Active'),
        'designation'      => post_val('designation'),
        'birthday'         => $birthday,
        'joining_date'     => $joining_date,

        // Phone UAE — multiple possible column names
        'phone'                  => $phone_uae,
        'phone_number_uae'       => $phone_uae,
        'phone_number_(UAE)'     => $phone_uae,

        // Phone home — multiple possible column names
        'phone_home'                   => $phone_home,
        'home_phone'                   => $phone_home,
        'phone_home_country'           => $phone_home,
        'phone_number_home_country'    => $phone_home,
        'phone_number_(Home Country)'  => $phone_home,
        'phone_won_country'            => $phone_home,
        'phone_number_won_country'     => $phone_home,
        'phone_number_(Won Country)'   => $phone_home,

        'address'          => post_val('address'),
        'passport'         => post_val('passport'),

        'passport_issue'        => $passport_issue,
        'passport_issuing'      => $passport_issue,
        'passport_issuing_date' => $passport_issue,

        'passport_expiry'       => $passport_expiry,
        'passport_Expire'       => $passport_expiry,
        'passport_expire_date'  => $passport_expiry,

        'nationality'            => post_val('nationality'),
        'emirates_id_number'     => post_val('emirates_id_number'),
        'saif_zone_id'           => post_val('saif_zone_id'),
        'uid_number'             => post_val('uid_number'),
        'visa_issuing_date'      => $visa_issue,
        'visa_expiry_date'       => $visa_expiry,
        'insurance_number'       => post_val('insurance_number'),
        'insurance_issuing_date' => $insurance_issue,
        'insurance_expiry_date'  => $insurance_expiry,
        'email'                  => post_val('email'),

        'previous_company'                              => post_val('previous_company'),
        'previous_company_name'                         => post_val('previous_company'),
        'previous_company_name_&_country(optional)'     => post_val('previous_company'),

        'resign_date'      => $resign_date,
    ];
}

/* ─────────────────────────────────────────────
   Save employee to DB (INSERT or UPDATE)
───────────────────────────────────────────── */
function save_employee_values($conn, $columns, $data, $photo, $id = null) {
    $sets   = [];
    $fields = [];
    $values = [];

    foreach ($data as $key => $value) {
        sql_set($conn, $columns, $key, $value, $sets);
        sql_insert($conn, $columns, $key, $value, $fields, $values);
    }

    if ($photo !== '') {
        sql_set($conn, $columns, 'photo', $photo, $sets);
        sql_insert($conn, $columns, 'photo', $photo, $fields, $values);
    }

    if ($id) {
        if (empty($sets)) return true;
        $safe_id = esc($conn, $id);
        return mysqli_query($conn, "UPDATE employees SET " . implode(',', $sets) . " WHERE id='$safe_id'");
    }

    if (empty($fields)) return false;
    return mysqli_query($conn, "INSERT INTO employees (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")");
}

/* ─────────────────────────────────────────────
   Save salary record (INSERT or UPDATE)
───────────────────────────────────────────── */
function save_salary_values($conn, $employee_columns, $salary_columns, $id, $user_no, $employee_id, $salary, $salary_month) {
    if (empty($salary_columns)) return true;

    $safe_user_no     = esc($conn, $user_no);
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
   Auto-migrate: ensure required columns exist
───────────────────────────────────────────── */
$required_employee_columns = [
    'passport'               => "VARCHAR(100) DEFAULT ''",
    'passport_issue'         => "DATE NULL",
    'passport_expiry'        => "DATE NULL",
    'nationality'            => "VARCHAR(100) DEFAULT ''",
    'emirates_id_number'     => "VARCHAR(100) DEFAULT ''",
    'saif_zone_id'           => "VARCHAR(100) DEFAULT ''",
    'visa_id_number'         => "VARCHAR(100) DEFAULT ''",
    'visa_issuing_date'      => "DATE NULL",
    'visa_expiry_date'       => "DATE NULL",
    'uid_number'             => "VARCHAR(100) DEFAULT ''",
    'insurance_number'       => "VARCHAR(100) DEFAULT ''",
    'insurance_issuing_date' => "DATE NULL",
    'insurance_expiry_date'  => "DATE NULL",
    'salary_by'              => "VARCHAR(20) DEFAULT ''",
    'fixed_salary'           => "DECIMAL(10,2) DEFAULT 0",
];
foreach ($required_employee_columns as $col_name => $definition) {
    $check_col = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE '" . esc($conn, $col_name) . "'");
    if ($check_col && mysqli_num_rows($check_col) == 0) {
        mysqli_query($conn, "ALTER TABLE employees ADD `$col_name` $definition");
    }
}

// Load after potential ALTER
$employees_columns = table_columns($conn, 'employees');

$required_salary_columns = [
    'salary_month'           => "VARCHAR(7) DEFAULT ''",
    'salary_by'              => "VARCHAR(20) DEFAULT ''",
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won'     => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance'         => "DECIMAL(10,2) DEFAULT 0",
    'gross_salary'           => "DECIMAL(10,2) DEFAULT 0",
    'total_deduction'        => "DECIMAL(10,2) DEFAULT 0",
    'net_salary'             => "DECIMAL(10,2) DEFAULT 0",
    'fixed_salary'           => "DECIMAL(10,2) DEFAULT 0",
];
foreach ($required_salary_columns as $col_name => $definition) {
    $check_col = mysqli_query($conn, "SHOW COLUMNS FROM employee_salary_records LIKE '" . esc($conn, $col_name) . "'");
    if ($check_col && mysqli_num_rows($check_col) == 0) {
        mysqli_query($conn, "ALTER TABLE employee_salary_records ADD `$col_name` $definition");
    }
}

// Load after potential ALTER
$salary_columns = table_columns($conn, 'employee_salary_records');

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
   GET: Search employee
───────────────────────────────────────────── */
if (isset($_GET['search_user_no']) && trim($_GET['search_user_no']) !== '') {
    $search_val = esc($conn, trim($_GET['search_user_no']));
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
   POST: Save / Update Employee
───────────────────────────────────────────── */
if (isset($_POST['save_employee']) || isset($_POST['update_employee'])) {
    $id   = post_val('id');
    $data = employee_data_from_post();
    $photo = uploadPhoto();

    // FIX: keep existing photo if no new one uploaded
    if ($photo === '' && $id !== '') {
        $existing_q = mysqli_query($conn, "SELECT photo FROM employees WHERE id='" . esc($conn, $id) . "' LIMIT 1");
        if ($existing_q && $row = mysqli_fetch_assoc($existing_q)) {
            // Don't overwrite — save_employee_values only writes photo if $photo !== ''
        }
    }

    // Duplicate check — only User No must be unique.
    // Card No (Bio Met No) is allowed to repeat across employees.
    $dup_user_no = esc($conn, $data['user_no']);
    $dup_sql = "SELECT id FROM employees WHERE user_no='$dup_user_no'";
    if ($id !== '') {
        $dup_sql .= " AND id != '" . esc($conn, $id) . "'";
    }
    $duplicate = mysqli_query($conn, $dup_sql);

    if ($duplicate && mysqli_num_rows($duplicate) > 0) {
        $message = "<div class='message error'>Duplicate User No already exists!</div>";
    } else {
        $ok = save_employee_values($conn, $employees_columns, $data, $photo, $id ?: null);
        if ($ok) {
            $lookup_id = $id ?: mysqli_insert_id($conn);
            $message = "<div class='message success'>Employee information saved successfully.</div>";
            $res = mysqli_query($conn, "SELECT * FROM employees WHERE id='" . esc($conn, $lookup_id) . "' LIMIT 1");
            if ($res) {
                $search_employee = mysqli_fetch_assoc($res);
                $search_employee = merge_salary_for_month($conn, $search_employee, $salary_columns, $salary_lookup_month);
            }
        } else {
            $message = "<div class='message error'>Error saving employee: " . htmlspecialchars(mysqli_error($conn)) . "</div>";
        }
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
        $message = "<div class='message error'>Please search or save employee first, then save salary details.</div>";
    } elseif ($existing_salary_count > 0 && $salary_month === '') {
        $message = "<div class='message error'>Please select Month Calendar before changing salary details.</div>";
    } else {
        $ok = save_salary_values($conn, $employees_columns, $salary_columns, $id, $user_no, $employee_id, $salary, $salary_month);
        if ($ok) {
            $month_label = $salary_month !== '' ? date('F Y', strtotime($salary_month . "-01")) : "Running Salary";
            $message = "<div class='message success'>Salary saved for $month_label. Net Salary: " . money($salary['net_salary']) . " AED</div>";
            $res = mysqli_query($conn, "SELECT * FROM employees WHERE user_no='" . esc($conn, $user_no) . "' LIMIT 1");
            if ($res) {
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
$basic_salary_value      = (float)first_val($search_employee, ['basic_salary'], 0);
$allowance_value         = (float)first_val($search_employee, ['allowance'], 0);
$att_allowance_value     = (float)first_val($search_employee, ['att_allowance'], 0);
$advance_value           = 0;
$insurance_value         = (float)first_val($search_employee, ['insurance_amount'], 0);
$other_deduction_value   = (float)first_val($search_employee, ['other_deduction'], 0);
$food_company_value      = (float)first_val($search_employee, ['food_allowance_company', 'food_allowance'], 0);
$food_won_value          = (float)first_val($search_employee, ['food_allowance_won'], 0);
$fixed_salary_value      = (float)first_val($search_employee, ['fixed_salary'], 0);

// OT from overtime_records for the selected month
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

// FIX: gross must match JS logic — food_company IS part of gross (employee receives full)
// Fixed salary employees: flat amount + food only (no basic/allowance/att/OT)
if ($fixed_salary_value > 0) {
    $gross_salary_value = $fixed_salary_value + $food_won_value + $food_company_value;
} else {
    $gross_salary_value = $basic_salary_value + $allowance_value + $att_allowance_value
                        + $monthly_ot_amount + $food_won_value + $food_company_value;
}
$total_deduction_value = $insurance_value + $other_deduction_value;
$net_salary_value      = $gross_salary_value - $total_deduction_value;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add / Edit Employee</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #eef2f7;
    padding: 24px;
    color: #1a2533;
}

/* ── Buttons ── */
.btn {
    display: inline-block;
    background: #2563eb;
    color: #fff;
    padding: 9px 18px;
    text-decoration: none;
    border-radius: 6px;
    margin: 0 5px 16px 0;
    border: 0;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: background .2s;
}
.btn:hover { background: #1d4ed8; }
.btn-dark  { background: #1e293b; }
.btn-dark:hover  { background: #0f172a; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }

/* ── Messages ── */
.message {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 600;
    font-size: 14px;
}
.success { color: #166534; background: #dcfce7; border: 1px solid #86efac; }
.error   { color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5; }

/* ── Search box ── */
.search-box {
    background: #fff;
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #dde3ec;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.search-box input {
    width: 360px;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #f8fafc;
}
.search-box input:focus { border-color: #2563eb; outline: none; background: #fff; }

/* ── Layout ── */
h2 { margin-bottom: 14px; font-size: 20px; color: #1e293b; }

.main-layout {
    display: grid;
    grid-template-columns: 55% 1fr;
    gap: 24px;
    align-items: start;
}

form, .detail-box {
    background: #fff;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,.07);
    border: 1px solid #e2e8f0;
}

/* ── Form grid ── */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.full-width { grid-column: 1 / 3; }

label {
    font-weight: 600;
    font-size: 13px;
    color: #374151;
}

input[type="text"],
input[type="email"],
input[type="tel"],
input[type="date"],
input[type="number"],
input[type="month"],
input[type="file"],
select {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #f8fafc;
    color: #1a2533;
    transition: border-color .15s;
}
input:focus, select:focus {
    border-color: #2563eb;
    outline: none;
    background: #fff;
}
input[readonly] { background: #e9ecef; color: #64748b; cursor: not-allowed; }

/* ── Section titles ── */
.section-title {
    grid-column: 1 / 3;
    padding: 10px 14px;
    background: #1e293b;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    border-radius: 6px;
    letter-spacing: .3px;
}

/* ── Save employee button ── */
.employee-save {
    grid-column: 1 / 3;
    width: 50%;
    justify-self: center;
    color: #1d4ed8;
    background: #fff;
    border: 3px solid #dc2626;
    font-weight: 700;
    padding: 9px 18px;
    font-size: 15px;
    border-radius: 8px;
    cursor: pointer;
    transition: background .2s, color .2s;
}
.employee-save:hover { background: #dc2626; color: #fff; }

/* ── Salary rows ── */
.net-salary-row {
    grid-column: 1 / 3;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 11px 16px;
    background: #1e293b;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    border-radius: 6px;
}

.net-salary-actual {
    grid-column: 1 / 3;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 11px 16px;
    background: #fff;
    color: #16a34a;
    border: 3px solid #16a34a;
    font-weight: 700;
    font-size: 14px;
    border-radius: 6px;
}

/* ── Salary action row ── */
.salary-action-row {
    grid-column: 1 / 3;
    display: grid;
    grid-template-columns: 1.3fr 0.8fr;
    gap: 16px;
    align-items: end;
}

.salary-submit {
    width: 100%;
    padding: 11px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 700;
    background: #2563eb;
    color: #fff;
    border: none;
    cursor: pointer;
    transition: background .2s;
}
.salary-submit:hover { background: #1d4ed8; }

.salary-month-box label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    font-size: 13px;
}

/* ── Detail box ── */
.detail-box h3 {
    background: #1e293b;
    color: #fff;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 14px;
    margin-bottom: 14px;
}

.detail-row {
    padding: 7px 0;
    border-bottom: 1px solid #e9ecef;
    font-size: 13px;
    display: flex;
    gap: 6px;
}
.detail-row b { min-width: 140px; color: #374151; }

.emp-photo {
    display: block;
    width: 150px;
    height: 150px;
    border-radius: 10px;
    object-fit: cover;
    border: 3px solid #2563eb;
    margin: 0 auto 18px;
}

.empty-detail {
    color: #94a3b8;
    font-size: 13px;
    padding: 10px 0;
}

/* ── Responsive ── */
@media (max-width: 960px) {
    .main-layout { grid-template-columns: 1fr; }
    .form-grid   { grid-template-columns: 1fr; }
    .full-width, .section-title, .net-salary-row, .net-salary-actual,
    .salary-action-row, .employee-save { grid-column: 1; width: 100%; }
    .salary-action-row { grid-template-columns: 1fr; }
    .search-box { flex-direction: column; }
    .search-box input { width: 100%; }
}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<a href="dashboard.php" class="btn btn-dark">&#8592; Dashboard</a>
<a href="employee_list.php" class="btn">Employee List</a>

<h2>Add / Edit Employee</h2>

<?php echo $message; ?>

<!-- ── Search ── -->
<div class="search-box">
    <form method="GET" style="display:contents;">
        <!-- FIX: preserve salary_month across search -->
        <input type="hidden" name="salary_month" value="<?php echo htmlspecialchars($salary_month); ?>">
        <input type="text" name="search_user_no"
               placeholder="Search by User No / Name / Employee ID / Card No"
               value="<?php echo htmlspecialchars($_GET['search_user_no'] ?? ''); ?>">
        <button type="submit" class="btn btn-dark">&#128269; Search</button>
        <a href="add_employee.php" class="btn btn-danger">&#10005; Clear</a>
    </form>
</div>

<div class="main-layout">

<!-- ══════════════════════════════
     Main Form
══════════════════════════════ -->
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id"          value="<?php echo htmlspecialchars(val($search_employee, 'id')); ?>">
    <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars(val($search_employee, 'employee_id')); ?>">
    <input type="hidden" name="user_no"     value="<?php echo htmlspecialchars(val($search_employee, 'user_no')); ?>">

    <div class="form-grid">

        <!-- ── Employee Details section ── -->
        <div class="section-title">&#128100; Employee Details</div>

        <div class="form-group full-width">
            <label>Employee Photo</label>
            <input type="file" name="photo" accept="image/*">
        </div>

        <div class="form-group">
            <label>User No.</label>
            <input type="text" name="user_no" value="<?php echo htmlspecialchars(val($search_employee, 'user_no')); ?>">
        </div>

        <div class="form-group">
            <label>Bio Met. No. (Card No)</label>
            <input type="text" name="card_no" value="<?php echo htmlspecialchars(first_val($search_employee, ['card_no', 'bio_met_no', 'bio_met._no', 'bio_met._No.'])); ?>">
        </div>

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars(val($search_employee, 'full_name')); ?>">
        </div>

        <div class="form-group">
            <label>Gender</label>
            <select name="gender">
                <option value="">Select Gender</option>
                <option value="Male"    <?php echo selected($search_employee, 'gender', 'Male'); ?>>Male</option>
                <option value="Female"  <?php echo selected($search_employee, 'gender', 'Female'); ?>>Female</option>
                <option value="Shemale" <?php echo selected($search_employee, 'gender', 'Shemale'); ?>>Shemale</option>
            </select>
        </div>

        <div class="form-group">
            <label>Department</label>
            <select name="department">
                <option value="">Select Department</option>
                <?php
                $departments = [
                    'CUTTING DEPT','FABRIC STORE','FACTORY','FINISHING DEPT',
                    'HOUSE KEEPING','MAINTENANCE - GENERAL','MAINTENANCE - SEWING',
                    'OFFICE STAFF','PACKING DEPT','PRESSING DEPT','PRINTING DEPT',
                    'SAMPLE DEPT','SECURITY','SEWING DEPT','STORE'
                ];
                foreach ($departments as $dept) {
                    $sel = (val($search_employee, 'department') === $dept) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($dept) . '" ' . $sel . '>' . htmlspecialchars($dept) . '</option>';
                }
                ?>
            </select>
        </div>

        <div class="form-group">
            <label>Employee Status</label>
            <select name="employee_status">
                <option value="Active"   <?php echo selected($search_employee, 'employee_status', 'Active'); ?>>Active</option>
                <option value="Inactive" <?php echo selected($search_employee, 'employee_status', 'Inactive'); ?>>Inactive</option>
                <option value="Resigned" <?php echo selected($search_employee, 'employee_status', 'Resigned'); ?>>Resigned</option>
                <option value="Absconding" <?php echo selected($search_employee, 'employee_status', 'Absconding'); ?>>Absconding</option>
                <option value="Terminated" <?php echo selected($search_employee, 'employee_status', 'Terminated'); ?>>Terminated</option>
                <option value="End of Contract" <?php echo selected($search_employee, 'employee_status', 'End of Contract'); ?>>End of Contract</option>
            </select>
        </div>

        <div class="form-group">
            <label>Designation</label>
            <select name="designation">
                <option value="">Select Designation</option>
                <?php
                $designations = [
                    'ADMIN','DIRECTOR','ASST.QC','CHECKER','CLEANER','CUTTER',
                    'DESIGNER','ELECTRICIAN','FABRIC ASSISTANT',
                    'FINANCE & ACCOUNT DEPT','HELPER','IT','MECHANIC',
                    'MERCHANDISER','MO','OFFICE BOY','OFFICE STAFF',
                    'PATTER MASTER','PRESSMAN','PRINTING','PRO','QC',
                    'QUALITY CHECKER','SAMPLE MAKER','SECURITY',
                    'STORE KEEPERS','SUPERVISOR','TRIMMER'
                ];
                foreach ($designations as $d) {
                    $sel = (val($search_employee, 'designation') === $d) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($d) . '" ' . $sel . '>' . htmlspecialchars($d) . '</option>';
                }
                ?>
            </select>
        </div>

        <div class="form-group">
            <label>Birthday</label>
            <input type="date" name="birthday" value="<?php echo htmlspecialchars(val($search_employee, 'birthday')); ?>">
        </div>

        <div class="form-group">
            <label>Start Date (Joining)</label>
            <input type="date" name="joining_date" value="<?php echo htmlspecialchars(val($search_employee, 'joining_date')); ?>">
        </div>

        <div class="form-group">
            <label>Phone Number (UAE)</label>
            <input type="tel" name="phone" pattern="[0-9]+" maxlength="15"
                   value="<?php echo htmlspecialchars(first_val($search_employee, ['phone','phone_number_uae','phone_number_(UAE)'])); ?>">
        </div>

        <div class="form-group">
            <label>Phone Number (Home Country)</label>
            <input type="tel" name="phone_home" pattern="[0-9]+" maxlength="15"
                   value="<?php echo htmlspecialchars(first_val($search_employee, ['phone_home','home_phone','phone_home_country','phone_number_home_country','phone_number_(Home Country)','phone_won_country','phone_number_won_country','phone_number_(Won Country)'])); ?>">
        </div>

        <div class="form-group full-width">
            <label>Address</label>
            <input type="text" name="address" value="<?php echo htmlspecialchars(val($search_employee, 'address')); ?>">
        </div>

        <div class="form-group">
            <label>Passport Number</label>
            <input type="text" name="passport" value="<?php echo htmlspecialchars(val($search_employee, 'passport')); ?>">
        </div>

        <div class="form-group">
            <label>Nationality</label>
            <select name="nationality">
                <option value="">Select Nationality</option>
                <?php foreach (['Indian','Bangladeshi','Srilankan','Pakistani','Nepali','Bhutani','UAE'] as $c) { ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo selected($search_employee, 'nationality', $c); ?>>
                        <?php echo htmlspecialchars($c); ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Passport Issuing Date</label>
            <input type="date" name="passport_issue"
                   value="<?php echo htmlspecialchars(first_val($search_employee, ['passport_issue','passport_issuing','passport_issuing_date'])); ?>">
        </div>

        <div class="form-group">
            <label>Passport Expire Date</label>
            <input type="date" name="passport_expiry"
                   value="<?php echo htmlspecialchars(first_val($search_employee, ['passport_expiry','passport_Expire','passport_expire_date'])); ?>">
        </div>

        <div class="form-group">
            <label>Emirates ID Number</label>
            <input type="text" name="emirates_id_number" value="<?php echo htmlspecialchars(val($search_employee, 'emirates_id_number')); ?>">
        </div>

        <div class="form-group">
            <label>UID Number</label>
            <input type="text" name="uid_number" value="<?php echo htmlspecialchars(val($search_employee, 'uid_number')); ?>">
        </div>

        <div class="form-group">
            <label>Visa Issuing Date</label>
            <input type="date" name="visa_issuing_date" value="<?php echo htmlspecialchars(val($search_employee, 'visa_issuing_date')); ?>">
        </div>

        <div class="form-group">
            <label>Visa Expiry Date</label>
            <input type="date" name="visa_expiry_date" value="<?php echo htmlspecialchars(val($search_employee, 'visa_expiry_date')); ?>">
        </div>

        <div class="form-group">
            <label>Insurance Number</label>
            <input type="text" name="insurance_number"
                   value="<?php echo htmlspecialchars(first_val($search_employee, ['insurance_number','insurance_no','inurance_number'])); ?>">
        </div>

        <div class="form-group">
            <label>Insurance Expire Date</label>
            <input type="date" name="insurance_expiry_date" value="<?php echo htmlspecialchars(val($search_employee, 'insurance_expiry_date')); ?>">
        </div>

        <div class="form-group">
            <label>SAIF ZONE ID</label>
            <input type="text" name="saif_zone_id" value="<?php echo htmlspecialchars(val($search_employee, 'saif_zone_id')); ?>">
        </div>

        <div class="form-group">
            <label>Previous Company &amp; Country (optional)</label>
            <input type="text" name="previous_company"
                   value="<?php echo htmlspecialchars(first_val($search_employee, ['previous_company','previous_company_name','previous_company_name_&_country(optional)'])); ?>">
        </div>

        <div class="form-group">
            <label>Resign Date</label>
            <input type="date" name="resign_date" value="<?php echo htmlspecialchars(val($search_employee, 'resign_date')); ?>">
        </div>

        <div class="form-group full-width">
            <label>Email</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars(val($search_employee, 'email')); ?>">
        </div>

        <button type="submit"
                class="employee-save"
                name="<?php echo $search_employee ? 'update_employee' : 'save_employee'; ?>">
            <?php echo $search_employee ? '&#9998; Edit / Update Employee' : '&#43; Add Employee'; ?>
        </button>

        <?php if ($search_employee): ?>
        <a href="employee_salary.php?user_no=<?php echo urlencode(val($search_employee, 'user_no')); ?>"
           class="employee-save"
           style="display:block;text-align:center;text-decoration:none;background:#16a34a;border-color:#16a34a;color:#fff;margin-top:14px;">
            &#128176; Manage Salary &amp; Deductions &#8594;
        </a>
        <?php else: ?>
        <p style="margin-top:14px;text-align:center;color:#64748b;font-size:13px;">
            &#128176; Save the employee first, then set salary from the <b>Salary Details</b> page.
        </p>
        <?php endif; ?>


    </div><!-- /.form-grid -->
</form>

<!-- ══════════════════════════════
     Detail Box
══════════════════════════════ -->
<div class="detail-box">
    <h3>&#128203; Employee Details</h3>

    <?php if (!empty($search_employee['photo'])): ?>
    <img class="emp-photo" src="uploads/<?php echo htmlspecialchars($search_employee['photo']); ?>" alt="Employee Photo">
    <?php endif; ?>

    <?php if ($search_employee): ?>
    <div class="detail-row"><b>User No:</b>          <?php echo htmlspecialchars(val($search_employee, 'user_no')); ?></div>
    <div class="detail-row"><b>Employee ID:</b>       <?php echo htmlspecialchars(val($search_employee, 'employee_id')); ?></div>
    <div class="detail-row"><b>Card No:</b>           <?php echo htmlspecialchars(val($search_employee, 'card_no')); ?></div>
    <div class="detail-row"><b>Name:</b>              <?php echo htmlspecialchars(val($search_employee, 'full_name')); ?></div>
    <div class="detail-row"><b>Gender:</b>            <?php echo htmlspecialchars(val($search_employee, 'gender')); ?></div>
    <div class="detail-row"><b>Department:</b>        <?php echo htmlspecialchars(val($search_employee, 'department')); ?></div>
    <div class="detail-row"><b>Designation:</b>       <?php echo htmlspecialchars(val($search_employee, 'designation')); ?></div>
    <div class="detail-row"><b>Status:</b>            <?php echo htmlspecialchars(val($search_employee, 'employee_status')); ?></div>
    <div class="detail-row"><b>Joining Date:</b>      <?php echo htmlspecialchars(display_date_dmy(val($search_employee, 'joining_date'))); ?></div>
    <div class="detail-row"><b>Nationality:</b>       <?php echo htmlspecialchars(val($search_employee, 'nationality')); ?></div>
    <div class="detail-row"><b>Passport No:</b>       <?php echo htmlspecialchars(val($search_employee, 'passport')); ?></div>
    <div class="detail-row"><b>Emirates ID:</b>       <?php echo htmlspecialchars(val($search_employee, 'emirates_id_number')); ?></div>
    <div class="detail-row"><b>Visa Expiry:</b>       <?php echo htmlspecialchars(display_date_dmy(val($search_employee, 'visa_expiry_date'))); ?></div>
    <hr style="margin:10px 0;border-color:#e9ecef;">
    <a href="employee_salary.php?user_no=<?php echo urlencode(val($search_employee, 'user_no')); ?>"
       style="display:block;text-align:center;background:#16a34a;color:#fff;padding:10px;border-radius:6px;text-decoration:none;font-weight:600;">
        &#128176; Manage Salary &amp; Deductions &#8594;
    </a>
    <?php else: ?>
    <p class="empty-detail">Search by User No / Name / Employee ID to view employee details here.</p>
    <?php endif; ?>
</div>

</div><!-- /.main-layout -->

</body>
</html>
