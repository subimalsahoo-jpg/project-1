<?php
include 'auth.php';
require_once 'notebook_helper.php';
requireLogin();
notebook_ensure_schema($conn);

$me_id   = (int)($_SESSION['user_id'] ?? 0);
$me_name = trim((string)($_SESSION['full_name'] ?? ''));
if ($me_name === '') $me_name = trim((string)($_SESSION['username'] ?? 'User'));

function nb_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nb_when($v) { $t = strtotime((string)$v); return $t ? date('d-M-Y h:i A', $t) : ''; }

/* AJAX: unread count (used by the sidebar badge poll). */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'unread') {
    header('Content-Type: application/json');
    echo json_encode(['count' => notebook_unread_count($conn, $me_id)]);
    exit();
}

$message = '';

/* Send a note to a selected user (or everyone). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_note'])) {
    $body = trim($_POST['message'] ?? '');
    $to   = $_POST['recipient_id'] ?? '';
    if ($me_id <= 0) {
        $message = "<div class='nb-msg err'>Your session has no user id; please log in again.</div>";
    } elseif ($body === '') {
        $message = "<div class='nb-msg err'>Please write a note before sending.</div>";
    } elseif ($to === '') {
        $message = "<div class='nb-msg err'>Please select a recipient.</div>";
    } else {
        $recipients = [];
        if ($to === 'all') {
            $recipients = notebook_users($conn, $me_id);
        } else {
            $rid = (int)$to;
            $ur = mysqli_query($conn, "SELECT id, username, full_name FROM users WHERE id=$rid LIMIT 1");
            if ($ur && mysqli_num_rows($ur) > 0) $recipients[] = mysqli_fetch_assoc($ur);
        }
        if (empty($recipients)) {
            $message = "<div class='nb-msg err'>Recipient not found.</div>";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO user_messages
                (sender_id, sender_name, recipient_id, recipient_name, message) VALUES (?,?,?,?,?)");
            foreach ($recipients as $u) {
                $rid = (int)$u['id'];
                $rname = notebook_user_label($u);
                mysqli_stmt_bind_param($stmt, 'issss', $me_id, $me_name, $rid, $rname, $body);
                mysqli_stmt_execute($stmt);
            }
            mysqli_stmt_close($stmt);
            header("Location: notebook.php?sent=1");
            exit();
        }
    }
}
if (isset($_GET['sent'])) $message = "<div class='nb-msg ok'>&#10004; Note sent.</div>";

/* Delete one of my notes (sender or recipient copy). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'])) {
    $mid = (int)$_POST['delete_note'];
    $stmt = mysqli_prepare($conn, "DELETE FROM user_messages WHERE id=? AND (recipient_id=? OR sender_id=?)");
    mysqli_stmt_bind_param($stmt, 'iii', $mid, $me_id, $me_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header("Location: notebook.php");
    exit();
}

/* Capture which received notes are still unread (to highlight as NEW), then
   mark everything received as read so the blinking badge clears. */
$just_unread = [];
if ($me_id > 0) {
    $uq = mysqli_query($conn, "SELECT id FROM user_messages WHERE recipient_id=$me_id AND is_read=0");
    if ($uq) while ($r = mysqli_fetch_assoc($uq)) $just_unread[(int)$r['id']] = true;
    mysqli_query($conn, "UPDATE user_messages SET is_read=1, read_at=NOW() WHERE recipient_id=$me_id AND is_read=0");
}

/* Load inbox + sent. */
$inbox = [];
$iq = mysqli_query($conn, "SELECT * FROM user_messages WHERE recipient_id=$me_id ORDER BY id DESC LIMIT 300");
if ($iq) while ($r = mysqli_fetch_assoc($iq)) $inbox[] = $r;
$sent = [];
$sq = mysqli_query($conn, "SELECT * FROM user_messages WHERE sender_id=$me_id ORDER BY id DESC LIMIT 300");
if ($sq) while ($r = mysqli_fetch_assoc($sq)) $sent[] = $r;

