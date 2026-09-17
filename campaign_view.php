<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
$pdo = get_db();
$active = 'campaigns';
$pageTitle = 'Campaign';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, l.name AS list_name FROM campaigns c JOIN lists l ON l.id=c.list_id WHERE c.id=?");
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) { flash('Campaign not found.', 'error'); header('Location: campaigns.php'); exit; }

// Start sending: enqueue campaign_sends rows for every subscribed contact in the list (idempotent)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start') {
    csrf_check();
    $already = $pdo->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=?");
    $already->execute([$id]);
    if ($already->fetchColumn() == 0) {
        $contacts = $pdo->prepare("
            SELECT c.id FROM contacts c
            JOIN contact_list cl ON cl.contact_id = c.id
            WHERE cl.list_id = ? AND c.status = 'subscribed'
        ");
        $contacts->execute([$campaign['list_id']]);
        $insert = $pdo->prepare("INSERT INTO campaign_sends (campaign_id, contact_id) VALUES (?, ?)");
        $pdo->beginTransaction();
        foreach ($contacts->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $insert->execute([$id, $cid]);
        }
        $pdo->commit();
    }
    $pdo->prepare("UPDATE campaigns SET status='sending', started_at=datetime('now') WHERE id=?")->execute([$id]);
    header('Location: campaign_view.php?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pause') {
    csrf_check();
    $pdo->prepare("UPDATE campaigns SET status='paused' WHERE id=?")->execute([$id]);
    header('Location: campaign_view.php?id=' . $id);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resume') {
    csrf_check();
    $pdo->prepare("UPDATE campaigns SET status='sending' WHERE id=?")->execute([$id]);
    header('Location: campaign_view.php?id=' . $id);
    exit;
}

$counts = $pdo->prepare("
    SELECT status, COUNT(*) AS n FROM campaign_sends WHERE campaign_id=? GROUP BY status
");
$counts->execute([$id]);
$statusCounts = ['pending'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
foreach ($counts->fetchAll() as $row) { $statusCounts[$row['status']] = (int)$row['n']; }
$total = array_sum($statusCounts);

$failedRows = $pdo->prepare("
    SELECT c.email, s.error FROM campaign_sends s JOIN contacts c ON c.id = s.contact_id
    WHERE s.campaign_id=? AND s.status='failed' LIMIT 20
");
$failedRows->execute([$id]);
$failed = $failedRows->fetchAll();

// Attachments on this campaign
$attStmt = $pdo->prepare("SELECT * FROM campaign_attachments WHERE campaign_id=? ORDER BY id");
$attStmt->execute([$id]);
$attachments = $attStmt->fetchAll();

// Remove an attachment (draft campaigns only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_attachment') {
    csrf_check();
    if ($campaign['status'] !== 'draft') {
        flash('Attachments can only be changed while the campaign is a draft.', 'error');
    } else {
        $attId = (int)($_POST['attachment_id'] ?? 0);
        $del = $pdo->prepare("SELECT * FROM campaign_attachments WHERE id=? AND campaign_id=?");
        $del->execute([$attId, $id]);
        if ($row = $del->fetch()) {
            if (is_file($row['path'])) @unlink($row['path']);
            $pdo->prepare("DELETE FROM campaign_attachments WHERE id=?")->execute([$attId]);
            flash('Attachment removed.');
        }
    }
    header('Location: campaign_view.php?id=' . $id);
    exit;
}

require __DIR__ . '/includes/layout_top.php';
?>
<h1><?= h($campaign['name']) ?></h1>
<p class="subtitle">List: <?= h($campaign['list_name']) ?> &middot; Status: <span class="badge <?= h($campaign['status']) ?>"><?= h($campaign['status']) ?></span></p>

<div class="card">
  <h2 style="margin-top:0">Preview</h2>
  <p><strong>Subject:</strong> <?= h($campaign['subject']) ?></p>
  <?php if ($attachments): ?>
    <p><strong>Attachments:</strong></p>
    <ul style="margin-top:0">
      <?php foreach ($attachments as $a): ?>
        <li>
          <?= h($a['filename']) ?>
          <span class="help">(<?= round($a['size'] / 1024, 1) ?> KB)</span>
          <?php if ($campaign['status'] === 'draft'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this attachment?');">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="remove_attachment">
              <input type="hidden" name="attachment_id" value="<?= (int)$a['id'] ?>">
              <button class="btn danger" style="margin:0;padding:2px 8px;font-size:12px" type="submit">Remove</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <div style="border:1px solid var(--border); border-radius:6px; padding:16px; background:#fafbff;">
    <?= $campaign['body_html'] ?>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Sending</h2>

  <?php if ($campaign['status'] === 'draft'): ?>
    <p>This campaign hasn't been sent yet.</p>
    <form method="post" onsubmit="return confirm('Start sending this campaign to all subscribed contacts on this list?');">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="start">
      <button class="btn" type="submit">🚀 Start Sending</button>
    </form>
  <?php else: ?>
    <div id="progressWrap">
      <div class="progress-outer">
        <div class="progress-inner" id="progressBar" style="width: <?= $total ? round($statusCounts['sent'] / $total * 100) : 0 ?>%">
          <?= (int)$statusCounts['sent'] ?> / <?= (int)$total ?>
        </div>
      </div>
      <p class="help" id="progressText">
        Sent: <span id="sentCount"><?= (int)$statusCounts['sent'] ?></span> &middot;
        Failed: <span id="failedCount"><?= (int)$statusCounts['failed'] ?></span> &middot;
        Pending: <span id="pendingCount"><?= (int)$statusCounts['pending'] ?></span>
      </p>
    </div>

    <?php if ($campaign['status'] === 'sending'): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="pause">
        <button class="btn secondary" type="submit">Pause</button>
      </form>
    <?php elseif ($campaign['status'] === 'paused'): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="resume">
        <button class="btn" type="submit">Resume</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($failed): ?>
    <h2>Failed sends</h2>
    <table>
      <tr><th>Email</th><th>Error</th></tr>
      <?php foreach ($failed as $f): ?>
        <tr><td><?= h($f['email']) ?></td><td><?= h((string)$f['error']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php if ($campaign['status'] === 'sending'): ?>
<script>
async function pollBatch() {
  const res = await fetch('send_batch.php?campaign_id=<?= (int)$id ?>', { method: 'POST' });
  const data = await res.json();
  if (data.ok) {
    document.getElementById('sentCount').textContent = data.sent;
    document.getElementById('failedCount').textContent = data.failed;
    document.getElementById('pendingCount').textContent = data.pending;
    const pct = data.total ? Math.round((data.sent / data.total) * 100) : 0;
    const bar = document.getElementById('progressBar');
    bar.style.width = pct + '%';
    bar.textContent = data.sent + ' / ' + data.total;

    if (data.status === 'sending' && data.pending > 0) {
      setTimeout(pollBatch, 1200);
    } else if (data.pending === 0) {
      setTimeout(() => location.reload(), 800);
    }
  }
}
pollBatch();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
