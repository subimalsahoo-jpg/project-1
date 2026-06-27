<?php
/* ─────────────────────────────────────────────
   Recruitment — candidate records + interview schedule.
   Offer letters are generated on offer_letter.php.
───────────────────────────────────────────── */
include 'auth.php';
require_once 'recruitment_helper.php';
requireAnyPermission(['recruitment_manage']);
rec_ensure_schema($conn);

function rc_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rc_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-M-Y', $t) : $v;
}

$logged_in_name = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User')));
$flash = '';
$flash_type = 'ok';
$tab = ($_GET['tab'] ?? 'candidates') === 'interviews' ? 'interviews' : 'candidates';

/* ── POST handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_candidate') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['candidate_name'] ?? '');
        if ($name === '') {
            $flash = 'Candidate name is required.'; $flash_type = 'err';
        } else {
            $cn  = rec_esc($conn, $name);
            $pp  = rec_esc($conn, $_POST['passport_no'] ?? '');
            $ct  = rec_esc($conn, $_POST['contact'] ?? '');
            $em  = rec_esc($conn, $_POST['email'] ?? '');
            $nat = rec_esc($conn, $_POST['nationality'] ?? '');
            $post= rec_esc($conn, $_POST['applied_post'] ?? '');
            $src = rec_esc($conn, $_POST['source'] ?? '');
            $bs  = (float)($_POST['basic_salary'] ?? 0);
            $ea  = (float)($_POST['external_allowance'] ?? 0);
            $fa  = (float)($_POST['food_allowance'] ?? 0);
            $st  = in_array($_POST['status'] ?? 'New', rec_statuses(), true) ? $_POST['status'] : 'New';
            $sts = rec_esc($conn, $st);
            $rk  = rec_esc($conn, $_POST['remarks'] ?? '');
            $cb  = rec_esc($conn, $logged_in_name);
            if ($id > 0) {
                mysqli_query($conn, "UPDATE candidates SET candidate_name='$cn', passport_no='$pp', contact='$ct', email='$em', nationality='$nat', applied_post='$post', source='$src', basic_salary='$bs', external_allowance='$ea', food_allowance='$fa', status='$sts', remarks='$rk' WHERE id=$id");
                $flash = 'Candidate updated.';
            } else {
                mysqli_query($conn, "INSERT INTO candidates (candidate_name, passport_no, contact, email, nationality, applied_post, source, basic_salary, external_allowance, food_allowance, status, remarks, created_by) VALUES ('$cn','$pp','$ct','$em','$nat','$post','$src','$bs','$ea','$fa','$sts','$rk','$cb')");
                $flash = 'Candidate added.';
            }
        }
    } elseif ($action === 'delete_candidate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { mysqli_query($conn, "DELETE FROM candidates WHERE id=$id"); $flash = 'Candidate deleted.'; }
    } elseif ($action === 'save_interview') {
        $id    = (int)($_POST['id'] ?? 0);
        $cid   = (int)($_POST['candidate_id'] ?? 0);
        $cand  = rec_candidate($conn, $cid);
        $cname = $cand['candidate_name'] ?? trim($_POST['candidate_name'] ?? '');
        $cpost = $cand['applied_post'] ?? trim($_POST['applied_post'] ?? '');
        if ($cname === '') {
            $flash = 'Please select a candidate.'; $flash_type = 'err'; $tab = 'interviews';
        } else {
            $cn   = rec_esc($conn, $cname);
            $cp   = rec_esc($conn, $cpost);
            $date = rec_esc($conn, $_POST['interview_date'] ?? '');
            $time = rec_esc($conn, $_POST['interview_time'] ?? '');
            $mode = in_array($_POST['mode'] ?? 'In-person', rec_interview_modes(), true) ? $_POST['mode'] : 'In-person';
            $md   = rec_esc($conn, $mode);
            $loc  = rec_esc($conn, $_POST['location'] ?? '');
            $intr = rec_esc($conn, $_POST['interviewer'] ?? '');
            $st   = in_array($_POST['status'] ?? 'Scheduled', rec_interview_statuses(), true) ? $_POST['status'] : 'Scheduled';
            $sts  = rec_esc($conn, $st);
            $notes= rec_esc($conn, $_POST['result_notes'] ?? '');
            $cb   = rec_esc($conn, $logged_in_name);
            $datesql = $date !== '' ? "'$date'" : "NULL";
            if ($id > 0) {
                mysqli_query($conn, "UPDATE interviews SET candidate_id=$cid, candidate_name='$cn', applied_post='$cp', interview_date=$datesql, interview_time='$time', mode='$md', location='$loc', interviewer='$intr', status='$sts', result_notes='$notes' WHERE id=$id");
                $flash = 'Interview updated.';
            } else {
                mysqli_query($conn, "INSERT INTO interviews (candidate_id, candidate_name, applied_post, interview_date, interview_time, mode, location, interviewer, status, result_notes, created_by) VALUES ($cid,'$cn','$cp',$datesql,'$time','$md','$loc','$intr','$sts','$notes','$cb')");
                $flash = 'Interview scheduled.';
            }
            $tab = 'interviews';
        }
    } elseif ($action === 'delete_interview') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { mysqli_query($conn, "DELETE FROM interviews WHERE id=$id"); $flash = 'Interview deleted.'; }
        $tab = 'interviews';
    }
}

/* ── Edit targets ── */
$edit_cand = ($tab === 'candidates' && isset($_GET['edit'])) ? rec_candidate($conn, (int)$_GET['edit']) : null;
$edit_iv = null;
if ($tab === 'interviews' && isset($_GET['edit_iv'])) {
    $ivid = (int)$_GET['edit_iv'];
    $r = mysqli_query($conn, "SELECT * FROM interviews WHERE id=$ivid LIMIT 1");
    $edit_iv = ($r && mysqli_num_rows($r) > 0) ? mysqli_fetch_assoc($r) : null;
}
$preselect_cid = (int)($_GET['iv_candidate'] ?? ($edit_iv['candidate_id'] ?? 0));

