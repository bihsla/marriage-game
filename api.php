<?php
// Private Nepali Marriage card game API. PHP 7.4+, writable ./rooms directory.
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/rules.php';

$dir = __DIR__ . '/rooms';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
  http_response_code(500); echo json_encode(['error' => 'Cannot create rooms directory']); exit;
}
$deny = $dir . '/.htaccess';
if (!file_exists($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$a = (string)($_GET['a'] ?? '');
$tok = (string)($in['token'] ?? '');
$code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($in['code'] ?? '')));

function out($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
function rpath($code) {
  global $dir;
  $safe = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code));
  return $dir . '/' . $safe . '.json';
}
function nm($name) {
  $name = trim(strip_tags((string)$name));
  $name = function_exists('mb_substr') ? mb_substr($name, 0, 14) : substr($name, 0, 14);
  return $name !== '' ? $name : 'Player';
}
function newDeck() {
  $deck = []; $serial = 1;
  foreach (['S','H','D','C'] as $suit) {
    foreach (['A','2','3','4','5','6','7','8','9','10','J','Q','K'] as $rank) {
      for ($copy = 0; $copy < 3; $copy++) $deck[] = $rank . $suit . '#' . $serial++;
    }
  }
  for ($i = 0; $i < 6; $i++) $deck[] = 'JK#' . $serial++;
  shuffle($deck);
  return $deck;
}
function room($code, $callback, $bumpVersion = true) {
  $path = rpath($code);
  if (!is_file($path)) out(['error' => 'Room not found'], 404);
  $fp = @fopen($path, 'c+');
  if (!$fp) out(['error' => 'Cannot open room'], 500);
  if (!flock($fp, LOCK_EX)) { fclose($fp); out(['error' => 'Room is busy; try again'], 503); }
  rewind($fp);
  $raw = stream_get_contents($fp);
  $state = json_decode($raw, true);
  if (!is_array($state) || !isset($state['players']) || !is_array($state['players'])) {
    flock($fp, LOCK_UN); fclose($fp); out(['error' => 'Room data is damaged'], 500);
  }
  $result = $callback($state);
  if ($bumpVersion) $state['v'] = (int)($state['v'] ?? 0) + 1;
  rewind($fp); ftruncate($fp, 0);
  fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE)); fflush($fp);
  flock($fp, LOCK_UN); fclose($fp);
  return $result;
}
function me(&$state, $token) {
  if ($token === '') out(['error' => 'Missing player token'], 403);
  foreach ($state['players'] as $i => $player) {
    if (isset($player['token']) && hash_equals((string)$player['token'], (string)$token)) return $i;
  }
  out(['error' => 'Not in this room'], 403);
}
function removeCard(&$hand, $card) {
  $index = array_search($card, $hand, true);
  if ($index === false) out(['error' => 'Card not in hand'], 400);
  array_splice($hand, $index, 1);
}
function deal(&$state) {
  $deck = newDeck(); $tiplu = null;
  foreach ($deck as $index => $card) {
    if (cardValue($card) !== 'JK') { $tiplu = $card; array_splice($deck, $index, 1); break; }
  }
  foreach ($state['players'] as &$player) $player['hand'] = array_splice($deck, 0, 21);
  unset($player);
  $state['tiplu'] = $tiplu;
  $state['discard'] = array_splice($deck, 0, 1);
  $state['deck'] = $deck;
  $state['seen'] = array_fill(0, count($state['players']), false);
  $state['status'] = 'playing'; $state['phase'] = 'draw'; $state['result'] = null;
  $state['turn'] = (($state['round'] ?? 1) - 1) % count($state['players']);
}

if ($a === 'create') {
  $token = bin2hex(random_bytes(16));
  do {
    $roomCode = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5);
    $path = rpath($roomCode);
    $fp = @fopen($path, 'x');
  } while (!$fp);
  $state = [
    'status' => 'lobby', 'round' => 1,
    'players' => [[ 'name' => nm($in['name'] ?? ''), 'token' => $token, 'hand' => [], 'score' => 0, 'ready' => true, 'online' => true, 'lastSeen' => time() ]],
    'deck' => [], 'discard' => [], 'turn' => 0, 'phase' => 'draw', 'seen' => [], 'tiplu' => null,
    'result' => null, 'chat' => [], 'v' => 1, 'created' => time()
  ];
  fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE)); fflush($fp); fclose($fp);
  out(['code' => $roomCode, 'token' => $token]);
}

