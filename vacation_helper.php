<?php
if (!function_exists('vacation_esc')) {
    function vacation_esc($conn, $value) {
        return mysqli_real_escape_string($conn, trim((string)$value));
    }
}

if (!function_exists('vacation_add_missing_column')) {
    function vacation_add_missing_column($conn, $table, $column, $definition) {
        $safe_table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $safe_column = vacation_esc($conn, $column);
        $check = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_table` LIKE '$safe_column'");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "ALTER TABLE `$safe_table` ADD `$column` $definition");
        }
    }

    function vacation_ensure_schema($conn) {
        vacation_add_missing_column($conn, 'vacations', 'leave_type', "VARCHAR(50) DEFAULT 'Annual Vacation'");
        vacation_add_missing_column($conn, 'vacations', 'paid_status', "VARCHAR(30) DEFAULT 'Paid'");
        vacation_add_missing_column($conn, 'vacations', 'return_date', "DATE NULL");
        vacation_add_missing_column($conn, 'vacations', 'vacation_status', "VARCHAR(30) DEFAULT 'Upcoming'");
        vacation_add_missing_column($conn, 'vacations', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    function vacation_effective_to_sql() {
        return "COALESCE(NULLIF(return_date,'0000-00-00'), to_date)";
    }

    function vacation_status_from_dates($from_date, $to_date, $return_date = '', $stored_status = '') {
        $today = date('Y-m-d');
        $stored = strtolower(trim((string)$stored_status));
        if ($stored === 'cancelled' || $stored === 'closed') {
            return ucfirst($stored);
        }
        if ($return_date !== '' && $return_date !== '0000-00-00' && strtotime($return_date) !== false && $return_date <= $today) {
            return 'Returned';
        }
        if ($from_date <= $today && $to_date >= $today) {
            return 'Running';
        }
        if ($from_date > $today) {
            return 'Upcoming';
        }
        if ($to_date < $today) {
            return 'Overdue Return';
        }
        return 'Upcoming';
    }
}
