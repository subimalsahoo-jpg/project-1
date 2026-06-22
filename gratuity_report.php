<?php
/* ─────────────────────────────────────────────
   End-of-Service Gratuity Report (UAE Labour Law).

   On-screen report of each employee's accrued gratuity liability, with a
   per-employee breakdown drawer and a print view. Read-only: it derives
   everything from existing employee + salary data, so no schema changes.

   Calculation rules live in gratuity_helper.php (21 days/yr for the first
   5 years, 30 days/yr beyond, based on last basic wage, 1-year minimum,
   capped at 2 years' basic wage).
───────────────────────────────────────────── */
include 'auth.php';
include_once 'gratuity_helper.php';
requirePermission('reports_view');

if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}
function g_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }

// ── Filters ──────────────────────────────────────────────────────────
$search_user = trim($_GET['search_user'] ?? '');
$status_filter = trim($_GET['status'] ?? 'active'); // active | resigned | all
$as_of_raw = trim($_GET['as_of'] ?? '');
$as_of = $as_of_raw !== '' ? normalize_input_date($as_of_raw) : date('Y-m-d');
if ($as_of === '') { $as_of = date('Y-m-d'); }
$as_of_month = date('Y-m', strtotime($as_of));
$is_print = isset($_GET['print']) && $_GET['print'] === '1';

// ── Resolve which employee columns exist (schema is flexible) ─────────
$emp_cols = gratuity_table_columns($conn, 'employees');
$has_status_col = isset($emp_cols['employee_status']);
$has_status_legacy = isset($emp_cols['status']);
$has_resign = isset($emp_cols['resign_date']);
$status_col = $has_status_col ? 'employee_status' : ($has_status_legacy ? 'status' : '');

// ── Build employee query ─────────────────────────────────────────────
$safe_as_of = mysqli_real_escape_string($conn, $as_of);
$where = ['1=1'];

if ($search_user !== '') {
    $safe_search = mysqli_real_escape_string($conn, $search_user);
    $where[] = "(user_no LIKE '%$safe_search%' OR full_name LIKE '%$safe_search%')";
}

// Status scoping: resigned employees are those with a resign_date in the past.
if ($status_filter === 'resigned' && $has_resign) {
    $where[] = "(resign_date IS NOT NULL AND resign_date != '' AND resign_date != '0000-00-00')";
} elseif ($status_filter === 'active') {
    if ($status_col !== '') {
        $where[] = "($status_col IS NULL OR $status_col='' OR $status_col='Active')";
    } elseif ($has_resign) {
        $where[] = "(resign_date IS NULL OR resign_date='' OR resign_date='0000-00-00')";
    }
}

