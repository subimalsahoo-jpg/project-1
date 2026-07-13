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
        // Original columns
        vacation_add_missing_column($conn, 'vacations', 'leave_type', "VARCHAR(50) DEFAULT 'Annual Vacation'");
        vacation_add_missing_column($conn, 'vacations', 'paid_status', "VARCHAR(30) DEFAULT 'Paid'");
        vacation_add_missing_column($conn, 'vacations', 'return_date', "DATE NULL");
        vacation_add_missing_column($conn, 'vacations', 'vacation_status', "VARCHAR(30) DEFAULT 'Pending Approval'");
        vacation_add_missing_column($conn, 'vacations', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        // New columns for expanded view
        vacation_add_missing_column($conn, 'vacations', 'department', "VARCHAR(100) DEFAULT ''");
        vacation_add_missing_column($conn, 'vacations', 'designation', "VARCHAR(100) DEFAULT ''");
        vacation_add_missing_column($conn, 'vacations', 'nationality', "VARCHAR(50) DEFAULT ''");
        vacation_add_missing_column($conn, 'vacations', 'leave_balance', "INT DEFAULT 0");
        vacation_add_missing_column($conn, 'vacations', 'ticket_type', "VARCHAR(30) DEFAULT 'Company'");
        vacation_add_missing_column($conn, 'vacations', 'leave_salary_status', "VARCHAR(30) DEFAULT 'Paid'");
        vacation_add_missing_column($conn, 'vacations', 'air_ticket_amount', "DECIMAL(10,2) DEFAULT 0.00");
        vacation_add_missing_column($conn, 'vacations', 'advance_salary', "DECIMAL(10,2) DEFAULT 0.00");
        vacation_add_missing_column($conn, 'vacations', 'passport_expiry', "DATE NULL");
        vacation_add_missing_column($conn, 'vacations', 'visa_expiry', "DATE NULL");
        vacation_add_missing_column($conn, 'vacations', 'approved_by', "VARCHAR(100) DEFAULT ''");
        vacation_add_missing_column($conn, 'vacations', 'applied_date', "DATE NULL");
        vacation_add_missing_column($conn, 'vacations', 'actual_return', "DATE NULL");
        vacation_add_missing_column($conn, 'vacations', 'late_days', "INT DEFAULT 0");
        vacation_add_missing_column($conn, 'vacations', 'payroll_processed', "VARCHAR(10) DEFAULT 'No'");
    }

    function vacation_effective_to_sql() {
        return "COALESCE(NULLIF(return_date,'0000-00-00'), to_date)";
    }

    /**
     * New Status System:
     * - Pending Approval (applied but not yet approved)
     * - Approved (HR approved, waiting for ticket/travel)
     * - Ticket Processing (ticket being arranged)
     * - Travelled (left for vacation)
     * - On Vacation (currently on vacation)
     * - Returned (came back)
     * - Return Today (expected to return today)
     * - Over Stayed (past due date, not returned)
     * - Cancelled (vacation cancelled)
     */
    function vacation_status_from_dates($from_date, $to_date, $return_date = '', $stored_status = '') {
        $today = date('Y-m-d');
        $stored = strtolower(trim((string)$stored_status));

        // If a specific status is stored, use it for these explicit states
        if ($stored === 'cancelled') return 'Cancelled';
        if ($stored === 'pending approval') return 'Pending Approval';
        if ($stored === 'approved') return 'Approved';
        if ($stored === 'ticket processing') return 'Ticket Processing';
        if ($stored === 'travelled') return 'Travelled';

        // Check actual return
        if ($return_date !== '' && $return_date !== '0000-00-00' && strtotime($return_date) !== false) {
            if ($return_date <= $today) {
                return 'Returned';
            }
        }

        // Return Today check
        if ($to_date === $today) {
            if (empty($return_date) || $return_date === '0000-00-00') {
                return 'Return Today';
            }
        }

        // Currently on vacation
        if ($from_date <= $today && $to_date >= $today) {
            return 'On Vacation';
        }

        // Over Stayed (past to_date but not returned)
        if ($to_date < $today && (empty($return_date) || $return_date === '0000-00-00')) {
            return 'Over Stayed';
        }

        // Future vacation - check stored status
        if ($from_date > $today) {
            if ($stored === 'ticket processing') return 'Ticket Processing';
            if ($stored === 'approved') return 'Approved';
            return 'Pending Approval';
        }

        return 'On Vacation';
    }

    /**
     * Get CSS class for status badge
     */
    function vacation_status_class($status) {
        $map = [
            'Pending Approval' => 'status-pending',
            'Approved' => 'status-approved',
            'Ticket Processing' => 'status-ticket',
            'Travelled' => 'status-travelled',
            'On Vacation' => 'status-on-vacation',
            'Returned' => 'status-returned',
            'Return Today' => 'status-return-today',
            'Over Stayed' => 'status-overstayed',
            'Cancelled' => 'status-cancelled',
        ];
        return $map[$status] ?? 'status-pending';
    }

    /**
     * Get status icon
     */
    function vacation_status_icon($status) {
        $map = [
            'Pending Approval' => '🟢',
            'Approved' => '🟢',
            'Ticket Processing' => '🟡',
            'Travelled' => '🟣',
            'On Vacation' => '🔵',
            'Returned' => '🟢',
            'Return Today' => '🟠',
            'Over Stayed' => '🔴',
            'Cancelled' => '⚫',
        ];
        return $map[$status] ?? '⚪';
    }

    /**
     * Calculate late days
     */
    function vacation_calculate_late_days($to_date, $actual_return) {
        if (empty($actual_return) || $actual_return === '0000-00-00') return 0;
        if (empty($to_date) || $to_date === '0000-00-00') return 0;
        $to = strtotime($to_date);
        $ret = strtotime($actual_return);
        if ($ret > $to) {
            return (int)(($ret - $to) / 86400);
        }
        return 0;
    }
}
