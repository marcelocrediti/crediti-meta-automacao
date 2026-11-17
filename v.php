<?php
declare(strict_types=1);
$home=getenv('HOME')?:'/home/u606090814';
$base=$home.'/domains/meucrediti.creditisolucoes.com.br';
$private=$base.'/meucrediti-private';
$public=$base.'/public_html/meucrediti';
$app=$private.'/app.php';
$config=require $private.'/config.php';
$db=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$exists=(bool)$db->query("SHOW TABLES LIKE 'mc_attribution'")->fetchColumn();
$count=$exists?(int)$db->query("SELECT COUNT(*) FROM mc_attribution")->fetchColumn():0;
$appText=is_file($app)?(string)file_get_contents($app):'';
$html=is_file($public.'/app.html')?(string)file_get_contents($public.'/app.html'):'';
echo json_encode([
 'table'=>$exists,
 'rows'=>$count,
 'backend_patched'=>str_contains($appText,'INSERT IGNORE INTO mc_attribution'),
 'tracker_file'=>is_file($public.'/attribution.js'),
 'html_injected'=>str_contains($html,'attribution.js?v=20261117')
],JSON_UNESCAPED_SLASHES).PHP_EOL;
