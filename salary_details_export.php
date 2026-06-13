<?php
include 'auth.php';
requirePermission('employee_view');

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function table_columns($conn, $table) {
    $columns = [];
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

function add_missing_column($conn, $table, $column, $definition) {
    $safe_column = esc($conn, $column);
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$safe_column'");
    if ($check && mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD `$column` $definition");
    }
}

function csv_cell($value) {
    $value = str_replace(["\r\n", "\r", "\n"], ' ', (string)$value);
    return '"' . str_replace('"', '""', $value) . '"';
}

function pick($row, $keys, $default = '') {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

$employee_required = [
    'salary_by' => "VARCHAR(20) DEFAULT ''",
    'basic_salary' => "DECIMAL(10,2) DEFAULT 0",
    'allowance' => "DECIMAL(10,2) DEFAULT 0",
    'att_allowance' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won' => "DECIMAL(10,2) DEFAULT 0",
    'insurance_amount' => "DECIMAL(10,2) DEFAULT 0",
    'other_deduction' => "DECIMAL(10,2) DEFAULT 0",
    'net_salary' => "DECIMAL(10,2) DEFAULT 0",
];

foreach ($employee_required as $column => $definition) {
    add_missing_column($conn, 'employees', $column, $definition);
}

$columns = [
    'user_no',
    'employee_id',
    'full_name',
    'department',
    'effective_month',
    'salary_by',
    'basic_salary',
    'allowance',
    'attendance_allowance',
    'food_allowance_company',
    'food_allowance_own',
    'insurance',
    'other_deduction',
];

$result = mysqli_query($conn, "
    SELECT *
    FROM employees
    ORDER BY CAST(user_no AS UNSIGNED) ASC, user_no ASC
");

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=salary_details_import_template_" . date('Y_m_d') . ".csv");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
echo implode(',', array_map('csv_cell', $columns)) . "\r\n";
echo csv_cell('Do not change row 1 column names. Existing employees start from row 4. Fill salary fields and import this file from import_salary_details.php.') . "\r\n";
echo csv_cell('effective_month format: yyyy-mm. Keep blank for running/base salary. If salary changes from a month, enter that month, for example 2026-06. Advance is managed separately from Advance Salary page.') . "\r\n";

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $line = [
            pick($row, ['user_no']),
            pick($row, ['employee_id', 'bio_met_no', 'card_no']),
            pick($row, ['full_name']),
            pick($row, ['department']),
            '',
            pick($row, ['salary_by']),
            pick($row, ['basic_salary'], 0),
            pick($row, ['allowance'], 0),
            pick($row, ['att_allowance'], 0),
            pick($row, ['food_allowance_company', 'food_allowance'], 0),
            pick($row, ['food_allowance_won'], 0),
            pick($row, ['insurance_amount'], 0),
            pick($row, ['other_deduction'], 0),
        ];
        echo implode(',', array_map('csv_cell', $line)) . "\r\n";
    }
}

for ($i = 0; $i < 25; $i++) {
    echo implode(',', array_fill(0, count($columns), '""')) . "\r\n";
}
exit;
?>
