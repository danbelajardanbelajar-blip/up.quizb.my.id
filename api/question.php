<?php




// ============================================




// api/question.php — Question CRUD (Admin)




// ============================================









function question_list(): void {




    requireAdmin();




    $quizId = (int)($_GET['quiz_id'] ?? 0);




    if (!$quizId) jsonError('Quiz ID diperlukan');









    $questions = DB::all(




        'SELECT id, question_text, type, points, order_num FROM questions WHERE quiz_id = ? ORDER BY order_num',




        [$quizId]




    );









    foreach ($questions as &$q) {




        $q['question_text'] = html_entity_decode($q['question_text'], ENT_QUOTES, 'UTF-8');




        $q['options'] = DB::all(




            'SELECT id, option_text, is_correct, order_num FROM options WHERE question_id = ? ORDER BY order_num',




            [$q['id']]




        );




        foreach ($q['options'] as &$o) {




            $o['option_text'] = html_entity_decode($o['option_text'], ENT_QUOTES, 'UTF-8');




            $o['is_correct']  = (bool)(int)$o['is_correct'];




        }




        unset($o);




    }




    unset($q);









    jsonSuccess($questions);




}














function question_list_all(): void {




    requireAdmin();




    $page   = max(1, (int)($_GET['page']   ?? 1));




    $limit  = 20;




    $offset = ($page - 1) * $limit;




    $search = trim($_GET['search'] ?? '');




    $quizId = (int)($_GET['quiz_id'] ?? 0);









    $conds  = [];




    $params = [];









    if ($search !== '') {




        $conds[]  = 'q.question_text LIKE ?';




        $params[] = '%' . $search . '%';




    }




    if ($quizId > 0) {




        $conds[]  = 'q.quiz_id = ?';




        $params[] = $quizId;




    }









    $whereStr = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';









    $total = (int)(DB::one(




        "SELECT COUNT(*) AS cnt FROM questions q $whereStr",




        $params




    )['cnt'] ?? 0);









    $questions = DB::all(




        "SELECT q.id, q.question_text, q.type, q.points, q.explanation, q.quiz_id,




                qz.title AS quiz_title




         FROM questions q




         JOIN quizzes qz ON q.quiz_id = qz.id




         $whereStr




         ORDER BY qz.title, q.order_num




         LIMIT ? OFFSET ?",




        array_merge($params, [$limit, $offset])




    );









    foreach ($questions as &$q) {




        $q['question_text'] = html_entity_decode($q['question_text'], ENT_QUOTES, 'UTF-8');




        $q['explanation']   = html_entity_decode($q['explanation'] ?? '', ENT_QUOTES, 'UTF-8');




        $q['options'] = DB::all(




            'SELECT id, option_text, is_correct, order_num FROM options WHERE question_id = ? ORDER BY order_num',




            [$q['id']]




        );




        foreach ($q['options'] as &$o) {




            $o['option_text'] = html_entity_decode($o['option_text'], ENT_QUOTES, 'UTF-8');




            $o['is_correct']  = (bool)(int)$o['is_correct'];




        }




        unset($o);




    }




    unset($q);









    jsonSuccess(['questions' => $questions, 'total' => $total, 'page' => $page, 'limit' => $limit]);




}









function question_create(): void {




    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);




    requireAdmin();




    $body = getBody();









    $quizId = (int)($body['quiz_id'] ?? 0);




    $text   = sanitizeString($body['question_text'] ?? '');




    $type   = in_array($body['type'] ?? '', ['multiple','true_false']) ? $body['type'] : 'multiple';




    $points = max(1, min(100, (int)($body['points'] ?? 10)));




    $order  = (int)($body['order_num'] ?? 0);




    $expl   = sanitizeString($body['explanation'] ?? '');




    $options = $body['options'] ?? [];









    if (!$quizId || !$text) jsonError('Quiz ID dan teks soal wajib diisi');




    if (empty($options)) jsonError('Minimal satu pilihan jawaban diperlukan');









    DB::execute(




        'INSERT INTO questions (quiz_id, question_text, type, points, order_num, explanation) VALUES (?,?,?,?,?,?)',




        [$quizId, $text, $type, $points, $order, $expl]




    );




    $qId = (int)DB::lastId();









    foreach ($options as $i => $opt) {




        $optText   = sanitizeString($opt['option_text'] ?? '');




        $isCorrect = (int)(bool)($opt['is_correct'] ?? false);




        if ($optText) {




            DB::execute(




                'INSERT INTO options (question_id, option_text, is_correct, order_num) VALUES (?,?,?,?)',




                [$qId, $optText, $isCorrect, $i + 1]




            );




        }




    }









    // Update quiz question count




    DB::execute(




        'UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM questions WHERE quiz_id = ?) WHERE id = ?',




        [$quizId, $quizId]




    );









    // — Broadcast notifikasi ke semua user aktif kecuali admin yang menambahkan




    $adminUser = getCurrentUser();




    broadcastNewQuestion($quizId, (int)($adminUser['id'] ?? 0));









    jsonSuccess(['id' => $qId], 'Soal berhasil ditambahkan');




}









