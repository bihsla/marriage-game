  $t=bin2hex(random_bytes(16));
  do { $code=substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,5); $p=rpath($code); $f=@fopen($p,'x'); } while(!$f);
  $r=['status'=>'lobby','round'=>1,'players'=>[['name'=>nm($in['name']??''),'token'=>$t,'hand'=>[],'score'=>0,'ready'=>true,'online'=>true,'lastSeen'=>time()]],
      'deck'=>[],'discard'=>[],'turn'=>0,'phase'=>'draw','seen'=>[],'tiplu'=>null,'result'=>null,'chat'=>[],'v'=>1,'created'=>time()];
  fwrite($f,json_encode($r)); fclose($f);
  out(['code'=>$code,'token'=>$t]);
}
if ($a==='join') {
  $t=bin2hex(random_bytes(16));
  room($code,function(&$r) use($t,$in) {
    if($r['status']!=='lobby') out(['error'=>'Game already started. You cannot join this round.'],400);
    if(count($r['players'])>=5) out(['error'=>'Room is full (maximum 5 players).'],400);
    $r['players'][]=['name'=>nm($in['name']??''),'token'=>$t,'hand'=>[],'score'=>0,'ready'=>false,'online'=>true,'lastSeen'=>time()];
  });
  out(['code'=>$code,'token'=>$t]);
}
if ($a==='state') {
  $res=room($code,function(&$r) use($tok,$in,$code) {
    $i=me($r,$tok); $now=time(); $changed=false;
    foreach($r['players'] as $j=>&$p) {
      $online=($now-(int)($p['lastSeen']??0))<35;
      if (($p['online']??false)!==$online) { $p['online']=$online; $changed=true; }
      if($j===$i) { $p['lastSeen']=$now; if(!($p['online']??false)){$p['online']=true;$changed=true;} }
    } unset($p);
    if($changed) $r['v']=($r['v']??0)+1;
    if(($in['v']??-1)===$r['v']) return ['same'=>1,'v'=>$r['v']];
    return ['v'=>$r['v'],'code'=>$code,'status'=>$r['status'],'round'=>$r['round'],'me'=>$i,'host'=>0,'turn'=>$r['turn'],'phase'=>$r['phase'],
      'players'=>array_map(fn($p,$k)=>['name'=>$p['name'],'count'=>count($p['hand']),'score'=>$p['score'],'seen'=>$r['seen'][$k]??false,'ready'=>$p['ready']??false,'online'=>$p['online']??false],$r['players'],array_keys($r['players'])),
      'hand'=>$r['players'][$i]['hand'],'top'=>end($r['discard'])?:null,'deck'=>count($r['deck']),
      'tiplu'=>(($r['seen'][$i]??false)||$r['status']==='finished')?$r['tiplu']:null,
      'result'=>$r['result'],'chat'=>array_slice($r['chat']??[],-30)];
  },false);
  out($res);
}
if ($a==='ready') {
  room($code,function(&$r) use($tok,$in) {
    if($r['status']!=='lobby') out(['error'=>'Lobby is closed'],400);
    $i=me($r,$tok); if($i===0) { $r['players'][$i]['ready']=true; return; }
    $r['players'][$i]['ready']=!empty($in['ready']);
  });
  out(['ok'=>1]);
}
if ($a==='leave') {
  room($code,function(&$r) use($tok) {
    $i=me($r,$tok);
    if($r['status']==='lobby') {
      array_splice($r['players'],$i,1);
      if(!$r['players']) { $r['status']='closed'; return; }
    } else { $r['players'][$i]['online']=false; $r['players'][$i]['lastSeen']=0; }
  });
  out(['ok'=>1]);
}
if ($a==='chat') {
  room($code,function(&$r) use($tok,$in) {
    $i=me($r,$tok); $msg=trim(strip_tags((string)($in['message']??'')));
    if($msg==='') out(['error'=>'Write a message first'],400);
    if(strlen($msg)>180) out(['error'=>'Message is too long'],400);
    $r['chat'][]=['name'=>$r['players'][$i]['name'],'message'=>$msg,'time'=>time()];
    $r['chat']=array_slice($r['chat'],-50);
  });
  out(['ok'=>1]);
}
if ($a==='start'||$a==='next') {
  room($code,function(&$r) use($tok,$a) {
    if(me($r,$tok)!==0) out(['error'=>'Only the host can do that'],403);
    if(count($r['players'])<2) out(['error'=>'Need at least 2 players'],400);
    if($a==='start') {
      if($r['status']!=='lobby') out(['error'=>'Already started'],400);
      foreach($r['players'] as $p) if(empty($p['ready'])) out(['error'=>'Everyone must be ready before starting'],400);
    } else { if($r['status']!=='finished') out(['error'=>'Round not finished'],400); $r['round']++; }
    deal($r);
  });
  out(['ok'=>1]);
}
if(in_array($a,['draw','take','discard','show','declare'],true)) {
  room($code,function(&$r) use($a,$tok,$in) {
    if($r['status']!=='playing') out(['error'=>'Game not running'],400);
    $i=me($r,$tok); if($r['turn']!==$i) out(['error'=>'Not your turn'],400);
    $h=&$r['players'][$i]['hand']; $t=$r['tiplu'];
    if($a==='draw'||$a==='take') {
      if($r['phase']!=='draw') out(['error'=>'Discard a card first'],400);
      if($a==='draw') {
        if(!$r['deck']) { $top=array_pop($r['discard']); $r['deck']=$r['discard']; shuffle($r['deck']); $r['discard']=$top?[$top]:[]; }
        if(!$r['deck']) out(['error'=>'No cards left to draw'],400);
        $h[]=array_pop($r['deck']);
      } else {
        if(!$r['discard']) out(['error'=>'No discard to take'],400);
        $h[]=array_pop($r['discard']);
      }
      $r['phase']='discard';
    } elseif($a==='show') {
      if($r['seen'][$i]) out(['error'=>'You already saw the maal'],400);
      $pure=array_values(array_filter($in['groups']??[],fn($g)=>is_array($g)&&(isPure(values($g))||isTunnel(values($g)))));
      if(count($pure)<3) out(['error'=>'Need 3 pure sequences (or tunnels) to see the maal'],400);
      $flat=array_merge(...$pure);
      if(count(array_unique($flat))!==count($flat)||!subsetOf($flat,$h)) out(['error'=>'Those cards are not in your hand'],400);
      $r['seen'][$i]=true;
    } elseif($a==='discard') {
      if($r['phase']!=='discard') out(['error'=>'Draw a card first'],400);
      $c=$in['card']??''; removeCard($h,$c); $r['discard'][]=$c;
      $r['turn']=($i+1)%count($r['players']); $r['phase']='draw';
    } else {
      if($r['phase']!=='discard') out(['error'=>'Draw a card first'],400);
      if(!$r['seen'][$i]) out(['error'=>'Show 3 pure sequences first'],400);
      $c=$in['card']??''; $groups=$in['groups']??[]; $test=$h; removeCard($test,$c);
      $flat=$groups?array_merge(...$groups):[];
      $x=$test;$y=$flat;sort($x);sort($y);
      if($x!==$y) out(['error'=>'Group ALL your other cards'],400);
      foreach($groups as $g) if(!is_array($g)||!validGroup(values($g),$t)) out(['error'=>'Invalid group: '.implode(' ',values((array)$g))],400);
      if(!array_filter($groups,fn($g)=>is_array($g)&&isPure(values($g)))) out(['error'=>'Need at least one pure sequence'],400);
      removeCard($h,$c);$r['discard'][]=$c;
      $n=count($r['players']);$m=[];
      foreach($r['players'] as $j=>$p) $m[$j]=$r['seen'][$j]?maal(values($p['hand']),$t):0;
      $tot=array_sum($m);$rows=[];
      foreach($r['players'] as $j=>$p) $rows[$j]=['name'=>$p['name'],'seen'=>$r['seen'][$j],'maal'=>$m[$j],'delta'=>$n*$m[$j]-$tot,'hand'=>$p['hand']];
      foreach($rows as $j=>$row) if($j!==$i){$b=$row['seen']?3:10;$rows[$j]['delta']-=$b;$rows[$i]['delta']+=$b;}
      foreach($rows as $j=>$row)$r['players'][$j]['score']+=$row['delta'];
      $r['status']='finished';$r['result']=['winner'=>$i,'groups'=>$groups,'rows'=>$rows,'tiplu'=>$t];
    }
  });
  out(['ok'=>1]);
}
out(['error'=>'Unknown action'],400);