$users = notebook_users($conn, $me_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notebook</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--brand:#1a3a5c;--accent:#e8a020;--green:#16a34a;--green-soft:#dcfce7;--red:#b91c1c;--red-soft:#fee2e2;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-600:#475569;--gray-800:#1e293b;--radius:8px;--shadow:0 2px 12px rgba(0,0,0,.08);}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--gray-100);color:var(--gray-800);font-size:14px;min-height:100vh;}
.topbar{position:sticky;top:0;z-index:50;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;height:54px;box-shadow:0 2px 10px rgba(0,0,0,.22);}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:15px;font-weight:700;}
.topbar-logo span{color:var(--accent);}
.btn-back{background:rgba(255,255,255,.15);color:#fff;text-decoration:none;padding:7px 13px;border-radius:6px;font-size:13px;}
.page{max-width:1080px;margin:22px auto;padding:0 18px;}
.page-title{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:700;color:var(--brand);margin-bottom:16px;}
.nb-msg{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-weight:600;}
.nb-msg.ok{background:var(--green-soft);color:#166534;border:1px solid #86efac;}
.nb-msg.err{background:var(--red-soft);color:#991b1b;border:1px solid #fca5a5;}
.nb-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:18px;align-items:start;}
@media(max-width:880px){.nb-grid{grid-template-columns:1fr;}}
.panel{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.panel-head{padding:13px 16px;border-bottom:1px solid var(--gray-200);font-weight:700;color:var(--brand);display:flex;align-items:center;justify-content:space-between;}
.panel-body{padding:16px;}
.fg{display:flex;flex-direction:column;gap:6px;margin-bottom:12px;}
.fg label{font-size:13px;font-weight:700;color:var(--brand);}
.fg select,.fg textarea{padding:10px 12px;border:1.6px solid #f1c27a;border-radius:8px;font-size:14px;font-family:inherit;background:#fffaf2;}
.fg textarea{min-height:150px;resize:vertical;line-height:1.6;}
.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(232,160,32,.22);}
.btn{display:inline-flex;align-items:center;gap:6px;border:none;border-radius:7px;padding:10px 18px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;}
.btn-accent{background:var(--accent);color:#1a1a1a;}
.nb-tabs{display:flex;gap:8px;margin-bottom:12px;}
.nb-tab{padding:8px 16px;border-radius:999px;border:1px solid var(--gray-200);background:#fff;cursor:pointer;font-weight:700;font-size:13px;color:var(--brand);}
.nb-tab.active{background:var(--brand);color:#fff;border-color:var(--brand);}
.note{border:1px solid var(--gray-200);border-radius:8px;padding:12px 14px;margin-bottom:10px;background:#fff;}
.note.new{border-left:4px solid var(--accent);background:#fffaf0;}
.note-meta{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:var(--gray-600);margin-bottom:6px;}
.note-from{font-weight:700;color:var(--brand);}
.note-body{white-space:pre-wrap;line-height:1.6;font-size:14px;}
.note-new-tag{background:var(--accent);color:#1a1a1a;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;margin-left:6px;}
.note-del{background:none;border:none;color:var(--red);cursor:pointer;font-size:12px;font-weight:600;}
.nb-empty{text-align:center;color:#94a3b8;padding:24px;}
.list-wrap{display:none;}
.list-wrap.active{display:block;}
</style>
</head>
<body>
<?php include 'nav_sidebar.php'; ?>
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="btn-back">&#8592; Dashboard</a>
        <?php echo function_exists('company_logo_img') ? company_logo_img(30, 'background:#fff;border-radius:5px;padding:2px 4px;margin-right:6px;') : ''; ?>
        <span class="topbar-logo">EURO TROUSERS <span>MFG CO (FZC)</span></span>
    </div>
    <div style="font-size:13px;">Signed in: <strong><?php echo nb_h($me_name); ?></strong></div>
</div>


<div class="page">
    <div class="page-title"><span>&#128210;</span> Notebook</div>
    <?php echo $message; ?>

    <div class="nb-grid">
        <!-- Compose -->
        <div class="panel">
            <div class="panel-head">&#9999;&#65039; Write a Note</div>
            <div class="panel-body">
                <form method="POST" action="notebook.php">
                    <input type="hidden" name="send_note" value="1">
                    <div class="fg">
                        <label>Send To</label>
                        <select name="recipient_id" required>
                            <option value="">— Select user —</option>
                            <option value="all">&#128226; All Users (broadcast)</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo nb_h(notebook_user_label($u)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Note</label>
                        <textarea name="message" placeholder="Type your message here..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-accent">&#128228; Send Note</button>
                </form>
            </div>
        </div>

        <!-- Messages -->
        <div class="panel">
            <div class="panel-head">Messages</div>
            <div class="panel-body">
                <div class="nb-tabs">
                    <div class="nb-tab active" onclick="nbTab('inbox', this)">Inbox (<?php echo count($inbox); ?>)</div>
                    <div class="nb-tab" onclick="nbTab('sent', this)">Sent (<?php echo count($sent); ?>)</div>
                </div>

                <div class="list-wrap active" id="nb-inbox">
                    <?php if (empty($inbox)): ?>
                    <div class="nb-empty">No messages received yet.</div>
                    <?php else: foreach ($inbox as $m): $isnew = isset($just_unread[(int)$m['id']]); ?>
                    <div class="note <?php echo $isnew ? 'new' : ''; ?>">
                        <div class="note-meta">
                            <span class="note-from">From: <?php echo nb_h($m['sender_name'] ?: ('User #' . $m['sender_id'])); ?><?php echo $isnew ? '<span class="note-new-tag">NEW</span>' : ''; ?></span>
                            <span><?php echo nb_h(nb_when($m['created_at'])); ?></span>
                        </div>
                        <div class="note-body"><?php echo nb_h($m['message']); ?></div>
                        <div style="text-align:right;margin-top:6px;">
                            <form method="POST" action="notebook.php" style="display:inline;" onsubmit="return confirm('Delete this note?');">
                                <input type="hidden" name="delete_note" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="note-del">Delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="list-wrap" id="nb-sent">
                    <?php if (empty($sent)): ?>
                    <div class="nb-empty">You haven't sent any notes yet.</div>
                    <?php else: foreach ($sent as $m): ?>
                    <div class="note">
                        <div class="note-meta">
                            <span class="note-from">To: <?php echo nb_h($m['recipient_name'] ?: ('User #' . $m['recipient_id'])); ?>
                                <?php echo ((int)$m['is_read'] === 1) ? '<span style="color:#16a34a;">&#10004; read</span>' : '<span style="color:#b9770e;">&#8226; unread</span>'; ?>
                            </span>
                            <span><?php echo nb_h(nb_when($m['created_at'])); ?></span>
                        </div>
                        <div class="note-body"><?php echo nb_h($m['message']); ?></div>
                        <div style="text-align:right;margin-top:6px;">
                            <form method="POST" action="notebook.php" style="display:inline;" onsubmit="return confirm('Delete this note?');">
                                <input type="hidden" name="delete_note" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="note-del">Delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function nbTab(which, el){
    document.querySelectorAll('.nb-tab').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    document.getElementById('nb-inbox').classList.toggle('active', which === 'inbox');
    document.getElementById('nb-sent').classList.toggle('active', which === 'sent');
}
</script>
</body>
</html>
