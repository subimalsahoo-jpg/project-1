<?php
/* ─────────────────────────────────────────────
   End-of-Service Gratuity helper (UAE Labour Law).

   Pure calculation helpers + data access for the gratuity report.
   Self-contained: no schema changes required. Reads the existing
   `employees` table (joining_date / resign_date / basic_salary) and the
   latest `employee_salary_records` row for the current basic wage.

   ── Legal basis (UAE Federal Decree-Law No. 33 of 2021, in force Feb 2022)
   For full-time employees on the unified (limited-term) contract:
     • Eligibility: at least 1 full year of continuous service.
     • Gratuity is based on the LAST BASIC WAGE (excludes allowances).
     • First 5 years : 21 calendar days' basic wage for each year.
     • After 5 years : 30 calendar days' basic wage for each extra year.
     • Total gratuity is capped at 2 years' (basic) wage.
     • Partial years are paid pro-rata.
   Daily wage uses the common UAE convention: monthly basic / 30.

   NOTE: Unpaid leave days should be excluded from service. We expose an
   optional $unpaid_days argument for that; callers may pass 0.
───────────────────────────────────────────── */

if (!defined('GRATUITY_DAYS_PER_YEAR_FIRST5')) {
    define('GRATUITY_DAYS_PER_YEAR_FIRST5', 21);
    define('GRATUITY_DAYS_PER_YEAR_AFTER5', 30);
    define('GRATUITY_DAYS_IN_MONTH', 30);      // daily wage = basic / 30
    define('GRATUITY_MIN_SERVICE_DAYS', 365);  // 1 full year to qualify
    define('GRATUITY_CAP_MONTHS', 24);         // capped at 2 years' basic wage
}

if (!function_exists('gratuity_parse_date')) {
    /* Normalise a date-ish value into a DateTime (00:00) or null. */
    function gratuity_parse_date($value) {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }
        // Prefer the app's own normaliser when available (handles dmy/mdy).
        if (function_exists('normalize_input_date')) {
            $iso = normalize_input_date($value);
            if ($iso !== '') {
                $value = $iso;
            }
        }
        try {
            $dt = new DateTime($value);
            $dt->setTime(0, 0, 0);
            return $dt;
        } catch (Exception $e) {
            $ts = strtotime($value);
            if ($ts === false) {
                return null;
            }
            $dt = new DateTime('@' . $ts);
            $dt->setTime(0, 0, 0);
            return $dt;
        }
    }
}

if (!function_exists('gratuity_service_days')) {
    /* Inclusive service span in days between joining and end date. */
    function gratuity_service_days($joining_date, $end_date, $unpaid_days = 0) {
        $start = gratuity_parse_date($joining_date);
        $end   = gratuity_parse_date($end_date);
        if (!$start || !$end || $end < $start) {
            return 0;
        }
        $days = (int)$start->diff($end)->days;
        $days -= max(0, (int)$unpaid_days);
        return max(0, $days);
    }
}

if (!function_exists('gratuity_format_duration')) {
    /* Human-readable "Xy Ym Zd" from a whole number of service days. */
    function gratuity_format_duration($total_days) {
        $total_days = max(0, (int)$total_days);
        $years  = intdiv($total_days, 365);
        $rem    = $total_days % 365;
        $months = intdiv($rem, 30);
        $days   = $rem % 30;
        $parts = [];
        if ($years)  { $parts[] = $years . 'y'; }
        if ($months) { $parts[] = $months . 'm'; }
        if ($days || !$parts) { $parts[] = $days . 'd'; }
        return implode(' ', $parts);
    }
}

