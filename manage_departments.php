<?php
include 'auth.php';
require_once 'department_helper.php';

/* Department / Position management is ADMIN ONLY. */
requireLogin();
if (!is_admin_user()) {
    http_response_code(403);
    echo "<h2 style='font-family:Arial;color:#c0392b;text-align:center;margin-top:60px;'>Access Denied</h2>";
    echo "<p style='font-family:Arial;text-align:center;'>Only an Admin can manage Departments and Positions.</p>";
    exit();
}

dept_ensure_schema($conn);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$type_map = dept_type_map();
$message  = '';
$error    = '';

/* Resolve the requested type, defaulting to department. */
function dept_resolve_type($value, $type_map) {
    $value = trim((string)$value);
    return isset($type_map[$value]) ? $value : 'department';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $type   = dept_resolve_type($_POST['type'] ?? '', $type_map);
    $table   = $type_map[$type]['table'];
    $emp_col = $type_map[$type]['emp_col'];
    $label   = $type_map[$type]['label'];

    $emp_col_exists = false;
    $cq = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE '" . mysqli_real_escape_string($conn, $emp_col) . "'");
    if ($cq && mysqli_num_rows($cq) > 0) $emp_col_exists = true;

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = "$label name is required.";
        } else {
            $safe = mysqli_real_escape_string($conn, $name);
            $dup = mysqli_query($conn, "SELECT id FROM `$table` WHERE LOWER(name) = LOWER('$safe') LIMIT 1");
            if ($dup && mysqli_num_rows($dup) > 0) {
                $error = "\"$name\" already exists.";
            } else {
                mysqli_query($conn, "INSERT INTO `$table` (name, status) VALUES ('$safe', 'Active')");
                $message = "$label \"$name\" added.";
            }
        }
    }

    if ($action === 'rename') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') {
            $error = "A valid name is required to rename.";
        } else {
            $cur = mysqli_query($conn, "SELECT name FROM `$table` WHERE id = $id LIMIT 1");
            if (!$cur || mysqli_num_rows($cur) === 0) {
                $error = "Record not found.";
            } else {
                $old_name = mysqli_fetch_assoc($cur)['name'];
                $safe_new = mysqli_real_escape_string($conn, $name);
                $dup = mysqli_query($conn, "SELECT id FROM `$table` WHERE LOWER(name) = LOWER('$safe_new') AND id <> $id LIMIT 1");
                if ($dup && mysqli_num_rows($dup) > 0) {
                    $error = "\"$name\" already exists.";
                } else {
                    mysqli_query($conn, "UPDATE `$table` SET name = '$safe_new' WHERE id = $id");
                    // Cascade the rename to all employees holding the old value,
                    // so existing records stay consistent with the new label.
                    $updated = 0;
                    if ($emp_col_exists && $old_name !== $name) {
                        $safe_old = mysqli_real_escape_string($conn, $old_name);
                        mysqli_query($conn, "UPDATE employees SET `$emp_col` = '$safe_new' WHERE `$emp_col` = '$safe_old'");
                        $updated = mysqli_affected_rows($conn);
                    }
                    $message = "$label renamed to \"$name\""
                        . ($updated > 0 ? " ($updated employee record(s) updated)." : ".");
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $cur = mysqli_query($conn, "SELECT name FROM `$table` WHERE id = $id LIMIT 1");
            $name = ($cur && mysqli_num_rows($cur) > 0) ? mysqli_fetch_assoc($cur)['name'] : '';
            $in_use = 0;
            if ($emp_col_exists && $name !== '') {
                $safe = mysqli_real_escape_string($conn, $name);
                $uq = mysqli_query($conn, "SELECT COUNT(*) AS c FROM employees WHERE `$emp_col` = '$safe'");
                $in_use = $uq ? (int)(mysqli_fetch_assoc($uq)['c'] ?? 0) : 0;
            }
            if ($in_use > 0) {
                $error = "Cannot delete \"$name\" — it is still assigned to $in_use employee(s). Rename it instead, or reassign those employees first.";
            } else {
                mysqli_query($conn, "DELETE FROM `$table` WHERE id = $id");
                $message = "$label \"$name\" deleted.";
            }
        }
    }
}

/* Editing state (from the list "Edit" links). */
$edit_type = dept_resolve_type($_GET['type'] ?? '', $type_map);
$edit_id   = (int)($_GET['edit'] ?? 0);
$edit_name = '';
if ($edit_id > 0) {
    $et = $type_map[$edit_type]['table'];
    $er = mysqli_query($conn, "SELECT name FROM `$et` WHERE id = $edit_id LIMIT 1");
    if ($er && mysqli_num_rows($er) > 0) {
        $edit_name = mysqli_fetch_assoc($er)['name'];
    } else {
        $edit_id = 0;
    }
}

