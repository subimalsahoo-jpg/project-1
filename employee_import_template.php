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

function add_missing_column($conn, $table, $column, $definition) {
    $safe_column = esc($conn, $column);
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$safe_column'");
    if ($check && mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD `$column` $definition");
    }
}

function value_from($row, $keys) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return '';
}

function csv_cell($value) {
    $value = (string)$value;
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    return '"' . str_replace('"', '""', $value) . '"';
}

$required_employee_columns = [
    'gender' => "VARCHAR(30) DEFAULT ''",
    'employee_status' => "VARCHAR(30) DEFAULT 'Active'",
    'phone_home' => "VARCHAR(100) DEFAULT ''",
    'phone_won_country' => "VARCHAR(100) DEFAULT ''",
    'device' => "VARCHAR(100) DEFAULT ''",
    'day_shift' => "VARCHAR(100) DEFAULT ''",
    'passport' => "VARCHAR(100) DEFAULT ''",
    'passport_number' => "VARCHAR(100) DEFAULT ''",
    'nationality' => "VARCHAR(100) DEFAULT ''",
    'marital_status' => "VARCHAR(50) DEFAULT ''",
    'passport_issue' => "DATE NULL",
    'passport_expiry' => "DATE NULL",
    'visa_id_number' => "VARCHAR(100) DEFAULT ''",
    'emirates_id_number' => "VARCHAR(100) DEFAULT ''",
    'uid_number' => "VARCHAR(100) DEFAULT ''",
    'visa_issuing_date' => "DATE NULL",
    'visa_expiry_date' => "DATE NULL",
    'insurance_number' => "VARCHAR(100) DEFAULT ''",
    'insurance_issuing_date' => "DATE NULL",
    'insurance_expiry_date' => "DATE NULL",
    'saif_zone_id' => "VARCHAR(100) DEFAULT ''",
    'email' => "VARCHAR(150) DEFAULT ''",
    'previous_company' => "VARCHAR(255) DEFAULT ''",
    'resign_date' => "DATE NULL",
    'salary_by' => "VARCHAR(20) DEFAULT ''",
    'basic_salary' => "DECIMAL(10,2) DEFAULT 0",
    'allowance' => "DECIMAL(10,2) DEFAULT 0",
    'att_allowance' => "DECIMAL(10,2) DEFAULT 0",
    'ot' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won' => "DECIMAL(10,2) DEFAULT 0",
    'insurance_amount' => "DECIMAL(10,2) DEFAULT 0",
    'other_deduction' => "DECIMAL(10,2) DEFAULT 0",
    'net_salary' => "DECIMAL(10,2) DEFAULT 0"
];

foreach ($required_employee_columns as $column => $definition) {
    add_missing_column($conn, 'employees', $column, $definition);
}

$columns = [
    'user_no',
    'employee_id',
    'card_no',
    'bio_met_no',
    'full_name',
    'gender',
    'department',
    'employee_status',
    'designation',
    'birthday',
    'joining_date',
    'phone',
    'phone_home',
    'address',
    'device',
    'day_shift',
    'passport',
    'passport_number',
    'nationality',
    'marital_status',
    'passport_issue',
    'passport_expiry',
    'visa_id_number',
    'emirates_id_number',
    'uid_number',
    'visa_issuing_date',
    'visa_expiry_date',
    'insurance_number',
    'insurance_issuing_date',
    'insurance_expiry_date',
    'saif_zone_id',
    'email',
    'previous_company',
    'resign_date',
    'salary_by',
    'basic_salary',
    'allowance',
    'att_allowance',
    'ot',
    'food_allowance_company',
    'food_allowance_own',
    'insurance_amount',
    'other_deduction',
    'net_salary'
];

$fallbacks = [
    'employee_id' => ['employee_id', 'bio_met_no', 'card_no'],
    'bio_met_no' => ['bio_met_no', 'employee_id', 'card_no'],
    'employee_status' => ['employee_status', 'status'],
    'phone_home' => ['phone_home', 'home_phone', 'phone_won_country'],
    'passport' => ['passport', 'passport_number'],
    'passport_number' => ['passport_number', 'passport'],
    'passport_issue' => ['passport_issue', 'passport_issuing', 'passport_issuing_date'],
    'passport_expiry' => ['passport_expiry', 'passport_expire', 'passport_expire_date'],
    'visa_id_number' => ['visa_id_number'],
    'emirates_id_number' => ['emirates_id_number'],
    'previous_company' => ['previous_company', 'previous_company_name'],
    'food_allowance_own' => ['food_allowance_won', 'food_allowance_own']
];

$result = mysqli_query($conn, "
    SELECT *
    FROM employees
    ORDER BY CAST(user_no AS UNSIGNED) ASC, user_no ASC
");

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=employee_overview_export_import_" . date('Y_m_d') . ".csv");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";
echo implode(',', array_map('csv_cell', $columns)) . "\r\n";
echo csv_cell('Do not change row 1 column names. Existing employee data starts from row 4. Add new employees below existing rows, then import this same file.') . "\r\n";
echo csv_cell('Date format: yyyy-mm-dd. Status: Active / Inactive / Resigned. Gender: Male / Female / Shemale. Salary by: Cash / Bank. Advance is managed from Advance Salary page, not this file.') . "\r\n";

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $line = [];
        foreach ($columns as $column) {
            $keys = $fallbacks[$column] ?? [$column];
            $line[] = csv_cell(value_from($row, $keys));
        }
        echo implode(',', $line) . "\r\n";
    }
}

for ($i = 0; $i < 20; $i++) {
    echo implode(',', array_fill(0, count($columns), '""')) . "\r\n";
}
exit;
?>
