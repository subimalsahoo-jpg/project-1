<?php
include 'auth.php';
requireAnyPermission(['gate_pass_manage']);

function gpp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gpp_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d / m / Y', $t) : $v;
}

$id = (int)($_GET['id'] ?? 0);
$auto = isset($_GET['auto']);

$pass = null;
if ($id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM gate_passes WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pass = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
}

if (!$pass) {
    echo "<p style='font-family:Arial;text-align:center;margin-top:60px;'>Gate pass not found. <a href='gate_pass.php'>Back</a></p>";
    exit();
}

$employees = json_decode((string)($pass['employees_json'] ?? '[]'), true);
if (!is_array($employees)) $employees = [];

/* Enrich each employee with Saif Zone ID / Emirates ID / photo. Older gate
   passes (saved before these fields existed) only stored name + user_no, so
   we look the missing values up live from the employee record. */
foreach ($employees as &$e) {
    $e['saif_zone_id'] = $e['saif_zone_id'] ?? '';
    $e['emirates_id']  = $e['emirates_id']  ?? '';
    $e['photo']        = $e['photo']        ?? '';
    $uno = trim((string)($e['user_no'] ?? ''));
    $needs = ($e['saif_zone_id'] === '' && $e['emirates_id'] === '' && $e['photo'] === '');
    if ($uno !== '' && $needs) {
        $stmt = mysqli_prepare($conn, "SELECT saif_zone_id, emirates_id_number, photo, full_name
            FROM employees WHERE user_no = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $uno);
        mysqli_stmt_execute($stmt);
        $r2 = mysqli_stmt_get_result($stmt);
        if ($row2 = mysqli_fetch_assoc($r2)) {
            $e['saif_zone_id'] = trim((string)($row2['saif_zone_id'] ?? ''));
            $e['emirates_id']  = trim((string)($row2['emirates_id_number'] ?? ''));
            $e['photo']        = trim((string)($row2['photo'] ?? ''));
            if (trim((string)($e['name'] ?? '')) === '') {
                $e['name'] = trim((string)($row2['full_name'] ?? ''));
            }
        }
        mysqli_stmt_close($stmt);
    }
}
unset($e);

