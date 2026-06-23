<?php
include 'auth.php';
requirePermission('user_manage');

$message = '';
$error = '';
$roles = ['Admin', 'HR', 'Salary', 'Attendance', 'Viewer'];
$permissions = permission_definitions();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'Viewer';
        $status = $_POST['status'] ?? 'Active';
        $selected_permissions = $_POST['permissions'] ?? [];

        if (!in_array($role, $roles, true)) $role = 'Viewer';
        if (!in_array($status, ['Active', 'Inactive'], true)) $status = 'Active';

        if ($username === '') {
            $error = 'Username is required.';
        } else {
            $safe_username = mysqli_real_escape_string($conn, $username);
            $safe_name = mysqli_real_escape_string($conn, $full_name);
            $safe_role = mysqli_real_escape_string($conn, $role);
            $safe_status = mysqli_real_escape_string($conn, $status);

            if ($id > 0) {
                $duplicate = mysqli_query($conn, "SELECT id FROM users WHERE username='$safe_username' AND id<>$id LIMIT 1");
                if ($duplicate && mysqli_num_rows($duplicate) > 0) {
                    $error = 'This username already exists.';
                } else {
                    $password_sql = '';
                    if ($password !== '') {
                        $hash = mysqli_real_escape_string($conn, password_hash($password, PASSWORD_DEFAULT));
                        $password_sql = ", password='$hash'";
                    }
                    mysqli_query($conn, "
                        UPDATE users
                        SET username='$safe_username', full_name='$safe_name', role='$safe_role', status='$safe_status' $password_sql
                        WHERE id=$id
                    ");
                    $message = 'User updated successfully.';
                }
            } else {
                $duplicate = mysqli_query($conn, "SELECT id FROM users WHERE username='$safe_username' LIMIT 1");
                if ($duplicate && mysqli_num_rows($duplicate) > 0) {
                    $error = 'This username already exists.';
                } elseif ($password === '') {
                    $error = 'Password is required for new user.';
                } else {
                    $hash = mysqli_real_escape_string($conn, password_hash($password, PASSWORD_DEFAULT));
                    mysqli_query($conn, "
                        INSERT INTO users (username, password, full_name, role, status)
                        VALUES ('$safe_username', '$hash', '$safe_name', '$safe_role', '$safe_status')
                    ");
                    $id = (int)mysqli_insert_id($conn);
                    $message = 'User created successfully.';
                }
            }

            if ($error === '' && $id > 0) {
                mysqli_query($conn, "DELETE FROM user_permissions WHERE user_id=$id");
                foreach ($permissions as $permission => $label) {
                    $allowed = in_array($permission, $selected_permissions, true) ? 1 : 0;
                    $safe_permission = mysqli_real_escape_string($conn, $permission);
                    mysqli_query($conn, "
                        INSERT INTO user_permissions (user_id, permission_name, allowed)
                        VALUES ($id, '$safe_permission', $allowed)
                    ");
                }
            }
        }
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === current_user_id()) {
            $error = 'You cannot delete your own user.';
        } elseif ($id > 0) {
            mysqli_query($conn, "DELETE FROM user_permissions WHERE user_id=$id");
            mysqli_query($conn, "DELETE FROM users WHERE id=$id");
            $message = 'User deleted successfully.';
        }
    }
}

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_user = [
    'id' => 0,
    'username' => '',
    'full_name' => '',
    'role' => 'Viewer',
    'status' => 'Active',
];
$edit_permissions = [];

if ($edit_id > 0) {
    $result = mysqli_query($conn, "SELECT * FROM users WHERE id=$edit_id LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $edit_user = mysqli_fetch_assoc($result);
        $pres = mysqli_query($conn, "SELECT permission_name, allowed FROM user_permissions WHERE user_id=$edit_id");
        if ($pres) {
            while ($row = mysqli_fetch_assoc($pres)) {
                if ((int)$row['allowed'] === 1) $edit_permissions[] = $row['permission_name'];
            }
        }
    }
}

if (empty($edit_permissions) && $edit_id > 0) {
    $edit_permissions = role_permissions($edit_user['role'] ?? 'Viewer');
}

