<?php
/* ─────────────────────────────────────────────
   Shared navigation side panel.

   Include this once, right after <body>, on any page that should show the
   full menu (the same one as the Dashboard). It is self-contained: it ships
   its own scoped styles (prefixed .appnav) and shifts page content right, so
   it does not require any change to the host page's own CSS.

   Requires auth.php to have been included already (for hasPermission()).
───────────────────────────────────────────── */
if (!function_exists('appnav_active')) {
    function appnav_active($file) {
        $current = basename($_SERVER['PHP_SELF'] ?? '');
        return $file === $current ? 'active' : '';
    }
}
$appnav_current = basename($_SERVER['PHP_SELF'] ?? '');
$appnav_logo = function_exists('company_logo_img')
    ? company_logo_img(26, 'background:#fff;border-radius:5px;padding:2px;margin-right:8px;')
    : '';
?>
<style>
/* ── Shared side panel (scoped) ── */
body { padding-left: 250px; }
.appnav {
    position: fixed; top: 0; left: 0; width: 250px; height: 100vh; z-index: 1200;
    background: #1a2533; color: #fff; padding: 18px 14px; overflow-y: auto;
    display: flex; flex-direction: column; gap: 3px;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 14px;
}
.appnav * { box-sizing: border-box; }
.appnav .appnav-brand {
    display: flex; align-items: center; font-weight: 700; font-size: 14px;
    padding: 4px 6px 14px; border-bottom: 1px solid rgba(255,255,255,0.12); margin-bottom: 8px; line-height: 1.2;
}
.appnav-title {
    padding: 11px 12px; border-radius: 7px; cursor: pointer; font-weight: 600;
    display: flex; align-items: center; justify-content: space-between; color: #e7ecf3; text-decoration: none;
}
.appnav-title:hover, .appnav-title.active { background: #e8a020; color: #1a1a1a; }
.appnav-title .appnav-caret { font-size: 10px; transition: transform .2s; }
.appnav-sub { display: none; flex-direction: column; padding: 2px 0 6px 6px; }
.appnav-sub.open { display: flex; }
.appnav-sub a {
    color: #b8c2cf; text-decoration: none; padding: 9px 12px; border-radius: 6px; font-size: 13px;
    display: block;
}
.appnav-sub a:hover { background: rgba(255,255,255,0.08); color: #fff; }
.appnav-sub a.active { background: #2563a8; color: #fff; font-weight: 600; }
.appnav-credit {
    margin-top: auto; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.12);
    font-size: 11px; line-height: 1.5; color: rgba(255,255,255,0.55);
    display: flex; align-items: center; flex-wrap: wrap; gap: 3px;
}
.appnav-toggle {
    display: none; position: fixed; top: 10px; left: 10px; z-index: 1300;
    background: #1a2533; color: #fff; border: none; border-radius: 6px;
    width: 42px; height: 38px; font-size: 18px; cursor: pointer;
}
.appnav-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1150; }

@media (max-width: 860px) {
    body { padding-left: 0; }
    .appnav { transform: translateX(-100%); transition: transform .25s; }
    .appnav.open { transform: translateX(0); }
    .appnav-toggle { display: block; }
    .appnav-backdrop.open { display: block; }
}
@media print {
    body { padding-left: 0 !important; }
    .appnav, .appnav-toggle, .appnav-backdrop { display: none !important; }
}
</style>

<button class="appnav-toggle" onclick="appnavOpen()" aria-label="Menu">&#9776;</button>
<div class="appnav-backdrop" id="appnavBackdrop" onclick="appnavClose()"></div>

<aside class="appnav" id="appnav">
    <div class="appnav-brand"><?php echo $appnav_logo; ?> Payroll Management</div>

    <a href="dashboard.php" class="appnav-title <?php echo appnav_active('dashboard.php'); ?>">&#127968; Dashboard</a>

    <?php if (hasPermission('employee_view') || hasPermission('employee_add')): ?>
    <div class="appnav-title" onclick="appnavToggle('emp')">&#128100; Employees <span class="appnav-caret">&#9662;</span></div>
    <div class="appnav-sub" id="appnav-emp">
        <?php if (hasPermission('employee_view')): ?>
        <a href="employee_overview.php" class="<?php echo appnav_active('employee_overview.php'); ?>">&#128203; Employee Overview</a>
        <a href="employee_list.php" class="<?php echo appnav_active('employee_list.php'); ?>">&#128221; Employee Details</a>
        <?php endif; ?>
        <?php if (hasPermission('employee_add')): ?>
        <a href="add_employee.php" class="<?php echo appnav_active('add_employee.php'); ?>">&#43; Add Employee</a>
        <a href="employee_salary.php" class="<?php echo appnav_active('employee_salary.php'); ?>">&#128176; Salary Details</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('salary_view') || hasPermission('salary_slip_print') || hasPermission('attendance_report') || hasPermission('reports_view')): ?>
    <div class="appnav-title" onclick="appnavToggle('report')">&#128202; Reports <span class="appnav-caret">&#9662;</span></div>
    <div class="appnav-sub" id="appnav-report">
        <?php if (hasPermission('salary_view')): ?>
        <a href="generate_salary.php" class="<?php echo appnav_active('generate_salary.php'); ?>">&#128181; Salary Sheet</a>
        <?php endif; ?>
        <?php if (hasPermission('salary_generate')): ?>
        <a href="advance_manage.php" class="<?php echo appnav_active('advance_manage.php'); ?>">&#128184; Advance Salary</a>
        <a href="wps_sif.php" class="<?php echo appnav_active('wps_sif.php'); ?>">&#127974; WPS / SIF File</a>
        <?php endif; ?>
        <?php if (hasPermission('salary_slip_print')): ?>
        <a href="salary_slip.php" class="<?php echo appnav_active('salary_slip.php'); ?>">&#129534; Salary Slip</a>
        <?php endif; ?>
        <?php if (hasPermission('attendance_report')): ?>
        <a href="attendance_report.php" class="<?php echo appnav_active('attendance_report.php'); ?>">&#128337; Attendance Report</a>
        <?php endif; ?>
        <?php if (hasPermission('reports_view')): ?>
        <a href="overtime_report.php" class="<?php echo appnav_active('overtime_report.php'); ?>">&#9203; Overtime Report</a>
        <a href="visa_expiring.php" class="<?php echo appnav_active('visa_expiring.php'); ?>">&#9888; Visa Expire</a>
        <a href="insurance_expiring.php" class="<?php echo appnav_active('insurance_expiring.php'); ?>">&#128737; Insurance Expire</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('vacation_manage')): ?>
    <div class="appnav-title" onclick="appnavToggle('vacation')">&#127965; Vacation / Absent <span class="appnav-caret">&#9662;</span></div>
    <div class="appnav-sub" id="appnav-vacation">
        <a href="add_vacation.php" class="<?php echo appnav_active('add_vacation.php'); ?>">&#43; Add Vacation</a>
        <a href="vacation_details.php" class="<?php echo appnav_active('vacation_details.php'); ?>">&#128197; Vacation Details</a>
        <a href="absent_details.php" class="<?php echo appnav_active('absent_details.php'); ?>">&#128197; Absent Details</a>
        <a href="holidays.php" class="<?php echo appnav_active('holidays.php'); ?>">&#127881; Holidays</a>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('uploads_manage') || hasPermission('attendance_upload')): ?>
    <div class="appnav-title" onclick="appnavToggle('upload')">&#128228; Uploads <span class="appnav-caret">&#9662;</span></div>
    <div class="appnav-sub" id="appnav-upload">
        <?php if (hasPermission('uploads_manage')): ?>
        <a href="dashboard.php#employee_upload">&#128100; Upload Employee Excel</a>
        <?php endif; ?>
        <?php if (hasPermission('attendance_upload')): ?>
        <a href="dashboard.php#attendance_upload">&#128337; Upload Attendance</a>
        <a href="auto_import_crosschex.php" class="<?php echo appnav_active('auto_import_crosschex.php'); ?>">&#128260; Sync CrossChex</a>
        <a href="ot_upload.php" class="<?php echo appnav_active('ot_upload.php'); ?>">&#9203; OT Upload</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('user_manage')): ?>
    <a href="manage_users.php" class="appnav-title <?php echo appnav_active('manage_users.php'); ?>">&#9881; User Management</a>
    <?php endif; ?>

    <div class="appnav-credit">
        <?php echo function_exists('company_logo_img') ? company_logo_img(18, 'background:#fff;border-radius:4px;padding:2px;margin-right:5px;') : ''; ?>
        <span><?php echo defined('APP_CREDIT') ? htmlspecialchars(APP_CREDIT) : 'Payroll Developed by Euro Trousers'; ?></span>
    </div>
</aside>

<script>
function appnavToggle(id) {
    var el = document.getElementById('appnav-' + id);
    if (el) el.classList.toggle('open');
}
function appnavOpen()  { document.getElementById('appnav').classList.add('open'); document.getElementById('appnavBackdrop').classList.add('open'); }
function appnavClose() { document.getElementById('appnav').classList.remove('open'); document.getElementById('appnavBackdrop').classList.remove('open'); }
// Auto-open the group that contains the active page
(function () {
    var active = document.querySelector('.appnav-sub a.active');
    if (active && active.parentElement) active.parentElement.classList.add('open');
})();
</script>
