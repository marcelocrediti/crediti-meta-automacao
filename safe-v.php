<?php
$home=getenv('HOME')?:'/home/u606090814';
$base=$home.'/domains/meucrediti.creditisolucoes.com.br';
$app=(string)@file_get_contents($base.'/meucrediti-private/app.php');
$js=(string)@file_get_contents($base.'/public_html/meucrediti/attribution.js');
echo json_encode([
 'registration_has_attribution_payload'=>str_contains($app,"$attrRaw=is_array($b['attribution']??null)"),
 'capture_route'=>str_contains($app,"/api/attribution/capture"),
 'registration_transaction_has_attribution_insert'=>str_contains($app,"INSERT IGNORE INTO mc_attribution(user_id") && str_contains($app,"if($attr)"),
 'tracker_modifies_registration_body'=>str_contains($js,"body.attribution=attr"),
 'tracker_posts_after_success'=>str_contains($js,"/api/attribution/capture") && str_contains($js,"resp.ok"),
],JSON_UNESCAPED_SLASHES).PHP_EOL;
