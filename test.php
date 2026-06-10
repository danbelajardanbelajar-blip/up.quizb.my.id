<?php
require_once 'api/question.php';
$file = 'C:\\Users\\zenhk\\OneDrive\\Documents\\2024\\Genap\\SOAL\\UAS\\Ushul Fiqh - Kelas X - UAS.docx';
$result = _parseDocx($file);
$noKey = 0;
foreach ($result as $q) {
    $hasKey = false;
    foreach ($q['options'] as $o) {
        if ($o['is_correct']) $hasKey = true;
    }
    if (!$hasKey) $noKey++;
}
echo "Total Questions: " . count($result) . PHP_EOL;
echo "Questions without a key: " . $noKey . PHP_EOL;
$last = end($result);
echo "Last question options count: " . count($last['options']) . PHP_EOL;
echo json_encode($last, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
