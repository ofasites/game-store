<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function promoReply(int $status,array $data): void {http_response_code($status);echo json_encode($data);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')promoReply(405,['ok'=>false,'error'=>'POST required.']);
$expected=getenv('OFA_COUPON_ADMIN_TOKEN')?:'';
if(strlen($expected)<32)promoReply(503,['ok'=>false,'error'=>'Promotion administration requires the coupon admin server setup.']);
$auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
if(!hash_equals('Bearer '.$expected,$auth))promoReply(401,['ok'=>false,'error'=>'Invalid admin credentials.']);
require_once __DIR__.'/promotion-store.php';
try {
 $raw=file_get_contents('php://input',false,null,0,8193);
 if(strlen($raw)>8192)throw new InvalidArgumentException('Request too large.');
 $input=json_decode($raw,true);
 if(!is_array($input))throw new InvalidArgumentException('Invalid request.');
 $dir=dirname(__DIR__).'/ofa-private';
 if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Storage unavailable.');
 $db=new PDO('sqlite:'.$dir.'/customers.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('PRAGMA busy_timeout=5000');
 $p=ofaPromotionAction($db,$input,time());
 promoReply(200,['ok'=>true,'promotion'=>$p,'serverTime'=>time(),'checkoutReady'=>false]);
} catch(InvalidArgumentException $e){promoReply(400,['ok'=>false,'error'=>$e->getMessage()]);}
catch(LogicException $e){promoReply(409,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){promoReply(503,['ok'=>false,'error'=>'Promotion storage unavailable. Refresh before retrying.']);}
