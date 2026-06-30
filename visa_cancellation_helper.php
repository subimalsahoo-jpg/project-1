<?php
/* ─────────────────────────────────────────────
   Visa Cancellation — data model + shared helpers.

   Stores per-employee visa cancellation / off-boarding records and exposes
   filtering, summary and option helpers used by visa_cancellation.php and
   its CSV/Excel export.

   Employee identity fields (name, passport, Emirates ID, nationality,
   department, designation) are read LIVE from the `employees` table via a
   join, so they never go stale. Visa + cancellation + settlement fields are
   stored here (pre-filled from the employee record when a record is created).
───────────────────────────────────────────── */

if (!function_exists('vc_cancellation_statuses')) {
    function vc_cancellation_statuses() {
        return ['Pending', 'Submitted', 'Approved', 'Rejected', 'Completed'];
    }
}
if (!function_exists('vc_cancellation_reasons')) {
    function vc_cancellation_reasons() {
        return ['Resignation', 'Termination', 'Transfer', 'End of Contract', 'Absconding', 'Other'];
    }
}
if (!function_exists('vc_settlement_statuses')) {
    function vc_settlement_statuses() {
        return ['Pending', 'In Progress', 'Paid', 'On Hold'];
    }
}
if (!function_exists('vc_clearance_statuses')) {
    function vc_clearance_statuses() {
        return ['Pending', 'In Progress', 'Cleared'];
    }
}
if (!function_exists('vc_visa_types')) {
    function vc_visa_types() {
        return ['Employment', 'Residence', 'Investor', 'Partner', 'Mission', 'Family', 'Other'];
    }
}

if (!function_exists('vc_status_colors')) {
    /* [background, text] per cancellation status for highlighting. */
    function vc_status_colors($status) {
        $map = [
            'Pending'   => ['#fef3c7', '#92400e'], // yellow
            'Submitted' => ['#dbeafe', '#1e40af'], // blue
            'Approved'  => ['#dcfce7', '#166534'], // green
            'Rejected'  => ['#fee2e2', '#991b1b'], // red
            'Completed' => ['#cffafe', '#155e75'], // teal
        ];
        return $map[$status] ?? ['#f1f5f9', '#475569'];
    }
}

