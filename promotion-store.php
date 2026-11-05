<?php
declare(strict_types=1);
function ofaPromotionDefaults(): array {return ['revision'=>0,'title'=>'Buy 2 Access Key games, get 1 free','buyQuantity'=>2,'giftQuantity'=>1,'giftPriceMMK'=>25000,'durationHours'=>48,'starts'=>0,'ends'=>0,'enabled'=>false];}
function ofaPromotionAction(PDO $db,array $input,int $now): array {
 $action=$input['action']??'get';
 if(!in_array($action,['get','save','enable','disable','start'],true))throw new InvalidArgumentException('Unknown promotion action.');
 $db->exec('CREATE TABLE IF NOT EXISTS store_promotions (id INTEGER PRIMARY KEY CHECK(id=1), data TEXT NOT NULL)');
 $db->exec('BEGIN IMMEDIATE');
 try {
  $raw=$db->query('SELECT data FROM store_promotions WHERE id=1')->fetchColumn();
  $p=$raw===false?ofaPromotionDefaults():json_decode($raw,true);
  if(!is_array($p))throw new RuntimeException('Invalid promotion storage.');
  if($action==='get'){$db->exec('COMMIT');return $p;}
  if(!isset($input['revision'])||!is_int($input['revision'])||$input['revision']!==$p['revision'])throw new LogicException('Promotion changed. Refresh before editing again.');
  if($action==='save'){
   $title=$input['title']??null;
   if(!is_string($title)||strlen(trim($title))<1||strlen(trim($title))>100)throw new InvalidArgumentException('Title must contain 1–100 bytes.');
   $p['title']=trim($title);
   foreach(['buyQuantity'=>99,'giftQuantity'=>99,'giftPriceMMK'=>10000000,'durationHours'=>720] as $field=>$max){
    $v=$input[$field]??null;
    if(!is_int($v)||$v<1||$v>$max)throw new InvalidArgumentException('Invalid '.$field.'.');
    $p[$field]=$v;
   }
  }
  if($action==='disable')$p['enabled']=false;
  if($action==='enable'){
   if($p['starts']<=0||$p['ends']<=$now)throw new InvalidArgumentException('Campaign has not started or has expired. Start a new campaign.');
   $p['enabled']=true;
  }
  if($action==='start'){$p['starts']=$now;$p['ends']=$now+$p['durationHours']*3600;$p['enabled']=true;}
  $p['revision']++;
  $db->prepare('INSERT OR REPLACE INTO store_promotions(id,data) VALUES(1,?)')->execute([json_encode($p,JSON_THROW_ON_ERROR)]);
  $db->exec('COMMIT');return $p;
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