/* ── Data ── */
$search = trim($_GET['q'] ?? '');
$cand_where = '';
if ($search !== '') {
    $s = rec_esc($conn, $search);
    $cand_where = "WHERE candidate_name LIKE '%$s%' OR passport_no LIKE '%$s%' OR applied_post LIKE '%$s%' OR contact LIKE '%$s%'";
}
$candidates = [];
$cq = mysqli_query($conn, "SELECT * FROM candidates $cand_where ORDER BY id DESC");
if ($cq) { while ($r = mysqli_fetch_assoc($cq)) { $candidates[] = $r; } }

$all_candidates = [];
$acq = mysqli_query($conn, "SELECT id, candidate_name, applied_post FROM candidates ORDER BY candidate_name ASC");
if ($acq) { while ($r = mysqli_fetch_assoc($acq)) { $all_candidates[] = $r; } }

$interviews = [];
$iq = mysqli_query($conn, "SELECT * FROM interviews ORDER BY (interview_date IS NULL), interview_date DESC, id DESC");
if ($iq) { while ($r = mysqli_fetch_assoc($iq)) { $interviews[] = $r; } }

/* Summary */
$total_cand = count($candidates);
$cnt_selected = 0; $cnt_joined = 0; $cnt_open = 0;
foreach ($candidates as $c) {
    $s = strtolower($c['status']);
    if ($s === 'selected' || $s === 'offer sent') $cnt_selected++;
    if ($s === 'joined') $cnt_joined++;
    if (!in_array($s, ['joined', 'rejected'], true)) $cnt_open++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recruitment</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--amber:#d97706;--amber-soft:#fef3c7;--red:#b91c1c;--info:#2563a8;--info-soft:#dbeafe;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
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
.card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:14px 16px;}
.card .label{font-size:12px;color:var(--gray-600);text-transform:uppercase;letter-spacing:.03em;}
.card .value{font-size:24px;font-weight:800;color:var(--brand);margin-top:4px;}
.card.ok .value{color:var(--green);}
.card.warn .value{color:var(--amber);}
.tabs{display:flex;gap:8px;margin-bottom:16px;}
.tab{padding:9px 18px;border-radius:8px;background:#fff;color:var(--brand);text-decoration:none;font-weight:700;box-shadow:var(--shadow);}
.tab.active{background:var(--brand);color:#fff;}
.panel{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;}
.panel-head{background:var(--brand);color:#fff;padding:11px 16px;font-weight:600;font-size:14px;display:flex;justify-content:space-between;align-items:center;}
.panel-body{padding:16px;}
.row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fg input,.fg select,.fg textarea{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:160px;font-family:inherit;}
.fg textarea{min-width:280px;min-height:60px;}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--brand-mid);color:#fff;}
.btn-success{background:var(--green);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn-sm{padding:5px 10px;font-size:12px;border-radius:6px;}
.btn-danger{background:#fee2e2;color:var(--red);}
.btn-accent{background:var(--accent);color:#3a2a00;}
.btn:hover{opacity:.93;}
.flash{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600;}
.flash.ok{background:var(--green-soft);color:var(--green);border:1px solid #b6e3c9;}
.flash.err{background:#fdecea;color:var(--red);border:1px solid #f5c6c0;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead th{background:var(--brand);color:#fff;padding:10px;text-align:center;font-size:12px;text-transform:uppercase;white-space:nowrap;}
tbody td{padding:9px 10px;text-align:center;border-bottom:1px solid var(--gray-200);}
tbody tr:nth-child(even){background:var(--gray-50);}
tbody td.l{text-align:left;}
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.badge.ok{background:var(--green-soft);color:var(--green);}
.badge.warn{background:var(--amber-soft);color:var(--amber);}
.badge.info{background:var(--info-soft);color:var(--info);}
.badge.bad{background:#fee2e2;color:var(--red);}
.table-wrap{overflow-x:auto;}
.muted{color:#94a3b8;}
.actbtns{display:flex;gap:5px;justify-content:center;flex-wrap:wrap;}
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
    <div><a class="btn-back" href="offer_letter.php">&#128221; Offer Letter</a></div>
</div>

<div class="page">
    <div class="page-title"><span>&#129309;</span> Recruitment</div>

    <?php if ($flash !== ''): ?><div class="flash <?php echo $flash_type; ?>"><?php echo rc_h($flash); ?></div><?php endif; ?>

    <div class="cards">
        <div class="card"><div class="label">Total Candidates</div><div class="value"><?php echo $total_cand; ?></div></div>
        <div class="card warn"><div class="label">Open / In Process</div><div class="value"><?php echo $cnt_open; ?></div></div>
        <div class="card ok"><div class="label">Selected / Offer Sent</div><div class="value"><?php echo $cnt_selected; ?></div></div>
        <div class="card ok"><div class="label">Joined</div><div class="value"><?php echo $cnt_joined; ?></div></div>
    </div>

    <div class="tabs">
        <a class="tab <?php echo $tab === 'candidates' ? 'active' : ''; ?>" href="recruitment.php?tab=candidates">&#128100; Candidates</a>
        <a class="tab <?php echo $tab === 'interviews' ? 'active' : ''; ?>" href="recruitment.php?tab=interviews">&#128197; Interview Schedule</a>
    </div>

<?php if ($tab === 'candidates'): ?>
    <!-- Candidate form -->
    <div class="panel">
        <div class="panel-head"><?php echo $edit_cand ? 'Edit Candidate' : 'Add Candidate'; ?></div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_candidate">
                <input type="hidden" name="id" value="<?php echo (int)($edit_cand['id'] ?? 0); ?>">
                <div class="row">
                    <div class="fg"><label>Candidate Name *</label><input type="text" name="candidate_name" value="<?php echo rc_h($edit_cand['candidate_name'] ?? ''); ?>" required></div>
                    <div class="fg"><label>Passport No</label><input type="text" name="passport_no" value="<?php echo rc_h($edit_cand['passport_no'] ?? ''); ?>"></div>
                    <div class="fg"><label>Contact</label><input type="text" name="contact" value="<?php echo rc_h($edit_cand['contact'] ?? ''); ?>"></div>
                    <div class="fg"><label>Email</label><input type="text" name="email" value="<?php echo rc_h($edit_cand['email'] ?? ''); ?>"></div>
                    <div class="fg"><label>Nationality</label><input type="text" name="nationality" value="<?php echo rc_h($edit_cand['nationality'] ?? ''); ?>"></div>
                    <div class="fg"><label>Applied Post</label><input type="text" name="applied_post" value="<?php echo rc_h($edit_cand['applied_post'] ?? ''); ?>" placeholder="e.g. Production In charge"></div>
                    <div class="fg"><label>Source</label><input type="text" name="source" value="<?php echo rc_h($edit_cand['source'] ?? ''); ?>" placeholder="Agency / Referral / Walk-in"></div>
                    <div class="fg"><label>Basic Salary (AED)</label><input type="number" step="1" name="basic_salary" value="<?php echo rc_h($edit_cand['basic_salary'] ?? ''); ?>"></div>
                    <div class="fg"><label>External Allowance</label><input type="number" step="1" name="external_allowance" value="<?php echo rc_h($edit_cand['external_allowance'] ?? ''); ?>"></div>
                    <div class="fg"><label>Food Allowance</label><input type="number" step="1" name="food_allowance" value="<?php echo rc_h($edit_cand['food_allowance'] ?? ''); ?>"></div>
                    <div class="fg"><label>Status</label><select name="status">
                        <?php foreach (rec_statuses() as $s): ?><option value="<?php echo rc_h($s); ?>" <?php echo (($edit_cand['status'] ?? 'New') === $s) ? 'selected' : ''; ?>><?php echo rc_h($s); ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="fg" style="flex:1;"><label>Remarks</label><input type="text" name="remarks" value="<?php echo rc_h($edit_cand['remarks'] ?? ''); ?>" style="min-width:240px;"></div>
                </div>
                <div style="margin-top:14px;display:flex;gap:10px;">
                    <button class="btn btn-success" type="submit"><?php echo $edit_cand ? '&#128190; Update' : '&#43; Add Candidate'; ?></button>
                    <?php if ($edit_cand): ?><a class="btn btn-gray" href="recruitment.php?tab=candidates">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Candidate list -->
    <div class="panel">
        <div class="panel-head">
            <span>Candidates (<?php echo $total_cand; ?>)</span>
            <form method="GET" style="display:flex;gap:6px;">
                <input type="hidden" name="tab" value="candidates">
                <input type="text" name="q" value="<?php echo rc_h($search); ?>" placeholder="Search name / passport / post" style="padding:6px 10px;border-radius:6px;border:none;font-size:13px;min-width:220px;">
                <button class="btn btn-sm btn-accent" type="submit">&#128269; Search</button>
            </form>
        </div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>Name</th><th>Passport</th><th>Contact</th><th>Applied Post</th><th>Nationality</th><th>Basic (AED)</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!empty($candidates)): $sl = 1; foreach ($candidates as $c): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td class="l"><b><?php echo rc_h($c['candidate_name']); ?></b></td>
                        <td><?php echo rc_h($c['passport_no']); ?></td>
                        <td><?php echo rc_h($c['contact']); ?></td>
                        <td class="l"><?php echo rc_h($c['applied_post']); ?></td>
                        <td><?php echo rc_h($c['nationality']); ?></td>
                        <td><?php echo $c['basic_salary'] > 0 ? number_format((float)$c['basic_salary'], 0) : '-'; ?></td>
                        <td><span class="badge <?php echo rec_status_badge($c['status']); ?>"><?php echo rc_h($c['status']); ?></span></td>
                        <td>
                            <div class="actbtns">
                                <a class="btn btn-sm btn-accent" href="offer_letter.php?candidate_id=<?php echo (int)$c['id']; ?>" title="Generate Offer Letter">&#128221; Offer</a>
                                <a class="btn btn-sm btn-primary" href="recruitment.php?tab=interviews&iv_candidate=<?php echo (int)$c['id']; ?>" title="Schedule Interview">&#128197;</a>
                                <a class="btn btn-sm btn-gray" href="recruitment.php?tab=candidates&edit=<?php echo (int)$c['id']; ?>">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this candidate?');">
                                    <input type="hidden" name="action" value="delete_candidate">
                                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="muted" style="padding:18px;">No candidates yet. Add one above.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

<?php else: /* interviews */ ?>
    <!-- Interview form -->
    <div class="panel">
        <div class="panel-head"><?php echo $edit_iv ? 'Edit Interview' : 'Schedule Interview'; ?></div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_interview">
                <input type="hidden" name="id" value="<?php echo (int)($edit_iv['id'] ?? 0); ?>">
                <div class="row">
                    <div class="fg"><label>Candidate *</label><select name="candidate_id" required>
                        <option value="">-- select --</option>
                        <?php foreach ($all_candidates as $ac): ?>
                        <option value="<?php echo (int)$ac['id']; ?>" <?php echo ($preselect_cid === (int)$ac['id']) ? 'selected' : ''; ?>><?php echo rc_h($ac['candidate_name']); ?><?php echo $ac['applied_post'] !== '' ? ' (' . rc_h($ac['applied_post']) . ')' : ''; ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="fg"><label>Date</label><input type="date" name="interview_date" value="<?php echo rc_h($edit_iv['interview_date'] ?? ''); ?>"></div>
                    <div class="fg"><label>Time</label><input type="time" name="interview_time" value="<?php echo rc_h($edit_iv['interview_time'] ?? ''); ?>"></div>
                    <div class="fg"><label>Mode</label><select name="mode">
                        <?php foreach (rec_interview_modes() as $m): ?><option value="<?php echo rc_h($m); ?>" <?php echo (($edit_iv['mode'] ?? 'In-person') === $m) ? 'selected' : ''; ?>><?php echo rc_h($m); ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="fg"><label>Location / Link</label><input type="text" name="location" value="<?php echo rc_h($edit_iv['location'] ?? ''); ?>" placeholder="Office / Meeting link"></div>
                    <div class="fg"><label>Interviewer</label><input type="text" name="interviewer" value="<?php echo rc_h($edit_iv['interviewer'] ?? ''); ?>"></div>
                    <div class="fg"><label>Status</label><select name="status">
                        <?php foreach (rec_interview_statuses() as $s): ?><option value="<?php echo rc_h($s); ?>" <?php echo (($edit_iv['status'] ?? 'Scheduled') === $s) ? 'selected' : ''; ?>><?php echo rc_h($s); ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="fg" style="flex:1;"><label>Result / Notes</label><input type="text" name="result_notes" value="<?php echo rc_h($edit_iv['result_notes'] ?? ''); ?>" style="min-width:240px;"></div>
                </div>
                <div style="margin-top:14px;display:flex;gap:10px;">
                    <button class="btn btn-success" type="submit"><?php echo $edit_iv ? '&#128190; Update' : '&#43; Schedule'; ?></button>
                    <?php if ($edit_iv): ?><a class="btn btn-gray" href="recruitment.php?tab=interviews">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Interview list -->
    <div class="panel">
        <div class="panel-head"><span>Scheduled Interviews (<?php echo count($interviews); ?>)</span></div>
        <div class="panel-body">
            <div class="table-wrap">
            <table>
                <thead><tr><th>SL</th><th>Candidate</th><th>Post</th><th>Date</th><th>Time</th><th>Mode</th><th>Location</th><th>Interviewer</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!empty($interviews)): $sl = 1; foreach ($interviews as $iv): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td class="l"><b><?php echo rc_h($iv['candidate_name']); ?></b></td>
                        <td class="l"><?php echo rc_h($iv['applied_post']); ?></td>
                        <td><?php echo rc_dmy($iv['interview_date']); ?></td>
                        <td><?php echo rc_h($iv['interview_time']); ?></td>
                        <td><?php echo rc_h($iv['mode']); ?></td>
                        <td class="l"><?php echo rc_h($iv['location']); ?></td>
                        <td><?php echo rc_h($iv['interviewer']); ?></td>
                        <td><span class="badge <?php echo rec_status_badge($iv['status']); ?>"><?php echo rc_h($iv['status']); ?></span></td>
                        <td class="l"><?php echo rc_h($iv['result_notes']); ?></td>
                        <td>
                            <div class="actbtns">
                                <a class="btn btn-sm btn-gray" href="recruitment.php?tab=interviews&edit_iv=<?php echo (int)$iv['id']; ?>">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this interview?');">
                                    <input type="hidden" name="action" value="delete_interview">
                                    <input type="hidden" name="id" value="<?php echo (int)$iv['id']; ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="11" class="muted" style="padding:18px;">No interviews scheduled yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
