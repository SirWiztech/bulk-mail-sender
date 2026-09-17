<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
$pdo = get_db();
$active = 'contacts';
$pageTitle = 'Contacts';

// Handle add contact
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO contacts (email, name) VALUES (?, ?)");
            $stmt->execute([$email, $name]);
            $newId = $pdo->lastInsertId();
            // add to "All Contacts" list
            $listId = $pdo->query("SELECT id FROM lists WHERE name='All Contacts'")->fetchColumn();
            if ($listId) {
                $pdo->prepare("INSERT OR IGNORE INTO contact_list (contact_id, list_id) VALUES (?, ?)")->execute([$newId, $listId]);
            }
            flash("Contact $email added.");
        } catch (PDOException $e) {
            flash("Could not add contact: that email may already exist.", 'error');
        }
    } else {
        flash("Please enter a valid email address.", 'error');
    }
    header('Location: contacts.php');
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM contacts WHERE id=?")->execute([$id]);
    flash("Contact deleted.");
    header('Location: contacts.php');
    exit;
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    csrf_check();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM contacts WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        flash("$count contact" . ($count === 1 ? '' : 's') . " deleted.");
    } else {
        flash("No contacts were selected.", 'error');
    }
    header('Location: contacts.php');
    exit;
}

// Search / filter
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT * FROM contacts WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (email LIKE ? OR name LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}
if (in_array($statusFilter, ['subscribed','unsubscribed','bounced'], true)) {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>
<h1>Contacts</h1>
<p class="subtitle">Manage the people who will receive your campaigns.</p>

<div class="card">
  <h2 style="margin-top:0">Add a contact</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="add">
    <div style="display:flex; gap:16px;">
      <div style="flex:1"><label>Email</label><input type="email" name="email" required></div>
      <div style="flex:1"><label>Name</label><input type="text" name="name"></div>
    </div>
    <button class="btn" type="submit">Add Contact</button>
  </form>
  <p class="help">Have a big list? <a href="import_contacts.php">Import from CSV →</a></p>
</div>

<div class="card">
  <form method="get" style="display:flex; gap:10px; align-items:end; margin-bottom:16px;">
    <div style="flex:1"><label>Search</label><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name or email"></div>
    <div style="width:200px">
      <label>Status</label>
      <select name="status">
        <option value="">All</option>
        <option value="subscribed" <?= $statusFilter==='subscribed'?'selected':'' ?>>Subscribed</option>
        <option value="unsubscribed" <?= $statusFilter==='unsubscribed'?'selected':'' ?>>Unsubscribed</option>
        <option value="bounced" <?= $statusFilter==='bounced'?'selected':'' ?>>Bounced</option>
      </select>
    </div>
    <button class="btn secondary" type="submit">Filter</button>
  </form>

  <form method="post" id="bulkForm" style="display:flex; gap:10px; align-items:center; margin-bottom:12px;" onsubmit="return confirmBulkDelete();">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="bulk_delete">
    <button class="btn danger" type="submit" id="bulkDeleteBtn" disabled style="margin:0">Delete selected</button>
    <span class="help" id="selectedCount">0 selected</span>
  </form>

  <table>
    <tr>
      <th style="width:32px"><input type="checkbox" onchange="toggleAll(this)" title="Select all"></th>
      <th>Email</th><th>Name</th><th>Status</th><th>Added</th><th></th>
    </tr>
    <?php foreach ($contacts as $c): ?>
      <tr>
        <td><input type="checkbox" form="bulkForm" name="ids[]" value="<?= (int)$c['id'] ?>" onchange="updateSelected()"></td>
        <td><?= h($c['email']) ?></td>
        <td><?= h($c['name']) ?></td>
        <td><span class="badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
        <td><?= h($c['created_at']) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this contact?');" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn danger" style="margin:0;padding:4px 10px;font-size:12px" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$contacts): ?><tr><td colspan="6" class="subtitle">No contacts found.</td></tr><?php endif; ?>
  </table>
</div>

<script>
function toggleAll(master) {
  document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = master.checked; });
  updateSelected();
}
function updateSelected() {
  var boxes = document.querySelectorAll('input[name="ids[]"]');
  var selected = Array.prototype.filter.call(boxes, function (cb) { return cb.checked; }).length;
  document.getElementById('bulkDeleteBtn').disabled = selected === 0;
  document.getElementById('selectedCount').textContent = selected + ' selected';
}
function confirmBulkDelete() {
  var selected = document.querySelectorAll('input[name="ids[]"]:checked').length;
  if (selected === 0) return false;
  return confirm('Delete ' + selected + ' selected contact' + (selected === 1 ? '' : 's') + '? This cannot be undone.');
}
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
