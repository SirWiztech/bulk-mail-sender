<?php
/**
 * Processes one batch of pending emails for a campaign.
 * Can be called via AJAX (from campaign_view.php) or via CLI/cron:
 *   php send_batch.php <campaign_id>
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not logged in']);
        exit;
    }
}

$campaignId = $isCli ? (int)($argv[1] ?? 0) : (int)($_REQUEST['campaign_id'] ?? 0);
$pdo = get_db();

$stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id=?");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    $out = ['ok' => false, 'error' => 'Campaign not found'];
    echo $isCli ? print_r($out, true) : json_encode($out);
    exit;
}

// Respect pause: don't send if campaign isn't actively 'sending'
if ($campaign['status'] !== 'sending') {
    $counts = get_counts($pdo, $campaignId);
    $out = array_merge(['ok' => true, 'status' => $campaign['status']], $counts);
    echo $isCli ? print_r($out, true) : json_encode($out);
    exit;
}

// Load campaign attachments once (same files sent with every email in the campaign)
$attachments = [];
$attStmt = $pdo->prepare("SELECT filename, path FROM campaign_attachments WHERE campaign_id=?");
$attStmt->execute([$campaignId]);
foreach ($attStmt->fetchAll() as $a) {
    if (is_file($a['path'])) {
        $attachments[] = ['path' => $a['path'], 'name' => $a['filename']];
    }
}

// Pull one batch of pending sends
$batch = $pdo->prepare("
    SELECT s.id AS send_id, c.* FROM campaign_sends s
    JOIN contacts c ON c.id = s.contact_id
    WHERE s.campaign_id = ? AND s.status = 'pending'
    LIMIT " . (int)BATCH_SIZE
);
$batch->execute([$campaignId]);
$rows = $batch->fetchAll();

$updateSent = $pdo->prepare("UPDATE campaign_sends SET status='sent', sent_at=datetime('now') WHERE id=?");
$updateFailed = $pdo->prepare("UPDATE campaign_sends SET status='failed', error=? WHERE id=?");
$updateSkipped = $pdo->prepare("UPDATE campaign_sends SET status='skipped', error='unsubscribed before send' WHERE id=?");

foreach ($rows as $contact) {
    // Re-check status in case they unsubscribed mid-campaign
    if ($contact['status'] !== 'subscribed') {
        $updateSkipped->execute([$contact['send_id']]);
        continue;
    }

    $subject = render_template($campaign['subject'], $contact);
    $body = render_template($campaign['body_html'], $contact);

    [$ok, $err] = send_mail($contact['email'], $contact['name'], $subject, $body, $attachments);

    if ($ok) {
        $updateSent->execute([$contact['send_id']]);
    } else {
        $updateFailed->execute([$err, $contact['send_id']]);
    }

    if (BATCH_DELAY_MS > 0) {
        usleep(BATCH_DELAY_MS * 1000);
    }
}

// If nothing pending remains, mark campaign completed
$remaining = $pdo->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=? AND status='pending'");
$remaining->execute([$campaignId]);
if ((int)$remaining->fetchColumn() === 0) {
    $pdo->prepare("UPDATE campaigns SET status='completed', completed_at=datetime('now') WHERE id=? AND status='sending'")->execute([$campaignId]);
}

$statusStmt = $pdo->prepare("SELECT status FROM campaigns WHERE id=?");
$statusStmt->execute([$campaignId]);
$finalStatus = $statusStmt->fetchColumn();

$counts = get_counts($pdo, $campaignId);
$out = array_merge(['ok' => true, 'status' => $finalStatus, 'processed' => count($rows)], $counts);
echo $isCli ? print_r($out, true) : json_encode($out);

function get_counts(PDO $pdo, int $campaignId): array {
    $c = $pdo->prepare("SELECT status, COUNT(*) n FROM campaign_sends WHERE campaign_id=? GROUP BY status");
    $c->execute([$campaignId]);
    $counts = ['pending'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    foreach ($c->fetchAll() as $r) { $counts[$r['status']] = (int)$r['n']; }
    $total = array_sum($counts);
    return [
        'sent' => $counts['sent'],
        'failed' => $counts['failed'],
        'pending' => $counts['pending'],
        'skipped' => $counts['skipped'],
        'total' => $total,
    ];
}
