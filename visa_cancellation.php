<?php
/* ─────────────────────────────────────────────
   Visa Cancellation Details Report + management (UAE HR).

   - Report with filters (date range, department, designation, nationality,
     visa status, cancellation status, reason, search) and a summary
     dashboard (total / pending / completed / total gratuity payable).
   - Colour-highlighted status (Pending=yellow, Approved=green, Rejected=red).
   - Add / edit / delete a cancellation record (off-boarding details).
   - CSV (Excel) export and a print / PDF view, both respecting the filters.

   Schema + shared logic live in visa_cancellation_helper.php.
───────────────────────────────────────────── */
include 'auth.php';
include_once 'visa_cancellation_helper.php';
requirePermission('reports_view');
vc_ensure_schema($conn);

$can_edit = function_exists('hasPermission') ? (hasPermission('employee_add') || is_admin_user()) : true;

function vh($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
if (!function_exists('money')) { function money($a) { return number_format((float)$a, 2); } }
function vc_date_dmy($v) { $v = trim((string)$v); if ($v === '' || $v === '0000-00-00') return ''; $t = strtotime($v); return $t ? date('d-m-Y', $t) : $v; }
function vc_yn($v) { return ((int)$v === 1) ? 'Yes' : 'No'; }

/* ── Collect filters ─────────────────────────────────────────────── */
$filters = [
    'from'                => trim($_GET['from'] ?? ''),
    'to'                  => trim($_GET['to'] ?? ''),
    'department'          => trim($_GET['department'] ?? ''),
    'designation'         => trim($_GET['designation'] ?? ''),
    'nationality'         => trim($_GET['nationality'] ?? ''),
    'cancellation_status' => trim($_GET['cancellation_status'] ?? ''),
    'reason'              => trim($_GET['reason'] ?? ''),
    'visa_status'         => trim($_GET['visa_status'] ?? ''),
    'search'              => trim($_GET['search'] ?? ''),
];
$qs = http_build_query(array_filter($filters));

/* ── Save (insert / update) ──────────────────────────────────────── */
if ($can_edit && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $user_no = trim($_POST['user_no'] ?? '');
    if ($user_no !== '') {
        $data = ['user_no' => $user_no];
        foreach (vc_editable_fields() as $field => $type) {
            $raw = $_POST[$field] ?? '';
            if ($type === 'date') {
                $norm = function_exists('normalize_input_date') ? normalize_input_date($raw) : trim((string)$raw);
                $data[$field] = ($norm === '' ? null : $norm);
            } elseif ($type === 'i') {
                $data[$field] = ((string)$raw === '1') ? 1 : 0;
            } elseif ($type === 'd') {
                $data[$field] = (float)($raw === '' ? 0 : $raw);
            } else {
                $data[$field] = trim((string)$raw);
            }
        }
        $data['created_by'] = $_SESSION['username'] ?? '';

        $fields = array_keys($data);
        $typeMap = vc_editable_fields();
        $types = '';
        $values = [];
        foreach ($fields as $fn) {
            if ($fn === 'user_no' || $fn === 'created_by') { $types .= 's'; }
            else { $t = $typeMap[$fn] ?? 's'; $types .= ($t === 'date') ? 's' : $t; }
            $values[] = $data[$fn];
        }
        if ($id > 0) {
            $set = implode(', ', array_map(fn($f) => "`$f`=?", $fields));
            $sql = "UPDATE visa_cancellations SET $set WHERE id=?";
            $types .= 'i'; $values[] = $id;
        } else {
            $cols = implode(', ', array_map(fn($f) => "`$f`", $fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO visa_cancellations ($cols) VALUES ($ph)";
        }
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$values);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    header('Location: visa_cancellation.php' . ($qs ? "?$qs&" : '?') . 'msg=saved');
    exit;
}

/* ── Delete ──────────────────────────────────────────────────────── */
if ($can_edit && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM visa_cancellations WHERE id=?");
        if ($stmt) { mysqli_stmt_bind_param($stmt, 'i', $id); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }
    }
    header('Location: visa_cancellation.php' . ($qs ? "?$qs&" : '?') . 'msg=deleted');
    exit;
}

/* ── Data ────────────────────────────────────────────────────────── */
$rows = vc_fetch_records($conn, $filters);
$summary = vc_summary($rows);
$is_print = isset($_GET['print']) && $_GET['print'] === '1';

/* ── CSV / Excel export ──────────────────────────────────────────── */
if (($_GET['export'] ?? '') === 'csv') {
    $headers = ['User No','Emp ID','Name','Passport No','Emirates ID','Nationality','Department','Designation','Company',
        'Emirates No','Visa Type','Visa Issue','Visa Expiry','Sponsor','Labour Card No',
        'Visa Cancel Date','Labour Card Cancel Date','Cancellation App No','Cancellation Status','Reason',
        'Last Working Day','Notice Start','Notice End','Basic Salary','Gratuity','Leave Encashment','Final Settlement','Settlement Status',
        'Exit Country Date','Air Ticket','Re-entry Eligible','Passport Returned','Emirates ID Returned','Assets Returned','Clearance','Remarks'];
    $company = defined('COMPANY_NAME') ? COMPANY_NAME : '';
    $cell = function ($v) { $v = str_replace(["\r\n","\r","\n"], ' ', (string)$v); return '"' . str_replace('"', '""', $v) . '"'; };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=visa_cancellation_report_' . date('Y_m_d') . '.csv');
    header('Pragma: no-cache'); header('Expires: 0');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map($cell, $headers)) . "\r\n";
    foreach ($rows as $r) {
        $line = [
            vc_pick($r, ['user_no']), vc_pick($r, ['employee_id','card_no']), vc_pick($r, ['emp_name']),
            vc_pick($r, ['passport']), vc_pick($r, ['emirates_id_number']), vc_pick($r, ['nationality']),
            vc_pick($r, ['department']), vc_pick($r, ['designation']), $company,
            vc_pick($r, ['emirates_number']), vc_pick($r, ['visa_type']), vc_date_dmy(vc_pick($r, ['visa_issue_date'])),
            vc_date_dmy(vc_pick($r, ['visa_expiry_date'])), vc_pick($r, ['visa_sponsor']), vc_pick($r, ['labour_card_number']),
            vc_date_dmy(vc_pick($r, ['visa_cancellation_date'])), vc_date_dmy(vc_pick($r, ['labour_card_cancellation_date'])),
            vc_pick($r, ['cancellation_application_number']), vc_pick($r, ['cancellation_status']), vc_pick($r, ['cancellation_reason']),
            vc_date_dmy(vc_pick($r, ['last_working_date'])), vc_date_dmy(vc_pick($r, ['notice_period_start'])), vc_date_dmy(vc_pick($r, ['notice_period_end'])),
            money(vc_pick($r, ['basic_salary'], 0)), money(vc_pick($r, ['gratuity_amount'], 0)), money(vc_pick($r, ['leave_encashment'], 0)),
            money(vc_pick($r, ['final_settlement_amount'], 0)), vc_pick($r, ['settlement_status']),
            vc_date_dmy(vc_pick($r, ['exit_country_date'])), vc_yn(vc_pick($r, ['air_ticket_provided'], 0)), vc_yn(vc_pick($r, ['re_entry_eligible'], 0)),
            vc_yn(vc_pick($r, ['passport_returned'], 0)), vc_yn(vc_pick($r, ['emirates_id_returned'], 0)), vc_yn(vc_pick($r, ['company_assets_returned'], 0)),
            vc_pick($r, ['clearance_status']), vc_pick($r, ['remarks']),
        ];
        echo implode(',', array_map($cell, $line)) . "\r\n";
    }
    exit;
}

/* ── Edit / new form state ───────────────────────────────────────── */
$show_form = false;
$rec = [];
$form_employee = null;
if ($can_edit && isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $res = mysqli_query($conn, "SELECT * FROM visa_cancellations WHERE id=$eid LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $rec = mysqli_fetch_assoc($res);
        $form_employee = vc_get_employee($conn, $rec['user_no']);
        $show_form = true;
    }
} elseif ($can_edit && isset($_GET['new'])) {
    $show_form = true;
    $new_user = trim($_GET['user_no'] ?? '');
    if ($new_user !== '') {
        $form_employee = vc_get_employee($conn, $new_user);
        if ($form_employee) {
            // Pre-fill visa + settlement fields from the employee record.
            $rec = [
                'user_no'          => $new_user,
                'emirates_number'  => vc_pick($form_employee, ['emirates_id_number']),
                'visa_issue_date'  => vc_pick($form_employee, ['visa_issuing_date']),
                'visa_expiry_date' => vc_pick($form_employee, ['visa_expiry_date']),
                'labour_card_number' => vc_pick($form_employee, ['uid_number']),
                'basic_salary'     => vc_pick($form_employee, ['basic_salary'], 0),
                'last_working_date'=> vc_pick($form_employee, ['resign_date']),
                'visa_type'        => 'Employment',
                'cancellation_status' => 'Pending',
                'settlement_status'   => 'Pending',
                'clearance_status'    => 'Pending',
                're_entry_eligible'   => 1,
            ];
        } else {
            $rec = ['user_no' => $new_user];
        }
    }
}
function rv($rec, $k, $d = '') { return isset($rec[$k]) && $rec[$k] !== null ? $rec[$k] : $d; }

$flash = $_GET['msg'] ?? '';
$departments  = vc_distinct_values($conn, 'department');
$nationalities= vc_distinct_values($conn, 'nationality');
$designations = vc_distinct_values($conn, 'designation');
$company_name = defined('COMPANY_NAME') ? COMPANY_NAME : 'Company';
$report_date  = date('d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visa Cancellation Report</title>
<style>
:root{--brand:#1a2533;--accent:#e8a020;--blue:#2563a8;--gray-50:#f7f9fc;--gray-100:#eef2f7;--gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-600:#475569;--green:#1e8e5a;--red:#c0392b;--amber:#b9770e;--radius:10px;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:var(--gray-50);color:#1f2933;}
.topbar{display:flex;align-items:center;justify-content:space-between;background:var(--brand);color:#fff;padding:10px 18px;position:sticky;top:0;z-index:1100;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar .logo{font-weight:700;font-size:15px;}
.topbar .logo span{color:var(--accent);}
.tbtn{background:rgba(255,255,255,.12);color:#fff;text-decoration:none;border:none;cursor:pointer;padding:7px 12px;border-radius:6px;font-size:13px;display:inline-flex;align-items:center;gap:5px;}
.tbtn:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;max-width:1380px;margin:0 auto;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:4px;}
.subtitle{color:var(--gray-600);font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);margin-bottom:20px;overflow:hidden;}
.card-header{background:var(--gray-100);padding:12px 16px;font-weight:600;color:var(--brand);font-size:14px;border-bottom:1px solid var(--gray-200);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.card-body{padding:16px;}
.row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.fgroup{display:flex;flex-direction:column;gap:5px;}
.fgroup label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fgroup input,.fgroup select,.fgroup textarea{padding:8px 10px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:150px;background:#fff;}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-weight:600;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn-success{background:var(--green);color:#fff;}
.btn-danger{background:var(--red);color:#fff;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn:hover{opacity:.92;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:20px;}
.stat{background:#fff;border:1px solid var(--gray-200);border-left:4px solid var(--blue);border-radius:var(--radius);padding:14px 16px;}
.stat.amber{border-left-color:var(--accent);}.stat.green{border-left-color:var(--green);}.stat.red{border-left-color:var(--red);}.stat.gray{border-left-color:var(--gray-400);}
.stat .label{font-size:12px;color:var(--gray-600);text-transform:uppercase;letter-spacing:.4px;}
.stat .value{font-size:22px;font-weight:700;color:var(--brand);margin-top:4px;}
.flash{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:600;background:#e3f6ec;color:var(--green);border:1px solid #b6e3c9;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:9px 11px;border-bottom:1px solid var(--gray-100);text-align:left;white-space:nowrap;}
th{background:var(--gray-100);color:var(--brand);font-weight:700;position:sticky;top:0;}
td.num,th.num{text-align:right;}
tbody tr:hover{background:var(--gray-50);}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.muted{color:var(--gray-400);}
.empty{padding:40px;text-align:center;color:var(--gray-400);}
.tfoot td{font-weight:800;background:var(--gray-100);}
/* form */
fieldset{border:1px solid var(--gray-200);border-radius:9px;padding:14px 16px;margin-bottom:16px;}
legend{font-weight:700;color:var(--brand);font-size:13px;padding:0 6px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
.grid .full{grid-column:1/-1;}
.emp-box{background:var(--gray-50);border:1px dashed var(--gray-200);border-radius:8px;padding:10px 14px;font-size:13px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px 18px;margin-bottom:14px;}
.emp-box b{color:var(--brand);}
.print-head{display:none;}
@media print{
    .topbar,.appnav,.appnav-toggle,.appnav-backdrop,.btn,.filter-card,.actions-col,.no-print{display:none !important;}
    body{background:#fff;padding-left:0 !important;}
    .page{max-width:none;padding:0;}
    .print-head{display:block;margin-bottom:12px;text-align:center;}
    .stat{break-inside:avoid;}
    @page{size:A4 landscape;margin:9mm;}
}
</style>
</head>
<body>
<?php if (!$is_print) { include 'nav_sidebar.php'; } ?>

<?php if (!$is_print): ?>
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="tbtn">&#8592; Dashboard</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;') : ''; ?>
        <span class="logo"><?php echo vh(strtoupper($company_name)); ?></span>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="visa_cancellation.php?<?php echo $qs ? $qs.'&' : ''; ?>export=csv" class="tbtn">&#128229; Excel</a>
        <a href="visa_cancellation.php?<?php echo $qs ? $qs.'&' : ''; ?>print=1" target="_blank" rel="noopener" class="tbtn">&#128438; PDF / Print</a>
    </div>
</div>
<?php endif; ?>

<div class="page">
    <div class="print-head">
        <?php echo function_exists('company_logo_img') ? company_logo_img(46) : ''; ?>
        <h2 style="margin:6px 0 2px;color:#1a2533;"><?php echo vh($company_name); ?></h2>
        <div style="font-size:14px;color:#475569;">Visa Cancellation Details Report &middot; <?php echo vh($report_date); ?></div>
    </div>

    <div class="page-title no-print"><span>&#128203;</span> Visa Cancellation Report</div>
    <div class="subtitle no-print">Track employee visa cancellation, final settlement, exit and document-clearance details.</div>

    <?php if ($flash === 'saved'): ?><div class="flash">Cancellation record saved.</div><?php endif; ?>
    <?php if ($flash === 'deleted'): ?><div class="flash">Record deleted.</div><?php endif; ?>

    <!-- Summary dashboard -->
    <div class="summary-grid">
        <div class="stat"><div class="label">Total Cancellations</div><div class="value"><?php echo (int)$summary['total']; ?></div></div>
        <div class="stat amber"><div class="label">Pending / Submitted</div><div class="value"><?php echo (int)$summary['pending']; ?></div></div>
        <div class="stat green"><div class="label">Completed</div><div class="value"><?php echo (int)$summary['completed']; ?></div></div>
        <div class="stat gray"><div class="label">Total Gratuity Payable</div><div class="value" style="font-size:17px;">AED <?php echo money($summary['total_gratuity']); ?></div></div>
    </div>

    <?php if (!$is_print): ?>
    <!-- Filters -->
    <div class="card filter-card">
        <div class="card-header">&#128269; Filters
            <?php if ($can_edit): ?>
            <span style="display:flex;gap:6px;align-items:center;">
                <form method="GET" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="new" value="1">
                    <input type="text" name="user_no" placeholder="Employee User No" style="padding:6px 9px;border:1px solid var(--gray-200);border-radius:6px;font-size:12px;">
                    <button class="btn btn-success btn-sm" type="submit">&#43; Add Cancellation</button>
                </form>
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="GET" class="row">
                <div class="fgroup"><label>Cancel Date From</label><input type="date" name="from" value="<?php echo vh($filters['from']); ?>"></div>
                <div class="fgroup"><label>To</label><input type="date" name="to" value="<?php echo vh($filters['to']); ?>"></div>
                <div class="fgroup"><label>Department</label><select name="department"><option value="">All</option><?php foreach ($departments as $d): ?><option value="<?php echo vh($d); ?>" <?php echo $filters['department']===$d?'selected':''; ?>><?php echo vh($d); ?></option><?php endforeach; ?></select></div>
                <div class="fgroup"><label>Designation</label><select name="designation"><option value="">All</option><?php foreach ($designations as $d): ?><option value="<?php echo vh($d); ?>" <?php echo $filters['designation']===$d?'selected':''; ?>><?php echo vh($d); ?></option><?php endforeach; ?></select></div>
                <div class="fgroup"><label>Nationality</label><select name="nationality"><option value="">All</option><?php foreach ($nationalities as $d): ?><option value="<?php echo vh($d); ?>" <?php echo $filters['nationality']===$d?'selected':''; ?>><?php echo vh($d); ?></option><?php endforeach; ?></select></div>
                <div class="fgroup"><label>Cancellation Status</label><select name="cancellation_status"><option value="">All</option><?php foreach (vc_cancellation_statuses() as $s): ?><option value="<?php echo $s; ?>" <?php echo $filters['cancellation_status']===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
                <div class="fgroup"><label>Reason</label><select name="reason"><option value="">All</option><?php foreach (vc_cancellation_reasons() as $s): ?><option value="<?php echo $s; ?>" <?php echo $filters['reason']===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
                <div class="fgroup"><label>Visa Status</label><select name="visa_status"><option value="">All</option><option value="Valid" <?php echo $filters['visa_status']==='Valid'?'selected':''; ?>>Valid</option><option value="Expired" <?php echo $filters['visa_status']==='Expired'?'selected':''; ?>>Expired</option></select></div>
                <div class="fgroup"><label>Search</label><input type="text" name="search" value="<?php echo vh($filters['search']); ?>" placeholder="User No / Name / Visa No"></div>
                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="visa_cancellation.php" class="btn btn-gray">Reset</a>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_form): /* ── Add / Edit form ── */ ?>
    <div class="card no-print" id="cancelForm">
        <div class="card-header"><?php echo (int)rv($rec,'id') > 0 || isset($_GET['edit']) ? 'Edit' : 'New'; ?> Visa Cancellation <?php echo $form_employee ? '&mdash; ' . vh(vc_pick($form_employee, ['full_name','name'])) . ' (' . vh(rv($rec,'user_no')) . ')' : ''; ?></div>
        <div class="card-body">
            <?php if ($form_employee): ?>
            <div class="emp-box">
                <div><b>User No:</b> <?php echo vh(vc_pick($form_employee, ['user_no'])); ?></div>
                <div><b>Name:</b> <?php echo vh(vc_pick($form_employee, ['full_name','name'])); ?></div>
                <div><b>Passport:</b> <?php echo vh(vc_pick($form_employee, ['passport'])) ?: '—'; ?></div>
                <div><b>Emirates ID:</b> <?php echo vh(vc_pick($form_employee, ['emirates_id_number'])) ?: '—'; ?></div>
                <div><b>Nationality:</b> <?php echo vh(vc_pick($form_employee, ['nationality'])) ?: '—'; ?></div>
                <div><b>Department:</b> <?php echo vh(vc_pick($form_employee, ['department'])) ?: '—'; ?></div>
                <div><b>Designation:</b> <?php echo vh(vc_pick($form_employee, ['designation'])) ?: '—'; ?></div>
            </div>
            <?php elseif (rv($rec,'user_no') !== ''): ?>
                <div class="flash" style="background:#fdecea;color:var(--red);border-color:#f5c6c0;">No employee found for User No "<?php echo vh(rv($rec,'user_no')); ?>". You can still save; employee details will link when the User No exists.</div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int)rv($rec,'id'); ?>">
                <input type="hidden" name="user_no" value="<?php echo vh(rv($rec,'user_no')); ?>">

                <?php if (rv($rec,'user_no') === ''): ?>
                <fieldset><legend>Employee</legend>
                    <div class="grid"><div class="fgroup full"><label>Employee User No *</label><input type="text" name="user_no" value="" required></div></div>
                </fieldset>
                <?php endif; ?>

                <fieldset><legend>Visa Details</legend>
                    <div class="grid">
                        <div class="fgroup"><label>Emirates Number</label><input type="text" name="emirates_number" value="<?php echo vh(rv($rec,'emirates_number')); ?>"></div>
                        <div class="fgroup"><label>Visa Type</label><select name="visa_type"><option value="">—</option><?php foreach (vc_visa_types() as $t): ?><option value="<?php echo $t; ?>" <?php echo rv($rec,'visa_type')===$t?'selected':''; ?>><?php echo $t; ?></option><?php endforeach; ?></select></div>
                        <div class="fgroup"><label>Visa Issue Date</label><input type="date" name="visa_issue_date" value="<?php echo vh(rv($rec,'visa_issue_date')); ?>"></div>
                        <div class="fgroup"><label>Visa Expiry Date</label><input type="date" name="visa_expiry_date" value="<?php echo vh(rv($rec,'visa_expiry_date')); ?>"></div>
                        <div class="fgroup"><label>Visa Sponsor</label><input type="text" name="visa_sponsor" value="<?php echo vh(rv($rec,'visa_sponsor')); ?>"></div>
                        <div class="fgroup"><label>Labour Card Number</label><input type="text" name="labour_card_number" value="<?php echo vh(rv($rec,'labour_card_number')); ?>"></div>
                    </div>
                </fieldset>

                <fieldset><legend>Cancellation Details</legend>
                    <div class="grid">
                        <div class="fgroup"><label>Visa Cancellation Date</label><input type="date" name="visa_cancellation_date" value="<?php echo vh(rv($rec,'visa_cancellation_date')); ?>"></div>
                        <div class="fgroup"><label>Labour Card Cancellation Date</label><input type="date" name="labour_card_cancellation_date" value="<?php echo vh(rv($rec,'labour_card_cancellation_date')); ?>"></div>
                        <div class="fgroup"><label>Cancellation Application No</label><input type="text" name="cancellation_application_number" value="<?php echo vh(rv($rec,'cancellation_application_number')); ?>"></div>
                        <div class="fgroup"><label>Cancellation Status</label><select name="cancellation_status"><?php foreach (vc_cancellation_statuses() as $s): ?><option value="<?php echo $s; ?>" <?php echo rv($rec,'cancellation_status','Pending')===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
                        <div class="fgroup"><label>Cancellation Reason</label><select name="cancellation_reason"><option value="">—</option><?php foreach (vc_cancellation_reasons() as $s): ?><option value="<?php echo $s; ?>" <?php echo rv($rec,'cancellation_reason')===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
                    </div>
                </fieldset>

                <fieldset><legend>Final Settlement</legend>
                    <div class="grid">
                        <div class="fgroup"><label>Last Working Date</label><input type="date" name="last_working_date" value="<?php echo vh(rv($rec,'last_working_date')); ?>"></div>
                        <div class="fgroup"><label>Notice Period Start</label><input type="date" name="notice_period_start" value="<?php echo vh(rv($rec,'notice_period_start')); ?>"></div>
                        <div class="fgroup"><label>Notice Period End</label><input type="date" name="notice_period_end" value="<?php echo vh(rv($rec,'notice_period_end')); ?>"></div>
                        <div class="fgroup"><label>Basic Salary (AED)</label><input type="number" step="0.01" name="basic_salary" value="<?php echo vh(rv($rec,'basic_salary',0)); ?>"></div>
                        <div class="fgroup"><label>Gratuity Amount (AED)</label><input type="number" step="0.01" name="gratuity_amount" value="<?php echo vh(rv($rec,'gratuity_amount',0)); ?>"></div>
                        <div class="fgroup"><label>Leave Encashment (AED)</label><input type="number" step="0.01" name="leave_encashment" value="<?php echo vh(rv($rec,'leave_encashment',0)); ?>"></div>
                        <div class="fgroup"><label>Final Settlement (AED)</label><input type="number" step="0.01" name="final_settlement_amount" value="<?php echo vh(rv($rec,'final_settlement_amount',0)); ?>"></div>
                        <div class="fgroup"><label>Settlement Status</label><select name="settlement_status"><?php foreach (vc_settlement_statuses() as $s): ?><option value="<?php echo $s; ?>" <?php echo rv($rec,'settlement_status','Pending')===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
                    </div>
                </fieldset>

                <fieldset><legend>Exit Information</legend>
                    <div class="grid">
                        <div class="fgroup"><label>Exit Country Date</label><input type="date" name="exit_country_date" value="<?php echo vh(rv($rec,'exit_country_date')); ?>"></div>
                        <div class="fgroup"><label>Air Ticket Provided</label><select name="air_ticket_provided"><option value="0" <?php echo (int)rv($rec,'air_ticket_provided',0)===0?'selected':''; ?>>No</option><option value="1" <?php echo (int)rv($rec,'air_ticket_provided',0)===1?'selected':''; ?>>Yes</option></select></div>
                        <div class="fgroup"><label>Re-entry Eligible</label><select name="re_entry_eligible"><option value="1" <?php echo (int)rv($rec,'re_entry_eligible',1)===1?'selected':''; ?>>Yes</option><option value="0" <?php echo (int)rv($rec,'re_entry_eligible',1)===0?'selected':''; ?>>No</option></select></div>
                        <div class="fgroup full"><label>Remarks</label><textarea name="remarks" rows="2"><?php echo vh(rv($rec,'remarks')); ?></textarea></div>
                    </div>
                </fieldset>

                <fieldset><legend>Document Tracking &amp; Clearance</legend>
                    <div class="grid">
                        <div class="fgroup"><label>Passport Returned</label><select name="passport_returned"><option value="0" <?php echo (int)rv($rec,'passport_returned',0)===0?'selected':''; ?>>No</option><option value="1" <?php echo (int)rv($rec,'passport_returned',0)===1?'selected':''; ?>>Yes</option></select></div>
                        <div class="fgroup"><label>Emirates ID Returned</label><select name="emirates_id_returned"><option value="0" <?php echo (int)rv($rec,'emirates_id_returned',0)===0?'selected':''; ?>>No</option><option value="1" <?php echo (int)rv($rec,'emirates_id_returned',0)===1?'selected':''; ?>>Yes</option></select></div>
                        <div class="fgroup"><label>Company Assets Returned</label><select name="company_assets_returned"><option value="0" <?php echo (int)rv($rec,'company_assets_returned',0)===0?'selected':''; ?>>No</option><option value="1" <?php echo (int)rv($rec,'company_assets_returned',0)===1?'selected':''; ?>>Yes</option></select></div>
                        <div class="fgroup"><label>Clearance Status</label><select name="clearance_status"><?php foreach (vc_clearance_statuses() as $s): ?><option value="<?php echo $s; ?>" <?php echo rv($rec,'clearance_status','Pending')===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?></select></div>
                    </div>
                </fieldset>

                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-success">&#128190; Save Record</button>
                    <a href="visa_cancellation.php?<?php echo $qs; ?>" class="btn btn-gray">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Report table -->
    <div class="card">
        <div class="card-header">&#128221; Cancellation Records (<?php echo count($rows); ?>)</div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>SL</th><th>User No</th><th>Name</th><th>Passport No</th><th>Emirates No</th>
                    <th>Visa Expiry</th><th>Cancel Date</th><th>Last Working Day</th><th>Reason</th>
                    <th>Status</th><th class="num">Gratuity</th><th class="num">Final Settlement</th>
                    <?php if (!$is_print): ?><th class="actions-col">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?php echo $is_print ? 12 : 13; ?>" class="empty">&#128203; No visa cancellation records match the filters.<?php echo $can_edit ? ' Use "Add Cancellation" above to create one.' : ''; ?></td></tr>
            <?php else: $sl = 1; foreach ($rows as $r):
                [$bg,$fg] = vc_status_colors($r['cancellation_status'] ?? '');
            ?>
                <tr>
                    <td><?php echo $sl++; ?></td>
                    <td><strong><?php echo vh(vc_pick($r,['user_no'])); ?></strong></td>
                    <td><?php echo vh(vc_pick($r,['emp_name'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo vh(vc_pick($r,['passport'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo vh(vc_pick($r,['emirates_number'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo vc_date_dmy(vc_pick($r,['visa_expiry_date'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo vc_date_dmy(vc_pick($r,['visa_cancellation_date'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo vc_date_dmy(vc_pick($r,['last_working_date'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo vh(vc_pick($r,['cancellation_reason'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><span class="pill" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;"><?php echo vh(vc_pick($r,['cancellation_status'])); ?></span></td>
                    <td class="num"><?php echo money(vc_pick($r,['gratuity_amount'],0)); ?></td>
                    <td class="num"><?php echo money(vc_pick($r,['final_settlement_amount'],0)); ?></td>
                    <?php if (!$is_print): ?>
                    <td class="actions-col">
                        <div style="display:flex;gap:5px;">
                            <a class="btn btn-gray btn-sm" href="visa_cancellation.php?<?php echo $qs ? $qs.'&' : ''; ?>edit=<?php echo (int)$r['id']; ?>"><?php echo $can_edit ? 'Edit' : 'View'; ?></a>
                            <?php if ($can_edit): ?>
                            <form method="POST" onsubmit="return confirm('Delete this cancellation record?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Del</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr class="tfoot">
                    <td colspan="10">Totals (<?php echo count($rows); ?> records)</td>
                    <td class="num">AED <?php echo money($summary['total_gratuity']); ?></td>
                    <td class="num">AED <?php echo money($summary['total_settlement']); ?></td>
                    <?php if (!$is_print): ?><td></td><?php endif; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card no-print">
        <div class="card-body" style="font-size:12px;color:var(--gray-600);line-height:1.6;">
            <strong>Status colours:</strong>
            <span class="pill" style="background:#fef3c7;color:#92400e;">Pending</span>
            <span class="pill" style="background:#dbeafe;color:#1e40af;">Submitted</span>
            <span class="pill" style="background:#dcfce7;color:#166534;">Approved</span>
            <span class="pill" style="background:#fee2e2;color:#991b1b;">Rejected</span>
            <span class="pill" style="background:#cffafe;color:#155e75;">Completed</span>
            &nbsp;·&nbsp; Employee name, passport, Emirates ID, nationality, department and designation are read live from the employee record. Tip: open an employee's gratuity figure from the <a href="gratuity_report.php">Gratuity Report</a>.
        </div>
    </div>
</div>

<?php if ($is_print): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
<?php endif; ?>
</body>
</html>
