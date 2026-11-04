<?php
declare(strict_types=1);
require_once __DIR__.'/rewards.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
// Configure only on the server, outside the public repository.
$expected=getenv('OFA_COUPON_ADMIN_TOKEN')?:'';
if(strlen($expected)<32){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Coupon administration is not configured on the server.']);exit;}
$auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
if(!hash_equals('Bearer '.$expected,$auth)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Invalid coupon admin credentials.']);exit;}
try{$raw=file_get_contents('php://input',false,null,0,32769);if(strlen($raw)>32768)throw new InvalidArgumentException('Request too large.');$in=json_decode($raw,true);if(!is_array($in))throw new InvalidArgumentException('Invalid request.');$db=rwDb();mcSchema($db);$action=$in['action']??'list';
if($action==='create'){$out=mcCreate($db,$in);}
elseif($action==='list'){$out=[];foreach($db->query('SELECT * FROM managed_coupons WHERE state<>"deleted" ORDER BY created DESC,code LIMIT 10000') as $r)$out[]=mcPublic($db,$r);}
elseif(in_array($action,['disable','delete'],true)){$code=strtoupper(trim((string)($in['code']??'')));$db->exec('BEGIN IMMEDIATE');try{$r=mcGet($db,$code);if(!$r)throw new InvalidArgumentException('Code not found.');[$used,$reserved]=mcCounts($db,$code);if($action==='delete'&&($used||$reserved))throw new InvalidArgumentException('Used or reserved codes can only be disabled.');$db->prepare('UPDATE managed_coupons SET state=? WHERE code=?')->execute([$action==='delete'?'deleted':'disabled',$code]);$db->exec('COMMIT');$out=[];}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}}
else throw new InvalidArgumentException('Unknown action.');echo json_encode(['ok'=>true,'coupons'=>$out]);
}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Coupon service unavailable.']);}
