<?php
/** HTTPS bridge compatible with the existing LPPAI VPS face engine. */
function proctoringEngineConfig(): array {
    $path=__DIR__.'/../config/proctoring.php';
    $config=is_file($path) ? require $path : [];
    if (!is_array($config)) $config=[];
    return array_merge($config,[
        'url'=>rtrim((string)(getenv('QUIZB_FACE_ENGINE_URL') ?: ($config['url']??'')),'/'),
        'token'=>(string)(getenv('QUIZB_FACE_ENGINE_TOKEN') ?: ($config['token']??'')),
    ]);
}
function proctoringUsesVps(): bool {
    $config=proctoringEngineConfig();
    return ($config['driver']??'')==='remote' || $config['url']!=='';
}
function proctoringRemoteResult(array $result, bool $enrolling, bool $challenge): array {
    if (($result['engine']??'')!=='opencv-sface-v1' || !is_bool($result['match']??null)
        || !is_string($result['status']??null) || !is_numeric($result['similarity']??null)
        || !is_finite((float)$result['similarity']) || abs((float)$result['similarity'])>100)
        return ['status'=>'UNAVAILABLE'];
    $score=(float)$result['similarity']/100;
    if ($result['match']===true && $result['status']==='MATCH' && $score>=0.45
        && (!$challenge || ($result['motion_passed']??false)===true)) {
        return ['status'=>$enrolling?'FACE_PRESENT':'MATCH','cosine'=>$score,'roll'=>0,
            'motion_passed'=>$challenge,'engine'=>'opencv-sface-v1','model'=>'opencv-sface-v1'];
    }
    return ['status'=>match($result['status']) {
        'MISMATCH'=>'RECHECK','MOTION_REQUIRED'=>'MOTION_REQUIRED','NO_FACE'=>'NO_FACE',default=>'UNAVAILABLE'
    }];
}
function proctoringRemote(array $input, bool $health): array {
    $config=proctoringEngineConfig();$url=$config['url'];
    $parts=parse_url($url);
    if (($parts['scheme']??'')!=='https' || !empty($parts['user']) || !empty($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || strlen($config['token'])<32
        || !function_exists('curl_init')) return ['status'=>'UNAVAILABLE'];
    $curl=curl_init($url.($health?'/health':'/verify'));if (!$curl) return ['status'=>'UNAVAILABLE'];$body='';
    $options=[CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$config['token']],
        CURLOPT_WRITEFUNCTION=>static function($curl,$chunk) use (&$body) {
            if (strlen($body)+strlen($chunk)>32768) return 0;$body.=$chunk;return strlen($chunk);
        }];
    if (!$health) {
        $enrolling=empty($input['reference']);
        $frames=$input['frames']??[$input['frame']??''];
        $direction=$input['direction']??null;
        if (!is_array($frames) || count($frames)!==($direction?9:1) || !in_array($direction,[null,'left','right'],true)) { curl_close($curl);return ['status'=>'INVALID_IMAGE']; }
        foreach ($frames as $frame) if (!is_string($frame) || strlen($frame)>700000) { curl_close($curl);return ['status'=>'INVALID_IMAGE']; }
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_POSTFIELDS]=json_encode(['reference'=>$enrolling?$frames[0]:$input['reference'],'frames'=>$frames,'direction'=>$direction]);
    }
    curl_setopt_array($curl,$options);$ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if ($http===503) return ['status'=>'SERVER_BUSY'];
    if ($ok===false || $http!==200) return ['status'=>'UNAVAILABLE'];
    $result=json_decode($body,true);if (!is_array($result)) return ['status'=>'UNAVAILABLE'];
    if ($health) return ($result['ready']??false)===true && ($result['engine']??'')==='opencv-sface-v1'
        && ($result['capture_protocol']??'')==='pose-burst-v2' && ($result['max_frames']??0)>=9
        ? ['status'=>'READY','model'=>'opencv-sface-v1'] : ['status'=>'UNAVAILABLE'];
    return proctoringRemoteResult($result,$enrolling,$direction!==null);
}
