<?php
// Direct test: extract text then parse

$filePath = 'C:\Users\zenhk\OneDrive\Documents\2022\Ganjil\Soal Pekan Ilmiyah 2022 tanpa kunci.docx';

// Extract directly with simpler approach
$zip = new ZipArchive();
$zip->open($filePath);
$xml = $zip->getFromName('word/document.xml');
$zip->close();

// Use regex instead of simplexml to extract text
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $xml, $matches);
$fullText = '';
if (isset($matches[1])) {
    foreach ($matches[1] as $text) {
        if (trim($text) !== '') {
            $fullText .= trim($text) . "\n";
        }
    }
}

// Parse
function _fixCorrect(array $questions): array {
    foreach ($questions as &$q) {
        $hasCorrect = false;
        foreach ($q['options'] as &$opt) {
            if ($opt['is_correct'] ?? false) {
                $hasCorrect = true;
                break;
            }
        }
        unset($opt);
        if (!$hasCorrect && count($q['options']) > 0) {
            $q['options'][0]['is_correct'] = 1;
        }
    }
    unset($q);
    return $questions;
}

function _parseTextQuestions(string $text): array {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
        $trimmed = trim(preg_replace('/\s+/', ' ', $line));
        if ($trimmed !== '') {
            $lines[] = $trimmed;
        }
    }
    
    if (count($lines) < 6) return [];
    
    $questions = [];
    $i = 0;
    
    while ($i < count($lines)) {
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
                        
                        $correct = 0;
                        if (preg_match('/\*|[\[\(]?(?:benar|correct|✓)[\]\)]?/i', $optText)) {
                            $correct = 1;
                            $optText = trim(preg_replace('/\*|[\[\(]?(?:benar|correct|✓)[\]\)]?/i', '', $optText));
                        }
                        
                        $options[] = [
                            'option_text' => $optText,
                            'is_correct'  => $correct,
                            'label'       => $label,
                        ];
                    }
                    
                    if (!empty($options)) {
                        $questions[] = [
                            'question_text' => $questionText,
                            'explanation'   => '',
                            'options'       => $options,
                        ];
                    }
                }
                
                $i += 5;
                continue;
            }
        }
        
        $i++;
    }
    
    return count($questions) >= 5 ? _fixCorrect($questions) : [];
}

$questions = _parseTextQuestions($fullText);

echo "PARSE RESULT: " . count($questions) . " questions\n\n";

// Try JSON encoding
$json = json_encode([
    'success' => true,
    'total' => count($questions),
    'questions' => array_slice($questions, 0, 3), // First 3 only
], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);

if ($json === false) {
    echo "JSON ENCODE FAILED: " . json_last_error_msg() . "\n";
} else {
    echo "JSON ENCODED SUCCESSFULLY\n";
    echo "First 500 chars:\n";
    echo substr($json, 0, 500) . "\n";
}
?>