function question_update(): void {




    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);




    requireAdmin();




    $body = getBody();









    $id     = (int)($body['id'] ?? 0);




    $text   = sanitizeString($body['question_text'] ?? '');




    $type   = in_array($body['type'] ?? '', ['multiple','true_false']) ? $body['type'] : 'multiple';




    $points = max(1, min(100, (int)($body['points'] ?? 10)));




    $order  = (int)($body['order_num'] ?? 0);




    $expl   = sanitizeString($body['explanation'] ?? '');




    $options = $body['options'] ?? [];









    if (!$id || !$text) jsonError('ID dan teks soal wajib diisi');









    DB::execute(




        'UPDATE questions SET question_text=?, type=?, points=?, order_num=?, explanation=? WHERE id=?',




        [$text, $type, $points, $order, $expl, $id]




    );









    // Delete & re-insert options




    DB::execute('DELETE FROM options WHERE question_id = ?', [$id]);




    foreach ($options as $i => $opt) {




        $optText   = sanitizeString($opt['option_text'] ?? '');




        $isCorrect = (int)(bool)($opt['is_correct'] ?? false);




        if ($optText) {




            DB::execute(




                'INSERT INTO options (question_id, option_text, is_correct, order_num) VALUES (?,?,?,?)',




                [$id, $optText, $isCorrect, $i + 1]




            );




        }




    }









    jsonSuccess(null, 'Soal berhasil diperbarui');




}









function question_delete(): void {




    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);




    requireAdmin();




    $body = getBody();




    $id   = (int)($body['id'] ?? 0);




    if (!$id) jsonError('ID diperlukan');









    $q = DB::one('SELECT quiz_id FROM questions WHERE id = ?', [$id]);




    if (!$q) jsonError('Soal tidak ditemukan', 404);









    DB::execute('DELETE FROM questions WHERE id = ?', [$id]);




    DB::execute(




        'UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM questions WHERE quiz_id = ?) WHERE id = ?',




        [$q['quiz_id'], $q['quiz_id']]




    );









    jsonSuccess(null, 'Soal berhasil dihapus');




}














// ============================================




// HELPERS — DOCX / XLSX PARSER




// ============================================









function _findExecutable(string $name): ?string {




    if (!function_exists('shell_exec')) return null;




    $cmd = PHP_OS_FAMILY === 'Windows' ? 'where ' . escapeshellarg($name) : 'command -v ' . escapeshellarg($name);




    $output = shell_exec($cmd . ' 2>NUL');




    if (!$output) return null;




    $path = trim(explode("\n", $output)[0]);




    return $path !== '' ? $path : null;




}









function _runShell(string $cmd): string {




    if (!function_exists('shell_exec')) return '';




    $output = shell_exec($cmd . ' 2>&1');




    return $output === null ? '' : trim($output);




}









function _extractTextFromDoc(string $path): string {




    $path = realpath($path);




    if (!$path) return '';









    $antiword = _findExecutable('antiword');




    if ($antiword) {




        return _runShell(escapeshellarg($antiword) . ' -m UTF-8.txt ' . escapeshellarg($path));




    }









    $catdoc = _findExecutable('catdoc');




    if ($catdoc) {




        return _runShell(escapeshellarg($catdoc) . ' ' . escapeshellarg($path));




    }









    $soffice = _findExecutable('soffice') ?: _findExecutable('libreoffice');




    if ($soffice) {




        $outDir   = sys_get_temp_dir();




        $basename = pathinfo($path, PATHINFO_FILENAME);




        $txtPath  = $outDir . DIRECTORY_SEPARATOR . $basename . '.txt';




        if (file_exists($txtPath)) @unlink($txtPath);




        _runShell(escapeshellarg($soffice) . ' --headless --convert-to txt:Text --outdir ' . escapeshellarg($outDir) . ' ' . escapeshellarg($path));




        if (file_exists($txtPath)) {




            $text = file_get_contents($txtPath);




            @unlink($txtPath);




            return $text ?: '';




        }




    }









    return '';




}









