<?php
/**
 * 파마스퀘어 입점 문의 메일 핸들러
 * Gabia 호스팅 서버에 업로드 후 form에서 POST 요청 수신
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$to      = 'official@phamasquare.com';
$type    = isset($_POST['type'])  ? strip_tags($_POST['type'])  : '입점 문의';
$name    = isset($_POST['name'])  ? strip_tags($_POST['name'])  : '';
$phone   = isset($_POST['phone']) ? strip_tags($_POST['phone']) : '';
$email   = isset($_POST['email']) ? strip_tags($_POST['email']) : '';
$content = isset($_POST['body'])  ? strip_tags($_POST['body'])  : '';

if (empty($name) || empty($phone)) {
    echo json_encode(['success' => false, 'error' => '필수 항목 누락']);
    exit;
}

$subject = '=?UTF-8?B?' . base64_encode('[파마스퀘어] ' . $type . ' - ' . $name) . '?=';

$body  = "■ 파마스퀘어 " . $type . " 문의\r\n";
$body .= "━━━━━━━━━━━━━━━━━━━━━━\r\n\r\n";
$body .= $content;
$body .= "\r\n\r\n━━━━━━━━━━━━━━━━━━━━━━\r\n";
$body .= "* 이 메일은 파마스퀘어 홈페이지 입점 문의 폼에서 자동 발송되었습니다.";

$from_email = !empty($email) ? $email : 'noreply@phamasquare.com';
$reply_to   = !empty($email) ? $email : 'noreply@phamasquare.com';

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: base64\r\n";
$headers .= "From: =?UTF-8?B?" . base64_encode('파마스퀘어 홈페이지') . "?= <noreply@phamasquare.com>\r\n";
$headers .= "Reply-To: " . $reply_to . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$result = mail($to, $subject, base64_encode($body), $headers);

if ($result) {
    echo json_encode(['success' => true, 'message' => '문의가 접수되었습니다.']);
} else {
    // mail() 실패 시 로그
    error_log('[파마스퀘어] mail() 실패 - ' . $type . ' / ' . $name . ' / ' . $phone);
    echo json_encode(['success' => false, 'error' => '발송 실패. 잠시 후 다시 시도해주세요.']);
}
?>
