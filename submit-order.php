<?php
// Requires PHP 7.4+ and ../ofa-private/mail-config.php outside public_html.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respond(int $status, array $data): void { http_response_code($status); echo json_encode($data); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok'=>false,'error'=>'Use checkout to submit an order.']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, ['https://oneforallgameservice.com','https://www.oneforallgameservice.com'], true)) respond(403,['ok'=>false,'error'=>'Invalid origin.']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 30000) respond(413,['ok'=>false,'error'=>'Order too large.']);
$input = json_decode(file_get_contents('php://input', false, null, 0, 30001), true);
if (!is_array($input)) respond(400,['ok'=>false,'error'=>'Invalid order.']);
$id = $input['orderId'] ?? '';
$receipt = $input['receipt'] ?? '';
if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{5,80}$/D', $id) || !is_string($receipt) || strlen($receipt)<80 || strlen($receipt)>25000) respond(400,['ok'=>false,'error'=>'Missing order details.']);
$private = dirname(__DIR__) . '/ofa-private';
$configFile = $private . '/mail-config.php';
if (!is_file($configFile)) respond(503,['ok'=>false,'error'=>'Order email is not configured. Please contact us on Telegram.']);
function smtpRead($stream, array $allowed): void {
    do { $line = fgets($stream, 4096); if ($line === false) throw new RuntimeException('SMTP connection ended'); } while (isset($line[3]) && $line[3] === '-');
    if (!in_array((int)substr($line,0,3),$allowed,true)) throw new RuntimeException('SMTP response rejected');
}
function smtpWrite($stream, string $command, array $allowed): void {
    $data=$command."\r\n"; $offset=0;
    while ($offset<strlen($data)) { $written=fwrite($stream,substr($data,$offset)); if (!$written) throw new RuntimeException('SMTP write failed'); $offset+=$written; }
    smtpRead($stream,$allowed);
}
$lock = null;
try {
    $config = require $configFile;
    if (!is_array($config) || empty($config['smtp_password']) || $config['smtp_password']==='REPLACE_WITH_YOUR_MAILBOX_PASSWORD') throw new RuntimeException('Configuration incomplete');
    // File locks and successful IDs prevent accidental resubmission on this server.
    $lock = fopen($private.'/order-mail.lock','c+');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock order queue');
    $ledgerFile=$private.'/order-mail-state.json';
    $ledger=is_file($ledgerFile)?json_decode(file_get_contents($ledgerFile),true):[];
    if (!is_array($ledger)) $ledger=[];
    $now=time();
    foreach ($ledger as $key=>$timestamp) if ((int)$timestamp<$now-86400*7) unset($ledger[$key]);
    $orderKey='order:'.$id;
    if (isset($ledger[$orderKey])) respond(200,['ok'=>true,'orderId'=>$id,'message'=>'Order already submitted. Payment awaiting verification.']);
    $ipKey='ip:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
    if (isset($ledger[$ipKey]) && $now-(int)$ledger[$ipKey]<30) respond(429,['ok'=>false,'error'=>'Please wait 30 seconds before submitting another order.']);
    $ledger[$ipKey]=$now;
    if (file_put_contents($ledgerFile,json_encode($ledger),LOCK_EX)===false) throw new RuntimeException('Cannot save submission state');
    $host='smtp.hostinger.com';
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
    $stream=stream_socket_client('ssl://'.$host.':465',$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);
    if (!$stream) throw new RuntimeException('SMTP unavailable');
    stream_set_timeout($stream,20);
    smtpRead($stream,[220]);
    smtpWrite($stream,'EHLO oneforallgameservice.com',[250]);
    smtpWrite($stream,'AUTH LOGIN',[334]);
    smtpWrite($stream,base64_encode('ofasupport@oneforallgameservice.com'),[334]);
    smtpWrite($stream,base64_encode((string)$config['smtp_password']),[235]);
    smtpWrite($stream,'MAIL FROM:<ofasupport@oneforallgameservice.com>',[250]);
    smtpWrite($stream,'RCPT TO:<oneforallgameservice@gmail.com>',[250,251]);
    smtpWrite($stream,'DATA',[354]);
    $body="NEW CUSTOMER ORDER — PAYMENT NOT VERIFIED\n\n".$receipt."\n\nCustomer-submitted details. Verify catalog prices, discount eligibility and incoming payment before delivery.\nDelivery: Telegram @oneforallgamekeys\n";
    $message='Date: '.gmdate('D, d M Y H:i:s').' +0000'."\r\n".
        'From: One For All Game Service <ofasupport@oneforallgameservice.com>'."\r\n".
        'To: oneforallgameservice@gmail.com'."\r\n".
        'Subject: New order '.$id.' - payment awaiting verification'."\r\n".
        'Message-ID: <'.bin2hex(random_bytes(16)).'@oneforallgameservice.com>'."\r\n".
        "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".
        rtrim(chunk_split(base64_encode($body),76,"\r\n"));
    smtpWrite($stream,$message."\r\n.",[250]);
    fclose($stream);
    $ledger[$orderKey]=$now;
    file_put_contents($ledgerFile,json_encode($ledger),LOCK_EX);
    respond(200,['ok'=>true,'orderId'=>$id,'message'=>'Order submitted. Payment awaiting verification. Delivery through Telegram.']);
} catch (Throwable $e) {
    error_log('OFA order email failed: '.get_class($e));
    respond(503,['ok'=>false,'error'=>'We could not confirm email submission. Please contact @oneforallgamekeys with your receipt before retrying.']);
}
