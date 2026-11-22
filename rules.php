<?php
// Marriage rules. Cards may be plain values (AS) or unique physical IDs (AS#12).
$RK = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];

function cardValue($c) {
  if (is_array($c) && isset($c['value'])) $c = $c['value'];
  $c = (string)$c;
  return preg_replace('/#\d+$/', '', $c);
}
function rk($c) { global $RK; return array_search(substr(cardValue($c), 0, -1), $RK, true); }
function su($c) { $v = cardValue($c); return substr($v, -1); }
function isRed($s) { return $s === 'H' || $s === 'D'; }
function cls($c, $t) {
  $c = cardValue($c); $t = $t ? cardValue($t) : null;
  if ($c === 'JK') return 'J';
  if (!$t) return '';
  $r = rk($c); $s = su($c); $tr = rk($t); $ts = su($t);
  if ($c === $t) return 'T';
  if ($s === $ts) { if ($r === ($tr + 1) % 13) return 'P'; if ($r === ($tr + 12) % 13) return 'H'; return ''; }
  if ($r === $tr) return isRed($s) === isRed($ts) ? 'A' : 'O';
  return '';
}
function isPure($g) {
  $n = count($g); if ($n < 3) return false;
  $s = su($g[0]); $rs = [];
  foreach ($g as $c) { if (cardValue($c) === 'JK' || su($c) !== $s) return false; $rs[] = rk($c) + 1; }
  sort($rs); if (count(array_unique($rs)) !== $n) return false;
  if ($rs[$n-1] - $rs[0] === $n - 1) return true;
  if ($rs[0] === 1) { $rs[0] = 14; sort($rs); return $rs[$n-1] - $rs[0] === $n - 1; }
  return false;
}
function isTunnel($g) { return count($g) === 3 && cardValue($g[0]) === cardValue($g[1]) && cardValue($g[1]) === cardValue($g[2]); }
function gapsOK($r, $w) { $n = count($r); return $r[$n-1] - $r[0] + 1 - $n <= $w; }
function isSeq($g, $t) {
  $len = count($g); if ($len < 3 || $len > 14) return false;
  foreach (['S','H','D','C'] as $s) {
    $nat = []; $w = 0; $ok = true;
    foreach ($g as $c) {
      if (cardValue($c) !== 'JK' && su($c) === $s && !isset($nat[rk($c) + 1])) $nat[rk($c) + 1] = 1;
      elseif (cls($c, $t) === '') $ok = false; else $w++;
    }
    if (!$ok || !$nat) continue;
    $r = array_keys($nat); sort($r);
    if (gapsOK($r, $w)) return true;
    if (isset($nat[1])) { $r[0] = 14; sort($r); if (gapsOK($r, $w)) return true; }
  }
  return false;
}
function isRankSet($g, $t) {
  $len = count($g); if ($len < 3 || $len > 4) return false;
  foreach ($g as $x) {
    if (cardValue($x) === 'JK') continue;
    $r = rk($x); $suits = []; $ok = true;
    foreach ($g as $c) {
      if (cardValue($c) !== 'JK' && rk($c) === $r && !isset($suits[su($c)])) $suits[su($c)] = 1;
      elseif (cls($c, $t) === '') $ok = false;
    }
    if ($ok && $suits) return true;
  }
  return false;
}
function validGroup($g, $t) { return isPure($g) || isTunnel($g) || isSeq($g, $t) || isRankSet($g, $t); }
function maal($h, $t) {
  $n = ['T' => 0, 'P' => 0, 'H' => 0, 'A' => 0];
  foreach ($h as $c) { $k = cls($c, $t); if (isset($n[$k])) $n[$k]++; }
  $m = min($n['T'], $n['P'], $n['H']);
  $tb = [0,3,7,7]; $ph = [0,2,5,10]; $ab = [0,5,15,25];
  return [0,10,30,60][$m] + $tb[min($n['T']-$m,3)] + $ph[min($n['P']-$m,3)] + $ph[min($n['H']-$m,3)] + $ab[min($n['A'],3)];
}
function subsetOf($cards, $hand) {
  foreach ($cards as $c) { $i = array_search($c, $hand, true); if ($i === false) return false; array_splice($hand, $i, 1); }
  return true;
}
