<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

final class AddInterests extends AbstractMigration {
  public function change(): void {
    // Check if column already exists
    $rows = $this->fetchAll("PRAGMA table_info(users)");
    $hasInterests = false;
    foreach ($rows as $row) {
      if ($row['name'] === 'interests') {
        $hasInterests = true;
        break;
      }
    }
    
    if (!$hasInterests) {
      $this->execute("ALTER TABLE users ADD COLUMN interests TEXT DEFAULT NULL");
    }
  }
}
