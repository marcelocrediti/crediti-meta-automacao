<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');
register_shutdown_function(function(){
  $e=error_get_last();
  if($e) echo "\nSHUTDOWN:".json_encode($e,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
});
$home=getenv('HOME')?:'/home/u606090814';
$priv=$home.'/domains/meucrediti.creditisolucoes.com.br/meucrediti-teste-private';
echo "stage:paths\n";
echo json_encode(['config'=>is_file($priv.'/config.php'),'app'=>is_file($priv.'/app.php'),'novo'=>is_file($priv.'/novo.php')])."\n";
$c=require $priv.'/config.php';
echo "stage:config\n";
echo json_encode(['is_array'=>is_array($c),'dsn_test'=>str_contains((string)($c['dsn']??''),'u606090814_mcteste'),'novo_enabled'=>!empty($c['novo_enabled'])])."\n";
require_once $priv.'/app.php';
echo "stage:app_loaded\n";
$appObj=new App($c);
echo "stage:app_created\n";
$db=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo "stage:db_connected\n";
echo "users:".$db->query("SELECT COUNT(*) FROM mc_users")->fetchColumn()."\n";
