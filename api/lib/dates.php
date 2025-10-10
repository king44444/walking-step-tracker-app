<?php
require_once __DIR__.'/env.php';

function now_in_tz(){
  $tz=new DateTimeZone(env('WALK_TZ','America/Denver'));
  return new DateTime('now',$tz);
}

function map_day_to_col($s){
  $m=strtolower(substr($s,0,3));
  return ['mon'=>'monday','tue'=>'tuesday','wed'=>'wednesday','thu'=>'thursday','fri'=>'friday','sat'=>'saturday'][$m]??null;
}

function resolve_target_day(DateTime $now,$overrideDay){
  if($overrideDay){$c=map_day_to_col($overrideDay);return $c?:null;}
  $hour=intval($now->format('H'));
  $t=clone $now;
  if($hour<12)$t->modify('-1 day');
  $w=intval($t->format('w')); // 0 Sun..6 Sat
  if($w===0)return 'saturday';
  return ['monday','tuesday','wednesday','thursday','friday','saturday'][$w-1]??null;
}

function resolve_active_week(PDO $pdo){
  $r=$pdo->query("SELECT week FROM weeks WHERE finalized=0 ORDER BY week DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  return $r['week']??null;
}

/**
 * Expand a week's step data (monday-saturday columns) into individual daily dates.
 * 
 * @param string $weekStart ISO date YYYY-MM-DD (the Monday)
 * @param array $daySteps Assoc array with keys: monday, tuesday, wednesday, thursday, friday, saturday (values can be null)
 * @return array Assoc array ['YYYY-MM-DD' => steps, ...] for 6 days (Mon-Sat)
 */
function expand_week_to_daily_dates(string $weekStart, array $daySteps): array {
  $result = [];
  $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
  
  try {
    $tz = new DateTimeZone(env('WALK_TZ', 'America/Denver'));
    $date = new DateTime($weekStart, $tz);
    
    foreach ($days as $day) {
      $dateStr = $date->format('Y-m-d');
      $steps = isset($daySteps[$day]) && $daySteps[$day] !== null ? (int)$daySteps[$day] : 0;
      $result[$dateStr] = $steps;
      $date->modify('+1 day');
    }
  } catch (Exception $e) {
    error_log('expand_week_to_daily_dates failed: ' . $e->getMessage());
  }
  
  return $result;
}
