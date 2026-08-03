<?php
// 파마스퀘어 이벤트 서버 API — 회원/쿠폰/스탬프/기대평/공유(레퍼럴)/설정 저장
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$FILE = __DIR__ . '/events_data.json';
$ADMIN_PW = '1234';

function load($FILE) {
  if (!file_exists($FILE)) {
    return array('members' => (object)array(), 'reviews' => array(), 'referrals' => array(),
      'config' => (object)array());
  }
  $raw = file_get_contents($FILE);
  $d = json_decode($raw, true);
  if (!is_array($d)) $d = array();
  if (!isset($d['members']) || !is_array($d['members'])) $d['members'] = array();
  if (!isset($d['reviews']) || !is_array($d['reviews'])) $d['reviews'] = array();
  if (!isset($d['referrals']) || !is_array($d['referrals'])) $d['referrals'] = array();
  if (!isset($d['config']) || !is_array($d['config'])) $d['config'] = array();
  return $d;
}
function save($FILE, $d) {
  $fp = fopen($FILE, 'c+');
  if (!$fp) { return false; }
  flock($fp, LOCK_EX);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return true;
}
function ok($x) { echo json_encode($x, JSON_UNESCAPED_UNICODE); exit(0); }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(array('error' => $msg)); exit(0); }

// re-read with lock, mutate via callback, write. Prevents lost updates.
function mutate($FILE, $cb) {
  $fp = fopen($FILE, 'c+');
  if (!$fp) return null;
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $d = json_decode($raw, true);
  if (!is_array($d)) $d = array();
  if (!isset($d['members']) || !is_array($d['members'])) $d['members'] = array();
  if (!isset($d['reviews']) || !is_array($d['reviews'])) $d['reviews'] = array();
  if (!isset($d['referrals']) || !is_array($d['referrals'])) $d['referrals'] = array();
  if (!isset($d['config']) || !is_array($d['config'])) $d['config'] = array();
  $d = $cb($d);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return $d;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $action = isset($_GET['action']) ? $_GET['action'] : '';
  $d = load($FILE);
  if ($action === 'config') {
    $vc = isset($d['config']['viewCounts']) ? $d['config']['viewCounts'] : (object)array();
    ok(array('config' => $d['config'], 'viewCounts' => $vc));
  } elseif ($action === 'viewbump') {
    $id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['id']) : '';
    if ($id === '') fail('no id');
    $d2 = mutate($FILE, function($d) use ($id) {
      if (!isset($d['config']) || !is_array($d['config'])) $d['config'] = array();
      if (!isset($d['config']['viewCounts']) || !is_array($d['config']['viewCounts'])) $d['config']['viewCounts'] = array();
      $d['config']['viewCounts'][$id] = (isset($d['config']['viewCounts'][$id]) ? intval($d['config']['viewCounts'][$id]) : 0) + 1;
      return $d;
    });
    ok(array('success' => true, 'viewCounts' => $d2['config']['viewCounts']));
  } elseif ($action === 'reviews') {
    $out = array();
    foreach ($d['reviews'] as $r) {
      $out[] = array('name' => isset($r['name']) ? $r['name'] : '', 'text' => isset($r['text']) ? $r['text'] : '', 'ts' => isset($r['ts']) ? $r['ts'] : '', 'uid' => isset($r['uid']) ? $r['uid'] : '');
    }
    ok(array('reviews' => $out));
  } elseif ($action === 'member') {
    $uid = isset($_GET['uid']) ? preg_replace('/\D/', '', $_GET['uid']) : '';
    $m = isset($d['members'][$uid]) ? $d['members'][$uid] : null;
    ok(array('member' => $m, 'config' => $d['config']));
  } elseif ($action === 'all') {
    $pw = isset($_GET['pw']) ? $_GET['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    ok($d);
  } elseif ($action === 'selftest') {
    // 진단: 데이터 파일 쓰기 가능 여부 확인
    $exists = file_exists($FILE);
    $writable = $exists ? is_writable($FILE) : is_writable(__DIR__);
    $canWrite = false; $err = '';
    $fp = @fopen($FILE, 'c+');
    if ($fp) {
      if (@flock($fp, LOCK_EX)) {
        $pos = ftell($fp);
        if (@fwrite($fp, '') !== false) { $canWrite = true; }
        @flock($fp, LOCK_UN);
      } else { $err = 'flock 실패'; }
      @fclose($fp);
    } else { $err = 'fopen 실패 (쓰기 권한 없음)'; }
    ok(array('ok' => true, 'fileExists' => $exists, 'isWritable' => $writable,
      'canActuallyWrite' => $canWrite, 'dir' => __DIR__, 'error' => $err,
      'members' => count($d['members']), 'reviews' => count($d['reviews']),
      'referrals' => count($d['referrals'])));
  } else {
    // ping
    ok(array('ok' => true, 'members' => count($d['members']), 'reviews' => count($d['reviews'])));
  }
}

