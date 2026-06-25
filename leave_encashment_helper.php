<?php
/* ─────────────────────────────────────────────
   Leave Encashment helper (UAE).

   Pays an employee for un-used / accrued ANNUAL leave days.
   UAE convention: daily wage = monthly BASIC salary / 30, and
   encashment amount = daily wage × encashed days.

   Self-contained: creates its own `leave_encashment` table on first use.
   Reuses gratuity_helper for the current basic wage and service-days math.

   Leave balance (days) for an employee is computed as:
       accrued (entitlement)  −  paid annual leave already taken
                              −  days already encashed before
   • Entitlement: 30 calendar days per completed year of service,
     pro-rata (≈ 2.5 days / month). UAE Labour Law gives 30 days/yr
     after one year (2 days/month between 6 months and 1 year).
   • Taken: sum of PAID leave days recorded in the `vacations` table.
   • Already encashed: sum of encash_days in previous encashment rows.

   The computed balance is only a SUGGESTION — the form lets HR override
   the days/amount (Auto Calculate = No).
───────────────────────────────────────────── */

include_once 'gratuity_helper.php'; // gratuity_current_basic(), gratuity_service_days(), gratuity_parse_date()

if (!defined('LE_DAYS_PER_YEAR')) {
    define('LE_DAYS_PER_YEAR', 30);   // annual leave entitlement (calendar days)
    define('LE_DAYS_IN_MONTH', 30);   // daily wage = basic / 30
}

if (!function_exists('le_esc')) {
    function le_esc($conn, $v) { return mysqli_real_escape_string($conn, trim((string)$v)); }
}

if (!function_exists('le_ensure_schema')) {
    /* Create the leave_encashment table and add any missing columns. */
    function le_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS leave_encashment (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_no VARCHAR(50) NOT NULL,
                employee_id VARCHAR(50) DEFAULT '',
                employee_name VARCHAR(150) DEFAULT '',
                basic_salary DECIMAL(10,2) DEFAULT 0,
                daily_wage DECIMAL(10,2) DEFAULT 0,
                leave_balance_days DECIMAL(6,1) DEFAULT 0,
                encash_days DECIMAL(6,1) DEFAULT 0,
                encash_amount DECIMAL(10,2) DEFAULT 0,
                encashment_month VARCHAR(7) DEFAULT '',
                auto_calculate VARCHAR(3) DEFAULT 'Yes',
                status VARCHAR(20) DEFAULT 'Pending',
                remarks VARCHAR(255) DEFAULT '',
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Defensive: add columns if an older table already existed.
        $cols = [
            'employee_id'        => "VARCHAR(50) DEFAULT ''",
            'employee_name'      => "VARCHAR(150) DEFAULT ''",
            'basic_salary'       => "DECIMAL(10,2) DEFAULT 0",
            'daily_wage'         => "DECIMAL(10,2) DEFAULT 0",
            'leave_balance_days' => "DECIMAL(6,1) DEFAULT 0",
            'encash_days'        => "DECIMAL(6,1) DEFAULT 0",
            'encash_amount'      => "DECIMAL(10,2) DEFAULT 0",
            'encashment_month'   => "VARCHAR(7) DEFAULT ''",
            'auto_calculate'     => "VARCHAR(3) DEFAULT 'Yes'",
            'status'             => "VARCHAR(20) DEFAULT 'Pending'",
            'remarks'            => "VARCHAR(255) DEFAULT ''",
            'created_by'         => "VARCHAR(100) DEFAULT ''",
            'created_at'         => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ];
        foreach ($cols as $name => $def) {
            $chk = mysqli_query($conn, "SHOW COLUMNS FROM leave_encashment LIKE '" . le_esc($conn, $name) . "'");
            if ($chk && mysqli_num_rows($chk) === 0) {
                mysqli_query($conn, "ALTER TABLE leave_encashment ADD `$name` $def");
            }
        }
    }
}

if (!function_exists('le_daily_wage')) {
    /* UAE daily wage = monthly basic / 30. */
    function le_daily_wage($basic_salary) {
        return round(max(0.0, (float)$basic_salary) / LE_DAYS_IN_MONTH, 2);
    }
}

if (!function_exists('le_accrued_days')) {
    /* Annual-leave days accrued from joining date to today (pro-rata 30/yr). */
    function le_accrued_days($conn, $employee_row, $as_of = null) {
        $joining = gratuity_pick($employee_row, ['joining_date', 'date_of_joining', 'doj'], '');
        if ($joining === '') { return 0.0; }
        $as_of = $as_of ?: date('Y-m-d');
        $service_days = gratuity_service_days($joining, $as_of, 0);
        return round(($service_days / 365.0) * LE_DAYS_PER_YEAR, 1);
    }
}

if (!function_exists('le_taken_days')) {
    /* Paid leave days already taken (from the vacations table). */
    function le_taken_days($conn, $user_no) {
        $u = le_esc($conn, $user_no);
        // Count only PAID leaves; unpaid leaves (e.g. swap-absent) are excluded.
        $effective_to = function_exists('vacation_effective_to_sql')
            ? vacation_effective_to_sql()
            : "to_date";
        $q = mysqli_query($conn, "
            SELECT COALESCE(SUM(DATEDIFF($effective_to, from_date) + 1), 0) AS taken
            FROM vacations
            WHERE user_no='$u'
            AND from_date IS NOT NULL AND from_date != '' AND from_date != '0000-00-00'
            AND (paid_status IS NULL OR paid_status='' OR LOWER(paid_status) NOT IN ('unpaid'))
        ");
        if (!$q) { return 0.0; }
        return round((float)(mysqli_fetch_assoc($q)['taken'] ?? 0), 1);
    }
}

if (!function_exists('le_already_encashed_days')) {
    /* Days already encashed in previous records (so the balance reduces). */
    function le_already_encashed_days($conn, $user_no) {
        $u = le_esc($conn, $user_no);
        $q = mysqli_query($conn, "SELECT COALESCE(SUM(encash_days),0) AS d FROM leave_encashment WHERE user_no='$u'");
        if (!$q) { return 0.0; }
        return round((float)(mysqli_fetch_assoc($q)['d'] ?? 0), 1);
    }
}

if (!function_exists('le_leave_balance')) {
    /*
       Suggested current leave balance in days:
         accrued − paid leave taken − already encashed   (never below 0).
       Returns a detail array.
    */
    function le_leave_balance($conn, $user_no, $employee_row, $as_of = null) {
        $accrued  = le_accrued_days($conn, $employee_row, $as_of);
        $taken    = le_taken_days($conn, $user_no);
        $encashed = le_already_encashed_days($conn, $user_no);
        $balance  = max(0.0, round($accrued - $taken - $encashed, 1));
        return [
            'accrued'  => $accrued,
            'taken'    => $taken,
            'encashed' => $encashed,
            'balance'  => $balance,
        ];
    }
}

if (!function_exists('le_calc_amount')) {
    /* Encashment amount = daily wage × encash days. */
    function le_calc_amount($basic_salary, $encash_days) {
        return round(le_daily_wage($basic_salary) * max(0.0, (float)$encash_days), 2);
    }
}
