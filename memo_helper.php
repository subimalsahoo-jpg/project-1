<?php
/* ──────────────────────────────────────────────────────────────────────────
   Employee Memo / Warning Letter — shared helpers.

   An HR/Admin can issue a memo (warning, show-cause, notice) to an employee.
   Each memo is stored per-employee (history) and can be printed on the company
   letterhead. Used by employee_memo.php (standalone), memo_panel.php (the
   Employee Overview "Memo" tab) and employee_memo_print.php (printable).
   ────────────────────────────────────────────────────────────────────────── */

if (!function_exists('memo_ensure_schema')) {
    function memo_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS employee_memos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                memo_no VARCHAR(40) DEFAULT '',
                user_no VARCHAR(50) NOT NULL,
                employee_id VARCHAR(50) DEFAULT '',
                employee_name VARCHAR(150) DEFAULT '',
                designation VARCHAR(120) DEFAULT '',
                memo_date DATE NULL,
                memo_type VARCHAR(80) DEFAULT '',
                subject VARCHAR(200) DEFAULT '',
                body TEXT,
                issued_by VARCHAR(150) DEFAULT '',
                created_by VARCHAR(150) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_memo_user (user_no),
                INDEX idx_memo_date (memo_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        /* Memo types are now editable (Admin-managed) and stored in their own
           table instead of being hard-coded. */
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS memo_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type_name VARCHAR(80) NOT NULL,
                default_subject VARCHAR(200) DEFAULT '',
                default_body TEXT,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_memo_type (type_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        /* Seed the built-in types once (only when the table is empty). */
        $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM memo_types"));
        if ((int)($cnt['c'] ?? 0) === 0) {
            $order = 0;
            foreach (memo_seed_types() as $seed) {
                $stmt = mysqli_prepare($conn, "INSERT INTO memo_types (type_name, default_subject, default_body, sort_order) VALUES (?,?,?,?)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'sssi', $seed['name'], $seed['subject'], $seed['body'], $order);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                $order++;
            }
        }
    }
}

if (!function_exists('memo_seed_types')) {
    /* Built-in types used ONLY to seed the memo_types table on first run.
       The Final Warning - Absence text mirrors the company's existing absence
       memo wording. After seeding, an Admin can add / edit / delete types. */
    function memo_seed_types() {
        return [
            ['name' => 'First Warning - Absence', 'subject' => 'ABSENT',
             'body' => "This is to formally notify you that you have remained absent from duty without prior approval or any valid justification.\n\nSuch unauthorized absence is a violation of company rules and disrupts the smooth functioning of our operations.\n\nYou are hereby issued this First Warning and advised to maintain regular attendance and report to duty on time. Any repetition of such conduct will result in further disciplinary action in accordance with company policy."],
            ['name' => 'Final Warning - Absence', 'subject' => 'ABSENT',
             'body' => "This is to inform you that the Company has already issued a First Warning Memo for your unauthorized absence from duty for several consecutive days.\n\nYour continued absence has adversely affected our operations and caused delays in factory workflow and productivity. Such conduct is considered a serious violation of company rules and responsibilities.\n\nTherefore, this letter serves as your Final Warning. You are hereby advised to maintain regular attendance and fulfil your duties as required. Any further unauthorized absence or repetition of this misconduct will result in disciplinary action, including the imposition of fines and/or other actions in accordance with company policy.\n\nPlease treat this matter with utmost seriousness and ensure that such incidents do not recur."],
            ['name' => 'Show Cause Notice', 'subject' => 'SHOW CAUSE NOTICE',
             'body' => "It has been observed that you have committed an act of misconduct as detailed below:\n\n[Describe the incident / violation here]\n\nYou are hereby directed to show cause in writing within three (3) days from the date of this memo as to why disciplinary action should not be taken against you for the above.\n\nFailure to submit a satisfactory explanation within the stipulated time will compel the Company to proceed with appropriate disciplinary action in accordance with company policy."],
            ['name' => 'Misconduct / Performance Warning', 'subject' => 'WARNING',
             'body' => "This memo is to bring to your attention serious concerns regarding your conduct / performance, which do not meet the standards expected by the Company.\n\nYou are hereby advised to immediately rectify the same and maintain the required standards of discipline and performance. Any repetition of such behaviour will result in further disciplinary action in accordance with company policy."],
            ['name' => 'General Notice', 'subject' => '', 'body' => ""],
        ];
    }
}

if (!function_exists('memo_type_rows')) {
    /* All memo types from the DB (ordered). Falls back to the seed list if the
       table is somehow empty, so the dropdown is never blank. */
    function memo_type_rows($conn) {
        $rows = [];
        $q = mysqli_query($conn, "SELECT id, type_name, default_subject, default_body FROM memo_types ORDER BY sort_order ASC, id ASC");
        if ($q) { while ($r = mysqli_fetch_assoc($q)) { $rows[] = $r; } }
        if (empty($rows)) {
            foreach (memo_seed_types() as $s) {
                $rows[] = ['id' => 0, 'type_name' => $s['name'], 'default_subject' => $s['subject'], 'default_body' => $s['body']];
            }
        }
        return $rows;
    }
}

if (!function_exists('memo_types')) {
    /* List of memo type names (for dropdowns / validation). */
    function memo_types($conn) {
        $names = [];
        foreach (memo_type_rows($conn) as $r) { $names[] = $r['type_name']; }
        return $names;
    }
}

if (!function_exists('memo_type_template')) {
    /* ['subject' => ..., 'body' => ...] for a given type name. */
    function memo_type_template($conn, $type) {
        foreach (memo_type_rows($conn) as $r) {
            if ($r['type_name'] === $type) {
                return ['subject' => (string)$r['default_subject'], 'body' => (string)$r['default_body']];
            }
        }
        return ['subject' => '', 'body' => ''];
    }
}

if (!function_exists('memo_employee_snapshot')) {
    /* Look up an employee by User No or Employee ID for prefilling. */
    function memo_employee_snapshot($conn, $key, $fallback_name = '') {
        $snap = [
            'user_no'     => trim((string)$key),
            'employee_id' => '',
            'name'        => $fallback_name,
            'designation' => '',
        ];
        $k = trim((string)$key);
        if ($k === '') return $snap;

        $stmt = mysqli_prepare($conn, "SELECT user_no, employee_id, full_name, designation
            FROM employees WHERE user_no = ? OR employee_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ss', $k, $k);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($e = mysqli_fetch_assoc($res)) {
                $snap['user_no']     = trim((string)$e['user_no']);
                $snap['employee_id'] = trim((string)($e['employee_id'] ?? ''));
                $snap['name']        = trim((string)$e['full_name']) ?: $fallback_name;
                $snap['designation'] = trim((string)($e['designation'] ?? ''));
            }
            mysqli_stmt_close($stmt);
        }
        return $snap;
    }
}

if (!function_exists('memo_next_no')) {
    /* Monthly serial: MEMO-YYYY-MM-NNN (robust against deletions). */
    function memo_next_no($conn) {
        $prefix    = 'MEMO-' . date('Y') . '-' . date('m') . '-';
        $safe_like = mysqli_real_escape_string($conn, $prefix) . '%';
        $max = 0;
        $q = mysqli_query($conn, "SELECT memo_no FROM employee_memos WHERE memo_no LIKE '$safe_like'");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $suffix = (int)substr((string)$r['memo_no'], strlen($prefix));
                if ($suffix > $max) $max = $suffix;
            }
        }
        return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('memo_current_user_name')) {
    function memo_current_user_name() {
        $n = trim((string)($_SESSION['full_name'] ?? ''));
        if ($n === '') $n = trim((string)($_SESSION['username'] ?? ''));
        if ($n === '') $n = trim((string)($_SESSION['role'] ?? 'User'));
        return $n;
    }
}
?>
