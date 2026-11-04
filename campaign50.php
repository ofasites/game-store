<?php
// Only hashes of the high-entropy campaign codes are shipped with the storefront.
declare(strict_types=1);
function c50Manifest(): array { return require __DIR__.'/campaign50-manifest.php'; }
function c50Metadata(): array {
 $data=json_decode((string)file_get_contents(__DIR__.'/campaign50-releases.json'),true);
 if(!is_array($data)||!isset($data['products']))throw new RuntimeException('Release metadata unavailable');
 return $data['products'];
}
function c50Eligible(string $id,array $p,array $meta,?DateTimeImmutable $now=null): bool {
 // Release-date campaign restrictions are paused. All catalog game listings qualify.
 return !empty($p['eligible']);
}
function c50Key(string $code): string { return 'C50:'.hash('sha256',strtoupper(trim($code))); }
function c50Validate(PDO $db,string $code): string {
 $key=c50Key($code);$manifest=c50Manifest();
 if(!preg_match('/^OFA-SINGLE50-[A-F0-9]{24}$/D',$code)||!isset($manifest[substr($key,4)]))throw new InvalidArgumentException('Invalid single-use code.');
 $q=$db->prepare('SELECT 1 FROM order_coupon_reservations WHERE code=?');$q->execute([$key]);
 if($q->fetchColumn())throw new InvalidArgumentException('This code is already reserved or used.');
 return $key;
}
function c50Minimum(string $code): int {
 $manifest=c50Manifest();$entry=$manifest[hash('sha256',strtoupper(trim($code)))]??null;
 return is_array($entry)?(int)($entry['minQuantity']??0):0;
}
function c50CheckMinimum(string $code,array $rows,array $products): void {
 $min=c50Minimum($code);if(!$min)return;$count=0;$seen=[];
 foreach($rows as $row){$id=$row['id']??'';$qty=$row['quantity']??0;
 if(!is_string($id)||isset($seen[$id])||!isset($products[$id])||!is_int($qty)||$qty<1||$qty>99)throw new InvalidArgumentException('Invalid cart items.');
 $seen[$id]=true;if(!empty($products[$id]['eligible']))$count+=$qty;
 }
 if($count<$min)throw new InvalidArgumentException('This coupon requires at least 4 game items in your cart.');
}
function c50Consume(PDO $db,string $key,string $order): void {
 if(strpos($key,'C50:')!==0)return;
 $q=$db->prepare('UPDATE order_coupon_reservations SET consumed=1 WHERE code=? AND order_id=?');$q->execute([$key,$order]);
 if($q->rowCount()!==1)throw new RuntimeException('Campaign reservation missing');
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
