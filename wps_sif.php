<?php
/* ─────────────────────────────────────────────
   UAE WPS — Salary Information File (SIF) generation.

   Workflow:
     1. Configure employer WPS details (establishment ID, bank routing).
     2. Maintain each employee's bank details (IBAN, routing, labour-card
        personal no.) — editable inline here.
     3. Pick a salary month that has generated salary records.
     4. Review the validation report + preview, then download the .sif file
        for upload to the bank / exchange WPS portal.

   The file reconciles to the actual net pay transferred. Calculation rules
   live in wps_helper.php.
───────────────────────────────────────────── */
include 'auth.php';
include_once 'wps_helper.php';
requireAnyPermission(['salary_generate','wps_manage']);

wps_ensure_schema($conn);

function wps_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// ── Resolve month (default to previous month — that's what you pay now) ──
$month = trim($_GET['month'] ?? $_POST['month'] ?? '');
if ($month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m', strtotime('first day of last month'));
}
$month_label = date('F Y', strtotime($month . '-01'));

// ── POST: save employer settings ────────────────────────────────────
if (($_POST['action'] ?? '') === 'save_settings') {
    wps_save_settings($conn, [
        'establishment_id'   => $_POST['establishment_id'] ?? '',
        'employer_routing'   => $_POST['employer_routing'] ?? '',
        'employer_bank_name' => $_POST['employer_bank_name'] ?? '',
        'date_format'        => $_POST['date_format'] ?? 'Y-m-d',
    ]);
    header('Location: wps_sif.php?month=' . urlencode($month) . '&msg=settings_saved');
    exit;
}

// ── POST: save one employee's bank details ──────────────────────────
if (($_POST['action'] ?? '') === 'save_bank') {
    $user_no = trim($_POST['user_no'] ?? '');
    if ($user_no !== '') {
        $iban    = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $routing = trim($_POST['bank_routing_code'] ?? '');
        $mol     = trim($_POST['mol_personal_id'] ?? '');
        $bank    = trim($_POST['bank_name'] ?? '');
        $exempt  = isset($_POST['wps_exempt']) ? 1 : 0;
        $stmt = mysqli_prepare($conn, "
            UPDATE employees
            SET iban=?, bank_routing_code=?, mol_personal_id=?, bank_name=?, wps_exempt=?
            WHERE user_no=?
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssssis', $iban, $routing, $mol, $bank, $exempt, $user_no);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    header('Location: wps_sif.php?month=' . urlencode($month) . '&msg=bank_saved#u' . urlencode($user_no));
    exit;
}

// ── Load settings + data ────────────────────────────────────────────
$settings = wps_get_settings($conn);
$setting_problems = wps_validate_settings($settings);
$rows = wps_collect_rows($conn, $month, $settings);

$included_rows = array_filter($rows, fn($r) => $r['included']);
$excluded_rows = array_filter($rows, fn($r) => !$r['included']);
$total_records = count($rows);
$included_count = count($included_rows);
$included_total = array_sum(array_map(fn($r) => $r['total'], $included_rows));
$error_count = count(array_filter($rows, fn($r) => !empty($r['errors'])));

// ── GET: stream the SIF download ────────────────────────────────────
if (($_GET['action'] ?? '') === 'download') {
    if (!empty($setting_problems) || $included_count === 0) {
        header('Location: wps_sif.php?month=' . urlencode($month) . '&msg=cannot_download');
        exit;
    }
    $now_ts = time();
    $sif = wps_build_sif($rows, $month, $settings, $now_ts);
    $filename = wps_filename($settings, $month, $now_ts);
    header('Content-Type: text/plain; charset=us-ascii');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sif['content']));
    header('X-Content-Type-Options: nosniff');
    echo $sif['content'];
    exit;
}

