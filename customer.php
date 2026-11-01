<?php
// Customer accounts. Requires PHP 7.4+, PDO SQLite, OpenSSL and ../ofa-private/mail-config.php.
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function reply(int $status,array $body): void { http_response_code($status); echo json_encode($body); exit; }
$origin='https://oneforallgameservice.com';
$allowed=[$origin,'https://www.oneforallgameservice.com'];
if (!empty($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'],$allowed,true)) reply(403,['error'=>'Invalid origin.']);
ini_set('session.use_strict_mode','1');
session_name('ofa_customer');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
if (isset($_SESSION['last']) && time()-$_SESSION['last']>7200) { unset($_SESSION['uid']); session_regenerate_id(true); }
$_SESSION['last']=time();
$private=dirname(__DIR__).'/ofa-private';
try {
 if (!is_dir($private) || !is_writable($private) || !extension_loaded('pdo_sqlite')) throw new RuntimeException('Private storage or PDO SQLite unavailable');
 $db=new PDO('sqlite:'.$private.'/customers.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('PRAGMA busy_timeout=5000');
 @chmod($private.'/customers.sqlite',0600);
 $db->exec('CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL, name TEXT NOT NULL, telegram TEXT NOT NULL DEFAULT "", verified INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS customer_tokens (token TEXT PRIMARY KEY, customer INTEGER NOT NULL, kind TEXT NOT NULL, expires INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS customer_limits (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL)');
 $db->exec('DELETE FROM customer_tokens WHERE expires < '.time());
 $db->exec('DELETE FROM customer_limits WHERE expires < '.time());
} catch(Throwable $e) { error_log('OFA customer storage: '.$e->getMessage()); reply(503,['error'=>'Customer accounts need server setup. Guest checkout is still available.']); }
require_once __DIR__.'/rewards.php';
rwDb();
function customer(): ?array { global $db; if(empty($_SESSION['uid'])) return null; $q=$db->prepare('SELECT id,email,name,telegram,verified FROM customers WHERE id=?'); $q->execute([$_SESSION['uid']]); $u=$q->fetch(PDO::FETCH_ASSOC); if(!$u)return null; $u['verified']=(bool)$u['verified']; unset($u['id']); return $u; }
if(empty($_SESSION['google_nonce']))$_SESSION['google_nonce']=bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD']==='GET') reply(200,['ok'=>true,'csrf'=>$_SESSION['csrf'],'googleNonce'=>$_SESSION['google_nonce'],'customer'=>customer()]);
if ($_SERVER['REQUEST_METHOD']!=='POST') reply(405,['error'=>'Method not allowed.']);
if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) reply(413,['error'=>'Request too large.']);
$in=json_decode(file_get_contents('php://input',false,null,0,12001),true);
if(!is_array($in) || !hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) reply(403,['error'=>'Refresh the page and try again.']);
function text(array $in,string $key,int $max): string { $v=$in[$key]??''; if(!is_string($v)||strlen($v)>$max) reply(400,['error'=>'Invalid '.$key.'.']); return trim($v); }
function limit(string $bucket,int $max,int $seconds): void { global $db; $key=hash('sha256',$bucket.'|'.($_SERVER['REMOTE_ADDR']??'')); $db->beginTransaction(); try { $q=$db->prepare('SELECT count,expires FROM customer_limits WHERE key=?'); $q->execute([$key]); $r=$q->fetch(PDO::FETCH_ASSOC); if($r && $r['expires']>time() && $r['count']>=$max) { $db->rollBack(); reply(429,['error'=>'Too many attempts. Please try again later.']); } $count=$r && $r['expires']>time()?(int)$r['count']+1:1; $expires=$r && $r['expires']>time()?(int)$r['expires']:time()+$seconds; $q=$db->prepare('INSERT OR REPLACE INTO customer_limits(key,count,expires) VALUES(?,?,?)'); $q->execute([$key,$count,$expires]); $db->commit(); }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;} }
function smtpResponse($s,array $codes): void { do { $l=fgets($s,4096); if($l===false)throw new RuntimeException('SMTP disconnected'); }while(isset($l[3])&&$l[3]==='-'); if(!in_array((int)substr($l,0,3),$codes,true)) throw new RuntimeException('SMTP rejected'); }
function smtpCommand($s,string $command,array $codes): void { $data=$command."\r\n"; for($i=0;$i<strlen($data);$i+=$n){$n=fwrite($s,substr($data,$i));if(!$n)throw new RuntimeException('SMTP write');} smtpResponse($s,$codes); }
function emailLink(string $email,string $kind,string $token): void {
 global $private,$origin;
 $cfg=require $private.'/mail-config.php';
 if(empty($cfg['smtp_password']) || $cfg['smtp_password']==='REPLACE_WITH_YOUR_MAILBOX_PASSWORD')throw new RuntimeException('Mail not configured');
 $host='smtp.hostinger.com';
 $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
 $s=stream_socket_client('ssl://'.$host.':465',$errno,$error,15,STREAM_CLIENT_CONNECT,$ctx);
 if(!$s)throw new RuntimeException('Mail unavailable'); stream_set_timeout($s,20);
 try {
 smtpResponse($s,[220]);smtpCommand($s,'EHLO oneforallgameservice.com',[250]);smtpCommand($s,'AUTH LOGIN',[334]);smtpCommand($s,base64_encode('ofasupport@oneforallgameservice.com'),[334]);smtpCommand($s,base64_encode($cfg['smtp_password']),[235]);smtpCommand($s,'MAIL FROM:<ofasupport@oneforallgameservice.com>',[250]);smtpCommand($s,'RCPT TO:<'.$email.'>',[250,251]);smtpCommand($s,'DATA',[354]);
 $reset=$kind==='reset'; $subject=$reset?'Reset your One For All password':'Verify your One For All email';
 $link=$origin.'/#account='.$kind.'&token='.$token; $expiry=$reset?'30 minutes':'24 hours'; $b='ofa_'.bin2hex(random_bytes(8));
 $heading=$reset?'Reset your password':'Verify your email address';
 $intro=$reset?'We received a request to reset the password for your One For All account. Press the button below to choose a new password.':'Thanks for creating a One For All account. To finish setting it up and confirm this email address, press the button below.';
 $btn=$reset?'RESET PASSWORD':'VERIFY MY EMAIL';
 $body=$heading."\n\n".$intro."\n\n".$link."\n\nThis link expires in ".$expiry.".\nIf you did not request this, you can safely ignore this email.\n\nOne For All Game Service\nSupport: https://t.me/oneforallgamekeys";
 $html='<!DOCTYPE html><html><body style="margin:0;padding:0;background:#171a21;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#171a21;"><tr><td align="center" style="padding:32px 12px;"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;font-family:Arial,Helvetica,sans-serif;">'
 .'<tr><td style="background:#0f1118;padding:22px 28px;border-radius:6px 6px 0 0;border-bottom:2px solid #6a5cff;"><span style="font-size:26px;font-weight:bold;font-style:italic;letter-spacing:1px;color:#ffffff;">ONE<span style="color:#9d8cff;">FORALL</span></span> <span style="font-size:11px;letter-spacing:3px;color:#8f98a0;">GAME SERVICE</span></td></tr>'
 .'<tr><td style="background:#1b2838;padding:34px 28px 10px 28px;"><div style="font-size:22px;font-weight:bold;color:#ffffff;margin-bottom:16px;">'.$heading.'</div><div style="font-size:15px;line-height:23px;color:#c6d4df;">'.$intro.'</div></td></tr>'
 .'<tr><td align="center" style="background:#1b2838;padding:28px;"><a href="'.$link.'" style="display:inline-block;background:#6a5cff;color:#ffffff;font-size:15px;font-weight:bold;letter-spacing:1px;text-decoration:none;padding:15px 38px;border-radius:4px;">'.$btn.'</a><div style="font-size:12px;color:#8f98a0;margin-top:14px;">This link expires in '.$expiry.'.</div></td></tr>'
 .'<tr><td style="background:#1b2838;padding:0 28px 28px 28px;"><div style="background:#16202d;border-radius:4px;padding:14px 16px;font-size:12px;line-height:19px;color:#8f98a0;">Button not working? Copy and paste this link into your browser:<br><a href="'.$link.'" style="color:#9d8cff;word-break:break-all;">'.$link.'</a></div></td></tr>'
 .'<tr><td style="background:#1b2838;padding:0 28px 28px 28px;font-size:13px;line-height:20px;color:#c6d4df;">If you did not request this, you can safely ignore this email. Nothing will change on your account.</td></tr>'
 .'<tr><td style="background:#0f1118;padding:20px 28px;border-radius:0 0 6px 6px;font-size:12px;line-height:19px;color:#8f98a0;">One For All Game Service &middot; oneforallgameservice.com<br>Need help? Message us on Telegram: <a href="https://t.me/oneforallgamekeys" style="color:#9d8cff;">@oneforallgamekeys</a><br>This is an automated message. Please do not reply with passwords or payment details.</td></tr>'
 .'</table></td></tr></table></body></html>';
 $msg='Date: '.gmdate('D, d M Y H:i:s').' +0000'."\r\n".'From: One For All <ofasupport@oneforallgameservice.com>'."\r\n".'To: <'.$email.'>'."\r\n".'Subject: '.$subject."\r\n".'Message-ID: <'.bin2hex(random_bytes(16)).'@oneforallgameservice.com>'."\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"".$b."\"\r\n\r\n--".$b."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n")."\r\n--".$b."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html),76,"\r\n")."\r\n--".$b."--";
 smtpCommand($s,rtrim($msg)."\r\n.",[250]);
 }finally{fclose($s);}
}
function issue(int $uid,string $kind,string $email): void { global $db; $token=bin2hex(random_bytes(32));$q=$db->prepare('INSERT INTO customer_tokens(token,customer,kind,expires) VALUES(?,?,?,?)');$q->execute([hash('sha256',$token),$uid,$kind,time()+($kind==='reset'?1800:86400)]);try{emailLink($email,$kind,$token);}catch(Throwable $e){$db->prepare('DELETE FROM customer_tokens WHERE token=?')->execute([hash('sha256',$token)]);throw $e;} }
try {
 $action=text($in,'action',30);
 if($action==='google'){
 limit('google',20,900);
 $credential=text($in,'credential',10000);
 if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$credential))reply(400,['error'=>'Invalid Google sign-in response.']);
 if(!function_exists('curl_init'))reply(503,['error'=>'Google sign-in requires PHP cURL. Use email login for now.']);
 $ch=curl_init('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($credential));
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
 $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false||$status!==200)reply(401,['error'=>'Google could not verify this sign-in. Please try again.']);
 $g=json_decode($raw,true);
 // tokeninfo validates this exact token; optional claims such as nonce may
 // only be present in its signed payload. Never trust this payload before
 // Google has accepted the token above.
 $parts=explode('.',$credential);
 $payload=count($parts)===3?$parts[1]:'';
 $payload.=str_repeat('=',(4-strlen($payload)%4)%4);
 $decoded=base64_decode(strtr($payload,'-_','+/'),true);
 $claims=$decoded===false?null:json_decode($decoded,true);
 if(!is_array($claims))reply(401,['error'=>'Invalid Google sign-in token. Please try again.']);
 $expectedNonce=(string)($_SESSION['google_nonce']??'');
 if($expectedNonce===''||!is_string($claims['nonce']??null)||!hash_equals($expectedNonce,$claims['nonce'])){
  error_log('OFA Google validation: session nonce mismatch');
  reply(401,['error'=>'Your Google sign-in session expired. Refresh this page, then try Google login again.']);
 }
 if(!is_array($g)||($g['aud']??'')!=='915039082117-4eu73pdmohp7tqk7bbpvevdjel23j07s.apps.googleusercontent.com'||!in_array($g['iss']??'',['accounts.google.com','https://accounts.google.com'],true)||(int)($g['exp']??0)<=time()||(isset($g['iat'])&&(int)$g['iat']>time()+120)||!in_array($g['email_verified']??false,[true,'true'],true)||empty($g['sub'])||strlen((string)$g['sub'])>255)reply(401,['error'=>'Google sign-in validation failed. Refresh and try again.']);
 $email=strtolower((string)($g['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))reply(400,['error'=>'Google did not provide a valid email.']);
 $db->exec('CREATE TABLE IF NOT EXISTS customer_google (subject TEXT PRIMARY KEY, customer INTEGER NOT NULL UNIQUE)');
 $db->beginTransaction();
 $q=$db->prepare('SELECT customer FROM customer_google WHERE subject=?');$q->execute([$g['sub']]);$linked=$q->fetchColumn();
 if(!empty($_SESSION['uid'])){
 $current=customer();if(!$current||$current['email']!==$email){$db->rollBack();reply(409,['error'=>'Choose the Google account matching your current account email.']);}
 if($linked&&(int)$linked!==(int)$_SESSION['uid']){$db->rollBack();reply(409,['error'=>'Google account is already linked to another customer.']);}
 $uid=(int)$_SESSION['uid'];
 $q=$db->prepare('SELECT subject FROM customer_google WHERE customer=?');$q->execute([$uid]);$subject=$q->fetchColumn();
 if($subject&&$subject!==$g['sub']){$db->rollBack();reply(409,['error'=>'A different Google account is already linked.']);}
 }elseif($linked){$uid=(int)$linked;}
 else{
 $q=$db->prepare('SELECT id FROM customers WHERE email=?');$q->execute([$email]);
 if($q->fetchColumn()){$db->rollBack();reply(409,['error'=>'An account already uses this email. Log in with your password first, then select Link Google in your profile. Use Forgot password if needed.']);}
 $name=substr(trim((string)($g['name']??'Customer')),0,80);if($name==='')$name='Customer';
 $db->prepare('INSERT INTO customers(email,password,name,verified,created) VALUES(?,?,?,?,?)')->execute([$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$name,1,time()]);$uid=(int)$db->lastInsertId();
 }
 $db->prepare('INSERT OR IGNORE INTO customer_google(subject,customer) VALUES(?,?)')->execute([$g['sub'],$uid]);
 $db->prepare('UPDATE customers SET verified=1 WHERE id=? AND email=?')->execute([$uid,$email]);
 $db->commit();session_regenerate_id(true);$_SESSION['uid']=$uid;
 reply(200,['ok'=>true,'customer'=>customer(),'message'=>'Google connected. You are logged in.']);
 }

 if(in_array($action,['rewards','redeem','coupon'],true)){
 if(empty($_SESSION['uid'])||!customer())reply(401,['error'=>'Please log in to view your rewards.']);
 $uid=(int)$_SESSION['uid'];
 try{
 if($action==='redeem'){limit('redeem',10,3600);$reward=rwRedeem($db,$uid,(int)($in['cost']??0));reply(200,['ok'=>true,'reward'=>$reward,'rewards'=>rwSummary($db,$uid)]);}
 if($action==='coupon'){$coupon=rwCoupon($db,$uid,strtoupper(text($in,'code',80)));reply(200,['ok'=>true,'coupon'=>$coupon]);}
 reply(200,['ok'=>true,'rewards'=>rwSummary($db,$uid)]);
 }catch(InvalidArgumentException $e){reply(400,['error'=>$e->getMessage()]);}
 }

 if($action==='logout'){ $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(200,['ok'=>true,'csrf'=>$_SESSION['csrf'],'customer'=>null]); }
 if($action==='profile'){
 if(!customer())reply(401,['error'=>'Please log in.']);
 $name=text($in,'name',80);$tg=text($in,'telegram',80);if($name==='')reply(400,['error'=>'Enter your name.']);
 $db->prepare('UPDATE customers SET name=?,telegram=? WHERE id=?')->execute([$name,$tg,$_SESSION['uid']]);reply(200,['ok'=>true,'customer'=>customer(),'message'=>'Profile saved.']);
 }
 if($action==='verify' || $action==='reset'){
 limit('token',20,900);$token=text($in,'token',64);if(!preg_match('/^[a-f0-9]{64}$/D',$token))reply(400,['error'=>'Invalid or expired link.']);
 $pass=$in['password']??'';if($action==='reset' && (!is_string($pass)||strlen($pass)<12||strlen($pass)>72))reply(400,['error'=>'Use a password of 12–72 bytes.']);
 $db->beginTransaction();$q=$db->prepare('SELECT * FROM customer_tokens WHERE token=? AND kind=? AND expires>?');$q->execute([hash('sha256',$token),$action,time()]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r){$db->rollBack();reply(400,['error'=>'Invalid or expired link. Request a new one.']);}
 if($action==='verify')$db->prepare('UPDATE customers SET verified=1 WHERE id=?')->execute([$r['customer']]);
 else {$db->prepare('UPDATE customers SET password=? WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$r['customer']]);unset($_SESSION['uid']);session_regenerate_id(true);}
 $db->prepare('DELETE FROM customer_tokens WHERE customer=? AND kind=?')->execute([$r['customer'],$action]);$db->commit();
 reply(200,['ok'=>true,'message'=>$action==='verify'?'Email verified. You can log in.':'Password reset. Log in with your new password.','customer'=>customer()]);
 }
 if($action==='resend'){
 $u=customer();if(!$u)reply(401,['error'=>'Please log in.']);limit('mail',3,3600);if(!$u['verified'])issue((int)$_SESSION['uid'],'verify',$u['email']);reply(200,['ok'=>true,'message'=>'Verification email sent. Check your inbox and spam folder.']);
 }
 if(!in_array($action,['register','login','forgot'],true))reply(400,['error'=>'Unknown action.']);
 $email=strtolower(text($in,'email',254));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))reply(400,['error'=>'Enter a valid email address.']);
 limit($action,$action==='login'?15:5,$action==='login'?900:3600);
 $q=$db->prepare('SELECT * FROM customers WHERE email=?');$q->execute([$email]);$u=$q->fetch(PDO::FETCH_ASSOC);
 if($action==='forgot'){if($u){try{issue((int)$u['id'],'reset',$email);}catch(Throwable $e){error_log('OFA reset mail failed');}} reply(200,['ok'=>true,'message'=>'If an account exists, a reset link will arrive shortly. Check spam too.']);}
 $pass=$in['password']??'';if(!is_string($pass)||strlen($pass)<12||strlen($pass)>72)reply(400,['error'=>'Use a password of 12–72 bytes.']);
 if($action==='register'){
 $name=text($in,'name',80);if(!$name)reply(400,['error'=>'Enter your name.']);
 if($u)reply(400,['error'=>'Unable to create account. Try logging in or resetting your password.']);
 $db->prepare('INSERT INTO customers(email,password,name,created) VALUES(?,?,?,?)')->execute([$email,password_hash($pass,PASSWORD_DEFAULT),$name,time()]);$uid=(int)$db->lastInsertId();
 session_regenerate_id(true);$_SESSION['uid']=$uid;
 try{issue($uid,'verify',$email);$message='Account created. Check your email to verify your address.';}catch(Throwable $e){error_log('OFA verification mail failed');$message='Account created. Verification email could not be sent. Use Resend verification in your profile.';}
 reply(200,['ok'=>true,'customer'=>customer(),'message'=>$message]);
 }
 if(!$u || !password_verify($pass,$u['password']))reply(401,['error'=>'Email or password is incorrect.']);
 session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];reply(200,['ok'=>true,'customer'=>customer(),'message'=>'You are logged in.']);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('OFA customer request: '.get_class($e));reply(503,['error'=>'Account service is temporarily unavailable. Guest checkout is still available.']);}