$gp_subject = trim((string)($pass['subject'] ?? '')) ?: 'Request for Permission';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gate Pass <?php echo gpp_h($pass['pass_no']); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Times New Roman',Georgia,serif;background:#e9edf3;color:#111;}
.toolbar{background:#1a3a5c;color:#fff;padding:10px 18px;display:flex;gap:10px;justify-content:center;font-family:'Segoe UI',Arial,sans-serif;}
.toolbar a,.toolbar button{background:#fff;color:#1a3a5c;border:none;border-radius:6px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}
.toolbar button.print{background:#0f766e;color:#fff;}

/* A4 sheet */
.sheet{background:#fff;width:210mm;min-height:297mm;margin:18px auto;padding:18mm 22mm;box-shadow:0 4px 24px rgba(0,0,0,.18);}
.letterhead{border-bottom:2px solid #1a4f9c;padding-bottom:10px;margin-bottom:26px;}
.doc-date{text-align:right;font-size:15px;margin-bottom:8px;}
.doc-title{text-align:center;font-size:22px;font-weight:800;letter-spacing:2px;text-decoration:underline;margin:14px 0 22px;}
.to-block{font-size:15px;line-height:1.5;margin-bottom:18px;}
.subject{font-size:15px;font-weight:700;margin-bottom:16px;}
.body-text{font-size:15px;line-height:1.7;margin-bottom:16px;text-align:justify;}
.emp-table{width:100%;border-collapse:collapse;margin:14px 0 22px;}
.emp-table th,.emp-table td{border:1px solid #333;padding:8px 12px;font-size:14.5px;text-align:left;vertical-align:middle;}
.emp-table th{background:#eef2f8;font-weight:700;letter-spacing:.5px;}
.emp-table td.idcol{width:150px;}
.emp-table td.photocol{width:90px;text-align:center;padding:5px;}
.emp-photo{height:78px;width:auto;max-width:72px;object-fit:cover;border:1px solid #999;border-radius:3px;display:inline-block;}
.emp-photo-none{display:inline-block;width:64px;height:74px;border:1px dashed #aaa;border-radius:3px;color:#999;font-size:10px;line-height:74px;text-align:center;font-family:'Segoe UI',Arial,sans-serif;}
.sign{margin-top:46px;font-size:15px;line-height:1.7;}
.sign .line{margin-top:42px;border-top:1px solid #333;width:230px;}
.passno{margin-top:6px;font-size:12px;color:#555;font-family:'Segoe UI',Arial,sans-serif;}

@media print{
    .toolbar{display:none!important;}
    body{background:#fff;}
    .sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none;}
    @page{size:A4;margin:14mm 16mm;}
}
</style>
</head>
<body>

<div class="toolbar">
    <a href="gate_pass.php">&#8592; Back to Gate Pass</a>
    <button class="print" onclick="window.print()">&#128438; Print</button>
</div>

<div class="sheet">

    <!-- Company letterhead (real image if provided, else HTML recreation) -->
    <div class="letterhead">
        <?php echo function_exists('company_letterhead_html') ? company_letterhead_html() : ''; ?>
    </div>

    <div class="doc-date">Date: <?php echo gpp_h(gpp_dmy($pass['pass_date'])); ?></div>

    <div class="doc-title">GATE PASS</div>

    <div class="to-block">
        To:<br>
        Security Officer<br>
        SAIF Zone<br>
        Sharjah, U.A.E.
    </div>

    <div class="subject">Subject: <?php echo gpp_h($gp_subject); ?></div>

    <div class="body-text">Dear Sir,</div>

    <div class="body-text">
        Kindly grant permission for the following employee<?php echo count($employees) > 1 ? 's' : ''; ?>
        to leave the premises on <strong><?php echo gpp_h(gpp_dmy($pass['leave_date'])); ?></strong>.
        <?php
            $rdate = trim((string)($pass['return_date'] ?? ''));
            $same_day = ($rdate === '' || $rdate === '0000-00-00' || $rdate === trim((string)$pass['leave_date']));
            $return_when = $same_day ? 'on the same day' : ('on <strong>' . gpp_h(gpp_dmy($rdate)) . '</strong>');
        ?>
        <?php if (trim((string)$pass['depart_time']) !== '' || trim((string)$pass['return_time']) !== ''): ?>
        They will depart at <strong><?php echo gpp_h($pass['depart_time']); ?></strong>
        and return to SAIF Zone at <strong><?php echo gpp_h($pass['return_time']); ?></strong> <?php echo $return_when; ?>.
        <?php elseif (!$same_day): ?>
        They will return to SAIF Zone <?php echo $return_when; ?>.
        <?php endif; ?>
        <?php if (trim((string)$pass['reason']) !== ''): ?>
        <br>Reason: <strong><?php echo gpp_h($pass['reason']); ?></strong>.
        <?php endif; ?>
    </div>

    <table class="emp-table">
        <thead>
            <tr>
                <th class="idcol">SAIF ZONE ID</th>
                <th class="idcol">EMIRATES ID</th>
                <th>EMPLOYEE NAME</th>
                <th class="photocol">PHOTO</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr><td class="idcol">&nbsp;</td><td class="idcol">&nbsp;</td><td>&nbsp;</td><td class="photocol">&nbsp;</td></tr>
            <?php else: foreach ($employees as $e):
                $photo = trim((string)($e['photo'] ?? ''));
            ?>
                <tr>
                    <td class="idcol"><?php echo gpp_h($e['saif_zone_id'] ?? ''); ?></td>
                    <td class="idcol"><?php echo gpp_h($e['emirates_id'] ?? ''); ?></td>
                    <td><?php echo gpp_h($e['name'] ?? ''); ?></td>
                    <td class="photocol">
                        <?php if ($photo !== ''): ?>
                            <img class="emp-photo" src="uploads/<?php echo gpp_h($photo); ?>" alt="">
                        <?php else: ?>
                            <span class="emp-photo-none">No Photo</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="body-text">Your cooperation and approval will be highly appreciated.</div>
    <div class="body-text">Thank you.</div>

    <div class="sign">
        Sincerely,
        <div class="line"></div>
        Authorized Signatory<br>
        HR Department.<br>
        For Euro Trousers MFG. CO. FZC
        <div class="passno">Ref: <?php echo gpp_h($pass['pass_no'] ?: ('GP-' . $pass['id'])); ?></div>
    </div>

</div>

<?php if ($auto): ?>
<script>
window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });
</script>
<?php endif; ?>
</body>
</html>
