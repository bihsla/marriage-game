<?php
// Marriage card game API - file-based rooms, polling. PHP 7.4+, writable ./rooms
header('Content-Type: application/json');
require __DIR__ . '/rules.php';
$dir = __DIR__ . '/rooms';
if (!is_dir($dir)) mkdir($dir, 0755);
if (!file_exists($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? ''; $tok = $in['token'] ?? ''; $code = $in['code'] ?? '';

function out($d, $c = 200) { http_response_code($c); echo json_encode($d); exit; }
function rpath($code) { global $dir; return $dir . '/' . preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) . '.json'; }
function newDeck() {
  $d = [];
  foreach (['S','H','D','C'] as $s) foreach (['A','2','3','4','5','6','7','8','9','10','J','Q','K'] as $r) for ($k = 0; $k < 3; $k++) $d[] = $r . $s;
  for ($k = 0; $k < 6; $k++) $d[] = 'JK';
  shuffle($d); return $d;
}
function room($code, $fn) {
  $p = rpath($code);
  if (!file_exists($p)) out(['error' => 'Room not found'], 404);
  $f = fopen($p, 'c+'); flock($f, LOCK_EX);
  $r = json_decode(stream_get_contents($f), true);
  $res = $fn($r);
  $r['v'] = ($r['v'] ?? 0) + 1;
  ftruncate($f, 0); rewind($f); fwrite($f, json_encode($r)); flock($f, LOCK_UN); fclose($f);
  return $res;
}
function me(&$r, $tok) {
  foreach ($r['players'] as $i => $p) if (hash_equals($p['token'], (string)$tok)) return $i;
  out(['error' => 'Not in this room'], 403);
}
function removeCard(&$hand, $c) {
  $i = array_search($c, $hand, true);
  if ($i === false) out(['error' => 'Card not in hand'], 400);
  array_splice($hand, $i, 1);
}
function nm($n) {
  $n = trim(strip_tags((string)$n));
  $n = function_exists('mb_substr') ? mb_substr($n, 0, 14) : substr($n, 0, 14);
  return $n !== '' ? $n : 'Player';
}
function deal(&$r) {
  $d = newDeck(); $ti = null;
  foreach ($d as $k => $c) if ($c !== 'JK') { $ti = $c; array_splice($d, $k, 1); break; }
  foreach ($r['players'] as &$p) $p['hand'] = array_splice($d, 0, 21);
  unset($p);
  $r['tiplu'] = $ti; $r['discard'] = array_splice($d, 0, 1); $r['deck'] = $d;
  $r['seen'] = array_fill(0, count($r['players']), false);
  $r['status'] = 'playing'; $r['phase'] = 'draw'; $r['result'] = null;
  $r['turn'] = (($r['round'] ?? 1) - 1) % count($r['players']);
}

if ($a === 'create') {
  do { $code = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4); } while (file_exists(rpath($code)));
  $t = bin2hex(random_bytes(8));
  $r = ['status' => 'lobby', 'round' => 1, 'players' => [['name' => nm($in['name'] ?? ''), 'token' => $t, 'hand' => [], 'score' => 0]],
        'deck' => [], 'discard' => [], 'turn' => 0, 'phase' => 'draw', 'seen' => [], 'tiplu' => null, 'result' => null, 'v' => 1];
  file_put_contents(rpath($code), json_encode($r));
  out(['code' => $code, 'token' => $t]);
}
if ($a === 'join') {
  $t = bin2hex(random_bytes(8));
  room($code, function (&$r) use ($t, $in) {
    if ($r['status'] !== 'lobby') out(['error' => 'Game already started'], 400);
    if (count($r['players']) >= 6) out(['error' => 'Room full'], 400);
    $r['players'][] = ['name' => nm($in['name'] ?? ''), 'token' => $t, 'hand' => [], 'score' => 0];
  });
  out(['code' => strtoupper($code), 'token' => $t]);
}
if ($a === 'start' || $a === 'next') {
  room($code, function (&$r) use ($tok, $a) {
    if (me($r, $tok) !== 0) out(['error' => 'Only the host can do that'], 403);
    if (count($r['players']) < 2) out(['error' => 'Need 2+ players'], 400);
    if ($a === 'start' && $r['status'] !== 'lobby') out(['error' => 'Already started'], 400);
    if ($a === 'next') { if ($r['status'] !== 'finished') out(['error' => 'Round not finished'], 400); $r['round']++; }
    deal($r);
  });
  out(['ok' => 1]);
}
if (in_array($a, ['draw', 'take', 'discard', 'show', 'declare'])) {
  room($code, function (&$r) use ($a, $tok, $in) {
    if ($r['status'] !== 'playing') out(['error' => 'Game not running'], 400);
    $i = me($r, $tok);
    if ($r['turn'] !== $i) out(['error' => 'Not your turn'], 400);
    $h = &$r['players'][$i]['hand']; $t = $r['tiplu'];
    if ($a === 'draw' || $a === 'take') {
      if ($r['phase'] !== 'draw') out(['error' => 'Discard a card first'], 400);
      if ($a === 'draw') {
        if (!$r['deck']) { $top = array_pop($r['discard']); $r['deck'] = $r['discard']; shuffle($r['deck']); $r['discard'] = [$top]; }
        $h[] = array_pop($r['deck']);
      } else $h[] = array_pop($r['discard']);
      $r['phase'] = 'discard';
    } elseif ($a === 'show') {
      if ($r['seen'][$i]) out(['error' => 'You already saw the maal'], 400);
      $pure = array_values(array_filter($in['groups'] ?? [], fn($g) => is_array($g) && (isPure($g) || isTunnel($g))));
      if (count($pure) < 3) out(['error' => 'Need 3 pure sequences (or tunnels) to see the maal'], 400);
      if (!subsetOf(array_merge(...$pure), $h)) out(['error' => 'Those cards are not in your hand'], 400);
      $r['seen'][$i] = true;
    } elseif ($a === 'discard') {
      if ($r['phase'] !== 'discard') out(['error' => 'Draw a card first'], 400);
      $c = $in['card'] ?? ''; removeCard($h, $c); $r['discard'][] = $c;
      $r['turn'] = ($i + 1) % count($r['players']); $r['phase'] = 'draw';
    } else { // declare
      if ($r['phase'] !== 'discard') out(['error' => 'Draw a card first'], 400);
      if (!$r['seen'][$i]) out(['error' => 'Show 3 pure sequences first'], 400);
      $c = $in['card'] ?? ''; $groups = $in['groups'] ?? [];
      $test = $h; removeCard($test, $c);
      $flat = $groups ? array_merge(...$groups) : [];
      $x = $test; $y = $flat; sort($x); sort($y);
      if ($x !== $y) out(['error' => 'Group ALL your other cards'], 400);
      foreach ($groups as $g) if (!is_array($g) || !validGroup($g, $t)) out(['error' => 'Invalid group: ' . implode(' ', (array)$g)], 400);
      if (!array_filter($groups, 'isPure')) out(['error' => 'Need at least one pure sequence'], 400);
      removeCard($h, $c); $r['discard'][] = $c;
      // scoring: pairwise maal difference (zero-sum) + winner bonus (3 from seen players, 10 from unseen)
      $n = count($r['players']); $m = [];
      foreach ($r['players'] as $j => $p) $m[$j] = $r['seen'][$j] ? maal($p['hand'], $t) : 0;
      $tot = array_sum($m); $rows = [];
      foreach ($r['players'] as $j => $p) $rows[$j] = ['name' => $p['name'], 'seen' => $r['seen'][$j], 'maal' => $m[$j], 'delta' => $n * $m[$j] - $tot, 'hand' => $p['hand']];
      foreach ($rows as $j => &$row) if ($j !== $i) { $b = $row['seen'] ? 3 : 10; $row['delta'] -= $b; $rows[$i]['delta'] += $b; }
      unset($row);
      foreach ($rows as $j => $row) $r['players'][$j]['score'] += $row['delta'];
      $r['status'] = 'finished';
      $r['result'] = ['winner' => $i, 'groups' => $groups, 'rows' => $rows, 'tiplu' => $t];
    }
  });
  out(['ok' => 1]);
}
if ($a === 'state') {
  $res = null;
  room($code, function (&$r) use ($tok, $in, &$res) {
    $i = me($r, $tok);
    if (($in['v'] ?? -1) === $r['v']) $res = ['same' => 1];
    else $res = [
      'v' => $r['v'], 'code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $GLOBALS['code'])),
      'status' => $r['status'], 'round' => $r['round'], 'me' => $i, 'turn' => $r['turn'], 'phase' => $r['phase'],
      'players' => array_map(fn($p, $k) => ['name' => $p['name'], 'count' => count($p['hand']), 'score' => $p['score'], 'seen' => $r['seen'][$k] ?? false], $r['players'], array_keys($r['players'])),
      'hand' => $r['players'][$i]['hand'], 'top' => end($r['discard']) ?: null, 'deck' => count($r['deck']),
      'tiplu' => (($r['seen'][$i] ?? false) || $r['status'] === 'finished') ? $r['tiplu'] : null,
      'result' => $r['result'],
    ];
    $r['v']--; // reads don't bump the version
  });
  out($res);
}
out(['error' => 'Unknown action'], 400);
