<?php
/* ─────────────────────────────────────────────
   Shared passport-alert logic — single source of truth.

   Mirrors visa_helper.php but for passport expiry. Used by the dashboard
   "Passport Expiring Soon" indicator and the Passport Expiring report so
   the count and the list always match.

   Rules:
     • Exclude employees who have resigned / left (re-uses the same active-
       employee filter as the visa alerts — visa_active_employee_filter()).
     • Window = within ONE month ahead. Already-expired passports are also
       included (an expired passport is even more urgent), exactly the way
       the visa alerts surface already-expired visas.
     • The employees table schema is flexible, so the passport-expiry
       column name is detected at runtime (passport_expiry /
       passport_expire_date / passport_Expire).
───────────────────────────────────────────── */

include_once 'visa_helper.php'; // re-use visa_active_employee_filter() & column cache

if (!function_exists('passport_expiry_column')) {
    /* Detect which passport-expiry column actually exists. Returns the
       column name (string) or null if none is present. */
    function passport_expiry_column($conn) {
        static $cache = false; // false = not resolved yet, null = none found
        if ($cache !== false) { return $cache; }
        $cols = visa_alert_columns($conn); // associative [field => true]
        foreach (['passport_expiry', 'passport_expire_date', 'passport_Expire'] as $candidate) {
            if (isset($cols[$candidate])) { $cache = $candidate; return $cache; }
        }
        $cache = null;
        return $cache;
    }
}

if (!function_exists('passport_alert_window_date')) {
    /* Upper bound of the alert window (1 month ahead, ISO date). */
    function passport_alert_window_date() {
        return date('Y-m-d', strtotime('+1 month'));
    }
}

if (!function_exists('passport_alert_query')) {
    /*
       Active employees whose passport is expired or expiring within one
       month, ordered soonest-first (expired at the top).
    */
    function passport_alert_query($conn) {
        $col = passport_expiry_column($conn);
        if (!$col) { return false; }
        $today  = date('Y-m-d');
        $window = mysqli_real_escape_string($conn, passport_alert_window_date());
        $filter = visa_active_employee_filter($conn, $today);
        return mysqli_query($conn, "
            SELECT *
            FROM employees
            WHERE `$col` IS NOT NULL
            AND `$col` != ''
            AND `$col` != '0000-00-00'
            AND `$col` <= '$window'
            $filter
            ORDER BY `$col` ASC
        ");
    }
}

if (!function_exists('passport_alert_count')) {
    /* Count of active employees with an expired / soon-expiring passport. */
    function passport_alert_count($conn) {
        $col = passport_expiry_column($conn);
        if (!$col) { return 0; }
        $today  = date('Y-m-d');
        $window = mysqli_real_escape_string($conn, passport_alert_window_date());
        $filter = visa_active_employee_filter($conn, $today);
        $res = mysqli_query($conn, "
            SELECT COUNT(*) AS total FROM employees
            WHERE `$col` IS NOT NULL
            AND `$col` != ''
            AND `$col` != '0000-00-00'
            AND `$col` <= '$window'
            $filter
        ");
        if (!$res) { return 0; }
        return (int)(mysqli_fetch_assoc($res)['total'] ?? 0);
    }
}
