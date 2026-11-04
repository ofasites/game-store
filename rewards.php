<?php
// Shared private order and rewards storage. No credentials are stored in public files.
declare(strict_types=1);
require_once __DIR__.'/campaign50.php';
require_once __DIR__.'/ada-offer.php';
function rwDb(): PDO {
 $dir=dirname(__DIR__).'/ofa-private';
 if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Private storage unavailable');
 $db=new PDO('sqlite:'.$dir.'/customers.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('PRAGMA busy_timeout=5000');
 $db->exec('CREATE TABLE IF NOT EXISTS reward_orders (id TEXT PRIMARY KEY, customer INTEGER, currency TEXT NOT NULL, subtotal INTEGER NOT NULL, discount INTEGER NOT NULL, total INTEGER NOT NULL, items TEXT NOT NULL, receipt TEXT NOT NULL, status TEXT NOT NULL, points INTEGER NOT NULL, coupon TEXT, approval_hash TEXT NOT NULL, approval_token TEXT NOT NULL, payload_hash TEXT NOT NULL, created INTEGER NOT NULL, approved INTEGER, notified INTEGER NOT NULL DEFAULT 0)');
 $db->exec('CREATE TABLE IF NOT EXISTS reward_ledger (id INTEGER PRIMARY KEY, customer INTEGER NOT NULL, amount INTEGER NOT NULL, reason TEXT NOT NULL, reference TEXT NOT NULL UNIQUE, created INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS reward_coupons (code TEXT PRIMARY KEY, customer INTEGER NOT NULL, percent INTEGER NOT NULL, cost INTEGER NOT NULL, state TEXT NOT NULL DEFAULT "available", order_id TEXT UNIQUE, created INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS stripe_payments (order_id TEXT PRIMARY KEY, binding TEXT NOT NULL, session_id TEXT UNIQUE, checkout_url TEXT, expires INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS order_coupon_reservations (code TEXT PRIMARY KEY, order_id TEXT NOT NULL UNIQUE, consumed INTEGER NOT NULL DEFAULT 0)');
 $columns=$db->query('PRAGMA table_info(order_coupon_reservations)')->fetchAll(PDO::FETCH_COLUMN,1);
 if(!in_array('consumed',$columns,true)){try{$db->exec('ALTER TABLE order_coupon_reservations ADD COLUMN consumed INTEGER NOT NULL DEFAULT 0');}catch(PDOException $e){$columns=$db->query('PRAGMA table_info(order_coupon_reservations)')->fetchAll(PDO::FETCH_COLUMN,1);if(!in_array('consumed',$columns,true))throw $e;}}
 @chmod($dir.'/customers.sqlite',0600);return $db;
}
function rwBalance(PDO $db,int $uid): int { $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM reward_ledger WHERE customer=?');$q->execute([$uid]);return (int)$q->fetchColumn(); }
function rwVerified(PDO $db,int $uid): bool {$q=$db->prepare('SELECT verified FROM customers WHERE id=?');$q->execute([$uid]);return (bool)$q->fetchColumn();}
function rwSummary(PDO $db,int $uid): array {
 $q=$db->prepare('SELECT id,currency,subtotal,discount,total,items,status,points,created,approved FROM reward_orders WHERE customer=? ORDER BY created DESC LIMIT 100');$q->execute([$uid]);$orders=$q->fetchAll(PDO::FETCH_ASSOC);
 foreach($orders as &$o){$o['items']=json_decode($o['items'],true);$o['scale']=$o['currency']==='THB'?100:1;}unset($o);
 $q=$db->prepare('SELECT amount,reason,reference,created FROM reward_ledger WHERE customer=? ORDER BY id DESC LIMIT 100');$q->execute([$uid]);$ledger=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$db->prepare('SELECT code,percent,cost,state,created FROM reward_coupons WHERE customer=? ORDER BY created DESC LIMIT 100');$q->execute([$uid]);
 return ['balance'=>rwBalance($db,$uid),'orders'=>$orders,'ledger'=>$ledger,'coupons'=>$q->fetchAll(PDO::FETCH_ASSOC),'rules'=>['mmkPerPoint'=>1000,'thbPerPoint'=>7,'tiers'=>[100=>5,200=>10,300=>15]]];
}
function rwRedeem(PDO $db,int $uid,int $cost): array {
 $tiers=[100=>5,200=>10,300=>15];if(!isset($tiers[$cost]))throw new InvalidArgumentException('Choose a valid reward.');
 if(!rwVerified($db,$uid))throw new InvalidArgumentException('Verify your email before redeeming points.');
 $db->exec('BEGIN IMMEDIATE');try{
 if(rwBalance($db,$uid)<$cost)throw new InvalidArgumentException('Not enough points for this reward.');
 $code='OFA-RWD-'.strtoupper(bin2hex(random_bytes(8)));
 $db->prepare('INSERT INTO reward_coupons(code,customer,percent,cost,created) VALUES(?,?,?,?,?)')->execute([$code,$uid,$tiers[$cost],$cost,time()]);
 $db->prepare('INSERT INTO reward_ledger(customer,amount,reason,reference,created) VALUES(?,?,?,?,?)')->execute([$uid,-$cost,'Coupon redemption',$code,time()]);$db->exec('COMMIT');
 return ['code'=>$code,'percent'=>$tiers[$cost]];
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function rwCoupon(PDO $db,int $uid,string $code): array {
 if(!rwVerified($db,$uid))throw new InvalidArgumentException('Verify your email before using reward coupons.');
 $q=$db->prepare('SELECT code,percent,state FROM reward_coupons WHERE code=? AND customer=?');$q->execute([$code,$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r||$r['state']!=='available')throw new InvalidArgumentException('Reward coupon is unavailable or already used.');return $r;
}
function rwOrder(PDO $db,array $in,?int $uid,string $provider='manual'): array {
 $catalog=json_decode(file_get_contents(__DIR__.'/reward-catalog.json'),true);if(!is_array($catalog))throw new RuntimeException('Catalog unavailable');
 $currency=$in['currency']??'';if(!in_array($currency,['MMK','THB'],true))throw new InvalidArgumentException('Invalid currency.');
 $scale=$currency==='THB'?100:1;$rows=$in['items']??null;if(!is_array($rows)||!count($rows)||count($rows)>100)throw new InvalidArgumentException('Please refresh checkout.');
 $code=strtoupper(trim((string)($in['promoCode']??'')));$campaign=strpos($code,'OFA-SINGLE50-')===0;
 $metadata=[];$redemptionDate=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));
 $items=[];$seen=[];$subtotal=0;$eligible=0;
 foreach($rows as $row){$id=$row['id']??'';$qty=$row['quantity']??0;if(!is_string($id)||!isset($catalog['products'][$id])||isset($seen[$id])||!is_int($qty)||$qty<1||$qty>99)throw new InvalidArgumentException('Invalid cart item. Please refresh checkout.');
 $seen[$id]=true;$p=$catalog['products'][$id];$unit=(int)round($p[$currency]*$scale);$sum=$unit*$qty;$subtotal+=$sum;$itemEligible=$campaign?c50Eligible($id,$p,$metadata,$redemptionDate):(bool)$p['eligible'];if($itemEligible)$eligible+=$sum;
 $items[]=['id'=>$id,'title'=>$p['title'],'type'=>$p['type'],'quantity'=>$qty,'unit'=>$unit,'eligible'=>$p['eligible']];}
 $percent=0;$reward=false;$storedCode=$campaign?c50Key($code):$code;
 $id=$in['orderId'];$hash=hash('sha256',json_encode([$uid,$currency,$items,$code]));
 $db->exec('BEGIN IMMEDIATE');try{
 $q=$db->prepare('SELECT * FROM reward_orders WHERE id=?');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
 if($old){$route=$db->prepare('SELECT 1 FROM stripe_payments WHERE order_id=?');$route->execute([$id]);if((bool)$route->fetchColumn()!==($provider==='stripe'))throw new InvalidArgumentException('This order uses another payment method. Reopen checkout.');if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('Order changed. Reopen checkout for a new order number.');$db->exec('COMMIT');return $old;}
 if($code!==''){
 if($eligible<=0)throw new InvalidArgumentException('Coupons apply to game listings only. Software and services are excluded.');
 if(strpos($code,'OFA-ADA25-')===0){$storedCode=adaValidate($db,$code);$percent=25;$reward=true;}
 elseif($campaign){$storedCode=c50Validate($db,$code);$percent=50;$reward=true;}
 elseif(strpos($code,'OFA-RWD-')===0){if(!$uid)throw new InvalidArgumentException('Log in to use your reward coupon.');$c=rwCoupon($db,$uid,$code);$percent=(int)$c['percent'];$reward=true;}
 elseif(strpos($code,'OFA-OPEN50-')===0){
   $chk=$db->prepare('SELECT code,percent,used FROM ip_promo_claims WHERE code=?');$chk->execute([$code]);$ipC=$chk->fetch(PDO::FETCH_ASSOC);
   if(!$ipC||(int)$ipC['used']===1)throw new InvalidArgumentException('This 50% Grand Opening code is invalid or has already been used.');
   $reserved=$db->prepare('SELECT 1 FROM reward_orders WHERE coupon=? AND status<>"expired"');$reserved->execute([$code]);if($reserved->fetchColumn())throw new InvalidArgumentException('This opening code is already reserved.');
   $percent=(int)$ipC['percent'];$reward=true;
 }
 else {if(!isset($catalog['promos'][$code]))throw new InvalidArgumentException('Invalid discount code.');$percent=(int)$catalog['promos'][$code];}
 }
 $discount=(int)floor($eligible*$percent/100+0.5);$total=$subtotal-$discount;
 if(!isset($in['total'])||abs((float)$in['total']*$scale-$total)>0.51)throw new InvalidArgumentException('Prices changed. Reopen checkout and review the total before paying.');
 $points=intdiv($total,$currency==='THB'?700:1000);$token=bin2hex(random_bytes(32));$receipt=(string)$in['receipt'];
 $db->prepare('INSERT INTO reward_orders(id,customer,currency,subtotal,discount,total,items,receipt,status,points,coupon,approval_hash,approval_token,payload_hash,created) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$uid,$currency,$subtotal,$discount,$total,json_encode($items),$receipt,'awaiting_verification',$points,$storedCode?:null,hash('sha256',$token),$token,$hash,time()]);
 if($provider==='stripe')$db->prepare('INSERT INTO stripe_payments(order_id,binding,expires) VALUES(?,?,?)')->execute([$id,bin2hex(random_bytes(32)),time()+3600]);
 if($reward)$db->prepare('INSERT INTO order_coupon_reservations(code,order_id) VALUES(?,?)')->execute([$storedCode,$id]);
 if($reward)$db->prepare('UPDATE reward_coupons SET state="reserved",order_id=? WHERE code=? AND customer=? AND state="available"')->execute([$id,$code,$uid]);
 $db->exec('COMMIT');$q=$db->prepare('SELECT * FROM reward_orders WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function rwApprove(PDO $db,string $id,string $token): array {
 $db->exec('BEGIN IMMEDIATE');try{
 $q=$db->prepare('SELECT * FROM reward_orders WHERE id=? AND approval_hash=?');$q->execute([$id,hash('sha256',$token)]);$o=$q->fetch(PDO::FETCH_ASSOC);
 $route=$db->prepare('SELECT 1 FROM stripe_payments WHERE order_id=?');$route->execute([$id]);if($route->fetchColumn())throw new InvalidArgumentException('Stripe orders can only be verified by the Stripe webhook.');
 if(!$o)throw new InvalidArgumentException('Invalid approval link.');if(!$o['notified'])throw new InvalidArgumentException('Order notification has not completed yet.');
 if($o['status']==='payment_verified'){$db->exec('COMMIT');return $o;}
 if($o['status']!=='awaiting_verification')throw new InvalidArgumentException('This order cannot be approved.');
 $db->prepare('UPDATE reward_orders SET status="payment_verified",approved=? WHERE id=?')->execute([time(),$id]);
 if($o['customer'] && (int)$o['points']>0)$db->prepare('INSERT INTO reward_ledger(customer,amount,reason,reference,created) VALUES(?,?,?,?,?)')->execute([$o['customer'],(int)$o['points'],'Verified purchase',$id,time()]);
 if($o['coupon']){
   $db->prepare('UPDATE reward_coupons SET state="used" WHERE code=? AND order_id=?')->execute([$o['coupon'],$id]);
   if(strpos($o['coupon'],'OFA-OPEN50-')===0)$db->prepare('UPDATE ip_promo_claims SET used=1 WHERE code=?')->execute([$o['coupon']]);
   c50Consume($db,$o['coupon'],$id);
 }
 $db->exec('COMMIT');$o['status']='payment_verified';return $o;
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
// Direct requests do not expose customer data.
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
