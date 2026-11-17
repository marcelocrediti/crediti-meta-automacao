<?php
declare(strict_types=1);

$home = getenv('HOME') ?: '/home/u606090814';
$base = $home . '/domains/meucrediti.creditisolucoes.com.br';
$public = $base . '/public_html/meucrediti';
$private = $base . '/meucrediti-private';
$app = $private . '/app.php';
$configFile = $private . '/config.php';
$html = $public . '/app.html';
$js = $public . '/attribution.js';
$stamp = date('Ymd_His');

function failx(string $m, int $c=1): never { fwrite(STDERR,$m.PHP_EOL); exit($c); }

if (!is_file($app) || !is_file($configFile) || !is_file($html)) failx('required files missing',2);
$config = require $configFile;
if (!is_array($config) || empty($config['dsn']) || empty($config['db_user']) || !array_key_exists('db_password',$config)) failx('invalid config',3);

try {
  $db = new PDO($config['dsn'],$config['db_user'],$config['db_password'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
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
    CONSTRAINT fk_mc_attribution_user FOREIGN KEY (user_id) REFERENCES mc_users(id) ON DELETE CASCADE,
    INDEX idx_mc_attribution_source(first_source),
    INDEX idx_mc_attribution_campaign(campaign_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch(Throwable $e) { failx('migration failed: '.$e->getMessage(),4); }

$tracker = <<<'JS'
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
      return {
        firstSource:normalizeSource(p.get('utm_source')||p.get('source')),
        method:'play_install_referrer',
        campaignId:clean(p.get('campaign_id')||p.get('utm_id'),160),
        utmSource:clean(p.get('utm_source'),80),
        utmMedium:clean(p.get('utm_medium'),80),
        utmCampaign:clean(p.get('utm_campaign'),180),
        utmContent:clean(p.get('utm_content'),180),
        landingPath:clean(location.pathname,255),
        capturedAt:Date.now()
      };
    }catch{return null}
  }
  function candidateFromUrl(){
    const p=new URLSearchParams(location.search);
    const signal=['utm_source','utm_medium','utm_campaign','utm_content','utm_id','campaign_id','mc_referrer','install_referrer'].some(k=>p.has(k));
    if(!signal)return null;
    const nativeRef=p.get('mc_referrer')||p.get('install_referrer');
    if(nativeRef){
      const x=parseReferrer(nativeRef);
      if(x)return x;
    }
    return {
      firstSource:normalizeSource(p.get('utm_source')||p.get('source')),
      method:'url_utm',
      campaignId:clean(p.get('campaign_id')||p.get('utm_id'),160),
      utmSource:clean(p.get('utm_source'),80),
      utmMedium:clean(p.get('utm_medium'),80),
      utmCampaign:clean(p.get('utm_campaign'),180),
      utmContent:clean(p.get('utm_content'),180),
      landingPath:clean(location.pathname,255),
      capturedAt:Date.now()
    };
  }
  function valid(x){
    return x && typeof x==='object' && (
      x.firstSource!=='desconhecido' || x.campaignId || x.utmSource || x.utmCampaign
    );
  }
  function storeFirst(x){
    if(!valid(x))return;
    try{
      if(!localStorage.getItem(KEY))localStorage.setItem(KEY,JSON.stringify(x));
    }catch{}
  }
  storeFirst(candidateFromUrl());
  try{
    if(window.MeuCreditiAttribution && typeof window.MeuCreditiAttribution.getInstallReferrer==='function'){
      const raw=window.MeuCreditiAttribution.getInstallReferrer();
      storeFirst(parseReferrer(raw));
    }
  }catch{}
  function current(){
    try{
      const x=JSON.parse(localStorage.getItem(KEY)||'null');
      return valid(x)?x:null;
    }catch{return null}
  }
  const originalFetch=window.fetch.bind(window);
  window.fetch=function(input,init){
    try{
      const url=typeof input==='string'?input:(input&&input.url)||'';
      const method=String(init&&init.method||'GET').toUpperCase();
      if(method==='POST' && (url.includes('/api/auth/register') || url.includes('route=%2Fapi%2Fauth%2Fregister') || url.includes('route=/api/auth/register')) && init && typeof init.body==='string'){
        const attr=current();
        if(attr){
          const body=JSON.parse(init.body);
          if(body && typeof body==='object' && !Array.isArray(body)){
            body.attribution=attr;
            init={...init,body:JSON.stringify(body)};
          }
        }
      }
    }catch{}
    return originalFetch(input,init);
  };
  window.CreditiAttribution={get:current,clear:function(){try{localStorage.removeItem(KEY)}catch{}}};
})();
JS;
if (file_put_contents($js,$tracker,LOCK_EX)===false) failx('tracker write failed',5);
@chmod($js,0644);

