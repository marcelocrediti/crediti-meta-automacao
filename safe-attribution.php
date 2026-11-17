<?php
declare(strict_types=1);
$home=getenv('HOME')?:'/home/u606090814';
$base=$home.'/domains/meucrediti.creditisolucoes.com.br';
$app=$base.'/meucrediti-private/app.php';
$html=$base.'/public_html/meucrediti/app.html';
$js=$base.'/public_html/meucrediti/attribution.js';
if(!is_file($app)||!is_file($html)){fwrite(STDERR,"required files missing\n");exit(2);}
$s=(string)file_get_contents($app);
$stamp=date('Ymd_His');
copy($app,$app.'.pre-safe-attribution-'.$stamp.'.bak');

$attrStart="\n   \$attrRaw=is_array(\$b['attribution']??null)?\$b['attribution']:[];";
$attrEnd="\n   \$name=preg_replace('/\\s+/u',' ',trim((string)(\$b['name']??'')));";
if(str_contains($s,$attrStart)){
  $a=strpos($s,$attrStart); $b=strpos($s,$attrEnd,$a);
  if($b===false){fwrite(STDERR,"register attr end missing\n");exit(3);}
  $s=substr($s,0,$a).$attrEnd.substr($s,$b+strlen($attrEnd));
}

$legalPat="/\\$this->q\\('INSERT INTO mc_legal\\(user_id,version,accepted_at\\) VALUES\\(\\?,\\?,\\?\\)'[^;]*;if\\(\\$attr\\)\\{.*?\\}\\$this->db->commit\\(\\);/s";
$legalOrig="\$this->q('INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)',[\$id,self::LEGAL,\$this->now()]);\$this->db->commit();";
$s2=preg_replace($legalPat,$legalOrig,$s,1,$replaced);
if($s2!==null && $replaced===1)$s=$s2;