$where_sql = implode(' AND ', $where);
$employees_q = mysqli_query($conn, "
    SELECT * FROM employees
    WHERE $where_sql
    ORDER BY CAST(user_no AS UNSIGNED) ASC, user_no ASC
");

// ── Build report rows ────────────────────────────────────────────────
$rows = [];
$total_liability = 0.0;
$eligible_count = 0;
$ineligible_count = 0;

if ($employees_q) {
    while ($emp = mysqli_fetch_assoc($employees_q)) {
        $user_no = $emp['user_no'] ?? '';
        if ($user_no === '') { continue; }

        $joining = gratuity_pick($emp, ['joining_date', 'start_date'], '');
        $resign  = $has_resign ? trim((string)($emp['resign_date'] ?? '')) : '';
        $resign_valid = ($resign !== '' && $resign !== '0000-00-00') ? $resign : '';

        // End date = resignation date if the employee has left, else "as of".
        $end_date = $resign_valid !== '' ? $resign_valid : $as_of;

        $basic = gratuity_current_basic($conn, $user_no, $emp, $as_of_month);
        $calc = calculate_gratuity($basic, $joining, $end_date);

        if ($calc['eligible']) {
            $eligible_count++;
            $total_liability += $calc['gratuity'];
        } else {
            $ineligible_count++;
        }

        $rows[] = [
            'user_no'     => $user_no,
            'name'        => gratuity_pick($emp, ['full_name', 'name'], ''),
            'department'  => gratuity_pick($emp, ['department'], ''),
            'designation' => gratuity_pick($emp, ['designation'], ''),
            'joining'     => $joining,
            'end_date'    => $end_date,
            'is_resigned' => $resign_valid !== '',
            'calc'        => $calc,
        ];
    }
}

$report_date_label = date('d M Y', strtotime($as_of));
$status_labels = ['active' => 'Active Employees', 'resigned' => 'Resigned Employees', 'all' => 'All Employees'];
$status_label = $status_labels[$status_filter] ?? 'All Employees';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gratuity Report — <?php echo g_h($report_date_label); ?></title>
<style>
:root{
    --brand:#1a2533; --accent:#e8a020; --blue:#2563a8;
    --gray-50:#f7f9fc; --gray-100:#eef2f7; --gray-200:#e2e8f0;
    --gray-400:#94a3b8; --gray-600:#475569; --green:#1e8e5a; --red:#c0392b;
    --radius:10px;
}
*{box-sizing:border-box;}
body{margin:0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:var(--gray-50);color:#1f2933;}
.topbar{
    display:flex;align-items:center;justify-content:space-between;
    background:var(--brand);color:#fff;padding:10px 18px;position:sticky;top:0;z-index:1100;
}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar .logo{font-weight:700;font-size:15px;letter-spacing:.3px;}
.topbar .logo span{color:var(--accent);font-weight:600;}
.tbtn{
    background:rgba(255,255,255,.12);color:#fff;text-decoration:none;border:none;cursor:pointer;
    padding:7px 12px;border-radius:6px;font-size:13px;display:inline-flex;align-items:center;gap:5px;
}
.tbtn:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;max-width:1280px;margin:0 auto;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:6px;}
.page-title .icon{font-size:22px;}
.subtitle{color:var(--gray-600);font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);margin-bottom:20px;overflow:hidden;}
.card-header{background:var(--gray-100);padding:12px 16px;font-weight:600;color:var(--brand);font-size:14px;border-bottom:1px solid var(--gray-200);}
.card-body{padding:16px;}
.filter-row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.fgroup{display:flex;flex-direction:column;gap:5px;}
.fgroup label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fgroup input,.fgroup select{
    padding:8px 10px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;min-width:150px;
}
.btn{padding:9px 16px;border-radius:7px;border:none;cursor:pointer;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-weight:600;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.btn-success{background:var(--green);color:#fff;}
.btn:hover{opacity:.92;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.stat{background:#fff;border:1px solid var(--gray-200);border-left:4px solid var(--blue);border-radius:var(--radius);padding:14px 16px;}
.stat.green{border-left-color:var(--green);}
.stat.amber{border-left-color:var(--accent);}
.stat.gray{border-left-color:var(--gray-400);}
.stat .label{font-size:12px;color:var(--gray-600);text-transform:uppercase;letter-spacing:.4px;}
.stat .value{font-size:22px;font-weight:700;color:var(--brand);margin-top:4px;}
.stat .value small{font-size:13px;font-weight:600;color:var(--gray-400);}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:9px 11px;border-bottom:1px solid var(--gray-100);text-align:right;white-space:nowrap;}
th{background:var(--gray-100);color:var(--brand);font-weight:700;position:sticky;top:0;}
th.th-left,td.td-left{text-align:left;}
tbody tr:hover{background:var(--gray-50);}
.tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.tag-elig{background:#e3f6ec;color:var(--green);}
.tag-no{background:#fdeceA;background:#fdecea;color:var(--red);}
.tag-resign{background:#fef3e0;color:#b9770e;}
.muted{color:var(--gray-400);}
.amount{font-weight:700;color:var(--brand);}
.detail-btn{background:none;border:1px solid var(--gray-200);border-radius:6px;padding:4px 9px;cursor:pointer;font-size:12px;color:var(--blue);}
.detail-btn:hover{background:var(--gray-50);}
.drawer-row td{background:#fbfcfe;border-bottom:2px solid var(--gray-200);padding:0;}
.drawer{display:none;padding:14px 18px;}
.drawer.open{display:block;}
.breakup{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 26px;}
.breakup .li{display:flex;justify-content:space-between;gap:12px;font-size:13px;padding:5px 0;border-bottom:1px dashed var(--gray-200);}
.breakup .li b{color:var(--brand);}
.formula{font-size:12px;color:var(--gray-600);margin-top:10px;background:var(--gray-50);padding:8px 12px;border-radius:7px;}
.tfoot td{font-weight:800;background:var(--gray-100);color:var(--brand);border-top:2px solid var(--gray-200);}
.empty{padding:40px;text-align:center;color:var(--gray-400);}
.legal-note{font-size:12px;color:var(--gray-600);line-height:1.6;}
.print-head{display:none;}
@media print{
    .topbar,.btn,.filter-card,.detail-btn,.appnav,.appnav-toggle,.appnav-backdrop{display:none !important;}
    body{background:#fff;padding-left:0 !important;}
    .page{max-width:none;padding:0;}
    .drawer{display:block !important;}
    .print-head{display:block;margin-bottom:12px;}
    .stat{break-inside:avoid;}
    @page{size:A4 landscape;margin:10mm;}
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
        <span class="logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="gratuity_report.php?<?php echo http_build_query(['search_user'=>$search_user,'status'=>$status_filter,'as_of'=>$as_of,'print'=>1]); ?>" target="_blank" rel="noopener" class="tbtn">&#128438; Print View</a>
        <button onclick="window.print()" class="tbtn">&#128438; Print</button>
    </div>
</div>
<?php endif; ?>

<div class="page">

<div class="print-head" style="text-align:center;">
    <?php echo function_exists('company_logo_img') ? company_logo_img(46) : ''; ?>
    <h2 style="margin:6px 0 2px;color:#1a2533;">EURO TROUSERS MFG CO (FZC)</h2>
    <div style="font-size:14px;color:#475569;">End-of-Service Gratuity Report</div>
</div>

<div class="page-title"><span class="icon">&#128176;</span> End-of-Service Gratuity</div>
<div class="subtitle">
    <?php echo g_h($status_label); ?> &middot; Liability calculated as of <strong><?php echo g_h($report_date_label); ?></strong>
    (resigned staff use their actual last working day).
</div>

<?php if (!$is_print): ?>
<!-- Filter -->
<div class="card filter-card">
    <div class="card-header">&#128269; Filters</div>
    <div class="card-body">
        <form method="GET">
            <div class="filter-row">
                <div class="fgroup">
                    <label>As-of Date</label>
                    <input type="date" name="as_of" value="<?php echo g_h($as_of); ?>">
                </div>
                <div class="fgroup">
                    <label>Employee Status</label>
                    <select name="status">
                        <option value="active"   <?php echo $status_filter==='active'?'selected':''; ?>>Active only</option>
                        <option value="resigned" <?php echo $status_filter==='resigned'?'selected':''; ?>>Resigned only</option>
                        <option value="all"      <?php echo $status_filter==='all'?'selected':''; ?>>All employees</option>
                    </select>
                </div>
                <div class="fgroup">
                    <label>Search User / Name</label>
                    <input type="text" name="search_user" value="<?php echo g_h($search_user); ?>" placeholder="User No or Name">
                </div>
                <button type="submit" class="btn btn-primary">&#128269; Apply</button>
                <a href="gratuity_report.php" class="btn btn-gray">&#10005; Reset</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Summary -->
<div class="summary-grid">
    <div class="stat amber">
        <div class="label">Total Gratuity Liability</div>
        <div class="value">AED <?php echo money($total_liability); ?></div>
    </div>
    <div class="stat green">
        <div class="label">Eligible Employees</div>
        <div class="value"><?php echo (int)$eligible_count; ?></div>
    </div>
    <div class="stat gray">
        <div class="label">Not Yet Eligible</div>
        <div class="value"><?php echo (int)$ineligible_count; ?> <small>(&lt; 1 yr)</small></div>
    </div>
    <div class="stat">
        <div class="label">Employees Shown</div>
        <div class="value"><?php echo count($rows); ?></div>
    </div>
</div>

<!-- Report table -->
<div class="card">
    <div class="card-header">&#128203; Employee-wise Gratuity Breakdown</div>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="width:42px;">SL</th>
                <th>User No</th>
                <th class="th-left">Employee Name</th>
                <th class="th-left">Department</th>
                <th>Joining Date</th>
                <th>Service</th>
                <th>Basic (AED)</th>
                <th>Status</th>
                <th>Gratuity (AED)</th>
                <?php if (!$is_print): ?><th>Details</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="<?php echo $is_print ? 9 : 10; ?>" class="empty">&#128269; No employees match the selected filters.</td></tr>
        <?php else: $sl = 1; foreach ($rows as $i => $row): $c = $row['calc']; ?>
            <tr>
                <td><?php echo $sl++; ?></td>
                <td><?php echo g_h($row['user_no']); ?></td>
                <td class="td-left"><?php echo g_h($row['name']); ?></td>
                <td class="td-left"><?php echo g_h($row['department']) ?: '<span class="muted">—</span>'; ?></td>
                <td><?php echo $row['joining'] !== '' ? g_h(date('d M Y', strtotime($row['joining']))) : '<span class="muted">—</span>'; ?></td>
                <td><?php echo g_h($c['duration_label']); ?></td>
                <td><?php echo money($c['basic_salary']); ?></td>
                <td>
                    <?php if ($row['is_resigned']): ?>
                        <span class="tag tag-resign">Resigned</span>
                    <?php elseif ($c['eligible']): ?>
                        <span class="tag tag-elig">Eligible</span>
                    <?php else: ?>
                        <span class="tag tag-no">&lt; 1 yr</span>
                    <?php endif; ?>
                </td>
                <td class="amount"><?php echo $c['eligible'] ? money($c['gratuity']) : '<span class="muted">0.00</span>'; ?><?php echo $c['capped'] ? ' <span class="muted" title="Capped at 2 years\' basic wage">*</span>' : ''; ?></td>
                <?php if (!$is_print): ?>
                <td><button type="button" class="detail-btn" onclick="toggleDrawer(<?php echo $i; ?>)">View</button></td>
                <?php endif; ?>
            </tr>
            <tr class="drawer-row">
                <td colspan="<?php echo $is_print ? 9 : 10; ?>">
                    <div class="drawer<?php echo $is_print ? ' open' : ''; ?>" id="drawer-<?php echo $i; ?>">
                        <?php if (!$c['eligible']): ?>
                            <div class="muted"><strong>Not eligible:</strong> <?php echo g_h($c['reason']); ?></div>
                        <?php else: ?>
                            <div class="breakup">
                                <div class="li"><span>Joining date</span> <b><?php echo $row['joining'] !== '' ? g_h(date('d M Y', strtotime($row['joining']))) : '—'; ?></b></div>
                                <div class="li"><span>Calculation end date</span> <b><?php echo g_h(date('d M Y', strtotime($row['end_date']))); ?></b></div>
                                <div class="li"><span>Total service</span> <b><?php echo g_h($c['duration_label']); ?> (<?php echo g_h($c['service_years']); ?> yrs)</b></div>
                                <div class="li"><span>Last basic wage</span> <b>AED <?php echo money($c['basic_salary']); ?></b></div>
                                <div class="li"><span>Daily wage (basic ÷ 30)</span> <b>AED <?php echo money($c['daily_wage']); ?></b></div>
                                <div class="li"><span>First 5 yrs @ 21 days/yr</span> <b><?php echo g_h($c['first5_days']); ?> days = AED <?php echo money($c['first5_amount']); ?></b></div>
                                <div class="li"><span>Beyond 5 yrs @ 30 days/yr</span> <b><?php echo g_h($c['after5_days']); ?> days = AED <?php echo money($c['after5_amount']); ?></b></div>
                                <div class="li"><span>Gratuity (before cap)</span> <b>AED <?php echo money($c['gratuity_raw']); ?></b></div>
                                <div class="li"><span>2-year cap</span> <b>AED <?php echo money($c['cap_amount']); ?><?php echo $c['capped'] ? ' (applied)' : ''; ?></b></div>
                                <div class="li"><span>Payable gratuity</span> <b style="color:var(--green);">AED <?php echo money($c['gratuity']); ?></b></div>
                            </div>
                            <div class="formula">
                                Formula: (min(service, 5) &times; 21 + max(service − 5, 0) &times; 30) &times; (basic ÷ 30), capped at 24 &times; basic.
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <?php if (!empty($rows)): ?>
        <tfoot>
            <tr class="tfoot">
                <td colspan="8" class="td-left">Total Gratuity Liability (<?php echo (int)$eligible_count; ?> eligible)</td>
                <td class="amount">AED <?php echo money($total_liability); ?></td>
                <?php if (!$is_print): ?><td></td><?php endif; ?>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<div class="card">
    <div class="card-header">&#9878; Calculation Basis</div>
    <div class="card-body legal-note">
        Computed per UAE Federal Decree-Law No. 33 of 2021 for full-time staff:
        gratuity is based on the <strong>last basic wage</strong> (excluding allowances),
        at <strong>21 days' pay per year for the first 5 years</strong> and
        <strong>30 days' pay per year thereafter</strong>, with a minimum of 1 year's
        continuous service and a total capped at <strong>2 years' basic wage</strong>.
        Figures are an estimate for planning; final settlement may vary with unpaid leave,
        contract specifics and statutory deductions. * indicates the 2-year cap was applied.
    </div>
</div>

</div>

<script>
function toggleDrawer(i){
    var d = document.getElementById('drawer-' + i);
    if (d) d.classList.toggle('open');
}
<?php if ($is_print): ?>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });<?php endif; ?>
</script>
</body>
</html>
