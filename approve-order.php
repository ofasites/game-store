<?php
declare(strict_types=1);
ini_set('display_errors','0');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
ini_set('session.use_strict_mode','1');session_name('ofa_approval');session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict','path'=>'/']);session_start();
require __DIR__.'/rewards.php';
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$error='';$done=false;$o=null;
try{
 $db=rwDb();$id=(string)($_POST['id']??$_GET['id']??'');$token=(string)($_POST['token']??$_GET['token']??'');
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Invalid approval link.');
 $q=$db->prepare('SELECT * FROM reward_orders WHERE id=? AND approval_hash=?');$q->execute([$id,hash('sha256',$token)]);$o=$q->fetch(PDO::FETCH_ASSOC);if(!$o)throw new RuntimeException('Order not found.');
 if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
 if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))||($_POST['checked']??'')!=='yes')throw new RuntimeException('Confirm that you checked the incoming payment.');
 $o=rwApprove($db,$id,$token);$done=true;
 }
}catch(Throwable $e){http_response_code(400);$error=$e instanceof PDOException?'Order storage unavailable.':$e->getMessage();}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>One For All — Payment review</title><style>body{background:#11121b;color:#eee;font:16px system-ui;margin:0;padding:30px 16px}main{max-width:700px;margin:auto;background:#202130;border:1px solid #45435e;border-radius:20px;padding:28px}h1{font-size:26px}small,p{color:#bcb9d1}button{background:#7759e7;border:0;border-radius:10px;padding:15px 22px;color:white;font-weight:700;cursor:pointer}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:14px system-ui;background:#14151f;padding:18px;border-radius:12px}label{display:block;margin:22px 0;line-height:1.6}.ok{color:#9cdea8}.error{color:#ffb6b6}</style><main><small>ONEFORALL · SELLER PAYMENT REVIEW</small><h1>Verify incoming payment</h1><p>This private link authorizes payment approval. Do not forward it to customers. Verify the actual bank or KPay transfer before approving.</p><?php if($error):?><p class="error"><?=h($error)?></p><?php elseif($o):?><h2><?=h($o['id'])?></h2><p>Total: <strong><?=h(number_format((int)$o['total']/($o['currency']==='THB'?100:1),$o['currency']==='THB'?2:0))?> <?=h($o['currency'])?></strong></p><p>Points after approval: <?=$o['customer']?(int)$o['points']:'0 (guest order)'?></p><pre><?=h($o['receipt'])?></pre><?php if($done||$o['status']==='payment_verified'):?><p class="ok">Payment verified. Account points were awarded once. Delivery remains manual through Telegram.</p><?php else:?><form method="post"><input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="token" value="<?=h($token)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label><input type="checkbox" name="checked" value="yes" required> I have checked the actual incoming payment and the order details.</label><button type="submit">Approve payment & award points</button></form><?php endif;endif;?></main></html>
