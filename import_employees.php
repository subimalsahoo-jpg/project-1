<?php
include 'auth.php';
requirePermission('uploads_manage');

$message = "";
$results = [];

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, (string)$value);
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

function normalize_header($header) {
    $header = trim((string)$header);
    $header = strtolower($header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim($header, '_');
}

function apply_employee_header_aliases(&$row) {
    $aliases = [
        'id_on_device' => 'employee_id',
        'id_on_device_employee_id' => 'employee_id',
        'bio_metric_card_no' => 'bio_met_no',
        'bio_met_no_card_no' => 'bio_met_no',
        'employee_full_name' => 'full_name',
        'full_name' => 'full_name',
        'position_designation' => 'designation',
        'designation_position' => 'designation',
        'start_date' => 'joining_date',
        'joining_date' => 'joining_date',
        'phone_number_uae' => 'phone',
        'phone_no_uae' => 'phone',
        'phone_number_won_country' => 'phone_home',
        'phone_no_home_country' => 'phone_home',
        'phone_no_home_country_' => 'phone_home',
        'passport_number' => 'passport',
        'passport_issue_date' => 'passport_issue',
        'passport_issuing_date' => 'passport_issue',
        'passport_expire_date' => 'passport_expiry',
        'passport_expiry_date' => 'passport_expiry',
        'emirates_id' => 'emirates_id_number',
        'emirates_id_no' => 'emirates_id_number',
        'emirates_id_number' => 'emirates_id_number',
        'saif_zone_id' => 'saif_zone_id',
        'visa_id_no' => 'visa_id_number',
        'visa_id_number' => 'visa_id_number',
        'uid_no' => 'uid_number',
        'uid_number' => 'uid_number',
        'visa_issue_date' => 'visa_issuing_date',
        'visa_issuing_date' => 'visa_issuing_date',
        'visa_expire_date' => 'visa_expiry_date',
        'visa_expiry_date' => 'visa_expiry_date',
        'insurance_issue_date' => 'insurance_issuing_date',
        'insurance_issuing_date' => 'insurance_issuing_date',
        'insurance_expire_date' => 'insurance_expiry_date',
        'insurance_expiry_date' => 'insurance_expiry_date',
        'previous_company_name' => 'previous_company',
        'previous_company_name_country_optional' => 'previous_company',
        'food_allowance_own' => 'food_allowance_won',
        'food_allowance_own_aed' => 'food_allowance_won',
        'food_allowance_won_aed' => 'food_allowance_won',
    ];

    foreach ($aliases as $alias => $canonical) {
        if (!empty($row[$alias]) && empty($row[$canonical])) {
            $row[$canonical] = $row[$alias];
        }
    }

    if (!empty($row['passport']) && empty($row['passport_number'])) {
        $row['passport_number'] = $row['passport'];
    }
    if (!empty($row['phone_home']) && empty($row['phone_won_country'])) {
        $row['phone_won_country'] = $row['phone_home'];
    }
}

function money_value($value) {
    $value = trim((string)$value);
    $value = str_replace([',', 'AED', 'aed'], '', $value);
    return $value === '' ? 0 : (float)$value;
}

function excel_date_to_ymd($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : '';
    }
    if (is_numeric($value) && (float)$value > 20000) {
        $unix = ((float)$value - 25569) * 86400;
        return gmdate('Y-m-d', (int)$unix);
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : $value;
}

function column_letters_to_index($letters) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($letters));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function read_csv_file($path) {
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) {
        return $rows;
    }
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function read_xlsx_file($path) {
    $rows = [];

    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive is not enabled in PHP. Please upload CSV or enable zip extension.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Unable to open XLSX file.');
    }

    $shared_strings = [];
    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared_xml !== false) {
        $shared = simplexml_load_string($shared_xml);
        foreach ($shared->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string)$run->t;
                }
            }
            $shared_strings[] = $text;
        }
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        $zip->close();
        throw new Exception('First worksheet not found.');
    }

    $sheet = simplexml_load_string($sheet_xml);
    foreach ($sheet->sheetData->row as $row_xml) {
        $row_index = ((int)$row_xml['r']) - 1;
        if (!isset($rows[$row_index])) {
            $rows[$row_index] = [];
        }

        foreach ($row_xml->c as $cell) {
            $ref = (string)$cell['r'];
            $col_index = column_letters_to_index($ref);
            $type = (string)$cell['t'];
            $value = '';

            if ($type === 'inlineStr' && isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            } elseif (isset($cell->v)) {
                $value = (string)$cell->v;
                if ($type === 's') {
                    $value = $shared_strings[(int)$value] ?? '';
                }
            }

            $rows[$row_index][$col_index] = $value;
        }
    }

    $zip->close();
    ksort($rows);
    return array_values($rows);
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