function _extractTextFromPdf(string $path): string {




    $path = realpath($path);




    if (!$path) return '';









    $pdftotext = _findExecutable('pdftotext');




    if ($pdftotext) {




        return _runShell(escapeshellarg($pdftotext) . ' -layout ' . escapeshellarg($path) . ' -');




    }









    $tesseract = _findExecutable('tesseract');




    if ($tesseract) {




        $output = _runShell(escapeshellarg($tesseract) . ' ' . escapeshellarg($path) . ' stdout -l eng');




        if ($output !== '') return $output;




        return _runShell(escapeshellarg($tesseract) . ' ' . escapeshellarg($path) . ' stdout');




    }









    return '';




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











/**



 * Parser utama — bekerja dengan teks multi-baris.



 */



function _parseTextQuestions(string $text): array {



    $rawLines = [];



    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {



        $trimmed = trim(preg_replace('/\s+/', ' ', $line));



        if ($trimmed !== '') {



            $rawLines[] = $trimmed;



        }



    }



    if (count($rawLines) < 6) {



        return _parseTextQuestionsLineByLine($text);



    }



    return _parseParagraphArray($rawLines);



}







/**



 * Core parser — terima array paragraf.



 *



 * STRATEGI 1 (primer):



 *   Baris dimulai "أ. " (alef + titik + spasi) = opsi pertama.



 *   Baris sebelumnya = teks soal.



 *   Kumpulkan opsi berikutnya (ب. ت. ث. ج.) atau (B. C. D. E.)



 *



 * STRATEGI 2 (fallback soal tanpa label):



 *   Setelah baris yang berakhir .... / ? / ؟,



 *   ambil 3-5 baris pendek berikutnya sebagai opsi tanpa label.



 */



function _parseParagraphArray(array $lines): array {
    // Normalize paragraphs so combined option blocks like
    // "A.جاكرتاB.الأسرةC.التعارفD.المدرسةE.التعلم" are split into separate lines.
    $splitOptionParagraph = static function (string $line): array {
        // Detect Arabic or Latin option markers anywhere in the line.
        if (!preg_match_all('/([أا]|ب|ت|ث|ج|[A-Ea-e])\./u', $line, $matches, PREG_OFFSET_CAPTURE)) {
            return [$line];
        }
        if (count($matches[0]) <= 1) {
            return [$line];
        }
        $parts = [];
        $last  = 0;
        foreach ($matches[0] as $match) {
            $pos = $match[1];
            if ($pos > $last) {
                $segment = trim(substr($line, $last, $pos - $last));
                if ($segment !== '') {
                    $parts[] = $segment;
                }
            }
            $last = $pos;
        }
        $lastPart = trim(substr($line, $last));
        if ($lastPart !== '') {
            $parts[] = $lastPart;
        }
        return $parts;
    };

    $normalized = [];
    foreach ($lines as $line) {
        foreach ($splitOptionParagraph($line) as $part) {
            $normalized[] = $part;
        }
    }
    $lines = $normalized;
    $total = count($lines);

    /*
     * Pemetaan huruf Arab / Latin ke indeks opsi (0=A, 1=B, 2=C, 3=D, 4=E):
     *   أ (U+0623) / ا (U+0627) / A/a → A
     *   ب (U+0628) / B/b            → B
     *   ت (U+062A) / C/c            → C
     *   ث (U+062B) / D/d            → D
     *   ج (U+062C) / E/e            → E   ← setelah ini, soal baru dimulai
     */
    $ARAB = ["\u{0623}"=>0,"\u{0627}"=>0,"\u{0628}"=>1,"\u{062A}"=>2,"\u{062B}"=>3,"\u{062C}"=>4,
             'A'=>0,'a'=>0,'B'=>1,'b'=>1,'C'=>2,'c'=>2,'D'=>3,'d'=>3,'E'=>4,'e'=>4];

    // Kembalikan indeks opsi Arab / Latin (0-4) atau -1
    $arabIdx = static function (string $l) use ($ARAB): int {
        if (!preg_match('/^([أابتثجA-Ea-e])\.\s*/u', $l, $m)) return -1;
        return $ARAB[$m[1]] ?? -1;
    };

    // Strip label Arab / Latin dari baris opsi
    $stripArab = static function (string $l): string {
        return trim(preg_replace('/^[أابتثجA-Ea-e]\.\s*/u', '', $l));
    };

    // Apakah baris ini penanda akhir soal (berakhir .... / ? / ؟ / !)
    $isQEnd = static function (string $l): bool {
        return (bool) preg_match('/[.…]{2,}\s*$|[\x{061F}?!]\s*$/u', $l);
    };

    $makeOpt = static function (string $text, int $idx): array {
        $correct = 0;
        if (preg_match('/\*|\b(?:benar|correct)\b/iu', $text)) {
            $correct = 1;
            $text    = trim(preg_replace('/\*|\b(?:benar|correct)\b/iu', '', $text));
        }
        return ['option_text'=>$text,'is_correct'=>$correct,'label'=>chr(65+$idx)];
    };

    /* ──────────────────────────────────────────────────────────
     * PASS 1: Blok opsi berlabel Arab (أ. ب. ت. ث. ج.)
     * FIX: Allow 2-5 options (not just 5)
     * ────────────────────────────────────────────────────────── */
    $pass1 = [];
    $used  = [];

    for ($i = 0; $i < $total; $i++) {
        // Mulai jika baris ini adalah أ. (indeks 0)
        if ($arabIdx($lines[$i]) !== 0) continue;

        // Kumpulkan opsi berurutan: 0,1,2,3,4
        $opts   = [];
        $k      = $i;
        $expect = 0;
        while ($k < $total && $expect <= 4) {
            $idx = $arabIdx($lines[$k]);
            if ($idx !== $expect) break;
            $text = $stripArab($lines[$k]);
            if ($text !== '') $opts[] = $makeOpt($text, $idx);
            $expect++;
            $k++;
        }
        // FIX: Allow 2-5 options instead of enforcing exactly 5
        // Original: if (count($opts) < 2) continue;
        if (count($opts) < 2) continue;  // Minimum 2 options

        // Kumpulkan teks soal dari baris sebelum أ. (max 5 ke belakang)
        $qParts = [];
        for ($j = $i - 1; $j >= max(0, $i - 5); $j--) {
            if (isset($used[$j])) break;
            if ($arabIdx($lines[$j]) >= 0) break; // baris opsi lain
            array_unshift($qParts, $lines[$j]);
        }
        if (empty($qParts)) continue;

        $startIdx = $i - count($qParts);
        $endIdx   = $k - 1;
        $pass1[]  = [
            'question_text' => implode(' ', $qParts),
            'explanation'   => '',
            'options'       => $opts,
            '_s'            => $startIdx,
            '_e'            => $endIdx,
        ];
        for ($r = $startIdx; $r <= $endIdx; $r++) $used[$r] = true;
        $i = $endIdx;
    }

    /* ──────────────────────────────────────────────────────────
     * PASS 2: Soal tanpa label Arab
     * FIX: Use mb_strlen for UTF-8 character count
     * FIX: Better multi-line context collection
     * ────────────────────────────────────────────────────────── */
    $pass2 = [];
    $i = 0;
    while ($i < $total) {
        if (isset($used[$i])) { $i++; continue; }
        $line = $lines[$i];
        if (!$isQEnd($line)) { $i++; continue; }

        // Kumpulkan kandidat opsi
        $cands = [];
        for ($k = $i + 1; $k < $total && count($cands) < 5; $k++) {
            if (isset($used[$k])) break;
            $nl = $lines[$k];
            if ($isQEnd($nl)) break;                              // soal baru
            if ($arabIdx($nl) >= 0) break;                        // label Arab → blok S1
            
            // FIX: Use mb_strlen to count UTF-8 characters, not bytes
            // Original: if (strlen($nl) > 120) break;
            // Arabic: 2-4 bytes per char, so 40 chars ≈ 120 bytes
            // This was rejecting valid 40-char Arabic options!
            if (mb_strlen($nl, 'UTF-8') > 40) break;              // terlalu panjang = wacana
            
            $cands[] = $nl;
        }
        // FIX: Allow 2-5 options (not just 3-5)
        if (count($cands) < 2) { $i++; continue; }

        $opts = array_map(fn($c,$idx)=>$makeOpt($c,$idx), $cands, array_keys($cands));

        // FIX: Improve context collection - don't stop at first ?
        // Collect more context lines for multi-line questions
        $ctx = [];
        $maxCtx = 5;  // Increased from 3 to support longer questions
        for ($j = $i - 1; $j >= max(0, $i - $maxCtx) && !isset($used[$j]); $j--) {
            $ctxLine = $lines[$j];
            // Stop at question boundary or used line, but allow up to 5 lines
            if ($arabIdx($ctxLine) >= 0) break;  // Stop at Arabic marker
            // FIX: Don't stop at intermediate ? - only at start of question
            if ($j < $i - 1 && $isQEnd($ctxLine)) break;  // Stop at previous question end
            array_unshift($ctx, $ctxLine);
        }
        
        $questionText = !empty($ctx)
            ? implode(' ', $ctx) . ' ' . $line
            : $line;

        $endIdx = $i + count($cands);
        $pass2[] = [
            'question_text' => $questionText,
            'explanation'   => '',
            'options'       => $opts,
            '_s'            => $i,
            '_e'            => $endIdx,
        ];
        for ($r = $i; $r <= $endIdx; $r++) $used[$r] = true;
        $i = $endIdx + 1;
    }

    // Gabung dan urutkan
    $all = array_merge($pass1, $pass2);
    usort($all, fn($a,$b) => $a['_s'] <=> $b['_s']);

    $result = array_map(function($q){ unset($q['_s'],$q['_e']); return $q; }, $all);

    /* ──────────────────────────────────────────────────────────
     * FIX #3 CRITICAL: Hybrid fallback for missed questions
     * 
     * Original logic: Only fallback if count($result) < 3
     * Problem: If 42 questions found, remaining 8 are abandoned
     * 
     * New logic: Try fallback on unused paragraphs to catch stragglers
     * ────────────────────────────────────────────────────────── */
    
    // Collect unused paragraphs
    $unusedLines = [];
    for ($i = 0; $i < $total; $i++) {
        if (!isset($used[$i]) && $lines[$i] !== '') {
            $unusedLines[] = $lines[$i];
        }
    }
    
    // If significant questions found but some unused, try fallback on unused
    if (count($result) >= 3 && count($unusedLines) >= 3) {
        $fallbackQuestions = _parseTextQuestionsLineByLine(implode("\n", $unusedLines));
        $result = array_merge($result, $fallbackQuestions);
    } else if (count($result) < 3) {
        // Original: full fallback if too few found
        return _parseTextQuestionsLineByLine(implode("\n", $lines));
    }
    
    return _fixCorrect($result);
}


function _parseTextQuestionsLineByLine(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    $questions = [];
    $cur = null;
    $inOptions = false;

    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));

        if ($line === '') {
            if ($cur && $inOptions) {
                $inOptions = false;
            }
            continue;
        }

        // Check for numbered question
        if (preg_match('/^(?:No\.?\s*)?(\d+)[\).\s:-]+(.+)$/i', $line, $m)) {
            if ($cur && !empty($cur['options'])) {
                $questions[] = $cur;
            }
            $cur = ['question_text' => trim($m[2]), 'explanation' => '', 'options' => []];
            $inOptions = false;
            continue;
        }

        // Check for question ending with punctuation
        if (!$cur && (strpos($line, '....') !== false || strpos($line, '...') !== false || str_ends_with($line, '?') || str_ends_with($line, ':'))) {
            $cur = ['question_text' => $line, 'explanation' => '', 'options' => []];
            $inOptions = true;
            continue;
        }

        // Check for options with letter prefix
        if ($cur && preg_match('/^([A-Ea-e])[\).\s:-]+(.+)$/', $line, $m)) {
            $opt = trim($m[2]);
            $correct = false;
            if (preg_match('/\*|\[?\(?(benar|correct)\)?\]?/i', $opt)) {
                $correct = true;
                $opt = trim(preg_replace('/\*|\[?\(?(benar|correct)\)?\]?/i', '', $opt));
            }
            $cur['options'][] = [
                'option_text' => $opt,
                'is_correct'  => $correct ? 1 : 0,
                'label'       => strtoupper($m[1]),
            ];
            $inOptions = true;
            continue;
        }

        // Options without letter in options-mode
        if ($cur && $inOptions && !preg_match('/^(?:Jawaban|Kunci|Answer|Key|Penjelasan|Pembahasan|Explanation)[\s:-]+/i', $line)) {
            $opt = $line;
            $correct = false;
            if (preg_match('/\*|\[?\(?(benar|correct)\)?\]?/i', $opt)) {
                $correct = true;
                $opt = trim(preg_replace('/\*|\[?\(?(benar|correct)\)?\]?/i', '', $opt));
            }

            // FIX: Use mb_strlen for UTF-8
            if (mb_strlen($opt, 'UTF-8') < 40) {  // Reasonable option length
                $cur['options'][] = [
                    'option_text' => $opt,
                    'is_correct'  => $correct ? 1 : 0,
                    'label'       => chr(65 + count($cur['options'])),
                ];
            }
            continue;
        }

        // Append to question text if in options mode
        if ($cur && !$inOptions) {
            $cur['question_text'] .= ' ' . $line;
        }
    }

    // Add last question
    if ($cur && !empty($cur['options'])) {
        $questions[] = $cur;
    }

    return _fixCorrect($questions);
}





