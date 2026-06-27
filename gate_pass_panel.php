<?php
/* ─────────────────────────────────────────────
   Embedded Gate Pass panel for the Employee Overview "Gate Pass" tab.
   Renders: stat cards (this employee) + a collapsible "Generate New Gate
   Pass" form (posts to gate_pass.php) + this employee's gate-pass history.
   Expects: $conn (mysqli) and $employee (array with user_no/full_name).
───────────────────────────────────────────── */
if (!isset($conn) || empty($employee)) { return; }

if (!function_exists('gpp_e')) {
    function gpp_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('gpp_dmy2')) {
    function gpp_dmy2($v) {
        $v = trim((string)$v);
        if ($v === '' || $v === '0000-00-00') return '';
        $t = strtotime($v);
        return $t ? date('d-M-Y', $t) : $v;
    }
}
if (!function_exists('gpp_time_selects')) {
    function gpp_time_selects($prefix, $defHour, $defMin, $defAp) {
        $h  = '<span class="gpp-time">';
        $h .= '<select name="' . $prefix . '_hour">';
        for ($i = 1; $i <= 12; $i++) {
            $sel = ((int)$i === (int)$defHour) ? 'selected' : '';
            $h  .= '<option value="' . $i . '" ' . $sel . '>' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '</option>';
        }
        $h .= '</select> : <select name="' . $prefix . '_min">';
        for ($m = 0; $m < 60; $m += 5) {
            $mm  = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            $sel = ($mm === $defMin) ? 'selected' : '';
            $h  .= '<option value="' . $mm . '" ' . $sel . '>' . $mm . '</option>';
        }
        $h .= '</select> <select name="' . $prefix . '_ampm">';
        foreach (['AM', 'PM'] as $ap) {
            $sel = ($ap === $defAp) ? 'selected' : '';
            $h  .= '<option value="' . $ap . '" ' . $sel . '>' . $ap . '</option>';
        }
        $h .= '</select></span>';
        return $h;
    }
}

/* Ensure the gate-pass tables exist (mirror gate_pass.php). */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gate_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pass_no VARCHAR(40) DEFAULT '',
    pass_date DATE NULL,
    leave_date DATE NULL,
    return_date DATE NULL,
    depart_time VARCHAR(20) DEFAULT '',
    return_time VARCHAR(20) DEFAULT '',
    subject VARCHAR(200) DEFAULT 'Request for Permission',
    reason VARCHAR(255) DEFAULT '',
    employees_json TEXT,
    employee_summary VARCHAR(500) DEFAULT '',
    employee_count INT DEFAULT 0,
    created_by VARCHAR(150) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach ([['subject', "VARCHAR(200) DEFAULT 'Request for Permission'"], ['return_date', "DATE NULL"]] as $mig) {
    $c = mysqli_query($conn, "SHOW COLUMNS FROM gate_passes LIKE '" . $mig[0] . "'");
    if ($c && mysqli_num_rows($c) === 0) { mysqli_query($conn, "ALTER TABLE gate_passes ADD `" . $mig[0] . "` " . $mig[1]); }
}
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS gate_pass_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason_text VARCHAR(120) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$seed_chk = mysqli_query($conn, "SELECT COUNT(*) AS c FROM gate_pass_reasons");
if ($seed_chk && (int)(mysqli_fetch_assoc($seed_chk)['c'] ?? 0) === 0) {
    $i = 0;
    foreach (['Leave','Visa Renewal','Personal Work','Medical Purpose','Passport Renewal','Holiday','Vacation','Country Exit'] as $d) {
        $sd = mysqli_real_escape_string($conn, $d);
        mysqli_query($conn, "INSERT INTO gate_pass_reasons (reason_text, sort_order) VALUES ('$sd', $i)");
        $i++;
    }
}
$gpp_reasons = [];
$rq = mysqli_query($conn, "SELECT reason_text FROM gate_pass_reasons ORDER BY sort_order, reason_text");
if ($rq) { while ($r = mysqli_fetch_assoc($rq)) { $gpp_reasons[] = $r['reason_text']; } }

