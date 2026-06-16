<?php
include 'auth.php';
requirePermission('employee_add');

$message = "";
$results = [];

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

function normalize_header($header) {
    $header = trim((string)$header);
    $header = strtolower($header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim($header, '_');
}

function money_value($value) {
    $value = trim((string)$value);
    $value = str_replace([',', 'AED', 'aed'], '', $value);
    return $value === '' ? 0 : (float)$value;
}

function normalize_month($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^\d{4}-\d{2}$/', $value)) return $value;
    if (preg_match('/^(\d{1,2})[-\/](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d', (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d', (int)$m[3], (int)$m[2]);
    }
    $time = strtotime($value . '-01');
    if (!$time) $time = strtotime($value);
    return $time ? date('Y-m', $time) : '';
}

function read_csv_file($path) {
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) return $rows;
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function column_letters_to_index($letters) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($letters));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
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
        if (!isset($rows[$row_index])) $rows[$row_index] = [];

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

function get_row_value($row, $header_map, $keys, $default = '') {
    foreach ($keys as $key) {
        $key = normalize_header($key);
        if (isset($header_map[$key])) {
            $idx = $header_map[$key];
            if (isset($row[$idx]) && trim((string)$row[$idx]) !== '') {
                return trim((string)$row[$idx]);
            }
        }
    }
    return $default;
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

$employee_required = [
    'salary_by' => "VARCHAR(20) DEFAULT ''",
    'basic_salary' => "DECIMAL(10,2) DEFAULT 0",
    'allowance' => "DECIMAL(10,2) DEFAULT 0",
    'att_allowance' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance' => "DECIMAL(10,2) DEFAULT 0",
    'insurance_amount' => "DECIMAL(10,2) DEFAULT 0",
    'other_deduction' => "DECIMAL(10,2) DEFAULT 0",
    'gross_salary' => "DECIMAL(10,2) DEFAULT 0",
    'total_deduction' => "DECIMAL(10,2) DEFAULT 0",
    'net_salary' => "DECIMAL(10,2) DEFAULT 0",
];

foreach ($employee_required as $column => $definition) {
    add_missing_column($conn, 'employees', $column, $definition);
}

$salary_required = [
    'employee_id' => "VARCHAR(100) DEFAULT ''",
    'salary_month' => "VARCHAR(7) DEFAULT ''",
    'salary_by' => "VARCHAR(20) DEFAULT ''",
    'basic_salary' => "DECIMAL(10,2) DEFAULT 0",
    'allowance' => "DECIMAL(10,2) DEFAULT 0",
    'att_allowance' => "DECIMAL(10,2) DEFAULT 0",
    'ot' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_company' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance_won' => "DECIMAL(10,2) DEFAULT 0",
    'food_allowance' => "DECIMAL(10,2) DEFAULT 0",
    'advance_amount' => "DECIMAL(10,2) DEFAULT 0",
    'insurance_amount' => "DECIMAL(10,2) DEFAULT 0",
    'other_deduction' => "DECIMAL(10,2) DEFAULT 0",
    'gross_salary' => "DECIMAL(10,2) DEFAULT 0",
    'total_deduction' => "DECIMAL(10,2) DEFAULT 0",
    'net_salary' => "DECIMAL(10,2) DEFAULT 0",
];

foreach ($salary_required as $column => $definition) {
    add_missing_column($conn, 'employee_salary_records', $column, $definition);
}

$employee_columns = table_columns($conn, 'employees');
$salary_columns = table_columns($conn, 'employee_salary_records');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['salary_file'])) {
    try {
        if ($_FILES['salary_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed.');
        }

        $file_name = $_FILES['salary_file']['name'] ?? '';
        $tmp_path = $_FILES['salary_file']['tmp_name'] ?? '';
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            $rows = read_xlsx_file($tmp_path);
        } elseif ($ext === 'csv') {
            $rows = read_csv_file($tmp_path);
        } else {
            throw new Exception('Please upload .xlsx or .csv file.');
        }

        if (count($rows) < 2) {
            throw new Exception('No data found in the file.');
        }

        $headers = [];
        foreach ($rows[0] as $idx => $header) {
            $headers[normalize_header($header)] = $idx;
        }

        $updated_running = 0;
        $updated_monthly = 0;
        $skipped = 0;

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $user_no = get_row_value($row, $headers, ['user_no', 'user no', 'user no.']);

            if ($user_no === '' || stripos($user_no, 'do not') !== false || stripos($user_no, 'effective_month format') !== false) {
                continue;
            }

            $safe_user_no = esc($conn, $user_no);
            $emp_result = mysqli_query($conn, "
                SELECT id, user_no, employee_id, full_name
                FROM employees
                WHERE user_no='$safe_user_no'
                LIMIT 1
            ");

            if (!$emp_result || mysqli_num_rows($emp_result) == 0) {
                $skipped++;
                $results[] = "Skipped User No $user_no - employee not found.";
                continue;
            }

            $employee = mysqli_fetch_assoc($emp_result);
            $employee_id = get_row_value($row, $headers, ['employee_id', 'id_on_device'], $employee['employee_id'] ?? '');
            $effective_month = normalize_month(get_row_value($row, $headers, ['effective_month', 'salary_month', 'month']));

            $basic_salary = money_value(get_row_value($row, $headers, ['basic_salary', 'basic salary']));
            $allowance = money_value(get_row_value($row, $headers, ['allowance']));
            $att_allowance = money_value(get_row_value($row, $headers, ['attendance_allowance', 'att_allowance', 'attendance allowance']));
            $food_company = money_value(get_row_value($row, $headers, ['food_allowance_company', 'food allowance company']));
            $food_won = money_value(get_row_value($row, $headers, ['food_allowance_own', 'food allowance own', 'food allowance (own)', 'food_allowance_won', 'food allowance won', 'food allowance (won)', 'food_allowance_self']));
            $insurance = money_value(get_row_value($row, $headers, ['insurance', 'insurance_amount']));
            $other = money_value(get_row_value($row, $headers, ['other_deduction', 'other deduction']));
            $salary_by = get_row_value($row, $headers, ['salary_by', 'payment_by', 'salary by', 'payment by']);

            if ($salary_by !== '') {
                $salary_by = strtolower($salary_by) === 'cash' ? 'Cash' : (strtolower($salary_by) === 'bank' ? 'Bank' : $salary_by);
            }

            $food_total = $food_company + $food_won;
            $gross_salary = $basic_salary + $allowance + $att_allowance + $food_total;
            $total_deduction = $insurance + $other;
            $net_salary = $gross_salary - $total_deduction;

            $salary_data = [
                'employee_id' => $employee_id,
                'salary_by' => $salary_by,
                'basic_salary' => $basic_salary,
                'allowance' => $allowance,
                'att_allowance' => $att_allowance,
                'ot' => 0,
                'food_allowance_company' => $food_company,
                'food_allowance_won' => $food_won,
                'food_allowance' => $food_total,
                'advance_amount' => 0,
                'insurance_amount' => $insurance,
                'other_deduction' => $other,
                'gross_salary' => $gross_salary,
                'total_deduction' => $total_deduction,
                'net_salary' => $net_salary,
            ];

            if ($effective_month === '') {
                $sets = [];
                foreach ($salary_data as $key => $value) {
                    if ($key !== 'employee_id' && $key !== 'advance_amount') {
                        sql_set($conn, $employee_columns, $key, $value, $sets);
                    }
                }

                if (!empty($sets)) {
                    mysqli_query($conn, "UPDATE employees SET " . implode(',', $sets) . " WHERE user_no='$safe_user_no'");
                    $updated_running++;
                }
            } else {
                $safe_month = esc($conn, $effective_month);
                $check = mysqli_query($conn, "
                    SELECT id FROM employee_salary_records
                    WHERE user_no='$safe_user_no' AND salary_month='$safe_month'
                    LIMIT 1
                ");

                $record_data = array_merge([
                    'user_no' => $user_no,
                    'salary_month' => $effective_month,
                ], $salary_data);

                if ($check && mysqli_num_rows($check) > 0) {
                    $sets = [];
                    foreach ($record_data as $key => $value) {
                        if ($key !== 'user_no' && $key !== 'salary_month') {
                            sql_set($conn, $salary_columns, $key, $value, $sets);
                        }
                    }
                    if (!empty($sets)) {
                        mysqli_query($conn, "
                            UPDATE employee_salary_records
                            SET " . implode(',', $sets) . "
                            WHERE user_no='$safe_user_no' AND salary_month='$safe_month'
                        ");
                    }
                } else {
                    $fields = [];
                    $values = [];
                    foreach ($record_data as $key => $value) {
                        sql_insert($conn, $salary_columns, $key, $value, $fields, $values);
                    }
                    if (!empty($fields)) {
                        mysqli_query($conn, "
                            INSERT INTO employee_salary_records (" . implode(',', $fields) . ")
                            VALUES (" . implode(',', $values) . ")
                        ");
                    }
                }
                $updated_monthly++;
            }
        }

        $message = "<div class='message success'>Import complete. Running salary updated: $updated_running. Month-wise salary updated: $updated_monthly. Skipped: $skipped.</div>";
    } catch (Exception $e) {
        $message = "<div class='message error'>" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Salary Details</title>
    <style>
        body{font-family:Arial, sans-serif;background:#f4f6f9;margin:0;padding:28px;color:#0f172a;}
        .btn{display:inline-block;background:#2c3e50;color:white;padding:11px 18px;border-radius:6px;text-decoration:none;border:0;cursor:pointer;margin-right:8px;}
        .btn-blue{background:#3498db;}
        .wrap{background:white;border-radius:10px;padding:22px;box-shadow:0 4px 14px rgba(15,23,42,.08);max-width:900px;}
        input[type=file]{padding:10px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;width:420px;max-width:100%;}
        .message{padding:14px;border-radius:8px;margin:16px 0;font-weight:bold;}
        .success{background:#dcfce7;color:#166534;border:1px solid #86efac;}
        .error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
        .note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:13px;border-radius:8px;line-height:1.7;}
        ul{line-height:1.8;}
    </style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
    <a href="dashboard.php" class="btn">Dashboard</a>
    <a href="salary_details_export.php" class="btn btn-blue">Download Salary Excel/CSV Template</a>
    <a href="add_employee.php" class="btn">Add / Edit Employee</a>

    <h2>Import Salary Details</h2>
    <div class="wrap">
        <?php echo $message; ?>
        <div class="note">
            First download template, fill salary values, then upload here. Keep <b>effective_month</b> blank for running salary.
            If salary changes from a specific month, enter month as <b>yyyy-mm</b>, example <b>2026-06</b>.
            Advance is not imported here because Advance Salary has separate page.
        </div>

        <form method="POST" enctype="multipart/form-data" style="margin-top:18px;">
            <label><b>Upload salary details file (.xlsx or .csv)</b></label><br><br>
            <input type="file" name="salary_file" accept=".xlsx,.csv" required>
            <button type="submit" class="btn btn-blue">Import Salary Details</button>
        </form>

        <?php if (!empty($results)) { ?>
            <h3>Import Notes</h3>
            <ul>
                <?php foreach ($results as $row) { ?>
                    <li><?php echo htmlspecialchars($row); ?></li>
                <?php } ?>
            </ul>
        <?php } ?>
    </div>
</body>
</html>