function _parseDocx(string $path): array {




    if (!class_exists('ZipArchive')) return [];




    $zip = new ZipArchive();




    if ($zip->open($path) !== true) return [];




    $xml = $zip->getFromName('word/document.xml');




    $zip->close();




    if (!$xml) return [];









    $doc = simplexml_load_string($xml);




    $doc->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');









    $paragraphs = [];




    foreach ($doc->xpath('//w:p') as $p) {




        $text = '';




        foreach ($p->xpath('.//w:t') as $t) {




            $text .= (string)$t;




        }




        $text = trim(preg_replace('/\s+/', ' ', $text));




        if ($text !== '') {




            $paragraphs[] = $text;




        }




    }









    // Gunakan _parseParagraphArray langsung (lebih akurat dari join ke string)




    $questions = _parseParagraphArray($paragraphs);




    if (!empty($questions)) return $questions;









    // Fallback: join ke string dan parse




    return _parseTextQuestionsLineByLine(implode("\n", $paragraphs));




}









function _parseDoc(string $path): array {




    $text = _extractTextFromDoc($path);




    if (!$text) return [];




    return _parseTextQuestions($text);




}









function _parsePdf(string $path): array {




    $text = _extractTextFromPdf($path);




    if (!$text) return [];




    return _parseTextQuestions($text);




}









