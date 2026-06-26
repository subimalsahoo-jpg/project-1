<?php
include 'auth.php';
requireAnyPermission(['employee_view', 'accommodation_manage']);
include_once 'accommodation_helper.php';

acc_ensure_schema($conn);

function ac_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$logged_in_name = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User')));
$LOCATIONS = ['Saif Zone', 'Out Side'];

$flash = '';
$flash_type = 'ok';

$gender  = ($_GET['gender'] ?? '') === 'Girls' ? 'Girls' : (($_GET['gender'] ?? '') === 'Boys' ? 'Boys' : '');
$room_id = (int)($_GET['room_id'] ?? 0);
$loc     = in_array($_GET['loc'] ?? '', $LOCATIONS, true) ? $_GET['loc'] : '';

/* ─────────────────────────────────────────────
   POST actions
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_room') {
        $g   = ($_POST['gender'] ?? '') === 'Girls' ? 'Girls' : 'Boys';
        $loc = in_array($_POST['main_location'] ?? '', $LOCATIONS, true) ? $_POST['main_location'] : 'Saif Zone';
        $tb  = trim($_POST['tower_block'] ?? '');
        $rn  = trim($_POST['room_number'] ?? '');
        $rfor = in_array($_POST['room_for'] ?? 'Labour', ['Office Staff','Labour'], true) ? $_POST['room_for'] : 'Labour';
        $cap = (int)($_POST['capacity'] ?? 6);
        if ($cap <= 0) { $cap = 6; }
        if ($rn === '') {
            $flash = 'Room Number is required.'; $flash_type = 'err';
        } else {
            $eg = acc_esc($conn, $g); $el = acc_esc($conn, $loc);
            $etb = acc_esc($conn, $tb); $ern = acc_esc($conn, $rn);
            $erf = acc_esc($conn, $rfor);
            $ecb = acc_esc($conn, $logged_in_name);
            mysqli_query($conn, "
                INSERT INTO accommodation_rooms (gender, main_location, tower_block, room_number, room_for, capacity, created_by)
                VALUES ('$eg','$el','$etb','$ern','$erf','$cap','$ecb')
            ");
            $flash = "Room added ($loc · " . ($tb !== '' ? $tb . ' · ' : '') . "Room $rn · $rfor).";
        }
        $gender = $g;
    } elseif ($action === 'delete_room') {
        $rid = (int)($_POST['room_id'] ?? 0);
        if ($rid > 0) {
            mysqli_query($conn, "DELETE FROM accommodation_allocations WHERE room_id=$rid");
            mysqli_query($conn, "DELETE FROM accommodation_rooms WHERE id=$rid");
            $flash = 'Room and its allocations removed.';
        }
        $room_id = 0;
    } elseif ($action === 'allocate') {
        $rid = (int)($_POST['room_id'] ?? 0);
        $uno = trim($_POST['user_no'] ?? '');
        $room = acc_room($conn, $rid);
        $emp  = acc_find_employee($conn, $uno);
        if (!$room) {
            $flash = 'Room not found.'; $flash_type = 'err';
        } elseif (!$emp) {
            $flash = 'Employee not found.'; $flash_type = 'err';
        } else {
            $current = acc_employee_current($conn, $emp['user_no']);
            if ($current) {
                $flash = ac_h($emp['full_name'] ?? $emp['user_no']) . ' is already allocated (Room '
                       . ac_h($current['room_number']) . '). Remove from there first.';
                $flash_type = 'err';
            } elseif (acc_allocated_count($conn, $rid) >= (int)$room['capacity']) {
                $flash = 'Room is full — no free space.'; $flash_type = 'err';
            } else {
                $eu = acc_esc($conn, $emp['user_no']);
                $ei = acc_esc($conn, $emp['employee_id'] ?? $emp['user_no']);
                $en = acc_esc($conn, $emp['full_name'] ?? '');
                $ecb = acc_esc($conn, $logged_in_name);
                mysqli_query($conn, "
                    INSERT INTO accommodation_allocations (room_id, user_no, employee_id, employee_name, created_by)
                    VALUES ($rid, '$eu', '$ei', '$en', '$ecb')
                ");
                $flash = ac_h($emp['full_name'] ?? $emp['user_no']) . ' added to the room.';
            }
        }
        $room_id = $rid;
        if ($room) { $gender = $room['gender']; }
    } elseif ($action === 'deallocate') {
        $aid = (int)($_POST['allocation_id'] ?? 0);
        $rid = (int)($_POST['room_id'] ?? 0);
        if ($aid > 0) {
            mysqli_query($conn, "DELETE FROM accommodation_allocations WHERE id=$aid");
            $flash = 'Employee removed from the room.';
        }
        $room_id = $rid;
    }
}

/* ─────────────────────────────────────────────
   Excel (CSV) export of the full accommodation list
───────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exp_gender = ($_GET['gender'] ?? '') === 'Girls' ? 'Girls' : (($_GET['gender'] ?? '') === 'Boys' ? 'Boys' : '');
    $exp_loc    = in_array($_GET['loc'] ?? '', $LOCATIONS, true) ? $_GET['loc'] : '';
    $rows = acc_all_allocations($conn, $exp_gender, $exp_loc);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="accommodation_' . ($exp_gender ?: 'all') . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee Accommodation List']);
    fputcsv($out, ['Generated', date('d-M-Y H:i')]);
    if ($exp_gender !== '') { fputcsv($out, ['Gender', $exp_gender]); }
    if ($exp_loc !== '')    { fputcsv($out, ['Location', $exp_loc]); }
    fputcsv($out, ['Total', count($rows)]);
    fputcsv($out, []);
    fputcsv($out, ['SL','User No','Employee Name','Department','Gender','Main Location','Tower/Block','Room Number','Room For','Capacity']);
    $sl = 1;
    foreach ($rows as $r) {
        fputcsv($out, [
            $sl++,
            $r['user_no'],
            $r['full_name'] ?? $r['employee_name'],
            $r['department'] ?? '',
            $r['gender'],
            $r['main_location'],
            $r['tower_block'],
            $r['room_number'],
            $r['room_for'] ?? '',
            $r['capacity'],
        ]);
    }
    fclose($out);
    exit;
}

/* Employee preview (before allocating) */
$preview_emp = null;
$preview_current = null;
if ($room_id > 0 && trim($_GET['emp_search'] ?? '') !== '') {
    $preview_emp = acc_find_employee($conn, trim($_GET['emp_search']));
    if ($preview_emp) {
        $preview_current = acc_employee_current($conn, $preview_emp['user_no']);
    } else {
        $flash = 'No employee found for "' . ac_h(trim($_GET['emp_search'])) . '".';
        $flash_type = 'err';
    }
}

