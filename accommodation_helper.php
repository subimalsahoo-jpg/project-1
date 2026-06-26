<?php
/* ─────────────────────────────────────────────
   Employee Accommodation helper.

   Two tables (auto-created):
     • accommodation_rooms       — one row per room
     • accommodation_allocations — which employee lives in which room
                                    (one active room per employee)

   Free Space = Capacity − Allocated employees (auto).
───────────────────────────────────────────── */

if (!function_exists('acc_esc')) {
    function acc_esc($conn, $v) { return mysqli_real_escape_string($conn, trim((string)$v)); }
}

if (!function_exists('acc_ensure_schema')) {
    function acc_ensure_schema($conn) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS accommodation_rooms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gender VARCHAR(10) NOT NULL DEFAULT 'Boys',
                main_location VARCHAR(50) NOT NULL DEFAULT 'Saif Zone',
                tower_block VARCHAR(100) DEFAULT '',
                room_number VARCHAR(50) DEFAULT '',
                room_for VARCHAR(20) DEFAULT 'Labour',
                capacity INT NOT NULL DEFAULT 6,
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Add room_for to an already-existing table (older installs).
        $rf = mysqli_query($conn, "SHOW COLUMNS FROM accommodation_rooms LIKE 'room_for'");
        if ($rf && mysqli_num_rows($rf) === 0) {
            mysqli_query($conn, "ALTER TABLE accommodation_rooms ADD room_for VARCHAR(20) DEFAULT 'Labour' AFTER room_number");
        }
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS accommodation_allocations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                user_no VARCHAR(50) NOT NULL,
                employee_id VARCHAR(50) DEFAULT '',
                employee_name VARCHAR(150) DEFAULT '',
                created_by VARCHAR(100) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user (user_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('acc_room')) {
    function acc_room($conn, $room_id) {
        $room_id = (int)$room_id;
        $r = mysqli_query($conn, "SELECT * FROM accommodation_rooms WHERE id=$room_id LIMIT 1");
        return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
    }
}

if (!function_exists('acc_allocated_count')) {
    function acc_allocated_count($conn, $room_id) {
        $room_id = (int)$room_id;
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM accommodation_allocations WHERE room_id=$room_id");
        return $r ? (int)(mysqli_fetch_assoc($r)['c'] ?? 0) : 0;
    }
}

if (!function_exists('acc_rooms_with_counts')) {
    /* Rooms for a gender (optionally filtered by main location), each with
       allocated count and free space. */
    function acc_rooms_with_counts($conn, $gender, $location = '') {
        $g = acc_esc($conn, $gender);
        $where = "r.gender='$g'";
        if ($location !== '') { $where .= " AND r.main_location='" . acc_esc($conn, $location) . "'"; }
        $rows = [];
        $q = mysqli_query($conn, "
            SELECT r.*,
                   (SELECT COUNT(*) FROM accommodation_allocations a WHERE a.room_id=r.id) AS allocated
            FROM accommodation_rooms r
            WHERE $where
            ORDER BY r.main_location, r.tower_block, CAST(r.room_number AS UNSIGNED), r.room_number
        ");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $row['allocated']  = (int)$row['allocated'];
                $row['free_space'] = max(0, (int)$row['capacity'] - $row['allocated']);
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('acc_room_employees')) {
    /* Allocated employees of a room, joined to live employee data. */
    function acc_room_employees($conn, $room_id) {
        $room_id = (int)$room_id;
        $rows = [];
        $q = mysqli_query($conn, "
            SELECT a.id AS allocation_id, a.user_no, a.employee_id, a.employee_name,
                   e.full_name, e.gender, e.department, e.designation
            FROM accommodation_allocations a
            LEFT JOIN employees e ON e.user_no = a.user_no
            WHERE a.room_id=$room_id
            ORDER BY CAST(a.user_no AS UNSIGNED), a.user_no
        ");
        if ($q) { while ($row = mysqli_fetch_assoc($q)) { $rows[] = $row; } }
        return $rows;
    }
}

if (!function_exists('acc_find_employee')) {
    /* Resolve an employee by user_no / employee_id / name. */
    function acc_find_employee($conn, $term) {
        $t = acc_esc($conn, $term);
        if ($t === '') return null;
        $r = mysqli_query($conn, "
            SELECT * FROM employees
            WHERE user_no='$t' OR employee_id='$t' OR full_name LIKE '%$t%'
            ORDER BY (user_no='$t') DESC, (employee_id='$t') DESC
            LIMIT 1
        ");
        return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
    }
}

if (!function_exists('acc_employee_count')) {
    /* Allocated employee count for a gender, optionally by main location. */
    function acc_employee_count($conn, $gender, $location = '') {
        $g = acc_esc($conn, $gender);
        $where = "r.gender='$g'";
        if ($location !== '') { $where .= " AND r.main_location='" . acc_esc($conn, $location) . "'"; }
        $q = mysqli_query($conn, "
            SELECT COUNT(*) AS c
            FROM accommodation_allocations a
            JOIN accommodation_rooms r ON r.id = a.room_id
            WHERE $where
        ");
        return $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    }
}

if (!function_exists('acc_employee_current')) {
    /* Where an employee is currently allocated (room + allocation), or null. */
    function acc_employee_current($conn, $user_no) {
        $u = acc_esc($conn, $user_no);
        $r = mysqli_query($conn, "
            SELECT a.id AS allocation_id, a.room_id, r.gender, r.main_location, r.tower_block, r.room_number
            FROM accommodation_allocations a
            LEFT JOIN accommodation_rooms r ON r.id = a.room_id
            WHERE a.user_no='$u' LIMIT 1
        ");
        return ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
    }
}

if (!function_exists('acc_total_housed')) {
    /* Total employees housed across all rooms / genders / locations. */
    function acc_total_housed($conn) {
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM accommodation_allocations");
        return $r ? (int)(mysqli_fetch_assoc($r)['c'] ?? 0) : 0;
    }
}

if (!function_exists('acc_all_allocations')) {
    /* Every allocated employee with their room info (for Excel export).
       Optionally filtered by gender / main location. */
    function acc_all_allocations($conn, $gender = '', $location = '') {
        $where = '1=1';
        if ($gender !== '')   { $where .= " AND r.gender='" . acc_esc($conn, $gender) . "'"; }
        if ($location !== '') { $where .= " AND r.main_location='" . acc_esc($conn, $location) . "'"; }
        $rows = [];
        $q = mysqli_query($conn, "
            SELECT a.user_no, a.employee_id, a.employee_name,
                   e.full_name, e.department, e.designation,
                   r.gender, r.main_location, r.tower_block, r.room_number, r.room_for, r.capacity
            FROM accommodation_allocations a
            JOIN accommodation_rooms r ON r.id = a.room_id
            LEFT JOIN employees e ON e.user_no = a.user_no
            WHERE $where
            ORDER BY r.gender, r.main_location, r.tower_block, CAST(r.room_number AS UNSIGNED), r.room_number
        ");
        if ($q) { while ($row = mysqli_fetch_assoc($q)) { $rows[] = $row; } }
        return $rows;
    }
}