function _parseXlsx(string $path): array {




    if (!class_exists('ZipArchive')) return [];




    $zip = new ZipArchive();




    if ($zip->open($path) !== true) return [];




    $ssXml = $zip->getFromName('xl/sharedStrings.xml');




    $wsXml = $zip->getFromName('xl/worksheets/sheet1.xml');




    $zip->close();




    if (!$wsXml) return [];









    $ss = [];




    if ($ssXml) {




        $ssObj = simplexml_load_string($ssXml);




        foreach ($ssObj->si as $si) {




            $text = '';




            if (isset($si->t)) { $text = (string)$si->t; }




            else { foreach ($si->r as $r) if (isset($r->t)) $text .= (string)$r->t; }




            $ss[] = $text;




        }




    }









    $ws   = simplexml_load_string($wsXml);




    $rows = [];




    foreach ($ws->sheetData->row as $row) {




        $rowArr = [];




        foreach ($row->c as $c) {




            preg_match('/([A-Z]+)/', strtoupper((string)$c['r']), $m);




            $colStr = $m[1] ?? 'A';




            $colIdx = 0;




            for ($i = 0; $i < strlen($colStr); $i++) $colIdx = $colIdx * 26 + (ord($colStr[$i]) - 64);




            $colIdx--;




            $type = (string)($c['t'] ?? '');




            $val  = (string)($c->v ?? '');




            $rowArr[$colIdx] = ($type === 's') ? ($ss[(int)$val] ?? '') : $val;




        }




        if (!empty($rowArr)) $rows[] = $rowArr;




    }




    if (empty($rows)) return [];









    return _parseRowsToQuestions($rows, _detectTableHeaderColumns($rows[0]));
}