$room = $room_id > 0 ? acc_room($conn, $room_id) : null;
if ($room) { $gender = $room['gender']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Accommodation</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--red:#b91c1c;--red-soft:#fee2e2;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;}
.btn-back:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:6px;}
.crumbs{font-size:13px;color:var(--gray-600);margin-bottom:16px;}
.crumbs a{color:var(--brand-mid);text-decoration:none;}
.flash{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600;}
.flash.ok{background:var(--green-soft);color:var(--green);border:1px solid #b6e3c9;}
.flash.err{background:#fdecea;color:var(--red);border:1px solid #f5c6c0;}
.landing{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;max-width:760px;}
.landing.landing-4{grid-template-columns:repeat(4,1fr);max-width:none;}
.total-emp-box{background:#fff;border:1px solid var(--gray-200);border-left:5px solid var(--accent);border-radius:8px;padding:12px 18px;margin-bottom:18px;font-size:16px;font-weight:700;color:var(--brand);box-shadow:var(--shadow);display:inline-block;}
.total-emp-box b{color:var(--brand-mid);font-size:22px;margin-left:6px;}
.choice .sub{font-size:13px;font-weight:700;color:var(--accent);margin-top:2px;}
.choice{background:#fff;border-radius:12px;box-shadow:var(--shadow);padding:30px;text-decoration:none;color:var(--brand);text-align:center;border:2px solid transparent;transition:.15s;}
.choice:hover{border-color:var(--accent);transform:translateY(-2px);}
.choice .ic{font-size:46px;}
.choice .t{font-size:19px;font-weight:800;margin-top:10px;}
.choice .s{font-size:12px;color:var(--gray-600);margin-top:4px;}
.choice .count{font-size:34px;font-weight:800;color:var(--brand-mid);margin-top:8px;line-height:1;}
.choice .count-lbl{font-size:12px;color:var(--gray-600);margin-top:2px;}
.choice .split{font-size:12px;color:var(--gray-800);margin-top:8px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:6px;padding:5px 8px;display:inline-block;}
.loctabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
.loctab{padding:7px 16px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:700;background:#fff;color:var(--brand);border:1px solid var(--gray-200);}
.loctab.active{background:var(--brand);color:#fff;border-color:var(--brand);}
.scards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
.scard{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:12px 16px;}
.scard .l{font-size:12px;color:var(--gray-600);text-transform:uppercase;}
.scard .v{font-size:24px;font-weight:800;color:var(--brand);margin-top:3px;}
@media(max-width:760px){.scards{grid-template-columns:repeat(2,1fr);}}
.panel{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;}
.panel-head{background:var(--brand);color:#fff;padding:11px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;}
.panel-body{padding:16px;}
.row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fg .hint{font-size:11px;color:#94a3b8;}
.fg input,.fg select{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:150px;}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--brand-mid);color:#fff;}
.btn-success{background:var(--green);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px;}
.btn-danger{background:var(--red-soft);color:var(--red);}
.btn:hover{opacity:.93;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{background:var(--brand);color:#fff;padding:10px;text-align:center;font-size:12px;text-transform:uppercase;white-space:nowrap;}
tbody td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--gray-200);}
tbody tr:nth-child(even){background:var(--gray-50);}
tbody td.l{text-align:left;}
.table-wrap{overflow-x:auto;}
.free-pill{display:inline-block;min-width:30px;padding:3px 10px;border-radius:12px;font-weight:800;}
.free-yes{background:var(--green-soft);color:var(--green);}
.free-no{background:var(--red-soft);color:var(--red);}
.emp-box{background:var(--gray-50);border:1px dashed var(--gray-200);border-radius:8px;padding:12px 14px;margin-top:12px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;}
.emp-box span{display:block;font-size:11px;color:var(--gray-600);}
.emp-box b{font-size:15px;color:var(--brand);}
.muted{color:#94a3b8;}
.tag{display:inline-block;background:#eef3fb;color:var(--brand-mid);border-radius:6px;padding:2px 8px;font-size:12px;font-weight:700;}
.emp-photo{width:46px;height:46px;border-radius:8px;object-fit:cover;border:1px solid var(--gray-200);background:#fff;vertical-align:middle;}
.emp-photo-none{display:inline-flex;align-items:center;justify-content:center;font-size:24px;color:#94a3b8;}
@media(max-width:760px){.landing{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="btn-back">&#8592; Dashboard</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;') : ''; ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
</div>

<div class="page">
    <div class="page-title"><span>&#127968;</span> Employee Accommodation</div>
    <div class="crumbs">
        <a href="accommodation.php">Home</a>
        <?php if ($gender !== ''): ?> &rsaquo; <a href="accommodation.php?gender=<?php echo $gender; ?>"><?php echo $gender; ?> Accommodation</a><?php endif; ?>
        <?php if ($room): ?> &rsaquo; Room <?php echo ac_h($room['room_number']); ?><?php endif; ?>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="flash <?php echo $flash_type; ?>"><?php echo $flash; ?></div>
    <?php endif; ?>

<?php if ($gender === ''): /* ── Landing ── */
    $boys_saif  = acc_employee_count($conn, 'Boys', 'Saif Zone');
    $boys_out   = acc_employee_count($conn, 'Boys', 'Out Side');
    $girls_saif = acc_employee_count($conn, 'Girls', 'Saif Zone');
    $girls_out  = acc_employee_count($conn, 'Girls', 'Out Side');
    $grand_total = acc_total_housed($conn);
?>
    <div class="total-emp-box">Total Employee Housed: <b><?php echo $grand_total; ?></b></div>
    <div class="landing landing-4">
        <a class="choice" href="accommodation.php?gender=Boys&loc=Saif+Zone">
            <div class="ic">&#128102;</div>
            <div class="t">Boys Accommodation</div>
            <div class="sub">(Saif Zone)</div>
            <div class="count"><?php echo $boys_saif; ?></div>
            <div class="count-lbl">Boys employees housed</div>
        </a>
        <a class="choice" href="accommodation.php?gender=Girls&loc=Saif+Zone">
            <div class="ic">&#128103;</div>
            <div class="t">Girls Accommodation</div>
            <div class="sub">(Saif Zone)</div>
            <div class="count"><?php echo $girls_saif; ?></div>
            <div class="count-lbl">Girls employees housed</div>
        </a>
        <a class="choice" href="accommodation.php?gender=Boys&loc=Out+Side">
            <div class="ic">&#128102;</div>
            <div class="t">Boys Accommodation</div>
            <div class="sub">(Out Side)</div>
            <div class="count"><?php echo $boys_out; ?></div>
            <div class="count-lbl">Boys employees housed</div>
        </a>
        <a class="choice" href="accommodation.php?gender=Girls&loc=Out+Side">
            <div class="ic">&#128103;</div>
            <div class="t">Girls Accommodation</div>
            <div class="sub">(Out Side)</div>
            <div class="count"><?php echo $girls_out; ?></div>
            <div class="count-lbl">Girls employees housed</div>
        </a>
    </div>

<?php elseif (!$room): /* ── Room list for a gender ── */
    $emp_total = acc_employee_count($conn, $gender);
    $emp_saif  = acc_employee_count($conn, $gender, 'Saif Zone');
    $emp_out   = acc_employee_count($conn, $gender, 'Out Side');
    $rooms = acc_rooms_with_counts($conn, $gender, $loc);
    $cap_sum = 0; $free_sum = 0;
    foreach ($rooms as $rm) { $cap_sum += (int)$rm['capacity']; $free_sum += (int)$rm['free_space']; }
?>
    <!-- Location tabs -->
    <div class="loctabs">
        <a class="loctab <?php echo $loc === '' ? 'active' : ''; ?>" href="accommodation.php?gender=<?php echo $gender; ?>">All Locations</a>
        <a class="loctab <?php echo $loc === 'Saif Zone' ? 'active' : ''; ?>" href="accommodation.php?gender=<?php echo $gender; ?>&loc=Saif+Zone">Saif Zone</a>
        <a class="loctab <?php echo $loc === 'Out Side' ? 'active' : ''; ?>" href="accommodation.php?gender=<?php echo $gender; ?>&loc=Out+Side">Out Side (outside Saif Zone)</a>
    </div>

    <!-- Summary -->
    <div class="scards">
        <div class="scard"><div class="l">Total <?php echo $gender; ?> Housed</div><div class="v"><?php echo $emp_total; ?></div></div>
        <div class="scard"><div class="l">In Saif Zone</div><div class="v"><?php echo $emp_saif; ?></div></div>
        <div class="scard"><div class="l">Out Side</div><div class="v"><?php echo $emp_out; ?></div></div>
        <div class="scard"><div class="l">Free Space<?php echo $loc !== '' ? ' (' . ac_h($loc) . ')' : ''; ?></div><div class="v" style="color:var(--green);"><?php echo $free_sum; ?></div></div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <span><?php echo $gender; ?> Accommodation Details<?php echo $loc !== '' ? ' &middot; ' . ac_h($loc) : ' &middot; All Locations'; ?></span>
            <a class="btn btn-sm btn-success" href="accommodation.php?export=excel&gender=<?php echo $gender; ?><?php echo $loc !== '' ? '&loc=' . urlencode($loc) : ''; ?>">&#11015; Export Excel</a>
        </div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SL</th><th>Main Location</th><th>Tower / Block</th><th>Room Number</th><th>Room For</th>
                        <th>Capacity</th><th>Allocated Employee</th><th>Free Space</th><th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if (!empty($rooms)): $sl = 1; foreach ($rooms as $rm):
                ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo ac_h($rm['main_location']); ?></td>
                        <td><?php echo ac_h($rm['tower_block']); ?></td>
                        <td><b><?php echo ac_h($rm['room_number']); ?></b></td>
                        <td><?php echo ac_h($rm['room_for'] ?? 'Labour'); ?></td>
                        <td><?php echo (int)$rm['capacity']; ?></td>
                        <td><?php echo (int)$rm['allocated']; ?></td>
                        <td><span class="free-pill <?php echo $rm['free_space'] > 0 ? 'free-yes' : 'free-no'; ?>"><?php echo (int)$rm['free_space']; ?></span></td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-sm btn-primary" href="accommodation.php?gender=<?php echo $gender; ?>&room_id=<?php echo (int)$rm['id']; ?>">Details</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this room and all its allocations?');">
                                <input type="hidden" name="action" value="delete_room">
                                <input type="hidden" name="room_id" value="<?php echo (int)$rm['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="muted" style="padding:18px;">No rooms<?php echo $loc !== '' ? ' in ' . ac_h($loc) : ''; ?> yet. Add one below.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">Add Room</div>
        <div class="panel-body">
            <form method="POST" class="row">
                <input type="hidden" name="action" value="add_room">
                <input type="hidden" name="gender" value="<?php echo $gender; ?>">
                <div class="fg">
                    <label>Main Location</label>
                    <select name="main_location">
                        <?php foreach ($LOCATIONS as $locopt): ?>
                        <option value="<?php echo ac_h($locopt); ?>" <?php echo ($loc !== '' && $loc === $locopt) ? 'selected' : ''; ?>><?php echo ac_h($locopt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg"><label>Tower / Block</label><input type="text" name="tower_block" placeholder="e.g. Tower A"></div>
                <div class="fg"><label>Room Number</label><input type="text" name="room_number" placeholder="e.g. 101" required></div>
                <div class="fg">
                    <label>Room For</label>
                    <select name="room_for">
                        <option value="Labour">Labour</option>
                        <option value="Office Staff">Office Staff</option>
                    </select>
                </div>
                <div class="fg"><label>Capacity</label><input type="number" name="capacity" value="6" min="1"><span class="hint">Default 6.</span></div>
                <button class="btn btn-success" type="submit">&#43; Add Room</button>
            </form>
        </div>
    </div>

<?php else: /* ── Room detail ── */
    $allocated = acc_allocated_count($conn, $room_id);
    $free = max(0, (int)$room['capacity'] - $allocated);
    $emps = acc_room_employees($conn, $room_id);
?>
    <div class="panel">
        <div class="panel-head">
            <span>Room <?php echo ac_h($room['room_number']); ?> &middot; <?php echo ac_h($room['main_location']); ?><?php echo $room['tower_block'] !== '' ? ' &middot; ' . ac_h($room['tower_block']) : ''; ?></span>
            <span class="tag"><?php echo $room['gender']; ?></span>
        </div>
        <div class="panel-body">
            <div class="row" style="gap:26px;">
                <div><span class="muted">Capacity</span><div style="font-size:22px;font-weight:800;"><?php echo (int)$room['capacity']; ?></div></div>
                <div><span class="muted">Allocated Employee</span><div style="font-size:22px;font-weight:800;color:var(--brand-mid);"><?php echo $allocated; ?></div></div>
                <div><span class="muted">Free Space</span><div style="font-size:22px;font-weight:800;color:<?php echo $free > 0 ? 'var(--green)' : 'var(--red)'; ?>;"><?php echo $free; ?></div></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">Employees in this Room</div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>SL</th><th>Photo</th><th>User No.</th><th>Name</th><th>Location Name</th><th>Block / Tower</th><th>Room Number</th><th>Department</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php if (!empty($emps)): $sl = 1; foreach ($emps as $e): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td>
                            <?php if (!empty($e['photo'])): ?>
                                <img src="uploads/<?php echo ac_h($e['photo']); ?>" alt="Photo" class="emp-photo">
                            <?php else: ?>
                                <span class="emp-photo emp-photo-none">&#128100;</span>
                            <?php endif; ?>
                        </td>
                        <td><b><?php echo ac_h($e['user_no']); ?></b></td>
                        <td class="l"><?php echo ac_h($e['full_name'] ?? $e['employee_name']); ?></td>
                        <td><?php echo ac_h($room['main_location']); ?></td>
                        <td><?php echo ac_h($room['tower_block']); ?></td>
                        <td><?php echo ac_h($room['room_number']); ?></td>
                        <td><?php echo ac_h($e['department'] ?? ''); ?></td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this employee from the room?');">
                                <input type="hidden" name="action" value="deallocate">
                                <input type="hidden" name="allocation_id" value="<?php echo (int)$e['allocation_id']; ?>">
                                <input type="hidden" name="room_id" value="<?php echo (int)$room_id; ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="muted" style="padding:18px;">No employees allocated yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">Add Employee to this Room</div>
        <div class="panel-body">
            <?php if ($free <= 0): ?>
                <div class="flash err" style="margin:0;">This room is full (Free Space 0). Remove someone or use another room.</div>
            <?php else: ?>
            <form method="GET" class="row">
                <input type="hidden" name="gender" value="<?php echo $gender; ?>">
                <input type="hidden" name="room_id" value="<?php echo (int)$room_id; ?>">
                <div class="fg" style="flex:1;">
                    <label>Employee User No / ID / Name</label>
                    <input type="text" name="emp_search" value="<?php echo ac_h(trim($_GET['emp_search'] ?? '')); ?>" placeholder="e.g. 1604" style="min-width:260px;">
                </div>
                <button class="btn btn-primary" type="submit">&#128269; Find</button>
            </form>

            <?php if ($preview_emp): ?>
                <div class="emp-box">
                    <div><span>Employee ID</span><b><?php echo ac_h($preview_emp['employee_id'] !== '' ? $preview_emp['employee_id'] : $preview_emp['user_no']); ?></b></div>
                    <div><span>User No</span><b><?php echo ac_h($preview_emp['user_no']); ?></b></div>
                    <div><span>Name</span><b><?php echo ac_h($preview_emp['full_name'] ?? ''); ?></b></div>
                    <div><span>Gender</span><b><?php echo ac_h($preview_emp['gender'] ?? ''); ?></b></div>
                    <div><span>Department</span><b><?php echo ac_h($preview_emp['department'] ?? ''); ?></b></div>
                    <?php if ($preview_current): ?>
                        <div class="flash err" style="margin:0;">Already in Room <?php echo ac_h($preview_current['room_number']); ?> (<?php echo ac_h($preview_current['gender']); ?>). Remove from there first.</div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="allocate">
                            <input type="hidden" name="room_id" value="<?php echo (int)$room_id; ?>">
                            <input type="hidden" name="user_no" value="<?php echo ac_h($preview_emp['user_no']); ?>">
                            <button class="btn btn-success" type="submit">&#43; Add to this Room</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php
                $boy_girl_gender = strtolower((string)($preview_emp['gender'] ?? ''));
                $expected = $gender === 'Boys' ? 'male' : 'female';
                if ($boy_girl_gender !== '' && strpos($boy_girl_gender, $expected) === false):
                ?>
                <div style="margin-top:8px;font-size:12px;color:var(--red);">&#9888; Note: this employee's gender (<?php echo ac_h($preview_emp['gender']); ?>) does not match a <?php echo $gender; ?> room.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</div>
</body>
</html>