if (!function_exists('vc_table_columns')) {
    function vc_table_columns($conn, $table) {
        $cols = [];
        $safe = mysqli_real_escape_string($conn, $table);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$safe`");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) { $cols[$r['Field']] = true; }
        }
        return $cols;
    }
}

if (!function_exists('vc_pick')) {
    function vc_pick($row, $keys, $default = '') {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') { return $row[$k]; }
        }
        return $default;
    }
}

if (!function_exists('vc_ensure_schema')) {
    function vc_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS visa_cancellations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_no VARCHAR(50) NOT NULL,
                -- Visa details (snapshot, editable)
                emirates_number VARCHAR(100) DEFAULT '',
                visa_type VARCHAR(50) DEFAULT '',
                visa_issue_date DATE NULL,
                visa_expiry_date DATE NULL,
                visa_sponsor VARCHAR(160) DEFAULT '',
                labour_card_number VARCHAR(100) DEFAULT '',
                -- Cancellation
                visa_cancellation_date DATE NULL,
                labour_card_cancellation_date DATE NULL,
                cancellation_application_number VARCHAR(100) DEFAULT '',
                cancellation_status VARCHAR(20) DEFAULT 'Pending',
                cancellation_reason VARCHAR(40) DEFAULT '',
                -- Final settlement
                last_working_date DATE NULL,
                notice_period_start DATE NULL,
                notice_period_end DATE NULL,
                basic_salary DECIMAL(10,2) DEFAULT 0,
                gratuity_amount DECIMAL(12,2) DEFAULT 0,
                leave_encashment DECIMAL(12,2) DEFAULT 0,
                final_settlement_amount DECIMAL(12,2) DEFAULT 0,
                settlement_status VARCHAR(20) DEFAULT 'Pending',
                -- Exit
                exit_country_date DATE NULL,
                air_ticket_provided VARCHAR(30) DEFAULT 'Company Ticket',
                re_entry_eligible TINYINT(1) DEFAULT 1,
                remarks TEXT,
                -- Document tracking
                passport_returned TINYINT(1) DEFAULT 0,
                emirates_id_returned TINYINT(1) DEFAULT 0,
                company_assets_returned TINYINT(1) DEFAULT 0,
                clearance_status VARCHAR(20) DEFAULT 'Pending',
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (user_no),
                INDEX idx_status (cancellation_status),
                INDEX idx_canceldate (visa_cancellation_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Upgrade older tables that were created with the legacy visa_number
        // column: add emirates_number if it is missing.
        $cols = vc_table_columns($conn, 'visa_cancellations');
        if (!isset($cols['emirates_number'])) {
            mysqli_query($conn, "ALTER TABLE visa_cancellations ADD COLUMN emirates_number VARCHAR(100) DEFAULT '' AFTER user_no");
        }

        // Air Ticket changed from a Yes/No flag to a choice (Company Ticket /
        // Own Ticket). Convert legacy tinyint columns to VARCHAR and map old
        // values: Yes(1) -> Company Ticket, No(0) -> Own Ticket.
        $atc = mysqli_query($conn, "SHOW COLUMNS FROM visa_cancellations LIKE 'air_ticket_provided'");
        if ($atc && ($atcr = mysqli_fetch_assoc($atc)) && stripos((string)($atcr['Type'] ?? ''), 'varchar') === false) {
            mysqli_query($conn, "ALTER TABLE visa_cancellations MODIFY air_ticket_provided VARCHAR(30) DEFAULT 'Company Ticket'");
            mysqli_query($conn, "UPDATE visa_cancellations SET air_ticket_provided='Company Ticket' WHERE air_ticket_provided='1'");
            mysqli_query($conn, "UPDATE visa_cancellations SET air_ticket_provided='Own Ticket' WHERE air_ticket_provided='0'");
        }
    }
}

if (!function_exists('vc_employee_name_col')) {
    function vc_employee_name_col($conn) {
        $cols = vc_table_columns($conn, 'employees');
        return isset($cols['full_name']) ? 'full_name' : (isset($cols['name']) ? 'name' : 'user_no');
    }
}

if (!function_exists('vc_get_employee')) {
    /* Fetch a single employee row (for pre-filling a new cancellation). */
    function vc_get_employee($conn, $user_no) {
        $safe = mysqli_real_escape_string($conn, (string)$user_no);
        $res = mysqli_query($conn, "SELECT * FROM employees WHERE user_no='$safe' LIMIT 1");
        return ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res) : null;
    }
}

if (!function_exists('vc_distinct_values')) {
    /* Distinct non-empty values of an employees column (for filter dropdowns). */
    function vc_distinct_values($conn, $column) {
        $cols = vc_table_columns($conn, 'employees');
        if (!isset($cols[$column])) { return []; }
        $out = [];
        $res = mysqli_query($conn, "SELECT DISTINCT `$column` AS v FROM employees WHERE `$column` IS NOT NULL AND `$column` != '' ORDER BY `$column` ASC");
        if ($res) { while ($r = mysqli_fetch_assoc($res)) { $out[] = $r['v']; } }
        return $out;
    }
}

if (!function_exists('vc_build_where')) {
    /*
       Build the WHERE clause for the joined cancellation query.
       vc = visa_cancellations alias, e = employees alias.
       $f keys: from, to, department, designation, nationality,
                cancellation_status, reason, visa_status, search
    */
    function vc_build_where($conn, $f) {
        $emp_cols = vc_table_columns($conn, 'employees');
        $where = ['1=1'];
        $esc = fn($v) => mysqli_real_escape_string($conn, trim((string)$v));

        if (!empty($f['from'])) { $where[] = "(vc.visa_cancellation_date IS NOT NULL AND vc.visa_cancellation_date >= '" . $esc($f['from']) . "')"; }
        if (!empty($f['to']))   { $where[] = "(vc.visa_cancellation_date IS NOT NULL AND vc.visa_cancellation_date <= '" . $esc($f['to']) . "')"; }
        if (!empty($f['department'])  && isset($emp_cols['department']))  { $where[] = "e.department='" . $esc($f['department']) . "'"; }
        if (!empty($f['designation']) && isset($emp_cols['designation'])) { $where[] = "e.designation='" . $esc($f['designation']) . "'"; }
        if (!empty($f['nationality']) && isset($emp_cols['nationality'])) { $where[] = "e.nationality='" . $esc($f['nationality']) . "'"; }
        if (!empty($f['cancellation_status']) && in_array($f['cancellation_status'], vc_cancellation_statuses(), true)) {
            $where[] = "vc.cancellation_status='" . $esc($f['cancellation_status']) . "'";
        }
        if (!empty($f['reason']) && in_array($f['reason'], vc_cancellation_reasons(), true)) {
            $where[] = "vc.cancellation_reason='" . $esc($f['reason']) . "'";
        }
        if (!empty($f['visa_status'])) {
            $today = date('Y-m-d');
            if ($f['visa_status'] === 'Expired') { $where[] = "(vc.visa_expiry_date IS NOT NULL AND vc.visa_expiry_date < '$today')"; }
            elseif ($f['visa_status'] === 'Valid') { $where[] = "(vc.visa_expiry_date IS NULL OR vc.visa_expiry_date >= '$today')"; }
        }
        if (!empty($f['search'])) {
            $s = $esc($f['search']);
            $namecol = vc_employee_name_col($conn);
            $where[] = "(vc.user_no LIKE '%$s%' OR e.`$namecol` LIKE '%$s%' OR vc.emirates_number LIKE '%$s%')";
        }
        return implode(' AND ', $where);
    }
}

if (!function_exists('vc_fetch_resigned_virtual')) {
    /*
       Surface employees who have RESIGNED (status Resign/Resigned, or a past
       resign_date) but do NOT yet have a cancellation record, as "to-process"
       virtual rows so HR can open and close their file from this report.
       Resignation == Resign here. Skipped when filters target processed
       records (a non-Pending status, a non-Resignation reason, or a cancel
       date range — virtual rows have no cancellation date yet).
    */
    function vc_fetch_resigned_virtual($conn, $f) {
        if (!empty($f['cancellation_status']) && $f['cancellation_status'] !== 'Pending') { return []; }
        if (!empty($f['reason']) && $f['reason'] !== 'Resignation') { return []; }
        if (!empty($f['from']) || !empty($f['to'])) { return []; }

        $emp_cols = vc_table_columns($conn, 'employees');
        $namecol = vc_employee_name_col($conn);
        $status_col = isset($emp_cols['employee_status']) ? 'employee_status'
                    : (isset($emp_cols['status']) ? 'status' : null);
        $today = date('Y-m-d');
        $esc = fn($v) => mysqli_real_escape_string($conn, trim((string)$v));

        $resigned = [];
        if ($status_col) { $resigned[] = "LOWER(e.`$status_col`) IN ('resign','resigned')"; }
        if (isset($emp_cols['resign_date'])) {
            $resigned[] = "(e.resign_date IS NOT NULL AND e.resign_date!='' AND e.resign_date!='0000-00-00' AND e.resign_date<='$today')";
        }
        if (empty($resigned)) { return []; }

        $where = ['(' . implode(' OR ', $resigned) . ')'];
        $where[] = "e.user_no NOT IN (SELECT user_no FROM visa_cancellations)";
        if (!empty($f['department'])  && isset($emp_cols['department']))  { $where[] = "e.department='" . $esc($f['department']) . "'"; }
        if (!empty($f['designation']) && isset($emp_cols['designation'])) { $where[] = "e.designation='" . $esc($f['designation']) . "'"; }
        if (!empty($f['nationality']) && isset($emp_cols['nationality'])) { $where[] = "e.nationality='" . $esc($f['nationality']) . "'"; }
        if (!empty($f['visa_status']) && isset($emp_cols['visa_expiry_date'])) {
            if ($f['visa_status'] === 'Expired') { $where[] = "(e.visa_expiry_date IS NOT NULL AND e.visa_expiry_date < '$today')"; }
            elseif ($f['visa_status'] === 'Valid') { $where[] = "(e.visa_expiry_date IS NULL OR e.visa_expiry_date >= '$today')"; }
        }
        if (!empty($f['search'])) {
            $s = $esc($f['search']);
            $parts = ["e.user_no LIKE '%$s%'", "e.`$namecol` LIKE '%$s%'"];
            if (isset($emp_cols['emirates_id_number'])) { $parts[] = "e.emirates_id_number LIKE '%$s%'"; }
            if (isset($emp_cols['visa_id_number']))     { $parts[] = "e.visa_id_number LIKE '%$s%'"; }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        $where_sql = implode(' AND ', $where);
        $order = isset($emp_cols['resign_date']) ? "e.resign_date DESC, " : "";
        $res = mysqli_query($conn, "SELECT e.* FROM employees e WHERE $where_sql ORDER BY $order CAST(e.user_no AS UNSIGNED) ASC, e.user_no ASC");
        $rows = [];
        if ($res) {
            while ($e = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    'id' => 0, '_virtual' => true,
                    'user_no' => $e['user_no'] ?? '',
                    'emp_name' => vc_pick($e, [$namecol, 'full_name', 'name']),
                    'employee_id' => vc_pick($e, ['employee_id']),
                    'card_no' => vc_pick($e, ['card_no']),
                    'passport' => vc_pick($e, ['passport']),
                    'emirates_id_number' => vc_pick($e, ['emirates_id_number']),
                    'nationality' => vc_pick($e, ['nationality']),
                    'department' => vc_pick($e, ['department']),
                    'designation' => vc_pick($e, ['designation']),
                    'emirates_number' => vc_pick($e, ['emirates_id_number']),
                    'visa_type' => '',
                    'visa_issue_date' => vc_pick($e, ['visa_issuing_date']),
                    'visa_expiry_date' => vc_pick($e, ['visa_expiry_date']),
                    'visa_sponsor' => '',
                    'labour_card_number' => vc_pick($e, ['uid_number']),
                    'visa_cancellation_date' => null,
                    'labour_card_cancellation_date' => null,
                    'cancellation_application_number' => '',
                    'cancellation_status' => 'Pending',
                    'cancellation_reason' => 'Resignation',
                    'last_working_date' => vc_pick($e, ['resign_date']),
                    'notice_period_start' => null, 'notice_period_end' => null,
                    'basic_salary' => vc_pick($e, ['basic_salary'], 0),
                    'gratuity_amount' => 0, 'leave_encashment' => 0,
                    'final_settlement_amount' => 0, 'settlement_status' => 'Pending',
                    'exit_country_date' => null, 'air_ticket_provided' => 'Company Ticket', 're_entry_eligible' => 1,
                    'remarks' => '',
                    'passport_returned' => 0, 'emirates_id_returned' => 0, 'company_assets_returned' => 0,
                    'clearance_status' => 'Pending',
                ];
            }
        }
        return $rows;
    }
}

if (!function_exists('vc_fetch_records')) {
    /* Joined cancellation records (newest first) + resigned to-process rows. */
    function vc_fetch_records($conn, $f) {
        $namecol = vc_employee_name_col($conn);
        $emp_cols = vc_table_columns($conn, 'employees');
        $col = fn($name, $as) => isset($emp_cols[$name]) ? "e.`$name` AS $as" : "'' AS $as";
        $select_emp = implode(",\n            ", [
            "e.`$namecol` AS emp_name",
            $col('employee_id', 'employee_id'),
            $col('card_no', 'card_no'),
            $col('passport', 'passport'),
            $col('emirates_id_number', 'emirates_id_number'),
            $col('nationality', 'nationality'),
            $col('department', 'department'),
            $col('designation', 'designation'),
        ]);
        $where = vc_build_where($conn, $f);
        $sql = "
            SELECT vc.*, $select_emp
            FROM visa_cancellations vc
            LEFT JOIN employees e ON e.user_no = vc.user_no
            WHERE $where
            ORDER BY (vc.visa_cancellation_date IS NULL), vc.visa_cancellation_date DESC, vc.id DESC
        ";
        $rows = [];
        $res = mysqli_query($conn, $sql);
        if ($res) { while ($r = mysqli_fetch_assoc($res)) { $r['_virtual'] = false; $rows[] = $r; } }
        // Surface resigned employees not yet processed, so their file can be closed here.
        $rows = array_merge($rows, vc_fetch_resigned_virtual($conn, $f));

        // Keep Pending / in-process records on top and push Completed ones to
        // the bottom, while preserving the existing order within each group.
        $pending_rows   = [];
        $completed_rows = [];
        foreach ($rows as $r) {
            if (strtolower(trim((string)($r['cancellation_status'] ?? ''))) === 'completed') {
                $completed_rows[] = $r;
            } else {
                $pending_rows[] = $r;
            }
        }
        return array_merge($pending_rows, $completed_rows);
    }
}

if (!function_exists('vc_summary')) {
    /* Dashboard figures over the filtered set. */
    function vc_summary($rows) {
        $sum = [
            'total' => count($rows),
            'pending' => 0,
            'completed' => 0,
            'approved' => 0,
            'rejected' => 0,
            'total_gratuity' => 0.0,
            'total_settlement' => 0.0,
        ];
        foreach ($rows as $r) {
            $st = $r['cancellation_status'] ?? '';
            if ($st === 'Pending' || $st === 'Submitted') { $sum['pending']++; }
            if ($st === 'Completed') { $sum['completed']++; }
            if ($st === 'Approved')  { $sum['approved']++; }
            if ($st === 'Rejected')  { $sum['rejected']++; }
            $sum['total_gratuity']   += (float)($r['gratuity_amount'] ?? 0);
            $sum['total_settlement'] += (float)($r['final_settlement_amount'] ?? 0);
        }
        return $sum;
    }
}

if (!function_exists('vc_editable_fields')) {
    /* Field => bind-type for insert/update (excludes id/user_no/timestamps). */
    function vc_editable_fields() {
        return [
            'emirates_number' => 's', 'visa_type' => 's', 'visa_issue_date' => 'date',
            'visa_expiry_date' => 'date', 'visa_sponsor' => 's', 'labour_card_number' => 's',
            'visa_cancellation_date' => 'date', 'labour_card_cancellation_date' => 'date',
            'cancellation_application_number' => 's', 'cancellation_status' => 's',
            'cancellation_reason' => 's', 'last_working_date' => 'date',
            'notice_period_start' => 'date', 'notice_period_end' => 'date',
            'basic_salary' => 'd', 'gratuity_amount' => 'd', 'leave_encashment' => 'd',
            'final_settlement_amount' => 'd', 'settlement_status' => 's',
            'exit_country_date' => 'date', 'air_ticket_provided' => 's',
            'remarks' => 's',
            'passport_returned' => 'i', 'emirates_id_returned' => 'i',
            'company_assets_returned' => 'i', 'clearance_status' => 's',
        ];
    }
}