function _fixCorrect(array $questions): array {




    foreach ($questions as &$q) {




        $has = false;




        foreach ($q['options'] as $o) {




            if ((int)$o['is_correct']) { $has = true; break; }




        }




        // Tandai apakah kunci jawaban terdeteksi dari file




        $q['has_key'] = $has;




        // TIDAK auto-assign opsi pertama — biarkan user memilih lewat UI jika belum ada kunci




    }




    unset($q);




    return $questions;




}

function _detectTableHeaderColumns(array $headerRow): array {
    $map = [
        'question'    => null,
        'options'     => [],
        'correct'     => null,
        'explanation' => null,
        'hasHeader'   => false,
    ];

    foreach ($headerRow as $idx => $value) {
        $key = strtolower(trim((string)$value));
        if ($key === '') continue;

        if (preg_match('/^(?:no|nomor|number)$/i', $key)) {
            $map['hasHeader'] = true;
            continue;
        }

        if (in_array($key, ['soal', 'pertanyaan', 'question', 'question_text', 'text', 'pertanyaan soal', 'question text'], true)) {
            $map['question']  = $idx;
            $map['hasHeader'] = true;
            continue;
        }

        if (preg_match('/^a(?:[\.\)]|\s|$)/i', $key) || preg_match('/^(?:opsi a|pilihan a|option a)$/i', $key)) {
            $map['options'][0] = $idx;
            $map['hasHeader'] = true;
            continue;
        }
        if (preg_match('/^b(?:[\.\)]|\s|$)/i', $key) || preg_match('/^(?:opsi b|pilihan b|option b)$/i', $key)) {
            $map['options'][1] = $idx;
            $map['hasHeader'] = true;
            continue;
        }
        if (preg_match('/^c(?:[\.\)]|\s|$)/i', $key) || preg_match('/^(?:opsi c|pilihan c|option c)$/i', $key)) {
            $map['options'][2] = $idx;
            $map['hasHeader'] = true;
            continue;
        }
        if (preg_match('/^d(?:[\.\)]|\s|$)/i', $key) || preg_match('/^(?:opsi d|pilihan d|option d)$/i', $key)) {
            $map['options'][3] = $idx;
            $map['hasHeader'] = true;
            continue;
        }
        if (preg_match('/^e(?:[\.\)]|\s|$)/i', $key) || preg_match('/^(?:opsi e|pilihan e|option e)$/i', $key)) {
            $map['options'][4] = $idx;
            $map['hasHeader'] = true;
            continue;
        }

        if (preg_match('/^(?:kunci|jawaban|answer|key)$/i', $key)) {
            $map['correct'] = $idx;
            $map['hasHeader'] = true;
            continue;
        }

        if (preg_match('/^(?:penjelasan|pembahasan|explanation|discussion|review)$/i', $key)) {
            $map['explanation'] = $idx;
            $map['hasHeader'] = true;
            continue;
        }
    }

    return $map;
}

/**
 * Ubah array baris mentah (dari CSV atau XLSX) menjadi array soal,
 * dengan menghormati pemetaan kolom dari _detectTableHeaderColumns.
 *
 * Format default (tanpa header yang dikenali):
 *   Kolom 0 = Soal
 *   Kolom 1 = Opsi A
 *   Kolom 2 = Opsi B
 *   Kolom 3 = Opsi C
 *   Kolom 4 = Opsi D
 *   Kolom 5 = Opsi E
 *   Kolom 6 = Kunci (huruf: A/B/C/D/E)
 *   Kolom 7 = Penjelasan
 *
 * Jika header terdeteksi, gunakan indeks kolom dari $headerMap.
 */
