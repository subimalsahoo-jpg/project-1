<?php
/* ─────────────────────────────────────────────
   Offer / Employment Agreement letter generator.

   - Shows a form (variable fields pre-filled, Job Profile editable).
   - On "Generate Word File" it streams a .doc (Microsoft Word) document
     that contains ONLY the letter body (no logo / letterhead) so it can
     be printed on the company's pre-printed letterhead.

   Template based on the Euro Trousers MFG Co (FZC) employment agreement.
───────────────────────────────────────────── */
include 'auth.php';
require_once 'recruitment_helper.php';
requireAnyPermission(['recruitment_manage']);
rec_ensure_schema($conn);

function ol_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Default Job Profile (Production In-charge example). HR can edit per post. */
$default_profile = "Line up making and production planning based on the style/delivery as well as the qty.
Complete production/lineup/finishing/packing/cutting according to the lineup and full responsibilities.
Ensure equal stand of Quality achieved. Leading & maintaining the consisting performance of the team with zero tolerance.
Plan, organize and direct the manufacturing and maintenance operations which ensure the most effective return on assets.
Initiate plans and processes which minimize manufacturing costs through effective utilization of manpower, equipment, facilities, materials, and capital. Assure attainment of business objectives and production schedules while ensuring product standards that will exceed our customers' expectations.
Make sure line samples are made and patterns are corrected before starting the cutting to avoid any issues that can affect production. Plan in advance for any product with washing/embroidery/printing (subcontract work).
Study the production plan on a daily basis, monitoring the production processes and adjusting schedules as needed.
Organize workflow according to workload to meet delivery schedules and complete assigned jobs.
Supervise the production team to produce and prioritize jobs and, when necessary, reorganize for deadline changes and updates.
Assure on-time delivery with 100% accuracy.
Implement manufacturing strategies and action plans to ensure production is cost effective.
Improve manpower utilization within existing departments and processes.
Manage spending against budget, controlling spending in relation to changes in production volume.
Supervise and motivate a team of workers and review the performance of subordinates.
Ensure health and safety guidelines are followed and maintain housekeeping in all areas.
Monitor product standards and implement quality-control programs.
Communicate with merchandisers and all related departments.";

/* Prefill from a candidate if provided */
$cand = rec_candidate($conn, (int)($_GET['candidate_id'] ?? $_POST['candidate_id'] ?? 0));

$d = function ($key, $fallback = '') use ($cand) {
    return $_POST[$key] ?? ($cand[$key] ?? $fallback);
};

/* Collect fields (POST wins, then candidate, then default) */
$f = [
    'letter_date'        => $_POST['letter_date']        ?? date('d/m/Y'),
    'name'               => $_POST['name']               ?? ($cand['candidate_name'] ?? ''),
    'pp_no'              => $_POST['pp_no']               ?? ($cand['passport_no'] ?? ''),
    'contact'            => $_POST['contact']             ?? ($cand['contact'] ?? ''),
    'post'               => $_POST['post']                ?? ($cand['applied_post'] ?? ''),
    'basic_salary'       => $_POST['basic_salary']        ?? ($cand['basic_salary'] ?? ''),
    'external_allowance' => $_POST['external_allowance']  ?? ($cand['external_allowance'] ?? ''),
    'food_allowance'     => $_POST['food_allowance']      ?? ($cand['food_allowance'] ?? ''),
    'reporting_to'       => $_POST['reporting_to']        ?? 'General Manager',
    'designation_visa'   => $_POST['designation_visa']    ?? 'As per Company Quota',
    'probation'          => $_POST['probation']           ?? 'Six months',
    'working_hours'      => $_POST['working_hours']       ?? '12 hours as per the factory',
    'working_days'       => $_POST['working_days']        ?? 'Six days a week. Sunday is a holiday.',
    'vacation_notice'    => $_POST['vacation_notice']     ?? '3 months notice to be given for going on Vacation or Cancellation',
    'job_profile'        => $_POST['job_profile']         ?? $default_profile,
];

$num = function ($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    return number_format((float)$v, 0);
};

