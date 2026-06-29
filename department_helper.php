<?php
/* ──────────────────────────────────────────────────────────────────────────
   Department & Position/Designation master lists.

   Historically the Department and Position/Designation dropdowns were
   hard-coded PHP arrays (in add_employee.php / employee_overview.php) or built
   from DISTINCT values in the employees table (employee_list.php). This helper
   moves them into editable master tables (`departments`, `designations`) so an
   Admin can add new options or rename existing ones from a single screen
   (manage_departments.php).

   The option lists returned for dropdowns are a UNION of the master table and
   the values actually used by employees, so a saved value is never missing
   from a dropdown even if it was removed from the master list.
   ────────────────────────────────────────────────────────────────────────── */

if (!function_exists('dept_default_departments')) {
    function dept_default_departments() {
        return [
            'CUTTING DEPT', 'FABRIC STORE', 'FACTORY', 'FINISHING DEPT',
            'HOUSE KEEPING', 'MAINTENANCE - GENERAL', 'MAINTENANCE - SEWING',
            'OFFICE STAFF', 'PACKING DEPT', 'PRESSING DEPT', 'PRINTING DEPT',
            'SAMPLE DEPT', 'SECURITY', 'SEWING DEPT', 'STORE',
        ];
    }
}

if (!function_exists('dept_default_designations')) {
    function dept_default_designations() {
        return [
            'ADMIN', 'DIRECTOR', 'ASST.QC', 'CHECKER', 'CLEANER', 'CUTTER',
            'DESIGNER', 'ELECTRICIAN', 'FABRIC ASSISTANT',
            'FINANCE & ACCOUNT DEPT', 'HELPER', 'IT', 'MECHANIC',
            'MERCHANDISER', 'MO', 'OFFICE BOY', 'OFFICE STAFF',
            'PATTER MASTER', 'PRESSMAN', 'PRINTING', 'PRO', 'QC',
            'QUALITY CHECKER', 'SAMPLE MAKER', 'SECURITY',
            'STORE KEEPERS', 'SUPERVISOR', 'TRIMMER',
        ];
    }
}

if (!function_exists('dept_type_map')) {
    /* Maps the logical "type" used by the management screen to its master table
       and the matching column on the employees table. */
    function dept_type_map() {
        return [
            'department'  => ['table' => 'departments',  'emp_col' => 'department',  'label' => 'Department'],
            'designation' => ['table' => 'designations', 'emp_col' => 'designation', 'label' => 'Position / Designation'],
        ];
    }
}

if (!function_exists('dept_ensure_schema')) {
    function dept_ensure_schema($conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_department_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS designations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_designation_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed each table once (only when empty) from the historical defaults
        // plus any values already used by employees, so nothing is lost.
        dept_seed_if_empty($conn, 'departments',  'department',  dept_default_departments());
        dept_seed_if_empty($conn, 'designations', 'designation', dept_default_designations());
    }

    function dept_seed_if_empty($conn, $table, $emp_col, array $defaults) {
        $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM `$table`"));
        if ((int)($cnt['c'] ?? 0) > 0) return;

        $values = $defaults;
        $col_q = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE '" . mysqli_real_escape_string($conn, $emp_col) . "'");
        if ($col_q && mysqli_num_rows($col_q) > 0) {
            $r = mysqli_query($conn, "SELECT DISTINCT `$emp_col` AS v FROM employees WHERE `$emp_col` IS NOT NULL AND `$emp_col` != ''");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $values[] = $row['v'];
        }

        $seen = [];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            $key = strtoupper($v);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $safe = mysqli_real_escape_string($conn, $v);
            mysqli_query($conn, "INSERT IGNORE INTO `$table` (name, status) VALUES ('$safe', 'Active')");
        }
    }
}

if (!function_exists('dept_option_list')) {
    /* Returns a sorted, de-duplicated list of option names: the active master
       entries combined with any value currently used by employees. */
    function dept_option_list($conn, $table, $emp_col) {
        dept_ensure_schema($conn);

        $list = [];
        $seen = [];
        $add  = function ($name) use (&$list, &$seen) {
            $name = trim((string)$name);
            if ($name === '') return;
            $key = strtoupper($name);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $list[] = $name;
        };

        $r = mysqli_query($conn, "SELECT name FROM `$table` WHERE status IS NULL OR status = '' OR status = 'Active' ORDER BY name ASC");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $add($row['name']);

        $col_q = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE '" . mysqli_real_escape_string($conn, $emp_col) . "'");
        if ($col_q && mysqli_num_rows($col_q) > 0) {
            $r2 = mysqli_query($conn, "SELECT DISTINCT `$emp_col` AS v FROM employees WHERE `$emp_col` IS NOT NULL AND `$emp_col` != ''");
            if ($r2) while ($row = mysqli_fetch_assoc($r2)) $add($row['v']);
        }

        usort($list, fn($a, $b) => strcasecmp($a, $b));
        return $list;
    }

    function dept_get_departments($conn)  { return dept_option_list($conn, 'departments',  'department'); }
    function dept_get_designations($conn) { return dept_option_list($conn, 'designations', 'designation'); }
}
?>