function _parseRowsToQuestions(array $rows, array $headerMap): array {
    $letters  = ['A', 'B', 'C', 'D', 'E'];
    $hasHdr   = $headerMap['hasHeader'];
    $start    = $hasHdr ? 1 : 0;

    if ($hasHdr && $headerMap['question'] !== null) {
        // Header terdeteksi: gunakan indeks kolom yang dipetakan
        $colQ    = $headerMap['question'];
        $colOpts = [];
        for ($j = 0; $j < 5; $j++) {
            $colOpts[$j] = $headerMap['options'][$j] ?? null;
        }
        // Isi kolom opsi yang tidak terdeteksi secara berurutan setelah kolom soal
        $nextCol = $colQ + 1;
        for ($j = 0; $j < 5; $j++) {
            if ($colOpts[$j] === null) {
                $colOpts[$j] = $nextCol++;
            }
        }
        $colCorrect = $headerMap['correct'] !== null ? $headerMap['correct'] : $nextCol;
        $colExpl    = $headerMap['explanation'] !== null ? $headerMap['explanation'] : ($colCorrect + 1);
    } else {
        // Tidak ada header yang dikenali — gunakan posisi kolom default
        $colQ       = 0;
        $colOpts    = [1, 2, 3, 4, 5];
        $colCorrect = 6;
        $colExpl    = 7;
    }

    $questions = [];
    for ($i = $start; $i < count($rows); $i++) {
        $r = $rows[$i];

        $q = trim($r[$colQ] ?? '');
        if ($q === '') continue;

        $correctLetter = strtoupper(trim($r[$colCorrect] ?? ''));
        $expl          = trim($r[$colExpl] ?? '');

        $opts = [];
        for ($j = 0; $j < 5; $j++) {
            $colIdx = $colOpts[$j];
            $ot = trim($r[$colIdx] ?? '');
            if ($ot === '') continue;
            $opts[] = [
                'option_text' => $ot,
                'is_correct'  => ($correctLetter === $letters[$j]) ? 1 : 0,
                'label'       => $letters[$j],
            ];
        }

        if (!empty($opts)) {
            $questions[] = ['question_text' => $q, 'explanation' => $expl, 'options' => $opts];
        }
    }

    return _fixCorrect($questions);
}

function _parseCsv(string $path): array {

    // Baca file dan strip UTF-8 BOM
    $raw = file_get_contents($path);
    if (!$raw) return [];
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }

    // Pilih separator terbaik berdasarkan konsistensi jumlah kolom
    $sep = _detectCsvSeparator($raw);

    // Parse baris menggunakan separator terpilih
    $rows = [];
    $tmp = tmpfile();
    fwrite($tmp, $raw);
    rewind($tmp);
    while (($row = fgetcsv($tmp, 0, $sep)) !== false) {
        $rows[] = $row;
    }
    fclose($tmp);

    if (empty($rows)) return [];

    // Gunakan _parseRowsToQuestions yang menghormati pemetaan kolom dari header
    return _parseRowsToQuestions($rows, _detectTableHeaderColumns($rows[0]));
}

/**
 * Tentukan separator CSV (koma atau titik koma) dengan membandingkan
 * konsistensi jumlah kolom yang dihasilkan oleh masing-masing separator.
 *
 * Strategi:
 *  1. Coba parse dengan ',' dan ';'.
 *  2. Hitung distribusi jumlah kolom per baris.
 *  3. Pilih separator yang menghasilkan distribusi paling seragam
 *     (nilai mode tertinggi, dengan bobot ke arah jumlah kolom yang
 *     sesuai format soal: 3–9 kolom).
 */
function _detectCsvSeparator(string $raw): string {
    $candidates = [',', ';'];
    $scores     = [];

    foreach ($candidates as $sep) {
        $tmp = tmpfile();
        fwrite($tmp, $raw);
        rewind($tmp);

        $colCounts = [];
        while (($row = fgetcsv($tmp, 0, $sep)) !== false) {
            $n = count($row);
            // Abaikan baris kosong / baris tunggal kosong
            if ($n === 1 && trim($row[0]) === '') continue;
            $colCounts[] = $n;
        }
        fclose($tmp);

        if (empty($colCounts)) {
            $scores[$sep] = 0;
            continue;
        }

        // Hitung mode (jumlah kolom yang paling sering muncul)
        $freq = array_count_values($colCounts);
        arsort($freq);
        $mode     = (int) array_key_first($freq);
        $modeFreq = $freq[$mode];
        $total    = count($colCounts);

        // Konsistensi: proporsi baris yang sesuai mode
        $consistency = $modeFreq / $total;

        // Bonus kecil jika mode ada di rentang kolom format soal (3–9)
        $rangeBonus = ($mode >= 3 && $mode <= 9) ? 0.05 : 0.0;

        $scores[$sep] = $consistency + $rangeBonus;
    }

    // Pilih separator dengan skor tertinggi; jika seri, pilih koma
    arsort($scores);
    return (string) array_key_first($scores);
}





    fclose($handle);









    // Jika semua baris hanya 1 kolom, kemungkinan separator titik koma




    $allSingle = !empty($rows) && count(array_filter($rows, fn($r) => count($r) > 1)) === 0;




    if ($allSingle) {




        $handle = fopen($path, 'r');




        $bom    = fread($handle, 3);




        if ($bom !== "\xEF\xBB\xBF") rewind($handle);




        $rows = [];




        while (($row = fgetcsv($handle, 0, ';')) !== false) {




            $rows[] = $row;




        }




        fclose($handle);




    }









    if (empty($rows)) return [];









    // Gunakan _parseRowsToQuestions yang menghormati pemetaan kolom dari header
    return _parseRowsToQuestions($rows, _detectTableHeaderColumns($rows[0]));
}









