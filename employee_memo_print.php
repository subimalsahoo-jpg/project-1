<?php
include 'auth.php';
require_once 'memo_helper.php';
requireAnyPermission(['memo_manage']);

memo_ensure_schema($conn);

function mpp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mpp_dmy($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d / m / Y', $t) : $v;
}

$id   = (int)($_GET['id'] ?? 0);
$auto = isset($_GET['auto']);

$memo = null;
if ($id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM employee_memos WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $memo = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
}

if (!$memo) {
    echo "<p style='font-family:Arial;text-align:center;margin-top:60px;'>Memo not found. <a href='employee_memo.php'>Back</a></p>";
    exit();
}

/* Split body into paragraphs on blank lines. */
$paragraphs = preg_split('/\r\n\r\n|\r\r|\n\n/', trim((string)$memo['body']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Memo <?php echo mpp_h($memo['memo_no']); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Times New Roman',Georgia,serif;background:#e9edf3;color:#111;}
.toolbar{background:#1a3a5c;color:#fff;padding:10px 18px;display:flex;gap:10px;justify-content:center;font-family:'Segoe UI',Arial,sans-serif;}
.toolbar a,.toolbar button{background:#fff;color:#1a3a5c;border:none;border-radius:6px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}
.toolbar button.print{background:#0f766e;color:#fff;}

.sheet{background:#fff;width:210mm;min-height:297mm;margin:18px auto;padding:20mm 22mm;box-shadow:0 4px 24px rgba(0,0,0,.18);}
.letterhead{border-bottom:2px solid #1a4f9c;padding-bottom:10px;margin-bottom:24px;}
.memo-top{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px;}
.memo-title{font-size:22px;font-weight:800;letter-spacing:2px;text-decoration:underline;}
.memo-date{font-size:15px;}
.info-table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:15px;}
.info-table td{padding:4px 0;vertical-align:top;}
.info-table td.k{width:160px;font-weight:700;}
.info-table td.sep{width:14px;font-weight:700;}
.body-text{font-size:15px;line-height:1.8;margin-bottom:14px;text-align:justify;}
.sign-block{margin-top:60px;display:flex;justify-content:space-between;gap:40px;font-size:15px;}
.sign-col{width:46%;}
.sign-line{margin-top:46px;border-top:1px solid #333;padding-top:5px;font-weight:700;}
.sign-row{margin-top:14px;}
.memo-ref{margin-top:30px;font-size:11.5px;color:#666;font-family:'Segoe UI',Arial,sans-serif;border-top:1px dashed #ccc;padding-top:8px;}

@media print{
    .toolbar{display:none!important;}
    body{background:#fff;}
    .sheet{width:auto;min-height:auto;margin:0;box-shadow:none;padding:14mm 16mm;}
    @page{size:A4;margin:0;}
}
</style>
</head>
<body>

<div class="toolbar">
    <a href="employee_memo.php">&#8592; Back to Memos</a>
    <button class="print" onclick="window.print()">&#128438; Print</button>
</div>

<div class="sheet">

    <!-- Company letterhead (real image if provided, else HTML recreation) -->
    <div class="letterhead">
        <?php echo function_exists('company_letterhead_html') ? company_letterhead_html() : ''; ?>
    </div>

    <div class="memo-top">
        <div class="memo-title">INTERNAL MEMO</div>
        <div class="memo-date">Date: <?php echo mpp_h(mpp_dmy($memo['memo_date'])); ?></div>
    </div>

    <table class="info-table">
        <tr><td class="k">NAME</td><td class="sep">:</td><td><?php echo mpp_h($memo['employee_name']); ?></td></tr>
        <tr><td class="k">DESIGNATION</td><td class="sep">:</td><td><?php echo mpp_h($memo['designation']); ?></td></tr>
        <tr><td class="k">ID NO</td><td class="sep">:</td><td><?php echo mpp_h($memo['employee_id'] ?: $memo['user_no']); ?></td></tr>
        <tr><td class="k">SUBJECT</td><td class="sep">:</td><td><strong><?php echo mpp_h($memo['subject']); ?></strong></td></tr>
    </table>

    <?php foreach ($paragraphs as $p): $p = trim($p); if ($p === '') continue; ?>
        <div class="body-text"><?php echo nl2br(mpp_h($p)); ?></div>
    <?php endforeach; ?>

    <div class="sign-block">
        <div class="sign-col">
            <div class="sign-line"><?php echo mpp_h($memo['issued_by'] ?: 'ADMIN In-charge'); ?></div>
            <div class="sign-row">Date&nbsp;:&nbsp;_____________________</div>
        </div>
        <div class="sign-col">
            <div class="sign-line">Employee Signature</div>
            <div class="sign-row">Date&nbsp;:&nbsp;_____________________</div>
        </div>
    </div>

    <div class="memo-ref">
        Ref: <?php echo mpp_h($memo['memo_no'] ?: ('MEMO-' . $memo['id'])); ?>
        <?php if (trim((string)($memo['created_by'] ?? '')) !== ''): ?>
        &nbsp;|&nbsp; Prepared by: <?php echo mpp_h($memo['created_by']); ?>
        <?php endif; ?>
    </div>

</div>

<?php if ($auto): ?>
<script>
window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });
</script>
<?php endif; ?>
</body>
</html>
