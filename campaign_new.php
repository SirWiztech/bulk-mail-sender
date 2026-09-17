<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';
$pdo = get_db();
$active = 'new_campaign';
$pageTitle = 'New Campaign';

$lists = $pdo->query("
    SELECT l.*, (SELECT COUNT(*) FROM contact_list cl JOIN contacts c ON c.id=cl.contact_id
                 WHERE cl.list_id=l.id AND c.status='subscribed') AS subscriber_count
    FROM lists l ORDER BY l.name
")->fetchAll();

// Send a test email to yourself before launching
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_send') {
    csrf_check();
    $testEmail = trim($_POST['test_email'] ?? '');
    $subject = $_POST['subject'] ?? '';
    $body = $_POST['body_html'] ?? '';
    if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $fakeContact = ['id' => 0, 'email' => $testEmail, 'name' => 'Test User', 'custom_fields' => '{}'];
        $renderedSubject = render_template($subject, $fakeContact);
        $renderedBody = render_template($body, $fakeContact);
        [$ok, $err] = send_mail($testEmail, 'Test User', $renderedSubject, $renderedBody);
        flash($ok ? "Test email sent to $testEmail." : "Test send failed: $err", $ok ? 'success' : 'error');
    } else {
        flash('Enter a valid email address for the test send.', 'error');
    }
    // Fall through and re-render the form with the same content preserved via JS localStorage would be ideal,
    // but to keep this simple we just redirect back to a fresh form.
    header('Location: campaign_new.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = $_POST['body_html'] ?? '';
    $listId = (int)($_POST['list_id'] ?? 0);

    if ($name === '' || $subject === '' || trim($body) === '' || !$listId) {
        flash('Please fill in all fields and choose a list.', 'error');
        header('Location: campaign_new.php');
        exit;
    }

    $pdo->prepare("INSERT INTO campaigns (name, subject, body_html, list_id) VALUES (?, ?, ?, ?)")
        ->execute([$name, $subject, $body, $listId]);
    $campaignId = $pdo->lastInsertId();

    flash('Campaign created. Review it and click "Start Sending" when ready.');
    header('Location: campaign_view.php?id=' . $campaignId);
    exit;
}

require __DIR__ . '/includes/layout_top.php';
?>
<h1>New Campaign</h1>
<p class="subtitle">Compose your email. Use merge tags to personalize each message.</p>

<div class="card">
  <form method="post" id="campaignForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create">

    <label>Campaign name (internal only)</label>
    <input type="text" name="name" required placeholder="e.g. October Newsletter">

    <label>Send to list</label>
    <select name="list_id" required>
      <option value="">-- choose a list --</option>
      <?php foreach ($lists as $l): ?>
        <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?> (<?= (int)$l['subscriber_count'] ?> subscribed)</option>
      <?php endforeach; ?>
    </select>

    <label>Subject line</label>
    <input type="text" name="subject" id="subject" required placeholder="e.g. Hi {name}, here's what's new">

    <label>Email body (HTML supported)</label>
    <textarea name="body_html" id="body_html" rows="14" required placeholder="<p>Hi {name},</p>&#10;&#10;<p>Your message here...</p>&#10;&#10;<p><a href='{unsubscribe_link}'>Unsubscribe</a></p>"></textarea>

    <div class="tag-hint help">
      Merge tags: <code>{name}</code> <code>{email}</code> <code>{unsubscribe_link}</code> — plus any custom field from your CSV import.
      <br>Tip: always include <code>{unsubscribe_link}</code> in your footer — it's required by law (CAN-SPAM/GDPR) and keeps your sender reputation healthy.
    </div>

    <button class="btn" type="submit">Create Campaign</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Send a test email first</h2>
  <p class="help">Sends the current subject/body above to a single address so you can check formatting before launching to your full list.</p>
  <form method="post" onsubmit="document.getElementById('test_subject').value = document.getElementById('subject').value; document.getElementById('test_body').value = document.getElementById('body_html').value;">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="test_send">
    <input type="hidden" name="subject" id="test_subject">
    <input type="hidden" name="body_html" id="test_body">
    <div style="display:flex; gap:10px; align-items:end;">
      <div style="flex:1"><label>Send test to</label><input type="email" name="test_email" required placeholder="you@yourdomain.com"></div>
      <button class="btn secondary" type="submit">Send Test</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
