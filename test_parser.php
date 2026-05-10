<?php
// Standalone test parser

function _parseTextQuestions(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    $questions = [];
    $cur = null;
    $answerHint = null;

    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(?:No\.?\s*)?(\d+)[\).\s:-]+(.+)$/i', $line, $m)) {
            if ($cur && !empty($cur['options'])) {
                if ($answerHint) _applyAnswerHint($cur, $answerHint);
                $questions[] = $cur;
            }
            $cur = ['question_text' => trim($m[2]), 'explanation' => '', 'options' => []];
            $answerHint = null;
            continue;
        }

        if ($cur && preg_match('/^([A-Ea-e])[\).\s:-]+(.+)$/', $line, $m)) {
            $opt = trim($m[2]);
            $correct = false;
            if (preg_match('/\*|[\[\(]?(?:benar|correct)[\]\)]?/i', $opt)) {
                $correct = true;
                $opt = trim(preg_replace('/\*|[\[\(]?(?:benar|correct)[\]\)]?/i', '', $opt));
            }
            $cur['options'][] = [
                'option_text' => $opt,
                'is_correct'  => $correct ? 1 : 0,
                'label'       => strtoupper($m[1]),
            ];
            continue;
        }

        if ($cur && preg_match('/^(?:Jawaban|Kunci|Answer|Key)[:\s-]+([A-Ea-e1-5])\b/i', $line, $m)) {
            $answerHint = strtoupper($m[1]);
            continue;
        }

        if ($cur && preg_match('/^(?:Penjelasan|Pembahasan|Explanation)[:\s-]+(.+)$/i', $line, $m)) {
            $cur['explanation'] = trim($m[1]);
            continue;
        }

        if ($cur) {
            if (empty($cur['options'])) {
                $cur['question_text'] .= ' ' . $line;
            } else {
                $lastIndex = count($cur['options']) - 1;
                if ($lastIndex >= 0) {
                    $cur['options'][$lastIndex]['option_text'] .= ' ' . $line;
                }
            }
        }
    }

    if ($cur && !empty($cur['options'])) {
        if ($answerHint) _applyAnswerHint($cur, $answerHint);
        $questions[] = $cur;
    }

    return _fixCorrect($questions);
}

function _fixCorrect(array $questions): array {
    foreach ($questions as &$q) {
        $has = false;
        foreach ($q['options'] as $o) if ($o['is_correct']) { $has = true; break; }
        if (!$has && !empty($q['options'])) $q['options'][0]['is_correct'] = 1;
    }
    unset($q);
    return $questions;
}

function _applyAnswerHint(array &$question, string $hint): void {
    $hint = strtoupper(trim($hint));
    if ($hint === '') return;
    $hint = $hint[0];
    $mapping = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, '1' => 0, '2' => 1, '3' => 2, '4' => 3, '5' => 4];
    if (!isset($mapping[$hint]) || !isset($question['options'][$mapping[$hint]])) return;
    foreach ($question['options'] as &$opt) {
        $opt['is_correct'] = 0;
    }
    $question['options'][$mapping[$hint]]['is_correct'] = 1;
    unset($opt);
}

// Test parsing
if ($argc < 2) {
    echo "Usage: php test_parser.php <file_path>\n";
    exit(1);
}

$filePath = $argv[1];
if (!file_exists($filePath)) {
    echo "File not found: $filePath\n";
    exit(1);
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$text = '';

if ($ext === 'pdf') {
    // Simulate PDF extraction
    $text = shell_exec("pdftotext -layout \"$filePath\" -");
    if (!$text) $text = shell_exec("tesseract \"$filePath\" stdout -l eng");
} elseif ($ext === 'docx') {
    // Simulate DOCX extraction
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml) {
            $doc = simplexml_load_string($xml);
            $doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach ($doc->xpath('//w:p') as $p) {
                foreach ($p->xpath('.//w:t') as $t) {
                    $text .= (string)$t;
                }
                $text .= "\n";
            }
        }
    }
} elseif ($ext === 'doc') {
    $text = shell_exec("antiword -m UTF-8.txt \"$filePath\"");
    if (!$text) $text = shell_exec("catdoc \"$filePath\"");
} else {
    $text = file_get_contents($filePath);
}

if (!$text) {
    echo "Could not extract text from file.\n";
    exit(1);
}

echo "Extracted text:\n$text\n\n";

$questions = _parseTextQuestions($text);
echo "Parsed questions: " . count($questions) . "\n";
foreach ($questions as $i => $q) {
    echo "Question " . ($i+1) . ": " . substr($q['question_text'], 0, 100) . "...\n";
    echo "Options: " . count($q['options']) . "\n";
    foreach ($q['options'] as $opt) {
        echo "  " . $opt['label'] . ": " . substr($opt['option_text'], 0, 50) . " (" . ($opt['is_correct'] ? 'correct' : 'wrong') . ")\n";
    }
    echo "\n";
}
?>