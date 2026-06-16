<?php
include 'auth.php';
include_once 'advance_helper.php';
requirePermission('salary_view');

if ((isset($_GET['generate']) || isset($_GET['generate_selected']) || isset($_GET['sunday_ot'])) && !hasPermission('salary_generate')) {
    requirePermission('salary_generate');
}

$month = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$search_user_no = trim($_GET['search_user_no'] ?? '');
$payment_filter = trim($_GET['payment_filter'] ?? '');
$food_filter = trim($_GET['food_filter'] ?? '');
$selected_users = $_GET['selected_users'] ?? [];
if (!is_array($selected_users)) {
    $selected_users = [$selected_users];
}
$selected_users = array_values(array_filter(array_map('trim', $selected_users), function($value) {
    return $value !== '';
}));
$use_sunday_ot = isset($_GET['sunday_ot']) && $_GET['sunday_ot'] === '1';
$is_excel_export = isset($_GET['export']) && $_GET['export'] === 'excel';
$is_search = isset($_GET['search']);
$is_selected_generate = isset($_GET['generate']) || isset($_GET['generate_selected']) || isset($_GET['sunday_ot']);
$is_generate_request = isset($_GET['generate']) || $is_selected_generate;
$show_salary_sheet = $is_search || $is_generate_request || $is_excel_export;
if ($is_generate_request || $is_excel_export) {
    @set_time_limit(300);
}
$month_title = date("F Y", strtotime($month . "-01"));
$month_days = (int)date('t', strtotime($month . "-01"));
$total_net_salary = 0;
$cash_payment_total = 0;
$bank_payment_total = 0;
payroll_ensure_advance_schema($conn);

function money($amount) {
    return number_format((float)$amount, 0);
}

function hours_minutes($hours) {
    $total_minutes = (int)round(((float)$hours) * 60);
    $hh = intdiv($total_minutes, 60);
    $mm = $total_minutes % 60;
    return sprintf('%02d:%02d', $hh, $mm);
}

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, $value);
}

function month_range($month) {
    $start = date('Y-m-01', strtotime($month . '-01'));
    $end = date('Y-m-t', strtotime($month . '-01'));
    return [$start, $end];
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

function pick($row, $keys, $default = 0) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function add_field($conn, $columns, $name, $value, &$fields, &$values) {
    if (has_col($columns, $name)) {
        $fields[] = "`$name`";
        $values[] = "'" . esc($conn, $value) . "'";
    }
}

function add_set($conn, $columns, $name, $value, &$sets) {
    if (has_col($columns, $name)) {
        $sets[] = "`$name`='" . esc($conn, $value) . "'";
    }
}

function ensure_index($conn, $table, $index, $columns) {
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE '" . esc($conn, $table) . "'");
    if (!$table_check || mysqli_num_rows($table_check) == 0) {
        return;
    }

    $table_columns = table_columns($conn, $table);
    foreach ($columns as $column) {
        if (!isset($table_columns[$column])) {
            return;
        }
    }

    $safe_index = esc($conn, $index);
    $index_check = mysqli_query($conn, "SHOW INDEX FROM `$table` WHERE Key_name='$safe_index'");
    if ($index_check && mysqli_num_rows($index_check) > 0) {
        return;
    }

    $column_sql = implode('`, `', $columns);
    mysqli_query($conn, "ALTER TABLE `$table` ADD INDEX `$index` (`$column_sql`)");
}

function monthly_ot_hours($conn, $user_no, $employee_id, $month) {
    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);
    $safe_employee_id = esc($conn, $employee_id);
    $condition = "user_no='$safe_user_no'";
    if ($employee_id !== '') {
        $condition = "(user_no='$safe_user_no' OR employee_id='$safe_employee_id')";
    }

    $result = mysqli_query($conn, "
        SELECT attendance_date, check_in, check_out
        FROM attendance
        WHERE $condition
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
        AND check_in IS NOT NULL
        AND TRIM(check_in) != ''
        AND check_out IS NOT NULL
        AND TRIM(check_out) != ''
    ");

    $extra_ot_hours = 0;
    $normal_extra_base = time_to_seconds('18:20:00');
    $friday_extra_base = time_to_seconds('18:45:00');
    $time_grace_seconds = 120;

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $date = $row['attendance_date'] ?? '';
            if ($date === '') {
                continue;
            }

            $day_name = date('l', strtotime($date));
            if ($day_name === 'Sunday') {
                continue;
            }

            $out_seconds = time_to_seconds($row['check_out'] ?? '');
            if ($day_name === 'Friday') {
                if ($out_seconds >= (time_to_seconds('19:45:00') - $time_grace_seconds)) {
                    $extra_ot_hours += floor((($out_seconds + $time_grace_seconds) - $friday_extra_base) / 3600);
                }
            } elseif ($out_seconds >= (time_to_seconds('19:20:00') - $time_grace_seconds)) {
                $extra_ot_hours += floor((($out_seconds + $time_grace_seconds) - $normal_extra_base) / 3600);
            }
        }
    }

    return (float)$extra_ot_hours;
}

function monthly_uploaded_extra_ot_hours($conn, $user_no, $employee_id, $month) {
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'overtime_records'");
    if (!$table_check || mysqli_num_rows($table_check) == 0) {
        return 0;
    }

    $hours_check = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'ot_hours'");
    $date_check = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'attendance_date'");
    if (!$hours_check || mysqli_num_rows($hours_check) == 0 || !$date_check || mysqli_num_rows($date_check) == 0) {
        return 0;
    }

    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);
    $condition = "user_no='$safe_user_no'";

    $employee_id_check = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'employee_id'");
    if ($employee_id_check && mysqli_num_rows($employee_id_check) > 0 && $employee_id !== '') {
        $safe_employee_id = esc($conn, $employee_id);
        $condition = "(user_no='$safe_user_no' OR employee_id='$safe_employee_id')";
    }

    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(ot_hours),0) AS total
        FROM overtime_records
        WHERE $condition
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
    "));

    return (float)($row['total'] ?? 0);
}

function monthly_sunday_ot_hours($conn, $user_no, $employee_id, $month) {
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'overtime_records'");
    if (!$table_check || mysqli_num_rows($table_check) == 0) {
        return 0;
    }

    $hours_check = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'ot_hours'");
    $date_check = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'attendance_date'");
    if (!$hours_check || mysqli_num_rows($hours_check) == 0 || !$date_check || mysqli_num_rows($date_check) == 0) {
        return 0;
    }

    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);
    $condition = "user_no='$safe_user_no'";

    $employee_id_check = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'employee_id'");
    if ($employee_id_check && mysqli_num_rows($employee_id_check) > 0 && $employee_id !== '') {
        $safe_employee_id = esc($conn, $employee_id);
        $condition = "(user_no='$safe_user_no' OR employee_id='$safe_employee_id')";
    }

    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(ot_hours),0) AS total
        FROM overtime_records
        WHERE $condition
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
        AND DAYNAME(attendance_date)='Sunday'
    "));

    return (float)($row['total'] ?? 0);
}

function time_to_seconds($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '00:00:00') {
        return 0;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        return 0;
    }
    $hours = (int)$m[1];
    $minutes = (int)$m[2];
    $seconds = isset($m[3]) ? (int)$m[3] : 0;
    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

function shortage_seconds_to_round_hours($seconds) {
    $seconds = max(0, (int)round($seconds));
    $hours = intdiv($seconds, 3600);
    $remaining = $seconds % 3600;
    return $hours + ($remaining > 1800 ? 1 : 0);
}

function monthly_regular_ot_hours($conn, $user_no, $employee_id, $month) {
    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);
    $safe_employee_id = esc($conn, $employee_id);
    $condition = "user_no='$safe_user_no'";
    if ($employee_id !== '') {
        $condition = "(user_no='$safe_user_no' OR employee_id='$safe_employee_id')";
    }

    $result = mysqli_query($conn, "
        SELECT attendance_date, check_in, check_out
        FROM attendance
        WHERE $condition
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
        AND check_in IS NOT NULL
        AND TRIM(check_in) != ''
        AND check_out IS NOT NULL
        AND TRIM(check_out) != ''
    ");

    $regular_ot_hours = 0;
    $regular_ot_deduct_hours = 0;
    $normal_duty_end = time_to_seconds('15:50:00');
    $friday_duty_end = time_to_seconds('16:30:00');
    $normal_regular_base = time_to_seconds('16:05:00');
    $friday_regular_base = time_to_seconds('16:45:00');
    $time_grace_seconds = 120;

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $date = $row['attendance_date'] ?? '';
            if ($date === '' || date('l', strtotime($date)) === 'Sunday') {
                continue;
            }
            $out_seconds = time_to_seconds($row['check_out'] ?? '');
            if (date('l', strtotime($date)) === 'Friday') {
                if ($out_seconds > 0 && $out_seconds < ($friday_duty_end - $time_grace_seconds)) {
                    $short_seconds = max(0, ($friday_duty_end - $out_seconds) - $time_grace_seconds);
                    $regular_ot_deduct_hours += shortage_seconds_to_round_hours($short_seconds);
                }
                $hours = floor((($out_seconds + $time_grace_seconds) - $friday_regular_base) / 3600);
                $regular_ot_hours += max(0, min(2, $hours));
            } else {
                if ($out_seconds > 0 && $out_seconds < ($normal_duty_end - $time_grace_seconds)) {
                    $short_seconds = max(0, ($normal_duty_end - $out_seconds) - $time_grace_seconds);
                    $regular_ot_deduct_hours += shortage_seconds_to_round_hours($short_seconds);
                }
                $hours = floor((($out_seconds + $time_grace_seconds) - $normal_regular_base) / 3600);
                $regular_ot_hours += max(0, min(2, $hours));
            }
        }
    }

    return (float)max(0, $regular_ot_hours - $regular_ot_deduct_hours);
}