/* This employee's gate-pass history. */
$gpp_uno = trim((string)$employee['user_no']);
$uno_esc = mysqli_real_escape_string($conn, $gpp_uno);
$gpp_rows = [];
$lq = mysqli_query($conn, "SELECT * FROM gate_passes
    WHERE employees_json LIKE '%\"user_no\":\"" . $uno_esc . "\"%'
       OR employee_summary LIKE '" . $uno_esc . " - %'
       OR employee_summary LIKE '%, " . $uno_esc . " - %'
    ORDER BY id DESC LIMIT 200");
if ($lq) { while ($r = mysqli_fetch_assoc($lq)) { $gpp_rows[] = $r; } }

$gpp_total = count($gpp_rows);
$gpp_month = 0; $gpp_today = 0;
$cur_m = date('Y-m'); $tod = date('Y-m-d');
foreach ($gpp_rows as $r) {
    if (substr((string)$r['pass_date'], 0, 7) === $cur_m) $gpp_month++;
    if ((string)$r['pass_date'] === $tod) $gpp_today++;
}
$gpp_print_id = (int)($_GET['gp_print'] ?? 0);
?>
<style>
.gpp-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;}
.gpp-card{background:#fff;border:1px solid var(--border,#d0d8e8);border-radius:10px;box-shadow:var(--shadow,0 2px 12px rgba(15,31,61,.10));padding:14px 18px;}
.gpp-card .l{color:var(--text-dim,#5a6480);font-size:12.5px;font-weight:600;}
.gpp-card .v{font-size:24px;font-weight:800;color:#0f766e;margin-top:4px;}
.gpp-gen-head{display:flex;align-items:center;justify-content:space-between;cursor:pointer;background:#0f766e;color:#fff;padding:12px 16px;border-radius:10px;font-weight:700;user-select:none;}
.gpp-gen-head .caret{transition:transform .2s;}
.gpp-gen-head.open .caret{transform:rotate(180deg);}
.gpp-gen-body{display:none;border:1px solid var(--border,#d0d8e8);border-top:none;border-radius:0 0 10px 10px;padding:18px;background:#fff;margin-bottom:18px;}
.gpp-gen-body.open{display:block;}
.gpp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.gpp-fg{display:flex;flex-direction:column;gap:5px;}
.gpp-fg.full{grid-column:1/-1;}
.gpp-fg label{font-size:12.5px;font-weight:700;color:#1a3a5c;}
.gpp-fg input,.gpp-fg select,.gpp-time select{padding:9px 10px;border:1.6px solid #f1c27a;border-radius:7px;font-size:14px;font-family:inherit;background:#fffaf2;}
.gpp-fg input:focus,.gpp-fg select:focus,.gpp-time select:focus{outline:none;border-color:#e8a020;background:#fff;box-shadow:0 0 0 3px rgba(232,160,32,.2);}
.gpp-time{display:inline-flex;align-items:center;gap:5px;}
.gpp-emp{margin-top:12px;border:1px solid var(--border,#d0d8e8);border-radius:8px;overflow:hidden;}
.gpp-emp .h{background:#1a3a5c;color:#fff;padding:8px 12px;font-size:12px;font-weight:700;display:flex;gap:20px;}
.gpp-emp .b{padding:10px 12px;display:flex;gap:20px;font-size:14px;}
.gpp-print-btn{background:#0f766e;color:#fff;border:none;border-radius:7px;padding:6px 12px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;}
.gpp-gen-submit{margin-top:14px;background:#e8a020;color:#1a1a1a;border:none;border-radius:7px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer;}
@media(max-width:900px){.gpp-cards{grid-template-columns:1fr;}.gpp-grid{grid-template-columns:1fr 1fr;}}
</style>

<div class="section-title">Gate Pass — <?= gpp_e($employee['full_name']) ?> <span style="font-weight:500;color:var(--text-dim);font-size:13px;">(User No: <?= gpp_e($employee['user_no']) ?>)</span></div>

<div class="gpp-cards">
    <div class="gpp-card"><div class="l">This Employee — Total</div><div class="v"><?= $gpp_total ?></div></div>
    <div class="gpp-card"><div class="l">This Month</div><div class="v"><?= $gpp_month ?></div></div>
    <div class="gpp-card"><div class="l">Issued Today</div><div class="v"><?= $gpp_today ?></div></div>
</div>

<?php if (hasPermission('gate_pass_manage')): ?>
<div class="gpp-gen-head" id="gppGenHead" onclick="gppToggleGen()">
    <span>➕ Generate New Gate Pass</span>
    <span class="caret">▾</span>
</div>
<div class="gpp-gen-body" id="gppGenBody">
    <form method="POST" action="gate_pass.php" id="gppForm">
        <input type="hidden" name="save_pass" value="1">
        <input type="hidden" name="origin" value="overview">
        <input type="hidden" name="origin_search" value="<?= gpp_e($employee['user_no']) ?>">
        <div class="gpp-grid">
            <div class="gpp-fg">
                <label>Date of Leaving Premises *</label>
                <input type="date" name="leave_date" value="<?= gpp_e(date('Y-m-d')) ?>" required>
            </div>
            <div class="gpp-fg">
                <label>Departure Time</label>
                <?= gpp_time_selects('depart', 9, '00', 'AM') ?>
            </div>
            <div class="gpp-fg">
                <label>Return Date</label>
                <input type="date" name="return_date" value="<?= gpp_e(date('Y-m-d')) ?>">
            </div>
            <div class="gpp-fg">
                <label>Return Time</label>
                <?= gpp_time_selects('return', 6, '00', 'PM') ?>
            </div>
            <div class="gpp-fg">
                <label>Reason *</label>
                <select name="reason" required>
                    <option value="">— Select reason —</option>
                    <?php foreach ($gpp_reasons as $rt): ?>
                    <option value="<?= gpp_e($rt) ?>"><?= gpp_e($rt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gpp-fg full" style="grid-column:span 3;">
                <label>Subject</label>
                <input type="text" name="subject" value="Request for Permission">
            </div>
        </div>
        <div class="gpp-emp">
            <div class="h"><span style="width:160px;">User No</span><span>Employee Name</span></div>
            <div class="b">
                <span style="width:160px;"><b><?= gpp_e($employee['user_no']) ?></b></span>
                <span><?= gpp_e($employee['full_name']) ?></span>
                <input type="hidden" name="user_no[]" value="<?= gpp_e($employee['user_no']) ?>">
                <input type="hidden" name="emp_name[]" value="<?= gpp_e($employee['full_name']) ?>">
            </div>
        </div>
        <div style="font-size:12px;color:var(--text-dim);margin-top:6px;">Saif Zone ID, Emirates ID and the photo are pulled automatically from the employee record.</div>
        <button type="submit" class="gpp-gen-submit">🚪 Generate &amp; Save Gate Pass</button>
    </form>
</div>
<?php endif; ?>

<div class="section-title">Gate Pass History</div>
<div class="table-scroll">
<table class="data-table">
    <thead>
        <tr><th>Pass No</th><th>Issued</th><th>Leave Date</th><th>Time</th><th>Reason</th><th>By</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php if (empty($gpp_rows)): ?>
        <tr><td colspan="7" style="color:var(--text-dim);padding:20px;">No gate passes for this employee yet.</td></tr>
    <?php else: foreach ($gpp_rows as $r): ?>
        <tr>
            <td><b><?= gpp_e($r['pass_no'] ?: ('#' . $r['id'])) ?></b></td>
            <td><?= gpp_e(gpp_dmy2($r['pass_date'])) ?></td>
            <td><?= gpp_e(gpp_dmy2($r['leave_date'])) ?><?php if (!empty($r['return_date']) && $r['return_date'] !== '0000-00-00'): ?> → <?= gpp_e(gpp_dmy2($r['return_date'])) ?><?php endif; ?></td>
            <td><?= gpp_e($r['depart_time']) ?> – <?= gpp_e($r['return_time']) ?></td>
            <td><?= gpp_e($r['reason']) ?></td>
            <td><?= trim((string)$r['created_by']) !== '' ? gpp_e($r['created_by']) : '—' ?></td>
            <td><a class="gpp-print-btn" href="gate_pass_print.php?id=<?= (int)$r['id'] ?>&auto=1" target="_blank" rel="noopener">🖨️ Print</a></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<script>
function gppToggleGen() {
    document.getElementById('gppGenHead').classList.toggle('open');
    document.getElementById('gppGenBody').classList.toggle('open');
}
<?php if ($gpp_print_id > 0): ?>
window.open('gate_pass_print.php?id=<?= $gpp_print_id ?>&auto=1', '_blank');
<?php endif; ?>
</script>
