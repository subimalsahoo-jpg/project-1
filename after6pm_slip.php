<?php
include 'auth.php';
requireAnyPermission(['salary_slip_print', 'salary_view', 'reports_view']);
require_once 'after6pm_helper.php';

$month       = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$month_title = date('F Y', strtotime($month . '-01'));
$search      = trim($_GET['search'] ?? $_GET['user_no'] ?? '');
$view        = isset($_GET['search_btn']) || isset($_GET['print']);
$auto_print  = isset($_GET['print']);

$employees = [];
if ($view && $search !== '') {
    $s = a6_esc($conn, $search);
    $q = mysqli_query($conn, "SELECT user_no, employee_id, full_name, designation, basic_salary, fixed_salary
        FROM employees
        WHERE user_no='$s' OR employee_id='$s' OR full_name LIKE '%$s%'
        ORDER BY CAST(user_no AS UNSIGNED), user_no");
    if ($q) { while ($r = mysqli_fetch_assoc($q)) { $employees[] = $r; } }
}

function a6_words($number) {
    $number = (int)round($number);
    $w = [0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',7=>'Seven',8=>'Eight',9=>'Nine',10=>'Ten',
        11=>'Eleven',12=>'Twelve',13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',17=>'Seventeen',18=>'Eighteen',
        19=>'Nineteen',20=>'Twenty',30=>'Thirty',40=>'Forty',50=>'Fifty',60=>'Sixty',70=>'Seventy',80=>'Eighty',90=>'Ninety'];
    if ($number == 0) return 'Zero';
    if ($number < 21) return $w[$number];
    if ($number < 100) return trim($w[10 * floor($number/10)] . ' ' . $w[$number % 10]);
    if ($number < 1000) return trim($w[floor($number/100)] . ' Hundred ' . a6_words($number % 100));
    if ($number < 100000) return trim(a6_words(floor($number/1000)) . ' Thousand ' . a6_words($number % 1000));
    return (string)$number;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>After 6pm Duty Slip — <?php echo htmlspecialchars($month_title); ?></title>
<style>
body{font-family:Arial;background:#f4f6f9;padding:20px;}
.search-box{text-align:center;margin-bottom:20px;}
.btn{background:#2563a8;color:#fff;padding:9px 16px;text-decoration:none;border:none;border-radius:5px;cursor:pointer;font-size:14px;}
.btn.dark{background:#1a3a5c;}
input{padding:9px;margin:4px;border:1px solid #cbd5e1;border-radius:5px;}
.print-wrapper{width:900px;margin:auto;display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.slip{background:#fff;border:1px solid #cbd5e1;border-radius:6px;padding:18px;}
.company{text-align:center;margin-bottom:10px;}
.company h2{font-size:17px;color:#1a3a5c;margin:4px 0 2px;}
.company p{font-size:12px;color:#475569;}
.title{text-align:center;font-weight:700;background:#1a3a5c;color:#fff;padding:7px;border-radius:5px;margin:8px 0 12px;font-size:14px;}
.info-table{width:100%;font-size:12.5px;margin-bottom:10px;}
.info-table td{padding:2px 4px;}
.sal-table{width:100%;border-collapse:collapse;font-size:13px;}
.sal-table th,.sal-table td{border:1px solid #333;padding:6px 8px;}
.sal-table th{background:#eef2f8;}
.right{text-align:right;}
.bold td{font-weight:800;}
.sign{display:flex;justify-content:space-between;margin-top:34px;font-size:12.5px;}
.sign-line{margin-top:30px;border-top:1px solid #333;width:150px;}
.system{text-align:center;color:#94a3b8;font-size:11px;margin-top:14px;}
@media print{
    .search-box{display:none!important;}
    body{background:#fff;padding:0;}
    .print-wrapper{width:auto;gap:6mm;}
    .slip{border:none;page-break-inside:avoid;}
    @page{size:A4 landscape;margin:6mm;}
}
</style>
</head>
<body>
<?php if (!$auto_print) include 'nav_sidebar.php'; ?>

<div class="search-box">
    <a href="dashboard.php" class="btn dark">Dashboard</a>
    <a href="after6pm_duty.php?month=<?php echo urlencode($month); ?>" class="btn dark">After 6pm Duty</a>
    <form method="GET" style="display:inline-block;">
        <input type="text" name="search" placeholder="User No / Employee ID / Name" value="<?php echo htmlspecialchars($search); ?>">
        <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" required>
        <button type="submit" name="search_btn" class="btn">Search</button>
        <a class="btn dark" href="after6pm_slip.php">Clear</a>
    </form>
    <button type="button" class="btn" onclick="window.print()">Print</button>
</div>

<?php if (!empty($employees)): ?>
<div class="print-wrapper">
    <?php foreach ($employees as $emp):
        if ((float)($emp['fixed_salary'] ?? 0) > 0) continue; // Fixed salary = no OT
        $b = a6_breakdown($conn, $emp['user_no'], $emp['employee_id'] ?? '', (float)($emp['basic_salary'] ?? 0), $month);
        if ($b['after6pm_hours'] <= 0 && $b['sunday_hours'] <= 0) continue;
    ?>
    <div class="slip">
        <div class="company">
            <?php echo function_exists('company_logo_img') ? company_logo_img(52) : ''; ?>
            <h2>Euro Trousers MFG. Co. FZC</h2>
            <p>J001-02, Saif Zone, Sharjah, UAE</p>
        </div>
        <div class="title">After 6pm Duty Pay Slip &mdash; <?php echo htmlspecialchars($month_title); ?></div>
        <table class="info-table">
            <tr><td>Employee Name</td><td>: <?php echo htmlspecialchars($emp['full_name']); ?></td></tr>
            <tr><td>User No / ID</td><td>: <?php echo htmlspecialchars($emp['user_no']); ?></td></tr>
            <tr><td>Designation</td><td>: <?php echo htmlspecialchars($emp['designation'] ?? ''); ?></td></tr>
        </table>
        <table class="sal-table">
            <tr><th>Description</th><th class="right">Hours</th><th class="right">Amount (AED)</th></tr>
            <tr>
                <td>After 6pm OT (1.25&times;)</td>
                <td class="right"><?php echo number_format($b['after6pm_hours'], 2); ?></td>
                <td class="right"><?php echo a6_money($b['after6pm_amount']); ?></td>
            </tr>
            <tr>
                <td>Sunday OT (1.5&times;)</td>
                <td class="right"><?php echo number_format($b['sunday_hours'], 2); ?></td>
                <td class="right"><?php echo a6_money($b['sunday_amount']); ?></td>
            </tr>
            <tr class="bold">
                <td>Total Payable</td>
                <td class="right"><?php echo number_format($b['after6pm_hours'] + $b['sunday_hours'], 2); ?></td>
                <td class="right"><?php echo a6_money($b['total']); ?></td>
            </tr>
        </table>
        <div style="text-align:right;font-weight:bold;margin-top:6px;font-size:12px;">
            In word : <?php echo a6_words($b['total']); ?> AED Only
        </div>
        <div class="sign">
            <div>Employee Signature<div class="sign-line"></div></div>
            <div>HR Signature<div class="sign-line"></div></div>
        </div>
        <div class="system">This is a system generated slip &mdash; separate from the main salary.</div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif ($view): ?>
<h3 style="text-align:center;color:#b91c1c;">No after-6pm / Sunday OT found for this employee in <?php echo htmlspecialchars($month_title); ?>.</h3>
<?php endif; ?>

<?php if ($auto_print && !empty($employees)): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
</body>
</html>
