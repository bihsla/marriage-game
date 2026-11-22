<?php
// Marriage card game API - file-based rooms, polling. Needs PHP 7.4+ and a writable /rooms folder.
header('Content-Type: application/json');
$dir = __DIR__ . '/rooms';
if (!is_dir($dir)) mkdir($dir, 0755);
// Room files contain everyone's hands + tokens: block direct web access (Apache/LiteSpeed)
if (!file_exists($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? '';

function out($d, $c = 200) { http_response_code($c); echo json_encode($d); exit; }
function rpath($code) { global $dir; return $dir . '/' . preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) . '.json'; }
function newDeck() {
  $d = [];
  foreach (['S','H','D','C'] as $s)
    foreach (['A','2','3','4','5','6','7','8','9','10','J','Q','K'] as $r)
      for ($k = 0; $k < 3; $k++) $d[] = $r . $s;   // 3 decks
  for ($k = 0; $k < 6; $k++) $d[] = 'JK';          // printed jokers
  shuffle($d);
  return $d;
}
function room($code, $fn) {
  $p = rpath($code);
  if (!file_exists($p)) out(['error' => 'Room not found'], 404);
  $f = fopen($p, 'c+'); flock($f, LOCK_EX);
  $r = json_decode(stream_get_contents($f), true);
  $res = $fn($r);
  $r['v'] = ($r['v'] ?? 0) + 1;
  ftruncate($f, 0); rewind($f); fwrite($f, json_encode($r));
  flock($f, LOCK_UN); fclose($f);
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
function name($n) {
  $n = trim(strip_tags((string)($n ?? '')));
  $n = function_exists('mb_substr') ? mb_substr($n, 0, 16) : substr($n, 0, 16);
  return $n !== '' ? $n : 'Player';
}

$tok = $in['token'] ?? '';
$code = $in['code'] ?? '';

if ($a === 'create') {
  do { $code = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4); } while (file_exists(rpath($code)));
  $t = bin2hex(random_bytes(8));
  $r = ['status' => 'lobby', 'players' => [['name' => name($in['name'] ?? ''), 'token' => $t, 'hand' => []]],
        'deck' => [], 'discard' => [], 'turn' => 0, 'phase' => 'draw', 'winner' => null, 'v' => 1];
  file_put_contents(rpath($code), json_encode($r));
  out(['code' => $code, 'token' => $t]);
}

if ($a === 'join') {
  $t = bin2hex(random_bytes(8));
  room($code, function (&$r) use ($t, $in) {
    if ($r['status'] !== 'lobby') out(['error' => 'Game already started'], 400);
    if (count($r['players']) >= 6) out(['error' => 'Room full'], 400);
    $r['players'][] = ['name' => name($in['name'] ?? ''), 'token' => $t, 'hand' => []];
  });
  out(['code' => strtoupper($code), 'token' => $t]);
}

if ($a === 'start') {
  room($code, function (&$r) use ($tok) {
    if (me($r, $tok) !== 0) out(['error' => 'Only host can start'], 403);
    if (count($r['players']) < 2) out(['error' => 'Need 2+ players'], 400);
    $r['deck'] = newDeck();
    foreach ($r['players'] as &$p) $p['hand'] = array_splice($r['deck'], 0, 21);
    $r['discard'] = array_splice($r['deck'], 0, 1);
    $r['status'] = 'playing'; $r['turn'] = 0; $r['phase'] = 'draw'; $r['winner'] = null;
  });
  out(['ok' => 1]);
}

if (in_array($a, ['draw', 'take', 'discard', 'declare'])) {
  room($code, function (&$r) use ($a, $tok, $in) {
    if ($r['status'] !== 'playing') out(['error' => 'Game not running'], 400);
    $i = me($r, $tok);
    if ($r['turn'] !== $i) out(['error' => 'Not your turn'], 400);
    $h = &$r['players'][$i]['hand'];
    if ($a === 'draw' || $a === 'take') {
      if ($r['phase'] !== 'draw') out(['error' => 'Discard first'], 400);
      if ($a === 'draw') {
        if (!$r['deck']) { // reshuffle discards
          $top = array_pop($r['discard']); $r['deck'] = $r['discard']; shuffle($r['deck']); $r['discard'] = [$top];
        }
        $h[] = array_pop($r['deck']);
      } else {
        $h[] = array_pop($r['discard']);
      }
      $r['phase'] = 'discard';
    } elseif ($a === 'discard') {
      if ($r['phase'] !== 'discard') out(['error' => 'Draw first'], 400);
      $c = $in['card'] ?? ''; removeCard($h, $c);
      $r['discard'][] = $c;
      $r['turn'] = ($i + 1) % count($r['players']); $r['phase'] = 'draw';
    } else { // declare: groups of the remaining 21 cards + 1 discard
      if ($r['phase'] !== 'discard') out(['error' => 'Draw first'], 400);
      $c = $in['card'] ?? ''; removeCard($h, $c);
      $flat = array_merge(...($in['groups'] ?: [[]]));
      $x = $h; $y = $flat; sort($x); sort($y);
      if ($x !== $y) out(['error' => 'Groups must contain all your other cards'], 400);
      $r['discard'][] = $c;
      $r['status'] = 'finished';
      $r['winner'] = ['name' => $r['players'][$i]['name'], 'groups' => $in['groups']];
      // TODO: validate sequences/tunnels/sets + count maal points
    }
  });
  out(['ok' => 1]);
}

if ($a === 'state') {
  $res = null;
  room($code, function (&$r) use ($tok, &$res) {
    $i = me($r, $tok);
    $res = [
      'code' => strtoupper(basename(rpath($GLOBALS['code']), '.json')),
      'status' => $r['status'], 'me' => $i, 'turn' => $r['turn'], 'phase' => $r['phase'],
      'players' => array_map(fn($p) => ['name' => $p['name'], 'count' => count($p['hand'])], $r['players']),
      'hand' => $r['players'][$i]['hand'], 'top' => end($r['discard']) ?: null,
      'deck' => count($r['deck']), 'winner' => $r['winner'],
    ];
    $r['v']--; // reads don't bump version
  });
  out($res);
}

out(['error' => 'Unknown action'], 400);
