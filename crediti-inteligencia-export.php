<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Acesso restrito');}
$tz=new DateTimeZone('America/Fortaleza');
$start=(new DateTimeImmutable('2026-11-15 00:00:00',$tz))->getTimestamp()*1000;
$home=getenv('HOME')?:'/home/u606090814';
$base=$home.'/domains/meucrediti.creditisolucoes.com.br';
$priv=$base.'/meucrediti-private';
$out=$base.'/public_html/meucrediti/crediti-inteligencia';
if(!is_file($priv.'/config.php')){fwrite(STDERR,"config missing
");exit(2);}
$c=require $priv.'/config.php';
if(!is_array($c)||empty($c['dsn'])||empty($c['db_user'])||empty($c['db_password'])||!preg_match('/^[a-f0-9]{64}$/iD',(string)($c['encryption_key']??''))){fwrite(STDERR,"config invalid
");exit(3);}
$m=hex2bin($c['encryption_key']);
$key=hash_hmac('sha256','meucrediti:auth-data:v1',$m,true);
$ek=hash_hmac('sha256','crediti-inteligencia-export:v1',$m,true);
$dec=function($s)use($key){if(!$s)return[];$b=base64_decode($s,true);if($b===false||strlen($b)<28)return[];$p=openssl_decrypt(substr($b,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16));if($p===false)return[];$j=json_decode($p,true);return is_array($j)?$j:[];};
$iso=function($ms)use($tz){$s=(int)floor(((int)$ms)/1000);return(new DateTimeImmutable('@'.$s))->setTimezone($tz)->format(DATE_ATOM);};
$ht=function($s,$pred,$fallback)use($tz){foreach(($s['history']??[])as$h){if(!is_array($h)||!$pred($h))continue;$t=(string)($h['timestamp']??'');if($t==='')continue;try{return(new DateTimeImmutable($t))->setTimezone($tz)->format(DATE_ATOM);}catch(Throwable $e){}}return$fallback;};
try{$db=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){fwrite(STDERR,"db unavailable
");exit(4);}
$customers=[];$credits=[];
$q=$db->prepare('SELECT id,created_at FROM mc_users WHERE created_at>=? ORDER BY created_at');$q->execute([$start]);
foreach($q as$u){$uk=hash_hmac('sha256','user:'.$u['id'],$ek);$customers[]=['userKeyParts'=>str_split($uk,16),'registeredAt'=>$iso($u['created_at']),'firstSource'=>'desconhecido','trackingBasis'=>'nao_atribuido'];}
$q=$db->prepare('SELECT id,user_id,product,snapshot_encrypted,created_at,updated_at FROM mc_live_requests WHERE created_at>=? ORDER BY created_at');$q->execute([$start]);
foreach($q as$r){
 $uk=hash_hmac('sha256','user:'.$r['user_id'],$ek);$rk=hash_hmac('sha256','request:'.$r['id'],$ek);
 $prod=match((string)$r['product']){'CARD'=>'CARTAO','FGTS'=>'FGTS','CLT'=>'CLT',default=>'OUTROS'};
 $ca=$iso($r['created_at']);$ua=$iso($r['updated_at']);
 $add=function($type,$at,$amt=null)use(&$credits,$r,$uk,$rk,$prod,$ek){$e=['idParts'=>str_split(hash_hmac('sha256','event:'.$r['id'].':'.$type,$ek),16),'requestIdParts'=>str_split($rk,16),'userKeyParts'=>str_split($uk,16),'product'=>$prod,'eventType'=>$type,'occurredAt'=>$at];if($amt!==null&&$amt>=0)$e['amountCents']=(int)$amt;$credits[]=$e;};
 $add('requested',$ca);$s=$dec($r['snapshot_encrypted']??null);if(!$s)continue;
 $stage=(string)($s['stage']??'');$status=(string)($s['summary_status']??'');$hist=is_array($s['history']??null)?$s['history']:[];
 $has=function($fn)use($hist){foreach($hist as$h)if(is_array($h)&&$fn($h))return true;return false;};
 if(($stage==='simulation'&&$status==='Offer available')||$has(fn($h)=>(($h['stage']??'')==='simulation'&&($h['summary_status']??'')==='Offer available')))$add('simulated',$ht($s,fn($h)=>(($h['stage']??'')==='simulation'&&($h['summary_status']??'')==='Offer available'),$ua));
 $later=['create-contract','sign-contract','registry','disbursement','pay-commission','collateral'];
 if(in_array($stage,$later,true)||$has(fn($h)=>in_array((string)($h['stage']??''),$later,true)))$add('contract_generated',$ht($s,fn($h)=>in_array((string)($h['stage']??''),['create-contract','sign-contract'],true),$ua));
 if($status==='Signed contract - Awaiting registry'||in_array($stage,['registry','disbursement','pay-commission','collateral'],true)||$has(fn($h)=>(($h['summary_status']??'')==='Signed contract - Awaiting registry'||in_array((string)($h['stage']??''),['registry','disbursement','pay-commission','collateral'],true))))$add('contract_signed',$ht($s,fn($h)=>(($h['summary_status']??'')==='Signed contract - Awaiting registry'||in_array((string)($h['stage']??''),['registry','disbursement','pay-commission','collateral'],true)),$ua));
 if(in_array($stage,['registry','disbursement','pay-commission','collateral'],true)||$has(fn($h)=>(($h['summary_status']??'')==='Registered - Pending payment'||($h['summary_status']??'')==='Contract paid'||in_array((string)($h['stage']??''),['registry','disbursement','pay-commission','collateral'],true))))$add('contracted',$ht($s,fn($h)=>(($h['summary_status']??'')==='Registered - Pending payment'||in_array((string)($h['stage']??''),['registry','disbursement','pay-commission','collateral'],true)),$ua));
 $paid=!empty($s['disbursement_confirmed'])||$has(fn($h)=>(($h['stage']??'')==='disbursement'&&($h['summary_status']??'')==='Contract paid'));
 if($paid){$amt=null;if(isset($s['_selected_offer']['disbursed_amount'])&&is_numeric($s['_selected_offer']['disbursed_amount']))$amt=(int)round(((float)$s['_selected_offer']['disbursed_amount'])*100);$add('disbursed',$ht($s,fn($h)=>(($h['stage']??'')==='disbursement'&&($h['summary_status']??'')==='Contract paid'),$ua),$amt);}
 if($status==='Canceled')$add('cancelled',$ht($s,fn($h)=>(($h['summary_status']??'')==='Canceled'),$ua));
}
$p=['generatedAt'=>(new DateTimeImmutable('now',$tz))->format(DATE_ATOM),'trackingStart'=>'2026-11-15','customers'=>$customers,'credits'=>$credits,'counts'=>['customers'=>count($customers),'credits'=>count($credits)]];
if(!is_dir($out)&&!mkdir($out,0700,true)){fwrite(STDERR,"mkdir failed
");exit(5);}
file_put_contents($out.'/.htaccess',"Require all denied
",LOCK_EX);
$tmp=tempnam($out,'evt_');if(!$tmp){fwrite(STDERR,"temp failed
");exit(6);}
file_put_contents($tmp,json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);chmod($tmp,0600);
if(!rename($tmp,$out.'/events.json')){fwrite(STDERR,"rename failed
");exit(7);}chmod($out.'/events.json',0600);
$mirror=$home.'/ci-events.json';
if(!copy($out.'/events.json',$mirror)){fwrite(STDERR,"mirror failed\n");exit(8);}chmod($mirror,0600);
echo json_encode($p['counts'],JSON_UNESCAPED_UNICODE).PHP_EOL;
