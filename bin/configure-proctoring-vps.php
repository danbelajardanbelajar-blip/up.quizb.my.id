<?php
// CLI-only: reuse private LPPAI service settings, never print the token.
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
$source=$argv[1]??'';
if (!$source || !is_file($source)) {fwrite(STDERR,"Private LPPAI config path not found.\n");exit(1);}
ob_start();$settings=require $source;ob_end_clean();
if (!is_array($settings) || strlen($settings['token']??'')<32 || rtrim((string)($settings['url']??''),'/')!=='https://face.lppai.quizb.my.id') {fwrite(STDERR,"LPPAI configuration is invalid or points to another service.\n");exit(1);}
$destination=__DIR__.'/../config/proctoring.php';
if (is_file($destination)) {fwrite(STDERR,"QuizB private config already exists; inspect it without exposing its token before changing.\n");exit(1);}
$mask=umask(0077);
$ok=file_put_contents($destination,"<?php\nreturn ".var_export(['driver'=>'remote','url'=>'https://face.lppai.quizb.my.id/quizb','token'=>$settings['token']],true).";\n",LOCK_EX);
umask($mask);
if ($ok===false) {fwrite(STDERR,"Cannot save QuizB private configuration.\n");exit(1);}
echo "Private QuizB VPS configuration saved. Token was not displayed.\n";
