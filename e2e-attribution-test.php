<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('restricted');}
$home=getenv('HOME')?:'/home/u606090814';
$priv=$home.'/domains/meucrediti.creditisolucoes.com.br/meucrediti-teste-private';
if(!is_file($priv.'/config.php')||!is_file($priv.'/app.php')){fwrite(STDERR,"test env missing\n");exit(2);}
$c=require $priv.'/config.php';
if(!is_array($c)||!str_contains((string)($c['dsn']??''),'u606090814_mcteste')||!empty($c['novo_enabled'])){fwrite(STDERR,"unsafe test config\n");exit(3);}
require_once $priv.'/app.php';
$appObj=new App($c);
$db=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false
]);
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
 captured_at BIGINT NOT NULL,
 CONSTRAINT fk_mc_attr_test_user FOREIGN KEY (user_id) REFERENCES mc_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$requestKey='ATTR_TEST_INSTAGRAM_20261117';
$old=$db->prepare("SELECT user_id FROM mc_live_requests WHERE request_key=?");
$old->execute([$requestKey]);
foreach($old as$r){
  $uid=(string)$r['user_id'];
  $db->prepare("DELETE FROM mc_live_actions WHERE user_id=?")->execute([$uid]);
  $db->prepare("DELETE FROM mc_live_requests WHERE user_id=?")->execute([$uid]);
  $db->prepare("DELETE FROM mc_requests WHERE user_id=?")->execute([$uid]);
  $db->prepare("DELETE FROM mc_sessions WHERE user_id=?")->execute([$uid]);
  $db->prepare("DELETE FROM mc_legal WHERE user_id=?")->execute([$uid]);
  $db->prepare("DELETE FROM mc_attribution WHERE user_id=?")->execute([$uid]);
  $db->prepare("DELETE FROM mc_users WHERE id=?")->execute([$uid]);
}

function cpfFromBase(string $base): string {
  $digits=str_split($base);
  for($pos=9;$pos<11;$pos++){
    $sum=0;
    for($i=0;$i<$pos;$i++)$sum+=(int)$digits[$i]*($pos+1-$i);
    $d=(($sum*10)%11)%10;
    $digits[]=(string)$d;
  }
  return implode('',$digits);
}
function isoMs(int $ms): string {
  $s=intdiv($ms,1000);
  return (new DateTimeImmutable('@'.$s))->setTimezone(new DateTimeZone('America/Fortaleza'))->format(DATE_ATOM);
}
$now=(int)round(microtime(true)*1000);
$cpf=cpfFromBase('583204719');
$email='teste-atribuicao-20261117@example.invalid';
$phone='85999990000';
$name='Cliente Teste Atribuicao';
$pin='482951';
$uid=bin2hex(random_bytes(16));
$profile=$appObj->encrypt(compact('name','cpf','email','phone'));
$db->beginTransaction();
try{
  $db->prepare("INSERT INTO mc_users(id,cpf_index,email_index,data_encrypted,password_hash,created_at) VALUES(?,?,?,?,?,?)")
     ->execute([$uid,hash('sha256','cpf-test-'.$cpf),hash('sha256','email-test-'.$email),$profile,password_hash($pin,PASSWORD_BCRYPT,['cost'=>10]),$now]);
  $db->prepare("INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)")
     ->execute([$uid,'TESTE_ATRIBUICAO_2026',$now]);
  $campaign='TESTE-INSTAGRAM-PRE-PLAY-20261117';
  $db->prepare("INSERT INTO mc_attribution(user_id,first_source,method,campaign_id,utm_source,utm_medium,utm_campaign,utm_content,landing_path,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
     ->execute([$uid,'instagram_ads','url_utm',$campaign,'instagram_ads','paid_social','teste-jornada-meucrediti','reels-teste','/meucrediti/',$now]);
  $rid=bin2hex(random_bytes(16));
  $t1=$now+60000;$t2=$now+120000;$t3=$now+180000;$t4=$now+240000;$t5=$now+300000;
  $snapshot=[
    'stage'=>'disbursement',
    'summary_status'=>'Contract paid',
    'disbursement_confirmed'=>true,
    '_selected_offer'=>['disbursed_amount'=>1000.00],
    'history'=>[
      ['stage'=>'simulation','summary_status'=>'Offer available','timestamp'=>isoMs($t1)],
      ['stage'=>'create-contract','summary_status'=>'Contract created','timestamp'=>isoMs($t2)],
      ['stage'=>'sign-contract','summary_status'=>'Signed contract - Awaiting registry','timestamp'=>isoMs($t3)],
      ['stage'=>'registry','summary_status'=>'Registered - Pending payment','timestamp'=>isoMs($t4)],
      ['stage'=>'disbursement','summary_status'=>'Contract paid','timestamp'=>isoMs($t5)]
    ]
  ];
  $db->prepare("INSERT INTO mc_live_requests(id,user_id,product,request_key,parameters_hash,local_status,transaction_encrypted,snapshot_encrypted,created_at,updated_at,acknowledged_version) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
     ->execute([$rid,$uid,'CARD',$requestKey,hash('sha256',$requestKey),'completed',$appObj->encrypt('TEST-TRANSACTION'),$appObj->encrypt($snapshot),$now,$t5,'TESTE_FICTICIO_SEM_API']);
  $db->commit();
}catch(Throwable $e){
  if($db->inTransaction())$db->rollBack();
  fwrite(STDERR,"insert failed\n");exit(4);
}

$uk=hash('sha256','test-user:'.$uid);
$rk=hash('sha256','test-request:'.$rid);
$event=function(string $type,int $at)use($rid,$rk,$uk){
  return ['id'=>hash('sha256','test-event:'.$rid.':'.$type),'requestId'=>$rk,'userKey'=>$uk,'product'=>'CARTAO','eventType'=>$type,'occurredAt'=>isoMs($at)];
};
$credits=[
  $event('requested',$now),
  $event('simulated',$now+60000),
  $event('contract_generated',$now+120000),
  $event('contract_signed',$now+180000),
  $event('contracted',$now+240000),
  array_merge($event('disbursed',$now+300000),['amountCents'=>100000])
];
$payload=[
  'generatedAt'=>isoMs($now+300000),
  'trackingStart'=>'2026-11-15',
  'testMode'=>true,
  'customers'=>[[
    'userKey'=>$uk,
    'registeredAt'=>isoMs($now),
    'firstSource'=>'instagram_ads',
    'firstCampaignId'=>$campaign,
    'trackingBasis'=>'utm_verified'
  ]],
  'credits'=>$credits,
  'counts'=>['customers'=>1,'credits'=>count($credits)]
];
$out=$home.'/ci-test-events.json';
file_put_contents($out,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
chmod($out,0600);
echo json_encode([
 'ok'=>true,
 'environment'=>'mcteste',
 'novoEnabled'=>false,
 'cpfMasked'=>'***.***.***-'.substr($cpf,-2),
 'campaignId'=>$campaign,
 'journey'=>['registered','requested','simulated','contract_generated','contract_signed','contracted','disbursed'],
 'disbursedAmountCents'=>100000,
 'payload'=>$payload
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
