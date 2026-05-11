<?php
// Advanced analyzer: detect questions by finding 5-option patterns

$filePath = $argv[1] ?? '';
if (!$filePath || !file_exists($filePath)) {
    echo "File not found\n";
    exit(1);
}

// Extract text from DOCX
$zip = new ZipArchive();
if ($zip->open($filePath) !== true) {
    echo "Cannot open ZIP\n";
    exit(1);
}

$xml = $zip->getFromName('word/document.xml');
$zip->close();

if (!$xml) {
    echo "Cannot read document.xml\n";
    exit(1);
}

$doc = simplexml_load_string($xml);
$doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

// Extract all paragraphs as lines
$lines = [];
foreach ($doc->xpath('//w:p') as $p) {
    $text = '';
    foreach ($p->xpath('.//w:t') as $t) {
        $text .= (string)$t;
    }
    $trimmed = trim($text);
    if ($trimmed !== '') {
        $lines[] = $trimmed;
    }
}

echo "=== FILE STRUCTURE ===\n";
echo "Total non-empty lines: " . count($lines) . "\n";
echo "\n=== FIRST 30 LINES ===\n";
for ($i = 0; $i < min(30, count($lines)); $i++) {
    echo sprintf("[%d] %s\n", $i, mb_substr($lines[$i], 0, 80));
}

// Try to detect pattern: groups of 5 consecutive non-empty items = options
echo "\n=== PATTERN DETECTION ===\n";
$questions = [];
$i = 0;

while ($i < count($lines)) {
    // Look ahead to see if next 5 lines form an options group
    $nextFive = [];
    for ($j = 0; $j < 5 && ($i + $j) < count($lines); $j++) {
        $nextFive[] = $lines[$i + $j];
    }
    
    // If we have 5 lines, assume they are options and look for the question before them
    if (count($nextFive) === 5) {
        // Find the question: go backwards from current position to find substantial text
        $questionIdx = $i - 1;
        while ($questionIdx >= 0 && strlen($lines[$questionIdx]) < 5) {
            $questionIdx--;
        }
        
        if ($questionIdx >= 0) {
            // We found potential question and 5 options
            $question = $lines[$questionIdx];
            $options = $nextFive;
            
            $questions[] = [
                'line_num' => $questionIdx,
                'question' => mb_substr($question, 0, 100),
                'options' => array_map(function($o) { return mb_substr($o, 0, 60); }, $options),
            ];
            
            // Skip the question and options
            $i = $i + 5;
        } else {
            $i++;
        }
    } else {
        $i++;
    }
}

echo "Detected pattern matches: " . count($questions) . "\n";
foreach ($questions as $idx => $q) {
    echo sprintf("\nQuestion %d (line %d):\n", $idx + 1, $q['line_num']);
    echo sprintf("  Q: %s\n", $q['question']);
    echo "  Options:\n";
    foreach ($q['options'] as $oi => $opt) {
        echo sprintf("    %s. %s\n", chr(65 + $oi), $opt);
    }
}
?>
