<?php
function is_valid_daycol($c){return in_array($c,['monday','tuesday','wednesday','thursday','friday','saturday'],true);}

/**
 * upsert_steps
 * Insert or update a single day's steps for a person in a week.
 *
 * Behavior:
 * - dayCol must be a valid weekday column.
 * - $steps may be null or a non-negative integer.
 * - We intentionally write the day's numeric value and rely on DB triggers
 *   to set the corresponding *_reported_at timestamp only the first time
 *   a positive value is observed (see api/migrate.php triggers).
 */
function upsert_steps(PDO $pdo,$week,$name,$dayCol,$steps){
  if(!is_valid_daycol($dayCol)) throw new RuntimeException('bad day');

  // Normalize steps: allow null or integer >= 0
  if ($steps === null) {
    $val = null;
  } else {
    $val = (int)$steps;
    if ($val < 0) throw new RuntimeException('invalid steps');
  }

  $sql = "INSERT INTO entries(week,name,$dayCol) VALUES(:w,:n,:s)
          ON CONFLICT(week,name) DO UPDATE SET $dayCol=excluded.$dayCol, updated_at=(datetime('now'))";
  $st = $pdo->prepare($sql);
  $st->bindValue(':w', $week);
  $st->bindValue(':n', $name);
  if ($val === null) {
    $st->bindValue(':s', null, PDO::PARAM_NULL);
  } else {
    $st->bindValue(':s', $val, PDO::PARAM_INT);
  }
  $st->execute();
}
