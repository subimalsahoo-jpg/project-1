<?php
include 'auth.php';
requirePermission('salary_slip_print');

$employee = null;
$employees = [];
$month = normalize_input_month($_GET['month'] ?? date('Y-m'), date('Y-m'));
$search = $_GET['search'] ?? '';
$selected_users = $_GET['selected_users'] ?? [];

if (!is_array($selected_users)) {
    $selected_users = [$selected_users];
}

$selected_users = array_values(array_filter(array_map('trim', $selected_users)));
$show_list = isset($_GET['show_list']);
$selected_mode = isset($_GET['selected_mode']);
$view_slips = isset($_GET['search_btn']) || isset($_GET['print_slips']);

if($view_slips){

    $search_safe = mysqli_real_escape_string($conn, $search);
    $month_safe  = mysqli_real_escape_string($conn, $month);
    $where = "s.salary_month='$month_safe'
        AND (s.salary_status IS NULL OR s.salary_status='' OR s.salary_status!='Unpaid')";

    if ($selected_mode && empty($selected_users)) {
        $where .= " AND 1=0";
    } elseif (!empty($selected_users)) {
        $safe_users = array_map(function($user) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $user) . "'";
        }, $selected_users);

        $where .= " AND s.user_no IN (" . implode(',', $safe_users) . ")";
    } elseif (isset($_GET['search_btn']) && $search_safe === '') {
        $where .= " AND 1=0";
    } elseif ($search_safe !== '') {
        $where .= "
        AND (
            s.user_no='$search_safe'
            OR e.employee_id='$search_safe'
            OR e.full_name LIKE '%$search_safe%'
        )";
    }

    $query = "
    SELECT e.full_name, e.user_no, e.designation, s.*
    FROM employee_salary_records s
    LEFT JOIN employees e ON s.user_no = e.user_no
    WHERE $where
    ORDER BY CAST(s.user_no AS UNSIGNED), s.user_no
    ";

    $result = mysqli_query($conn, $query);

    if($result && mysqli_num_rows($result) > 0){
        while($row = mysqli_fetch_assoc($result)){
            $employees[] = $row;
        }
        $employee = $employees[0] ?? null;
    }
}

$month_title = date("F Y", strtotime($month . "-01"));

