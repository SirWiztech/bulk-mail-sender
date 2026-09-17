<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
$pdo = get_db();
$active = 'contacts';
$pageTitle = 'Import Contacts';

$lists = $pdo->query("SELECT * FROM lists ORDER BY name")->fetchAll();

$step = 'upload';
$headers = [];
$previewRows = [];
$tmpFile = '';

// Step 2: after upload, show column mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    csrf_check();
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $dest = UPLOAD_PATH . '/' . uniqid('import_') . '.csv';
        move_uploaded_file($_FILES['csv']['tmp_name'], $dest);
        $fh = fopen($dest, 'r');
        $headers = fgetcsv($fh);
        $count = 0;
        while (($row = fgetcsv($fh)) !== false && $count < 5) {
            $previewRows[] = $row;
            $count++;
        }
        fclose($fh);
        $step = 'map';
        $tmpFile = basename($dest);
    } else {
        flash('Please choose a CSV file to upload.', 'error');
    }
}

// Step 3: process import with mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_check();
    $file = UPLOAD_PATH . '/' . basename($_POST['tmp_file'] ?? '');
    $emailCol = (int)($_POST['email_col'] ?? -1);
    $nameCol  = $_POST['name_col'] === '' ? -1 : (int)$_POST['name_col'];
    $listId   = (int)($_POST['list_id'] ?? 0);
    $hasHeader = !empty($_POST['has_header']);

    $inserted = 0; $skipped = 0; $duplicates = 0;

    if (file_exists($file) && $emailCol >= 0) {
        $fh = fopen($file, 'r');
        $rowNum = 0;
        $insertContact = $pdo->prepare("INSERT INTO contacts (email, name) VALUES (?, ?)");
        $findContact = $pdo->prepare("SELECT id FROM contacts WHERE email = ?");
        $linkList = $pdo->prepare("INSERT OR IGNORE INTO contact_list (contact_id, list_id) VALUES (?, ?)");

        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            if ($hasHeader && $rowNum === 1) continue;

            $email = trim($row[$emailCol] ?? '');
            $name  = $nameCol >= 0 ? trim($row[$nameCol] ?? '') : '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }

            $findContact->execute([$email]);
            $existingId = $findContact->fetchColumn();

            if ($existingId) {
                $duplicates++;
                if ($listId) $linkList->execute([$existingId, $listId]);
                continue;
            }

            $insertContact->execute([$email, $name]);
            $newId = $pdo->lastInsertId();
            if ($listId) $linkList->execute([$newId, $listId]);
            $inserted++;
        }
        fclose($fh);
        @unlink($file);
    }

    flash("Import complete: $inserted new contacts added, $duplicates already existed (added to list), $skipped rows skipped (invalid email).");
    header('Location: contacts.php');
    exit;
}

require __DIR__ . '/includes/layout_top.php';
?>
<h1>Import Contacts from CSV</h1>
<p class="subtitle">Upload a CSV file, map its columns, and choose a list to add contacts to.</p>

<?php if ($step === 'upload'): ?>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="preview">
    <label>CSV File</label>
    <input type="file" name="csv" accept=".csv" required>
    <p class="help">The file should contain at least an email column. A header row is recommended.</p>
    <button class="btn" type="submit">Upload &amp; Preview</button>
  </form>
</div>
<?php else: ?>
<div class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="tmp_file" value="<?= h($tmpFile) ?>">

    <h2 style="margin-top:0">Preview</h2>
    <div style="overflow-x:auto">
    <table>
      <tr><?php foreach ($headers as $i => $h): ?><th>Col <?= $i ?>: <?= h($h) ?></th><?php endforeach; ?></tr>
      <?php foreach ($previewRows as $row): ?>
        <tr><?php foreach ($row as $cell): ?><td><?= h((string)$cell) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table>
    </div>

    <label><input type="checkbox" name="has_header" value="1" checked style="width:auto"> First row is a header (skip it)</label>

    <label>Which column is the Email address?</label>
    <select name="email_col" required>
      <?php foreach ($headers as $i => $hd): ?>
        <option value="<?= $i ?>"><?= h($hd) ?: 'Column ' . $i ?></option>
      <?php endforeach; ?>
    </select>

    <label>Which column is the Name? (optional)</label>
    <select name="name_col">
      <option value="">-- none --</option>
      <?php foreach ($headers as $i => $hd): ?>
        <option value="<?= $i ?>"><?= h($hd) ?: 'Column ' . $i ?></option>
      <?php endforeach; ?>
    </select>

    <label>Add all imported contacts to list</label>
    <select name="list_id">
      <?php foreach ($lists as $l): ?>
        <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <button class="btn" type="submit">Import Contacts</button>
    <a class="btn secondary" href="import_contacts.php">Cancel</a>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
