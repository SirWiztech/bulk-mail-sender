<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_db();
$active = 'dashboard';
$pageTitle = 'Dashboard';

$totalContacts = $pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$subscribed    = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status='subscribed'")->fetchColumn();
$unsubscribed  = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status='unsubscribed'")->fetchColumn();
$totalCampaigns= $pdo->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();
$totalSent     = $pdo->query("SELECT COUNT(*) FROM campaign_sends WHERE status='sent'")->fetchColumn();

$recentCampaigns = $pdo->query("SELECT c.*, l.name AS list_name,
    (SELECT COUNT(*) FROM campaign_sends s WHERE s.campaign_id=c.id AND s.status='sent') AS sent_count,
    (SELECT COUNT(*) FROM campaign_sends s WHERE s.campaign_id=c.id) AS total_count
    FROM campaigns c JOIN lists l ON l.id=c.list_id
    ORDER BY c.id DESC LIMIT 5")->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>
<h1>Dashboard</h1>
<p class="subtitle">Overview of your contacts and campaigns.</p>

<div class="stat-grid">
  <div class="stat"><div class="num"><?= (int)$totalContacts ?></div><div class="label">Total Contacts</div></div>
  <div class="stat"><div class="num"><?= (int)$subscribed ?></div><div class="label">Subscribed</div></div>
  <div class="stat"><div class="num"><?= (int)$unsubscribed ?></div><div class="label">Unsubscribed</div></div>
  <div class="stat"><div class="num"><?= (int)$totalCampaigns ?></div><div class="label">Campaigns</div></div>
  <div class="stat"><div class="num"><?= (int)$totalSent ?></div><div class="label">Emails Sent</div></div>
</div>

<h2>Recent Campaigns</h2>
<div class="card">
<?php if (!$recentCampaigns): ?>
  <p class="subtitle">No campaigns yet. <a href="campaign_new.php">Create your first campaign →</a></p>
<?php else: ?>
  <table>
    <tr><th>Name</th><th>List</th><th>Status</th><th>Progress</th><th>Created</th><th></th></tr>
    <?php foreach ($recentCampaigns as $c): ?>
      <tr>
        <td><?= h($c['name']) ?></td>
        <td><?= h($c['list_name']) ?></td>
        <td><span class="badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
        <td><?= (int)$c['sent_count'] ?> / <?= (int)$c['total_count'] ?></td>
        <td><?= h($c['created_at']) ?></td>
        <td><a href="campaign_view.php?id=<?= (int)$c['id'] ?>">View →</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
