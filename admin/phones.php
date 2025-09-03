<?php
require __DIR__.'/../api/util.php';
require __DIR__.'/../api/lib/phone.php';
$pdo = pdo();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  $name = $_POST['name'] ?? '';
  if ($action==='save') {
    $phone = $_POST['phone'] ?? '';
    $st = $pdo->prepare("UPDATE users SET phone_e164=? WHERE name=?");
    $st->execute([$phone ?: null, $name]);
  } elseif ($action==='normalize') {
    $phone = $_POST['phone'] ?? '';
    $norm = to_e164($phone);
    $st = $pdo->prepare("UPDATE users SET phone_e164=? WHERE name=?");
    $st->execute([$norm, $name]);
  } elseif ($action==='clear') {
    $st = $pdo->prepare("UPDATE users SET phone_e164=NULL WHERE name=?");
    $st->execute([$name]);
  }
  header('Location: phones.php'); exit;
}

$rows = $pdo->query("SELECT name, COALESCE(phone_e164,'') AS phone_e164 FROM users ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><meta charset="utf-8"><title>Phones</title>
<style>body{font-family:system-ui;margin:20px} table{border-collapse:collapse} td,th{border:1px solid #444;padding:6px 8px}</style>
<h1>Phones</h1>
<table>
<tr><th>Name</th><th>Phone</th><th>Actions</th><th>Test curl</th></tr>
<?php foreach($rows as $r): $n=htmlspecialchars($r['name']); $p=htmlspecialchars($r['phone_e164']); ?>
<tr>
  <td><?= $n ?></td>
  <td>
    <form method="post" style="display:inline">
      <input type="hidden" name="name" value="<?= $n ?>">
      <input name="phone" value="<?= $p ?>" placeholder="+18015551234">
      <button name="action" value="save">Save</button>
      <button name="action" value="normalize" type="submit">Normalize</button>
      <button name="action" value="clear" type="submit">Clear</button>
    </form>
  </td>
  <td></td>
  <td><code>curl -X POST https://mikebking.com/dev/html/walk/api/sms.php --data-urlencode "From=<?= $p ?: '+1XXXXXXXXXX' ?>" --data-urlencode "Body=123"</code></td>
</tr>
<?php endforeach; ?>
</table>
