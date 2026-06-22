<?php
include 'auth.php';
requirePermission('employee_view');
include_once 'visa_helper.php';

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function table_columns($conn, $table) {
    $columns = [];
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[$row['Field']] = true;
        }
    }
    return $columns;
}

function value_from($row, $keys) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return '';
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function display_date_dmy($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') {
        return '';
    }

    $time = strtotime($value);
    return $time ? date('d-m-Y', $time) : $value;
}

function is_date_field_label($label) {
    return in_array($label, [
        'Birthday',
        'Start Date',
        'Passport Issue Date',
        'Passport Expiry Date',
        'Visa Issuing Date',
        'Visa Expiry Date',
        'Insurance Issuing Date',
        'Insurance Expire Date',
        'Resign Date',
    ], true);
}

$employee_columns = table_columns($conn, 'employees');
$name = $_GET['name'] ?? '';
$user_no = $_GET['user_no'] ?? '';
$status_filter = $_GET['status'] ?? '';
$dept_filter = $_GET['department'] ?? '';
$desig_filter = $_GET['designation'] ?? '';
$nation_filter = $_GET['nationality'] ?? '';
$is_excel = isset($_GET['export']) && $_GET['export'] === 'excel';

$where = ["1=1"];

if ($name !== '') {
    $safe_name = esc($conn, $name);
    $where[] = "full_name LIKE '%$safe_name%'";
}

if ($user_no !== '') {
    $safe_user_no = esc($conn, $user_no);
    $where[] = "user_no='$safe_user_no'";
}

if ($status_filter !== '') {
    $safe_status = esc($conn, $status_filter);
    if ($status_filter === 'Resigned') {
        $status_parts = [];
        if (isset($employee_columns['employee_status'])) {
            $status_parts[] = "employee_status IN ('Resigned','Resign')";
        }
        if (isset($employee_columns['status'])) {
            $status_parts[] = "status IN ('Resigned','Resign')";
        }
        if (isset($employee_columns['resign_date'])) {
            $status_parts[] = "(resign_date IS NOT NULL AND resign_date!='' AND resign_date!='0000-00-00' AND resign_date<=CURDATE())";
        }
        if (!empty($status_parts)) {
            $where[] = "(" . implode(" OR ", $status_parts) . ")";
        }
    } else {
        if (isset($employee_columns['employee_status'])) {
            $where[] = "employee_status='$safe_status'";
        } elseif (isset($employee_columns['status'])) {
            $where[] = "status='$safe_status'";
        }
    }
}

if ($dept_filter !== '' && isset($employee_columns['department'])) {
    $safe_dept = esc($conn, $dept_filter);
    $where[] = "department='$safe_dept'";
}

if ($desig_filter !== '' && isset($employee_columns['designation'])) {
    $safe_desig = esc($conn, $desig_filter);
    $where[] = "designation='$safe_desig'";
}

if ($nation_filter !== '' && isset($employee_columns['nationality'])) {
    $safe_nation = esc($conn, $nation_filter);
    $where[] = "nationality='$safe_nation'";
}

