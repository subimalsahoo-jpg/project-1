<?php
/* ─────────────────────────────────────────────
   Recruitment helper.

   Self-contained: creates its own `candidates` and `interviews`
   tables on first use. Used by recruitment.php and offer_letter.php.

   - candidates : applicant record (name, passport, contact, post,
     salary breakup, status, source, etc.)
   - interviews : interview schedule linked to a candidate.
───────────────────────────────────────────── */

if (!function_exists('rec_esc')) {
    function rec_esc($conn, $v) { return mysqli_real_escape_string($conn, trim((string)$v)); }
}

if (!function_exists('rec_statuses')) {
    function rec_statuses() {
        return ['New', 'Shortlisted', 'Interview Scheduled', 'Selected', 'Offer Sent', 'Joined', 'Rejected', 'On Hold'];
    }
}
if (!function_exists('rec_interview_modes')) {
    function rec_interview_modes() { return ['In-person', 'Phone', 'Online']; }
}
if (!function_exists('rec_interview_statuses')) {
    function rec_interview_statuses() { return ['Scheduled', 'Completed', 'Selected', 'Rejected', 'No Show']; }
}

if (!function_exists('rec_ensure_schema')) {
    function rec_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS candidates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                candidate_name VARCHAR(150) NOT NULL,
                passport_no VARCHAR(60) DEFAULT '',
                contact VARCHAR(60) DEFAULT '',
                email VARCHAR(120) DEFAULT '',
                nationality VARCHAR(80) DEFAULT '',
                applied_post VARCHAR(120) DEFAULT '',
                source VARCHAR(120) DEFAULT '',
                basic_salary DECIMAL(10,2) DEFAULT 0,
                external_allowance DECIMAL(10,2) DEFAULT 0,
                food_allowance DECIMAL(10,2) DEFAULT 0,
                status VARCHAR(40) DEFAULT 'New',
                remarks VARCHAR(500) DEFAULT '',
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $cand_cols = [
            'passport_no'        => "VARCHAR(60) DEFAULT ''",
            'contact'            => "VARCHAR(60) DEFAULT ''",
            'email'              => "VARCHAR(120) DEFAULT ''",
            'nationality'        => "VARCHAR(80) DEFAULT ''",
            'applied_post'       => "VARCHAR(120) DEFAULT ''",
            'source'             => "VARCHAR(120) DEFAULT ''",
            'basic_salary'       => "DECIMAL(10,2) DEFAULT 0",
            'external_allowance' => "DECIMAL(10,2) DEFAULT 0",
            'food_allowance'     => "DECIMAL(10,2) DEFAULT 0",
            'status'             => "VARCHAR(40) DEFAULT 'New'",
            'remarks'            => "VARCHAR(500) DEFAULT ''",
            'created_by'         => "VARCHAR(100) DEFAULT ''",
            'created_at'         => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ];
        foreach ($cand_cols as $name => $def) {
            $chk = mysqli_query($conn, "SHOW COLUMNS FROM candidates LIKE '" . rec_esc($conn, $name) . "'");
            if ($chk && mysqli_num_rows($chk) === 0) {
                mysqli_query($conn, "ALTER TABLE candidates ADD `$name` $def");
            }
        }

        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS interviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                candidate_id INT DEFAULT 0,
                candidate_name VARCHAR(150) DEFAULT '',
                applied_post VARCHAR(120) DEFAULT '',
                interview_date DATE NULL,
                interview_time VARCHAR(20) DEFAULT '',
                mode VARCHAR(30) DEFAULT 'In-person',
                location VARCHAR(200) DEFAULT '',
                interviewer VARCHAR(150) DEFAULT '',
                status VARCHAR(30) DEFAULT 'Scheduled',
                result_notes VARCHAR(500) DEFAULT '',
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $iv_cols = [
            'candidate_id'   => "INT DEFAULT 0",
            'candidate_name' => "VARCHAR(150) DEFAULT ''",
            'applied_post'   => "VARCHAR(120) DEFAULT ''",
            'interview_date' => "DATE NULL",
            'interview_time' => "VARCHAR(20) DEFAULT ''",
            'mode'           => "VARCHAR(30) DEFAULT 'In-person'",
            'location'       => "VARCHAR(200) DEFAULT ''",
            'interviewer'    => "VARCHAR(150) DEFAULT ''",
            'status'         => "VARCHAR(30) DEFAULT 'Scheduled'",
            'result_notes'   => "VARCHAR(500) DEFAULT ''",
            'created_by'     => "VARCHAR(100) DEFAULT ''",
            'created_at'     => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        ];
        foreach ($iv_cols as $name => $def) {
            $chk = mysqli_query($conn, "SHOW COLUMNS FROM interviews LIKE '" . rec_esc($conn, $name) . "'");
            if ($chk && mysqli_num_rows($chk) === 0) {
                mysqli_query($conn, "ALTER TABLE interviews ADD `$name` $def");
            }
        }
    }
}

if (!function_exists('rec_candidate')) {
    /* Fetch one candidate by id. */
    function rec_candidate($conn, $id) {
        $id = (int)$id;
        if ($id <= 0) return null;
        $r = mysqli_query($conn, "SELECT * FROM candidates WHERE id=$id LIMIT 1");
        return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
    }
}

if (!function_exists('rec_status_badge')) {
    /* CSS class suffix for a candidate status pill. */
    function rec_status_badge($status) {
        $s = strtolower(trim((string)$status));
        if (in_array($s, ['selected', 'joined', 'offer sent'], true)) return 'ok';
        if (in_array($s, ['rejected', 'no show'], true)) return 'bad';
        if (in_array($s, ['interview scheduled', 'shortlisted'], true)) return 'info';
        return 'warn';
    }
}
