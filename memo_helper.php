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
    }
}

if (!function_exists('memo_types')) {
    /* The memo categories offered in the dropdown. */
    function memo_types() {
        return [
            'First Warning - Absence',
            'Final Warning - Absence',
            'Show Cause Notice',
            'Misconduct / Performance Warning',
            'General Notice',
        ];
    }
}

if (!function_exists('memo_default_subject')) {
    function memo_default_subject($type) {
        switch ($type) {
            case 'First Warning - Absence':
            case 'Final Warning - Absence':
                return 'ABSENT';
            case 'Show Cause Notice':
                return 'SHOW CAUSE NOTICE';
            case 'Misconduct / Performance Warning':
                return 'WARNING';
            default:
                return '';
        }
    }
}

if (!function_exists('memo_default_body')) {
    /* Editable starter text for each memo type. The Final Warning - Absence
       text mirrors the company's existing absence memo wording. */
    function memo_default_body($type) {
        switch ($type) {
            case 'First Warning - Absence':
                return "This is to formally notify you that you have remained absent from duty without prior approval or any valid justification.\n\n"
                     . "Such unauthorized absence is a violation of company rules and disrupts the smooth functioning of our operations.\n\n"
                     . "You are hereby issued this First Warning and advised to maintain regular attendance and report to duty on time. Any repetition of such conduct will result in further disciplinary action in accordance with company policy.";
            case 'Final Warning - Absence':
                return "This is to inform you that the Company has already issued a First Warning Memo for your unauthorized absence from duty for several consecutive days.\n\n"
                     . "Your continued absence has adversely affected our operations and caused delays in factory workflow and productivity. Such conduct is considered a serious violation of company rules and responsibilities.\n\n"
                     . "Therefore, this letter serves as your Final Warning. You are hereby advised to maintain regular attendance and fulfil your duties as required. Any further unauthorized absence or repetition of this misconduct will result in disciplinary action, including the imposition of fines and/or other actions in accordance with company policy.\n\n"
                     . "Please treat this matter with utmost seriousness and ensure that such incidents do not recur.";
            case 'Show Cause Notice':
                return "It has been observed that you have committed an act of misconduct as detailed below:\n\n"
                     . "[Describe the incident / violation here]\n\n"
                     . "You are hereby directed to show cause in writing within three (3) days from the date of this memo as to why disciplinary action should not be taken against you for the above.\n\n"
                     . "Failure to submit a satisfactory explanation within the stipulated time will compel the Company to proceed with appropriate disciplinary action in accordance with company policy.";
            case 'Misconduct / Performance Warning':
                return "This memo is to bring to your attention serious concerns regarding your conduct / performance, which do not meet the standards expected by the Company.\n\n"
                     . "You are hereby advised to immediately rectify the same and maintain the required standards of discipline and performance. Any repetition of such behaviour will result in further disciplinary action in accordance with company policy.";
            default:
                return "";
        }
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
