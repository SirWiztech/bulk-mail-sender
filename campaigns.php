<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
$pdo = get_db();
$active = 'campaigns';
$pageTitle = 'Campaigns';

$campaigns = $pdo->query("
    SELECT c.*, l.name AS list_name,
      (SELECT COUNT(*) FROM campaign_sends s WHERE s.campaign_id=c.id AND s.status='sent') AS sent_count,
      (SELECT COUNT(*) FROM campaign_sends s WHERE s.campaign_id=c.id AND s.status='failed') AS failed_count,
      (SELECT COUNT(*) FROM campaign_sends s WHERE s.campaign_id=c.id) AS total_count
    FROM campaigns c JOIN lists l ON l.id = c.list_id
    ORDER BY c.id DESC
")->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>
<h1>Campaigns</h1>
<p class="subtitle">All your bulk email campaigns.</p>
<p><a class="btn" href="campaign_new.php">+ New Campaign</a></p>

<div class="card">
  <table>
    <tr><th>Name</th><th>List</th><th>Status</th><th>Progress</th><th>Created</th><th></th></tr>
    <?php foreach ($campaigns as $c): ?>
      <tr>
        <td><?= h($c['name']) ?></td>
        <td><?= h($c['list_name']) ?></td>
        <td><span class="badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
        <td><?= (int)$c['sent_count'] ?> sent<?= $c['failed_count'] ? ', ' . (int)$c['failed_count'] . ' failed' : '' ?> / <?= (int)$c['total_count'] ?></td>
        <td><?= h($c['created_at']) ?></td>
        <td><a href="campaign_view.php?id=<?= (int)$c['id'] ?>">View →</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$campaigns): ?><tr><td colspan="6" class="subtitle">No campaigns yet.</td></tr><?php endif; ?>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