if ($method === 'POST') {
  $raw = isset($_POST['data']) ? $_POST['data'] : file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) fail('invalid json');
  $action = isset($body['action']) ? $body['action'] : '';

  if ($action === 'sync') {
    // upsert a member's full personal record
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    if ($uid === '') fail('no uid');
    $member = isset($body['member']) ? $body['member'] : array();
    $d = mutate($FILE, function($d) use ($uid, $member) {
      // tombstone: don't resurrect a deleted member unless this is a newer signup
      if (isset($d['deleted']) && is_array($d['deleted']) && isset($d['deleted'][$uid])) {
        $delTs = $d['deleted'][$uid];
        $mTs = isset($member['ts']) ? $member['ts'] : '';
        if ($mTs === '' || strcmp($mTs, $delTs) <= 0) { return $d; }
        unset($d['deleted'][$uid]); // genuinely new signup after deletion
      }
      $prev = isset($d['members'][$uid]) ? $d['members'][$uid] : array();
      $merged = array_merge($prev, $member);
      // never let a client sync lower the server's referral-granted bonus
      $pb = isset($prev['bonusPlays']) ? intval($prev['bonusPlays']) : 0;
      $cb = isset($member['bonusPlays']) ? intval($member['bonusPlays']) : 0;
      $merged['bonusPlays'] = max($pb, $cb);
      $d['members'][$uid] = $merged;
      return $d;
    });
    $m = isset($d['members'][$uid]) ? $d['members'][$uid] : null;
    ok(array('success' => true, 'member' => $m));
  }

  if ($action === 'review') {
    $review = isset($body['review']) ? $body['review'] : null;
    if (!$review) fail('no review');
    $d = mutate($FILE, function($d) use ($review) {
      $d['reviews'][] = $review;
      return $d;
    });
    ok(array('success' => true, 'reviews' => $d['reviews']));
  }

  if ($action === 'referral') {
    // a friend visited sharer's link → credit sharer +1 (once per visitor)
    $sharer = isset($body['sharer']) ? preg_replace('/\D/', '', $body['sharer']) : '';
    $visitor = isset($body['visitor']) ? preg_replace('/\D/', '', $body['visitor']) : '';
    $vkey = $visitor !== '' ? $visitor : (isset($body['vkey']) ? $body['vkey'] : uniqid());
    if ($sharer === '') fail('no sharer');
    $granted = false;
    $d = mutate($FILE, function($d) use ($sharer, $vkey, &$granted) {
      // dedupe: same sharer+visitor only once
      foreach ($d['referrals'] as $r) {
        if ($r['sharer'] === $sharer && $r['visitor'] === $vkey) return $d;
      }
      $d['referrals'][] = array('sharer' => $sharer, 'visitor' => $vkey, 'ts' => date('c'));
      if (!isset($d['members'][$sharer]) || !is_array($d['members'][$sharer])) $d['members'][$sharer] = array();
      $cur = isset($d['members'][$sharer]['pendingBonus']) ? intval($d['members'][$sharer]['pendingBonus']) : 0;
      $d['members'][$sharer]['pendingBonus'] = $cur + 1;
      $granted = true;
      return $d;
    });
    ok(array('success' => true, 'granted' => $granted));
  }

  if ($action === 'claim') {
    // member claims their pending referral bonus; server returns amount and zeroes it
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    if ($uid === '') fail('no uid');
    $claimed = 0;
    $d = mutate($FILE, function($d) use ($uid, &$claimed) {
      if (isset($d['members'][$uid]['pendingBonus'])) {
        $claimed = intval($d['members'][$uid]['pendingBonus']);
        $d['members'][$uid]['pendingBonus'] = 0;
      }
      return $d;
    });
    ok(array('success' => true, 'claimed' => $claimed));
  }

  if ($action === 'addstamp') {
    // 관리자: 특정 회원에게 방문 스탬프 1개 서버측 적립
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    if ($uid === '') fail('no uid');
    $source = isset($body['source']) ? $body['source'] : 'visit';
    $daily = isset($body['daily']) ? intval($body['daily']) : 0;
    $blockList = isset($body['block']) ? explode(',', $body['block']) : array('visit','buy1','buy2');
    $blocked = false;
    $d = mutate($FILE, function($d) use ($uid, $source, $daily, $blockList, &$blocked) {
      if (!isset($d['members'][$uid]) || !is_array($d['members'][$uid])) $d['members'][$uid] = array();
      if (!isset($d['members'][$uid]['stamplog']) || !is_array($d['members'][$uid]['stamplog'])) $d['members'][$uid]['stamplog'] = array();
      $today = date('Y-m-d');
      if ($daily) {
        foreach ($d['members'][$uid]['stamplog'] as $e) {
          if (isset($e['date']) && $e['date'] === $today && in_array(isset($e['source'])?$e['source']:'', $blockList)) { $blocked = true; return $d; }
        }
      }
      $who = isset($d['members'][$uid]['name']) ? $d['members'][$uid]['name'] : '';
      $d['members'][$uid]['stamplog'][] = array('date' => $today, 'source' => $source, 'who' => $who, 'ts' => date('c'));
      if (!isset($d['allStamps']) || !is_array($d['allStamps'])) $d['allStamps'] = array();
      $d['allStamps'][] = array('uid' => $uid, 'date' => $today, 'source' => $source, 'who' => $who, 'ts' => date('c'));
      // 방문/구매 스탬프마다 룰렛 기회 1회 추가
      if (in_array($source, array('visit','buy1','buy2'))) {
        $cur = isset($d['members'][$uid]['spinBonus']) ? intval($d['members'][$uid]['spinBonus']) : 0;
        $d['members'][$uid]['spinBonus'] = $cur + 1;
      }
      return $d;
    });
    ok(array('success' => !$blocked, 'blocked' => $blocked));
  }

  if ($action === 'reward') {
    // admin marks a member's review rewarded and grants welcome coupon+stamp server-side flag
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    $d = mutate($FILE, function($d) use ($uid) {
      foreach ($d['reviews'] as $i => $r) {
        if (isset($r['uid']) && preg_replace('/\D/', '', $r['uid']) === $uid) { $d['reviews'][$i]['rewarded'] = true; }
      }
      if (isset($d['members'][$uid])) { $d['members'][$uid]['rewarded'] = true; }
      return $d;
    });
    ok(array('success' => true));
  }

  if ($action === 'config') {
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $config = isset($body['config']) ? $body['config'] : array();
    $d = mutate($FILE, function($d) use ($config) {
      $d['config'] = array_merge($d['config'], $config);
      return $d;
    });
    ok(array('success' => true, 'config' => $d['config']));
  }

  if ($action === 'clear') {
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $kind = isset($body['kind']) ? $body['kind'] : 'all';
    $d = mutate($FILE, function($d) use ($kind) {
      if ($kind === 'reviews') { $d['reviews'] = array(); }
      elseif ($kind === 'members') { $d['members'] = array(); $d['referrals'] = array(); }
      else { $d['members'] = array(); $d['reviews'] = array(); $d['referrals'] = array(); }
      return $d;
    });
    ok(array('success' => true));
  }

  if ($action === 'delmember') {
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    if ($uid === '') fail('no uid');
    $d = mutate($FILE, function($d) use ($uid) {
      if (!isset($d['deleted']) || !is_array($d['deleted'])) $d['deleted'] = array();
      $d['deleted'][$uid] = date('c');
      if (isset($d['members'][$uid])) unset($d['members'][$uid]);
      if (isset($d['reviews']) && is_array($d['reviews'])) { $d['reviews'] = array_values(array_filter($d['reviews'], function($r) use ($uid) { return preg_replace('/\D/', '', isset($r['uid'])?$r['uid']:(isset($r['phone'])?$r['phone']:'')) !== $uid; })); }
      if (isset($d['referrals']) && is_array($d['referrals'])) { $d['referrals'] = array_values(array_filter($d['referrals'], function($x) use ($uid) { return (isset($x['sharer'])?$x['sharer']:'') !== $uid && (isset($x['visitor'])?$x['visitor']:'') !== $uid; })); }
      return $d;
    });
    ok(array('success' => true));
  }

  if ($action === 'delcoupon') {
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    $code = isset($body['code']) ? $body['code'] : '';
    if ($uid === '' || $code === '') fail('no uid/code');
    $d = mutate($FILE, function($d) use ($uid, $code) {
      if (isset($d['members'][$uid]) && isset($d['members'][$uid]['coupons']) && is_array($d['members'][$uid]['coupons'])) {
        $src = '';
        $kept = array();
        foreach ($d['members'][$uid]['coupons'] as $c) {
          if ((isset($c['code']) ? $c['code'] : '') === $code) { $src = isset($c['source']) ? $c['source'] : ''; continue; }
          $kept[] = $c;
        }
        $d['members'][$uid]['coupons'] = array_values($kept);
        // 오픈 기대평 웰컴 쿠폰이면 다시 받을 수 있게 활성화
        if (strpos($src, '오픈 기대평') !== false || strpos($src, '기대평') !== false) {
          $d['members'][$uid]['welcomeIssued'] = false;
          if (isset($d['members'][$uid]['rewarded'])) $d['members'][$uid]['rewarded'] = false;
          if (isset($d['reviews']) && is_array($d['reviews'])) {
            foreach ($d['reviews'] as $i => $r) {
              if (preg_replace('/\D/', '', isset($r['uid']) ? $r['uid'] : (isset($r['phone']) ? $r['phone'] : '')) === $uid) { $d['reviews'][$i]['rewarded'] = false; }
            }
          }
        }
      }
      return $d;
    });
    ok(array('success' => true));
  }

  if ($action === 'setused') {
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    $code = isset($body['code']) ? $body['code'] : '';
    $used = isset($body['used']) ? (bool)$body['used'] : false;
    if ($uid === '' || $code === '') fail('no uid/code');
    $d = mutate($FILE, function($d) use ($uid, $code, $used) {
      if (isset($d['members'][$uid]) && isset($d['members'][$uid]['coupons']) && is_array($d['members'][$uid]['coupons'])) {
        foreach ($d['members'][$uid]['coupons'] as $i => $c) {
          if ((isset($c['code']) ? $c['code'] : '') === $code) { $d['members'][$uid]['coupons'][$i]['used'] = $used; }
        }
      }
      return $d;
    });
    ok(array('success' => true));
  }

  if ($action === 'delstamp') {
    $pw = isset($body['pw']) ? $body['pw'] : '';
    if ($pw !== $ADMIN_PW) fail('unauthorized', 401);
    $uid = isset($body['uid']) ? preg_replace('/\D/', '', $body['uid']) : '';
    $srcList = isset($body['sources']) ? explode(',', $body['sources']) : array();
    $date = isset($body['date']) ? $body['date'] : '';
    if ($uid === '') fail('no uid');
    $d = mutate($FILE, function($d) use ($uid, $srcList, $date) {
      if (isset($d['members'][$uid]) && isset($d['members'][$uid]['stamplog']) && is_array($d['members'][$uid]['stamplog'])) {
        $kept = array(); $removedGame = false; $removedSpin = 0;
        foreach ($d['members'][$uid]['stamplog'] as $e) {
          $s = isset($e['source']) ? $e['source'] : '';
          $dt = isset($e['date']) ? $e['date'] : '';
          $matchSrc = empty($srcList) ? true : in_array($s, $srcList);
          $matchDate = ($date === '') ? true : ($dt === $date);
          if ($matchSrc && $matchDate) { if ($s === 'game') $removedGame = true; if (in_array($s, array('visit','buy1','buy2'))) $removedSpin++; continue; }
          $kept[] = $e;
        }
        $d['members'][$uid]['stamplog'] = array_values($kept);
        if ($removedGame) $d['members'][$uid]['gameStampDone'] = false;
        if ($removedSpin > 0) { $cur = isset($d['members'][$uid]['spinBonus']) ? intval($d['members'][$uid]['spinBonus']) : 0; $d['members'][$uid]['spinBonus'] = max(0, $cur - $removedSpin); }
      }
      return $d;
    });
    ok(array('success' => true));
  }

  fail('unknown action');
}

fail('method not allowed', 405);
?>