function save_employee($conn, $employee_columns, $row) {
    $user_no = trim((string)($row['user_no'] ?? ''));
    if ($user_no === '') {
        return ['status' => 'Skipped', 'message' => 'Missing user_no'];
    }

    $employee_fields = [
        'user_no', 'employee_id', 'card_no', 'bio_met_no', 'full_name', 'gender',
        'department', 'employee_status', 'designation', 'birthday', 'joining_date',
        'phone', 'phone_home', 'phone_won_country', 'address', 'device', 'day_shift',
        'passport', 'passport_number', 'nationality', 'marital_status', 'passport_issue', 'passport_expiry', 'visa_id_number',
        'emirates_id_number', 'uid_number', 'visa_issuing_date', 'visa_expiry_date',
        'insurance_number', 'insurance_issuing_date', 'insurance_expiry_date', 'saif_zone_id', 'email',
        'previous_company', 'previous_company_name', 'resign_date', 'salary_by',
        'basic_salary', 'allowance', 'att_allowance', 'ot', 'food_allowance',
        'food_allowance_company', 'food_allowance_won',
        'insurance_amount', 'other_deduction', 'net_salary', 'photo'
    ];

    $date_fields = [
        'birthday', 'joining_date', 'passport_issue', 'passport_expiry',
        'visa_issuing_date', 'visa_expiry_date', 'insurance_issuing_date', 'insurance_expiry_date', 'resign_date'
    ];

    foreach ($date_fields as $field) {
        if (isset($row[$field])) {
            $row[$field] = excel_date_to_ymd($row[$field]);
        }
    }

    if (empty($row['employee_status'])) {
        $row['employee_status'] = 'Active';
    }
    if (empty($row['phone_won_country']) && !empty($row['phone_home'])) {
        $row['phone_won_country'] = $row['phone_home'];
    }
    if (empty($row['bio_met_no']) && !empty($row['card_no'])) {
        $row['bio_met_no'] = $row['card_no'];
    }
    if (empty($row['passport_number']) && !empty($row['passport'])) {
        $row['passport_number'] = $row['passport'];
    }

    $money_fields = [
        'basic_salary', 'allowance', 'att_allowance', 'ot', 'food_allowance_company',
        'food_allowance_won', 'insurance_amount', 'other_deduction',
        'net_salary'
    ];
    foreach ($money_fields as $field) {
        if (isset($row[$field])) {
            $row[$field] = money_value($row[$field]);
        }
    }

    $food_company = money_value($row['food_allowance_company'] ?? 0);
    $food_won = money_value($row['food_allowance_won'] ?? 0);
    $row['food_allowance'] = $food_company + $food_won;

    $earned = money_value($row['basic_salary'] ?? 0)
        + money_value($row['allowance'] ?? 0)
        + money_value($row['att_allowance'] ?? 0)
        + money_value($row['ot'] ?? 0)
        + $food_won;
    $deduction = $food_company
        + money_value($row['insurance_amount'] ?? 0)
        + money_value($row['other_deduction'] ?? 0);
    $row['net_salary'] = $earned - $deduction;

    $safe_user_no = esc($conn, $user_no);
    $check = mysqli_query($conn, "SELECT id FROM employees WHERE user_no='$safe_user_no' LIMIT 1");
    $exists = $check && mysqli_num_rows($check) > 0;

    if ($exists) {
        $sets = [];
        foreach ($employee_fields as $field) {
            if ($field !== 'user_no' && array_key_exists($field, $row)) {
                sql_set($conn, $employee_columns, $field, $row[$field], $sets);
            }
        }
        if (!empty($sets)) {
            $ok = mysqli_query($conn, "UPDATE employees SET " . implode(',', $sets) . " WHERE user_no='$safe_user_no'");
            return ['status' => $ok ? 'Updated' : 'Error', 'message' => $ok ? $user_no : mysqli_error($conn)];
        }
        return ['status' => 'Skipped', 'message' => 'No matching columns for ' . $user_no];
    }

    $fields = [];
    $values = [];
    foreach ($employee_fields as $field) {
        if (array_key_exists($field, $row)) {
            sql_insert($conn, $employee_columns, $field, $row[$field], $fields, $values);
        }
    }

    if (empty($fields)) {
        return ['status' => 'Skipped', 'message' => 'No matching columns for ' . $user_no];
    }

    $ok = mysqli_query($conn, "INSERT INTO employees (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")");
    return ['status' => $ok ? 'Inserted' : 'Error', 'message' => $ok ? $user_no : mysqli_error($conn)];
}

