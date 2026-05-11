<?php
// Simple analyzer to understand file structure

function _parseTextQuestions(string $text): array {
    // Split on "....." or "...." to detect question boundaries
    $questionBlocks = preg_split('/\.{4,}/', $text, -1, PREG_SPLIT_NO_EMPTY);
    
    if (count($questionBlocks) <= 1) {
        return [];
    }

    $questions = [];
    foreach ($questionBlocks as $block) {
        $lines = preg_split('/\r\n|\r|\n/', trim($block));
        if (empty($lines)) continue;

        // First non-empty line is the question
        $questionText = trim(array_shift($lines));
        if (!$questionText) continue;

        $options = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));
            if ($line === '') continue;

            // Check if line is an option with letter prefix
            if (preg_match('/^([A-Ea-e])[\).\s:-]+(.+)$/', $line, $m)) {
                $optText = trim($m[2]);
                $options[] = [
                    'option_text' => $optText,
                    'label'       => strtoupper($m[1]),
                ];
            } elseif (!empty($options)) {
                // Append to last option
                $lastIdx = count($options) - 1;
                if ($lastIdx >= 0) {
                    $options[$lastIdx]['option_text'] .= ' ' . $line;
                }
            } else {
                // Standalone option without letter
                $options[] = [
                    'option_text' => $line,
                    'label'       => chr(65 + count($options)),
                ];
            }
        }

        if (!empty($options)) {
            $questions[] = [
                'question_text' => $questionText,
                'options'       => $options,
            ];
        }
    }

    return $questions;
}

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

$text = '';
foreach ($doc->xpath('//w:p') as $p) {
    foreach ($p->xpath('.//w:t') as $t) {
        $text .= (string)$t;
    }
    $text .= "\n";
}

echo "=== FILE ANALYSIS ===\n";
echo "Total lines: " . count(preg_split('/\n/', $text)) . "\n";
echo "\n=== FIRST 20 LINES ===\n";
$lines = preg_split('/\n/', $text);
for ($i = 0; $i < min(20, count($lines)); $i++) {
    echo sprintf("[%d] %s\n", $i+1, mb_substr(trim($lines[$i]), 0, 100));
}

echo "\n=== PARSING RESULTS ===\n";
$questions = _parseTextQuestions($text);
echo "Total questions parsed: " . count($questions) . "\n";
echo "\n";

foreach ($questions as $i => $q) {
    echo sprintf("Question %d:\n", $i+1);
    echo sprintf("  Text: %s\n", mb_substr($q['question_text'], 0, 150));
    echo sprintf("  Options: %d\n", count($q['options']));
    foreach ($q['options'] as $opt) {
        echo sprintf("    %s. %s\n", $opt['label'], mb_substr($opt['option_text'], 0, 80));
    }
    echo "\n";
}
?>
