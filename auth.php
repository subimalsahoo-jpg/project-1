<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    include 'db.php';
}

include_once __DIR__ . '/company.php';

function auth_table_columns($conn, $table) {
    $columns = [];
    $safe_table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_table`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[$row['Field']] = true;
        }
    }
    return $columns;
}

function auth_column_exists($conn, $table, $column) {
    $safe_table = mysqli_real_escape_string($conn, $table);
    $safe_column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safe_table` LIKE '$safe_column'");
    return $result && mysqli_num_rows($result) > 0;
}

function detect_input_date_order($values) {
    foreach ((array)$values as $value) {
        $value = trim((string)$value);
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
            $first = (int)$m[1];
            $second = (int)$m[2];
            if ($first > 12) return 'dmy';
            if ($second > 12) return 'mdy';
        }
    }
    return 'dmy';
}

function normalize_input_date($value, $order = 'dmy') {
    $value = trim((string)$value);
    if ($value === '') return '';

    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2]; $y = (int)$m[3];
        if ($a > 12) {
            $d = $a; $mo = $b;
        } elseif ($b > 12) {
            $mo = $a; $d = $b;
        } elseif ($order === 'mdy') {
            $mo = $a; $d = $b;
        } else {
            $d = $a; $mo = $b;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function normalize_input_month($value, $fallback = '') {
    $value = trim((string)$value);
    if ($value === '') return $fallback;

    if (preg_match('/^(\d{4})[-\/.](\d{1,2})$/', $value, $m)) {
        $month = (int)$m[2];
        return ($month >= 1 && $month <= 12) ? sprintf('%04d-%02d', (int)$m[1], $month) : $fallback;
    }

    if (preg_match('/^(\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
        $month = (int)$m[1];
        return ($month >= 1 && $month <= 12) ? sprintf('%04d-%02d', (int)$m[2], $month) : $fallback;
    }

    $timestamp = strtotime('01 ' . $value);
    if (!$timestamp) $timestamp = strtotime($value . '-01');
    return $timestamp ? date('Y-m', $timestamp) : $fallback;
}

function auth_ensure_schema($conn) {
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) DEFAULT '',
            role VARCHAR(50) DEFAULT 'Viewer',
            status VARCHAR(20) DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $columns = auth_table_columns($conn, 'users');
    $adds = [
        'full_name' => "ALTER TABLE users ADD full_name VARCHAR(150) DEFAULT ''",
        'role' => "ALTER TABLE users ADD role VARCHAR(50) DEFAULT 'Viewer'",
        'status' => "ALTER TABLE users ADD status VARCHAR(20) DEFAULT 'Active'",
        'created_at' => "ALTER TABLE users ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($adds as $column => $sql) {
        if (!isset($columns[$column])) {
            mysqli_query($conn, $sql);
        }
    }

    mysqli_query($conn, "
        UPDATE users
        SET role='Admin', status='Active'
        WHERE username='admin'
          AND (role IS NULL OR role='' OR role='Viewer')
    ");

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS user_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            permission_name VARCHAR(100) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY user_permission_unique (user_id, permission_name)
        )
    ");

    $count_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users");
    $count_row = $count_result ? mysqli_fetch_assoc($count_result) : ['total' => 0];
    if ((int)($count_row['total'] ?? 0) === 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        mysqli_query($conn, "
            INSERT INTO users (username, password, full_name, role, status)
            VALUES ('admin', '$password', 'System Admin', 'Admin', 'Active')
        ");
    }
}

function permission_definitions() {
    return [
        'dashboard_view' => 'Dashboard View',
        'employee_view' => 'Employee View/List',
        'employee_add' => 'Employee Add/Edit',
        'employee_delete' => 'Employee Delete',
        'attendance_report' => 'Attendance Report',
        'attendance_upload' => 'Attendance Upload',
        'salary_view' => 'Salary Sheet View',
        'salary_generate' => 'Salary Generate',
        'salary_slip_print' => 'Salary Slip Print',
        'advance_manage' => 'Advance Salary',
        'overtime_view' => 'Overtime Report',
        'gratuity_view' => 'Gratuity Report',
        'wps_manage' => 'WPS / SIF File',
        'visa_cancellation_manage' => 'Visa Cancellation',
        'visa_insurance_view' => 'Visa & Insurance Expiry',
        'vacation_manage' => 'Vacation Manage',
        'leave_encashment_manage' => 'Leave Encashment',
        'accommodation_manage' => 'Employee Accommodation',
        'gate_pass_manage' => 'Gate Pass',
        'reports_view' => 'Reports View',
        'uploads_manage' => 'Uploads Manage',
        'complaints_manage' => 'Complaints Manage',
        'recruitment_manage' => 'Recruitment Manage',
        'user_manage' => 'User Management',
    ];
}

function role_permissions($role) {
    $all = array_keys(permission_definitions());
    $role = strtolower((string)$role);

    if ($role === 'admin') {
        return $all;
    }

    $map = [
        'hr' => [
            'dashboard_view', 'employee_view', 'employee_add',
            'attendance_report', 'attendance_upload',
            'vacation_manage', 'leave_encashment_manage',
            'visa_cancellation_manage', 'visa_insurance_view',
            'accommodation_manage', 'complaints_manage', 'recruitment_manage',
            'gate_pass_manage',
            'salary_slip_print', 'uploads_manage',
            'overtime_view', 'gratuity_view', 'reports_view',
        ],
        'salary' => [
            'dashboard_view', 'employee_view', 'salary_view',
            'salary_generate', 'salary_slip_print', 'reports_view',
            'advance_manage', 'overtime_view', 'gratuity_view', 'wps_manage',
            'leave_encashment_manage',
        ],
        'attendance' => [
            'dashboard_view', 'employee_view', 'attendance_report',
            'attendance_upload', 'reports_view', 'overtime_view',
        ],
        'viewer' => [
            'dashboard_view', 'employee_view', 'attendance_report',
            'salary_view', 'reports_view', 'overtime_view', 'gratuity_view', 'visa_insurance_view',
        ],
    ];

    return $map[$role] ?? $map['viewer'];
}

function current_user_id() {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_role() {
    return $_SESSION['role'] ?? 'Viewer';
}

function is_admin_user() {
    return strtolower(current_user_role()) === 'admin';
}

function hasPermission($permission) {
    global $conn;

    if (is_admin_user()) {
        return true;
    }

    $role_allowed = in_array($permission, role_permissions(current_user_role()), true);
    $user_id = current_user_id();
    if ($user_id <= 0) {
        return $role_allowed;
    }

    $safe_permission = mysqli_real_escape_string($conn, $permission);
    $result = mysqli_query($conn, "
        SELECT allowed
        FROM user_permissions
        WHERE user_id=$user_id AND permission_name='$safe_permission'
        LIMIT 1
    ");

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return (int)$row['allowed'] === 1;
    }

    return $role_allowed;
}

function requireLogin() {
    if (!isset($_SESSION['username'])) {
        header("Location: login.php");
        exit();
    }
}

function requirePermission($permission) {
    requireLogin();
    if (!hasPermission($permission)) {
        http_response_code(403);
        echo "<h2 style='font-family:Arial;color:#c0392b;text-align:center;margin-top:60px;'>Access Denied</h2>";
        echo "<p style='font-family:Arial;text-align:center;'>You do not have permission to open this page.</p>";
        exit();
    }
}

/* Allow access if the user has ANY of the given permissions (used so a
   specific report permission OR its broader umbrella both grant access,
   keeping older grants working while enabling finer-grained control). */
function requireAnyPermission(array $permissions) {
    requireLogin();
    foreach ($permissions as $permission) {
        if (hasPermission($permission)) {
            return;
        }
    }
    http_response_code(403);
    echo "<h2 style='font-family:Arial;color:#c0392b;text-align:center;margin-top:60px;'>Access Denied</h2>";
    echo "<p style='font-family:Arial;text-align:center;'>You do not have permission to open this page.</p>";
    exit();
}

function login_user($conn, $username, $password) {
    auth_ensure_schema($conn);

    $safe_username = mysqli_real_escape_string($conn, trim($username));
    $result = mysqli_query($conn, "SELECT * FROM users WHERE username='$safe_username' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        return false;
    }

    $user = mysqli_fetch_assoc($result);
    if (($user['status'] ?? 'Active') !== 'Active') {
        return false;
    }

    $stored = (string)($user['password'] ?? '');
    $ok = password_verify($password, $stored)
        || hash_equals($stored, $password)
        || hash_equals($stored, md5($password));

    if (!$ok) {
        return false;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
    $_SESSION['role'] = $user['role'] ?? 'Viewer';
    return true;
}

auth_ensure_schema($conn);
?>
