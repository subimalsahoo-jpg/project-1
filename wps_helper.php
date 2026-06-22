<?php
/* ─────────────────────────────────────────────
   UAE Wage Protection System (WPS) — SIF generation helper.

   Produces the Salary Information File (SIF) consumed by UAE banks /
   exchange houses and reconciled by the Central Bank + MOHRE.

   ── File structure (comma-delimited text, one record per line) ─────────
   The file is a list of EDR (Employee Detail Record) lines followed by a
   single SCR (Salary Control Record) trailer line:

     EDR,<EmpUniqueID>,<RoutingCode>,<IBAN>,<PayStart>,<PayEnd>,<Days>,<Fixed>,<Variable>,<LeaveDays>
     ...
     SCR,<EstablishmentID>,<EmployerRouting>,<FileDate>,<FileTime>,<SalaryMonth>,<EDRCount>,<TotalSalary>,<Currency>

   ── Identifiers (validated) ────────────────────────────────────────────
     • Employer Establishment ID  : 13 digits (MOHRE)
     • Employee Unique ID         : 14 digits (labour card / personal no.)
     • Routing code (bank/agent)  : 9 digits
     • Employee account           : UAE IBAN, "AE" + 21 digits (23 chars)
     • Amounts                    : 2 decimals, no separators, no symbol
     • Currency                   : AED

   IMPORTANT: WPS agents (banks/exchanges) have minor layout/date-format
   variations. The format is centralised in the constants below and is
   adjustable from the WPS Settings panel so the output can be matched to
   the specific agent's published spec.

   ── Income mapping (reconciles to the actual bank transfer) ────────────
   Fixed + Variable income reported per employee equals the NET amount
   transferred (net_payable). Deductions are applied to the variable
   portion first, then the fixed portion, keeping both non-negative.
───────────────────────────────────────────── */

if (!defined('WPS_DATE_FORMAT')) {
    define('WPS_DATE_FORMAT', 'Y-m-d'); // pay start / pay end / file date
    define('WPS_TIME_FORMAT', 'Hi');    // file creation time (HHMM)
    define('WPS_MONTH_FORMAT', 'Ym');   // salary month (YYYYMM)
    define('WPS_CURRENCY', 'AED');
    define('WPS_EDR_TAG', 'EDR');
    define('WPS_SCR_TAG', 'SCR');
    define('WPS_FIELD_SEP', ',');
    define('WPS_LINE_SEP', "\r\n");     // CRLF per bank file conventions
    // Expected identifier lengths (warn, do not hard-block, on mismatch).
    define('WPS_LEN_ESTABLISHMENT', 13);
    define('WPS_LEN_EMP_UNIQUE_ID', 14);
    define('WPS_LEN_ROUTING', 9);
    define('WPS_LEN_IBAN', 23);
}

