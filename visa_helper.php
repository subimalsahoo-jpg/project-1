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
        $cols = visa_alert_columns($conn);
        $status_col = isset($cols['employee_status']) ? 'employee_status'
                    : (isset($cols['status']) ? 'status' : null);
        $today = mysqli_real_escape_string($conn, $today);

        $filter = '';
        if ($status_col) {
            $filter .= " AND ({$p}`$status_col` IS NULL OR {$p}`$status_col`=''"
                     . " OR LOWER({$p}`$status_col`) NOT IN ('resign','resigned','inactive','left','terminated','absconding','end of contract'))";
        }
        if (isset($cols['resign_date'])) {
            $filter .= " AND ({$p}resign_date IS NULL OR {$p}resign_date='' OR {$p}resign_date='0000-00-00'"
                     . " OR {$p}resign_date > '$today')";
        }
        return $filter;
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
