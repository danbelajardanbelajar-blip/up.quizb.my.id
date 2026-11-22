<?php
// Real PHP -> Python -> ONNX integration; fixtures must be public test samples outside this repo.
require __DIR__.'/../includes/proctoring-verification.php';
function ensureRuntime(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
ensureRuntime((proctoringRuntime([],true)['status']??null)==='READY','PHP can load the actual face models');
$directory=getenv('QUIZB_FACE_FIXTURES');
if (!$directory || !is_file($directory.'/lena.jpg')) throw new RuntimeException('QUIZB_FACE_FIXTURES required');
$reference=base64_encode(file_get_contents($directory.'/lena.jpg'));
$result=proctoringRuntime(['frame'=>$reference,'reference'=>$reference]);
ensureRuntime(($result['status']??null)==='MATCH' && ($result['cosine']??0)>.99,'actual same-face image matches through PHP pipe');
ensureRuntime((proctoringRuntime(['frame'=>'invalid','reference'=>$reference])['status']??null)!=='MATCH','invalid frame never matches through PHP pipe');
$old=getenv('QUIZB_PROCTORING_PYTHON');putenv('QUIZB_PROCTORING_PYTHON=/missing/quizb-python');
ensureRuntime((proctoringRuntime([],true)['status']??null)==='UNAVAILABLE','missing interpreter fails closed');
putenv('QUIZB_PROCTORING_PYTHON='.$old);
