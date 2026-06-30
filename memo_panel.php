<?php
/* ─────────────────────────────────────────────
   Embedded Memo panel for the Employee Overview "Memo" tab.
   Renders: a "Issue New Memo" form (posts to employee_memo.php) + this
   employee's memo history. Expects $conn (mysqli) and $employee (array with
   user_no / full_name / employee_id / designation).
───────────────────────────────────────────── */
if (!isset($conn) || empty($employee)) { return; }
require_once 'memo_helper.php';
memo_ensure_schema($conn);

if (!function_exists('mpn_h')) {
    function mpn_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('mpn_dmy')) {
    function mpn_dmy($v) {
        $v = trim((string)$v);
        if ($v === '' || $v === '0000-00-00') return '';
        $t = strtotime($v);
        return $t ? date('d-M-Y', $t) : $v;
    }
}

$mpn_uno   = trim((string)$employee['user_no']);
$mpn_name  = trim((string)($employee['full_name'] ?? ''));
$mpn_desig = trim((string)($employee['designation'] ?? ''));
$mpn_empid = trim((string)($employee['employee_id'] ?? ''));
$mpn_admin = function_exists('is_admin_user') ? is_admin_user() : false;

/* This employee's memo history. */
$uno_esc = mysqli_real_escape_string($conn, $mpn_uno);
$mpn_rows = [];
$lq = mysqli_query($conn, "SELECT * FROM employee_memos WHERE user_no='$uno_esc' ORDER BY id DESC LIMIT 200");
if ($lq) { while ($r = mysqli_fetch_assoc($lq)) { $mpn_rows[] = $r; } }

/* Memo types (Admin-managed) + JS template data (subject + body per type). */
$mpn_type_rows = memo_type_rows($conn);
$mpn_js_templates = [];
foreach ($mpn_type_rows as $mt) {
    $mpn_js_templates[$mt['type_name']] = ['subject' => (string)$mt['default_subject'], 'body' => (string)$mt['default_body']];
}
$mpn_first_subject = $mpn_type_rows[0]['default_subject'] ?? '';
$mpn_first_body    = $mpn_type_rows[0]['default_body'] ?? '';
$mpn_print_id = (int)($_GET['memo_print'] ?? 0);
?>

