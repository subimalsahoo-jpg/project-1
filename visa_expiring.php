<?php
include 'auth.php';
requireAnyPermission(['employee_view','visa_insurance_view']);
include_once 'visa_helper.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function display_date_dmy($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return '';
    $time = strtotime($value);
    return $time ? date('d-m-Y', $time) : $value;
}

$today = date('Y-m-d');
$three_months = visa_alert_window_date();

$result = visa_alert_query($conn);
$total_count = $result ? mysqli_num_rows($result) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expired &amp; Expiring Visas</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --brand:      #1a3a5c;
    --brand-mid:  #2563a8;
    --accent:     #e8a020;
    --red-dark:   #b91c1c;
    --red-mid:    #dc2626;
    --red-soft:   #fee2e2;
    --orange:     #ea580c;
    --orange-soft:#fff0e6;
    --yellow:     #d97706;
    --yellow-soft:#fefce8;
    --green:      #16a34a;
    --green-soft: #dcfce7;
    --gray-50:    #f8fafc;
    --gray-100:   #f1f5f9;
    --gray-200:   #e2e8f0;
    --gray-600:   #475569;
    --gray-800:   #1e293b;
    --radius:     8px;
    --shadow:     0 2px 12px rgba(0,0,0,0.10);
}

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--gray-100);
    color: var(--gray-800);
    font-size: 14px;
    min-height: 100vh;
}

/* ── Top Bar ── */
.topbar {
    position: sticky;
    top: 0;
    z-index: 50;
    background: var(--brand);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 54px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.22);
}
.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-logo { font-size: 15px; font-weight: 700; color: #fff; letter-spacing: 0.03em; }
.topbar-logo span { color: var(--accent); }
.btn-back {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    padding: 6px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: background 0.15s;
}
.btn-back:hover { background: rgba(255,255,255,0.22); }

.topbar-right {
    display: flex; align-items: center; gap: 10px;
}
.count-pill {
    background: var(--accent);
    color: #1a1a1a;
    font-weight: 700;
    font-size: 13px;
    border-radius: 20px;
    padding: 4px 14px;
    white-space: nowrap;
}

/* ── Page Body ── */
.page-body {
    padding: 24px 24px 40px;
}

/* ── Page Heading ── */
.page-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}
.page-heading h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--brand);
}
.page-heading .icon {
    width: 40px; height: 40px;
    background: var(--red-soft);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
}

/* ── Legend ── */
.legend {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.legend-item {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 600; color: var(--gray-600);
}
.legend-dot {
    width: 12px; height: 12px;
    border-radius: 3px;
}

/* ── Table Container ── */
.table-container {
    background: #fff;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    overflow-x: auto;
}

/* ── Table ── */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 700px;
}
thead th {
    background: var(--brand);
    color: #fff;
    font-weight: 600;
    padding: 11px 12px;
    text-align: center;
    white-space: nowrap;
    border-right: 1px solid rgba(255,255,255,0.12);
    letter-spacing: 0.02em;
    font-size: 12px;
    text-transform: uppercase;
}
thead th:last-child { border-right: none; }
thead th.th-name  { text-align: left; padding-left: 16px; }
thead th.th-dept  { text-align: left; }

