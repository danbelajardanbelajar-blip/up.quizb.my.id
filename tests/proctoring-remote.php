<?php
require __DIR__.'/../includes/proctoring-verification.php';
function remoteAssert(bool $value,string $label): void {if (!$value) throw new RuntimeException($label);echo "PASS $label\n";}
$good=['engine'=>'opencv-sface-v1','match'=>true,'status'=>'MATCH','similarity'=>65.2,'motion_passed'=>true];
remoteAssert(proctoringRemoteResult($good,false,true)['status']==='MATCH','VPS verified identity and motion accepted');
remoteAssert(proctoringRemoteResult($good,true,false)['status']==='FACE_PRESENT','enrollment uses server quality check');
foreach ([['match'=>'true'],['engine'=>'unknown'],['status'=>'MISMATCH'],['similarity'=>NAN],['similarity'=>INF],['similarity'=>101],['similarity'=>44.9],['motion_passed'=>false]] as $override) remoteAssert(proctoringRemoteResult(array_merge($good,$override),false,true)['status']!=='MATCH','invalid or incomplete VPS verdict rejected');
remoteAssert(proctoringRemoteResult(array_merge($good,['motion_passed'=>false]),false,false)['status']==='MATCH','periodic single image does not pretend to verify motion');
putenv('QUIZB_FACE_ENGINE_URL=https://face.example.test');putenv('QUIZB_FACE_ENGINE_TOKEN=');
remoteAssert(proctoringRuntime([],true)['status']==='UNAVAILABLE','remote unavailable never falls back to PHP process');