if ($a === 'join') {
  if (strlen($code) !== 5) out(['error' => 'Enter a valid 5-character room code'], 400);
  $token = bin2hex(random_bytes(16));
  room($code, function (&$state) use ($token, $in) {
    if (($state['status'] ?? '') !== 'lobby') out(['error' => 'Game already started. You cannot join this round.'], 400);
    if (count($state['players']) >= 5) out(['error' => 'Room is full (maximum 5 players).'], 400);
    $state['players'][] = ['name' => nm($in['name'] ?? ''), 'token' => $token, 'hand' => [], 'score' => 0, 'ready' => false, 'online' => true, 'lastSeen' => time()];
  });
  out(['code' => $code, 'token' => $token]);
}

if ($a === 'state') {
  if (strlen($code) !== 5) out(['error' => 'Invalid room code'], 400);
  $result = room($code, function (&$state) use ($tok, $in, $code) {
    $index = me($state, $tok); $now = time(); $changed = false;
    foreach ($state['players'] as $j => &$player) {
      $online = ($now - (int)($player['lastSeen'] ?? 0)) < 35;
      if (($player['online'] ?? false) !== $online) { $player['online'] = $online; $changed = true; }
      if ($j === $index) {
        $player['lastSeen'] = $now;
        if (empty($player['online'])) { $player['online'] = true; $changed = true; }
      }
    }
    unset($player);
    if ($changed) $state['v'] = (int)($state['v'] ?? 0) + 1;
    if (($in['v'] ?? -1) === ($state['v'] ?? 0)) return ['same' => 1, 'v' => $state['v']];
    return [
      'v' => $state['v'], 'code' => $code, 'status' => $state['status'], 'round' => $state['round'], 'me' => $index,
      'host' => 0, 'turn' => $state['turn'], 'phase' => $state['phase'],
      'players' => array_map(function ($player, $j) use ($state) {
        return ['name' => $player['name'], 'count' => count($player['hand']), 'score' => $player['score'],
          'seen' => $state['seen'][$j] ?? false, 'ready' => $player['ready'] ?? false, 'online' => $player['online'] ?? false];
      }, $state['players'], array_keys($state['players'])),
      'hand' => $state['players'][$index]['hand'], 'top' => end($state['discard']) ?: null, 'deck' => count($state['deck']),
      'tiplu' => (($state['seen'][$index] ?? false) || $state['status'] === 'finished') ? $state['tiplu'] : null,
      'result' => $state['result'], 'chat' => array_slice($state['chat'] ?? [], -30)
    ];
  }, false);
  out($result);
}

if ($a === 'ready') {
  room($code, function (&$state) use ($tok, $in) {
    if ($state['status'] !== 'lobby') out(['error' => 'Lobby is closed'], 400);
    $index = me($state, $tok);
    if ($index === 0) { $state['players'][$index]['ready'] = true; return; }
    $state['players'][$index]['ready'] = !empty($in['ready']);
  });
  out(['ok' => 1]);
}

if ($a === 'leave') {
  room($code, function (&$state) use ($tok) {
    $index = me($state, $tok);
    if ($state['status'] === 'lobby') {
      array_splice($state['players'], $index, 1);
      if (!$state['players']) $state['status'] = 'closed';
    } else {
      $state['players'][$index]['online'] = false;
      $state['players'][$index]['lastSeen'] = 0;
    }
  });
  out(['ok' => 1]);
}

if ($a === 'chat') {
  room($code, function (&$state) use ($tok, $in) {
    $index = me($state, $tok);
    $message = trim(strip_tags((string)($in['message'] ?? '')));
    if ($message === '') out(['error' => 'Write a message first'], 400);
    if (function_exists('mb_strlen') ? mb_strlen($message) > 180 : strlen($message) > 180) out(['error' => 'Message is too long'], 400);
    $state['chat'][] = ['name' => $state['players'][$index]['name'], 'message' => $message, 'time' => time()];
    $state['chat'] = array_slice($state['chat'], -50);
  });
  out(['ok' => 1]);
}

if ($a === 'start' || $a === 'next') {
  room($code, function (&$state) use ($tok, $a) {
    if (me($state, $tok) !== 0) out(['error' => 'Only the host can do that'], 403);
    if (count($state['players']) < 2) out(['error' => 'Need at least 2 players'], 400);
    if ($a === 'start') {
      if ($state['status'] !== 'lobby') out(['error' => 'Already started'], 400);
      foreach ($state['players'] as $player) if (empty($player['ready'])) out(['error' => 'Everyone must be ready before starting'], 400);
    } else {
      if ($state['status'] !== 'finished') out(['error' => 'Round not finished'], 400);
      $state['round']++;
    }
    deal($state);
  });
  out(['ok' => 1]);
}

