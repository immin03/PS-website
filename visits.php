<?php
// 파마스퀘어 일별 방문자 통계 — 가비아 API 없음 → 자체 집계
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$FILE = __DIR__ . '/visits_data.json';
$EVENTS_FILE = __DIR__ . '/events_data.json';
$ADMIN_PWS = array('phama2026!', '1234');
$TZ = 'Asia/Seoul';

function admin_pws($ADMIN_PWS, $EVENTS_FILE) {
  $pws = $ADMIN_PWS;
  if (file_exists($EVENTS_FILE)) {
    $ed = json_decode(@file_get_contents($EVENTS_FILE), true);
    if (is_array($ed) && isset($ed['config']['adminPw']) && is_string($ed['config']['adminPw']) && $ed['config']['adminPw'] !== '') {
      $pws[] = $ed['config']['adminPw'];
    }
  }
  return array_values(array_unique($pws));
}

function today_kst($TZ) {
  try {
    $dt = new DateTime('now', new DateTimeZone($TZ));
    return $dt->format('Y-m-d');
  } catch (Exception $e) {
    return gmdate('Y-m-d', time() + 9 * 3600);
  }
}

function load_visits($FILE) {
  if (!file_exists($FILE)) {
    return array('days' => array(), 'seen' => array());
  }
  $d = json_decode(file_get_contents($FILE), true);
  if (!is_array($d)) $d = array();
  if (!isset($d['days']) || !is_array($d['days'])) $d['days'] = array();
  if (!isset($d['seen']) || !is_array($d['seen'])) $d['seen'] = array();
  return $d;
}

function save_visits($FILE, $d) {
  $fp = fopen($FILE, 'c+');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return true;
}

function mutate_visits($FILE, $cb) {
  $fp = fopen($FILE, 'c+');
  if (!$fp) return null;
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $d = json_decode($raw, true);
  if (!is_array($d)) $d = array();
  if (!isset($d['days']) || !is_array($d['days'])) $d['days'] = array();
  if (!isset($d['seen']) || !is_array($d['seen'])) $d['seen'] = array();
  $d = $cb($d);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return $d;
}

function prune_visits($d) {
  $cutoffDays = date('Y-m-d', time() - 400 * 86400);
  $cutoffSeen = date('Y-m-d', time() - 45 * 86400);
  foreach (array_keys($d['days']) as $day) {
    if ($day < $cutoffDays) unset($d['days'][$day]);
  }
  foreach (array_keys($d['seen']) as $day) {
    if ($day < $cutoffSeen) unset($d['seen'][$day]);
  }
  return $d;
}

function visitor_id() {
  $cookie = isset($_COOKIE['ps_vid']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_COOKIE['ps_vid']) : '';
  if ($cookie !== '' && strlen($cookie) >= 8) return $cookie;
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
  $id = substr(hash('sha256', $ip . '|' . $ua . '|' . uniqid('', true)), 0, 24);
  setcookie('ps_vid', $id, time() + 86400 * 400, '/');
  $_COOKIE['ps_vid'] = $id;
  return $id;
}

function ok($x) { echo json_encode($x, JSON_UNESCAPED_UNICODE); exit(0); }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(array('error' => $msg)); exit(0); }

$method = $_SERVER['REQUEST_METHOD'];
$action = '';
$page = 'home';
$pw = '';

if ($method === 'GET') {
  $action = isset($_GET['action']) ? $_GET['action'] : 'hit';
  $page = isset($_GET['page']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['page']) : 'home';
  $pw = isset($_GET['pw']) ? $_GET['pw'] : '';
} else {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = $_POST;
  $action = isset($body['action']) ? $body['action'] : 'hit';
  $page = isset($body['page']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $body['page']) : 'home';
  $pw = isset($body['pw']) ? $body['pw'] : '';
}
if ($page === '') $page = 'home';

if ($action === 'hit') {
  $day = today_kst($TZ);
  $vid = visitor_id();
  $d = mutate_visits($FILE, function($d) use ($day, $vid, $page) {
    if (!isset($d['days'][$day]) || !is_array($d['days'][$day])) {
      $d['days'][$day] = array('visitors' => 0, 'pageviews' => 0, 'pages' => array());
    }
    if (!isset($d['days'][$day]['pages']) || !is_array($d['days'][$day]['pages'])) {
      $d['days'][$day]['pages'] = array();
    }
    if (!isset($d['seen'][$day]) || !is_array($d['seen'][$day])) {
      $d['seen'][$day] = array();
    }
    $d['days'][$day]['pageviews'] = intval($d['days'][$day]['pageviews']) + 1;
    $d['days'][$day]['pages'][$page] = (isset($d['days'][$day]['pages'][$page]) ? intval($d['days'][$day]['pages'][$page]) : 0) + 1;
    if (!isset($d['seen'][$day][$vid])) {
      $d['seen'][$day][$vid] = 1;
      $d['days'][$day]['visitors'] = intval($d['days'][$day]['visitors']) + 1;
    }
    return prune_visits($d);
  });
  if (!$d) fail('write failed', 500);
  $today = isset($d['days'][$day]) ? $d['days'][$day] : array('visitors' => 0, 'pageviews' => 0);
  ok(array(
    'ok' => true,
    'day' => $day,
    'visitors' => intval($today['visitors']),
    'pageviews' => intval($today['pageviews'])
  ));
}

if ($action === 'stats') {
  if (!in_array($pw, admin_pws($ADMIN_PWS, $EVENTS_FILE), true)) fail('unauthorized', 401);
  $d = load_visits($FILE);
  $days = isset($d['days']) ? $d['days'] : array();
  krsort($days);
  $out = array();
  $totalVisitors = 0;
  $totalPageviews = 0;
  foreach ($days as $day => $row) {
    $v = isset($row['visitors']) ? intval($row['visitors']) : 0;
    $pv = isset($row['pageviews']) ? intval($row['pageviews']) : 0;
    $pages = isset($row['pages']) && is_array($row['pages']) ? $row['pages'] : array();
    $totalVisitors += $v;
    $totalPageviews += $pv;
    $out[] = array(
      'date' => $day,
      'visitors' => $v,
      'pageviews' => $pv,
      'home' => isset($pages['home']) ? intval($pages['home']) : 0,
      'event' => isset($pages['event']) ? intval($pages['event']) : 0,
      'board' => isset($pages['board']) ? intval($pages['board']) : 0
    );
  }
  $today = today_kst($TZ);
  $todayRow = isset($days[$today]) ? $days[$today] : array('visitors' => 0, 'pageviews' => 0, 'pages' => array());
  ok(array(
    'ok' => true,
    'today' => $today,
    'todayVisitors' => isset($todayRow['visitors']) ? intval($todayRow['visitors']) : 0,
    'todayPageviews' => isset($todayRow['pageviews']) ? intval($todayRow['pageviews']) : 0,
    'totalVisitors' => $totalVisitors,
    'totalPageviews' => $totalPageviews,
    'days' => $out
  ));
}

fail('unknown action');
