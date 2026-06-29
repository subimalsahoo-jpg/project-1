<?php
/* ─────────────────────────────────────────────
   Shared helpers for the "After 6pm Duty Hours" feature (the second,
   separately-paid part of salary: After-6pm OT @1.25x + Sunday OT @1.5x).
   Same calculation as the salary sheet. Used by after6pm_duty.php,
   after6pm_slip.php and the Employee Overview Finance tab.
───────────────────────────────────────────── */

if (!function_exists('a6_esc')) {
    function a6_esc($conn, $v) { return mysqli_real_escape_string($conn, (string)$v); }
}
if (!function_exists('a6_money')) {
    function a6_money($a) { return number_format((float)$a, 0); }
}
if (!function_exists('a6_month_range')) {
    function a6_month_range($month) {
        return [date('Y-m-01', strtotime($month . '-01')), date('Y-m-t', strtotime($month . '-01'))];
    }
}
if (!function_exists('a6_time_to_seconds')) {
    function a6_time_to_seconds($value) {
        $value = trim((string)$value);
        if ($value === '' || $value === '00:00:00') return 0;
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) return 0;
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
    }
}

/* After-6pm OT from attendance (matches monthly_ot_hours in generate_salary.php) */
if (!function_exists('a6_attendance_ot')) {
    function a6_attendance_ot($conn, $user_no, $employee_id, $month) {
        [$ms, $me] = a6_month_range($month);
        $u = a6_esc($conn, $user_no); $e = a6_esc($conn, $employee_id);
        $cond = "user_no='$u'";
        if ($employee_id !== '') $cond = "(user_no='$u' OR employee_id='$e')";
        $res = mysqli_query($conn, "SELECT attendance_date, check_out FROM attendance
            WHERE $cond AND attendance_date BETWEEN '" . a6_esc($conn, $ms) . "' AND '" . a6_esc($conn, $me) . "'
            AND check_in IS NOT NULL AND TRIM(check_in)!='' AND check_out IS NOT NULL AND TRIM(check_out)!=''");
        $hours = 0;
        $normal_base = a6_time_to_seconds('18:20:00');
        $friday_base = a6_time_to_seconds('18:45:00');
        $grace = 120;
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $date = $row['attendance_date'] ?? '';
                if ($date === '') continue;
                $day = date('l', strtotime($date));
                if ($day === 'Sunday') continue;
                $out = a6_time_to_seconds($row['check_out'] ?? '');
                if ($day === 'Friday') {
                    if ($out >= (a6_time_to_seconds('19:45:00') - $grace)) $hours += floor((($out + $grace) - $friday_base) / 3600);
                } elseif ($out >= (a6_time_to_seconds('19:20:00') - $grace)) {
                    $hours += floor((($out + $grace) - $normal_base) / 3600);
                }
            }
        }
        return (float)$hours;
    }
}

/* Uploaded OT (overtime_records), optionally Sunday-only. */
if (!function_exists('a6_uploaded_ot')) {
    function a6_uploaded_ot($conn, $user_no, $employee_id, $month, $sunday_only = false) {
        $t = mysqli_query($conn, "SHOW TABLES LIKE 'overtime_records'");
        if (!$t || mysqli_num_rows($t) == 0) return 0;
        [$ms, $me] = a6_month_range($month);
        $u = a6_esc($conn, $user_no); $e = a6_esc($conn, $employee_id);
        $cond = "user_no='$u'";
        $ec = mysqli_query($conn, "SHOW COLUMNS FROM overtime_records LIKE 'employee_id'");
        if ($ec && mysqli_num_rows($ec) > 0 && $employee_id !== '') $cond = "(user_no='$u' OR employee_id='$e')";
        $sun = $sunday_only ? " AND DAYNAME(attendance_date)='Sunday'" : "";
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(ot_hours),0) AS total FROM overtime_records
            WHERE $cond AND attendance_date BETWEEN '" . a6_esc($conn, $ms) . "' AND '" . a6_esc($conn, $me) . "'$sun"));
        return (float)($row['total'] ?? 0);
    }
}

/* Full After-6pm + Sunday breakdown + amounts for one employee/month. */
if (!function_exists('a6_breakdown')) {
    function a6_breakdown($conn, $user_no, $employee_id, $basic, $month) {
        $att     = a6_attendance_ot($conn, $user_no, $employee_id, $month);
        $up_total = a6_uploaded_ot($conn, $user_no, $employee_id, $month, false);
        $sunday  = a6_uploaded_ot($conn, $user_no, $employee_id, $month, true);
        $nonsun  = max(0, $up_total - $sunday);
        $after6pm_hours = $att + $nonsun;
        $rate = ((float)$basic) / 30 / 8;
        $after6pm_amount = $rate * 1.25 * $after6pm_hours;
        $sunday_amount   = $rate * 1.5 * $sunday;
        return [
            'after6pm_hours'  => $after6pm_hours,
            'after6pm_amount' => $after6pm_amount,
            'sunday_hours'    => $sunday,
            'sunday_amount'   => $sunday_amount,
            'total'           => $after6pm_amount + $sunday_amount,
        ];
    }
}

/* Ensure the separate after-6pm salary records table exists. */
if (!function_exists('a6_ensure_table')) {
    function a6_ensure_table($conn) {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS after6pm_salary_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_no VARCHAR(50) NOT NULL,
            employee_id VARCHAR(50) DEFAULT '',
            salary_month VARCHAR(7) NOT NULL,
            basic_salary DECIMAL(10,2) DEFAULT 0,
            after6pm_hours DECIMAL(10,2) DEFAULT 0,
            after6pm_amount DECIMAL(10,2) DEFAULT 0,
            sunday_hours DECIMAL(10,2) DEFAULT 0,
            sunday_amount DECIMAL(10,2) DEFAULT 0,
            total_amount DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'Generated',
            created_by VARCHAR(100) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_month (user_no, salary_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

/* Fetch a saved after-6pm record (or null). */
if (!function_exists('a6_get_record')) {
    function a6_get_record($conn, $user_no, $month) {
        a6_ensure_table($conn);
        $u = a6_esc($conn, $user_no); $m = a6_esc($conn, $month);
        $r = mysqli_query($conn, "SELECT * FROM after6pm_salary_records WHERE user_no='$u' AND salary_month='$m' LIMIT 1");
        return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
    }
}