<style>
.mpn-head{display:flex;align-items:center;justify-content:space-between;background:#7c3aed;color:#fff;padding:12px 16px;border-radius:10px;font-weight:700;cursor:pointer;user-select:none;}
.mpn-head .caret{transition:transform .2s;}
.mpn-head.open .caret{transform:rotate(180deg);}
.mpn-body{display:none;border:1px solid var(--border,#d0d8e8);border-top:none;border-radius:0 0 10px 10px;padding:18px;background:#fff;margin-bottom:18px;}
.mpn-body.open{display:block;}
.mpn-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.mpn-fg{display:flex;flex-direction:column;gap:5px;}
.mpn-fg.full{grid-column:1/-1;}
.mpn-fg label{font-size:12.5px;font-weight:700;color:#1a3a5c;}
.mpn-fg input,.mpn-fg select,.mpn-fg textarea{padding:9px 11px;border:1.6px solid #ddd6fe;border-radius:7px;font-size:14px;font-family:inherit;background:#faf8ff;}
.mpn-fg textarea{min-height:180px;resize:vertical;line-height:1.6;}
.mpn-fg input:focus,.mpn-fg select:focus,.mpn-fg textarea:focus{outline:none;border-color:#7c3aed;background:#fff;box-shadow:0 0 0 3px rgba(124,58,237,.18);}
.mpn-submit{margin-top:14px;background:#7c3aed;color:#fff;border:none;border-radius:7px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer;}
.mpn-table{width:100%;border-collapse:collapse;margin-top:6px;}
.mpn-table th{background:#1a3a5c;color:#fff;text-align:left;padding:9px 11px;font-size:12px;}
.mpn-table td{border-bottom:1px solid var(--border,#e2e8f0);padding:8px 11px;font-size:13px;vertical-align:top;}
.mpn-pill{display:inline-block;background:#ede9fe;color:#5b21b6;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700;}
.mpn-print{background:#0f766e;color:#fff;border:none;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;}
.mpn-del{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;cursor:pointer;}
@media(max-width:900px){.mpn-grid{grid-template-columns:1fr 1fr;}}
</style>

<div class="section-title">Employee Memo — <?= mpn_h($mpn_name) ?> <span style="font-weight:500;color:var(--text-dim);font-size:13px;">(User No: <?= mpn_h($mpn_uno) ?>)</span></div>

<div class="mpn-head" id="mpnHead" onclick="mpnToggle()">
    <span>&#10133; Issue New Memo</span>
    <span class="caret">&#9662;</span>
</div>
<div class="mpn-body" id="mpnBody">
    <form method="POST" action="employee_memo.php">
        <input type="hidden" name="save_memo" value="1">
        <input type="hidden" name="origin" value="overview">
        <input type="hidden" name="origin_search" value="<?= mpn_h($mpn_uno) ?>">
        <input type="hidden" name="user_no" value="<?= mpn_h($mpn_uno) ?>">
        <input type="hidden" name="employee_name" value="<?= mpn_h($mpn_name) ?>">
        <input type="hidden" name="employee_id" value="<?= mpn_h($mpn_empid) ?>">
        <div class="mpn-grid">
            <div class="mpn-fg">
                <label>Designation</label>
                <input type="text" name="designation" value="<?= mpn_h($mpn_desig) ?>">
            </div>
            <div class="mpn-fg">
                <label>Memo Date</label>
                <input type="date" name="memo_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mpn-fg">
                <label>Memo Type</label>
                <select id="mpnType" name="memo_type" onchange="mpnApplyTemplate()">
                    <?php foreach ($mpn_type_rows as $mt): $t = $mt['type_name']; ?>
                    <option value="<?= mpn_h($t) ?>"><?= mpn_h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mpn-fg full">
                <label>Subject</label>
                <input type="text" id="mpnSubject" name="subject" value="<?= mpn_h($mpn_first_subject) ?>">
            </div>
            <div class="mpn-fg full">
                <label>Memo Body (editable)</label>
                <textarea id="mpnBodyText" name="body"><?= mpn_h($mpn_first_body) ?></textarea>
            </div>
            <div class="mpn-fg">
                <label>Issued By</label>
                <input type="text" name="issued_by" value="ADMIN In-charge">
            </div>
        </div>
        <button type="submit" class="mpn-submit">&#128190; Save &amp; Print Memo</button>
    </form>
</div>


<table class="mpn-table">
    <thead>
        <tr><th>Memo No</th><th>Date</th><th>Type</th><th>Subject</th><th>Issued By</th><th>Action</th></tr>
    </thead>
    <tbody>
        <?php if (empty($mpn_rows)): ?>
        <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:18px;">No memos issued to this employee yet.</td></tr>
        <?php else: foreach ($mpn_rows as $r): ?>
        <tr>
            <td><?= mpn_h($r['memo_no'] ?: ('#' . $r['id'])) ?></td>
            <td><?= mpn_h(mpn_dmy($r['memo_date'])) ?></td>
            <td><span class="mpn-pill"><?= mpn_h($r['memo_type']) ?></span></td>
            <td><?= mpn_h($r['subject']) ?></td>
            <td><?= mpn_h($r['issued_by']) ?></td>
            <td style="white-space:nowrap;">
                <a class="mpn-print" href="employee_memo_print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
                <?php if ($mpn_admin): ?>
                <form method="POST" action="employee_memo.php" style="display:inline;" onsubmit="return confirm('Delete this memo? This cannot be undone.');">
                    <input type="hidden" name="delete_memo" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="origin" value="overview">
                    <input type="hidden" name="origin_search" value="<?= mpn_h($mpn_uno) ?>">
                    <button type="submit" class="mpn-del">Delete</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
    </tbody>
</table>

<script>
function mpnToggle(){
    document.getElementById('mpnHead').classList.toggle('open');
    document.getElementById('mpnBody').classList.toggle('open');
}
var MPN_TEMPLATES = <?= json_encode($mpn_js_templates, JSON_UNESCAPED_UNICODE) ?>;
var mpnLastSubject = document.getElementById('mpnSubject').value;
var mpnLastBody    = document.getElementById('mpnBodyText').value;
function mpnApplyTemplate(){
    var type = document.getElementById('mpnType').value;
    var tpl  = MPN_TEMPLATES[type];
    if (!tpl) return;
    var s = document.getElementById('mpnSubject');
    var b = document.getElementById('mpnBodyText');
    if (b.value.trim() === '' || b.value === mpnLastBody) { b.value = tpl.body; }
    if (s.value.trim() === '' || s.value === mpnLastSubject) { s.value = tpl.subject; }
    mpnLastBody = b.value; mpnLastSubject = s.value;
}
<?php if ($mpn_print_id > 0): ?>
/* A memo was just saved from this tab — open its printable copy. */
window.addEventListener('load', function(){
    window.open('employee_memo_print.php?id=<?= $mpn_print_id ?>&auto=1', '_blank');
});
<?php endif; ?>
</script>