if (in_array($a, ['draw','take','discard','show','declare'], true)) {
  room($code, function (&$state) use ($a, $tok, $in) {
    if ($state['status'] !== 'playing') out(['error' => 'Game not running'], 400);
    $index = me($state, $tok);
    if ($state['turn'] !== $index) out(['error' => 'Not your turn'], 400);
    $hand = &$state['players'][$index]['hand']; $tiplu = $state['tiplu'];
    if ($a === 'draw' || $a === 'take') {
      if ($state['phase'] !== 'draw') out(['error' => 'Discard a card first'], 400);
      if ($a === 'draw') {
        if (!$state['deck']) {
          $top = array_pop($state['discard']);
          $state['deck'] = $state['discard']; shuffle($state['deck']);
          $state['discard'] = $top ? [$top] : [];
        }
        if (!$state['deck']) out(['error' => 'No cards left to draw'], 400);
        $hand[] = array_pop($state['deck']);
      } else {
        if (!$state['discard']) out(['error' => 'No discard to take'], 400);
        $hand[] = array_pop($state['discard']);
      }
      $state['phase'] = 'discard';
    } elseif ($a === 'show') {
      if ($state['seen'][$index]) out(['error' => 'You already saw the maal'], 400);
      $pure = array_values(array_filter($in['groups'] ?? [], function ($group) {
        return is_array($group) && (isPure(values($group)) || isTunnel(values($group)));
      }));
      if (count($pure) < 3) out(['error' => 'Need 3 pure sequences (or tunnels) to see the maal'], 400);
      $flat = array_merge(...$pure);
      if (count(array_unique($flat)) !== count($flat) || !subsetOf($flat, $hand)) out(['error' => 'Those cards are not in your hand'], 400);
      $state['seen'][$index] = true;
    } elseif ($a === 'discard') {
      if ($state['phase'] !== 'discard') out(['error' => 'Draw a card first'], 400);
      $card = (string)($in['card'] ?? ''); removeCard($hand, $card); $state['discard'][] = $card;
      $state['turn'] = ($index + 1) % count($state['players']); $state['phase'] = 'draw';
    } else {
      if ($state['phase'] !== 'discard') out(['error' => 'Draw a card first'], 400);
      if (empty($state['seen'][$index])) out(['error' => 'Show 3 pure sequences first'], 400);
      $card = (string)($in['card'] ?? ''); $groups = $in['groups'] ?? [];
      if (!is_array($groups)) out(['error' => 'Invalid groups'], 400);
      $test = $hand; removeCard($test, $card);
      $flat = [];
      foreach ($groups as $group) {
        if (!is_array($group)) out(['error' => 'Invalid group data'], 400);
        $flat = array_merge($flat, $group);
      }
      $x = $test; $y = $flat; sort($x); sort($y);
      if ($x !== $y) out(['error' => 'Group ALL your other cards'], 400);
      foreach ($groups as $group) {
        if (!validGroup(values($group), $tiplu)) out(['error' => 'Invalid group: ' . implode(' ', values($group))], 400);
      }
      $hasPure = false;
      foreach ($groups as $group) if (isPure(values($group))) { $hasPure = true; break; }
      if (!$hasPure) out(['error' => 'Need at least one pure sequence'], 400);
      removeCard($hand, $card); $state['discard'][] = $card;
      $playerCount = count($state['players']); $maalValues = []; $rows = [];
      foreach ($state['players'] as $j => $player) $maalValues[$j] = !empty($state['seen'][$j]) ? maal(values($player['hand']), $tiplu) : 0;
      $total = array_sum($maalValues);
      foreach ($state['players'] as $j => $player) {
        $rows[$j] = ['name' => $player['name'], 'seen' => (bool)$state['seen'][$j], 'maal' => $maalValues[$j],
          'delta' => $playerCount * $maalValues[$j] - $total, 'hand' => $player['hand']];
      }
      foreach ($rows as $j => $row) {
        if ($j !== $index) {
          $bonus = $row['seen'] ? 3 : 10;
          $rows[$j]['delta'] -= $bonus;
          $rows[$index]['delta'] += $bonus;
        }
      }
      foreach ($rows as $j => $row) $state['players'][$j]['score'] += $row['delta'];
      $state['status'] = 'finished';
      $state['result'] = ['winner' => $index, 'groups' => $groups, 'rows' => $rows, 'tiplu' => $tiplu];
    }
  });
  out(['ok' => 1]);
}

out(['error' => 'Unknown action'], 400);