function monthly_short_duty_days($conn, $user_no, $employee_id, $month) {
    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);
    $safe_employee_id = esc($conn, $employee_id);
    $condition = "user_no='$safe_user_no'";
    if ($employee_id !== '') {
        $condition = "(user_no='$safe_user_no' OR employee_id='$safe_employee_id')";
    }

    $result = mysqli_query($conn, "
        SELECT attendance_date, check_in, check_out
        FROM attendance
        WHERE $condition
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
        AND attendance_date NOT IN (SELECT holiday_date FROM holidays)
        AND check_in IS NOT NULL
        AND TRIM(check_in) != ''
        AND check_out IS NOT NULL
        AND TRIM(check_out) != ''
    ");

    $short_days = 0;
    $normal_duty_end = time_to_seconds('15:50:00');
    $friday_duty_end = time_to_seconds('16:30:00');
    $time_grace_seconds = 120;

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $date = $row['attendance_date'] ?? '';
            if ($date === '' || date('l', strtotime($date)) === 'Sunday') {
                continue;
            }

            $out_seconds = time_to_seconds($row['check_out'] ?? '');
            if ($out_seconds <= 0) {
                continue;
            }

            $duty_end = date('l', strtotime($date)) === 'Friday' ? $friday_duty_end : $normal_duty_end;
            if ($out_seconds < ($duty_end - $time_grace_seconds)) {
                $short_days++;
            }
        }
    }

    return $short_days;
}

function monthly_working_days($conn, $user_no, $month) {
    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);

    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT
        SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations l
                    WHERE l.user_no = attendance.user_no
                    AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
                ) THEN 0
                WHEN check_in IS NOT NULL AND TRIM(check_in) != '' THEN 1
                WHEN DAYNAME(attendance_date)='Sunday' THEN 1
                WHEN attendance_date IN (SELECT holiday_date FROM holidays) THEN 1
                ELSE 0
            END
        ) AS working_days
        FROM attendance
        WHERE user_no='$safe_user_no'
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
    "));

    return (float)($row['working_days'] ?? 0);
}

function monthly_late_seconds($conn, $user_no, $month) {
    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);

    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations l
                    WHERE l.user_no = attendance.user_no
                    AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
                ) THEN 0
                WHEN late_time IS NOT NULL
                AND TRIM(late_time) != ''
                AND TRIM(late_time) != '00:00'
                AND TRIM(late_time) != '00:00:00'
                THEN TIME_TO_SEC(late_time)
                WHEN check_in IS NOT NULL
                AND TRIM(check_in) != ''
                AND TIME_TO_SEC(check_in) > TIME_TO_SEC('07:06:00')
                THEN TIME_TO_SEC(check_in) - TIME_TO_SEC('07:00:00')
                ELSE 0
            END
        ), 0) AS late_seconds
        FROM attendance
        WHERE user_no='$safe_user_no'
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
    "));

    return (int)($row['late_seconds'] ?? 0);
}

function monthly_absent_days($conn, $user_no, $month) {
    $safe_user_no = esc($conn, $user_no);
    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);

    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations v
                    WHERE v.user_no = attendance.user_no
                    AND attendance.attendance_date BETWEEN v.from_date AND v.to_date
                ) THEN 0
                WHEN DAYNAME(attendance_date)='Sunday' THEN 0
                WHEN attendance_date IN (SELECT holiday_date FROM holidays) THEN 0
                WHEN check_in IS NULL OR TRIM(check_in) = '' THEN 1
                ELSE 0
            END
        ), 0) AS absent_days
        FROM attendance
        WHERE user_no='$safe_user_no'
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
    "));

    return (float)($row['absent_days'] ?? 0);
}

function add_missing_column($conn, $table, $column, $definition) {
    $safe_column = esc($conn, $column);
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$safe_column'");
    if ($check && mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD `$column` $definition");
    }
}

$employee_columns = table_columns($conn, 'employees');
$salary_columns = table_columns($conn, 'employee_salary_records');

add_missing_column($conn, 'employee_salary_records', 'ot_amount', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'salary_earned', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'allowance_earned', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'total_salary', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'gross_total', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'net_payable', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'salary_by', "VARCHAR(20) DEFAULT ''");
add_missing_column($conn, 'employee_salary_records', 'salary_status', "VARCHAR(20) DEFAULT 'Unpaid'");
add_missing_column($conn, 'employee_salary_records', 'food_allowance_company', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'food_allowance_won', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'regular_ot_hours', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'regular_ot_amount', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'after6pm_ot_amount', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'extra_ot_hours', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'extra_ot_amount', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'total_late_hours', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'late_amount', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'advance_id', "INT DEFAULT NULL");
add_missing_column($conn, 'employee_salary_records', 'advance_balance_after', "DECIMAL(12,2) DEFAULT 0");
add_missing_column($conn, 'employee_salary_records', 'fixed_salary', "DECIMAL(10,2) DEFAULT 0");
add_missing_column($conn, 'employees', 'fixed_salary', "DECIMAL(10,2) DEFAULT 0");

ensure_index($conn, 'attendance', 'idx_att_user_date', ['user_no', 'attendance_date']);
ensure_index($conn, 'attendance', 'idx_att_employee_date', ['employee_id', 'attendance_date']);
ensure_index($conn, 'overtime_records', 'idx_ot_user_date', ['user_no', 'attendance_date']);
ensure_index($conn, 'overtime_records', 'idx_ot_employee_date', ['employee_id', 'attendance_date']);
ensure_index($conn, 'vacations', 'idx_vac_user_dates', ['user_no', 'from_date', 'to_date']);
ensure_index($conn, 'holidays', 'idx_holiday_date', ['holiday_date']);

$salary_columns = table_columns($conn, 'employee_salary_records');
$employee_columns = table_columns($conn, 'employees');

$active_condition = "1=1";
$active_condition_join = "1=1";
$safe_status_month = esc($conn, $month);
if (has_col($employee_columns, 'employee_status')) {
    $active_condition = "(employee_status='Active' OR employee_status='' OR employee_status IS NULL)";
    $active_condition_join = "(e.employee_status='Active' OR e.employee_status='' OR e.employee_status IS NULL)";
    if (has_col($employee_columns, 'resign_date')) {
        $active_condition = "($active_condition OR ((employee_status='Inactive' OR employee_status='Resigned') AND resign_date IS NOT NULL AND resign_date!='' AND DATE_FORMAT(resign_date, '%Y-%m') >= '$safe_status_month'))";
        $active_condition_join = "($active_condition_join OR ((e.employee_status='Inactive' OR e.employee_status='Resigned') AND e.resign_date IS NOT NULL AND e.resign_date!='' AND DATE_FORMAT(e.resign_date, '%Y-%m') >= '$safe_status_month'))";
    }
} elseif (has_col($employee_columns, 'status')) {
    $active_condition = "(status='Active' OR status='' OR status IS NULL)";
    $active_condition_join = "(e.status='Active' OR e.status='' OR e.status IS NULL)";
    if (has_col($employee_columns, 'resign_date')) {
        $active_condition = "($active_condition OR ((status='Inactive' OR status='Resigned') AND resign_date IS NOT NULL AND resign_date!='' AND DATE_FORMAT(resign_date, '%Y-%m') >= '$safe_status_month'))";
        $active_condition_join = "($active_condition_join OR ((e.status='Inactive' OR e.status='Resigned') AND e.resign_date IS NOT NULL AND e.resign_date!='' AND DATE_FORMAT(e.resign_date, '%Y-%m') >= '$safe_status_month'))";
    }
}

