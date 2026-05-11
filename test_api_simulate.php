<?php
// Simulate the file import API endpoint

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define stub functions that normally come from includes
function jsonError($msg) {
    http_response_code(400);
    echo json_encode(['error' => $msg], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jsonSuccess($data) {
    http_response_code(200);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Copy the parser functions from api/question.php
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
    
    if (count($lines) < 6) {
        return _parseTextQuestionsLineByLine($text);
    }
    
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
    
    if (count($questions) >= 5) {
        return _fixCorrect($questions);
    }
    
    return _parseTextQuestionsLineByLine($text);
}

function _parseTextQuestionsLineByLine(string $text): array {
    // Fallback implementation - simplified
    return [];
}

// Test with actual file
$filePath = 'C:\Users\zenhk\OneDrive\Documents\2022\Ganjil\Soal Pekan Ilmiyah 2022 tanpa kunci.docx';

if (!file_exists($filePath)) {
    jsonError('File not found');
}

// Extract text
$zip = new ZipArchive();
if ($zip->open($filePath) !== true) {
    jsonError('Cannot open file');
}

$xml = $zip->getFromName('word/document.xml');
$zip->close();

if (!$xml) {
    jsonError('Cannot read document');
}

$libxml_use_internal_errors = libxml_use_internal_errors(true);
$doc = simplexml_load_string($xml);
if (!$doc) {
    $errors = libxml_get_errors();
    jsonError('Invalid XML: ' . (count($errors) > 0 ? $errors[0]->message : 'unknown'));
}
libxml_use_internal_errors($libxml_use_internal_errors);

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

// Parse questions
$questions = _parseTextQuestions($fullText);

echo "=== PARSE RESULT ===\n";
echo "Questions found: " . count($questions) . "\n\n";

// Return as JSON
jsonSuccess([
    'success' => true,
    'message' => count($questions) . ' pertanyaan berhasil diparsing',
    'total' => count($questions),
    'questions' => $questions,
]);
?>