/* Load both lists with their in-use employee counts. */
function dept_load_rows($conn, $table, $emp_col) {
    $emp_col_exists = false;
    $cq = mysqli_query($conn, "SHOW COLUMNS FROM employees LIKE '" . mysqli_real_escape_string($conn, $emp_col) . "'");
    if ($cq && mysqli_num_rows($cq) > 0) $emp_col_exists = true;

    $rows = [];
    $r = mysqli_query($conn, "SELECT id, name, status FROM `$table` ORDER BY name ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $used = 0;
            if ($emp_col_exists) {
                $safe = mysqli_real_escape_string($conn, $row['name']);
                $uq = mysqli_query($conn, "SELECT COUNT(*) AS c FROM employees WHERE `$emp_col` = '$safe'");
                $used = $uq ? (int)(mysqli_fetch_assoc($uq)['c'] ?? 0) : 0;
            }
            $row['in_use'] = $used;
            $rows[] = $row;
        }
    }
    return $rows;
}

$department_rows  = dept_load_rows($conn, 'departments',  'department');
$designation_rows = dept_load_rows($conn, 'designations', 'designation');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Departments &amp; Positions</title>
<style>
    body{font-family:Arial;background:#f4f6f9;margin:0;padding:24px 24px 24px 274px;color:#111827;}
    @media(max-width:860px){ body{ padding-left:24px; } }
    .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
    .top h2{margin:0;}
    .btn,button{background:#2d9cdb;color:#fff;border:0;padding:9px 14px;border-radius:4px;text-decoration:none;cursor:pointer;font-weight:bold;font-size:13px;}
    .dark{background:#23364d;}
    .danger{background:#e52525;}
    .muted{background:#64748b;}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(320px,1fr));gap:20px;}
    @media(max-width:900px){ .grid2{grid-template-columns:1fr;} }
    .card{background:#fff;border-radius:8px;padding:18px;margin-bottom:20px;box-shadow:0 2px 12px rgba(0,0,0,.08);}
    .card h3{margin:0 0 14px;color:#23364d;}
    label{font-weight:bold;display:block;margin-bottom:6px;}
    input[type=text]{padding:9px;border:1px solid #cbd5e1;border-radius:4px;width:100%;max-width:340px;}
    form.inline{display:inline;}
    table{width:100%;border-collapse:collapse;background:#fff;margin-top:6px;}
    th,td{border:1px solid #d1d5db;padding:8px 9px;text-align:left;font-size:13px;}
    th{background:#23364d;color:#fff;}
    .pill{display:inline-block;background:#eef2f7;color:#475569;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:bold;}
    .row-actions{white-space:nowrap;}
    .msg{background:#e7f8ed;color:#0b7f31;padding:10px;border-radius:4px;margin-bottom:12px;}
    .err{background:#ffe5e5;color:#b00020;padding:10px;border-radius:4px;margin-bottom:12px;}
    .add-form{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px;}
    .hint{color:#64748b;font-size:12px;margin:4px 0 14px;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<div class="top">
    <h2>&#127970; Departments &amp; Positions</h2>
    <div>
        <a class="btn dark" href="dashboard.php">&#8592; Dashboard</a>
        <a class="btn danger" href="logout.php">Logout</a>
    </div>
</div>

<p class="hint">Admin-only. Options added or renamed here appear in the Department and Position/Designation dropdowns across the system. Renaming an option also updates every employee currently assigned to it.</p>

<?php if ($message !== ''): ?><div class="msg"><?php echo h($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>

<div class="grid2">
<?php
$sections = [
    ['type' => 'department',  'title' => 'Departments',           'rows' => $department_rows],
    ['type' => 'designation', 'title' => 'Positions / Designations', 'rows' => $designation_rows],
];
foreach ($sections as $sec):
    $type  = $sec['type'];
    $rows  = $sec['rows'];
    $is_editing = ($edit_id > 0 && $edit_type === $type);
?>
    <div class="card">
        <h3><?php echo h($sec['title']); ?> <span class="pill"><?php echo count($rows); ?></span></h3>

        <form method="POST" class="add-form">
            <input type="hidden" name="action" value="<?php echo $is_editing ? 'rename' : 'add'; ?>">
            <input type="hidden" name="type" value="<?php echo h($type); ?>">
            <?php if ($is_editing): ?><input type="hidden" name="id" value="<?php echo (int)$edit_id; ?>"><?php endif; ?>
            <div style="flex:1;min-width:240px;">
                <label><?php echo $is_editing ? 'Rename to' : 'Add new'; ?></label>
                <input type="text" name="name" value="<?php echo $is_editing ? h($edit_name) : ''; ?>"
                       placeholder="Enter name..." required>
            </div>
            <div>
                <button type="submit"><?php echo $is_editing ? '&#128190; Save' : '&#43; Add'; ?></button>
                <?php if ($is_editing): ?>
                    <a class="btn muted" href="manage_departments.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <table>
            <tr>
                <th>Name</th>
                <th>In Use</th>
                <th style="width:170px;">Action</th>
            </tr>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr>
                <td><?php echo h($r['name']); ?></td>
                <td><?php echo (int)$r['in_use']; ?></td>
                <td class="row-actions">
                    <a class="btn" href="manage_departments.php?type=<?php echo h($type); ?>&edit=<?php echo (int)$r['id']; ?>">Edit</a>
                    <form method="POST" class="inline"
                          onsubmit="return confirm('Delete this entry? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type" value="<?php echo h($type); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <button class="danger" type="submit" <?php echo ((int)$r['in_use'] > 0) ? 'title="In use — rename instead"' : ''; ?>>Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="3">No entries yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>
<?php endforeach; ?>
</div>

</body>
</html>
