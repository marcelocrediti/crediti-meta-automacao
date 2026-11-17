<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
register_shutdown_function(function(){ $e=error_get_last(); if($e) echo "\nFATAL:".$e['message']."\n"; });
if(PHP_SAPI!=='cli') exit(2);
$home=getenv('HOME')?:'/home/u606090814';
$priv=$home.'/domains/meucrediti.creditisolucoes.com.br/meucrediti-teste-private';
$c=require $priv.'/config.php';
if(!is_array($c)||!str_contains((string)($c['dsn']??''),'u606090814_mcteste')||!empty($c['novo_enabled'])){echo "unsafe_test_env\n";exit(3);}
require_once $priv.'/app.php';
$appObj=new App($c);
$db=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false
]);
try{
 $db->exec("CREATE TABLE IF NOT EXISTS mc_attribution (
  user_id CHAR(32) NOT NULL PRIMARY KEY,
  first_source VARCHAR(32) NOT NULL DEFAULT 'desconhecido',
  method VARCHAR(32) NOT NULL DEFAULT 'url_utm',
  campaign_id VARCHAR(160) NULL,
  utm_source VARCHAR(80) NULL,
  utm_medium VARCHAR(80) NULL,
  utm_campaign VARCHAR(180) NULL,
  utm_content VARCHAR(180) NULL,
  landing_path VARCHAR(255) NULL,
  captured_at BIGINT NOT NULL
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}catch(Throwable $e){echo "attribution_table_failed\n";exit(10);}

function cpfFromBase(string $base): string {
 $digits=str_split($base);
 for($pos=9;$pos<11;$pos++){
  $sum=0; for($i=0;$i<$pos;$i++)$sum+=(int)$digits[$i]*($pos+1-$i);
  $digits[]=(string)((($sum*10)%11)%10);
 }
 return implode('',$digits);
}
function isoMs(int $ms): string {
 return (new DateTimeImmutable('@'.intdiv($ms,1000)))->setTimezone(new DateTimeZone('America/Fortaleza'))->format(DATE_ATOM);
}
$now=(int)round(microtime(true)*1000);
$suffix=substr(bin2hex(random_bytes(6)),0,10);
$cpf=cpfFromBase('583204719');
$email='teste-'.$suffix.'@example.invalid';
$phone='85999990000';
$name='Cliente Teste Atribuicao';
$uid=bin2hex(random_bytes(16));
$rid=bin2hex(random_bytes(16));
$requestKey='ATTR_TEST_'.strtoupper($suffix);
$campaign='TESTE-INSTAGRAM-PRE-PLAY-20261117';
$t1=$now+60000;$t2=$now+120000;$t3=$now+180000;$t4=$now+240000;$t5=$now+300000;
$snapshot=[
 'stage'=>'disbursement','summary_status'=>'Contract paid','disbursement_confirmed'=>true,
 '_selected_offer'=>['disbursed_amount'=>1000.00],
 'history'=>[
  ['stage'=>'simulation','summary_status'=>'Offer available','timestamp'=>isoMs($t1)],
  ['stage'=>'create-contract','summary_status'=>'Contract created','timestamp'=>isoMs($t2)],
  ['stage'=>'sign-contract','summary_status'=>'Signed contract - Awaiting registry','timestamp'=>isoMs($t3)],
  ['stage'=>'registry','summary_status'=>'Registered - Pending payment','timestamp'=>isoMs($t4)],
  ['stage'=>'disbursement','summary_status'=>'Contract paid','timestamp'=>isoMs($t5)]
 ]
];
try{
 $db->beginTransaction();
 $db->prepare("INSERT INTO mc_users(id,cpf_index,email_index,data_encrypted,password_hash,created_at) VALUES(?,?,?,?,?,?)")
    ->execute([$uid,hash('sha256','test-'.$suffix.'-'.$cpf),hash('sha256',$email),$appObj->encrypt(compact('name','cpf','email','phone')),password_hash('482951',PASSWORD_BCRYPT,['cost'=>10]),$now]);
 $db->prepare("INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)")->execute([$uid,'TESTE_ATRIBUICAO_2026',$now]);
 $db->prepare("INSERT INTO mc_attribution(user_id,first_source,method,campaign_id,utm_source,utm_medium,utm_campaign,utm_content,landing_path,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
    ->execute([$uid,'instagram_ads','url_utm',$campaign,'instagram_ads','paid_social','teste-jornada-meucrediti','reels-teste','/meucrediti/',$now]);
 $db->prepare("INSERT INTO mc_live_requests(id,user_id,product,request_key,parameters_hash,local_status,transaction_encrypted,snapshot_encrypted,created_at,updated_at,acknowledged_version) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$rid,$uid,'CARD',$requestKey,hash('sha256',$requestKey),'completed',$appObj->encrypt('TEST-TRANSACTION'),$appObj->encrypt($snapshot),$now,$t5,'TESTE_FICTICIO_SEM_API']);
 $db->commit();
}catch(Throwable $e){
 if($db->inTransaction())$db->rollBack();
 echo "insert_failed:".get_class($e)."\n";exit(4);
}
$uk=hash('sha256','test-user:'.$uid);
$rk=hash('sha256','test-request:'.$rid);
$ev=function(string $type,int $at)use($rid,$rk,$uk){return ['id'=>hash('sha256','test-event:'.$rid.':'.$type),'requestId'=>$rk,'userKey'=>$uk,'product'=>'CARTAO','eventType'=>$type,'occurredAt'=>isoMs($at)];};
$credits=[
 $ev('requested',$now),
 $ev('simulated',$t1),
 $ev('contract_generated',$t2),
 $ev('contract_signed',$t3),
 $ev('contracted',$t4),
 array_merge($ev('disbursed',$t5),['amountCents'=>100000])
];
$payload=[
 'generatedAt'=>isoMs($t5),'trackingStart'=>'2026-11-15','testMode'=>true,
 'customers'=>[['userKey'=>$uk,'registeredAt'=>isoMs($now),'firstSource'=>'instagram_ads','firstCampaignId'=>$campaign,'trackingBasis'=>'utm_verified']],
 'credits'=>$credits,'counts'=>['customers'=>1,'credits'=>6]
];
file_put_contents($home.'/ci-test-events.json',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
chmod($home.'/ci-test-events.json',0600);
echo json_encode(['ok'=>true,'environment'=>'mcteste','novoEnabled'=>false,'cpfMasked'=>'***.***.***-'.substr($cpf,-2),'campaignId'=>$campaign,'journey'=>['registered','requested','simulated','contract_generated','contract_signed','contracted','disbursed'],'disbursedAmountCents'=>100000,'payload'=>$payload],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
