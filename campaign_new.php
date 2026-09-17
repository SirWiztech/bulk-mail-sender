<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';
$pdo = get_db();
$active = 'new_campaign';
$pageTitle = 'New Campaign';

/**
 * Validate and store uploaded attachment files. Returns [files, errors].
 * files: list of ['path'=>..., 'name'=>original name, 'size'=>..., 'mime'=>...]
 */
function handle_attachment_uploads(): array {
    $files = [];
    $errors = [];

    if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'])) {
        return [$files, $errors];
    }

    $allowed = array_map('trim', explode(',', ATTACHMENT_MIME_WHITELIST));
    $names = $_FILES['attachments']['name'];
    $count = count($names);

    if ($count > MAX_ATTACHMENTS) {
        $errors[] = 'You can attach at most ' . MAX_ATTACHMENTS . ' files per campaign.';
    }

    if (!is_dir(UPLOAD_PATH)) {
        @mkdir(UPLOAD_PATH, 0775, true);
    }
    if (!is_dir(UPLOAD_PATH)) {
        $errors[] = 'Uploads directory is not writable: ' . UPLOAD_PATH;
        return [$files, $errors];
    }

    $limit = min($count, MAX_ATTACHMENTS);
    for ($i = 0; $i < $limit; $i++) {
        if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // empty input slot
        }
        $origName = (string)$names[$i];
        if ($origName === '') continue;
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $origName);

        if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "Upload failed for \"$safeName\" (code {$_FILES['attachments']['error'][$i]}).";
            continue;
        }
        $tmp = $_FILES['attachments']['tmp_name'][$i];
        $size = (int)$_FILES['attachments']['size'][$i];

        if ($size > MAX_ATTACHMENT_SIZE) {
            $errors[] = "\"$safeName\" exceeds the " . round(MAX_ATTACHMENT_SIZE / 1048576, 1) . ' MB limit.';
            continue;
        }
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = "\"$safeName\": .$ext files are not allowed (allowed: " . ATTACHMENT_MIME_WHITELIST . ').';
            continue;
        }
        if (!is_uploaded_file($tmp)) {
            $errors[] = "\"$safeName\" is not a valid upload.";
            continue;
        }

        $stored = 'att_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = UPLOAD_PATH . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = "Could not store \"$safeName\".";
            continue;
        }
        $mime = '';
        if (function_exists('mime_content_type')) {
            $mime = (string)@mime_content_type($dest);
        }
        $files[] = ['path' => $dest, 'name' => $safeName, 'size' => $size, 'mime' => $mime];
    }

    return [$files, $errors];
}

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
        [$testFiles, $uploadErrors] = handle_attachment_uploads();
        foreach ($uploadErrors as $ue) { flash($ue, 'error'); }
        $fakeContact = ['id' => 0, 'email' => $testEmail, 'name' => 'Test User', 'custom_fields' => '{}'];
        $renderedSubject = render_template($subject, $fakeContact);
        $renderedBody = render_template($body, $fakeContact);
        [$ok, $err] = send_mail($testEmail, 'Test User', $renderedSubject, $renderedBody, $testFiles);
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

    [$files, $uploadErrors] = handle_attachment_uploads();
    if ($uploadErrors) {
        foreach ($uploadErrors as $ue) { flash($ue, 'error'); }
        // Keep the campaign working even if only some files failed; but if every chosen file failed, stop.
        $hadFiles = false;
        $chosenNames = (($_FILES['attachments']['name'] ?? []) ?: []);
        if (is_array($chosenNames)) {
            foreach ($chosenNames as $n) { if ($n !== '') { $hadFiles = true; break; } }
        }
        if ($hadFiles && !$files) {
            flash('Campaign was not created because none of the attachments could be saved.', 'error');
            header('Location: campaign_new.php');
            exit;
        }
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO campaigns (name, subject, body_html, list_id) VALUES (?, ?, ?, ?)")
        ->execute([$name, $subject, $body, $listId]);
    $campaignId = $pdo->lastInsertId();

    $insertAtt = $pdo->prepare("
        INSERT INTO campaign_attachments (campaign_id, filename, path, size, mime_type)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($files as $f) {
        $insertAtt->execute([$campaignId, $f['name'], $f['path'], $f['size'], $f['mime']]);
    }
    $pdo->commit();

    flash('Campaign created' . ($files ? ' with ' . count($files) . ' attachment' . (count($files) === 1 ? '' : 's') : '')
        . '. Review it and click "Start Sending" when ready.');
    header('Location: campaign_view.php?id=' . $campaignId);
    exit;
}

require __DIR__ . '/includes/layout_top.php';
?>
<h1>New Campaign</h1>
<p class="subtitle">Compose your email. Use merge tags to personalize each message.</p>

<div class="card">
  <form method="post" id="campaignForm" enctype="multipart/form-data">
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

    <label>Attachments (optional, up to <?= (int)MAX_ATTACHMENTS ?> files, max <?= round(MAX_ATTACHMENT_SIZE / 1048576, 1) ?> MB each)</label>
    <input type="file" name="attachments[]" multiple
           accept="<?= h('.' . str_replace(',', ',.', ATTACHMENT_MIME_WHITELIST)) ?>"
           onchange="addAttachmentSlot()">
    <div id="extraAttachments"></div>
    <p class="help">Allowed types: <?= h(ATTACHMENT_MIME_WHITELIST) ?>. Attachments are sent with every email in this campaign — large files can hurt deliverability, so keep them small.</p>

    <div class="tag-hint help">
      Merge tags: <code>{name}</code> <code>{email}</code> <code>{unsubscribe_link}</code> — plus any custom field from your CSV import.
      <br>Tip: always include <code>{unsubscribe_link}</code> in your footer — it's required by law (CAN-SPAM/GDPR) and keeps your sender reputation healthy.
    </div>

    <button class="btn" type="submit">Create Campaign</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Send a test email first</h2>
  <p class="help">Sends the current subject/body above (plus any attachments you pick here) to a single address so you can check formatting before launching to your full list.</p>
  <form method="post" enctype="multipart/form-data" onsubmit="document.getElementById('test_subject').value = document.getElementById('subject').value; document.getElementById('test_body').value = document.getElementById('body_html').value;">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="test_send">
    <input type="hidden" name="subject" id="test_subject">
    <input type="hidden" name="body_html" id="test_body">
    <label>Attachments (optional)</label>
    <input type="file" name="attachments[]" multiple
           accept="<?= h('.' . str_replace(',', ',.', ATTACHMENT_MIME_WHITELIST)) ?>">
    <div style="display:flex; gap:10px; align-items:end; margin-top:8px;">
      <div style="flex:1"><label>Send test to</label><input type="email" name="test_email" required placeholder="you@yourdomain.com"></div>
      <button class="btn secondary" type="submit">Send Test</button>
    </div>
  </form>
</div>

<script>
// Add another file slot when the first input already has a file (supports up to MAX attachments)
function addAttachmentSlot() {
  const wrap = document.getElementById('extraAttachments');
  const slots = wrap.querySelectorAll('input[type="file"]');
  const first = document.querySelector('#campaignForm > input[type="file"]');
  const hasFile = first && first.files.length > 0;
  const max = <?= (int)MAX_ATTACHMENTS ?>;

  if (hasFile && slots.length + 1 < max && !wrap.querySelector('input[type="file"]:not([data-filled])')) {
    const input = document.createElement('input');
    input.type = 'file';
    input.name = 'attachments[]';
    input.accept = first.accept;
    input.style.marginTop = '6px';
    input.addEventListener('change', function () {
      if (this.files.length > 0) this.setAttribute('data-filled', '1');
      addAttachmentSlot();
    });
    wrap.appendChild(input);
  }
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
