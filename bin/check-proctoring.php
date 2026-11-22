<?php
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require __DIR__.'/../includes/proctoring-verification.php';
$health=proctoringRuntime([],true);
echo 'Engine health: '.($health['status']??'INVALID').PHP_EOL;
if (($health['status']??'')!=='READY') exit(1);
if (!function_exists('imagecreatetruecolor')) {echo "GD is required for the blank-frame authorization probe.\n";exit(1);}
$image=imagecreatetruecolor(640,480);ob_start();imagejpeg($image,null,85);$blank=base64_encode(ob_get_clean());imagedestroy($image);
$result=proctoringRuntime(['frame'=>$blank,'reference'=>$blank]);
echo 'Authenticated blank-frame probe: '.($result['status']??'INVALID').PHP_EOL;
if (($result['status']??'')!=='NO_FACE') exit(1);
echo "PASS: model ready, credentials accepted, blank image rejected. No participant images used.\n";
