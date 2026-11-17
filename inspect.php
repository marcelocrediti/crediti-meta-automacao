<?php
$home=getenv('HOME')?:'/home/u606090814';
$f=$home.'/domains/meucrediti.creditisolucoes.com.br/meucrediti-private/app.php';
$s=(string)file_get_contents($f);
$p=strpos($s,"if(\$path==='/api/auth/register'");
if($p===false){echo "register not found\n";exit;}
echo substr($s,$p,4500);
