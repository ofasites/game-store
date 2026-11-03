<?php
declare(strict_types=1);
require_once __DIR__.'/rewards.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'POST required']);exit;}
try {
 $raw=file_get_contents('php://input',false,null,0,2049);
 if(strlen($raw)>2048)throw new InvalidArgumentException('Invalid request.');
 $in=json_decode($raw,true);
 if(!is_array($in)||!is_string($in['code']??null))throw new InvalidArgumentException('Invalid request.');
 $db=rwDb();c50Validate($db,strtoupper(trim($in['code'])));
 $catalog=json_decode((string)file_get_contents(__DIR__.'/reward-catalog.json'),true);
 if(!is_array($catalog))throw new RuntimeException('Catalog unavailable');
 $meta=[];$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));$ids=[];
 foreach($catalog['products'] as $id=>$p)if(c50Eligible($id,$p,$meta,$now))$ids[]=$id;
 echo json_encode(['ok'=>true,'percent'=>50,'eligibleProductIds'=>$ids,'date'=>$now->format('Y-m-d'),'timezone'=>'Asia/Bangkok','reservation'=>'on_order_submission']);
}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Coupon validation temporarily unavailable.']);}
