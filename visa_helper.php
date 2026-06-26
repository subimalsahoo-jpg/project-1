<?php
/* ─────────────────────────────────────────────
   Shared visa-alert logic — single source of truth.

   Used by the dashboard, the employee list badge/popup, and the Visa
   Expiring report so every visa count and list always matches.

   Rules:
     • Exclude employees who have resigned / left (their visa may still be
       valid, but they are no longer employed). "Left" = resign_date in the
       past, or status resigned/inactive/left/terminated.
     • Include visas that are ALREADY expired as well as those expiring
       within the alert window (renewal in progress, or renewed but not yet
       entered into the system).
   The employees table schema is flexible, so we only filter on columns
   that actually exist.
───────────────────────────────────────────── */

if (!function_exists('visa_alert_columns')) {
    function visa_alert_columns($conn) {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $cols = [];
        $res = mysqli_query($conn, "SHOW COLUMNS FROM employees");
        if ($res) {
            while ($c = mysqli_fetch_assoc($res)) { $cols[$c['Field']] = true; }
        }
        $cache = $cols;
        return $cols;
    }
}

if (!function_exists('visa_alert_window_date')) {
    /* Upper bound of the alert window (3 months ahead, ISO date). */
    function visa_alert_window_date() {
        return date('Y-m-d', strtotime('+3 months'));
    }
}

if (!function_exists('visa_active_employee_filter')) {
    /*
       SQL AND-clause that removes resigned/left employees.
       @param string $today  Y-m-d (already "now").
       @param string $alias  optional table alias, e.g. 'e' for joins.
    */
    function visa_active_employee_filter($conn, $today, $alias = '') {
        $p = $alias !== '' ? $alias . '.' : '';
        // "Departed" is determined by VISA CANCELLATION being Completed.
        // Notice-period / resigned-but-not-yet-cancelled employees are still
        // on duty, so they must still appear (visa alerts, reports). Only
        // exclude employees whose visa cancellation status is 'Completed'.
        $vt = mysqli_query($conn, "SHOW TABLES LIKE 'visa_cancellations'");
        if ($vt && mysqli_num_rows($vt) > 0) {
            return " AND {$p}user_no NOT IN (SELECT user_no FROM visa_cancellations WHERE cancellation_status='Completed')";
        }
        return '';
    }
}

if (!function_exists('visa_alert_query')) {
    /*
       Full result of active employees whose visa is expired or expiring
       within the window, ordered soonest-first (expired at the top).
    */
    function visa_alert_query($conn) {
        $today  = date('Y-m-d');
        $window = mysqli_real_escape_string($conn, visa_alert_window_date());
        $filter = visa_active_employee_filter($conn, $today);
        return mysqli_query($conn, "
            SELECT *
            FROM employees
            WHERE visa_expiry_date IS NOT NULL
            AND visa_expiry_date != ''
            AND visa_expiry_date != '0000-00-00'
            AND visa_expiry_date <= '$window'
            $filter
            ORDER BY visa_expiry_date ASC
        ");
    }
}

if (!function_exists('visa_alert_count')) {
    /* Count of active employees with an expired / soon-expiring visa. */
    function visa_alert_count($conn) {
        $today  = date('Y-m-d');
        $window = mysqli_real_escape_string($conn, visa_alert_window_date());
        $filter = visa_active_employee_filter($conn, $today);
        $res = mysqli_query($conn, "
            SELECT COUNT(*) AS total FROM employees
            WHERE visa_expiry_date IS NOT NULL
            AND visa_expiry_date != ''
            AND visa_expiry_date != '0000-00-00'
            AND visa_expiry_date <= '$window'
            $filter
        ");
        if (!$res) { return 0; }
        return (int)(mysqli_fetch_assoc($res)['total'] ?? 0);
    }
}


if (!function_exists('visa_expired_list')) {
    /* Active (not resigned/left) employees whose visa has ALREADY expired —
       used for the dashboard's scrolling red reminder ribbon. */
    function visa_expired_list($conn) {
        $today = date('Y-m-d');
        $filter = visa_active_employee_filter($conn, $today);
        $rows = [];
        $res = mysqli_query($conn, "
            SELECT * FROM employees
            WHERE visa_expiry_date IS NOT NULL
            AND visa_expiry_date != ''
            AND visa_expiry_date != '0000-00-00'
            AND visa_expiry_date < '$today'
            $filter
            ORDER BY visa_expiry_date ASC
        ");
        if ($res) { while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; } }
        return $rows;
    }
}
