<?php
/* ─────────────────────────────────────────────
   Swap Day / Compensatory-Off tool.

   Handles the common case where a normal working day is given as a paid
   day off and another day (often a Sunday) is worked instead — without the
   working-day-off showing everyone as "absent", and without needing punches
   for the worked day.

   It does two safe things, in one click:
     1. OFF DAY  -> added to the `holidays` table. The salary engine treats a
        holiday as paid/present (never absent), so nobody is penalised.
     2. WORK DAY -> every active employee (not on vacation) gets a present
        attendance row for that date (check-in filled), so the worked day is
        counted even though nobody punched. (Sundays are already paid, so for
        a Friday<->Sunday swap only the OFF day is strictly required, but
        recording the worked day keeps the attendance report accurate.)

   After applying, regenerate that month's salary.
───────────────────────────────────────────── */
include 'auth.php';
requirePermission('attendance_upload');
include_once 'vacation_helper.php';

function sd_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sd_esc($conn, $v) { return mysqli_real_escape_string($conn, (string)$v); }

/* Normalise a time input (HH:MM or HH:MM:SS) to HH:MM:SS; fall back to default. */
function sd_time($t, $default) {
    $t = trim((string)$t);
    if ($t === '') { return $default; }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ':' . (isset($m[3]) ? $m[3] : '00');
    }
    return $default;
}

/* Make sure the manual-reason column exists (same as edit_attendance.php). */
$mr_check = mysqli_query($conn, "SHOW COLUMNS FROM attendance LIKE 'manual_entry_reason'");
if ($mr_check && mysqli_num_rows($mr_check) === 0) {
    mysqli_query($conn, "ALTER TABLE attendance ADD COLUMN manual_entry_reason VARCHAR(255) NULL DEFAULT '' AFTER overtime");
}

/* Which attendance columns exist (schema is flexible). */
$att_cols = [];
$acr = mysqli_query($conn, "SHOW COLUMNS FROM attendance");
if ($acr) { while ($c = mysqli_fetch_assoc($acr)) { $att_cols[$c['Field']] = true; } }

/* Employee status column. */
$emp_cols = [];
$ecr = mysqli_query($conn, "SHOW COLUMNS FROM employees");
if ($ecr) { while ($c = mysqli_fetch_assoc($ecr)) { $emp_cols[$c['Field']] = true; } }
$status_col = isset($emp_cols['employee_status']) ? 'employee_status'
            : (isset($emp_cols['status']) ? 'status' : null);
$name_col = isset($emp_cols['full_name']) ? 'full_name' : (isset($emp_cols['name']) ? 'name' : 'user_no');

/* Active-employee WHERE fragment (exclude resigned / left). */
function sd_active_clause($conn, $status_col, $emp_cols) {
    $today = date('Y-m-d');
    $c = '1=1';
    if ($status_col) {
        $c .= " AND (`$status_col` IS NULL OR `$status_col`='' OR LOWER(`$status_col`) NOT IN ('resign','resigned','inactive','left','terminated','absconding','end of contract'))";
    }
    if (isset($emp_cols['resign_date'])) {
        $c .= " AND (resign_date IS NULL OR resign_date='' OR resign_date='0000-00-00' OR resign_date > '$today')";
    }
    return $c;
}

