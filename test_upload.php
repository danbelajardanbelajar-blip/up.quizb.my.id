<?php
$ch = curl_init('https://quizb.my.id/api.php?action=attempt.upload_snapshot');
$payload = json_encode(array("attempt_id" => 9999, "image" => "data:image/jpeg;base64,/9j/4AAQ"));
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$result = curl_exec($ch);
curl_close($ch);
echo "Result: " . $result;