$preview = wps_build_sif($rows, $month, $settings);
$flash = $_GET['msg'] ?? '';
$flash_map = [
    'settings_saved' => ['ok', 'Employer WPS settings saved.'],
    'bank_saved'     => ['ok', 'Employee bank details updated.'],
    'cannot_download'=> ['err', 'Cannot generate the file — fix the employer settings and ensure at least one valid employee.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WPS / SIF Generation — <?php echo wps_h($month_label); ?></title>
<style>
:root{
    --brand:#1a2533; --accent:#e8a020; --blue:#2563a8;
    --gray-50:#f7f9fc; --gray-100:#eef2f7; --gray-200:#e2e8f0;
    --gray-400:#94a3b8; --gray-600:#475569; --green:#1e8e5a; --red:#c0392b; --amber:#b9770e;
    --radius:10px;
}
*{box-sizing:border-box;}
body{margin:0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:var(--gray-50);color:#1f2933;}
.topbar{display:flex;align-items:center;justify-content:space-between;background:var(--brand);color:#fff;padding:10px 18px;position:sticky;top:0;z-index:1100;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar .logo{font-weight:700;font-size:15px;}
.topbar .logo span{color:var(--accent);}
.tbtn{background:rgba(255,255,255,.12);color:#fff;text-decoration:none;border:none;cursor:pointer;padding:7px 12px;border-radius:6px;font-size:13px;display:inline-flex;align-items:center;gap:5px;}
.tbtn:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;max-width:1320px;margin:0 auto;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:4px;}
.subtitle{color:var(--gray-600);font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);margin-bottom:20px;overflow:hidden;}
.card-header{background:var(--gray-100);padding:12px 16px;font-weight:600;color:var(--brand);font-size:14px;border-bottom:1px solid var(--gray-200);display:flex;justify-content:space-between;align-items:center;}
.card-body{padding:16px;}
.row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.fgroup{display:flex;flex-direction:column;gap:5px;}
.fgroup label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fgroup input,.fgroup select{padding:8px 10px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:150px;}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-weight:600;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn-success{background:var(--green);color:#fff;}
.btn-lg{padding:12px 22px;font-size:15px;}
.btn[disabled]{opacity:.5;cursor:not-allowed;}
.btn:hover{opacity:.92;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.stat{background:#fff;border:1px solid var(--gray-200);border-left:4px solid var(--blue);border-radius:var(--radius);padding:14px 16px;}
.stat.green{border-left-color:var(--green);}
.stat.amber{border-left-color:var(--accent);}
.stat.red{border-left-color:var(--red);}
.stat .label{font-size:12px;color:var(--gray-600);text-transform:uppercase;letter-spacing:.4px;}
.stat .value{font-size:22px;font-weight:700;color:var(--brand);margin-top:4px;}
.flash{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600;}
.flash.ok{background:#e3f6ec;color:var(--green);border:1px solid #b6e3c9;}
.flash.err{background:#fdecea;color:var(--red);border:1px solid #f5c6c0;}
.banner{padding:12px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;}
.banner.warn{background:#fef7e8;color:var(--amber);border:1px solid #f3e0b5;}
.banner ul{margin:6px 0 0;padding-left:18px;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:8px 10px;border-bottom:1px solid var(--gray-100);text-align:left;vertical-align:top;}
th{background:var(--gray-100);color:var(--brand);font-weight:700;white-space:nowrap;}
td.num,th.num{text-align:right;white-space:nowrap;}
tbody tr:hover{background:var(--gray-50);}
.tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.tag-ok{background:#e3f6ec;color:var(--green);}
.tag-skip{background:#eef2f7;color:var(--gray-600);}
.tag-err{background:#fdecea;color:var(--red);}
.muted{color:var(--gray-400);}
.mini{font-size:11px;color:var(--red);margin-top:3px;}
.mini.warn{color:var(--amber);}
.bank-input{padding:5px 7px;border:1px solid var(--gray-200);border-radius:6px;font-size:12px;width:100%;min-width:120px;}
.bank-input.mono{font-family:ui-monospace,Menlo,Consolas,monospace;}
.bank-form{display:grid;grid-template-columns:repeat(2,minmax(120px,1fr));gap:6px;align-items:center;}
.preview{background:#0f172a;color:#cbd5e1;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:14px;border-radius:8px;overflow-x:auto;white-space:pre;line-height:1.55;}
.preview .edr{color:#7dd3fc;} .preview .scr{color:#fcd34d;}
details summary{cursor:pointer;font-weight:600;color:var(--blue);}
.legal-note{font-size:12px;color:var(--gray-600);line-height:1.6;}
@media print{.topbar,.appnav,.appnav-toggle,.appnav-backdrop,.btn{display:none !important;}}
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
    <a href="generate_salary.php?month=<?php echo wps_h($month); ?>" class="tbtn">&#129534; Salary Sheet</a>
</div>

<div class="page">
    <div class="page-title"><span>&#127974;</span> WPS / SIF Generation</div>
    <div class="subtitle">Generate the UAE Wage Protection System Salary Information File for <strong><?php echo wps_h($month_label); ?></strong>, ready for upload to your bank / exchange WPS portal.</div>

    <?php if ($flash && isset($flash_map[$flash])): ?>
        <div class="flash <?php echo $flash_map[$flash][0]; ?>"><?php echo wps_h($flash_map[$flash][1]); ?></div>
    <?php endif; ?>

    <!-- Month picker -->
    <div class="card">
        <div class="card-header">&#128197; Salary Month</div>
        <div class="card-body">
            <form method="GET" class="row">
                <div class="fgroup">
                    <label>Select month (must have generated salary)</label>
                    <input type="month" name="month" value="<?php echo wps_h($month); ?>">
                </div>
                <button type="submit" class="btn btn-primary">Load</button>
            </form>
        </div>
    </div>

    <!-- Employer settings -->
    <div class="card">
        <div class="card-header">&#9881; Employer WPS Settings
            <?php if (empty($setting_problems)): ?><span class="tag tag-ok">Configured</span><?php else: ?><span class="tag tag-err">Incomplete</span><?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="month" value="<?php echo wps_h($month); ?>">
                <div class="row">
                    <div class="fgroup">
                        <label>Establishment ID (MOHRE, 13 digits)</label>
                        <input type="text" name="establishment_id" value="<?php echo wps_h($settings['establishment_id']); ?>" placeholder="e.g. 1234567890123">
                    </div>
                    <div class="fgroup">
                        <label>Employer Bank Routing Code (9 digits)</label>
                        <input type="text" name="employer_routing" value="<?php echo wps_h($settings['employer_routing']); ?>" placeholder="e.g. 509876543">
                    </div>
                    <div class="fgroup">
                        <label>Employer Bank / Agent Name</label>
                        <input type="text" name="employer_bank_name" value="<?php echo wps_h($settings['employer_bank_name']); ?>" placeholder="e.g. Emirates NBD">
                    </div>
                    <div class="fgroup">
                        <label>Date Format (match your agent's spec)</label>
                        <select name="date_format">
                            <?php foreach (['Y-m-d'=>'YYYY-MM-DD','d/m/Y'=>'DD/MM/YYYY','Ymd'=>'YYYYMMDD','d-m-Y'=>'DD-MM-YYYY'] as $fv=>$fl): ?>
                                <option value="<?php echo $fv; ?>" <?php echo ($settings['date_format']===$fv)?'selected':''; ?>><?php echo $fl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($setting_problems)): ?>
        <div class="banner warn"><strong>&#9888; Employer configuration needed before generating the file:</strong>
            <ul><?php foreach ($setting_problems as $p): ?><li><?php echo wps_h($p); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="summary-grid">
        <div class="stat <?php echo $included_count>0?'green':'red'; ?>">
            <div class="label">Records in SIF</div>
            <div class="value"><?php echo (int)$included_count; ?> <small class="muted">/ <?php echo (int)$total_records; ?></small></div>
        </div>
        <div class="stat amber">
            <div class="label">Total to Transfer</div>
            <div class="value" style="font-size:18px;">AED <?php echo money($included_total); ?></div>
        </div>
        <div class="stat <?php echo $error_count>0?'red':'green'; ?>">
            <div class="label">Validation Errors</div>
            <div class="value"><?php echo (int)$error_count; ?></div>
        </div>
        <div class="stat">
            <div class="label">Excluded</div>
            <div class="value"><?php echo count($excluded_rows); ?> <small class="muted">cash/unpaid/exempt</small></div>
        </div>
    </div>

    <!-- Download -->
    <div class="card">
        <div class="card-header">&#11015; Generate File</div>
        <div class="card-body">
            <?php $can_download = empty($setting_problems) && $included_count > 0; ?>
            <?php if ($can_download): ?>
                <a class="btn btn-success btn-lg" href="wps_sif.php?action=download&month=<?php echo wps_h($month); ?>">&#11015; Download SIF (<?php echo (int)$included_count; ?> employees, AED <?php echo money($included_total); ?>)</a>
            <?php else: ?>
                <button class="btn btn-success btn-lg" disabled>&#11015; Download SIF</button>
                <div class="mini" style="margin-top:8px;">Resolve the employer settings and ensure at least one valid, bank-paid employee with a clean validation status.</div>
            <?php endif; ?>
            <details style="margin-top:14px;">
                <summary>Preview file contents (<?php echo count($preview['lines']); ?> lines)</summary>
                <div class="preview" style="margin-top:10px;"><?php
                    foreach ($preview['lines'] as $ln) {
                        $cls = strpos($ln, 'SCR') === 0 ? 'scr' : 'edr';
                        echo '<span class="' . $cls . '">' . wps_h($ln) . "</span>\n";
                    }
                    if (count($preview['lines']) === 1) { echo '<span class="muted">(no employee records — only the SCR trailer)</span>'; }
                ?></div>
            </details>
        </div>
    </div>

    <!-- Employee table -->
    <div class="card">
        <div class="card-header">&#128100; Employee Records &amp; Bank Details — <?php echo wps_h($month_label); ?></div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Name</th>
                    <th>Bank Details (editable)</th>
                    <th class="num">Days</th>
                    <th class="num">Fixed</th>
                    <th class="num">Variable</th>
                    <th class="num">Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="muted" style="padding:28px;text-align:center;">No generated salary records for <?php echo wps_h($month_label); ?>. Generate salaries first on the Salary Sheet page.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr id="u<?php echo wps_h($r['user_no']); ?>">
                    <td><strong><?php echo wps_h($r['user_no']); ?></strong></td>
                    <td><?php echo wps_h($r['name']); ?></td>
                    <td style="min-width:340px;">
                        <form method="POST" class="bank-form">
                            <input type="hidden" name="action" value="save_bank">
                            <input type="hidden" name="month" value="<?php echo wps_h($month); ?>">
                            <input type="hidden" name="user_no" value="<?php echo wps_h($r['user_no']); ?>">
                            <input class="bank-input" name="mol_personal_id" value="<?php echo wps_h($r['mol_id']); ?>" placeholder="Employee Unique ID (14 digits)">
                            <input class="bank-input mono" name="iban" value="<?php echo wps_h($r['iban']); ?>" placeholder="IBAN (AE...)">
                            <input class="bank-input" name="bank_routing_code" value="<?php echo wps_h($r['routing']); ?>" placeholder="Routing code (9 digits)">
                            <input class="bank-input" name="bank_name" value="<?php echo wps_h($r['bank_name']); ?>" placeholder="Bank name">
                            <label style="font-size:11px;display:flex;align-items:center;gap:5px;"><input type="checkbox" name="wps_exempt" value="1" <?php echo ($r['pay_method']!=='cash' && $r['skip_reason']==='Marked WPS-exempt')?'checked':''; ?>> WPS-exempt</label>
                            <button type="submit" class="btn btn-gray" style="padding:5px 10px;font-size:12px;">Save</button>
                        </form>
                        <?php foreach ($r['errors'] as $e): ?><div class="mini">&#9888; <?php echo wps_h($e); ?></div><?php endforeach; ?>
                        <?php foreach ($r['warnings'] as $w): ?><div class="mini warn">&#9888; <?php echo wps_h($w); ?></div><?php endforeach; ?>
                    </td>
                    <td class="num"><?php echo (int)$r['days']; ?></td>
                    <td class="num"><?php echo money($r['fixed']); ?></td>
                    <td class="num"><?php echo money($r['variable']); ?></td>
                    <td class="num"><strong><?php echo money($r['total']); ?></strong></td>
                    <td>
                        <?php if ($r['included']): ?>
                            <span class="tag tag-ok">In file</span>
                        <?php elseif (!empty($r['errors'])): ?>
                            <span class="tag tag-err">Excluded</span><div class="mini"><?php echo wps_h($r['skip_reason']); ?></div>
                        <?php else: ?>
                            <span class="tag tag-skip">Excluded</span><div class="mini muted"><?php echo wps_h($r['skip_reason']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">&#9878; About this file &amp; compliance</div>
        <div class="card-body legal-note">
            The SIF lists one <strong>EDR</strong> line per paid employee plus a single <strong>SCR</strong> trailer
            (employer establishment ID, routing code, file date/time, salary month, record count, total amount, AED).
            Reported Fixed + Variable income equals the <strong>net amount transferred</strong> to each employee.
            Employees paid in cash, on unpaid leave / zero pay, or flagged WPS-exempt are excluded from the file.
            <br><br>
            <strong>Note:</strong> WPS agents (banks / exchange houses) may differ slightly in field layout or date
            format — use the Date Format setting to match your agent's published spec and validate one file on the portal
            before going live. Under the current UAE rules, wages for the preceding month should be transferred by the
            1st of the following month, and employees on approved unpaid leave must be separately notified to MOHRE.
        </div>
    </div>
</div>
</body>
</html>
