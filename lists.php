<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
$pdo = get_db();
$active = 'lists';
$pageTitle = 'Lists';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        try {
            $pdo->prepare("INSERT INTO lists (name) VALUES (?)")->execute([$name]);
            flash("List \"$name\" created.");
        } catch (PDOException $e) {
            flash("A list with that name already exists.", 'error');
        }
    }
    header('Location: lists.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM lists WHERE id=?")->execute([$id]);
    flash("List deleted.");
    header('Location: lists.php');
    exit;
}

$lists = $pdo->query("
    SELECT l.*, (SELECT COUNT(*) FROM contact_list cl WHERE cl.list_id = l.id) AS contact_count
    FROM lists l ORDER BY l.name
")->fetchAll();

require __DIR__ . '/includes/layout_top.php';
?>
<h1>Lists</h1>
<p class="subtitle">Group contacts into lists so you can target campaigns to specific segments.</p>

<div class="card">
  <h2 style="margin-top:0">Create a list</h2>
  <form method="post" style="display:flex; gap:10px; align-items:end;">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="add">
    <div style="flex:1"><label>List name</label><input type="text" name="name" required placeholder="e.g. Newsletter Subscribers"></div>
    <button class="btn" type="submit">Create</button>
  </form>
</div>

<div class="card">
  <table>
    <tr><th>Name</th><th>Contacts</th><th></th></tr>
    <?php foreach ($lists as $l): ?>
      <tr>
        <td><?= h($l['name']) ?></td>
        <td><?= (int)$l['contact_count'] ?></td>
        <td>
          <?php if ($l['name'] !== 'All Contacts'): ?>
          <form method="post" onsubmit="return confirm('Delete this list? Contacts will not be deleted.');" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn danger" style="margin:0;padding:4px 10px;font-size:12px" type="submit">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
