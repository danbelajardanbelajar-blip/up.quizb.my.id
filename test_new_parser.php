<?php
// Test the new parser logic directly

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

// Decode XML
$doc = simplexml_load_string($xml);
$doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

$fullText = '';
foreach ($doc->xpath('//w:p') as $p) {
    $text = '';
    foreach ($p->xpath('.//w:t') as $t) {
        $text .= (string)$t;
    }
    if (trim($text) !== '') {
        $fullText .= trim($text) . "\n";
    }
}

// NEW LOGIC from _parseTextQuestions()
$lines = [];
foreach (preg_split('/\r\n|\r|\n/', trim($fullText)) as $line) {
    $trimmed = trim(preg_replace('/\s+/', ' ', $line));
    if ($trimmed !== '') {
        $lines[] = $trimmed;
    }
}

echo "Total lines: " . count($lines) . "\n\n";

$questions = [];
$i = 0;

while ($i < count($lines)) {
    // Check if next 5 lines could be options
    if ($i + 5 <= count($lines)) {
        $potentialOptions = array_slice($lines, $i, 5);
        
        $avgLength = array_sum(array_map('strlen', $potentialOptions)) / 5;
        $isOptionGroup = true;
        
        foreach ($potentialOptions as $opt) {
            if (strlen($opt) > 150) {
                $isOptionGroup = false;
                break;
            }
        }
        
        foreach ($potentialOptions as $opt) {
            if (preg_match('/^(?:No\.?\s*)?(\d+)[\).\s:-]+/i', $opt) || 
                strpos($opt, 'Jawaban') !== false || 
                strpos($opt, 'Kunci') !== false) {
                $isOptionGroup = false;
                break;
            }
        }
        
        if ($isOptionGroup && $avgLength > 5 && $avgLength < 100) {
            // Found options!
            $questionStart = $i - 1;
            $questionLines = [];
            
            for ($j = $questionStart; $j >= 0; $j--) {
                if (strlen($lines[$j]) > 200) {
                    if (!empty($questionLines)) break;
                    $questionLines[] = $lines[$j];
                } elseif (strlen($lines[$j]) > 30) {
                    $questionLines[] = $lines[$j];
                } else {
                    if (!empty($questionLines)) break;
                }
            }
            
            if (!empty($questionLines)) {
                $questionLines = array_reverse($questionLines);
                $questionText = implode(' ', $questionLines);
                
                $options = [];
                foreach ($potentialOptions as $idx => $optLine) {
                    $optText = $optLine;
                    $label = chr(65 + $idx);
                    
                    if (preg_match('/^([A-Ea-e])[\).\s:-]+(.+)$/', $optLine, $m)) {
                        $label = strtoupper($m[1]);
                        $optText = trim($m[2]);
                    }
                    
                    $options[] = [
                        'text' => $optText,
                        'label' => $label,
                    ];
                }
                
                if (!empty($options)) {
                    $questions[] = [
                        'question' => mb_substr($questionText, 0, 100),
                        'options' => $options,
                    ];
                }
            }
            
            $i += 5;
            continue;
        }
    }
    
    $i++;
}

echo "DETECTED QUESTIONS: " . count($questions) . "\n\n";

// Show first 10
for ($idx = 0; $idx < min(10, count($questions)); $idx++) {
    $q = $questions[$idx];
    echo sprintf("Q%d: %s\n", $idx + 1, $q['question']);
    foreach ($q['options'] as $opt) {
        echo sprintf("  %s. %s\n", $opt['label'], mb_substr($opt['text'], 0, 60));
    }
    echo "\n";
}

if (count($questions) > 10) {
    echo "... and " . (count($questions) - 10) . " more questions\n";
}

echo "\nSUCCESS: Parser detected " . count($questions) . " questions\n";
?>
