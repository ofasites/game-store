<?php
declare(strict_types=1);
require_once __DIR__.'/rewards.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'POST required']);exit;}
try {
 $raw=file_get_contents('php://input',false,null,0,32769);
 if(strlen($raw)>32768)throw new InvalidArgumentException('Invalid request.');
 $in=json_decode($raw,true);
 if(!is_array($in)||!is_string($in['code']??null))throw new InvalidArgumentException('Invalid request.');
 $db=rwDb();$code=strtoupper(trim($in['code']));$ada=strpos($code,'OFA-ADA25-')===0;if($ada){adaValidate($db,$code);}else{c50Validate($db,$code);}
 $catalog=json_decode((string)file_get_contents(__DIR__.'/reward-catalog.json'),true);
 if(!is_array($catalog))throw new RuntimeException('Catalog unavailable');
 if(!$ada)c50CheckMinimum($code,is_array($in['items']??null)?$in['items']:[],$catalog['products']);
 $meta=[];$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));$ids=[];
 foreach($catalog['products'] as $id=>$p)if(c50Eligible($id,$p,$meta,$now))$ids[]=$id;
 echo json_encode(['ok'=>true,'percent'=>$ada?25:c50Percent($code),'minQuantity'=>$ada?0:c50Minimum($code),'eligibleProductIds'=>$ids,'date'=>$now->format('Y-m-d'),'timezone'=>'Asia/Bangkok','reservation'=>'on_order_submission']);
}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Coupon validation temporarily unavailable.']);}
