<?php
declare(strict_types=1);
function adaSchema(PDO $db): void {$db->exec('CREATE TABLE IF NOT EXISTS ada_offers (visitor TEXT PRIMARY KEY, started INTEGER NOT NULL, code TEXT UNIQUE, expires INTEGER)');}
function adaValidate(PDO $db,string $code): string {
 adaSchema($db);$q=$db->prepare('SELECT expires FROM ada_offers WHERE code=?');$q->execute([$code]);$expiry=$q->fetchColumn();
 if(!$expiry||time()>=(int)$expiry)throw new InvalidArgumentException('Ada gift is invalid or expired. Remove it and review your total.');
 $q=$db->prepare('SELECT 1 FROM order_coupon_reservations WHERE code=?');$q->execute([$code]);if($q->fetchColumn())throw new InvalidArgumentException('Ada gift is already reserved or used.');return $code;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
require_once __DIR__.'/rewards.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
if(isset($_SERVER['HTTP_SEC_FETCH_SITE'])&&!in_array($_SERVER['HTTP_SEC_FETCH_SITE'],['same-origin','none'],true)){http_response_code(403);exit;}
try {
 $token=$_COOKIE['ofa_ada_visitor']??'';if(!preg_match('/^[a-f0-9]{64}$/D',$token)){$token=bin2hex(random_bytes(32));setcookie('ofa_ada_visitor',$token,['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);}
 $db=rwDb();adaSchema($db);$visitor=hash('sha256',$token);$now=time();$in=json_decode(file_get_contents('php://input',false,null,0,1024),true)?:[];
 $db->exec('BEGIN IMMEDIATE');
 $db->prepare('INSERT OR IGNORE INTO ada_offers(visitor,started) VALUES(?,?)')->execute([$visitor,$now]);
 $q=$db->prepare('SELECT * FROM ada_offers WHERE visitor=?');$q->execute([$visitor]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(($in['action']??'')==='claim'&&!$row['code']&&$now-(int)$row['started']>=300){$row['code']='OFA-ADA25-'.strtoupper(bin2hex(random_bytes(12)));$row['expires']=$now+600;$db->prepare('UPDATE ada_offers SET code=?,expires=? WHERE visitor=?')->execute([$row['code'],$row['expires'],$visitor]);}
 $db->exec('COMMIT');$available=false;if($row['code']){try{adaValidate($db,$row['code']);$available=true;}catch(InvalidArgumentException $e){}}
 echo json_encode(['ok'=>true,'available'=>$available,'issued'=>(bool)$row['code'],'code'=>$available?$row['code']:null,'expires'=>$available?(int)$row['expires']:null,'serverTime'=>$now,'wait'=>max(0,300-($now-(int)$row['started']))]);
}catch(Throwable $e){if(isset($db)){try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}}http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Offer temporarily unavailable']);}