/* ── Schema ─────────────────────────────────────────────────────────── */
if (!function_exists('wps_columns_of')) {
    function wps_columns_of($conn, $table) {
        $cols = [];
        $safe = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$safe`");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) { $cols[$row['Field']] = true; }
        }
        return $cols;
    }
}

if (!function_exists('wps_add_column')) {
    function wps_add_column($conn, $table, $column, $definition) {
        $cols = wps_columns_of($conn, $table);
        if (!isset($cols[$column])) {
            $safeT = mysqli_real_escape_string($conn, $table);
            mysqli_query($conn, "ALTER TABLE `$safeT` ADD COLUMN `$column` $definition");
        }
    }
}

if (!function_exists('wps_ensure_schema')) {
    /* Create the WPS settings table and add the employee bank columns. */
    function wps_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS wps_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                establishment_id   VARCHAR(20)  DEFAULT '',
                employer_routing   VARCHAR(20)  DEFAULT '',
                employer_bank_name VARCHAR(120) DEFAULT '',
                date_format        VARCHAR(20)  DEFAULT 'Y-m-d',
                updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Seed a single settings row if empty.
        $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM wps_settings");
        $row = $res ? mysqli_fetch_assoc($res) : ['c' => 1];
        if ((int)($row['c'] ?? 0) === 0) {
            mysqli_query($conn, "INSERT INTO wps_settings (establishment_id) VALUES ('')");
        }

        // Per-employee bank / WPS identifiers.
        if (!empty(wps_columns_of($conn, 'employees'))) {
            wps_add_column($conn, 'employees', 'iban', "VARCHAR(34) DEFAULT ''");
            wps_add_column($conn, 'employees', 'bank_routing_code', "VARCHAR(20) DEFAULT ''");
            wps_add_column($conn, 'employees', 'bank_name', "VARCHAR(120) DEFAULT ''");
            wps_add_column($conn, 'employees', 'mol_personal_id', "VARCHAR(20) DEFAULT ''");
            wps_add_column($conn, 'employees', 'wps_exempt', "TINYINT(1) DEFAULT 0");
        }
    }
}

if (!function_exists('wps_get_settings')) {
    function wps_get_settings($conn) {
        $defaults = [
            'id' => 0, 'establishment_id' => '', 'employer_routing' => '',
            'employer_bank_name' => '', 'date_format' => 'Y-m-d',
        ];
        $res = mysqli_query($conn, "SELECT * FROM wps_settings ORDER BY id ASC LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            return array_merge($defaults, mysqli_fetch_assoc($res));
        }
        return $defaults;
    }
}

if (!function_exists('wps_save_settings')) {
    function wps_save_settings($conn, $data) {
        $allowed_formats = ['Y-m-d', 'd/m/Y', 'Ymd', 'd-m-Y'];
        $fmt = in_array($data['date_format'] ?? '', $allowed_formats, true) ? $data['date_format'] : 'Y-m-d';
        $est  = mysqli_real_escape_string($conn, trim($data['establishment_id'] ?? ''));
        $rout = mysqli_real_escape_string($conn, trim($data['employer_routing'] ?? ''));
        $bank = mysqli_real_escape_string($conn, trim($data['employer_bank_name'] ?? ''));
        $fmtE = mysqli_real_escape_string($conn, $fmt);

        $res = mysqli_query($conn, "SELECT id FROM wps_settings ORDER BY id ASC LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $id = (int)mysqli_fetch_assoc($res)['id'];
            return mysqli_query($conn, "
                UPDATE wps_settings
                SET establishment_id='$est', employer_routing='$rout',
                    employer_bank_name='$bank', date_format='$fmtE'
                WHERE id=$id
            ");
        }
        return mysqli_query($conn, "
            INSERT INTO wps_settings (establishment_id, employer_routing, employer_bank_name, date_format)
            VALUES ('$est','$rout','$bank','$fmtE')
        ");
    }
}

/* ── Validation ─────────────────────────────────────────────────────── */
if (!function_exists('wps_is_digits')) {
    function wps_is_digits($value, $len = null) {
        $value = trim((string)$value);
        if (!preg_match('/^\d+$/', $value)) { return false; }
        if ($len !== null && strlen($value) !== (int)$len) { return false; }
        return true;
    }
}

if (!function_exists('wps_validate_iban')) {
    /* Validate a UAE IBAN: format + ISO 13616 mod-97 checksum. */
    function wps_validate_iban($iban) {
        $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
        if ($iban === '') { return [false, 'IBAN is missing']; }
        if (!preg_match('/^AE\d{21}$/', $iban)) {
            return [false, 'UAE IBAN must be "AE" followed by 21 digits (23 chars)'];
        }
        // Move the 4 leading chars to the end, convert letters to numbers.
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        for ($i = 0, $n = strlen($rearranged); $i < $n; $i++) {
            $ch = $rearranged[$i];
            $numeric .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        }
        // mod 97 over a long numeric string, processed in chunks.
        $remainder = 0;
        for ($i = 0, $n = strlen($numeric); $i < $n; $i += 7) {
            $remainder = (int)(($remainder . substr($numeric, $i, 7)) % 97);
        }
        if ($remainder !== 1) {
            return [false, 'IBAN checksum is invalid'];
        }
        return [true, ''];
    }
}

/* ── Formatting ─────────────────────────────────────────────────────── */
if (!function_exists('wps_amount')) {
    function wps_amount($value) {
        return number_format(round((float)$value, 2), 2, '.', '');
    }
}

if (!function_exists('wps_clean_field')) {
    /* Strip the field separator / newlines so a value can't break the row. */
    function wps_clean_field($value) {
        $value = (string)$value;
        $value = str_replace([",", "\r", "\n"], [' ', ' ', ' '], $value);
        return trim($value);
    }
}

if (!function_exists('wps_split_income')) {
    /*
       Split the paid amount into Fixed + Variable so they sum to net pay.
       @return array{fixed:float, variable:float}
    */
    function wps_split_income($gross_fixed, $gross_variable, $net_paid) {
        $gross_fixed    = max(0.0, (float)$gross_fixed);
        $gross_variable = max(0.0, (float)$gross_variable);
        $net_paid       = max(0.0, (float)$net_paid);

        $gross_total = $gross_fixed + $gross_variable;
        $deductions  = max(0.0, $gross_total - $net_paid);

        // Apply deductions to variable first, then to fixed.
        $variable = max(0.0, $gross_variable - $deductions);
        $remaining = max(0.0, $deductions - $gross_variable);
        $fixed = max(0.0, $gross_fixed - $remaining);

        // Guard against rounding drift: force fixed+variable == net_paid.
        $sum = $fixed + $variable;
        if ($sum > 0 && abs($sum - $net_paid) >= 0.005) {
            $scale = $net_paid / $sum;
            $fixed *= $scale;
            $variable *= $scale;
        } elseif ($sum <= 0 && $net_paid > 0) {
            $fixed = $net_paid; // all in fixed if no components recorded
        }
        return ['fixed' => round($fixed, 2), 'variable' => round($variable, 2)];
    }
}

/* ── Data collection ───────────────────────────────────────────────── */
if (!function_exists('wps_pick')) {
    function wps_pick($row, $keys, $default = '') {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') { return $row[$k]; }
        }
        return $default;
    }
}

if (!function_exists('wps_collect_rows')) {
    /*
       Build the per-employee dataset for a salary month (format YYYY-MM).
       Joins generated salary records with employee bank details, computes
       the income split, and runs validation. Returns a list of rows:
         user_no, name, mol_id, iban, routing, bank_name, days, leave_days,
         pay_method, fixed, variable, total, included(bool), errors[], warnings[]
    */
    function wps_collect_rows($conn, $month, $settings) {
        $safe_month = mysqli_real_escape_string($conn, $month);
        $emp_cols = wps_columns_of($conn, 'employees');
        $name_col = isset($emp_cols['full_name']) ? 'full_name' : (isset($emp_cols['name']) ? 'name' : 'user_no');

        $sql = "
            SELECT s.*, e.$name_col AS emp_name,
                   e.iban AS e_iban, e.bank_routing_code AS e_routing,
                   e.bank_name AS e_bank, e.mol_personal_id AS e_mol,
                   e.wps_exempt AS e_exempt
            FROM employee_salary_records s
            LEFT JOIN employees e ON e.user_no = s.user_no
            WHERE s.salary_month = '$safe_month'
            ORDER BY CAST(s.user_no AS UNSIGNED) ASC, s.user_no ASC
        ";
        $res = mysqli_query($conn, $sql);
        $rows = [];
        $month_days = (int)date('t', strtotime($month . '-01'));

        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $status = strtolower((string)wps_pick($r, ['salary_status'], ''));
                $net = (float)wps_pick($r, ['net_payable', 'net_salary', 'total_salary'], 0);

                // Gross fixed = earned basic + earned allowances + food allowances.
                $gross_fixed = (float)wps_pick($r, ['salary_earned'], 0)
                             + (float)wps_pick($r, ['allowance_earned'], 0)
                             + (float)wps_pick($r, ['food_allowance_company'], 0)
                             + (float)wps_pick($r, ['food_allowance_won'], 0);
                // Gross variable = all overtime components.
                $gross_variable = (float)wps_pick($r, ['ot_amount'], 0);
                if ($gross_variable <= 0) {
                    $gross_variable = (float)wps_pick($r, ['regular_ot_amount'], 0)
                                    + (float)wps_pick($r, ['after6pm_ot_amount'], 0)
                                    + (float)wps_pick($r, ['extra_ot_amount'], 0);
                }

                $split = wps_split_income($gross_fixed, $gross_variable, $net);
                $pay_method = strtolower((string)wps_pick($r, ['salary_by'], 'bank'));
                $is_exempt = (int)wps_pick($r, ['e_exempt'], 0) === 1;

                $iban = strtoupper(preg_replace('/\s+/', '', (string)wps_pick($r, ['e_iban'], '')));
                $routing = trim((string)wps_pick($r, ['e_routing'], ''));
                $mol = trim((string)wps_pick($r, ['e_mol'], ''));

                $errors = [];
                $warnings = [];

                // Validation (errors block inclusion; warnings are advisory).
                if ($mol === '') {
                    $errors[] = 'Missing Employee Unique ID (labour card / personal no.)';
                } elseif (!wps_is_digits($mol, WPS_LEN_EMP_UNIQUE_ID)) {
                    $warnings[] = 'Employee Unique ID is usually ' . WPS_LEN_EMP_UNIQUE_ID . ' digits';
                }
                [$iban_ok, $iban_msg] = wps_validate_iban($iban);
                if (!$iban_ok) { $errors[] = $iban_msg; }
                if ($routing === '') {
                    $errors[] = 'Missing bank routing code';
                } elseif (!wps_is_digits($routing, WPS_LEN_ROUTING)) {
                    $warnings[] = 'Routing code is usually ' . WPS_LEN_ROUTING . ' digits';
                }

                $included = true;
                $skip_reason = '';
                if ($is_exempt) { $included = false; $skip_reason = 'Marked WPS-exempt'; }
                elseif ($status === 'unpaid' || $net <= 0) { $included = false; $skip_reason = 'No net pay / unpaid'; }
                elseif ($pay_method === 'cash') { $included = false; $skip_reason = 'Paid in cash (not via WPS)'; }
                elseif (!empty($errors)) { $included = false; $skip_reason = 'Validation errors'; }

                $rows[] = [
                    'user_no'    => (string)wps_pick($r, ['user_no'], ''),
                    'name'       => (string)wps_pick($r, ['emp_name'], ''),
                    'mol_id'     => $mol,
                    'iban'       => $iban,
                    'routing'    => $routing,
                    'bank_name'  => (string)wps_pick($r, ['e_bank'], ''),
                    'pay_method' => $pay_method,
                    'status'     => (string)wps_pick($r, ['salary_status'], ''),
                    'days'       => $month_days,
                    'leave_days' => 0,
                    'fixed'      => $split['fixed'],
                    'variable'   => $split['variable'],
                    'total'      => round($split['fixed'] + $split['variable'], 2),
                    'included'   => $included,
                    'skip_reason'=> $skip_reason,
                    'errors'     => $errors,
                    'warnings'   => $warnings,
                ];
            }
        }
        return $rows;
    }
}

/* ── SIF builders ──────────────────────────────────────────────────── */
if (!function_exists('wps_build_edr')) {
    function wps_build_edr($row, $month, $date_format) {
        $start = date($date_format, strtotime($month . '-01'));
        $end   = date($date_format, strtotime(date('Y-m-t', strtotime($month . '-01'))));
        return implode(WPS_FIELD_SEP, [
            WPS_EDR_TAG,
            wps_clean_field($row['mol_id']),
            wps_clean_field($row['routing']),
            wps_clean_field($row['iban']),
            $start,
            $end,
            (int)$row['days'],
            wps_amount($row['fixed']),
            wps_amount($row['variable']),
            (int)$row['leave_days'],
        ]);
    }
}

if (!function_exists('wps_build_scr')) {
    function wps_build_scr($settings, $month, $edr_count, $total_salary, $now_ts, $date_format) {
        return implode(WPS_FIELD_SEP, [
            WPS_SCR_TAG,
            wps_clean_field($settings['establishment_id']),
            wps_clean_field($settings['employer_routing']),
            date($date_format, $now_ts),
            date(WPS_TIME_FORMAT, $now_ts),
            date(WPS_MONTH_FORMAT, strtotime($month . '-01')),
            (int)$edr_count,
            wps_amount($total_salary),
            WPS_CURRENCY,
        ]);
    }
}

if (!function_exists('wps_build_sif')) {
    /*
       Build the complete SIF text from collected rows.
       @return array{content:string, count:int, total:float, lines:array}
    */
    function wps_build_sif($rows, $month, $settings, $now_ts = null) {
        $now_ts = $now_ts ?: time();
        $date_format = $settings['date_format'] ?: WPS_DATE_FORMAT;
        $lines = [];
        $count = 0;
        $total = 0.0;
        foreach ($rows as $row) {
            if (empty($row['included'])) { continue; }
            $lines[] = wps_build_edr($row, $month, $date_format);
            $count++;
            $total += (float)$row['total'];
        }
        $scr = wps_build_scr($settings, $month, $count, $total, $now_ts, $date_format);
        $all = array_merge($lines, [$scr]);
        return [
            'content' => implode(WPS_LINE_SEP, $all) . WPS_LINE_SEP,
            'count'   => $count,
            'total'   => round($total, 2),
            'lines'   => $all,
        ];
    }
}

if (!function_exists('wps_filename')) {
    function wps_filename($settings, $month, $now_ts = null) {
        $now_ts = $now_ts ?: time();
        $est = preg_replace('/[^0-9A-Za-z]/', '', (string)$settings['establishment_id']);
        $est = $est !== '' ? $est : 'WPS';
        return $est . '_' . date('Ym', strtotime($month . '-01')) . '_' . date('YmdHis', $now_ts) . '.sif';
    }
}

if (!function_exists('wps_validate_settings')) {
    /* Returns a list of employer-level configuration problems (blocking). */
    function wps_validate_settings($settings) {
        $problems = [];
        $est = trim((string)$settings['establishment_id']);
        $rout = trim((string)$settings['employer_routing']);
        if ($est === '') {
            $problems[] = 'Employer Establishment ID is not set (WPS Settings).';
        } elseif (!wps_is_digits($est, WPS_LEN_ESTABLISHMENT)) {
            $problems[] = 'Establishment ID should be ' . WPS_LEN_ESTABLISHMENT . ' digits.';
        }
        if ($rout === '') {
            $problems[] = 'Employer bank routing code is not set (WPS Settings).';
        }
        return $problems;
    }
}
