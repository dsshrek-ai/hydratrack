<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// Runs a prepared statement and returns all rows (SELECT) or the statement's
// affected-row count wrapped in an array (anything else).
function q(string $sql, string $types = '', array $params = []): array {
  $stmt = db()->prepare($sql);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

function exec1(string $sql, string $types = '', array $params = []): int {
  $stmt = db()->prepare($sql);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

// ---- Auth: shared MyDataWorld login + an app_access grant for 'hydratrack' ----

const APP_KEY = 'hydratrack';
const APP_NAME = 'HydraTrack';

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $rows = q(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()',
    's', [$m[1]]
  );
  if (!$rows) {
    fail('Session expired or invalid -- please log in again', 401);
  }
  return $rows[0];
}

function hasAppAccess(array $user): bool {
  $rows = q(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?',
    'is', [(int)$user['id'], APP_KEY]
  );
  return (bool)$rows;
}

function logAppUsage(int $userId): void {
  try {
    exec1(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1',
      'is', [$userId, APP_KEY]
    );
  } catch (mysqli_sql_exception $e) {
    // best-effort
  }
}

function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess($user)) {
    fail('Not authorized for ' . APP_NAME, 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

// ---- Dates, time zone, settings ----

const WEEKDAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                       5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const MAX_OUNCES_PER_ENTRY = 1000;

function utc(): DateTimeZone {
  static $z = null;
  return $z ?? ($z = new DateTimeZone('UTC'));
}

// 1 = Monday ... 7 = Sunday for a 'Y-m-d' date.
function weekdayOf(string $date): int {
  return (int)(new DateTime($date, utc()))->format('N');
}

function addDays(string $date, int $days): string {
  return (new DateTime($date, utc()))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
}

function validDate(?string $s): bool {
  if ($s === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
    return false;
  }
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  return checkdate($m, $d, $y);
}

// Loads (creating on first use) this user's settings, adopting the browser's
// time zone when it sends a valid one. The user's local "today" drives every
// log date, so it is computed from their zone, never the server's.
function userContext(int $userId, string $tzParam): array {
  $tzParam = trim($tzParam);
  $validTz = $tzParam !== '' && in_array($tzParam, DateTimeZone::listIdentifiers(), true);
  $rows = q('SELECT time_zone, start_date FROM hydra_user_settings WHERE user_id = ?', 'i', [$userId]);
  if (!$rows) {
    $tzName = $validTz ? $tzParam : 'America/Denver';
    $start = (new DateTime('now', new DateTimeZone($tzName)))->format('Y-m-d');
    exec1(
      'INSERT INTO hydra_user_settings (user_id, time_zone, start_date) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE user_id = user_id',
      'iss', [$userId, $tzName, $start]
    );
    $rows = q('SELECT time_zone, start_date FROM hydra_user_settings WHERE user_id = ?', 'i', [$userId]);
  } elseif ($validTz && $rows[0]['time_zone'] !== $tzParam) {
    exec1('UPDATE hydra_user_settings SET time_zone = ? WHERE user_id = ?', 'si', [$tzParam, $userId]);
    $rows[0]['time_zone'] = $tzParam;
  }
  $tz = new DateTimeZone($rows[0]['time_zone']);
  $today = (new DateTime('now', $tz))->format('Y-m-d');
  $start = min($rows[0]['start_date'], $today);
  return ['userId' => $userId, 'tz' => $tz, 'today' => $today, 'start' => $start];
}

function clockTime(string $utcDateTime, DateTimeZone $tz): string {
  return (new DateTime($utcDateTime, utc()))->setTimezone($tz)->format('g:i A');
}

// ---- Goals applicable to a date (spec 4.2 / 12) ----

function goalsApplicableOn(int $userId, string $date): array {
  $wd = weekdayOf($date);
  return q(
    'SELECT g.id, g.container_id, g.quantity, g.target_ounces, g.sort_order
     FROM hydra_goals g
     JOIN hydra_goal_weekdays w ON w.goal_id = g.id AND w.weekday_number = ?
     WHERE g.user_id = ? AND g.is_active = 1
       AND g.start_date <= ? AND (g.end_date IS NULL OR g.end_date >= ?)
     ORDER BY g.sort_order, g.id',
    'iiss', [$wd, $userId, $date, $date]
  );
}

// ---- Daily recalculation ----
// $refreshTargets = true re-snapshots which goals apply and their targets
// (used for today, and for filling days as they first come around);
// false keeps the day's stored targets and only recounts what was logged.

function recalcDay(int $userId, string $date, bool $refreshTargets): void {
  $wd = weekdayOf($date);

  if ($refreshTargets) {
    $goals = goalsApplicableOn($userId, $date);
    $keep = [];
    foreach ($goals as $g) {
      $keep[] = (int)$g['id'];
      $containerId = $g['container_id'] !== null ? (int)$g['container_id'] : null;
      $qty = $g['quantity'] !== null ? (float)$g['quantity'] : null;
      $target = (float)$g['target_ounces'];
      $sort = (int)$g['sort_order'];
      $goalId = (int)$g['id'];
      exec1(
        'INSERT INTO hydra_daily_goal_progress
           (user_id, progress_date, weekday_number, goal_id, container_id, sort_order, target_quantity, target_ounces)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE weekday_number = VALUES(weekday_number), container_id = VALUES(container_id),
           sort_order = VALUES(sort_order), target_quantity = VALUES(target_quantity),
           target_ounces = VALUES(target_ounces)',
        'isiiiidd', [$userId, $date, $wd, $goalId, $containerId, $sort, $qty, $target]
      );
    }
    if ($keep) {
      $in = implode(',', $keep);
      exec1("DELETE FROM hydra_daily_goal_progress WHERE user_id = ? AND progress_date = ? AND goal_id NOT IN ($in)",
            'is', [$userId, $date]);
    } else {
      exec1('DELETE FROM hydra_daily_goal_progress WHERE user_id = ? AND progress_date = ?', 'is', [$userId, $date]);
    }
  }

  $logs = q(
    'SELECT container_id, entry_type, ounces, logged_at FROM hydra_water_logs
     WHERE user_id = ? AND log_date = ? ORDER BY logged_at, id',
    'is', [$userId, $date]
  );
  $consumed = 0.0;
  $first = null;
  $last = null;
  $quickByContainer = [];
  foreach ($logs as $l) {
    $oz = (float)$l['ounces'];
    $consumed += $oz;
    $first = $first ?? $l['logged_at'];
    $last = $l['logged_at'];
    if ($l['entry_type'] === 'QuickLog' && $l['container_id'] !== null) {
      $quickByContainer[(int)$l['container_id']][] = $oz;
    }
  }

  // Container goals take their container's quick logs in goal order, each
  // filling up to its quantity; the last goal for a container keeps any extras.
  // Direct-ounce goals then share whatever ounces no container goal claimed
  // (manual entries, containers with no goal, and so on).
  $progress = q(
    'SELECT id, container_id, target_quantity, target_ounces FROM hydra_daily_goal_progress
     WHERE user_id = ? AND progress_date = ? ORDER BY sort_order, goal_id',
    'is', [$userId, $date]
  );
  $byContainer = [];
  $direct = [];
  foreach ($progress as $i => $p) {
    if ($p['container_id'] !== null) {
      $byContainer[(int)$p['container_id']][] = $i;
    } else {
      $direct[] = $i;
    }
  }
  $result = [];
  $attributed = 0.0;
  foreach ($byContainer as $containerId => $idxs) {
    $pours = $quickByContainer[$containerId] ?? [];
    $pos = 0;
    foreach ($idxs as $n => $i) {
      $need = (float)$progress[$i]['target_quantity'];
      $isLast = $n === count($idxs) - 1;
      $count = 0;
      $oz = 0.0;
      while ($pos < count($pours) && ($isLast || $count < $need)) {
        $count++;
        $oz += $pours[$pos];
        $pos++;
      }
      $attributed += $oz;
      $result[$i] = [$count, $oz, $count >= $need];
    }
  }
  $remaining = max(0.0, $consumed - $attributed);
  foreach ($direct as $n => $i) {
    $target = (float)$progress[$i]['target_ounces'];
    $isLast = $n === count($direct) - 1;
    $oz = $isLast ? $remaining : min($remaining, $target);
    $remaining -= $oz;
    $result[$i] = [0, $oz, $oz >= $target];
  }

  $targetTotal = 0.0;
  foreach ($progress as $i => $p) {
    $targetTotal += (float)$p['target_ounces'];
    [$count, $oz, $done] = $result[$i];
    $doneInt = $done ? 1 : 0;
    $count = (float)$count;
    $rowId = (int)$p['id'];
    exec1('UPDATE hydra_daily_goal_progress SET completed_quantity = ?, logged_ounces = ?, goal_completed = ? WHERE id = ?',
          'ddii', [$count, $oz, $doneInt, $rowId]);
  }

  $percent = $targetTotal > 0 ? min(99999.0, round($consumed / $targetTotal * 100, 2)) : 0.0;
  $goalMet = ($targetTotal > 0 && $consumed >= $targetTotal) ? 1 : 0;
  $entryMade = $logs ? 1 : 0;
  exec1(
    'INSERT INTO hydra_daily_summary
       (user_id, summary_date, weekday_number, target_ounces, consumed_ounces, percent_complete,
        goal_met, entry_made, first_log_at, last_log_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE target_ounces = VALUES(target_ounces), consumed_ounces = VALUES(consumed_ounces),
       percent_complete = VALUES(percent_complete), goal_met = VALUES(goal_met), entry_made = VALUES(entry_made),
       first_log_at = VALUES(first_log_at), last_log_at = VALUES(last_log_at)',
    'isidddiiss', [$userId, $date, $wd, $targetTotal, $consumed, $percent, $goalMet, $entryMade, $first, $last]
  );
}

// Makes sure there is a summary row for every day from the user's start date
// through today. A day that comes around with no activity gets its target
// snapshotted from the goals in effect (nothing can have changed them since,
// because every goal edit runs this first).
function ensureDays(array $ctx): void {
  $userId = $ctx['userId'];
  $rows = q('SELECT MAX(summary_date) AS last FROM hydra_daily_summary WHERE user_id = ?', 'i', [$userId]);
  $last = $rows[0]['last'] ?? null;
  $from = $last ? addDays($last, 1) : $ctx['start'];
  for ($d = $from; $d <= $ctx['today']; $d = addDays($d, 1)) {
    recalcDay($userId, $d, true);
  }
}

// ---- Stats / streaks (spec 8, 16) ----
// Logging streak: consecutive days with an entry. Goal streak: consecutive
// days that had a target and met it; days with no target are skipped (they
// neither count nor break it). Today never breaks a streak just because it
// isn't over yet.

function recalcStats(array $ctx): array {
  $userId = $ctx['userId'];
  $today = $ctx['today'];
  $rows = q(
    'SELECT summary_date, target_ounces, consumed_ounces, goal_met, entry_made
     FROM hydra_daily_summary WHERE user_id = ? AND summary_date <= ? ORDER BY summary_date',
    'is', [$userId, $today]
  );

  $logRun = 0; $logPrev = 0; $logLongest = 0;
  $goalRun = 0; $goalPrev = 0; $goalLongest = 0;
  $lastApplicable = null; $lastApplicableMet = false;
  $totalOz = 0.0; $daysLogged = 0; $goalsMet = 0; $applicableDays = 0;
  $lastRow = null;

  foreach ($rows as $r) {
    $entry = (int)$r['entry_made'] === 1;
    $met = (int)$r['goal_met'] === 1;
    $applicable = (float)$r['target_ounces'] > 0;
    $totalOz += (float)$r['consumed_ounces'];

    $logPrev = $logRun;
    $logRun = $entry ? $logRun + 1 : 0;
    $logLongest = max($logLongest, $logRun);
    if ($entry) { $daysLogged++; }

    if ($applicable) {
      $goalPrev = $goalRun;
      $goalRun = $met ? $goalRun + 1 : 0;
      $goalLongest = max($goalLongest, $goalRun);
      $lastApplicable = $r['summary_date'];
      $lastApplicableMet = $met;
      if ($met) { $goalsMet++; }
      if ($met || $r['summary_date'] !== $today) { $applicableDays++; }
    }
    $lastRow = $r;
  }

  $todayOpen = $lastRow && $lastRow['summary_date'] === $today;
  $currentLog = ($todayOpen && (int)$lastRow['entry_made'] === 0) ? $logPrev : $logRun;
  $currentGoal = ($lastApplicable === $today && !$lastApplicableMet) ? $goalPrev : $goalRun;

  exec1(
    'INSERT INTO hydra_user_stats
       (user_id, current_logging_streak, longest_logging_streak, current_goal_streak, longest_goal_streak,
        total_ounces_logged, total_days_logged, total_goals_met, last_calculated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE current_logging_streak = VALUES(current_logging_streak),
       longest_logging_streak = VALUES(longest_logging_streak), current_goal_streak = VALUES(current_goal_streak),
       longest_goal_streak = VALUES(longest_goal_streak), total_ounces_logged = VALUES(total_ounces_logged),
       total_days_logged = VALUES(total_days_logged), total_goals_met = VALUES(total_goals_met),
       last_calculated_at = VALUES(last_calculated_at)',
    'iiiiidii', [$userId, $currentLog, $logLongest, $currentGoal, $goalLongest, $totalOz, $daysLogged, $goalsMet]
  );

  return [
    'CurrentLoggingStreak' => $currentLog,
    'LongestLoggingStreak' => $logLongest,
    'CurrentGoalStreak' => $currentGoal,
    'LongestGoalStreak' => $goalLongest,
    'TotalOuncesLogged' => round($totalOz, 2),
    'TotalDaysLogged' => $daysLogged,
    'TotalGoalsMet' => $goalsMet,
    // Per day that had an entry, and met / completed target days (today only
    // once it's met, since it isn't over yet).
    'AverageDailyOunces' => $daysLogged ? round($totalOz / $daysLogged, 1) : 0,
    'GoalCompletionRate' => $applicableDays ? round($goalsMet / $applicableDays * 100, 1) : 0,
    'TrackingSince' => $ctx['start'],
  ];
}

// ---- Containers ----

function containerRow(array $r): array {
  return [
    'Id' => (int)$r['id'],
    'Name' => $r['container_name'],
    'Ounces' => (float)$r['volume_ounces'],
    'Icon' => (string)($r['icon_key'] ?? ''),
    'SortOrder' => (int)$r['sort_order'],
    'QuickLog' => (int)$r['is_quick_log'] === 1,
    'Active' => (int)$r['is_active'] === 1,
  ];
}

function listContainers(int $userId): array {
  $rows = q('SELECT * FROM hydra_containers WHERE user_id = ? ORDER BY is_active DESC, sort_order, container_name, id',
            'i', [$userId]);
  return array_map('containerRow', $rows);
}

function ownContainer(int $userId, int $id): array {
  $rows = q('SELECT * FROM hydra_containers WHERE id = ? AND user_id = ?', 'ii', [$id, $userId]);
  if (!$rows) {
    fail('Container not found', 404);
  }
  return $rows[0];
}

function saveContainer(array $ctx, array $b): int {
  $userId = $ctx['userId'];
  $id = (int)($b['id'] ?? 0);
  $name = trim((string)($b['name'] ?? ''));
  $oz = round((float)($b['ounces'] ?? 0), 2);
  $icon = trim((string)($b['icon'] ?? ''));
  $iconVal = $icon === '' ? null : mb_substr($icon, 0, 50);
  $sort = (int)($b['sortOrder'] ?? 0);
  $quick = !empty($b['quickLog']) ? 1 : 0;
  $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
  if ($name === '') {
    fail('Container name is required');
  }
  if ($oz <= 0 || $oz > MAX_OUNCES_PER_ENTRY) {
    fail('Ounces must be between 0 and ' . MAX_OUNCES_PER_ENTRY);
  }
  $name = mb_substr($name, 0, 100);

  if ($id > 0) {
    $old = ownContainer($userId, $id);
    exec1(
      'UPDATE hydra_containers SET container_name = ?, volume_ounces = ?, icon_key = ?, sort_order = ?,
         is_quick_log = ?, is_active = ? WHERE id = ? AND user_id = ?',
      'sdsiiiii', [$name, $oz, $iconVal, $sort, $quick, $active, $id, $userId]
    );
    // Goals built on this container follow its new size from today on; past
    // days keep the targets they had (they're snapshots), and logs keep the
    // ounces actually recorded.
    if ((float)$old['volume_ounces'] !== $oz) {
      exec1('UPDATE hydra_goals SET target_ounces = ROUND(quantity * ?, 2) WHERE user_id = ? AND container_id = ?',
            'dii', [$oz, $userId, $id]);
      recalcDay($userId, $ctx['today'], true);
    }
    return $id;
  }
  return exec1(
    'INSERT INTO hydra_containers (user_id, container_name, volume_ounces, icon_key, sort_order, is_quick_log, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    'isdsiii', [$userId, $name, $oz, $iconVal, $sort, $quick, $active]
  );
}

// ---- Goals ----

function listGoals(int $userId): array {
  $rows = q(
    'SELECT g.*, c.container_name, c.volume_ounces, c.icon_key, c.is_active AS container_active
     FROM hydra_goals g LEFT JOIN hydra_containers c ON c.id = g.container_id
     WHERE g.user_id = ? ORDER BY g.is_active DESC, g.sort_order, g.id',
    'i', [$userId]
  );
  $days = q(
    'SELECT w.goal_id, w.weekday_number FROM hydra_goal_weekdays w
     JOIN hydra_goals g ON g.id = w.goal_id WHERE g.user_id = ? ORDER BY w.weekday_number',
    'i', [$userId]
  );
  $byGoal = [];
  foreach ($days as $d) {
    $byGoal[(int)$d['goal_id']][] = (int)$d['weekday_number'];
  }
  return array_map(function ($r) use ($byGoal) {
    $isContainer = $r['container_id'] !== null;
    return [
      'Id' => (int)$r['id'],
      'Name' => (string)($r['goal_name'] ?? ''),
      'ContainerId' => $isContainer ? (int)$r['container_id'] : null,
      'ContainerName' => $isContainer ? $r['container_name'] : null,
      'ContainerOunces' => $isContainer ? (float)$r['volume_ounces'] : null,
      'ContainerIcon' => $isContainer ? (string)($r['icon_key'] ?? '') : '',
      'ContainerActive' => $isContainer ? (int)$r['container_active'] === 1 : null,
      'Quantity' => $r['quantity'] !== null ? (float)$r['quantity'] : null,
      'TargetOunces' => (float)$r['target_ounces'],
      'Weekdays' => $byGoal[(int)$r['id']] ?? [],
      'StartDate' => $r['start_date'],
      'EndDate' => $r['end_date'],
      'Active' => (int)$r['is_active'] === 1,
      'SortOrder' => (int)$r['sort_order'],
    ];
  }, $rows);
}

function saveGoal(array $ctx, array $b): int {
  $userId = $ctx['userId'];
  $id = (int)($b['id'] ?? 0);
  $name = trim((string)($b['name'] ?? ''));
  $nameVal = $name === '' ? null : mb_substr($name, 0, 150);
  $containerId = (int)($b['containerId'] ?? 0);
  $sort = (int)($b['sortOrder'] ?? 0);
  $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;

  if ($containerId > 0) {
    $container = ownContainer($userId, $containerId);
    $qty = round((float)($b['quantity'] ?? 0), 2);
    if ($qty <= 0 || $qty > 100) {
      fail('Quantity must be between 0 and 100');
    }
    $target = round($qty * (float)$container['volume_ounces'], 2);
    $containerVal = $containerId;
    $qtyVal = $qty;
  } else {
    $target = round((float)($b['targetOunces'] ?? 0), 2);
    if ($target <= 0 || $target > 10000) {
      fail('Target ounces must be between 0 and 10000');
    }
    $containerVal = null;
    $qtyVal = null;
  }

  $weekdays = array_values(array_unique(array_filter(array_map('intval', (array)($b['weekdays'] ?? [])),
    function ($d) { return $d >= 1 && $d <= 7; })));
  sort($weekdays);
  if (!$weekdays) {
    fail('Pick at least one day');
  }

  $start = (string)($b['startDate'] ?? '');
  if ($start === '') {
    $start = $ctx['today'];
  }
  if (!validDate($start)) {
    fail('Start date is not a valid date');
  }
  $end = trim((string)($b['endDate'] ?? ''));
  $endVal = null;
  if ($end !== '') {
    if (!validDate($end)) {
      fail('End date is not a valid date');
    }
    if ($end < $start) {
      fail('End date must be on or after the start date');
    }
    $endVal = $end;
  }

  $conn = db();
  $conn->begin_transaction();
  if ($id > 0) {
    if (!q('SELECT 1 FROM hydra_goals WHERE id = ? AND user_id = ?', 'ii', [$id, $userId])) {
      $conn->rollback();
      fail('Goal not found', 404);
    }
    exec1(
      'UPDATE hydra_goals SET goal_name = ?, container_id = ?, quantity = ?, target_ounces = ?, start_date = ?,
         end_date = ?, is_active = ?, sort_order = ? WHERE id = ? AND user_id = ?',
      'siddssiiii', [$nameVal, $containerVal, $qtyVal, $target, $start, $endVal, $active, $sort, $id, $userId]
    );
    exec1('DELETE FROM hydra_goal_weekdays WHERE goal_id = ?', 'i', [$id]);
  } else {
    $id = exec1(
      'INSERT INTO hydra_goals (user_id, goal_name, container_id, quantity, target_ounces, start_date, end_date,
         is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      'isiddssii', [$userId, $nameVal, $containerVal, $qtyVal, $target, $start, $endVal, $active, $sort]
    );
  }
  foreach ($weekdays as $d) {
    $dayName = WEEKDAY_NAMES[$d];
    exec1('INSERT INTO hydra_goal_weekdays (goal_id, weekday_number, weekday_name) VALUES (?, ?, ?)',
          'iis', [$id, $d, $dayName]);
  }
  $conn->commit();
  recalcDay($userId, $ctx['today'], true);
  return $id;
}

// ---- Logging ----

function insertLog(array $ctx, ?int $containerId, ?int $goalId, float $ounces, string $type, ?string $notes): int {
  $today = $ctx['today'];
  $wd = weekdayOf($today);
  $now = gmdate('Y-m-d H:i:s');
  $id = exec1(
    'INSERT INTO hydra_water_logs (user_id, logged_at, log_date, weekday_number, container_id, goal_id, ounces,
       entry_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'issiiidss', [$ctx['userId'], $now, $today, $wd, $containerId, $goalId, $ounces, $type, $notes]
  );
  recalcDay($ctx['userId'], $today, false);
  return $id;
}

function quickLog(array $ctx, int $containerId): int {
  $c = ownContainer($ctx['userId'], $containerId);
  if ((int)$c['is_active'] !== 1) {
    fail('That container is inactive');
  }
  // Record which goal this pour counts toward: the first of today's goals for
  // this container that still needs one, else the last of them, else none.
  $rows = q(
    'SELECT goal_id, target_quantity, completed_quantity FROM hydra_daily_goal_progress
     WHERE user_id = ? AND progress_date = ? AND container_id = ? ORDER BY sort_order, goal_id',
    'isi', [$ctx['userId'], $ctx['today'], $containerId]
  );
  $goalId = null;
  foreach ($rows as $r) {
    $goalId = (int)$r['goal_id'];
    if ((float)$r['completed_quantity'] < (float)$r['target_quantity']) {
      break;
    }
  }
  return insertLog($ctx, $containerId, $goalId, (float)$c['volume_ounces'], 'QuickLog', null);
}

function deleteLog(array $ctx, int $id): void {
  $rows = q('SELECT log_date FROM hydra_water_logs WHERE id = ? AND user_id = ?', 'ii', [$id, $ctx['userId']]);
  if (!$rows) {
    fail('Entry not found', 404);
  }
  exec1('DELETE FROM hydra_water_logs WHERE id = ? AND user_id = ?', 'ii', [$id, $ctx['userId']]);
  recalcDay($ctx['userId'], $rows[0]['log_date'], false);
}

// ---- Read models ----

function todayView(array $ctx): array {
  $userId = $ctx['userId'];
  $today = $ctx['today'];
  $sum = q('SELECT * FROM hydra_daily_summary WHERE user_id = ? AND summary_date = ?', 'is', [$userId, $today])[0];

  $progress = q(
    'SELECT p.*, g.goal_name, c.container_name, c.volume_ounces, c.icon_key
     FROM hydra_daily_goal_progress p
     JOIN hydra_goals g ON g.id = p.goal_id
     LEFT JOIN hydra_containers c ON c.id = p.container_id
     WHERE p.user_id = ? AND p.progress_date = ? ORDER BY p.sort_order, p.goal_id',
    'is', [$userId, $today]
  );
  $goals = array_map(function ($p) {
    $isContainer = $p['container_id'] !== null;
    return [
      'GoalId' => (int)$p['goal_id'],
      'Name' => (string)($p['goal_name'] ?? ''),
      'Kind' => $isContainer ? 'container' : 'ounces',
      'ContainerId' => $isContainer ? (int)$p['container_id'] : null,
      'ContainerName' => $isContainer ? $p['container_name'] : null,
      'ContainerOunces' => $isContainer ? (float)$p['volume_ounces'] : null,
      'Icon' => $isContainer ? (string)($p['icon_key'] ?? '') : '',
      'TargetQuantity' => $p['target_quantity'] !== null ? (float)$p['target_quantity'] : null,
      'CompletedQuantity' => (float)$p['completed_quantity'],
      'TargetOunces' => (float)$p['target_ounces'],
      'LoggedOunces' => (float)$p['logged_ounces'],
      'Completed' => (int)$p['goal_completed'] === 1,
    ];
  }, $progress);

  $logs = q(
    'SELECT l.id, l.logged_at, l.ounces, l.entry_type, l.notes, c.container_name, c.icon_key
     FROM hydra_water_logs l LEFT JOIN hydra_containers c ON c.id = l.container_id
     WHERE l.user_id = ? AND l.log_date = ? ORDER BY l.logged_at DESC, l.id DESC',
    'is', [$userId, $today]
  );
  $tz = $ctx['tz'];
  $entries = array_map(function ($l) use ($tz) {
    return [
      'Id' => (int)$l['id'],
      'Time' => clockTime($l['logged_at'], $tz),
      'Ounces' => (float)$l['ounces'],
      'Type' => $l['entry_type'],
      'ContainerName' => $l['container_name'],
      'Icon' => (string)($l['icon_key'] ?? ''),
      'Notes' => (string)($l['notes'] ?? ''),
    ];
  }, $logs);

  $quick = array_values(array_filter(listContainers($userId), function ($c) {
    return $c['Active'] && $c['QuickLog'];
  }));

  return [
    'ok' => true,
    'Date' => $today,
    'Weekday' => weekdayOf($today),
    'TargetOunces' => (float)$sum['target_ounces'],
    'ConsumedOunces' => (float)$sum['consumed_ounces'],
    'PercentComplete' => (float)$sum['percent_complete'],
    'GoalMet' => (int)$sum['goal_met'] === 1,
    'Goals' => $goals,
    'Entries' => $entries,
    'QuickLog' => $quick,
    'HasContainers' => (bool)q('SELECT 1 FROM hydra_containers WHERE user_id = ? LIMIT 1', 'i', [$userId]),
    'Stats' => recalcStats($ctx),
  ];
}

const HISTORY_RANGES = ['30' => 30, '60' => 60, '90' => 90, '365' => 365, 'all' => 0];

function historyView(array $ctx, string $range): array {
  $days = HISTORY_RANGES[$range] ?? 30;
  $from = $days > 0 ? max($ctx['start'], addDays($ctx['today'], -($days - 1))) : $ctx['start'];
  $rows = q(
    'SELECT summary_date, target_ounces, consumed_ounces, percent_complete, goal_met, entry_made
     FROM hydra_daily_summary WHERE user_id = ? AND summary_date BETWEEN ? AND ? ORDER BY summary_date',
    'iss', [$ctx['userId'], $from, $ctx['today']]
  );
  return [
    'ok' => true,
    'Range' => $days > 0 ? (string)$days : 'all',
    'From' => $from,
    'To' => $ctx['today'],
    'Days' => array_map(function ($r) {
      return [
        'Date' => $r['summary_date'],
        'TargetOunces' => (float)$r['target_ounces'],
        'ConsumedOunces' => (float)$r['consumed_ounces'],
        'PercentComplete' => (float)$r['percent_complete'],
        'GoalMet' => (int)$r['goal_met'] === 1,
        'EntryMade' => (int)$r['entry_made'] === 1,
      ];
    }, $rows),
  ];
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');
$tzParam = (string)($method === 'GET' ? ($_GET['tz'] ?? '') : ($body['tz'] ?? ''));

// Every member action starts the same way: check the login, load the user's
// settings/local date, and make sure today's (and any missed days') summary
// rows exist before reading or changing anything.
function memberContext(string $tzParam): array {
  $user = requireMember();
  $ctx = userContext((int)$user['id'], $tzParam);
  ensureDays($ctx);
  return $ctx;
}

switch ($action) {

  case 'today': {
    respond(todayView(memberContext($tzParam)));
  }

  case 'history': {
    $ctx = memberContext($tzParam);
    respond(historyView($ctx, (string)($_GET['range'] ?? '30')));
  }

  case 'stats': {
    $ctx = memberContext($tzParam);
    respond(['ok' => true, 'Stats' => recalcStats($ctx)]);
  }

  case 'containers': {
    $ctx = memberContext($tzParam);
    respond(['ok' => true, 'Containers' => listContainers($ctx['userId'])]);
  }

  case 'goals': {
    $ctx = memberContext($tzParam);
    respond(['ok' => true, 'Goals' => listGoals($ctx['userId']), 'Containers' => listContainers($ctx['userId'])]);
  }

  case 'saveContainer': {
    $ctx = memberContext($tzParam);
    respond(['ok' => true, 'Id' => saveContainer($ctx, $body)]);
  }

  case 'setContainerActive': {
    $ctx = memberContext($tzParam);
    $id = (int)($body['id'] ?? 0);
    ownContainer($ctx['userId'], $id);
    $active = !empty($body['active']) ? 1 : 0;
    exec1('UPDATE hydra_containers SET is_active = ? WHERE id = ? AND user_id = ?', 'iii', [$active, $id, $ctx['userId']]);
    respond(['ok' => true]);
  }

  case 'saveGoal': {
    $ctx = memberContext($tzParam);
    respond(['ok' => true, 'Id' => saveGoal($ctx, $body)]);
  }

  case 'setGoalActive': {
    $ctx = memberContext($tzParam);
    $id = (int)($body['id'] ?? 0);
    if (!q('SELECT 1 FROM hydra_goals WHERE id = ? AND user_id = ?', 'ii', [$id, $ctx['userId']])) {
      fail('Goal not found', 404);
    }
    $active = !empty($body['active']) ? 1 : 0;
    exec1('UPDATE hydra_goals SET is_active = ? WHERE id = ? AND user_id = ?', 'iii', [$active, $id, $ctx['userId']]);
    recalcDay($ctx['userId'], $ctx['today'], true);
    respond(['ok' => true]);
  }

  case 'quickLog': {
    $ctx = memberContext($tzParam);
    $id = quickLog($ctx, (int)($body['containerId'] ?? 0));
    respond(['ok' => true, 'LogId' => $id, 'Today' => todayView($ctx)]);
  }

  case 'logOunces': {
    $ctx = memberContext($tzParam);
    $oz = round((float)($body['ounces'] ?? 0), 2);
    if ($oz <= 0 || $oz > MAX_OUNCES_PER_ENTRY) {
      fail('Enter between 0 and ' . MAX_OUNCES_PER_ENTRY . ' ounces');
    }
    $notes = trim((string)($body['notes'] ?? ''));
    $id = insertLog($ctx, null, null, $oz, 'Manual', $notes === '' ? null : mb_substr($notes, 0, 500));
    respond(['ok' => true, 'LogId' => $id, 'Today' => todayView($ctx)]);
  }

  // Also serves Undo: the Today screen deletes the entry it just created.
  // Logging actions return the refreshed Today view so one tap = one request.
  case 'deleteLog': {
    $ctx = memberContext($tzParam);
    deleteLog($ctx, (int)($body['id'] ?? 0));
    respond(['ok' => true, 'Today' => todayView($ctx)]);
  }

  // -- MyDataWorld login (shared with the other apps) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $rows = q('SELECT id, password_hash, display_name FROM users WHERE username = ?', 's', [$username]);
    $user = $rows[0] ?? null;
    if (!$user || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $userId = (int)$user['id'];
    $days = SESSION_LIFETIME_DAYS;
    exec1('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
          'sii', [$token, $userId, $days]);
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      exec1('DELETE FROM sessions WHERE token = ?', 's', [$token]);
    }
    respond(['ok' => true]);
  }

  case 'checkAccess': {
    $user = requireUser();
    if (!hasAppAccess($user)) {
      fail('Not authorized for ' . APP_NAME, 403);
    }
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
