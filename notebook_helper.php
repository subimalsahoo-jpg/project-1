<?php
/* ──────────────────────────────────────────────────────────────────────────
   Internal Notebook / Messenger — shared helpers.

   Any logged-in payroll user can write a note and send it to another user (or
   to everyone). The recipient gets a blinking unread badge in the sidebar and
   can read the note (the sender's name is shown). Used by notebook.php and
   nav_sidebar.php.
   ────────────────────────────────────────────────────────────────────────── */

if (!function_exists('notebook_ensure_schema')) {
    function notebook_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS user_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_id INT NOT NULL,
                sender_name VARCHAR(150) DEFAULT '',
                recipient_id INT NOT NULL,
                recipient_name VARCHAR(150) DEFAULT '',
                message TEXT,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMP NULL,
                INDEX idx_recipient (recipient_id, is_read),
                INDEX idx_sender (sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('notebook_unread_count')) {
    function notebook_unread_count($conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return 0;
        $t = mysqli_query($conn, "SHOW TABLES LIKE 'user_messages'");
        if (!$t || mysqli_num_rows($t) === 0) return 0;
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM user_messages WHERE recipient_id=$user_id AND is_read=0");
        return $r ? (int)(mysqli_fetch_assoc($r)['c'] ?? 0) : 0;
    }
}

if (!function_exists('notebook_users')) {
    function notebook_users($conn, $exclude_id = 0) {
        $exclude_id = (int)$exclude_id;
        $users = [];
        $r = mysqli_query($conn, "SELECT id, username, full_name FROM users WHERE id<>$exclude_id ORDER BY full_name, username");
        if ($r) while ($u = mysqli_fetch_assoc($r)) { $users[] = $u; }
        return $users;
    }
}

if (!function_exists('notebook_user_label')) {
    function notebook_user_label($u) {
        $name = trim((string)($u['full_name'] ?? ''));
        $un   = trim((string)($u['username'] ?? ''));
        if ($name !== '' && $un !== '' && strcasecmp($name, $un) !== 0) return $name . ' (' . $un . ')';
        return $name !== '' ? $name : $un;
    }
}
?>
