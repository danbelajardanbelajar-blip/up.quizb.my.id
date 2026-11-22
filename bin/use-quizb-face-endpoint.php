<?php
// CLI-only activation: probe before writing, keep a private backup, never display the token.
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/proctoring-verification.php';
$target=__DIR__.'/../config/proctoring.php';
if (!is_file($target)) {fwrite(STDERR,"QuizB private configuration is missing.\n");exit(1);}
$config=require $target;
if (!is_array($config) || strlen($config['token']??'')<32) {fwrite(STDERR,"Private configuration invalid.\n");exit(1);}
$url='https://face.lppai.quizb.my.id/quizb';
putenv('QUIZB_FACE_ENGINE_URL='.$url);
$health=proctoringRuntime([],true);
if (($health['status']??'')!=='READY' || !function_exists('imagecreatetruecolor')) {fwrite(STDERR,"New endpoint or GD is not ready. Configuration unchanged.\n");exit(1);}
$image=imagecreatetruecolor(640,480);ob_start();imagejpeg($image,null,85);$blank=base64_encode(ob_get_clean());imagedestroy($image);
$result=proctoringRuntime(['frame'=>$blank,'reference'=>$blank]);
if (($result['status']??'')!=='NO_FACE') {fwrite(STDERR,"Authenticated probe failed. Configuration unchanged.\n");exit(1);}
$userHomePath=getenv('HOME') ?: getenv('USERPROFILE');
if (!$userHomePath) {fwrite(STDERR,"Cannot determine private backup directory.\n");exit(1);}
$backupDir=$userHomePath.'/.local/share/quizb-config-backups';
$oldMask=umask(0077);
try {
    if (!is_dir($backupDir) && !mkdir($backupDir,0700,true)) throw new RuntimeException('Cannot create backup directory');
    $backup=$backupDir.'/proctoring-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.php';
    if (file_put_contents($backup,file_get_contents($target),LOCK_EX)===false) throw new RuntimeException('Cannot create backup');
    chmod($backup,0600);
    $config['driver']='remote';$config['url']=$url;
    $temporary=tempnam(dirname($target),'proctoring-tmp-');
    if (!$temporary || file_put_contents($temporary,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false) throw new RuntimeException('Cannot prepare configuration');
    chmod($temporary,0600);
    if (!rename($temporary,$target)) throw new RuntimeException('Cannot activate configuration');
    echo "PASS: QuizB dedicated endpoint activated. Credentials accepted; blank image rejected. Private backup saved; token not displayed.\n";
} catch (Throwable $error) {
    if (isset($temporary) && is_file($temporary)) unlink($temporary);
    fwrite(STDERR,"Activation failed. Existing configuration retained.\n");exit(1);
} finally {umask($oldMask);}
