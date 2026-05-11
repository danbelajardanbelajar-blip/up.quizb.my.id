<?php
// Smart analyzer: detect questions by finding 5 consecutive option lines

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

echo "=== ANALYZING FILE ===\n";
echo "Total non-empty lines: " . count($lines) . "\n\n";

// Strategy: Find patterns of [question lines] + [5 option lines]
// Options are typically shorter, single-line items
// Questions can be multiple lines or just the direct question

$questions = [];
$i = 1; // Skip header (line 0)
$questionNum = 0;

while ($i < count($lines)) {
    // Check if next 5 consecutive lines could be options
    if ($i + 4 < count($lines)) {
        $potentialOptions = array_slice($lines, $i, 5);
        
        // Check if this looks like 5 options:
        // - All should be reasonably short (not full paragraphs)
        // - Should not start with numbers or bullet points typically
        $avgLength = array_sum(array_map('strlen', $potentialOptions)) / 5;
        
        // If average length is less than 100 chars and no very long lines, likely options
        $isOptionGroup = true;
        foreach ($potentialOptions as $opt) {
            if (strlen($opt) > 150) { // Too long for an option
                $isOptionGroup = false;
                break;
            }
        }
        
        if ($isOptionGroup && $avgLength < 80) {
            // Found 5 potential options!
            // Find the question: gather lines before this until we find empty space or previous options
            $questionStart = $i - 1;
            
            // Go backwards to find the actual question (skip short transition lines)
            while ($questionStart > 0 && strlen($lines[$questionStart]) > 150) {
                $questionStart--;
            }
            
            // Gather all lines from questionStart to i as the question text
            $questionLines = [];
            for ($j = $questionStart; $j < $i; $j++) {
                $questionLines[] = $lines[$j];
            }
            
            $questionNum++;
            $questions[] = [
                'num' => $questionNum,
                'question' => implode(' ', $questionLines),
                'options' => $potentialOptions,
                'lineRange' => [$questionStart, $i + 4],
            ];
            
            // Move pointer past the options
            $i += 5;
            continue;
        }
    }
    
    $i++;
}

echo "Detected questions: " . count($questions) . "\n\n";

// Display detected questions
foreach ($questions as $q) {
    echo sprintf("=== QUESTION %d ===\n", $q['num']);
    echo sprintf("Q: %s\n\n", mb_substr($q['question'], 0, 120));
    foreach ($q['options'] as $idx => $opt) {
        echo sprintf("%s. %s\n", chr(65 + $idx), mb_substr($opt, 0, 90));
    }
    echo "\n";
}

echo sprintf("\nSUMMARY: Found %d questions with proper structure\n", count($questions));
?>
