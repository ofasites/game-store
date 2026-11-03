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
 if(empty($p['eligible'])||preg_match('/phantom[\s_-]*blade/i',$id.' '.($p['title']??'')))return false;
 $m=$meta[$id]??[];
 if(($m['platform']??'')!=='steam-pc'||($m['released']??false)!==true||empty($m['source']))return false;
 $date=$m['releaseDate']??'';
 if(!is_string($date))return false;
 $tz=new DateTimeZone('Asia/Bangkok');
 $release=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);
 if(!$release||$release->format('Y-m-d')!==$date)return false;
 $today=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz)->setTime(0,0);
 // Calendar-month anniversary, clamped to the last day of the next month.
 $next=$release->modify('first day of next month');
 $anniversary=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),min((int)$release->format('d'),(int)$next->format('t')));
 return $release<=$today&&$anniversary<=$today;
}
function c50Key(string $code): string { return 'C50:'.hash('sha256',strtoupper(trim($code))); }
function c50Validate(PDO $db,string $code): string {
 $key=c50Key($code);$manifest=c50Manifest();
 if(!preg_match('/^OFA-SINGLE50-[A-F0-9]{24}$/D',$code)||!isset($manifest[substr($key,4)]))throw new InvalidArgumentException('Invalid single-use code.');
 $q=$db->prepare('SELECT 1 FROM order_coupon_reservations WHERE code=?');$q->execute([$key]);
 if($q->fetchColumn())throw new InvalidArgumentException('This code is already reserved or used.');
 return $key;
}
function c50Consume(PDO $db,string $key,string $order): void {
 if(strpos($key,'C50:')!==0)return;
 $q=$db->prepare('UPDATE order_coupon_reservations SET consumed=1 WHERE code=? AND order_id=?');$q->execute([$key,$order]);
 if($q->rowCount()!==1)throw new RuntimeException('Campaign reservation missing');
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