/* ── Generate the Word document ── */
if (($_POST['action'] ?? '') === 'generate') {
    $safe_name = preg_replace('/[^A-Za-z0-9_]+/', '_', trim($f['name'] !== '' ? $f['name'] : 'Candidate'));
    $filename  = 'Offer_Letter_' . $safe_name . '.doc';

    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $profile_items = '';
    foreach (preg_split('/\r\n|\r|\n/', (string)$f['job_profile']) as $line) {
        $line = trim($line);
        if ($line !== '') { $profile_items .= '<li>' . ol_h($line) . '</li>'; }
    }
    $basic_txt = $num($f['basic_salary']);
    $ext_txt   = $num($f['external_allowance']);
    $food_txt  = $num($f['food_allowance']);

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><title>Offer Letter</title>';
    echo '<style>
        @page { size: A4; margin: 2.2cm 2cm 2cm 2cm; }
        body { font-family: "Times New Roman", serif; font-size: 11.5pt; color:#000; line-height:1.35; }
        h2 { font-size: 13pt; text-align:center; text-decoration: underline; margin: 14pt 0; }
        .head p { margin: 2pt 0; }
        ul { margin: 4pt 0 8pt 18pt; }
        li { margin-bottom: 4pt; text-align: justify; }
        table.terms { width:100%; border-collapse: collapse; margin: 8pt 0; }
        table.terms td { vertical-align: top; padding: 3pt 4pt; font-size: 11.5pt; }
        td.k { width: 38%; font-weight: bold; }
        td.c { width: 4%; text-align:center; }
        .clause { text-align: justify; margin: 6pt 0; }
        .sign { margin-top: 28pt; }
        .sign td { width:50%; vertical-align: top; padding-top: 30pt; font-size: 11.5pt; }
    </style></head><body>';

    echo '<div class="head">';
    echo '<p><b>Date:</b> ' . ol_h($f['letter_date']) . '</p>';
    echo '<p><b>Name:</b> ' . ol_h($f['name']) . '</p>';
    echo '<p><b>PP No:</b> ' . ol_h($f['pp_no']) . '</p>';
    echo '<p><b>Contact:</b> ' . ol_h($f['contact']) . '</p>';
    echo '</div>';

    echo '<h2>Employment Agreement &ndash; For the Post of ' . ol_h($f['post']) . '</h2>';

    echo '<p><b>Job Profile</b></p>';
    echo '<ul>' . $profile_items . '</ul>';

    echo '<table class="terms">';
    $row = function ($k, $v) { return '<tr><td class="k">' . $k . '</td><td class="c">:</td><td>' . $v . '</td></tr>'; };
    echo $row('Designation on visa', ol_h($f['designation_visa']));
    echo $row('Reporting to', ol_h($f['reporting_to']));
    echo $row('Basic Salary', $basic_txt !== '' ? 'AED ' . $basic_txt : '');
    echo $row('External Allowance', $ext_txt !== '' ? 'AED ' . $ext_txt : '');
    echo $row('Food Allowance', $food_txt !== '' ? 'AED ' . $food_txt : '');
    echo $row('Probation Period', ol_h($f['probation']));
    echo $row('Insurance', 'Life Insurance covers minimum 24 months Basic Salary');
    echo $row('Accident Cover', 'As per the law &ndash; (Accident cover &ndash; 24 hours worldwide during the validity of VISA with the Company)');
    echo $row('Accommodation', 'Co-sharing with staff. Provided by the company.');
    echo $row('Transport', 'Provided by the company &ndash; from and to accommodation to place of work.');
    echo $row('Annual Leave, Gratuity', 'As per the company law &ndash; 30 days leave salary, 21 days gratuity (covering 7 days gratuity &ndash; 9 days sick leave &ndash; 5 days compensation for national holidays) for each working year, paid upon successful completion of the contract.');
    echo $row('Contract Period', 'As per UAE law');
    echo $row('VISA / Air Ticket', 'Visa provided by the company. Arrival ticket to be borne by the applicant. Return ticket on vacation provided upon completion of every 03 years. In case of breach of contract before 03 years, cost of VISA to be reimbursed to the company.');
    echo $row('Working Days', ol_h($f['working_days']));
    echo $row('Working Hours', ol_h($f['working_hours']));
    echo $row('Vacation Notice', ol_h($f['vacation_notice']));
    echo '</table>';

    echo '<p class="clause"><b>Confidential Clause:</b> During the course of working with the company you will be trained and exposed to a lot of core data and information of the company which are a core fundamental part of the company &ndash; like suppliers/customers and other information. Hence you are forbidden to pass down this information, intentionally or unintentionally, to anyone for personal gain or without any gain.</p>';

    echo '<p class="clause"><b>Non-competition clause on resignation:</b></p>';
    echo '<ol>';
    echo '<li class="clause">You will be entitled to a non-compete agreement prohibiting you from working with a competitor for the next 02 years in UAE.</li>';
    echo '<li class="clause">In the event of termination of employment or voluntary resignation, you will not be allowed, for a period of two years from the effective date of termination or resignation, to engage yourself directly or indirectly in a business activity similar to that of the first party in the UAE, whether as a consultant, partner, employee, technical or commercial representative or in any capacity relating to the above, nor shall you be entitled to interact with any customer, supplier or representative of Euro Trousers MFG Co (FZC) for the same period.</li>';
    echo '<li class="clause">The applicant acknowledges that a breach of any provision of this agreement may result in continuing and irreparable damage to the company, which, in addition to all other reliefs available to it (including the right to claim damages from the applicant), shall be entitled to the issuance of an injunction restraining any breach of this agreement.</li>';
    echo '<li class="clause">During the period of employment, you will not engage in any business activity, directly or indirectly, regardless of whether it is for profit or non-profit.</li>';
    echo '<li class="clause">The applicant, upon cessation of employment irrespective of the time, manner or reason of termination, shall immediately surrender and deliver to the company all lists, books, records, memoranda and data of every kind.</li>';
    echo '<li class="clause">During the period of employment, the applicant should take care of his duties and focus on his work and not interfere with the duties and work of other staff unless asked to do so.</li>';
    echo '</ol>';

    echo '<p>We welcome you to the organization and wish you all the best in your career with us.</p>';
    echo '<p>Yours Faithfully,</p>';

    echo '<table class="sign"><tr>';
    echo '<td>For Euro Trousers MFG Co (FZC)<br><br>&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;<br>Authorized Signatory<br>HR Dept.</td>';
    echo '<td>Agreed &amp; accepted the above Offer Letter &amp; the Job Profile<br><br>&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;<br>Signed By &ndash; ' . ol_h($f['name']) . '</td>';
    echo '</tr></table>';

    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offer Letter Generator</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--brand-mid:#2563a8;--accent:#e8a020;--green:#16a34a;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;}
.btn-back:hover{background:rgba(255,255,255,.22);}
.page{padding:22px;max-width:1000px;}
.page-title{font-size:20px;font-weight:700;color:var(--brand);display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.panel{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;}
.panel-head{background:var(--brand);color:#fff;padding:11px 16px;font-weight:600;font-size:14px;}
.panel-body{padding:16px;}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.full{grid-column:1/-1;}
.fg label{font-size:12px;color:var(--gray-600);font-weight:600;}
.fg input,.fg textarea{padding:9px 11px;border:1px solid var(--gray-200);border-radius:7px;font-size:13px;width:100%;font-family:inherit;}
.fg textarea{min-height:220px;resize:vertical;}
.actions{margin-top:16px;display:flex;gap:10px;}
.btn{padding:10px 18px;border-radius:7px;border:none;cursor:pointer;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-success{background:var(--green);color:#fff;}
.btn-gray{background:var(--gray-200);color:#334155;}
.hint{font-size:12px;color:#64748b;margin-bottom:12px;}
@media(max-width:800px){.grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="topbar">
    <div class="topbar-left">
        <a href="recruitment.php" class="btn-back">&#8592; Recruitment</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;') : ''; ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
</div>
<div class="page">
    <div class="page-title"><span>&#128221;</span> Offer Letter Generator</div>
    <div class="hint">Fill in the details, then click <b>Generate Word File</b>. The downloaded <b>.doc</b> contains only the letter text (no logo/header) so you can print it on the company letterhead. The Job Profile is editable per post.</div>

    <form method="POST" action="offer_letter.php">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="candidate_id" value="<?php echo (int)($cand['id'] ?? 0); ?>">

        <div class="panel">
            <div class="panel-head">Candidate &amp; Post</div>
            <div class="panel-body">
                <div class="grid">
                    <div class="fg"><label>Date</label><input type="text" name="letter_date" value="<?php echo ol_h($f['letter_date']); ?>"></div>
                    <div class="fg"><label>Name</label><input type="text" name="name" value="<?php echo ol_h($f['name']); ?>" required></div>
                    <div class="fg"><label>Passport No</label><input type="text" name="pp_no" value="<?php echo ol_h($f['pp_no']); ?>"></div>
                    <div class="fg"><label>Contact</label><input type="text" name="contact" value="<?php echo ol_h($f['contact']); ?>"></div>
                    <div class="fg"><label>Post / Designation</label><input type="text" name="post" value="<?php echo ol_h($f['post']); ?>" placeholder="e.g. Production In charge"></div>
                    <div class="fg"><label>Designation on visa</label><input type="text" name="designation_visa" value="<?php echo ol_h($f['designation_visa']); ?>"></div>
                    <div class="fg"><label>Reporting to</label><input type="text" name="reporting_to" value="<?php echo ol_h($f['reporting_to']); ?>"></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">Salary &amp; Terms (AED)</div>
            <div class="panel-body">
                <div class="grid">
                    <div class="fg"><label>Basic Salary</label><input type="number" step="1" name="basic_salary" value="<?php echo ol_h($f['basic_salary']); ?>"></div>
                    <div class="fg"><label>External Allowance</label><input type="number" step="1" name="external_allowance" value="<?php echo ol_h($f['external_allowance']); ?>"></div>
                    <div class="fg"><label>Food Allowance</label><input type="number" step="1" name="food_allowance" value="<?php echo ol_h($f['food_allowance']); ?>"></div>
                    <div class="fg"><label>Probation Period</label><input type="text" name="probation" value="<?php echo ol_h($f['probation']); ?>"></div>
                    <div class="fg"><label>Working Hours</label><input type="text" name="working_hours" value="<?php echo ol_h($f['working_hours']); ?>"></div>
                    <div class="fg full"><label>Working Days</label><input type="text" name="working_days" value="<?php echo ol_h($f['working_days']); ?>"></div>
                    <div class="fg full"><label>Vacation Notice</label><input type="text" name="vacation_notice" value="<?php echo ol_h($f['vacation_notice']); ?>"></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">Job Profile (one duty per line)</div>
            <div class="panel-body">
                <div class="fg full"><textarea name="job_profile"><?php echo ol_h($f['job_profile']); ?></textarea></div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-success">&#11015; Generate Word File</button>
            <a href="recruitment.php" class="btn btn-gray">Cancel</a>
        </div>
    </form>
</div>
</body>
</html>