tbody tr { border-bottom: 1px solid var(--gray-200); }
tbody tr:last-child { border-bottom: none; }
tbody tr:nth-child(even) { background: var(--gray-50); }
tbody tr:hover td { background: #eef3fb !important; }

tbody td {
    padding: 10px 12px;
    text-align: center;
    vertical-align: middle;
    color: var(--gray-800);
}
tbody td.td-name {
    text-align: left;
    padding-left: 16px;
    font-weight: 600;
    font-size: 13px;
}
tbody td.td-dept {
    text-align: left;
    color: var(--gray-600);
    font-size: 12px;
}
tbody td.td-sl {
    color: var(--gray-600);
    font-size: 12px;
    width: 44px;
}
tbody td.td-userno {
    font-weight: 700;
    color: var(--brand-mid);
}
tbody td.td-userno a {
    color: var(--brand-mid);
    text-decoration: none;
}
tbody td.td-userno a:hover {
    text-decoration: underline;
}

/* ── Urgency colouring ── */
/* already expired: still employed, working on an expired visa */
tr.urg-expired td { background: #fde0e0; }
tr.urg-expired:nth-child(even) td { background: #fbd5d5; }
tr.urg-expired .days-badge { background: var(--red-dark); color: #fff; }
tr.urg-expired .date-cell  { color: var(--red-dark); font-weight: 800; }

/* ≤ 7 days: critical red */
tr.urg-critical td { background: #fff5f5; }
tr.urg-critical:nth-child(even) td { background: #ffeded; }
tr.urg-critical .days-badge { background: var(--red-soft); color: var(--red-dark); }
tr.urg-critical .date-cell  { color: var(--red-dark); font-weight: 700; }

/* 8–14 days: urgent orange */
tr.urg-urgent td { background: #fff8f3; }
tr.urg-urgent:nth-child(even) td { background: #fff1e6; }
tr.urg-urgent .days-badge { background: var(--orange-soft); color: var(--orange); }
tr.urg-urgent .date-cell  { color: var(--orange); font-weight: 700; }

/* 15–30 days: warning yellow */
tr.urg-warning td { background: #fffef0; }
tr.urg-warning:nth-child(even) td { background: #fffce0; }
tr.urg-warning .days-badge { background: var(--yellow-soft); color: var(--yellow); }
tr.urg-warning .date-cell  { color: var(--yellow); font-weight: 600; }

/* 31–90 days: normal */
tr.urg-normal .days-badge { background: var(--green-soft); color: var(--green); }
tr.urg-normal .date-cell  { color: var(--gray-600); }

.days-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 12px;
    white-space: nowrap;
}

/* ── Empty State ── */
.empty-state {
    padding: 60px 20px;
    text-align: center;
}
.empty-state .icon { font-size: 44px; margin-bottom: 12px; }
.empty-state h3 { color: var(--green); font-size: 18px; margin-bottom: 6px; }
.empty-state p  { color: var(--gray-600); font-size: 14px; }

@media print {
    .topbar { position: static; }
    body { background: #fff; }
    .table-container { box-shadow: none; }
    @page { size: A4 landscape; margin: 10mm; }
}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<!-- Top Bar -->
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="btn-back">&#8592; Dashboard</a>
        <?php echo company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;'); ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
    <div class="topbar-right">
        <?php if ($total_count > 0): ?>
        <span class="count-pill">&#9888; <?php echo $total_count; ?> Visa<?php echo $total_count > 1 ? 's' : ''; ?> to Action</span>
        <?php endif; ?>
        <button onclick="window.print()" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;">&#128438; Print</button>
    </div>
</div>

<div class="page-body">

    <!-- Heading -->
    <div class="page-heading">
        <div class="icon">&#128196;</div>
        <div>
        </div>
    </div>

    <!-- Colour legend -->
    <?php if ($total_count > 0): ?>
    <div class="legend">
        <span class="legend-item"><span class="legend-dot" style="background:#b91c1c;"></span> Expired (still employed)</span>
        <span class="legend-item"><span class="legend-dot" style="background:#fca5a5;"></span> Critical (≤ 7 days)</span>
        <span class="legend-item"><span class="legend-dot" style="background:#fdba74;"></span> Urgent (8–14 days)</span>
        <span class="legend-item"><span class="legend-dot" style="background:#fde68a;"></span> Warning (15–30 days)</span>
        <span class="legend-item"><span class="legend-dot" style="background:#bbf7d0;"></span> Normal (31–90 days)</span>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="table-container">
    <table>
    <thead>
        <tr>
            <th>SL</th>
            <th>User No.</th>
            <th class="th-name">Name</th>
            <th class="th-dept">Department</th>
            <th>Phone</th>
            <th>Emirates ID</th>
            <th>Expiry Date</th>
            <th>Remaining</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $sl = 1;
    if ($total_count > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $remaining = floor((strtotime($row['visa_expiry_date']) - strtotime($today)) / 86400);
            if ($remaining < 0)        $urg = 'urg-expired';
            elseif ($remaining <= 7)   $urg = 'urg-critical';
            elseif ($remaining <= 14)  $urg = 'urg-urgent';
            elseif ($remaining <= 30)  $urg = 'urg-warning';
            else                       $urg = 'urg-normal';
    ?>
    <tr class="<?php echo $urg; ?>">
        <td class="td-sl"><?php echo $sl++; ?></td>
        <td class="td-userno">
            <a href="employee_overview.php?search=<?php echo urlencode((string)$row['user_no']); ?>&tab=details">
                <?php echo h($row['user_no']); ?>
            </a>
        </td>
        <td class="td-name"><?php echo h($row['full_name']); ?></td>
        <td class="td-dept"><?php echo h($row['department']); ?></td>
        <td><?php echo h($row['phone']); ?></td>
        <td><?php echo h($row['emirates_id_number'] ?? ''); ?></td>
        <td class="date-cell"><?php echo h(display_date_dmy($row['visa_expiry_date'])); ?></td>
        <td>
            <?php if ($remaining < 0): ?>
                <span class="days-badge">Expired <?php echo abs($remaining); ?>d ago</span>
            <?php else: ?>
                <span class="days-badge"><?php echo $remaining; ?> Days</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
        }
    } else { ?>
    <tr>
        <td colspan="8">
            <div class="empty-state">
                <div class="icon">&#9989;</div>
                <h3>All Clear!</h3>
                <p>No employee visas expiring within the next 3 months.</p>
            </div>
        </td>
    </tr>
    <?php } ?>
    </tbody>
    </table>
    </div>

</div><!-- /page-body -->
</body>
</html>