$where_sql = implode(' AND ', $where);
$result = mysqli_query($conn, "
    SELECT *
    FROM employees
    WHERE $where_sql
    ORDER BY CAST(user_no AS UNSIGNED) ASC, user_no ASC
");

// --- Filtered counts (based on current WHERE filters, excluding status filter) ---
$where_no_status = ["1=1"];
if ($name !== '') { $safe_name2 = esc($conn, $name); $where_no_status[] = "full_name LIKE '%$safe_name2%'"; }
if ($user_no !== '') { $safe_uno2 = esc($conn, $user_no); $where_no_status[] = "user_no='$safe_uno2'"; }
if ($dept_filter !== '' && isset($employee_columns['department'])) { $safe_dept2 = esc($conn, $dept_filter); $where_no_status[] = "department='$safe_dept2'"; }
if ($desig_filter !== '' && isset($employee_columns['designation'])) { $safe_desig2 = esc($conn, $desig_filter); $where_no_status[] = "designation='$safe_desig2'"; }
if ($nation_filter !== '' && isset($employee_columns['nationality'])) { $safe_nation2 = esc($conn, $nation_filter); $where_no_status[] = "nationality='$safe_nation2'"; }
$where_no_status_sql = implode(' AND ', $where_no_status);

$status_count_select = [
    isset($employee_columns['employee_status']) ? "employee_status" : "'' AS employee_status",
    isset($employee_columns['status']) ? "status" : "'' AS status",
    isset($employee_columns['resign_date']) ? "resign_date" : "'' AS resign_date",
];
$status_count_sql = implode(', ', $status_count_select);

$fcount_active = 0; $fcount_inactive = 0; $fcount_resigned = 0;
$fcount_res = mysqli_query($conn, "SELECT $status_count_sql FROM employees WHERE $where_no_status_sql");
if ($fcount_res) {
    while ($cr = mysqli_fetch_assoc($fcount_res)) {
        $cs = isset($cr['employee_status']) && $cr['employee_status'] !== '' ? $cr['employee_status'] : (isset($cr['status']) ? $cr['status'] : '');
        $cs = $cs !== '' ? $cs : 'Active';
        $rd_val = $cr['resign_date'] ?? '';
        $has_past_resign = $rd_val !== '' && $rd_val !== '0000-00-00' && strtotime($rd_val) !== false && strtotime($rd_val) <= strtotime(date('Y-m-d'));
        if (in_array(strtolower($cs), ['resign','resigned']) || $has_past_resign) { $fcount_resigned++; }
        elseif (strtolower($cs) === 'inactive') { $fcount_inactive++; }
        else { $fcount_active++; }
    }
}

// --- Status counts (total, no extra filters) ---
$resigned_condition = "";
if (isset($employee_columns['employee_status'])) {
    $resigned_condition = "employee_status IN ('Resigned','Resign')";
} elseif (isset($employee_columns['status'])) {
    $resigned_condition = "status IN ('Resigned','Resign')";
}
if (isset($employee_columns['resign_date'])) {
    $rd = "(resign_date IS NOT NULL AND resign_date!='' AND resign_date!='0000-00-00' AND resign_date<=CURDATE())";
    $resigned_condition = $resigned_condition ? "($resigned_condition OR $rd)" : $rd;
}

$count_active = 0; $count_inactive = 0; $count_resigned = 0;
$count_res = mysqli_query($conn, "SELECT $status_count_sql FROM employees");
if ($count_res) {
    while ($cr = mysqli_fetch_assoc($count_res)) {
        $cs = isset($cr['employee_status']) && $cr['employee_status'] !== '' ? $cr['employee_status'] : (isset($cr['status']) ? $cr['status'] : '');
        $cs = $cs !== '' ? $cs : 'Active';
        $rd_val = $cr['resign_date'] ?? '';
        $has_past_resign = $rd_val !== '' && $rd_val !== '0000-00-00' && strtotime($rd_val) !== false && strtotime($rd_val) <= strtotime(date('Y-m-d'));
        if (in_array(strtolower($cs), ['resign','resigned']) || $has_past_resign) {
            $count_resigned++;
        } elseif (strtolower($cs) === 'inactive') {
            $count_inactive++;
        } else {
            $count_active++;
        }
    }
}

// --- Distinct values for Department, Designation, Nationality dropdowns ---
$departments = [];
if (isset($employee_columns['department'])) {
    $dr = mysqli_query($conn, "SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department!='' ORDER BY department ASC");
    if ($dr) while ($row = mysqli_fetch_row($dr)) $departments[] = $row[0];
}
$designations = [];
if (isset($employee_columns['designation'])) {
    $dr = mysqli_query($conn, "SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation!='' ORDER BY designation ASC");
    if ($dr) while ($row = mysqli_fetch_row($dr)) $designations[] = $row[0];
}
$nationalities = [];
if (isset($employee_columns['nationality'])) {
    $dr = mysqli_query($conn, "SELECT DISTINCT nationality FROM employees WHERE nationality IS NOT NULL AND nationality!='' ORDER BY nationality ASC");
    if ($dr) while ($row = mysqli_fetch_row($dr)) $nationalities[] = $row[0];
}

$today = date('Y-m-d');
$visa_expire_result = visa_alert_query($conn);
$visa_expire_count = $visa_expire_result ? mysqli_num_rows($visa_expire_result) : 0;

$fields = [
    ['User No.', ['user_no']],
    ['ID On Device', ['employee_id', 'bio_met_no', 'bio_met._no', 'card_no']],
    ['Card No.', ['card_no']],
    ['Full Name', ['full_name']],
    ['Gender', ['gender']],
    ['Department', ['department']],
    ['Employee Status', ['employee_status', 'status']],
    ['Designation', ['designation']],
    ['Birthday', ['birthday']],
    ['Start Date', ['joining_date', 'start_date']],
    ['Phone Number (UAE)', ['phone', 'phone_number_uae', 'phone_number_(UAE)']],
    ['Phone Number (Home Country)', ['phone_home', 'home_phone', 'phone_home_country', 'phone_number_home_country', 'phone_number_(Home Country)', 'phone_won_country', 'phone_number_won_country', 'phone_number_(Won Country)']],
    ['Address', ['address']],
    ['Device', ['device']],
    ['Day Shift', ['day_shift']],
    ['Passport Number', ['passport', 'passport_number']],
    ['Passport Issue Date', ['passport_issue', 'passport_issuing', 'passport_issuing_date']],
    ['Passport Expiry Date', ['passport_expiry', 'passport_Expire', 'passport_expire_date']],
    ['Nationality', ['nationality']],
    ['Visa ID Number', ['visa_id_number', 'emirates_id_number']],
    ['UID Number', ['uid_number']],
    ['Visa Issuing Date', ['visa_issuing_date']],
    ['Visa Expiry Date', ['visa_expiry_date']],
    ['Insurance Number', ['insurance_number']],
    ['Insurance Issuing Date', ['insurance_issuing_date']],
    ['Insurance Expire Date', ['insurance_expiry_date']],
    ['Email', ['email']],
    ['Previous Company', ['previous_company', 'previous_company_name', 'previous_company_name_&_country(optional)']],
    ['Resign Date', ['resign_date']],
];

if ($is_excel) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=employee_list_" . date('Y_m_d') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Employee List</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'DM Sans',sans-serif;
    background:#eef1f6;
    padding:0;
    min-height:100vh;
}
.page-wrapper{
    padding:28px 32px;
    max-width:100%;
}
.header-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:18px;
}
.page-title{
    font-size:22px;
    font-weight:600;
    color:#1a2233;
    letter-spacing:-0.3px;
}
.top-btn{
    margin-bottom:14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    flex-wrap:wrap;
}
.top-btn-left{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.top-btn-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.btn{
    background:#2563eb;
    color:white;
    padding:9px 18px;
    text-decoration:none;
    border-radius:7px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:none;
    cursor:pointer;
    font-family:'DM Sans',sans-serif;
    font-size:13.5px;
    font-weight:500;
    transition:background 0.15s,transform 0.1s;
}
.btn:hover{background:#1d4ed8;transform:translateY(-1px);}
.btn:active{transform:translateY(0);}
.dashboard{background:#1e293b;}
.dashboard:hover{background:#0f172a;}
.delete{background:#dc2626;}
.delete:hover{background:#b91c1c;}
.edit{background:#ea580c;}
.edit:hover{background:#c2410c;}
.salary{background:#16a34a;}
.salary:hover{background:#15803d;}
.search-box{
    background:white;
    padding:14px 20px;
    margin-bottom:12px;
    border-radius:10px;
    border:1px solid #e2e8f0;
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    font-size:13.5px;
    color:#475569;
}
.search-row{display:contents;}
.search-pills-row{display:none;}
.pills-label{display:none;}
input,select{
    padding:8px 12px;
    border:1px solid #cbd5e1;
    border-radius:6px;
    font-family:'DM Sans',sans-serif;
    font-size:13.5px;
    color:#1e293b;
    background:white;
    outline:none;
    transition:border-color 0.15s;
}
input:focus,select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.1);}
.reset-link{
    color:#2563eb;
    text-decoration:none;
    font-size:13px;
    font-weight:500;
}
.reset-link:hover{text-decoration:underline;}
.table-wrapper{
    margin-top:12px;
    border-radius:12px;
    border:1px solid #e2e8f0;
    background:white;
    overflow:hidden;
}
.top-scroll{
    width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    background:#f8fafc;
    height:14px;
    border-bottom:1px solid #e2e8f0;
}
.top-scroll-inner{
    width:100%;
    height:1px;
}
.table-box{
    overflow-x:auto;
    max-height:calc(100vh - 280px);
    overflow-y:auto;
}
table{
    width:max-content;
    min-width:100%;
    border-collapse:collapse;
}
thead{
    position:sticky;
    top:0;
    z-index:10;
}
th{
    background:#1e293b;
    color:#e2e8f0;
    padding:13px 15px;
    text-align:left;
    white-space:nowrap;
    font-size:12px;
    font-weight:600;
    letter-spacing:0.5px;
    text-transform:uppercase;
    border-bottom:2px solid #334155;
    position:sticky;
    top:0;
}
th:first-child{border-left:none;}
td{
    padding:11px 15px;
    border-bottom:1px solid #f1f5f9;
    text-align:left;
    vertical-align:middle;
    white-space:nowrap;
    font-size:13.5px;
    color:#334155;
}
tbody tr:hover td{background:#f8fafc;}
tbody tr:last-child td{border-bottom:none;}
.status-active{
    background:#dcfce7;
    color:#15803d;
    padding:4px 11px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    letter-spacing:0.2px;
    display:inline-block;
}
.status-inactive{
    background:#fee2e2;
    color:#b91c1c;
    padding:4px 11px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    display:inline-block;
}
.status-resigned{
    background:#fef3c7;
    color:#92400e;
    padding:4px 11px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    display:inline-block;
}
.visa-alert-btn{
    background:#dc2626;
    color:white;
    padding:9px 18px;
    border-radius:7px;
    font-weight:600;
    font-size:13.5px;
    cursor:pointer;
    border:none;
    font-family:'DM Sans',sans-serif;
    display:inline-flex;
    align-items:center;
    gap:7px;
    animation:pulse 2s infinite;
}
@keyframes pulse{
    0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,0.35);}
    50%{box-shadow:0 0 0 6px rgba(220,38,38,0);}
}
.visa-warning{
    background:#fef2f2 !important;
    color:#dc2626 !important;
    font-weight:600;
}
.action-col{
    white-space:nowrap;
}
.action-col .btn{
    padding:6px 12px;
    font-size:12px;
    border-radius:5px;
}
.modal{
    display:none;
    position:fixed;
    z-index:9999;
    left:0;
    top:0;
    width:100%;
    height:100%;
    background:rgba(15,23,42,0.6);
    backdrop-filter:blur(3px);
}
.modal-content{
    background:white;
    width:90%;
    max-width:1000px;
    max-height:80vh;
    overflow:auto;
    margin:50px auto;
    padding:28px;
    border-radius:14px;
    box-shadow:0 25px 60px rgba(0,0,0,0.2);
}
.modal-title{
    font-size:18px;
    font-weight:600;
    color:#1e293b;
    margin-bottom:18px;
    padding-bottom:14px;
    border-bottom:1px solid #e2e8f0;
}
.close{
    float:right;
    font-size:24px;
    font-weight:600;
    color:#94a3b8;
    cursor:pointer;
    line-height:1;
    transition:color 0.15s;
}
.close:hover{color:#dc2626;}
.popup-table{
    width:100%;
    border-collapse:collapse;
    font-size:13.5px;
}
.popup-table th{
    background:#1e293b;
    color:#e2e8f0;
    padding:11px 13px;
    text-align:left;
    font-size:11.5px;
    letter-spacing:0.4px;
    text-transform:uppercase;
    font-weight:600;
}
.popup-table td{
    border-bottom:1px solid #f1f5f9;
    padding:11px 13px;
    color:#334155;
    text-align:left;
}
.popup-table tbody tr:hover td{background:#f8fafc;}
.row-num{
    color:#94a3b8;
    font-size:12px;
    font-family:'DM Mono',monospace;
}
.user-no-cell{
    font-family:'DM Mono',monospace;
    font-size:13px;
    color:#2563eb;
    font-weight:500;
}
.no-record{
    text-align:center;
    padding:48px 20px;
    color:#94a3b8;
    font-size:14px;
}
.stat-cards{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    align-items:center;
}
.stat-card{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 14px;
    border-radius:20px;
    border:1.5px solid #e2e8f0;
    background:white;
    cursor:pointer;
    text-decoration:none;
    font-family:'DM Sans',sans-serif;
    transition:all 0.15s;
    white-space:nowrap;
}
.stat-card:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(0,0,0,0.08);}
.stat-card.active-card{border-color:#bbf7d0;background:#f0fdf4;}
.stat-card.inactive-card{border-color:#fecaca;background:#fef2f2;}
.stat-card.resigned-card{border-color:#fde68a;background:#fffbeb;}
.stat-card.selected{box-shadow:0 0 0 3px rgba(37,99,235,0.15);border-color:#2563eb !important;}
.stat-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.stat-dot.gray{background:#94a3b8;}
.stat-dot.green{background:#16a34a;}
.stat-dot.red{background:#dc2626;}
.stat-dot.amber{background:#d97706;}
.stat-card-label{font-size:12.5px;color:#64748b;font-weight:500;}
.stat-card-num{font-size:13px;font-weight:700;margin-left:2px;}
<?php if ($is_excel) { ?>
body{background:white;padding:0;}
.header-row,.top-btn,.search-box,.top-scroll,.visa-alert-btn,.action-col{display:none;}
table{width:100%;}
<?php } ?>
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="page-wrapper">

<div class="header-row">
    <h2 class="page-title">&#128101; Employee List</h2>
    <button class="visa-alert-btn" onclick="openVisaPopup()">
        &#9888; Visa Expiring Soon: <?php echo $visa_expire_count; ?>
    </button>
</div>

<div class="top-btn">
    <div class="top-btn-left">
        <a href="dashboard.php" class="btn dashboard">&#9776; Dashboard</a>
        <a href="add_employee.php" class="btn">&#43; Add Employee</a>
        <a href="employee_list.php?<?php echo http_build_query(['name'=>$name, 'user_no'=>$user_no, 'status'=>$status_filter, 'department'=>$dept_filter, 'designation'=>$desig_filter, 'nationality'=>$nation_filter, 'export'=>'excel']); ?>" class="btn">&#8659; Download Excel</a>
        <a href="employee_import_template.php" class="btn">&#8659; Export / Import Template</a>
    </div>
    <?php
    function build_filter_url($extra = []) {
        global $name, $user_no, $dept_filter, $desig_filter, $nation_filter, $status_filter;
        $params = ['name'=>$name,'user_no'=>$user_no,'department'=>$dept_filter,'designation'=>$desig_filter,'nationality'=>$nation_filter,'status'=>$status_filter];
        foreach ($extra as $k=>$v) $params[$k] = $v;
        return 'employee_list.php?' . http_build_query($params);
    }
    ?>
    <div class="top-btn-right">
        <div class="stat-cards">
            <a href="<?php echo build_filter_url(['status'=>'']); ?>" class="stat-card <?php echo $status_filter==='' ? 'selected' : ''; ?>">
                <span class="stat-dot gray"></span>
                <span class="stat-card-label">Total</span>
                <span class="stat-card-num"><?php echo $fcount_active + $fcount_inactive + $fcount_resigned; ?></span>
            </a>
            <a href="<?php echo build_filter_url(['status'=>'Active']); ?>" class="stat-card active-card <?php echo $status_filter==='Active' ? 'selected' : ''; ?>">
                <span class="stat-dot green"></span>
                <span class="stat-card-label">Active</span>
                <span class="stat-card-num" style="color:#16a34a;"><?php echo $fcount_active; ?></span>
            </a>
            <a href="<?php echo build_filter_url(['status'=>'Inactive']); ?>" class="stat-card inactive-card <?php echo $status_filter==='Inactive' ? 'selected' : ''; ?>">
                <span class="stat-dot red"></span>
                <span class="stat-card-label">Inactive</span>
                <span class="stat-card-num" style="color:#dc2626;"><?php echo $fcount_inactive; ?></span>
            </a>
            <a href="<?php echo build_filter_url(['status'=>'Resigned']); ?>" class="stat-card resigned-card <?php echo $status_filter==='Resigned' ? 'selected' : ''; ?>">
                <span class="stat-dot amber"></span>
                <span class="stat-card-label">Resigned</span>
                <span class="stat-card-num" style="color:#d97706;"><?php echo $fcount_resigned; ?></span>
            </a>
        </div>
    </div>
</div>

<form method="GET" class="search-box">
    <div class="search-row">
        <label>Name:</label>
        <input type="text" name="name" placeholder="Employee Name" value="<?php echo h($name); ?>">
        <label>User No:</label>
        <input type="text" name="user_no" placeholder="User No." value="<?php echo h($user_no); ?>">
        <label>Status:</label>
        <select name="status">
            <option value="">All Status</option>
            <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
            <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
            <option value="Resigned" <?php echo $status_filter === 'Resigned' ? 'selected' : ''; ?>>Resigned</option>
        </select>
        <?php if (!empty($departments)): ?>
        <label>Dept:</label>
        <select name="department">
            <option value="">All Dept</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?php echo h($d); ?>" <?php echo $dept_filter === $d ? 'selected' : ''; ?>><?php echo h($d); ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="hidden" name="department" value="">
        <?php endif; ?>
        <?php if (!empty($designations)): ?>
        <label>Designation:</label>
        <select name="designation">
            <option value="">All</option>
            <?php foreach ($designations as $d): ?>
            <option value="<?php echo h($d); ?>" <?php echo $desig_filter === $d ? 'selected' : ''; ?>><?php echo h($d); ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="hidden" name="designation" value="">
        <?php endif; ?>
        <?php if (!empty($nationalities)): ?>
        <label>Nationality:</label>
        <select name="nationality">
            <option value="">All</option>
            <?php foreach ($nationalities as $n): ?>
            <option value="<?php echo h($n); ?>" <?php echo $nation_filter === $n ? 'selected' : ''; ?>><?php echo h($n); ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="hidden" name="nationality" value="">
        <?php endif; ?>
        <button type="submit" class="btn" style="margin:0;">&#128269; Search</button>
        <a href="employee_list.php" class="reset-link">&#10006; Reset</a>
    </div>
</form>

<div class="table-wrapper">
<div class="top-scroll" id="topScroll">
    <div class="top-scroll-inner"></div>
</div>

<div class="table-box" id="tableBox">
<table>
<thead>
<tr>
    <?php foreach ($fields as $field) { ?>
        <th><?php echo h($field[0]); ?></th>
    <?php } ?>
    <th class="action-col">Action</th>
</tr>
</thead>
<tbody>

<?php if ($result && mysqli_num_rows($result) > 0) { ?>
    <?php while($row = mysqli_fetch_assoc($result)) {
        $status = value_from($row, ['employee_status', 'status']);
        $status = $status !== '' ? $status : 'Active';
        $resign_date_value = value_from($row, ['resign_date']);
        $has_valid_past_resign_date = $resign_date_value !== ''
            && $resign_date_value !== '0000-00-00'
            && strtotime($resign_date_value) !== false
            && strtotime($resign_date_value) <= strtotime($today);
        if (in_array(strtolower($status), ['resign', 'resigned'], true) || $has_valid_past_resign_date) {
            $status = 'Resigned';
        }
        $visa_expiry = value_from($row, ['visa_expiry_date']);
        $is_visa_warning = $visa_expiry !== '' && $visa_expiry !== '0000-00-00' && $visa_expiry <= visa_alert_window_date();
        $edit_user_no = value_from($row, ['user_no']);
    ?>
    <tr>
        <?php foreach ($fields as $field) {
            $label = $field[0];
            $value = value_from($row, $field[1]);
            if (is_date_field_label($label)) {
                $value = display_date_dmy($value);
            }
            $cell_class = '';
            if ($label === 'Visa Expiry Date' && $is_visa_warning) {
                $cell_class = 'visa-warning';
            }
        ?>
            <td class="<?php echo $cell_class; ?>">
                <?php if ($label === 'Employee Status') { ?>
                    <?php if (strtolower($status) === 'active') { ?>
                        <span class="status-active">Active</span>
                    <?php } elseif (in_array(strtolower($status), ['resign', 'resigned'], true)) { ?>
                        <span class="status-resigned">Resigned</span>
                    <?php } else { ?>
                        <span class="status-inactive"><?php echo h($status); ?></span>
                    <?php } ?>
                <?php } else { ?>
                    <?php echo h($value); ?>
                <?php } ?>
            </td>
        <?php } ?>
        <td class="action-col">
            <a class="btn edit" href="add_employee.php?search_user_no=<?php echo urlencode($edit_user_no); ?>">Edit</a>
            <a class="btn salary" href="employee_salary.php?user_no=<?php echo urlencode($edit_user_no); ?>">Salary</a>
            <a class="btn delete"
               href="delete_employee.php?id=<?php echo urlencode($row['id'] ?? ''); ?>"
               onclick="return confirm('Delete this employee?')">Delete</a>
        </td>
    </tr>
    <?php } ?>
<?php } else { ?>
    <tr>
        <td colspan="<?php echo count($fields) + 1; ?>" class="no-record">
            &#128204; No employee found.
        </td>
    </tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>

<div id="visaModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeVisaPopup()">&times;</span>
        <h2 class="modal-title">&#9888; Visa Expiring Within 2 Months</h2>
        <table class="popup-table">
            <tr>
                <th>SL</th>
                <th>User No</th>
                <th>ID On Device</th>
                <th>Name</th>
                <th>Department</th>
                <th>Phone</th>
                <th>Visa ID</th>
                <th>Visa Expiry Date</th>
                <th>Remaining Days</th>
            </tr>
            <?php
            $sl = 1;
            if($visa_expire_result && $visa_expire_count > 0){
                mysqli_data_seek($visa_expire_result, 0);
                while($v = mysqli_fetch_assoc($visa_expire_result)){
                    $remaining_days = floor((strtotime($v['visa_expiry_date']) - strtotime($today)) / (60*60*24));
            ?>
            <tr>
                <td><?php echo $sl++; ?></td>
                <td><?php echo h(value_from($v, ['user_no'])); ?></td>
                <td><?php echo h(value_from($v, ['employee_id', 'bio_met_no', 'card_no'])); ?></td>
                <td><?php echo h(value_from($v, ['full_name'])); ?></td>
                <td><?php echo h(value_from($v, ['department'])); ?></td>
                <td><?php echo h(value_from($v, ['phone', 'phone_number_uae'])); ?></td>
                <td><?php echo h(value_from($v, ['visa_id_number', 'emirates_id_number'])); ?></td>
                <td class="visa-warning"><?php echo h(display_date_dmy(value_from($v, ['visa_expiry_date']))); ?></td>
                <td><?php if ($remaining_days < 0): ?>Expired <?php echo abs($remaining_days); ?>d ago<?php else: ?><?php echo $remaining_days; ?> Days<?php endif; ?></td>
            </tr>
            <?php } } else { ?>
            <tr>
                <td colspan="9" style="text-align:center;color:#16a34a;font-weight:600;padding:24px;">
                    &#10003; No visa alerts (expired or expiring soon).
                </td>
            </tr>
            <?php } ?>
        </table>
    </div>
</div>

<script>
const topScroll = document.getElementById('topScroll');
const tableBox = document.getElementById('tableBox');
const topScrollInner = document.querySelector('.top-scroll-inner');

if (topScroll && tableBox) {
    function syncTopScrollbarWidth() {
        if (!topScrollInner) return;
        topScrollInner.style.width = tableBox.scrollWidth + 'px';
    }

    syncTopScrollbarWidth();
    window.addEventListener('resize', syncTopScrollbarWidth);

    topScroll.addEventListener('scroll', function () {
        tableBox.scrollLeft = topScroll.scrollLeft;
    });

    tableBox.addEventListener('scroll', function () {
        topScroll.scrollLeft = tableBox.scrollLeft;
    });
}

function openVisaPopup(){
    document.getElementById('visaModal').style.display = 'block';
}

function closeVisaPopup(){
    document.getElementById('visaModal').style.display = 'none';
}

window.onclick = function(event){
    let modal = document.getElementById('visaModal');
    if(event.target == modal){
        modal.style.display = 'none';
    }
}
</script>
</div><!-- /.page-wrapper -->
</body>
</html>