$list_month_safe = mysqli_real_escape_string($conn, $month);
$slip_list_result = mysqli_query($conn, "
    SELECT s.user_no, e.full_name, s.net_payable, s.salary_status
    FROM employee_salary_records s
    LEFT JOIN employees e ON s.user_no = e.user_no
    WHERE s.salary_month='$list_month_safe'
    AND (s.salary_status IS NULL OR s.salary_status='' OR s.salary_status!='Unpaid')
    ORDER BY CAST(s.user_no AS UNSIGNED), s.user_no
");

function money($amount){
    return number_format((float)$amount, 0);
}

function numberToWords($number){
    $number = (int)$number;

    $words = array(
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight',
        9 => 'Nine', 10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve',
        13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty',
        70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    );

    if($number == 0){ return 'Zero'; }
    if($number < 21){ return $words[$number]; }

    if($number < 100){
        return trim($words[10 * floor($number / 10)] . ' ' . $words[$number % 10]);
    }

    if($number < 1000){
        return trim($words[floor($number / 100)] . ' Hundred ' . numberToWords($number % 100));
    }

    if($number < 100000){
        return trim(numberToWords(floor($number / 1000)) . ' Thousand ' . numberToWords($number % 1000));
    }

    return $number;
}

function payslipBlock(
    $employee,
    $month_title,
    $present_days,
    $absent_days,
    $ot_hours,
    $basic_salary,
    $allowance,
    $good_att_allowance,
    $ot_amount,
    $food_allowance,
    $salary_earned,
    $allowance_earned,
    $total_earnings,
    $after_deduction_earnings,
    $advance,
    $insurance,
    $other_deduction,
    $net_pay
){
?>

<div class="payslip">

    <div class="company">
        <?php echo company_logo_img(58, 'margin-bottom:6px;'); ?>
        <h2>Euro Trousers MFG. Co. FZC</h2>
        <p>J001-02, Saif Zone, Sharjah, UAE</p>
    </div>

    <div class="title">
        Pay Slip Month of <?php echo $month_title; ?>
    </div>

    <table class="info-table">
        <tr>
            <td>Employee name</td>
            <td>: <?php echo $employee['full_name']; ?></td>
            <td>Pay Period</td>
            <td>: <?php echo $month_title; ?></td>
        </tr>

        <tr>
            <td>User No/ID no.</td>
            <td>: <?php echo $employee['user_no']; ?></td>
            <td>Worked Days</td>
            <td>: <?php echo $present_days; ?></td>
        </tr>

        <tr>
            <td>Designation</td>
            <td>: <?php echo $employee['designation'] ?? ''; ?></td>
            <td>Absent Days</td>
            <td>: <?php echo $absent_days; ?></td>
        </tr>

        <tr class="ot-row">
            <td></td>
            <td></td>
            <td>OT</td>
            <td>: <?php echo $ot_hours; ?> Hrs</td>
        </tr>
    </table>

    <table class="salary-table">
        <tr>
            <th>Earnings</th>
            <th class="amount-head">Amount<br>(AED)</th>
            <th>After Deductions Earnings</th>
            <th class="amount-head">Amount<br>(AED)</th>
        </tr>

        <tr>
            <td>Basic Salary</td>
            <td class="right"><?php echo money($basic_salary); ?></td>
            <td>Salary Earned</td>
            <td class="right"><?php echo money($salary_earned); ?></td>
        </tr>

        <tr>
            <td>Allowance</td>
            <td class="right"><?php echo money($allowance); ?></td>
            <td>Allowance Earned</td>
            <td class="right"><?php echo money($allowance_earned); ?></td>
        </tr>

        <tr>
            <td>Good Att. Allowance</td>
            <td class="right"><?php echo money($good_att_allowance); ?></td>
            <td>Good Att. Allowance</td>
            <td class="right"><?php echo money($good_att_allowance); ?></td>
        </tr>

        <tr>
            <td>OT</td>
            <td class="right"><?php echo money($ot_amount); ?></td>
            <td>OT</td>
            <td class="right"><?php echo money($ot_amount); ?></td>
        </tr>

        <tr>
            <td>Food Allowance</td>
            <td class="right"><?php echo money($food_allowance); ?></td>
            <td>Food Allowance</td>
            <td class="right"><?php echo money($food_allowance); ?></td>
        </tr>

        <tr>
            <td>&nbsp;</td>
            <td></td>
            <td></td>
            <td></td>
        </tr>

        <tr class="bold">
            <td>Total Earnings</td>
            <td class="right"><?php echo money($total_earnings); ?></td>
            <td>After Deductions Earnings</td>
            <td class="right"><?php echo money($after_deduction_earnings); ?></td>
        </tr>

        <tr>
            <td class="no-border"></td>
            <td class="no-border"></td>
            <td class="bold">Deduction</td>
            <td></td>
        </tr>

        <tr>
            <td class="no-border"></td>
            <td class="no-border"></td>
            <td>Advance</td>
            <td class="right"><?php echo money($advance); ?></td>
        </tr>

        <tr>
            <td class="no-border"></td>
            <td class="no-border"></td>
            <td>Insurance</td>
            <td class="right"><?php echo money($insurance); ?></td>
        </tr>

        <tr>
            <td class="no-border"></td>
            <td class="no-border"></td>
            <td>Other Deduction</td>
            <td class="right"><?php echo money($other_deduction); ?></td>
        </tr>

        <tr class="bold">
            <td class="no-border"></td>
            <td class="no-border"></td>
            <td>Net Pay</td>
            <td class="right"><?php echo money($net_pay); ?></td>
        </tr>
    </table>

    <div style="text-align:right;font-weight:bold;margin-top:5px;font-size:12px;">
        In word : <?php echo numberToWords(round($net_pay)); ?> AED Only
    </div>

    <div class="signature">
        <div>
            Employer Signature
            <div class="sign-line"></div>
        </div>

        <div>
            HR Signature
            <div class="sign-line"></div>
        </div>
    </div>

    <div class="system">
        This is system generated payslip
    </div>

</div>

<?php } ?>

<?php
function renderPayslip($conn, $employee, $month, $month_title){
    $basic_salary = (float)($employee['basic_salary'] ?? 0);
    $allowance = (float)($employee['allowance'] ?? 0);
    $good_att_allowance = (float)($employee['att_allowance'] ?? 0);

    $regular_ot_hours = (float)($employee['regular_ot_hours'] ?? 0);
    $after6pm_hours = (float)($employee['ot'] ?? 0);
    $extra_ot_hours = (float)($employee['extra_ot_hours'] ?? 0);
    $ot_hours = $regular_ot_hours + $after6pm_hours + $extra_ot_hours;

    $food_allowance_company = (float)($employee['food_allowance_company'] ?? 0);
    $food_allowance_won = (float)($employee['food_allowance_won'] ?? 0);
    if ($food_allowance_company == 0 && $food_allowance_won == 0 && isset($employee['food_allowance'])) {
        $food_allowance_company = (float)$employee['food_allowance'];
    }
    $food_allowance = $food_allowance_company + $food_allowance_won;

    $advance = (float)($employee['advance_amount'] ?? 0);
    $insurance = (float)($employee['insurance_amount'] ?? 0);
    $other_deduction = (float)($employee['other_deduction'] ?? 0);

    $month_total_days = date('t', strtotime($month . "-01"));
    $month_safe = mysqli_real_escape_string($conn, $month);
    $user_no_safe = mysqli_real_escape_string($conn, $employee['user_no']);

    $attendance_query = mysqli_query($conn, "
    SELECT
    COUNT(*) AS total_records,

    SUM(
        CASE
            WHEN EXISTS (
                SELECT 1 FROM vacations l
                WHERE l.user_no = attendance.user_no
                AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
            )
            THEN 0

            WHEN check_in IS NOT NULL
            AND TRIM(check_in) != ''
            THEN 1

            WHEN DAYNAME(attendance_date)='Sunday'
            THEN 1

            WHEN attendance_date IN (SELECT holiday_date FROM holidays)
            THEN 1

            ELSE 0
        END
    ) AS present_days,

    SUM(
        CASE
            WHEN EXISTS (
                SELECT 1 FROM vacations l
                WHERE l.user_no = attendance.user_no
                AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
            )
            THEN 0

            WHEN
                (check_in IS NULL OR TRIM(check_in)='')
                AND DAYNAME(attendance_date)!='Sunday'
                AND attendance_date NOT IN (SELECT holiday_date FROM holidays)
            THEN 1

            ELSE 0
        END
    ) AS absent_days

    FROM attendance
    WHERE user_no='$user_no_safe'
    AND attendance_date LIKE '$month_safe%'
    ");

    $attendance = $attendance_query ? mysqli_fetch_assoc($attendance_query) : [];

    $present_days = (float)($attendance['present_days'] ?? 0);
    $absent_days = (float)($attendance['absent_days'] ?? 0);

    $late_query = mysqli_query($conn, "
        SELECT COALESCE(SUM(CASE
            WHEN EXISTS (
                SELECT 1 FROM vacations l
                WHERE l.user_no = attendance.user_no
                  AND attendance.attendance_date BETWEEN l.from_date AND l.to_date
            ) THEN 0
            WHEN late_time IS NOT NULL AND TRIM(late_time)!=''
              AND TRIM(late_time) NOT IN ('00:00','00:00:00') THEN TIME_TO_SEC(late_time)
            WHEN check_in IS NOT NULL AND TRIM(check_in)!=''
              AND TIME_TO_SEC(check_in) > TIME_TO_SEC('07:06:00')
                THEN TIME_TO_SEC(check_in) - TIME_TO_SEC('07:00:00')
            ELSE 0
        END),0) AS late_seconds
        FROM attendance
        WHERE user_no='$user_no_safe'
        AND attendance_date LIKE '$month_safe%'
    ");
    $late_row = $late_query ? mysqli_fetch_assoc($late_query) : [];
    $late_seconds = (int)($late_row['late_seconds'] ?? 0);
    $late_att_allowance_removed = 0;
    if ($late_seconds > 0 && $good_att_allowance > 0) {
        $late_att_allowance_removed = $good_att_allowance;
        $good_att_allowance = 0;
    }

    $salary_earned = isset($employee['salary_earned']) && $employee['salary_earned'] !== ''
        ? (float)$employee['salary_earned']
        : (($basic_salary / $month_total_days) * $present_days);
    $allowance_earned = isset($employee['allowance_earned']) && $employee['allowance_earned'] !== ''
        ? (float)$employee['allowance_earned']
        : (($allowance / $month_total_days) * $present_days);
    $ot_amount = (float)($employee['ot_amount'] ?? 0);

    $total_earnings = $basic_salary + $allowance + $good_att_allowance + $ot_amount + $food_allowance;
    $after_deduction_earnings = isset($employee['gross_total']) && $employee['gross_total'] !== ''
        ? max(0, (float)$employee['gross_total'] - $late_att_allowance_removed)
        : ($salary_earned + $allowance_earned + $good_att_allowance + $ot_amount + $food_allowance);
    $total_deduction = isset($employee['total_deduction']) && $employee['total_deduction'] !== ''
        ? (float)$employee['total_deduction']
        : ($advance + $insurance + $other_deduction);
    $net_pay = isset($employee['net_payable']) && $employee['net_payable'] !== ''
        ? max(0, (float)$employee['net_payable'] - $late_att_allowance_removed)
        : (isset($employee['net_salary']) && $employee['net_salary'] !== '' ? max(0, (float)$employee['net_salary'] - $late_att_allowance_removed) : ($after_deduction_earnings - $total_deduction));

    payslipBlock(
        $employee,
        $month_title,
        $present_days,
        $absent_days,
        $ot_hours,
        $basic_salary,
        $allowance,
        $good_att_allowance,
        $ot_amount,
        $food_allowance,
        $salary_earned,
        $allowance_earned,
        $total_earnings,
        $after_deduction_earnings,
        $advance,
        $insurance,
        $other_deduction,
        $net_pay
    );
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Pay Slip</title>

<style>
body{
    font-family:Arial;
    background:#f4f6f9;
    padding:20px;
}

.search-box{
    text-align:center;
    margin-bottom:20px;
}

.btn, button{
    background:#3498db;
    color:white;
    padding:10px 18px;
    text-decoration:none;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

.dashboard{
    background:#2c3e50;
}

input{
    padding:10px;
    margin:5px;
}

.print-wrapper{
    width:900px;
    margin:auto;
}

.select-box{
    width:900px;
    margin:0 auto 20px auto;
    background:white;
    padding:15px;
    border-radius:6px;
    text-align:left;
}

.select-table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}

.select-table th,
.select-table td{
    border:1px solid #ccc;
    padding:8px;
    text-align:center;
}

.select-table th{
    background:#2c3e50;
    color:white;
}

.payslip{
    width:870px;
    margin:0 auto 25px auto;
    background:white;
    padding:20px;
    color:#000;
    box-sizing:border-box;
}

.company{
    text-align:center;
    font-weight:bold;
}

.company h2{
    margin:0;
    font-size:24px;
}

.company p{
    margin:5px 0;
    font-size:16px;
}

.title{
    text-align:center;
    margin:25px 0;
    font-size:20px;
}

.info-table{
    width:100%;
    margin-bottom:25px;
}

.info-table td{
    padding:3px;
    font-size:20px;
}

.salary-table{
    width:100%;
    border-collapse:collapse;
    font-size:18px;
}

.salary-table th{
    border:2px solid #000;
    background:#d9d9d9;
    padding:5px;
    font-size:22px;
}

.salary-table td{
    border:1px solid #000;
    padding:5px;
}

.amount-head{
    width:90px;
    font-size:14px !important;
    color:#555;
    border:1px solid #ff8c1a !important;
    background:#e6e6e6 !important;
}

.bold{
    font-weight:bold;
}

.right{
    text-align:right;
}

.no-border{
    border:none !important;
}

.signature{
    margin-top:80px;
    display:flex;
    justify-content:space-around;
    text-align:center;
    font-weight:bold;
}

.sign-line{
    margin-top:80px;
    border-top:1px solid #000;
    width:230px;
}

.system{
    margin-top:80px;
    text-align:center;
    font-weight:bold;
}

@media print{

    @page{
        size:A4 landscape;
        margin:2mm;
    }

    html, body{
        background:white;
        padding:0;
        margin:0;
    }

    .search-box,
    .select-box{
        display:none;
    }

    .print-wrapper{
        width:287mm;
        margin:0 auto;
        padding:0;
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:4mm;
        align-items:start;
    }

    .payslip{
        width:140mm;
        min-height:102mm;
        margin:0;
        padding:6mm;
        box-sizing:border-box;
        border:none;
        background:white;
        page-break-inside:avoid;
        break-inside:avoid;
    }

    .payslip:nth-of-type(2n){
        page-break-after:always;
        break-after:page;
    }

    .payslip:last-child{
        page-break-after:auto;
        break-after:auto;
    }

    .company h2{
        font-size:16px;
    }

    .company p{
        font-size:12px;
        margin:2px 0;
    }

    .title{
        font-size:13px;
        margin:8px 0;
    }

    .info-table{
        margin-bottom:8px;
    }

    .info-table td{
        font-size:11px;
        padding:1px 3px;
    }

    .salary-table{
        font-size:11px;
    }

    .salary-table th{
        font-size:12px;
        padding:2px;
        border:1px solid #000;
    }

    .salary-table td{
        font-size:11px;
        padding:2px;
    }

    .amount-head{
        width:45px;
        font-size:8px !important;
    }

    .signature{
        margin-top:35px;
        font-size:12px;
    }

    .sign-line{
        margin-top:35px;
        width:170px;
    }

    .system{
        margin-top:25px;
        font-size:11px;
    }

    .ot-row{
        display:none;
    }
}
</style>
</head>

<body>
<?php include 'nav_sidebar.php'; ?>

<div class="search-box">
    <a href="dashboard.php" class="btn dashboard">Dashboard</a>

    <form method="GET" style="display:inline-block;">
        <input type="text" name="search" placeholder="User No / Employee ID / Name"
        value="<?php echo htmlspecialchars($search); ?>">

        <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" required>

        <button type="submit" name="search_btn">Search</button>
        <a class="btn dashboard" href="salary_slip.php">Clear</a>
        <button type="submit" name="show_list">Select Employees</button>
    </form>

    <button type="button" onclick="printSlips()">Print</button>
</div>

<?php if($show_list){ ?>
<div class="select-box">
    <form method="GET" id="selectSlipForm">
        <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">
        <input type="hidden" name="selected_mode" value="1">
        <label>
            <input type="checkbox" id="selectAllSlips" onclick="toggleSlipSelection(this)">
            Select All
        </label>
        <button type="submit" name="print_slips">Print Selected</button>

        <table class="select-table">
            <tr>
                <th>Select</th>
                <th>User No</th>
                <th>Name</th>
                <th>Net Payable</th>
                <th>Status</th>
            </tr>

            <?php if($slip_list_result && mysqli_num_rows($slip_list_result) > 0){ ?>
                <?php while($list_row = mysqli_fetch_assoc($slip_list_result)){ ?>
                <tr>
                    <td>
                        <input class="slip-check" type="checkbox" name="selected_users[]"
                        value="<?php echo htmlspecialchars($list_row['user_no']); ?>">
                    </td>
                    <td><?php echo htmlspecialchars($list_row['user_no']); ?></td>
                    <td><?php echo htmlspecialchars($list_row['full_name'] ?? ''); ?></td>
                    <td><?php echo money($list_row['net_payable'] ?? 0); ?> AED</td>
                    <td><?php echo htmlspecialchars($list_row['salary_status'] ?? 'Generated'); ?></td>
                </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <td colspan="5">No generated salary record found for this month.</td>
                </tr>
            <?php } ?>
        </table>
    </form>
</div>
<?php } ?>

<?php if(!empty($employees)){ ?>

<div class="print-wrapper">
    <?php foreach($employees as $employee_row){ renderPayslip($conn, $employee_row, $month, $month_title); } ?>
</div>

<?php } elseif($view_slips) { ?>

<h3 style="text-align:center;color:red;">
Employee Salary Record Not Found for This Month
</h3>

<?php } ?>

<script>
function toggleSlipSelection(source) {
    var checks = document.querySelectorAll('.slip-check');
    for (var i = 0; i < checks.length; i++) {
        checks[i].checked = source.checked;
    }
}

function printSlips() {
    // If payslips are already rendered on the page, just print them.
    if (document.querySelector('.print-wrapper')) {
        window.print();
        return;
    }

    // On the "Select Employees" list: if some are checked, run the
    // proper "Print Selected" flow so it never prints a blank page.
    var selectForm = document.getElementById('selectSlipForm');
    var checked = document.querySelectorAll('.slip-check:checked');

    if (selectForm && checked.length > 0) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'print_slips';
        hidden.value = '1';
        selectForm.appendChild(hidden);
        selectForm.submit();
        return;
    }

    alert('Print korar age: Search kore ekjon employee ber korun, athaba "Select Employees" theke checkbox select kore "Print Selected" button-e click korun.');
}

<?php if(isset($_GET['print_slips']) && !empty($employees)){ ?>
window.addEventListener('load', function(){
    window.print();
});
<?php } ?>
</script>

</body>
</html>