$sessionLine="\$u=\$this->session();if(!\$u)reject(401,'Entre na sua conta.');\$profile=\$this->decrypt(\$u['data_encrypted']);\$uid=\$u['id'];";
if(!str_contains($s,"/api/attribution/capture")){
  if(!str_contains($s,$sessionLine)){fwrite(STDERR,"session marker missing\n");exit(4);}
  $route=<<<'PHP'

   if($path==='/api/attribution/capture'&&$method==='POST'){
    $b=$this->body(4096);$raw=is_array($b['attribution']??null)?$b['attribution']:[];
    if($raw){
     $clean=static function(mixed $v,int $max): string {
      if(!is_string($v)&&!is_numeric($v))return '';
      $x=trim((string)$v);$x=preg_replace('/[\x00-\x1f\x7f]/u','',$x)??'';
      return substr($x,0,$max);
     };
     $source=$clean($raw['firstSource']??'desconhecido',32);
     $allowed=['instagram_ads','facebook_ads','google_ads','youtube_ads','direto','organico','desconhecido'];
     if(!in_array($source,$allowed,true))$source='desconhecido';
     $methodA=$clean($raw['method']??'url_utm',32);
     if(!in_array($methodA,['url_utm','play_install_referrer','app_store_campaign','manual_verified'],true))$methodA='url_utm';
     $campaignId=$clean($raw['campaignId']??'',160);$utmSource=$clean($raw['utmSource']??'',80);
     $utmMedium=$clean($raw['utmMedium']??'',80);$utmCampaign=$clean($raw['utmCampaign']??'',180);
     $utmContent=$clean($raw['utmContent']??'',180);$landingPath=$clean($raw['landingPath']??'',255);
     if($source!=='desconhecido'||$campaignId!==''||$utmSource!==''||$utmCampaign!==''){
      try{$this->q('INSERT IGNORE INTO mc_attribution(user_id,first_source,method,campaign_id,utm_source,utm_medium,utm_campaign,utm_content,landing_path,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$uid,$source,$methodA,$campaignId?:null,$utmSource?:null,$utmMedium?:null,$utmCampaign?:null,$utmContent?:null,$landingPath?:null,$this->now()]);}
      catch(Throwable $e){error_log('MeuCrediti attribution capture failed');}
     }
    }
    $this->output(['success'=>true]);return;
   }
PHP;
  $s=str_replace($sessionLine,$sessionLine.$route,$s,$n);
  if($n!==1){fwrite(STDERR,"route insert failed\n");exit(5);}
}

$tmp=$app.'.safeattr.tmp';
if(file_put_contents($tmp,$s,LOCK_EX)===false){fwrite(STDERR,"app write failed\n");exit(6);}
@chmod($tmp,fileperms($app)&0777);
if(!rename($tmp,$app)){fwrite(STDERR,"app replace failed\n");exit(7);}

$tracker=<<<'JS'
(function(){
  'use strict';
  const KEY='mc_attribution_v1';
  const allowedSources=new Set(['instagram_ads','facebook_ads','google_ads','youtube_ads','direto','organico','desconhecido']);
  const clean=(v,max=180)=>String(v||'').trim().replace(/[\u0000-\u001f\u007f]/g,'').slice(0,max);
  function normalizeSource(v){
    const s=String(v||'').toLowerCase().trim();
    if(['instagram','instagram_ads','ig','meta_instagram'].includes(s))return 'instagram_ads';
    if(['facebook','facebook_ads','fb','meta_facebook','meta'].includes(s))return 'facebook_ads';
    if(['google','google_ads','gads','adwords'].includes(s))return 'google_ads';
    if(['youtube','youtube_ads','yt'].includes(s))return 'youtube_ads';
    if(['organico','organic'].includes(s))return 'organico';
    if(['direto','direct'].includes(s))return 'direto';
    return allowedSources.has(s)?s:'desconhecido';
  }
  function parseReferrer(raw){
    try{
      const p=new URLSearchParams(String(raw||'').replace(/^\?/,''));
      return {firstSource:normalizeSource(p.get('utm_source')||p.get('source')),method:'play_install_referrer',
        campaignId:clean(p.get('campaign_id')||p.get('utm_id'),160),utmSource:clean(p.get('utm_source'),80),
        utmMedium:clean(p.get('utm_medium'),80),utmCampaign:clean(p.get('utm_campaign'),180),
        utmContent:clean(p.get('utm_content'),180),landingPath:clean(location.pathname,255),capturedAt:Date.now()};
    }catch{return null}
  }
  function candidateFromUrl(){
    const p=new URLSearchParams(location.search);
    const signal=['utm_source','utm_medium','utm_campaign','utm_content','utm_id','campaign_id','mc_referrer','install_referrer'].some(k=>p.has(k));
    if(!signal)return null;
    const nativeRef=p.get('mc_referrer')||p.get('install_referrer'); if(nativeRef){const x=parseReferrer(nativeRef);if(x)return x;}
    return {firstSource:normalizeSource(p.get('utm_source')||p.get('source')),method:'url_utm',
      campaignId:clean(p.get('campaign_id')||p.get('utm_id'),160),utmSource:clean(p.get('utm_source'),80),
      utmMedium:clean(p.get('utm_medium'),80),utmCampaign:clean(p.get('utm_campaign'),180),
      utmContent:clean(p.get('utm_content'),180),landingPath:clean(location.pathname,255),capturedAt:Date.now()};
  }
  function valid(x){return x&&typeof x==='object'&&(x.firstSource!=='desconhecido'||x.campaignId||x.utmSource||x.utmCampaign)}
  function storeFirst(x){if(!valid(x))return;try{if(!localStorage.getItem(KEY))localStorage.setItem(KEY,JSON.stringify(x));}catch{}}
  function current(){try{const x=JSON.parse(localStorage.getItem(KEY)||'null');return valid(x)?x:null}catch{return null}}
  storeFirst(candidateFromUrl());
  try{if(window.MeuCreditiAttribution&&typeof window.MeuCreditiAttribution.getInstallReferrer==='function')storeFirst(parseReferrer(window.MeuCreditiAttribution.getInstallReferrer()))}catch{}
  const originalFetch=window.fetch.bind(window);
  window.fetch=function(input,init){
    const url=typeof input==='string'?input:(input&&input.url)||'';
    const method=String(init&&init.method||'GET').toUpperCase();
    const isRegister=method==='POST'&&(url.includes('/api/auth/register')||url.includes('route=%2Fapi%2Fauth%2Fregister')||url.includes('route=/api/auth/register'));
    const req=originalFetch(input,init);
    if(!isRegister)return req;
    return req.then(resp=>{
      if(resp&&resp.ok){
        const attr=current();
        if(attr)setTimeout(()=>originalFetch('index.php?route='+encodeURIComponent('/api/attribution/capture'),{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-Requested-With':'MeuCrediti'},
          body:JSON.stringify({attribution:attr})
        }).catch(()=>{}),0);
      }
      return resp;
    });
  };
  window.CreditiAttribution={get:current,clear:function(){try{localStorage.removeItem(KEY)}catch{}}};
})();
JS;
if(file_put_contents($js,$tracker,LOCK_EX)===false){fwrite(STDERR,"tracker write failed\n");exit(8);}
@chmod($js,0644);
echo json_encode(['status'=>'ok','registration_untouched'=>true,'capture_route'=>true,'tracker_fail_open'=>true]).PHP_EOL;