$htmlText = file_get_contents($html);
if ($htmlText===false) failx('html read failed',6);
if (!str_contains($htmlText,'attribution.js?v=20261117')) {
  copy($html,$html.'.pre-attribution-'.$stamp.'.bak');
  $tag='<script src="attribution.js?v=20261117"></script>';
  if (str_contains($htmlText,'</body>')) $htmlText=str_replace('</body>',$tag."\n</body>",$htmlText);
  else $htmlText.="\n".$tag."\n";
  if (file_put_contents($html,$htmlText,LOCK_EX)===false) failx('html patch failed',7);
}

$appText = file_get_contents($app);
if ($appText===false) failx('app read failed',8);
if (!str_contains($appText,'mc_attribution')) {
  copy($app,$app.'.pre-attribution-'.$stamp.'.bak');

  $registerNeedle = "if(\$path==='/api/auth/register'&&\$method==='POST'){\n   \$b=\$this->body();";
  $attrCode = <<<'PHP'

   $attrRaw=is_array($b['attribution']??null)?$b['attribution']:[];
   $attr=null;
   if($attrRaw){
    $aclean=static function(mixed $v,int $max): string {
     if(!is_string($v)&&!is_numeric($v))return '';
     $s=trim((string)$v);$s=preg_replace('/[\x00-\x1f\x7f]/u','',$s)??'';
     return mb_substr($s,0,$max);
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
  if(!str_contains($appText,$registerNeedle)) failx('register marker not found',9);
  $new=str_replace($registerNeedle,$registerNeedle.$attrCode,$appText,$count1);
  if($count1!==1) failx('register marker count invalid',9);

  $legalNeedle = "\$this->q('INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)',[\$id,self::LEGAL,\$this->now()]);\$this->db->commit();";
  $legalReplace = "\$this->q('INSERT INTO mc_legal(user_id,version,accepted_at) VALUES(?,?,?)',[\$id,self::LEGAL,\$this->now()]);if(\$attr){\$this->q('INSERT IGNORE INTO mc_attribution(user_id,first_source,method,campaign_id,utm_source,utm_medium,utm_campaign,utm_content,landing_path,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[\$id,\$attr['source'],\$attr['method'],\$attr['campaignId']?:null,\$attr['utmSource']?:null,\$attr['utmMedium']?:null,\$attr['utmCampaign']?:null,\$attr['utmContent']?:null,\$attr['landingPath']?:null,\$this->now()]);}\$this->db->commit();";
  if(!str_contains($new,$legalNeedle)) failx('transaction marker not found',10);
  $new=str_replace($legalNeedle,$legalReplace,$new);

  $tmp=$app.'.attr.tmp';
  if(file_put_contents($tmp,$new,LOCK_EX)===false) failx('app tmp write failed',11);
  $lint=[];$rc=0;exec(PHP_BINARY.' -l '.escapeshellarg($tmp).' 2>&1',$lint,$rc);
  if($rc!==0){@unlink($tmp);failx('lint failed: '.implode(' | ',$lint),12);}
  @chmod($tmp,fileperms($app)&0777);
  if(!rename($tmp,$app))failx('app replace failed',13);
}

echo json_encode([
  'status'=>'ok',
  'table'=>true,
  'tracker'=>is_file($js),
  'html_injected'=>str_contains((string)file_get_contents($html),'attribution.js?v=20261117'),
  'backend_patched'=>str_contains((string)file_get_contents($app),'mc_attribution')
],JSON_UNESCAPED_SLASHES).PHP_EOL;
