<?php
if (!function_exists('payroll_esc')) {
    function payroll_esc($conn, $value) {
        return mysqli_real_escape_string($conn, (string)$value);
    }
}

if (!function_exists('payroll_ensure_advance_schema')) {
    function payroll_advance_add_missing_column($conn, $table, $column, $definition) {
        $safe_table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $safe_column = payroll_esc($conn, $column);
        $check = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_table` LIKE '$safe_column'");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "ALTER TABLE `$safe_table` ADD `$column` $definition");
        }
    }

    function payroll_ensure_advance_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS employee_advances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_no VARCHAR(50) NOT NULL,
                employee_name VARCHAR(150) DEFAULT '',
                advance_date DATE NOT NULL,
                total_advance DECIMAL(12,2) NOT NULL DEFAULT 0,
                monthly_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
                start_month VARCHAR(7) NOT NULL,
                total_months INT NOT NULL DEFAULT 1,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                note TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_no (user_no),
                INDEX idx_start_month (start_month),
                INDEX idx_status (status)
            )
        ");

        payroll_advance_add_missing_column($conn, 'employee_advances', 'employee_name', "VARCHAR(150) DEFAULT ''");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'advance_date', "DATE NULL");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'total_advance', "DECIMAL(12,2) NOT NULL DEFAULT 0");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'monthly_deduction', "DECIMAL(12,2) NOT NULL DEFAULT 0");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'start_month', "VARCHAR(7) DEFAULT ''");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'total_months', "INT NOT NULL DEFAULT 1");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'paid_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'balance_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'status', "VARCHAR(20) NOT NULL DEFAULT 'Active'");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'note', "TEXT");
        payroll_advance_add_missing_column($conn, 'employee_advances', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS employee_advance_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                advance_id INT NOT NULL,
                user_no VARCHAR(50) NOT NULL,
                salary_month VARCHAR(7) NOT NULL,
                deduction_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                salary_record_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_advance_month (advance_id, salary_month),
                INDEX idx_user_month (user_no, salary_month)
            )
        ");

        payroll_advance_add_missing_column($conn, 'employee_advance_payments', 'salary_record_id', "INT DEFAULT NULL");
        payroll_advance_add_missing_column($conn, 'employee_advance_payments', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    function payroll_get_employee_name($conn, $user_no) {
        $safe_user = payroll_esc($conn, $user_no);
        $q = mysqli_query($conn, "SELECT full_name FROM employees WHERE user_no='$safe_user' LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            return $row['full_name'] ?? '';
        }
        return '';
    }

    function payroll_get_active_advance($conn, $user_no, $month) {
        payroll_ensure_advance_schema($conn);
        $safe_user = payroll_esc($conn, $user_no);
        $safe_month = payroll_esc($conn, $month);
        $q = mysqli_query($conn, "
            SELECT *
            FROM employee_advances
            WHERE user_no='$safe_user'
              AND status='Active'
              AND start_month <= '$safe_month'
              AND balance_amount > 0
            ORDER BY start_month ASC, id ASC
            LIMIT 1
        ");
        return ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : null;
    }

    function payroll_calculate_advance_deduction($conn, $user_no, $month) {
        payroll_ensure_advance_schema($conn);
        $safe_user = payroll_esc($conn, $user_no);
        $safe_month = payroll_esc($conn, $month);
        $existing_q = mysqli_query($conn, "
            SELECT p.deduction_amount, a.*
            FROM employee_advance_payments p
            LEFT JOIN employee_advances a ON a.id = p.advance_id
            WHERE p.user_no='$safe_user'
              AND p.salary_month='$safe_month'
            ORDER BY p.id DESC
            LIMIT 1
        ");
        if ($existing_q && mysqli_num_rows($existing_q) > 0) {
            $existing = mysqli_fetch_assoc($existing_q);
            $amount = (float)($existing['deduction_amount'] ?? 0);
            return [
                'amount' => $amount,
                'advance' => $existing,
                'balance_after' => max(0, (float)($existing['balance_amount'] ?? 0))
            ];
        }

        $advance = payroll_get_active_advance($conn, $user_no, $month);
        if (!$advance) {
            return ['amount' => 0, 'advance' => null, 'balance_after' => 0];
        }

        $monthly = (float)($advance['monthly_deduction'] ?? 0);
        $balance = (float)($advance['balance_amount'] ?? 0);
        $amount = max(0, min($monthly, $balance));

        return [
            'amount' => $amount,
            'advance' => $advance,
            'balance_after' => max(0, $balance - $amount)
        ];
    }

    function payroll_apply_advance_payment($conn, $advance_id, $user_no, $month, $amount, $salary_record_id = null) {
        payroll_ensure_advance_schema($conn);
        $advance_id = (int)$advance_id;
        $amount = round((float)$amount, 2);
        if ($advance_id <= 0 || $amount <= 0) {
            return;
        }

        $safe_user = payroll_esc($conn, $user_no);
        $safe_month = payroll_esc($conn, $month);
        $salary_record_id_sql = $salary_record_id ? (int)$salary_record_id : "NULL";

        $existing_q = mysqli_query($conn, "
            SELECT id, deduction_amount
            FROM employee_advance_payments
            WHERE advance_id=$advance_id AND salary_month='$safe_month'
            LIMIT 1
        ");

        if ($existing_q && mysqli_num_rows($existing_q) > 0) {
            $existing = mysqli_fetch_assoc($existing_q);
            $old_amount = (float)($existing['deduction_amount'] ?? 0);
            $delta = $amount - $old_amount;
            $payment_id = (int)$existing['id'];

            mysqli_query($conn, "
                UPDATE employee_advance_payments
                SET deduction_amount=$amount, salary_record_id=$salary_record_id_sql
                WHERE id=$payment_id
            ");
        } else {
            $delta = $amount;
            mysqli_query($conn, "
                INSERT INTO employee_advance_payments
                    (advance_id, user_no, salary_month, deduction_amount, salary_record_id)
                VALUES
                    ($advance_id, '$safe_user', '$safe_month', $amount, $salary_record_id_sql)
            ");
        }

        if (abs($delta) > 0.0001) {
            mysqli_query($conn, "
                UPDATE employee_advances
                SET
                    paid_amount = GREATEST(0, paid_amount + ($delta)),
                    balance_amount = GREATEST(0, total_advance - GREATEST(0, paid_amount + ($delta)))
                WHERE id=$advance_id
            ");
        }

        mysqli_query($conn, "
            UPDATE employee_advances
            SET status = CASE WHEN balance_amount <= 0 THEN 'Completed' ELSE 'Active' END
            WHERE id=$advance_id
        ");
    }

    function payroll_get_advance_summary($conn, $user_no) {
        payroll_ensure_advance_schema($conn);
        $safe_user = payroll_esc($conn, $user_no);
        $q = mysqli_query($conn, "
            SELECT
                COALESCE(SUM(total_advance),0) AS total_advance,
                COALESCE(SUM(paid_amount),0) AS paid_amount,
                COALESCE(SUM(balance_amount),0) AS balance_amount,
                COALESCE(MAX(monthly_deduction),0) AS monthly_deduction,
                MAX(advance_date) AS last_advance_date
            FROM employee_advances
            WHERE user_no='$safe_user'
              AND status IN ('Active','Completed')
        ");
        return ($q && ($row = mysqli_fetch_assoc($q))) ? $row : [
            'total_advance' => 0,
            'paid_amount' => 0,
            'balance_amount' => 0,
            'monthly_deduction' => 0,
            'last_advance_date' => ''
        ];
    }
}