if ($is_generate_request) {
    $employees_q = mysqli_query($conn, "
        SELECT * FROM employees
        WHERE $active_condition
        ORDER BY CAST(user_no AS UNSIGNED) ASC
    ");
} else {
    $employees_q = false;
}

if ($is_generate_request && $employees_q) {
    while ($employee = mysqli_fetch_assoc($employees_q)) {
        $user_no = $employee['user_no'] ?? '';
        if ($user_no === '') {
            continue;
        }
        if ($is_selected_generate && (empty($selected_users) || !in_array((string)$user_no, $selected_users, true))) {
            continue;
        }

        $safe_user_no = esc($conn, $user_no);
        $safe_month = esc($conn, $month);
        $salary_setup = [];
        $salary_setup_q = mysqli_query($conn, "
            SELECT *
            FROM employee_salary_records
            WHERE user_no='$safe_user_no'
            AND salary_month <= '$safe_month'
            ORDER BY salary_month DESC
            LIMIT 1
        ");
        if ($salary_setup_q && mysqli_num_rows($salary_setup_q) > 0) {
            $salary_setup = mysqli_fetch_assoc($salary_setup_q);
        }
        $salary_source = array_merge($employee, $salary_setup);

        $basic_salary = (float)pick($salary_source, ['basic_salary'], 0);
        $allowance = (float)pick($salary_source, ['allowance'], 0);
        $employee_att_allowance = (float)pick($employee, ['att_allowance', 'attendance_allowance'], 0);
        $setup_att_allowance = (float)pick($salary_setup, ['att_allowance', 'attendance_allowance'], 0);
        $att_allowance = $employee_att_allowance > 0 ? $employee_att_allowance : $setup_att_allowance;
        $employee_id_value = $employee['employee_id'] ?? ($employee['id'] ?? '');
        $working_days_for_generate = monthly_working_days($conn, $user_no, $month);
        $late_seconds_for_generate = monthly_late_seconds($conn, $user_no, $month);
        $absent_days_for_generate = monthly_absent_days($conn, $user_no, $month);
        $short_duty_days_for_generate = monthly_short_duty_days($conn, $user_no, $employee_id_value, $month);
        if ($late_seconds_for_generate > 0 || $absent_days_for_generate > 0 || $short_duty_days_for_generate > 0) {
            $att_allowance = 0;
        }

        $regular_ot_hours = monthly_regular_ot_hours($conn, $user_no, $employee_id_value, $month);
        $attendance_after6pm_ot = monthly_ot_hours($conn, $user_no, $employee_id_value, $month);
        $uploaded_ot_total = monthly_uploaded_extra_ot_hours($conn, $user_no, $employee_id_value, $month);
        $sunday_ot_hours = monthly_sunday_ot_hours($conn, $user_no, $employee_id_value, $month);
        $nonsunday_uploaded_ot = max(0, $uploaded_ot_total - $sunday_ot_hours);
        // After 6pm column = attendance after-6pm OT + non-Sunday uploaded OT (overtime_report)
        $ot = $attendance_after6pm_ot + $nonsunday_uploaded_ot;
        // Sunday OT column = Sunday uploaded OT only (overtime_report), paid at 1.5x
        $extra_ot_hours = $sunday_ot_hours;
        $food_allowance_company = (float)pick($salary_source, ['food_allowance_company', 'food_allowance_(company)'], 0);
        $food_allowance_won = (float)pick($salary_source, ['food_allowance_won', 'food_allowance_(won)', 'food_allowance_own', 'food_allowance_(own)'], 0);
        if ($food_allowance_company == 0 && $food_allowance_won == 0) {
            $food_allowance_company = (float)pick($salary_source, ['food_allowance'], 0);
        }
        $gross_food_allowance = $food_allowance_company + $food_allowance_won;
        $net_food_adjustment = -$food_allowance_company;
        $advance_info = payroll_calculate_advance_deduction($conn, $user_no, $month);
        $advance_id = $advance_info['advance']['id'] ?? null;
        $advance_balance_after = (float)($advance_info['balance_after'] ?? 0);
        $advance = (float)($advance_info['amount'] ?? 0);
        $insurance = (float)pick($salary_source, ['insurance_amount'], 0);
        $other_deduction = (float)pick($salary_source, ['other_deduction'], 0);
        $salary_by = pick($salary_source, ['salary_by', 'payment_by'], '');

        if ($working_days_for_generate <= 0) {
            $advance = 0;
            $advance_id = null;
            $advance_balance_after = (float)($advance_info['advance']['balance_amount'] ?? 0);
            $regular_ot_amount = 0;
            $after6pm_ot_amount = 0;
            $extra_ot_amount = 0;
            $total_late_hours = 0;
            $late_amount = 0;
            $ot_amount = 0;
            $total_earned = 0;
            $total_deduction = 0;
            $net_salary = 0;
        } else {
            $total_late_hours = round($late_seconds_for_generate / 3600, 2);
            $late_amount = ($basic_salary / 30 / 8) * 1.25 * $total_late_hours;
            $regular_ot_amount = ($basic_salary / 30 / 8) * 1.25 * $regular_ot_hours;
            $after6pm_ot_amount = ($basic_salary / 30 / 8) * 1.25 * $ot;
            $extra_ot_amount = ($basic_salary / 30 / 8) * 1.5 * $extra_ot_hours;
            $ot_amount = $regular_ot_amount + $after6pm_ot_amount + $extra_ot_amount;
            $salary_earned_for_generate = $month_days > 0 ? ($basic_salary / $month_days) * $working_days_for_generate : 0;
            $allowance_earned_for_generate = $month_days > 0 ? ($allowance / $month_days) * $working_days_for_generate : 0;
            $total_salary_for_generate = max(0, $salary_earned_for_generate + $allowance_earned_for_generate + $att_allowance + $ot_amount - $late_amount);
            $gross_total_for_generate = max(0, $total_salary_for_generate + $gross_food_allowance);
            $total_earned = $gross_total_for_generate;
            $total_deduction = $advance + $insurance + $other_deduction;
            $net_salary = $total_earned - $total_deduction + $net_food_adjustment;
        }

        $check_salary = mysqli_query($conn, "
            SELECT id FROM employee_salary_records
            WHERE user_no='$safe_user_no'
            AND salary_month='$safe_month'
            LIMIT 1
        ");

        $salary_data = [
            'employee_id' => $employee_id_value,
            'user_no' => $user_no,
            'salary_month' => $month,
            'basic_salary' => $basic_salary,
            'allowance' => $allowance,
            'att_allowance' => $att_allowance,
            'ot' => $ot,
            'regular_ot_hours' => $regular_ot_hours,
            'regular_ot_amount' => $regular_ot_amount ?? 0,
            'after6pm_ot_amount' => $after6pm_ot_amount ?? 0,
            'extra_ot_hours' => $extra_ot_hours,
            'extra_ot_amount' => $extra_ot_amount ?? 0,
            'ot_amount' => $ot_amount ?? 0,
            'total_late_hours' => $total_late_hours ?? 0,
            'late_amount' => $late_amount ?? 0,
            'food_allowance' => $gross_food_allowance,
            'food_allowance_company' => $food_allowance_company,
            'food_allowance_won' => $food_allowance_won,
            'advance_id' => $advance_id,
            'advance_amount' => $advance,
            'advance_balance_after' => $advance_balance_after,
            'insurance_amount' => $insurance,
            'other_deduction' => $other_deduction,
            'total_earned' => $total_earned,
            'total_deduction' => $total_deduction,
            'net_salary' => $net_salary,
            'salary_by' => $salary_by,
            'salary_status' => $working_days_for_generate <= 0 ? 'Unpaid' : 'Generated'
        ];

        $salary_record_id = 0;
        if ($check_salary && mysqli_num_rows($check_salary) > 0) {
            $existing_salary = mysqli_fetch_assoc($check_salary);
            $salary_record_id = (int)($existing_salary['id'] ?? 0);
            $sets = [];
            foreach ($salary_data as $key => $value) {
                if ($key !== 'user_no' && $key !== 'salary_month') {
                    add_set($conn, $salary_columns, $key, $value, $sets);
                }
            }
            if (!empty($sets)) {
                mysqli_query($conn, "
                    UPDATE employee_salary_records
                    SET " . implode(',', $sets) . "
                    WHERE user_no='$safe_user_no'
                    AND salary_month='$safe_month'
                ");
            }
        } else {
            $fields = [];
            $values = [];
            foreach ($salary_data as $key => $value) {
                add_field($conn, $salary_columns, $key, $value, $fields, $values);
            }
            if (!empty($fields)) {
                mysqli_query($conn, "
                    INSERT INTO employee_salary_records (" . implode(',', $fields) . ")
                    VALUES (" . implode(',', $values) . ")
                ");
                $salary_record_id = (int)mysqli_insert_id($conn);
            }
        }

        if ($working_days_for_generate > 0 && $advance_id && $advance > 0) {
            payroll_apply_advance_payment($conn, $advance_id, $user_no, $month, $advance, $salary_record_id);
        }
    }
}

$safe_month = esc($conn, $month);
$safe_search_user_no = esc($conn, $search_user_no);
$safe_payment_filter = esc($conn, $payment_filter);
$search_condition = "";
$employee_salary_by_select = has_col($employee_columns, 'salary_by')
    ? "e.salary_by AS employee_salary_by,"
    : (has_col($employee_columns, 'payment_by') ? "e.payment_by AS employee_salary_by," : "'' AS employee_salary_by,");
$employee_food_company_select = has_col($employee_columns, 'food_allowance_company')
    ? "e.food_allowance_company AS employee_food_allowance_company,"
    : (has_col($employee_columns, 'food_allowance') ? "e.food_allowance AS employee_food_allowance_company," : "0 AS employee_food_allowance_company,");
$employee_food_won_select = has_col($employee_columns, 'food_allowance_won')
    ? "e.food_allowance_won AS employee_food_allowance_won,"
    : "0 AS employee_food_allowance_won,";
$employee_basic_select = has_col($employee_columns, 'basic_salary') ? "e.basic_salary" : "0";
$employee_allowance_select = has_col($employee_columns, 'allowance') ? "e.allowance" : "0";
$employee_att_allowance_select = has_col($employee_columns, 'att_allowance') ? "e.att_allowance" : (has_col($employee_columns, 'attendance_allowance') ? "e.attendance_allowance" : "0");
$employee_fixed_salary_select = has_col($employee_columns, 'fixed_salary') ? "e.fixed_salary" : "0";
$employee_advance_select = "0";
$employee_insurance_select = has_col($employee_columns, 'insurance_amount') ? "e.insurance_amount" : "0";
$employee_other_deduction_select = has_col($employee_columns, 'other_deduction') ? "e.other_deduction" : "0";
$employee_payment_select = has_col($employee_columns, 'salary_by') ? "e.salary_by" : (has_col($employee_columns, 'payment_by') ? "e.payment_by" : "''");
$employee_food_company_expr = has_col($employee_columns, 'food_allowance_company')
    ? "e.food_allowance_company"
    : (has_col($employee_columns, 'food_allowance') ? "e.food_allowance" : "0");
$employee_food_won_expr = has_col($employee_columns, 'food_allowance_won') ? "e.food_allowance_won" : "0";

if ($search_user_no !== "") {
    $search_condition = "
        AND (
            e.user_no='$safe_search_user_no'
            OR e.employee_id='$safe_search_user_no'
            OR e.full_name LIKE '%$safe_search_user_no%'
        )
    ";
}

if ($payment_filter === 'Cash' || $payment_filter === 'Bank') {
    $search_condition .= "
        AND COALESCE(NULLIF(s.salary_by,''), NULLIF(ss.salary_by,''), $employee_payment_select) = '$safe_payment_filter'
    ";
}

if ($food_filter === 'Company') {
    $search_condition .= "
        AND COALESCE(NULLIF(s.food_allowance_company,0), NULLIF(ss.food_allowance_company,0), $employee_food_company_expr, 0) > 0
    ";
} elseif ($food_filter === 'Own' || $food_filter === 'Won') {
    $search_condition .= "
        AND COALESCE(NULLIF(s.food_allowance_won,0), NULLIF(ss.food_allowance_won,0), $employee_food_won_expr, 0) > 0
    ";
}

$sql = "
SELECT
    e.full_name,
    e.user_no,
    e.employee_id,
    e.designation,
    $employee_salary_by_select
    $employee_food_company_select
    $employee_food_won_select
    s.*,
    s.id AS salary_record_id,
    s.salary_status AS current_salary_status,
    e.user_no AS user_no,
    e.employee_id AS employee_id,
    COALESCE(s.basic_salary, ss.basic_salary, $employee_basic_select, 0) AS basic_salary,
    COALESCE(s.allowance, ss.allowance, $employee_allowance_select, 0) AS allowance,
    $employee_att_allowance_select AS employee_base_att_allowance,
    COALESCE(NULLIF($employee_att_allowance_select, 0), NULLIF(ss.att_allowance, 0), NULLIF(s.att_allowance, 0), 0) AS att_allowance,
    COALESCE(NULLIF(s.fixed_salary, 0), NULLIF(ss.fixed_salary, 0), NULLIF($employee_fixed_salary_select, 0), 0) AS fixed_salary,
    COALESCE(s.food_allowance_company, ss.food_allowance_company, $employee_food_company_expr, 0) AS food_allowance_company,
    COALESCE(s.food_allowance_won, ss.food_allowance_won, $employee_food_won_expr, 0) AS food_allowance_won,
    COALESCE(s.food_allowance, ss.food_allowance, $employee_food_company_expr, 0) AS food_allowance,
    COALESCE(s.advance_amount, 0) AS advance_amount,
    COALESCE(s.insurance_amount, ss.insurance_amount, $employee_insurance_select, 0) AS insurance_amount,
    COALESCE(s.other_deduction, ss.other_deduction, $employee_other_deduction_select, 0) AS other_deduction,
    COALESCE(NULLIF(s.salary_by,''), NULLIF(ss.salary_by,''), $employee_payment_select, '') AS salary_by
FROM employees e
LEFT JOIN employee_salary_records s
    ON s.user_no = e.user_no
    AND s.salary_month = '$safe_month'
LEFT JOIN employee_salary_records ss
    ON ss.user_no = e.user_no
    AND ss.salary_month = (
        SELECT MAX(ss2.salary_month)
        FROM employee_salary_records ss2
        WHERE ss2.user_no = e.user_no
        AND ss2.salary_month <= '$safe_month'
    )
WHERE $active_condition_join
$search_condition
ORDER BY CAST(e.user_no AS UNSIGNED) ASC
";

$result = $show_salary_sheet ? mysqli_query($conn, $sql) : false;

if ($is_excel_export) {
    $file_month = date('F_Y', strtotime($month . "-01"));
    $file_user = $search_user_no !== "" ? "_User_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $search_user_no) : "";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=salary_sheet_" . $file_month . $file_user . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Salary Sheet â€” <?php echo htmlspecialchars($month_title); ?></title>
<style>
/* â”€â”€ Reset & Base â”€â”€ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --brand:       #1a3a5c;
    --brand-mid:   #2563a8;
    --accent:      #e8a020;
    --accent-soft: #fef3dc;
    --green:       #16a34a;
    --green-soft:  #dcfce7;
    --red:         #dc2626;
    --red-soft:    #fee2e2;
    --gray-50:     #f8fafc;
    --gray-100:    #f1f5f9;
    --gray-200:    #e2e8f0;
    --gray-400:    #94a3b8;
    --gray-600:    #475569;
    --gray-800:    #1e293b;
    --sunday-bg:   #fff3cd;
    --absent-bg:   #c4c4c4;
    --absent-text: #fff;
    --radius:      6px;
    --shadow:      0 2px 8px rgba(0,0,0,0.10);
}

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--gray-100);
    color: var(--gray-800);
    font-size: 13px;
    min-height: 100vh;
    overflow-x: hidden;
}

/* â”€â”€ Top Bar â”€â”€ */
.topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--brand);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    height: 56px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.25);
}
.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-logo {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: #fff;
    text-decoration: none;
}
.topbar-logo span { color: var(--accent); }
.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    padding: 6px 14px;
    border-radius: var(--radius);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: background 0.15s;
}
.btn-back:hover { background: rgba(255,255,255,0.22); }

.topbar-summary {
    display: flex;
    align-items: center;
    gap: 10px;
}
.summary-pill {
    background: rgba(255,255,255,0.10);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 12px;
    white-space: nowrap;
}
.summary-pill .lbl { color: var(--gray-400); font-size: 11px; display: block; }
.summary-pill .val { color: #fff; font-weight: 700; font-size: 14px; }
.summary-pill.total { background: var(--accent); border-color: var(--accent); }
.summary-pill.total .lbl { color: rgba(0,0,0,0.55); }
.summary-pill.total .val { color: #1a1a1a; }

/* â”€â”€ Filter Panel â”€â”€ */
.filter-panel {
    background: #fff;
    border-bottom: 2px solid var(--gray-200);
    padding: 12px 20px;
}
.filter-inner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.filter-inner input[type="month"],
.filter-inner input[type="text"],
.filter-inner select {
    height: 36px;
    border: 1.5px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 0 10px;
    font-size: 13px;
    color: var(--gray-800);
    background: var(--gray-50);
    transition: border-color 0.15s;
    outline: none;
}
.filter-inner input[type="text"] { width: 240px; }
.filter-inner input:focus,
.filter-inner select:focus { border-color: var(--brand-mid); background: #fff; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 16px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: opacity 0.15s, transform 0.1s;
}
.btn:hover { opacity: 0.88; transform: translateY(-1px); }
.btn-primary   { background: var(--brand-mid); color: #fff; }
.btn-success   { background: var(--green);     color: #fff; }
.btn-warning   { background: var(--accent);    color: #1a1a1a; }
.btn-secondary { background: var(--gray-600);  color: #fff; }
.btn-outline   { background: #fff; color: var(--brand); border: 1.5px solid var(--brand-mid); }
.btn-sunday    { background: #ff8c00; color: #fff; }

/* â”€â”€ Page content wrapper â”€â”€ */
.page-content {
    display: block;
}

/* â”€â”€ Generate Actions Bar â”€â”€ */
.actions-bar {
    background: var(--brand);
    padding: 8px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.actions-bar label {
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}
.actions-bar input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
.mode-badge {
    background: #ff8c00;
    color: #fff;
    border-radius: 12px;
    padding: 3px 12px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.05em;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%,100% { opacity: 1; }
    50% { opacity: 0.75; }
}

/* â”€â”€ Table Wrapper â”€â”€
   CRITICAL: both overflow-x AND overflow-y on the SAME element
   so that position:sticky works correctly in all browsers.
   Height is set dynamically by JS to fill remaining viewport.  */
.table-wrapper {
    margin: 0;
    overflow-x: auto;
    overflow-y: auto;
    height: var(--table-h, 60vh);
    box-shadow: var(--shadow);
    -webkit-overflow-scrolling: touch;
}

/* â”€â”€ Table â”€â”€ */
table.salary-table {
    border-collapse: separate;   /* MUST be separate for sticky to work */
    border-spacing: 0;
    background: #fff;
    font-size: 12px;
    min-width: max-content;      /* prevent table from collapsing */
}

/* â”€â”€ Sticky header rows â”€â”€
   top values = cumulative height of rows above              */
table.salary-table thead tr th {
    position: sticky;
    background: var(--brand);
    color: #fff;
    font-weight: 600;
    text-align: center;
    padding: 7px 6px;
    border-right: 1px solid rgba(255,255,255,0.18);
    border-bottom: 1px solid rgba(255,255,255,0.18);
    white-space: nowrap;
    z-index: 20;
}
/* Row 1: company title  (height ~34px) */
table.salary-table thead tr:nth-child(1) th { top: 0; height: 34px; }
/* Row 2: group labels   (height ~30px) */
table.salary-table thead tr:nth-child(2) th { top: 34px; height: 30px; }
/* Row 3: column names   */
table.salary-table thead tr:nth-child(3) th {
    top: 64px;
    font-size: 11px;
    background: #1d3d60;
    border-bottom: 2px solid var(--accent);
}

/* â”€â”€ Frozen left columns (SL, UserNo, Select, Name) â”€â”€
   These stay fixed on horizontal scroll.
   left values: SL=0, UserNo=42px, Select=90px, Name=124px  */

/* Header frozen cells */
table.salary-table thead tr th:nth-child(1) { left: 0;     z-index: 30; min-width: 40px;  }
table.salary-table thead tr th:nth-child(2) { left: 40px;  z-index: 30; min-width: 62px;  }
table.salary-table thead tr th:nth-child(3) { left: 102px; z-index: 30; min-width: 34px;  }
table.salary-table thead tr th:nth-child(4) { left: 136px; z-index: 30; min-width: 150px; text-align: left; padding-left: 10px; }

/* Body frozen cells */
table.salary-table tbody tr td:nth-child(1),
table.salary-table tbody tr td:nth-child(2),
table.salary-table tbody tr td:nth-child(3),
table.salary-table tbody tr td:nth-child(4) {
    position: sticky;
    background: #fff;
    z-index: 5;
    border-right: 1px solid var(--gray-200);
}
table.salary-table tbody tr td:nth-child(1) { left: 0;     min-width: 40px;  }
table.salary-table tbody tr td:nth-child(2) { left: 40px;  min-width: 62px;  }
table.salary-table tbody tr td:nth-child(3) { left: 102px; min-width: 34px;  }
table.salary-table tbody tr td:nth-child(4) { left: 136px; min-width: 150px; text-align: left; font-weight: 600; padding-left: 10px; }

/* Frozen shadow divider after Name column */
table.salary-table tbody tr td:nth-child(4),
table.salary-table thead tr th:nth-child(4) {
    box-shadow: 3px 0 6px rgba(0,0,0,0.12);
}

/* Even rows: frozen cells also need background */
table.salary-table tbody tr:nth-child(even) td:nth-child(1),
table.salary-table tbody tr:nth-child(even) td:nth-child(2),
table.salary-table tbody tr:nth-child(even) td:nth-child(3),
table.salary-table tbody tr:nth-child(even) td:nth-child(4) { background: var(--gray-50); }

/* No-work row frozen */
tr.no-work-row td:nth-child(1),
tr.no-work-row td:nth-child(2),
tr.no-work-row td:nth-child(3),
tr.no-work-row td:nth-child(4) { background: var(--absent-bg) !important; }

/* Group header colors */
th.grp-identity  { background: #1a3a5c !important; }
th.grp-base      { background: #1e5080 !important; }
th.grp-days      { background: #1e6090 !important; }
th.grp-earnings  { background: #1a7060 !important; }
th.grp-ot        { background: #1a6e2a !important; }
th.grp-allowance { background: #9a5200 !important; }
th.grp-food      { background: #6030b0 !important; }
th.grp-deduction { background: #8b1c1c !important; }
th.grp-net       { background: #0f2a45 !important; }
th.grp-status    { background: #2a2a4a !important; }

/* Column widths */
th.col-basic  { min-width: 80px; }
th.col-amount { min-width: 76px; }
th.col-days   { min-width: 58px; }
th.col-ot     { min-width: 58px; }
th.col-net    { min-width: 84px; background: #0f2a45 !important; }

/* Body rows */
table.salary-table tbody tr td {
    padding: 6px 6px;
    text-align: center;
    border-right: 1px solid var(--gray-200);
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
    white-space: nowrap;
}
table.salary-table tbody tr:nth-child(even) { background: var(--gray-50); }
table.salary-table tbody tr:hover td { background: #e8f0fb !important; }
table.salary-table tbody tr:hover td:nth-child(1),
table.salary-table tbody tr:hover td:nth-child(2),
table.salary-table tbody tr:hover td:nth-child(3),
table.salary-table tbody tr:hover td:nth-child(4) { background: #e8f0fb !important; }

/* No-work row */
tr.no-work-row td {
    background: var(--absent-bg) !important;
    color: var(--absent-text) !important;
}
tr.no-work-row td:nth-child(4) { color: #ffd5d5 !important; }

/* Sunday OT highlight */
td.sunday-ot { background: var(--sunday-bg) !important; font-weight: 700; color: #92400e; }

/* Company food is deducted from gross salary */
td.food-company-deduction {
    background: #fde2e2 !important;
    color: #b91c1c !important;
    font-weight: 700;
}
table.salary-table tbody tr:hover td.food-company-deduction { background: #fbd5d5 !important; }

/* Net payable cell */
td.td-net {
    background: var(--accent-soft) !important;
    color: var(--brand) !important;
    font-weight: 700;
    font-size: 13px;
}
tr.no-work-row td.td-net { background: #666 !important; color: #fff !important; }

/* Status badges */
.badge {
    display: inline-block;
    border-radius: 10px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.badge-generated { background: var(--green-soft); color: var(--green); }
.badge-not       { background: var(--red-soft);   color: var(--red);   }

/* Payment badge */
.pay-badge { display: inline-block; border-radius: 4px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
.pay-cash  { background: #fef3c7; color: #92400e; }
.pay-bank  { background: #dbeafe; color: #1e40af; }

/* Total row */
tr.total-row td, tr.total-row th {
    background: var(--brand) !important;
    color: #fff !important;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 8px;
    border: 1px solid #fff;
}
tr.total-row td.td-net-total {
    background: var(--accent) !important;
    color: #1a1a1a !important;
    font-size: 15px;
}

/* â”€â”€ Empty State â”€â”€ */
.empty-state {
    background: #fff;
    border-radius: var(--radius);
    margin: 30px 20px;
    padding: 60px 20px;
    text-align: center;
    box-shadow: var(--shadow);
}
.empty-state .icon { font-size: 48px; margin-bottom: 12px; }
.empty-state h3 { color: var(--gray-600); font-size: 18px; margin-bottom: 8px; }
.empty-state p  { color: var(--gray-400); font-size: 14px; }

/* â”€â”€ Print / Excel overrides â”€â”€ */
<?php if ($is_excel_export) { ?>
.topbar, .filter-panel, .actions-bar { display: none !important; }
.table-wrapper { overflow: visible !important; height: auto !important; }
table.salary-table thead tr th,
table.salary-table tbody tr td { position: static !important; }
<?php } ?>

@media print {
    .topbar, .filter-panel, .actions-bar { display: none !important; }
    body { background: #fff; }
    .table-wrapper { overflow: visible !important; height: auto !important; }
    table.salary-table { border-collapse: collapse !important; min-width: auto; }
    table.salary-table thead tr th,
    table.salary-table tbody tr td { position: static !important; }
    td, th { font-size: 8px !important; padding: 3px !important; white-space: normal !important; }
    @page { size: A3 landscape; margin: 6mm; }
}
</style>
</head>
<body>

<!-- â•â• TOP BAR â•â• -->
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="btn-back">&#8592; Dashboard</a>
        <?php echo company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;'); ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
    <div class="topbar-summary">
        <div class="summary-pill">
            <span class="lbl">CASH</span>
            <span class="val" id="cashPaymentTop">0</span>
        </div>
        <div class="summary-pill">
            <span class="lbl">BANK</span>
            <span class="val" id="bankPaymentTop">0</span>
        </div>
        <div class="summary-pill total">
            <span class="lbl">NET PAYABLE</span>
            <span class="val" id="totalSalaryTop">0</span>
        </div>
        <button onclick="window.print()" class="btn btn-outline" style="height:36px;">&#128438; Print</button>
    </div>
</div>

<!-- â•â• FILTER PANEL + CONTENT â•â• -->
<div class="page-content">

<!-- â•â• FILTER PANEL â•â• -->
<div class="filter-panel">
    <form method="GET">
        <div class="filter-inner">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" required>
            <input type="text" name="search_user_no" placeholder="&#128269; Search User No / ID / Name" value="<?php echo htmlspecialchars($search_user_no); ?>">
            <select name="payment_filter">
                <option value="">All Payment</option>
                <option value="Cash"  <?php echo $payment_filter === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="Bank"  <?php echo $payment_filter === 'Bank' ? 'selected' : ''; ?>>Bank</option>
            </select>
            <select name="food_filter">
                <option value="">All Food</option>
                <option value="Company" <?php echo $food_filter === 'Company' ? 'selected' : ''; ?>>Company Food</option>
                <option value="Own"     <?php echo ($food_filter === 'Own' || $food_filter === 'Won') ? 'selected' : ''; ?>>Own Food</option>
            </select>
            <button type="submit" name="search" value="1" class="btn btn-primary">Search</button>
            <a class="btn btn-success" href="generate_salary.php?month=<?php echo urlencode($month); ?>&search_user_no=<?php echo urlencode($search_user_no); ?>&payment_filter=<?php echo urlencode($payment_filter); ?>&food_filter=<?php echo urlencode($food_filter); ?>&search=1&export=excel">&#8659; Excel</a>
            <a class="btn btn-secondary" href="generate_salary.php?month=<?php echo urlencode($month); ?>">&#10005; Clear</a>
        </div>
    </form>
</div>

<?php if ($show_salary_sheet) { ?>

<!-- â•â• SALARY TABLE FORM â•â• -->
<form method="GET">
<input type="hidden" name="month"          value="<?php echo htmlspecialchars($month); ?>">
<input type="hidden" name="search_user_no" value="<?php echo htmlspecialchars($search_user_no); ?>">
<input type="hidden" name="payment_filter" value="<?php echo htmlspecialchars($payment_filter); ?>">
<input type="hidden" name="food_filter"    value="<?php echo htmlspecialchars($food_filter); ?>">

<!-- Actions bar (top) -->
<div class="actions-bar" id="actionsTop">
    <label><input type="checkbox" id="selectAllTop"> Select All</label>
    <button type="submit" name="generate"   value="1" class="btn btn-warning selected-generate-btn">&#9654; Generate Salary Sheet</button>
    <button type="submit" name="sunday_ot"  value="1" class="btn btn-sunday  selected-generate-btn">&#9788; Sunday Generate</button>
    <?php if ($use_sunday_ot) { ?>
        <span class="mode-badge">Sunday OT Mode Active</span>
    <?php } ?>
</div>

<!-- Table -->
<div class="table-wrapper" id="tableScroll">
<table class="salary-table">
<thead>
    <!-- Row 1: Company name -->
    <tr>
        <th colspan="32" style="font-size:16px; font-weight:800; letter-spacing:0.06em; background:var(--brand); padding:10px;">
            EURO TROUSERS MFG CO (FZC) &mdash; SALARY SHEET FOR <?php echo strtoupper($month_title); ?>
        </th>
    </tr>
    <!-- Row 2: Group labels -->
    <tr>
        <th colspan="4"  class="grp-identity">Identity</th>
        <th colspan="2"  class="grp-base">Base Pay</th>
        <th colspan="3"  class="grp-days">Attendance</th>
        <th colspan="2"  class="grp-earnings">Earned</th>
        <th colspan="7"  class="grp-ot">Overtime</th>
        <th colspan="2"  class="grp-deduction">Late Ded.</th>
        <th colspan="1"  class="grp-allowance">Att Allow.</th>
        <th colspan="1"  class="grp-earnings">Total Sal.</th>
        <th colspan="2"  class="grp-food">Food Allow.</th>
        <th colspan="1"  class="grp-earnings">Gross</th>
        <th colspan="4"  class="grp-deduction">Deductions</th>
        <th colspan="1"  class="grp-net">Net</th>
        <th colspan="2"  class="grp-status">Info</th>
    </tr>
    <!-- Row 3: Column headers -->
    <tr>
        <th class="col-num">SL</th>
        <th>User No.</th>
        <th style="min-width:30px;">&#9745;</th>
        <th class="col-name">Employee Name</th>
        <th class="col-basic">Basic Salary</th>
        <th class="col-basic">Allowance</th>
        <th class="col-days">Month Days</th>
        <th class="col-days">Working Days</th>
        <th class="col-days">Absent</th>
        <th class="col-amount">Salary Earned</th>
        <th class="col-amount">Allow. Earned</th>
        <th class="col-ot">Reg OT hrs</th>
        <th class="col-amount">Reg OT Amt</th>
        <th class="col-ot">After 6pm hrs</th>
        <th class="col-amount">After 6pm Amt</th>
        <th class="col-ot">Sunday OT hrs</th>
        <th class="col-amount">Sunday OT Amt</th>
        <th class="col-amount">OT Total</th>
        <th class="col-ot">Total Late</th>
        <th class="col-amount">Late Amount</th>
        <th class="col-amount">Good Att.</th>
        <th class="col-amount">Total Salary</th>
        <th class="col-amount">Food (Co.)</th>
        <th class="col-amount">Food (Own)</th>
        <th class="col-amount">Gross Total</th>
        <th class="col-amount">Insurance</th>
        <th class="col-amount">Advance</th>
        <th class="col-amount">Other Ded.</th>
        <th class="col-amount">Total Ded.</th>
        <th class="col-net">Net Payable</th>
        <th style="min-width:70px;">Pay By</th>
        <th style="min-width:90px;">Status</th>
    </tr>
</thead>
<tbody>

<?php
$sl = 1;

if ($result) {
while ($emp = mysqli_fetch_assoc($result)) {
    $user_no = $emp['user_no'];
    $safe_user_no = esc($conn, $user_no);
    $employee_id_value = $emp['employee_id'] ?? '';
    $should_generate_employee = $is_generate_request && (!$is_selected_generate || in_array((string)$user_no, $selected_users, true));

    $basic_salary = (float)($emp['basic_salary'] ?? 0);
    $allowance = (float)($emp['allowance'] ?? 0);
    $fixed_salary = (float)($emp['fixed_salary'] ?? 0);
    $is_fixed = $fixed_salary > 0;
    $att_allowance = (float)($emp['att_allowance'] ?? 0);
    $employee_base_att_allowance = (float)($emp['employee_base_att_allowance'] ?? 0);
    if ($employee_base_att_allowance > 0) {
        $att_allowance = $employee_base_att_allowance;
    }
    $regular_ot_hours = monthly_regular_ot_hours($conn, $user_no, $employee_id_value, $month);
    $attendance_after6pm_ot = monthly_ot_hours($conn, $user_no, $employee_id_value, $month);
    $uploaded_ot_total = monthly_uploaded_extra_ot_hours($conn, $user_no, $employee_id_value, $month);
    $sunday_ot_hours = monthly_sunday_ot_hours($conn, $user_no, $employee_id_value, $month);
    $nonsunday_uploaded_ot = max(0, $uploaded_ot_total - $sunday_ot_hours);
    // After 6pm column = attendance after-6pm OT + non-Sunday uploaded OT (overtime_report)
    $live_after6pm_ot = $attendance_after6pm_ot + $nonsunday_uploaded_ot;

    if ($should_generate_employee) {
        $ot_hours = $live_after6pm_ot;       // After 6pm column
        $extra_ot_hours = $sunday_ot_hours;  // Sunday OT column
    } else {
        $ot_hours = isset($emp['ot']) && $emp['ot'] !== '' ? (float)$emp['ot'] : $live_after6pm_ot;
        $extra_ot_hours = isset($emp['extra_ot_hours']) && $emp['extra_ot_hours'] !== '' ? (float)$emp['extra_ot_hours'] : $sunday_ot_hours;
    }
    $ot_hours_class = '';
    $ot_amount_class = $sunday_ot_hours > 0 ? 'sunday-ot' : '';
    $food_allowance_company = isset($emp['food_allowance_company']) && $emp['food_allowance_company'] !== ''
        ? (float)$emp['food_allowance_company']
        : (float)($emp['employee_food_allowance_company'] ?? 0);
    $food_allowance_won = isset($emp['food_allowance_won']) && $emp['food_allowance_won'] !== ''
        ? (float)$emp['food_allowance_won']
        : (float)($emp['employee_food_allowance_won'] ?? 0);
    if ($food_allowance_company == 0 && $food_allowance_won == 0) {
        $food_allowance_company = (float)($emp['food_allowance'] ?? 0);
    }
    $gross_food_allowance = $food_allowance_company + $food_allowance_won;
    $net_food_adjustment = -$food_allowance_company;

    $insurance = (float)($emp['insurance_amount'] ?? 0);
    $advance = (float)($emp['advance_amount'] ?? 0);
    $other_deduction = (float)($emp['other_deduction'] ?? 0);
    $payment_by = trim($emp['salary_by'] ?? '');
    if ($payment_by === '') {
        $payment_by = trim($emp['employee_salary_by'] ?? '');
    }

    [$month_start, $month_end] = month_range($month);
    $safe_month_start = esc($conn, $month_start);
    $safe_month_end = esc($conn, $month_end);
    $att_q = mysqli_query($conn, "
        SELECT
        SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations l
                    WHERE l.user_no = attendance.user_no
                    AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
                ) THEN 0
                WHEN check_in IS NOT NULL AND TRIM(check_in) != '' THEN 1
                WHEN DAYNAME(attendance_date)='Sunday' THEN 1
                WHEN attendance_date IN (SELECT holiday_date FROM holidays) THEN 1
                ELSE 0
            END
        ) AS present_days,
        SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM vacations l
                    WHERE l.user_no = attendance.user_no
                    AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
                ) THEN 0
                WHEN (check_in IS NULL OR TRIM(check_in)='')
                AND DAYNAME(attendance_date)!='Sunday'
                AND attendance_date NOT IN (SELECT holiday_date FROM holidays)
                THEN 1
                ELSE 0
            END
        ) AS absent_days
        FROM attendance
        WHERE user_no='$safe_user_no'
        AND attendance_date BETWEEN '$safe_month_start' AND '$safe_month_end'
    ");

    $att = $att_q ? mysqli_fetch_assoc($att_q) : [];
    $present_days = (float)($att['present_days'] ?? 0);
    $absent_days = (float)($att['absent_days'] ?? 0);
    $late_seconds = monthly_late_seconds($conn, $user_no, $month);
    $calculated_late_hours = round($late_seconds / 3600, 2);
    $calculated_late_amount = ($basic_salary / 30 / 8) * 1.25 * $calculated_late_hours;
    $stored_late_amount = isset($emp['late_amount']) && $emp['late_amount'] !== '' ? (float)$emp['late_amount'] : 0;
    $stored_late_hours = isset($emp['total_late_hours']) && $emp['total_late_hours'] !== '' ? (float)$emp['total_late_hours'] : 0;
    $total_late_hours = $stored_late_hours > 0 ? $stored_late_hours : $calculated_late_hours;
    $late_amount = $stored_late_amount > 0 ? $stored_late_amount : $calculated_late_amount;
    $no_working_days = $present_days <= 0;
    $late_att_allowance_removed = 0;
    $short_duty_days = monthly_short_duty_days($conn, $user_no, $employee_id_value, $month);

    if ($late_seconds > 0 || $absent_days > 0 || $short_duty_days > 0) {
        $late_att_allowance_removed = $att_allowance;
        $att_allowance = 0;
    }

    if ($is_fixed) {
        // Fixed salary: flat monthly amount, but reduced for ABSENT days the
        // same way basic salary is prorated: (fixed_salary / month_days) * present_days.
        // present_days already counts Sundays + holidays, so a full month with no
        // absence pays the whole fixed amount. Still: no OT, no late deduction,
        // no attendance-allowance. Deductions (insurance, advance, other) still apply.
        $salary_earned = $month_days > 0 ? ($fixed_salary / $month_days) * $present_days : 0;
        $allowance_earned = 0;
        $att_allowance = 0;
        $late_att_allowance_removed = 0;
        $regular_ot_hours = 0;
        $regular_ot_amount = 0;
        $ot_hours = 0;
        $after6pm_ot_amount = 0;
        $extra_ot_hours = 0;
        $extra_ot_amount = 0;
        $sunday_ot_hours = 0;
        $ot_amount = 0;
        $total_late_hours = 0;
        $late_amount = 0;
        $total_salary = $salary_earned;
        $gross_total = max(0, $total_salary + $gross_food_allowance);
        $total_deduction = $insurance + $advance + $other_deduction;
        $net_payable = max(0, $gross_total - $total_deduction + $net_food_adjustment);
    } elseif ($no_working_days) {
        $salary_earned = 0;
        $allowance_earned = 0;
        $regular_ot_amount = 0;
        $after6pm_ot_amount = 0;
        $extra_ot_hours = 0;
        $extra_ot_amount = 0;
        $total_late_hours = 0;
        $late_amount = 0;
        $ot_amount = 0;
        $att_allowance = 0;
        $total_salary = 0;
        $gross_total = 0;
        $total_deduction = 0;
        $net_payable = 0;
    } elseif ($should_generate_employee) {
        $salary_earned = $month_days > 0 ? ($basic_salary / $month_days) * $present_days : 0;
        $allowance_earned = $month_days > 0 ? ($allowance / $month_days) * $present_days : 0;
        $regular_ot_amount = ($basic_salary / 30 / 8) * 1.25 * $regular_ot_hours;
        $after6pm_ot_amount = ($basic_salary / 30 / 8) * 1.25 * $ot_hours;
        $extra_ot_amount = ($basic_salary / 30 / 8) * 1.5 * $extra_ot_hours;
        $ot_amount = $regular_ot_amount + $after6pm_ot_amount + $extra_ot_amount;
        $total_late_hours = $calculated_late_hours;
        $late_amount = $calculated_late_amount;
        $total_salary = max(0, $salary_earned + $allowance_earned + $ot_amount + $att_allowance - $late_amount);
        $gross_total = max(0, $total_salary + $gross_food_allowance);
        $total_deduction = $insurance + $advance + $other_deduction;
        $net_payable = $gross_total - $total_deduction + $net_food_adjustment;
    } else {
        $salary_earned = isset($emp['salary_earned']) && $emp['salary_earned'] !== ''
            ? (float)$emp['salary_earned']
            : ($month_days > 0 ? ($basic_salary / $month_days) * $present_days : 0);
        $allowance_earned = isset($emp['allowance_earned']) && $emp['allowance_earned'] !== ''
            ? (float)$emp['allowance_earned']
            : ($month_days > 0 ? ($allowance / $month_days) * $present_days : 0);
        $regular_ot_amount = isset($emp['regular_ot_amount']) && $emp['regular_ot_amount'] !== ''
            ? (float)$emp['regular_ot_amount']
            : (($basic_salary / 30 / 8) * 1.25 * $regular_ot_hours);
        $after6pm_ot_amount = isset($emp['after6pm_ot_amount']) && $emp['after6pm_ot_amount'] !== ''
            ? (float)$emp['after6pm_ot_amount']
            : (($basic_salary / 30 / 8) * 1.25 * $ot_hours);
        $extra_ot_amount = isset($emp['extra_ot_amount']) && $emp['extra_ot_amount'] !== ''
            ? (float)$emp['extra_ot_amount']
            : (($basic_salary / 30 / 8) * 1.5 * $extra_ot_hours);
        $ot_amount = isset($emp['ot_amount']) && $emp['ot_amount'] !== ''
            ? (float)$emp['ot_amount']
            : ($regular_ot_amount + $after6pm_ot_amount + $extra_ot_amount);
        // Total salary is computed from the components shown in this row, so it
        // always matches them. (Previously a saved total had the removed
        // attendance-allowance / late subtracted again, double-counting the
        // penalty when the record was generated with the absence already applied.)
        $total_salary = max(0, $salary_earned + $allowance_earned + $ot_amount + $att_allowance - $late_amount);
        $gross_total = max(0, $total_salary + $gross_food_allowance);
        $total_deduction = isset($emp['total_deduction']) && $emp['total_deduction'] !== ''
            ? (float)$emp['total_deduction']
            : ($insurance + $advance + $other_deduction);
        $net_payable = max(0, $gross_total - $total_deduction + $net_food_adjustment);
    }

    $current_salary_status = trim($emp['current_salary_status'] ?? ($emp['salary_status'] ?? ''));
    $salary_is_generated = !$no_working_days && in_array($current_salary_status, ['Generated', 'Paid'], true);
    if ($should_generate_employee && !$no_working_days) {
        $salary_is_generated = true;
    }

    if (!$salary_is_generated) {
        $salary_earned = 0;
        $allowance_earned = 0;
        $regular_ot_amount = 0;
        $after6pm_ot_amount = 0;
        $extra_ot_amount = 0;
        $total_late_hours = 0;
        $late_amount = 0;
        $ot_amount = 0;
        $att_allowance = 0;
        $total_salary = 0;
        $gross_total = 0;
        $total_deduction = 0;
        $net_payable = 0;
    }

    $display_food_allowance_company = $salary_is_generated ? $food_allowance_company : 0;
    $display_food_allowance_won     = $salary_is_generated ? $food_allowance_won     : 0;
    $food_company_cell_class = '';
    $food_company_display = money($display_food_allowance_company);
    $total_net_salary += $net_payable;
    $payment_by_lower = strtolower($payment_by);
    if ($payment_by_lower === 'cash') {
        $cash_payment_total += $net_payable;
    } elseif ($payment_by_lower === 'bank') {
        $bank_payment_total += $net_payable;
    }

    if ($should_generate_employee) {
        $sets = [];
        add_set($conn, $salary_columns, 'att_allowance', $att_allowance, $sets);
        add_set($conn, $salary_columns, 'fixed_salary', $fixed_salary, $sets);
        add_set($conn, $salary_columns, 'ot', $ot_hours, $sets);
        add_set($conn, $salary_columns, 'regular_ot_hours', $regular_ot_hours, $sets);
        add_set($conn, $salary_columns, 'regular_ot_amount', $regular_ot_amount, $sets);
        add_set($conn, $salary_columns, 'after6pm_ot_amount', $after6pm_ot_amount, $sets);
        add_set($conn, $salary_columns, 'extra_ot_hours', $extra_ot_hours, $sets);
        add_set($conn, $salary_columns, 'food_allowance', $gross_food_allowance, $sets);
        add_set($conn, $salary_columns, 'food_allowance_company', $food_allowance_company, $sets);
        add_set($conn, $salary_columns, 'food_allowance_won', $food_allowance_won, $sets);
        add_set($conn, $salary_columns, 'salary_earned', $salary_earned, $sets);
        add_set($conn, $salary_columns, 'allowance_earned', $allowance_earned, $sets);
        add_set($conn, $salary_columns, 'extra_ot_amount', $extra_ot_amount, $sets);
        add_set($conn, $salary_columns, 'ot_amount', $ot_amount, $sets);
        add_set($conn, $salary_columns, 'total_late_hours', $total_late_hours, $sets);
        add_set($conn, $salary_columns, 'late_amount', $late_amount, $sets);
        add_set($conn, $salary_columns, 'total_salary', $total_salary, $sets);
        add_set($conn, $salary_columns, 'gross_total', $gross_total, $sets);
        add_set($conn, $salary_columns, 'total_deduction', $total_deduction, $sets);
        add_set($conn, $salary_columns, 'net_salary', $net_payable, $sets);
        add_set($conn, $salary_columns, 'net_payable', $net_payable, $sets);
        add_set($conn, $salary_columns, 'salary_by', $payment_by, $sets);
        add_set($conn, $salary_columns, 'salary_status', $no_working_days ? 'Unpaid' : 'Generated', $sets);
        if (!empty($sets)) {
            mysqli_query($conn, "
                UPDATE employee_salary_records
                SET " . implode(',', $sets) . "
                WHERE user_no='$safe_user_no'
                AND salary_month='$safe_month'
            ");
        }
    }

    $pay_badge_class = $payment_by_lower === 'cash' ? 'pay-cash' : ($payment_by_lower === 'bank' ? 'pay-bank' : '');
?>
<tr class="<?php echo $no_working_days ? 'no-work-row' : ''; ?>">
    <td><?php echo $sl++; ?></td>
    <td><strong><?php echo htmlspecialchars($user_no); ?></strong></td>
    <td><input type="checkbox" class="employee-select" name="selected_users[]" value="<?php echo htmlspecialchars($user_no); ?>" <?php echo in_array((string)$user_no, $selected_users, true) ? 'checked' : ''; ?>></td>
    <td class="td-name"><?php echo htmlspecialchars($emp['full_name'] ?? ''); ?></td>
    <td><?php echo money($basic_salary); ?></td>
    <td><?php echo money($allowance); ?></td>
    <td><?php echo $month_days; ?></td>
    <td><?php echo money($present_days); ?></td>
    <td><?php echo money($absent_days); ?></td>
    <td><?php echo money($salary_earned); ?></td>
    <td><?php echo money($allowance_earned); ?></td>
    <td><?php echo money($regular_ot_hours); ?></td>
    <td><?php echo money($regular_ot_amount); ?></td>
    <td class="<?php echo $ot_hours_class; ?>"><?php echo money($ot_hours); ?></td>
    <td><?php echo money($after6pm_ot_amount); ?></td>
    <td class="<?php echo $ot_amount_class; ?>"><?php echo number_format((float)$extra_ot_hours, 2); ?></td>
    <td class="<?php echo $ot_amount_class; ?>"><?php echo money($extra_ot_amount); ?></td>
    <td class="<?php echo $ot_amount_class; ?>"><?php echo money($ot_amount); ?></td>
    <td><?php echo hours_minutes($total_late_hours); ?></td>
    <td style="color:#d35400;font-weight:700;"><?php echo $late_amount > 0 ? '-' . money($late_amount) : money(0); ?></td>
    <td><?php echo money($att_allowance); ?></td>
    <td><?php echo money($total_salary); ?></td>
    <td class="<?php echo $food_company_cell_class; ?>"><?php echo $food_company_display; ?></td>
    <td><?php echo money($display_food_allowance_won); ?></td>
    <td><?php echo money($gross_total); ?></td>
    <td><?php echo money($insurance); ?></td>
    <td><?php echo money($advance); ?></td>
    <td><?php echo money($other_deduction); ?></td>
    <td><?php echo money($total_deduction); ?></td>
    <td class="td-net"><?php echo money($net_payable); ?></td>
    <td><?php if ($payment_by !== '') { ?><span class="pay-badge <?php echo $pay_badge_class; ?>"><?php echo htmlspecialchars($payment_by); ?></span><?php } ?></td>
    <td>
        <?php if ($salary_is_generated): ?>
            <span class="badge badge-generated">&#10003; Generated</span>
        <?php else: ?>
            <span class="badge badge-not">&#8212; Not Generated</span>
        <?php endif; ?>
    </td>
</tr>
<?php
}
}
?>
</tbody>
<tfoot>
<tr class="total-row">
    <td colspan="29" style="text-align:right; font-size:14px; padding-right:16px; font-weight:700;">TOTAL NET PAYABLE (AED)</td>
    <td class="td-net-total"><?php echo money($total_net_salary); ?></td>
    <td colspan="2"></td>
</tr>
</tfoot>
</table>
</div><!-- /table-wrapper -->

<!-- Bottom actions bar -->
<div class="actions-bar" style="border-top:2px solid rgba(255,255,255,0.15); flex-shrink:0;">
    <label><input type="checkbox" id="selectAllBottom"> Select All</label>
    <button type="submit" name="generate"  value="1" class="btn btn-warning selected-generate-btn">&#9654; Generate Salary Sheet</button>
    <button type="submit" name="sunday_ot" value="1" class="btn btn-sunday  selected-generate-btn">&#9788; Sunday Generate</button>
    <?php if ($use_sunday_ot) { ?>
        <span class="mode-badge">Sunday OT Mode Active</span>
    <?php } ?>
</div>
</form>

<?php } else { ?>
<div class="empty-state">
    <div class="icon">&#128203;</div>
    <h3>No Salary Sheet Loaded</h3>
    <p>Select a month and click <strong>Search</strong> to view saved records,<br>then select employees and click <strong>Generate Salary Sheet</strong> to create or update.</p>
</div>
<?php } ?>

</div><!-- /page-content -->

<script>
// â”€â”€ Dynamically set table height to fill remaining viewport â”€â”€
function setTableHeight() {
    var wrapper = document.getElementById('tableScroll');
    if (!wrapper) return;
    var rect = wrapper.getBoundingClientRect();
    // bottom actions bar height ~44px, leave 8px gap
    var h = window.innerHeight - rect.top - 52;
    if (h < 200) h = 200;
    document.documentElement.style.setProperty('--table-h', h + 'px');
}
setTableHeight();
window.addEventListener('resize', setTableHeight);

// â”€â”€ Live totals â”€â”€
document.getElementById("totalSalaryTop").innerText = "<?php echo money($total_net_salary); ?>  AED";
document.getElementById("cashPaymentTop").innerText  = "<?php echo money($cash_payment_total); ?> AED";
document.getElementById("bankPaymentTop").innerText  = "<?php echo money($bank_payment_total); ?> AED";

// â”€â”€ Select All logic â”€â”€
function employeeCheckboxes() {
    return Array.from(document.querySelectorAll(".employee-select"));
}

function syncSelectAllState() {
    const boxes   = employeeCheckboxes();
    const checked = boxes.filter(b => b.checked).length;
    ["selectAllTop","selectAllBottom"].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.checked       = boxes.length > 0 && checked === boxes.length;
        el.indeterminate = checked > 0 && checked < boxes.length;
    });
}

function setAllEmployees(checked) {
    employeeCheckboxes().forEach(b => b.checked = checked);
    syncSelectAllState();
}

["selectAllTop","selectAllBottom"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener("change", function() { setAllEmployees(this.checked); });
});

employeeCheckboxes().forEach(b => b.addEventListener("change", syncSelectAllState));

document.querySelectorAll(".selected-generate-btn").forEach(btn => {
    btn.addEventListener("click", function(e) {
        if (!employeeCheckboxes().some(b => b.checked)) {
            e.preventDefault();
            alert("Please select at least one employee first.");
        }
    });
});

syncSelectAllState();
</script>
</body>
</html>