function _quizbPdo(): PDO {




    return new PDO(




        'mysql:host=' . DB_HOST . ';dbname=quic1934_quizb;charset=utf8mb4',




        DB_USER, DB_PASS,




        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]




    );




}









// ============================================




// question_import_file_parse




// ============================================









function question_import_file_parse(): void {




    requireAdmin();




    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);




    if (empty($_FILES['file'])) jsonError('File diperlukan');









    $f   = $_FILES['file'];




    if ($f['error'] !== UPLOAD_ERR_OK) jsonError('Upload gagal (kode: ' . $f['error'] . ')');




    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));









    if ($ext === 'docx') {




        $questions = _parseDocx($f['tmp_name']);




    } elseif ($ext === 'doc') {




        $questions = _parseDoc($f['tmp_name']);




    } elseif (in_array($ext, ['xlsx', 'xls'])) {




        $questions = _parseXlsx($f['tmp_name']);




    } elseif ($ext === 'csv') {




        $questions = _parseCsv($f['tmp_name']);




    } elseif ($ext === 'pdf') {




        $questions = _parsePdf($f['tmp_name']);




    } else {




        jsonError('Format tidak didukung. Gunakan .doc, .docx, .xlsx, .xls, .csv, atau .pdf');




    }









    if (empty($questions)) jsonError('Tidak ada soal yang dapat diparsing. Periksa format file.');




    jsonSuccess(['questions' => $questions, 'count' => count($questions)]);




}









// ============================================




// question_import_save




// ============================================









function question_import_save(): void {




    requireAdmin();




    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);




    $body      = getBody();




    $quizId    = (int)($body['quiz_id'] ?? 0);




    $questions = $body['questions'] ?? [];




    if (!$quizId) jsonError('Quiz ID diperlukan');




    if (empty($questions)) jsonError('Tidak ada soal');









    $maxOrder = (int)(DB::one(




        'SELECT COALESCE(MAX(order_num), 0) AS m FROM questions WHERE quiz_id = ?', [$quizId]




    )['m'] ?? 0);









    $imported = 0;




    foreach ($questions as $q) {




        $text = sanitizeString($q['question_text'] ?? '');




        $expl = sanitizeString($q['explanation']   ?? '');




        $opts = $q['options'] ?? [];




        if (!$text || empty($opts)) continue;




        $maxOrder++;




        DB::execute(




            'INSERT INTO questions (quiz_id, question_text, type, points, order_num, explanation) VALUES (?,?,?,?,?,?)',




            [$quizId, $text, 'multiple', 10, $maxOrder, $expl]




        );




        $qid = (int)DB::lastId();




        foreach ($opts as $i => $o) {




            $ot = sanitizeString($o['option_text'] ?? '');




            $ic = (int)(bool)($o['is_correct'] ?? false);




            if ($ot) DB::execute(




                'INSERT INTO options (question_id, option_text, is_correct, order_num) VALUES (?,?,?,?)',




                [$qid, $ot, $ic, $i + 1]




            );




        }




        $imported++;




    }




    DB::execute(




        'UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM questions WHERE quiz_id = ?) WHERE id = ?',




        [$quizId, $quizId]




    );









    // — Broadcast notifikasi soal baru (hanya jika ada yang berhasil diimpor)




    if ($imported > 0) {




        $adminUser = getCurrentUser();




        broadcastNewQuestion($quizId, (int)($adminUser['id'] ?? 0));




    }









    jsonSuccess(['imported' => $imported], "$imported soal berhasil diimpor");




}









// ============================================




// question_browse_quizb




// ============================================









function question_browse_quizb(): void {




    requireAdmin();




    try {




        $pdo     = _quizbPdo();




        $titleId = (int)($_GET['title_id'] ?? 0);









        if ($titleId) {




            $stmt = $pdo->prepare(




                'SELECT q.id, q.text AS question_text, q.explanation




                 FROM questions q WHERE q.title_id = ? ORDER BY q.id LIMIT 300'




            );




            $stmt->execute([$titleId]);




            $qs = $stmt->fetchAll();




            foreach ($qs as &$q) {




                $os = $pdo->prepare(




                    'SELECT text AS option_text, is_correct FROM choices WHERE question_id = ? ORDER BY id'




                );




                $os->execute([$q['id']]);




                $q['options'] = $os->fetchAll();




            }




            unset($q);




            jsonSuccess(['questions' => $qs, 'count' => count($qs)]);




        } else {




            $themes = $pdo->query(




                'SELECT id, name FROM themes WHERE deleted_at IS NULL ORDER BY sort_order, name'




            )->fetchAll();




            foreach ($themes as &$th) {




                $s = $pdo->prepare(




                    'SELECT id, name FROM subthemes WHERE theme_id = ? AND deleted_at IS NULL ORDER BY name'




                );




                $s->execute([$th['id']]);




                $subs = $s->fetchAll();




                foreach ($subs as &$sub) {




                    $t = $pdo->prepare(




                        'SELECT id, title FROM quiz_titles


