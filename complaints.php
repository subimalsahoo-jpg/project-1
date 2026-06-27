<?php
/* ─────────────────────────────────────────────
   Complaints List — all employee complaints in one place.
   Shows Open vs Closed counts, filters, and links to each
   employee's complaint thread (Employee Overview → Complaints).

   Open    = Pending / In Progress (and blank status)
   Closed  = Solved / Rejected
───────────────────────────────────────────── */
include 'auth.php';
requireAnyPermission(['complaints_manage']);

function cmp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cmp_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-M-Y', $t) : $v;
}
function cmp_is_open($status) {
    $s = strtolower(trim((string)$status));
    return ($s === '' || $s === 'pending' || $s === 'in progress');
}

/* Ensure the complaints table exists (it is created on demand elsewhere). */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_no VARCHAR(50) NOT NULL,
    employee_name VARCHAR(150) DEFAULT '',
    complaint_type VARCHAR(60) DEFAULT '',
    complaint_subject VARCHAR(200) DEFAULT '',
    complaint_details TEXT,
    complaint_date DATE NULL,
    priority_level VARCHAR(20) DEFAULT 'Medium',
    complaint_status VARCHAR(30) DEFAULT 'Pending',
    hr_reply VARCHAR(500) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Quick status update from this page */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $cid = (int)($_POST['complaint_id'] ?? 0);
    $st  = in_array($_POST['complaint_status'] ?? '', ['Pending','In Progress','Solved','Rejected'], true) ? $_POST['complaint_status'] : 'Pending';
    $rep = trim($_POST['hr_reply'] ?? '');
    if ($cid > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE complaints SET complaint_status=?, hr_reply=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'ssi', $st, $rep, $cid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/* Filters */
$f_view   = $_GET['view'] ?? 'all';      // all | open | closed
$f_status = $_GET['status'] ?? '';
$f_type   = $_GET['type'] ?? '';
$f_search = trim($_GET['q'] ?? '');

$where = [];
if ($f_view === 'open')   { $where[] = "(complaint_status IS NULL OR complaint_status='' OR complaint_status IN ('Pending','In Progress'))"; }
if ($f_view === 'closed') { $where[] = "complaint_status IN ('Solved','Rejected')"; }
if ($f_status !== '' && in_array($f_status, ['Pending','In Progress','Solved','Rejected'], true)) {
    $where[] = "complaint_status='" . mysqli_real_escape_string($conn, $f_status) . "'";
}
if ($f_type !== '') { $where[] = "complaint_type='" . mysqli_real_escape_string($conn, $f_type) . "'"; }
if ($f_search !== '') {
    $s = mysqli_real_escape_string($conn, $f_search);
    $where[] = "(user_no LIKE '%$s%' OR employee_name LIKE '%$s%' OR complaint_subject LIKE '%$s%')";
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$rq = mysqli_query($conn, "SELECT * FROM complaints $where_sql ORDER BY complaint_date DESC, id DESC");
if ($rq) { while ($r = mysqli_fetch_assoc($rq)) { $rows[] = $r; } }

/* Overall counts (ignore filters) */
$total = 0; $open = 0; $closed = 0; $high_open = 0;
$cq = mysqli_query($conn, "SELECT complaint_status, priority_level FROM complaints");
if ($cq) {
    while ($c = mysqli_fetch_assoc($cq)) {
        $total++;
        if (cmp_is_open($c['complaint_status'])) {
            $open++;
            if (strtolower((string)$c['priority_level']) === 'high') { $high_open++; }
        } else { $closed++; }
    }
}

/* Distinct types for the filter */
$types = [];
$tq = mysqli_query($conn, "SELECT DISTINCT complaint_type FROM complaints WHERE complaint_type<>'' ORDER BY complaint_type");
if ($tq) { while ($t = mysqli_fetch_assoc($tq)) { $types[] = $t['complaint_type']; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Complaints</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--amber:#d97706;--amber-soft:#fef3c7;--red:#b91c1c;--red-soft:#fee2e2;--info:#2563a8;--info-soft:#dbeafe;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;}
.btn-back:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px;}
.card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:14px 16px;text-decoration:none;color:inherit;display:block;border:2px solid transparent;}
.card:hover{border-color:var(--accent);}
.card .label{font-size:12px;color:var(--gray-600);text-transform:uppercase;letter-spacing:.03em;}
.card .value{font-size:26px;font-weight:800;color:var(--brand);margin-top:4px;}
.card.open .value{color:var(--amber);}
.card.closed .value{color:var(--green);}
.card.high .value{color:var(--red);}
.panel{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;}
.panel-head{background:var(--brand);color:#fff;padding:11px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.panel-body{padding:16px;}
.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
.filters select,.filters input{padding:7px 10px;border-radius:6px;border:1px solid var(--gray-200);font-size:13px;}
.viewtabs{display:flex;gap:6px;}
.viewtab{padding:6px 14px;border-radius:6px;background:#fff;color:var(--brand);text-decoration:none;font-weight:700;font-size:13px;border:1px solid var(--gray-200);}
.viewtab.active{background:var(--brand);color:#fff;}
.btn{padding:8px 14px;border-radius:7px;border:none;cursor:pointer;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-accent{background:var(--accent);color:#3a2a00;}
.btn-primary{background:var(--brand-mid);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{background:var(--brand);color:#fff;padding:10px;text-align:center;font-size:12px;text-transform:uppercase;white-space:nowrap;}
tbody td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--gray-200);vertical-align:middle;}
tbody tr:nth-child(even){background:var(--gray-50);}
tbody td.l{text-align:left;}
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.b-open{background:var(--amber-soft);color:var(--amber);}
.b-prog{background:var(--info-soft);color:var(--info);}
.b-solved{background:var(--green-soft);color:var(--green);}
.b-rej{background:var(--red-soft);color:var(--red);}
.pri-High{background:var(--red-soft);color:var(--red);}
.pri-Medium{background:var(--amber-soft);color:var(--amber);}
.pri-Low{background:var(--green-soft);color:var(--green);}
.unolink{font-weight:800;color:var(--brand-mid);text-decoration:none;}
.unolink:hover{text-decoration:underline;}
.table-wrap{overflow-x:auto;}
.muted{color:#94a3b8;}
.upd{display:flex;gap:5px;align-items:center;justify-content:center;flex-wrap:wrap;}
.upd select,.upd input{padding:5px 7px;border-radius:6px;border:1px solid var(--gray-200);font-size:12px;}
@media(max-width:900px){.cards{grid-template-columns:repeat(2,1fr);}}
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
    <div class="page-title"><span>&#128221;</span> Complaints</div>

    <div class="cards">
        <a class="card" href="complaints.php?view=all"><div class="label">Total Complaints</div><div class="value"><?php echo $total; ?></div></a>
        <a class="card open" href="complaints.php?view=open"><div class="label">Open</div><div class="value"><?php echo $open; ?></div></a>
        <a class="card closed" href="complaints.php?view=closed"><div class="label">Closed</div><div class="value"><?php echo $closed; ?></div></a>
        <a class="card high" href="complaints.php?view=open"><div class="label">High Priority (Open)</div><div class="value"><?php echo $high_open; ?></div></a>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="viewtabs">
                <a class="viewtab <?php echo $f_view==='all'?'active':''; ?>" href="complaints.php?view=all">All</a>
                <a class="viewtab <?php echo $f_view==='open'?'active':''; ?>" href="complaints.php?view=open">Open</a>
                <a class="viewtab <?php echo $f_view==='closed'?'active':''; ?>" href="complaints.php?view=closed">Closed</a>
            </div>
            <form method="GET" class="filters">
                <input type="hidden" name="view" value="<?php echo cmp_h($f_view); ?>">
                <select name="type">
                    <option value="">All Types</option>
                    <?php foreach ($types as $t): ?><option value="<?php echo cmp_h($t); ?>" <?php echo $f_type===$t?'selected':''; ?>><?php echo cmp_h($t); ?></option><?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">Any Status</option>
                    <?php foreach (['Pending','In Progress','Solved','Rejected'] as $st): ?><option value="<?php echo $st; ?>" <?php echo $f_status===$st?'selected':''; ?>><?php echo $st; ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="q" value="<?php echo cmp_h($f_search); ?>" placeholder="User No / Name / Subject">
                <button class="btn btn-accent" type="submit">&#128269; Filter</button>
                <a class="btn btn-gray" href="complaints.php">Clear</a>
            </form>
        </div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>Date</th><th>Employee</th><th>Type</th><th>Subject</th><th>Priority</th><th>Status</th><th>HR Reply</th><th>Update</th></tr></thead>
                <tbody>
                <?php if (!empty($rows)): $sl = 1; foreach ($rows as $c):
                    $st = trim((string)($c['complaint_status'] ?? '')) ?: 'Pending';
                    $sb = $st === 'Solved' ? 'b-solved' : ($st === 'Rejected' ? 'b-rej' : ($st === 'In Progress' ? 'b-prog' : 'b-open'));
                    $pri = $c['priority_level'] ?: 'Medium';
                ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo cmp_dmy($c['complaint_date']); ?></td>
                        <td class="l"><a class="unolink" href="employee_overview.php?search=<?php echo urlencode((string)$c['user_no']); ?>&tab=complain" title="Open complaint thread"><?php echo cmp_h($c['employee_name'] ?: $c['user_no']); ?></a><div class="muted" style="font-size:11px;">User No: <?php echo cmp_h($c['user_no']); ?></div></td>
                        <td><?php echo cmp_h($c['complaint_type']); ?></td>
                        <td class="l"><?php echo cmp_h($c['complaint_subject']); ?><?php if (!empty($c['complaint_details'])): ?><div class="muted" style="font-size:11px;"><?php echo cmp_h(mb_substr((string)$c['complaint_details'], 0, 80)); ?><?php echo mb_strlen((string)$c['complaint_details']) > 80 ? '…' : ''; ?></div><?php endif; ?></td>
                        <td><span class="badge pri-<?php echo cmp_h($pri); ?>"><?php echo cmp_h($pri); ?></span></td>
                        <td><span class="badge <?php echo $sb; ?>"><?php echo cmp_h($st); ?></span></td>
                        <td class="l"><?php echo $c['hr_reply'] !== '' ? cmp_h($c['hr_reply']) : '<span class="muted">—</span>'; ?></td>
                        <td>
                            <form method="POST" class="upd">
                                <input type="hidden" name="complaint_id" value="<?php echo (int)$c['id']; ?>">
                                <select name="complaint_status">
                                    <?php foreach (['Pending','In Progress','Solved','Rejected'] as $os): ?><option value="<?php echo $os; ?>" <?php echo $st===$os?'selected':''; ?>><?php echo $os; ?></option><?php endforeach; ?>
                                </select>
                                <input type="text" name="hr_reply" value="<?php echo cmp_h($c['hr_reply']); ?>" placeholder="Reply" style="width:120px;">
                                <button class="btn btn-primary" type="submit" name="update_status" style="padding:5px 10px;">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="muted" style="padding:20px;">No complaints found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