if (!function_exists('calculate_gratuity')) {
    /*
       Core calculation. Returns a detailed breakdown array.

       @param float        $basic_salary  Last monthly basic wage (AED).
       @param string       $joining_date  Date of joining.
       @param string       $end_date      Last working day (resign date or "as of" date).
       @param int          $unpaid_days   Unpaid leave days to exclude (default 0).

       @return array{
         eligible:bool, reason:string,
         basic_salary:float, daily_wage:float,
         service_days:int, service_years:float, duration_label:string,
         first5_years:float, first5_days:float, first5_amount:float,
         after5_years:float, after5_days:float, after5_amount:float,
         gratuity_raw:float, cap_amount:float, capped:bool, gratuity:float
       }
    */
    function calculate_gratuity($basic_salary, $joining_date, $end_date = null, $unpaid_days = 0) {
        $basic_salary = max(0.0, (float)$basic_salary);
        if ($end_date === null || trim((string)$end_date) === '') {
            $end_date = date('Y-m-d');
        }

        $service_days  = gratuity_service_days($joining_date, $end_date, $unpaid_days);
        $service_years = $service_days / 365.0;
        $daily_wage    = $basic_salary / GRATUITY_DAYS_IN_MONTH;

        $result = [
            'eligible'       => false,
            'reason'         => '',
            'basic_salary'   => round($basic_salary, 2),
            'daily_wage'     => round($daily_wage, 2),
            'service_days'   => $service_days,
            'service_years'  => round($service_years, 2),
            'duration_label' => gratuity_format_duration($service_days),
            'first5_years'   => 0.0,
            'first5_days'    => 0.0,
            'first5_amount'  => 0.0,
            'after5_years'   => 0.0,
            'after5_days'    => 0.0,
            'after5_amount'  => 0.0,
            'gratuity_raw'   => 0.0,
            'cap_amount'     => round($basic_salary * GRATUITY_CAP_MONTHS, 2),
            'capped'         => false,
            'gratuity'       => 0.0,
        ];

        if ($basic_salary <= 0) {
            $result['reason'] = 'No basic salary on record.';
            return $result;
        }
        if ($service_days < GRATUITY_MIN_SERVICE_DAYS) {
            $result['reason'] = 'Less than 1 year of service (not eligible).';
            return $result;
        }

        // First 5 years at 21 days/year, pro-rata.
        $first5_years = min($service_years, 5.0);
        $first5_days  = $first5_years * GRATUITY_DAYS_PER_YEAR_FIRST5;
        $first5_amount = $first5_days * $daily_wage;

        // Beyond 5 years at 30 days/year, pro-rata.
        $after5_years = max(0.0, $service_years - 5.0);
        $after5_days  = $after5_years * GRATUITY_DAYS_PER_YEAR_AFTER5;
        $after5_amount = $after5_days * $daily_wage;

        $gratuity_raw = $first5_amount + $after5_amount;
        $cap_amount   = $basic_salary * GRATUITY_CAP_MONTHS;
        $capped       = $gratuity_raw > $cap_amount;
        $gratuity     = $capped ? $cap_amount : $gratuity_raw;

        $result['eligible']      = true;
        $result['first5_years']  = round($first5_years, 2);
        $result['first5_days']   = round($first5_days, 1);
        $result['first5_amount'] = round($first5_amount, 2);
        $result['after5_years']  = round($after5_years, 2);
        $result['after5_days']   = round($after5_days, 1);
        $result['after5_amount'] = round($after5_amount, 2);
        $result['gratuity_raw']  = round($gratuity_raw, 2);
        $result['capped']        = $capped;
        $result['gratuity']      = round($gratuity, 2);
        return $result;
    }
}

if (!function_exists('gratuity_table_columns')) {
    function gratuity_table_columns($conn, $table) {
        $columns = [];
        $safe = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$safe`");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $columns[$row['Field']] = true;
            }
        }
        return $columns;
    }
}

if (!function_exists('gratuity_pick')) {
    /* First non-empty value among $keys, else $default. */
    function gratuity_pick($row, $keys, $default = '') {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return $row[$key];
            }
        }
        return $default;
    }
}

if (!function_exists('gratuity_current_basic')) {
    /*
       Resolve the employee's current basic wage the same way the salary
       sheet does: latest employee_salary_records row (salary_month <= $month)
       merged over the employees row, then pick basic_salary.
    */
    function gratuity_current_basic($conn, $user_no, $employee_row, $month = null) {
        $month = $month ?: date('Y-m');
        $safe_user = mysqli_real_escape_string($conn, (string)$user_no);
        $safe_month = mysqli_real_escape_string($conn, $month);

        $setup = [];
        $q = mysqli_query($conn, "
            SELECT * FROM employee_salary_records
            WHERE user_no='$safe_user' AND salary_month <= '$safe_month'
            ORDER BY salary_month DESC
            LIMIT 1
        ");
        if ($q && mysqli_num_rows($q) > 0) {
            $setup = mysqli_fetch_assoc($q);
        }
        $source = array_merge((array)$employee_row, (array)$setup);
        return (float)gratuity_pick($source, ['basic_salary'], 0);
    }
}