function save_salary_record($conn, $salary_columns, $row) {
    if (empty($salary_columns) || empty($row['user_no'])) {
        return;
    }

    $salary_month = date('Y-m');
    $user_no = trim((string)$row['user_no']);
    $food_company = money_value($row['food_allowance_company'] ?? 0);
    $food_won = money_value($row['food_allowance_won'] ?? 0);
    $earned = money_value($row['basic_salary'] ?? 0)
        + money_value($row['allowance'] ?? 0)
        + money_value($row['att_allowance'] ?? 0)
        + money_value($row['ot'] ?? 0)
        + $food_won;
    $deduction = $food_company
        + money_value($row['insurance_amount'] ?? 0)
        + money_value($row['other_deduction'] ?? 0);

    $record = [
        'employee_id' => $row['employee_id'] ?? '',
        'user_no' => $user_no,
        'salary_month' => $salary_month,
        'basic_salary' => money_value($row['basic_salary'] ?? 0),
        'allowance' => money_value($row['allowance'] ?? 0),
        'att_allowance' => money_value($row['att_allowance'] ?? 0),
        'ot' => money_value($row['ot'] ?? 0),
        'food_allowance' => $food_company + $food_won,
        'food_allowance_company' => $food_company,
        'food_allowance_won' => $food_won,
        'advance_amount' => 0,
        'insurance_amount' => money_value($row['insurance_amount'] ?? 0),
        'other_deduction' => money_value($row['other_deduction'] ?? 0),
        'salary_by' => $row['salary_by'] ?? '',
        'total_earned' => $earned,
        'total_deduction' => $deduction,
        'net_salary' => $earned - $deduction
    ];

    $safe_user_no = esc($conn, $user_no);
    $safe_month = esc($conn, $salary_month);
    $check = mysqli_query($conn, "
        SELECT id FROM employee_salary_records
        WHERE user_no='$safe_user_no' AND salary_month='$safe_month'
        LIMIT 1
    ");

    if ($check && mysqli_num_rows($check) > 0) {
        $sets = [];
        foreach ($record as $field => $value) {
            if ($field !== 'user_no' && $field !== 'salary_month') {
                sql_set($conn, $salary_columns, $field, $value, $sets);
            }
        }
        if (!empty($sets)) {
            mysqli_query($conn, "
                UPDATE employee_salary_records SET " . implode(',', $sets) . "
                WHERE user_no='$safe_user_no' AND salary_month='$safe_month'
            ");
        }
        return;
    }

    $fields = [];
    $values = [];
    foreach ($record as $field => $value) {
        sql_insert($conn, $salary_columns, $field, $value, $fields, $values);
    }
    if (!empty($fields)) {
        mysqli_query($conn, "INSERT INTO employee_salary_records (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")");
    }
}

$required_employee_columns = [
    'gender' => "VARCHAR(30) DEFAULT ''",
    'employee_status' => "VARCHAR(30) DEFAULT 'Active'",
    'bio_met_no' => "VARCHAR(100) DEFAULT ''",
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
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won' => "DECIMAL(10,2) DEFAULT 0",
    'insurance_amount' => "DECIMAL(10,2) DEFAULT 0",
    'other_deduction' => "DECIMAL(10,2) DEFAULT 0",
];

foreach ($required_employee_columns as $column => $definition) {
    add_missing_column($conn, 'employees', $column, $definition);
}

$employee_columns = table_columns($conn, 'employees');
$salary_columns = table_columns($conn, 'employee_salary_records');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['employee_file'])) {
    try {
        if ($_FILES['employee_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed.');
        }

        $file_name = $_FILES['employee_file']['name'];
        $tmp_path = $_FILES['employee_file']['tmp_name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            $rows = read_xlsx_file($tmp_path);
        } elseif ($ext === 'csv') {
            $rows = read_csv_file($tmp_path);
        } else {
            throw new Exception('Please upload .xlsx or .csv file.');
        }

        if (count($rows) < 4) {
            throw new Exception('No employee data found. Keep headers in row 1 and data from row 4.');
        }

        $header_row = $rows[0];
        $headers = [];
        foreach ($header_row as $index => $header) {
            $headers[$index] = normalize_header($header);
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        for ($i = 3; $i < count($rows); $i++) {
            $excel_row = $rows[$i];
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = trim((string)($excel_row[$index] ?? ''));
                }
            }
            apply_employee_header_aliases($row);

            if (implode('', $row) === '') {
                continue;
            }

            $result = save_employee($conn, $employee_columns, $row);
            if ($result['status'] === 'Inserted') {
                $inserted++;
            } elseif ($result['status'] === 'Updated') {
                $updated++;
            } elseif ($result['status'] === 'Error') {
                $errors++;
            } else {
                $skipped++;
            }
            $results[] = $result;
        }

        $message = "<div class='message success'>Import complete. Inserted: $inserted, Updated: $updated, Skipped: $skipped, Errors: $errors</div>";
    } catch (Exception $e) {
        $message = "<div class='message error'>" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Import Employees</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    padding: 30px;
    color: #111;
}
.btn,
button {
    display: inline-block;
    background: #3498db;
    color: #fff;
    padding: 10px 18px;
    text-decoration: none;
    border: 0;
    border-radius: 5px;
    cursor: pointer;
}
.btn-dark,
button {
    background: #2c3e50;
}
.box {
    background: #fff;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 0 10px rgba(0,0,0,.10);
    max-width: 900px;
}
.message {
    padding: 12px 15px;
    border-radius: 6px;
    margin: 15px 0;
    font-weight: bold;
}
.success { color: #176b35; background: #e9f8ee; }
.error { color: #b00020; background: #fdecec; }
input[type=file] {
    display: block;
    width: 100%;
    padding: 10px;
    border: 1px solid #aaa;
    box-sizing: border-box;
    margin: 10px 0 15px;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    background: #fff;
}
th, td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
    font-size: 13px;
}
th {
    background: #2c3e50;
    color: white;
}
ul {
    line-height: 1.7;
}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<a href="dashboard.php" class="btn">Dashboard</a>
<a href="add_employee.php" class="btn">Add Employee</a>
<a href="employee_list.php" class="btn">Employee List</a>
<a href="employee_import_template.php" class="btn">Download Export / Import Template</a>

<h2>Import Employees</h2>

<?php echo $message; ?>

<div class="box">
    <form method="POST" enctype="multipart/form-data">
        <label><b>Upload employee Excel / CSV file</b></label>
        <input type="file" name="employee_file" accept=".xlsx,.csv" required>
        <button type="submit">Import Employees</button>
    </form>

    <h3>Important</h3>
    <ul>
        <li>First download the export / import template. It includes current Employee Overview data.</li>
        <li>Do not change row 1 column names.</li>
        <li>Employee data must start from row 4.</li>
        <li>If user_no already exists, employee details will be updated.</li>
        <li>If user_no is new, employee will be inserted.</li>
        <li>Advance salary is not imported here. Use the Advance Salary page for advance payments.</li>
    </ul>
</div>

<?php if (!empty($results)) { ?>
<table>
    <tr>
        <th>Status</th>
        <th>Details</th>
    </tr>
    <?php foreach ($results as $row) { ?>
    <tr>
        <td><?php echo htmlspecialchars($row['status']); ?></td>
        <td><?php echo htmlspecialchars($row['message']); ?></td>
    </tr>
    <?php } ?>
</table>
<?php } ?>

</body>
</html>
