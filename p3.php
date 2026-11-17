<?php
declare(strict_types=1);
$home=getenv('HOME')?:'/home/u606090814';
$app=$home.'/domains/meucrediti.creditisolucoes.com.br/meucrediti-private/app.php';
if(!is_file($app)){fwrite(STDERR,"app missing\n");exit(2);}
$s=(string)file_get_contents($app);
if(str_contains($s,"INSERT IGNORE INTO mc_attribution")){
 echo json_encode(['status'=>'already','backend_patched'=>true]).PHP_EOL;exit;
}
$start="if(\$path==='/api/auth/register'&&\$method==='POST'){\n   \$b=\$this->body();";
if(!str_contains($s,$start)){fwrite(STDERR,"register start missing\n");exit(3);}
$attr=<<<'PHP'

   $attrRaw=is_array($b['attribution']??null)?$b['attribution']:[];
   $attr=null;
   if($attrRaw){
    $aclean=static function(mixed $v,int $max): string {
     if(!is_string($v)&&!is_numeric($v))return '';
     $x=trim((string)$v);$x=preg_replace('/[\x00-\x1f\x7f]/u','',$x)??'';
     return substr($x,0,$max);
    };
    $source=$aclean($attrRaw['firstSource']??'desconhecido',32);
    $allowed=['instagram_ads','facebook_ads','google_ads','youtube_ads','direto','organico','desconhecido'];
    if(!in_array($source,$allowed,true))$source='desconhecido';
    $method=$aclean($attrRaw['method']??'url_utm',32);
    if(!in_array($method,['url_utm','play_install_referrer','app_store_campaign','manual_verified'],true))$method='url_utm';
    $campaignId=$aclean($attrRaw['campaignId']??'',160);
    $utmSource=$aclean($attrRaw['utmSource']??'',80);
    $utmMedium=$aclean($attrRaw['utmMedium']??'',80);
    $utmCampaign=$aclean($attrRaw['utmCampaign']??'',180);
    $utmContent=$aclean($attrRaw['utmContent']??'',180);
    $landingPath=$aclean($attrRaw['landingPath']??'',255);
    if($source!=='desconhecido'||$campaignId!==''||$utmSource!==''||$utmCampaign!==''){
     $attr=compact('source','method','campaignId','utmSource','utmMedium','utmCampaign','utmContent','landingPath');
    }
   }
PHP;
$s=str_replace($start,$start.$attr,$s,$n1);
if($n1!==1){fwrite(STDERR,"register replace failed\n");exit(4);}
$legal="\$this->q('INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)',[\$id,self::LEGAL,\$this->now()]);\$this->db->commit();";
$replacement="\$this->q('INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)',[\$id,self::LEGAL,\$this->now()]);if(\$attr){\$this->q('INSERT IGNORE INTO mc_attribution(user_id,first_source,method,campaign_id,utm_source,utm_medium,utm_campaign,utm_content,landing_path,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[\$id,\$attr['source'],\$attr['method'],\$attr['campaignId']?:null,\$attr['utmSource']?:null,\$attr['utmMedium']?:null,\$attr['utmCampaign']?:null,\$attr['utmContent']?:null,\$attr['landingPath']?:null,\$this->now()]);}\$this->db->commit();";
if(!str_contains($s,$legal)){fwrite(STDERR,"legal marker missing\n");exit(5);}
$s=str_replace($legal,$replacement,$s,$n2);
if($n2!==1){fwrite(STDERR,"legal replace failed\n");exit(6);}
$backup=$app.'.pre-attribution-backend-'.date('Ymd_His').'.bak';
if(!copy($app,$backup)){fwrite(STDERR,"backup failed\n");exit(7);}
$tmp=$app.'.attr2.tmp';
if(file_put_contents($tmp,$s,LOCK_EX)===false){fwrite(STDERR,"write failed\n");exit(8);}
@chmod($tmp,fileperms($app)&0777);
if(!rename($tmp,$app)){fwrite(STDERR,"replace failed\n");exit(10);}
echo json_encode(['status'=>'ok','backend_patched'=>true,'backup'=>basename($backup)]).PHP_EOL;