$users = mysqli_query($conn, "SELECT id, username, full_name, role, status, created_at FROM users ORDER BY id ASC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Management</title>
    <style>
        body{font-family:Arial;background:#f4f6f9;margin:0;padding:24px 24px 24px 274px;color:#111827;}
        @media(max-width:860px){ body{ padding-left:24px; } }
        .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
        .btn,button{background:#2d9cdb;color:white;border:0;padding:9px 14px;border-radius:4px;text-decoration:none;cursor:pointer;font-weight:bold;}
        .dark{background:#23364d;}
        .danger{background:#e52525;}
        .card{background:white;border-radius:8px;padding:18px;margin-bottom:20px;box-shadow:0 2px 12px rgba(0,0,0,.08);}
        label{font-weight:bold;display:block;margin-top:10px;}
        input,select{padding:9px;border:1px solid #cbd5e1;border-radius:4px;min-width:260px;}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:10px 24px;}
        .permissions{display:grid;grid-template-columns:repeat(3,minmax(210px,1fr));gap:8px;margin-top:12px;}
        .permissions label{font-weight:normal;margin:0;background:#f8fafc;padding:8px;border:1px solid #e5e7eb;border-radius:4px;}
        table{width:100%;border-collapse:collapse;background:white;}
        th,td{border:1px solid #d1d5db;padding:9px;text-align:left;}
        th{background:#23364d;color:white;}
        .msg{background:#e7f8ed;color:#0b7f31;padding:10px;border-radius:4px;margin-bottom:12px;}
        .err{background:#ffe5e5;color:#b00020;padding:10px;border-radius:4px;margin-bottom:12px;}
        .inline{display:inline;}
    </style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="top">
    <h2>User Management</h2>
    <div>
        <a class="btn dark" href="dashboard.php">Dashboard</a>
        <a class="btn danger" href="logout.php">Logout</a>
    </div>
</div>

<?php if($message !== ''){ ?><div class="msg"><?php echo h($message); ?></div><?php } ?>
<?php if($error !== ''){ ?><div class="err"><?php echo h($error); ?></div><?php } ?>

<div class="card">
    <h3><?php echo ((int)$edit_user['id'] > 0) ? 'Edit User' : 'Add New User'; ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="id" value="<?php echo (int)$edit_user['id']; ?>">

        <div class="grid">
            <div>
                <label>Username</label>
                <input type="text" name="username" value="<?php echo h($edit_user['username']); ?>" required>
            </div>
            <div>
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?php echo h($edit_user['full_name'] ?? ''); ?>">
            </div>
            <div>
                <label>Password <?php echo ((int)$edit_user['id'] > 0) ? '(blank = no change)' : ''; ?></label>
                <input type="password" name="password" <?php echo ((int)$edit_user['id'] > 0) ? '' : 'required'; ?>>
            </div>
            <div>
                <label>Role</label>
                <select name="role">
                    <?php foreach($roles as $role){ ?>
                        <option value="<?php echo h($role); ?>" <?php echo (($edit_user['role'] ?? '') === $role) ? 'selected' : ''; ?>>
                            <?php echo h($role); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="Active" <?php echo (($edit_user['status'] ?? '') === 'Active') ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo (($edit_user['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
        </div>

        <h4>Permissions</h4>
        <div class="permissions">
            <?php foreach($permissions as $permission => $label){ ?>
                <label>
                    <input type="checkbox" name="permissions[]" value="<?php echo h($permission); ?>"
                        <?php echo in_array($permission, $edit_permissions, true) ? 'checked' : ''; ?>>
                    <?php echo h($label); ?>
                </label>
            <?php } ?>
        </div>

        <p>
            <button type="submit">Save User</button>
            <a class="btn dark" href="manage_users.php">Clear Form</a>
        </p>
    </form>
</div>

<div class="card">
    <h3>All Users</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Role</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php if($users && mysqli_num_rows($users) > 0){ ?>
            <?php while($u = mysqli_fetch_assoc($users)){ ?>
            <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo h($u['username']); ?></td>
                <td><?php echo h($u['full_name']); ?></td>
                <td><?php echo h($u['role']); ?></td>
                <td><?php echo h($u['status']); ?></td>
                <td>
                    <a class="btn" href="manage_users.php?edit=<?php echo (int)$u['id']; ?>">Edit</a>
                    <?php if((int)$u['id'] !== current_user_id()){ ?>
                    <form method="POST" class="inline" onsubmit="return confirm('Delete this user?');">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                        <button class="danger" type="submit">Delete</button>
                    </form>
                    <?php } ?>
                </td>
            </tr>
            <?php } ?>
        <?php } else { ?>
            <tr><td colspan="6">No user found.</td></tr>
        <?php } ?>
    </table>
</div>
</body>
</html>