$flash = '';
$flash_type = 'ok';
$result_lines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_swap') {
    $off_raw  = trim($_POST['off_date'] ?? '');
    $work_raw = trim($_POST['work_date'] ?? '');
    $reason   = trim($_POST['reason'] ?? '');
    $off_date  = $off_raw  !== '' ? normalize_input_date($off_raw)  : '';
    $work_date = $work_raw !== '' ? normalize_input_date($work_raw) : '';

    // User Nos who were ABSENT on the work day (won't be marked present;
    // each gets a 1-day unpaid leave => one-day deduction).
    $absent_raw  = trim($_POST['absent_users'] ?? '');
    $absent_list = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $absent_raw))));
    $absent_set  = array_flip($absent_list);

    // Work-day duty times (default 8 hours: 07:00 -> 15:50).
    $ci_time   = sd_time($_POST['work_checkin']  ?? '', '07:00:00');
    $co_time   = sd_time($_POST['work_checkout'] ?? '', '15:50:00');
    $ci_time_e = sd_esc($conn, $ci_time);
    $co_time_e = sd_esc($conn, $co_time);
    // Correct duty times on entries already created by a previous swap.
    $overwrite_times = isset($_POST['overwrite_times']);

    if ($off_date === '' && $work_date === '') {
        $flash = 'Please provide at least an Off day or a Work day.';
        $flash_type = 'err';
    } else {
        // ── 1) OFF DAY -> paid holiday ──────────────────────────────
        if ($off_date !== '') {
            $d = sd_esc($conn, $off_date);
            $label = sd_esc($conn, $reason !== '' ? $reason : 'Compensatory Off (day swap)');
            $exists = mysqli_query($conn, "SELECT id FROM holidays WHERE holiday_date='$d' LIMIT 1");
            if ($exists && mysqli_num_rows($exists) > 0) {
                mysqli_query($conn, "UPDATE holidays SET holiday_name='$label' WHERE holiday_date='$d'");
                $result_lines[] = "Off day " . date('d-M-Y', strtotime($off_date)) . " is already a holiday — label updated (paid, no absent).";
            } else {
                mysqli_query($conn, "INSERT INTO holidays (holiday_date, holiday_name) VALUES ('$d', '$label')");
                $result_lines[] = "Off day " . date('d-M-Y', strtotime($off_date)) . " added as a paid holiday (no absent for anyone).";
            }
        }

        // ── 2) WORK DAY -> mark active employees present ────────────
        if ($work_date !== '') {
            $wd = sd_esc($conn, $work_date);
            $active = sd_active_clause($conn, $status_col, $emp_cols);
            // Active employees NOT on vacation on the work date.
            $emp_q = mysqli_query($conn, "
                SELECT user_no, `$name_col` AS emp_name
                FROM employees e
                WHERE $active
                AND user_no IS NOT NULL AND user_no != ''
                AND NOT EXISTS (
                    SELECT 1 FROM vacations v
                    WHERE v.user_no = e.user_no
                    AND '$wd' BETWEEN v.from_date AND v.to_date
                )
                ORDER BY CAST(user_no AS UNSIGNED) ASC
            ");
            $marked = 0; $already = 0; $updated = 0;
            $has_mr = isset($att_cols['manual_entry_reason']);
            $note = sd_esc($conn, $reason !== '' ? $reason : 'Worked day swap (no punch) — marked present');
            if ($emp_q) {
                while ($e = mysqli_fetch_assoc($emp_q)) {
                    // Skip employees marked absent on the work day.
                    if (isset($absent_set[(string)$e['user_no']])) { continue; }
                    $uno = sd_esc($conn, $e['user_no']);
                    $ename = sd_esc($conn, $e['emp_name']);
                    $row = mysqli_query($conn, "SELECT id, check_in" . ($has_mr ? ", manual_entry_reason" : "") . " FROM attendance WHERE user_no='$uno' AND attendance_date='$wd' LIMIT 1");
                    if ($row && mysqli_num_rows($row) > 0) {
                        $r = mysqli_fetch_assoc($row);
                        $ci = trim((string)($r['check_in'] ?? ''));
                        $existing_reason = $has_mr ? trim((string)($r['manual_entry_reason'] ?? '')) : '';
                        if ($ci === '') {
                            // No punch yet -> mark present for the worked day.
                            $sets = ["check_in='$ci_time_e'"];
                            if (isset($att_cols['check_out']))           { $sets[] = "check_out='$co_time_e'"; }
                            if (isset($att_cols['late_time']))           { $sets[] = "late_time='00:00:00'"; }
                            if (isset($att_cols['manual_entry_reason'])) { $sets[] = "manual_entry_reason='$note'"; }
                            mysqli_query($conn, "UPDATE attendance SET " . implode(', ', $sets) . " WHERE id=" . (int)$r['id']);
                            $marked++;
                        } elseif ($overwrite_times && $existing_reason !== '') {
                            // Correct the duty hours on a previously created swap/manual entry.
                            // A non-empty manual_entry_reason means it was NOT a real machine punch.
                            $sets = ["check_in='$ci_time_e'"];
                            if (isset($att_cols['check_out']))           { $sets[] = "check_out='$co_time_e'"; }
                            if (isset($att_cols['late_time']))           { $sets[] = "late_time='00:00:00'"; }
                            // Keep the existing label unless the user typed a new reason.
                            if (isset($att_cols['manual_entry_reason']) && $reason !== '') { $sets[] = "manual_entry_reason='$note'"; }
                            mysqli_query($conn, "UPDATE attendance SET " . implode(', ', $sets) . " WHERE id=" . (int)$r['id']);
                            $updated++;
                        } else {
                            $already++;
                        }
                    } else {
                        $cols = ['user_no', 'attendance_date', 'check_in'];
                        $vals = ["'$uno'", "'$wd'", "'$ci_time_e'"];
                        if (isset($att_cols['employee_name']))       { $cols[] = 'employee_name';       $vals[] = "'$ename'"; }
                        if (isset($att_cols['check_out']))           { $cols[] = 'check_out';           $vals[] = "'$co_time_e'"; }
                        if (isset($att_cols['late_time']))           { $cols[] = 'late_time';           $vals[] = "'00:00:00'"; }
                        if (isset($att_cols['manual_entry_reason'])) { $cols[] = 'manual_entry_reason'; $vals[] = "'$note'"; }
                        mysqli_query($conn, "INSERT INTO attendance (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")");
                        $marked++;
                    }
                }
            }
            $result_lines[] = "Work day " . date('d-M-Y', strtotime($work_date)) . ": marked $marked employee(s) present"
                . ($updated > 0 ? ", updated duty times on $updated existing swap " . ($updated > 1 ? "entries" : "entry") : "")
                . ($already > 0 ? " ($already already had a real punch — left unchanged)" : "") . ".";

            // ── Absent on the work day -> 1-day unpaid leave (one-day deduction) ──
            if (!empty($absent_list)) {
                if (function_exists('vacation_ensure_schema')) { vacation_ensure_schema($conn); }
                $vreason = sd_esc($conn, $reason !== '' ? $reason : 'Absent on compensatory work day');
                $absent_done = 0; $absent_skipped = 0;
                foreach ($absent_list as $au) {
                    $auno = sd_esc($conn, $au);
                    $eq = mysqli_query($conn, "SELECT `$name_col` AS emp_name FROM employees WHERE user_no='$auno' LIMIT 1");
                    if (!$eq || mysqli_num_rows($eq) === 0) { $absent_skipped++; continue; }
                    $en = sd_esc($conn, mysqli_fetch_assoc($eq)['emp_name']);
                    // Don't double-add if a leave already covers the work date.
                    $vex = mysqli_query($conn, "SELECT id FROM vacations WHERE user_no='$auno' AND '$wd' BETWEEN from_date AND to_date LIMIT 1");
                    if ($vex && mysqli_num_rows($vex) > 0) { $absent_skipped++; continue; }
                    mysqli_query($conn, "
                        INSERT INTO vacations
                            (user_no, employee_name, from_date, to_date, return_date, leave_type, paid_status, vacation_status, reason)
                        VALUES
                            ('$auno', '$en', '$wd', '$wd', '$wd', 'Unpaid', 'Unpaid', 'Closed', '$vreason')
                    ");
                    $absent_done++;
                }
                $result_lines[] = "Absent on work day: applied a 1-day unpaid leave (one-day deduction) to $absent_done employee(s)"
                    . ($absent_skipped > 0 ? " ($absent_skipped skipped — unknown user or already on leave)" : "") . ".";
            }
        }

        $flash = 'Swap applied. Now regenerate the affected month\'s salary.';
        $flash_type = 'ok';
    }
}

/* Month to suggest for regeneration. */
$regen_month = '';
if (!empty($off_date)) { $regen_month = date('Y-m', strtotime($off_date)); }
elseif (!empty($work_date)) { $regen_month = date('Y-m', strtotime($work_date)); }

/* Recent comp-off holidays for reference. */
$recent_holidays = mysqli_query($conn, "SELECT holiday_date, holiday_name FROM holidays ORDER BY holiday_date DESC LIMIT 8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Swap Day / Comp-Off</title>
<style>
:root{--brand:#1a2533;--accent:#e8a020;--blue:#2563a8;--gray-50:#f7f9fc;--gray-100:#eef2f7;--gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-600:#475569;--green:#1e8e5a;--red:#c0392b;--radius:10px;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:var(--gray-50);color:#1f2933;}
.topbar{display:flex;align-items:center;justify-content:space-between;background:var(--brand);color:#fff;padding:10px 18px;position:sticky;top:0;z-index:1100;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar .logo{font-weight:700;font-size:15px;}
.topbar .logo span{color:var(--accent);}
.tbtn{background:rgba(255,255,255,.12);color:#fff;text-decoration:none;padding:7px 12px;border-radius:6px;font-size:13px;}
.tbtn:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;max-width:920px;margin:0 auto;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:4px;}
.subtitle{color:var(--gray-600);font-size:13px;margin-bottom:18px;line-height:1.6;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);margin-bottom:18px;overflow:hidden;}
.card-header{background:var(--gray-100);padding:12px 16px;font-weight:600;color:var(--brand);font-size:14px;border-bottom:1px solid var(--gray-200);}
.card-body{padding:16px;}
.row{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;}
.fgroup{display:flex;flex-direction:column;gap:5px;}
.fgroup label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fgroup .hint{font-size:11px;color:var(--gray-400);}
.fgroup input{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:190px;}
.btn{padding:10px 18px;border-radius:7px;border:none;cursor:pointer;font-size:14px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-success{background:var(--green);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn:hover{opacity:.93;}
.flash{padding:12px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600;}
.flash.ok{background:#e3f6ec;color:var(--green);border:1px solid #b6e3c9;}
.flash.err{background:#fdecea;color:var(--red);border:1px solid #f5c6c0;}
.result{background:var(--gray-50);border:1px dashed var(--gray-200);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px;}
.result li{margin:4px 0;}
.note{font-size:12px;color:var(--gray-600);line-height:1.7;background:#fff7e6;border:1px solid #f3e0b5;border-radius:8px;padding:10px 14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:8px 10px;border-bottom:1px solid var(--gray-100);text-align:left;}
th{background:var(--gray-100);color:var(--brand);}
.muted{color:var(--gray-400);}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="tbtn">&#8592; Dashboard</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;') : ''; ?>
        <span class="logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
</div>

<div class="page">
    <div class="page-title"><span>&#128260;</span> Swap Day / Compensatory Off</div>
    <div class="subtitle">
        Use this when a normal working day is given as a <strong>paid day off</strong> and another day (e.g. a Sunday) is <strong>worked instead</strong>.
        The off day becomes a paid holiday (no absent for anyone) and the worked day is marked present for all active staff — even without punches.
    </div>

    <?php if ($flash !== ''): ?>
        <div class="flash <?php echo $flash_type; ?>"><?php echo sd_h($flash); ?></div>
    <?php endif; ?>
    <?php if (!empty($result_lines)): ?>
        <div class="result"><strong>Done:</strong><ul><?php foreach ($result_lines as $l): ?><li><?php echo sd_h($l); ?></li><?php endforeach; ?></ul>
        <?php if ($regen_month !== ''): ?>
            <a class="btn btn-primary" style="margin-top:6px;" href="generate_salary.php?month=<?php echo sd_h($regen_month); ?>&search=1">&#129534; Regenerate <?php echo sd_h(date('F Y', strtotime($regen_month.'-01'))); ?> Salary</a>
        <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">Apply a Day Swap</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="apply_swap">
                <div class="row">
                    <div class="fgroup">
                        <label>Off Day (paid, no work)</label>
                        <input type="date" name="off_date" value="">
                        <span class="hint">A working day given as paid off &rarr; becomes a holiday.</span>
                    </div>
                    <div class="fgroup">
                        <label>Work Day (worked instead)</label>
                        <input type="date" name="work_date" value="">
                        <span class="hint">Everyone worked this day &rarr; marked present (no punch needed).</span>
                    </div>
                </div>
                <div class="row" style="margin-top:14px;">
                    <div class="fgroup">
                        <label>Work Day Check-In</label>
                        <input type="time" name="work_checkin" value="07:00" style="min-width:140px;">
                        <span class="hint">Duty start time on the worked day.</span>
                    </div>
                    <div class="fgroup">
                        <label>Work Day Check-Out</label>
                        <input type="time" name="work_checkout" value="15:50" style="min-width:140px;">
                        <span class="hint">Default 07:00&ndash;15:50 = 8 hours duty.</span>
                    </div>
                    <div class="fgroup" style="flex:1;min-width:240px;">
                        <label style="display:flex;align-items:center;gap:7px;">
                            <input type="checkbox" name="overwrite_times" value="1" style="min-width:auto;width:16px;height:16px;">
                            Update times on existing swap entries
                        </label>
                        <span class="hint">Tick to correct the duty hours on a swap you already applied for this date. Real machine punches are never changed.</span>
                    </div>
                </div>
                <div class="row" style="margin-top:14px;">
                    <div class="fgroup" style="flex:1;">
                        <label>Reason / Label</label>
                        <input type="text" name="reason" style="min-width:320px;width:100%;" placeholder="e.g. Compensatory Off (19-Jun Fri) — swapped with 21-Jun (Sun) duty">
                    </div>
                </div>
                <div class="row" style="margin-top:14px;">
                    <div class="fgroup" style="flex:1;">
                        <label>Absent on Work Day (User Nos) — optional</label>
                        <input type="text" name="absent_users" style="min-width:320px;width:100%;" placeholder="e.g. 1426, 1295, 1564">
                        <span class="hint">These employees are NOT marked present and get a 1-day unpaid leave (one-day deduction). Separate by comma or space.</span>
                    </div>
                </div>
                <div style="margin-top:16px;display:flex;gap:10px;">
                    <button type="submit" class="btn btn-success">&#10003; Apply Swap</button>
                    <a href="holidays.php" class="btn btn-gray">View Holidays</a>
                </div>
            </form>
        </div>
    </div>

    <div class="note">
        <strong>How it works &amp; what to do next:</strong>
        <ul style="margin:6px 0 0;padding-left:18px;">
            <li><strong>Off day</strong> is added to Holidays &rarr; the salary engine pays it and counts <em>no absent</em> for it.</li>
            <li><strong>Work day</strong> &rarr; each active employee (not on leave) gets a present attendance row using your <em>Check-In / Check-Out</em> times (default 07:00&ndash;15:50 = 8 hours). Existing real punches are left untouched.</li>
            <li><strong>Already applied this swap?</strong> To fix the duty hours, enter the same Work Day, set the correct Check-In/Check-Out and tick <em>“Update times on existing swap entries”</em> &rarr; only the swap-created rows are corrected; real machine punches are never changed.</li>
            <li><strong>Absent on work day</strong> &rarr; listed User Nos are skipped (not marked present) and given a 1-day unpaid leave, so they lose exactly one day's pay for missing the compensatory duty.</li>
            <li>Sundays are already paid as present, so for a Friday&harr;Sunday swap the <em>Off day</em> alone is enough; recording the Work day just keeps the attendance report accurate.</li>
            <li><strong>Important:</strong> after applying, click <em>Regenerate Salary</em> for that month so the new figures are saved.</li>
        </ul>
    </div>

    <div class="card">
        <div class="card-header">&#127881; Recent Holidays / Comp-Off</div>
        <div class="card-body" style="padding:0;">
            <table>
                <thead><tr><th>Date</th><th>Name</th></tr></thead>
                <tbody>
                <?php if ($recent_holidays && mysqli_num_rows($recent_holidays) > 0): ?>
                    <?php while ($hr = mysqli_fetch_assoc($recent_holidays)): ?>
                        <tr>
                            <td><?php echo $hr['holiday_date'] ? sd_h(date('d-M-Y', strtotime($hr['holiday_date']))) : '<span class="muted">—</span>'; ?></td>
                            <td><?php echo sd_h($hr['holiday_name']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="2" class="muted" style="padding:16px;">No holidays recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
