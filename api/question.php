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

function _isQuestionLine(string $line): bool {
    // Baris soal biasanya:
    // 1. Berakhir dengan tanda "..." atau "?" atau "؟" (tanda pertanyaan Arab/Indonesia)
    // 2. Mengandung kata tanya dalam bahasa Indonesia atau Arab
    $line = rtrim($line);

    // Tanda akhir pertanyaan / pilihan soal umum
    if (preg_match('/[.…]{2,}\s*$/', $line))         return true;
    if (str_ends_with($line, '?'))                     return true;
    if (str_ends_with($line, '؟'))                    return true;
    if (str_ends_with($line, ':'))                     return true;

    // Kata tanya Indonesia
    if (preg_match('/\b(apa(?:kah)?|siapa(?:kah)?|bagaimana|dimana|di mana|kemana|mengapa|kapan|berapa|manakah|yang mana|pilih(?:lah)?|tentukan|sebutkan|jelaskan|tuliskan)\b/iu', $line)) return true;

    // Kata tanya Arab common (transliterasi: مَنْ مَا كَيْفَ أَيْنَ مَتَى كَمْ)
    if (preg_match('/[\x{0645}\x{0646}][\x{0020}]/u', $line)) return false; // too broad
    
    return false;
}

function _isOptionLine(string $line): bool {
    // Diawali huruf Latin A-E atau huruf Arab أ ب ت ث ج
    if (preg_match('/^[A-Ea-e][).\s:-]/u', $line)) return true;
    // Huruf Arab sebagai label opsi: أ. ب. ت. ث. ج.
    if (preg_match('/^[أبتثج][).\s]/u', $line))    return true;
    return false;
}

/**
 * Parser utama: bekerja dengan array baris/paragraf.
 * Strategi: soal dideteksi dari baris yang berakhir dengan tanda pertanyaan,
 * kemudian dikumpulkan pilihan jawaban dari baris berikutnya.
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
 * Core parser: terima array paragraf, hasilkan soal.
 */
function _parseParagraphArray(array $lines): array {
    $total     = count($lines);
    $questions = [];
    $cur       = null;      // soal sedang dibangun
    $pendingWacana = [];    // baris wacana/konteks sebelum soal

    $flushCur = function () use (&$cur, &$questions) {
        if ($cur && count($cur['options']) >= 2) {
            $questions[] = $cur;
        }
        $cur = null;
    };

    for ($i = 0; $i < $total; $i++) {
        $line = $lines[$i];

        // ── Pilihan jawaban (jika sedang dalam soal) ──────────────────────
        if ($cur !== null && _isOptionLine($line)) {
            // Strip label (A. / أ. dll)
            $optText = trim(preg_replace('/^[A-Ea-eأبتثج][).\s:-]+/u', '', $line));

            // Tandai kunci jawaban jika ada marker
            $correct = 0;
            if (preg_match('/\*|\[?(?:benar|correct|✓)\]?/iu', $optText)) {
                $correct  = 1;
                $optText  = trim(preg_replace('/\*|\[?(?:benar|correct|✓)\]?/iu', '', $optText));
            }
            if ($optText !== '') {
                $cur['options'][] = [
                    'option_text' => $optText,
                    'is_correct'  => $correct,
                    'label'       => chr(65 + count($cur['options'])),
                ];
            }
            continue;
        }

        // ── Soal baru dimulai (baris berakhir tanda tanya / titik-titik) ──
        if (_isQuestionLine($line)) {
            // Simpan soal sebelumnya jika ada
            $flushCur();

            // Gabungkan wacana sebelum soal sebagai bagian dari question_text
            // Batasi: ambil max 3 baris wacana terdekat (baris panjang)
            $context = '';
            if (!empty($pendingWacana)) {
                // Hanya ambil baris konteks yang panjang (>50 char) — teks wacana
                $ctx = array_filter($pendingWacana, fn($l) => strlen($l) > 50);
                if (!empty($ctx)) {
                    // Ambil 3 baris terakhir saja
                    $ctx = array_slice(array_values($ctx), -3);
                    $context = implode(' ', $ctx) . ' ';
                }
                $pendingWacana = [];
            }

            $cur = [
                'question_text' => $context . $line,
                'explanation'   => '',
                'options'       => [],
            ];
            continue;
        }

        // ── Baris pilihan tanpa label (hanya jika sudah dalam soal & punya ≥1 opsi berlabel) ──
        if ($cur !== null && count($cur['options']) >= 1) {
            // Jika baris ini tampak seperti kelanjutan opsi (pendek, belum ada soal baru)
            $prevOpts = count($cur['options']);
            if ($prevOpts < 5 && strlen($line) <= 120 && !_isQuestionLine($line)) {
                $optText = $line;
                $correct = 0;
                if (preg_match('/\*|\[?(?:benar|correct|✓)\]?/iu', $optText)) {
                    $correct  = 1;
                    $optText  = trim(preg_replace('/\*|\[?(?:benar|correct|✓)\]?/iu', '', $optText));
                }
                if ($optText !== '') {
                    $cur['options'][] = [
                        'option_text' => $optText,
                        'is_correct'  => $correct,
                        'label'       => chr(65 + count($cur['options'])),
                    ];
                }
                continue;
            }
            // Baris ini bukan opsi → soal sudah selesai, simpan
            $flushCur();
        }

        // ── Baris yang bukan soal dan bukan opsi → simpan sebagai wacana ──
        if ($cur === null) {
            $pendingWacana[] = $line;
            // Batasi buffer wacana agar tidak terlalu besar
            if (count($pendingWacana) > 10) {
                array_shift($pendingWacana);
            }
        }
    }

    // Simpan soal terakhir
    $flushCur();

    // Jika terlalu sedikit soal terdeteksi, fallback ke line-by-line
    if (count($questions) < 3) {
        return _parseTextQuestionsLineByLine(implode("\n", []));
    }

    return _fixCorrect($questions);
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
            $cur['options'][] = [
                'option_text' => $opt,
                'is_correct'  => $correct ? 1 : 0,
                'label'       => chr(65 + count($cur['options'])),
            ];
            continue;
        }

        // Append to question text if not in options
        if ($cur && !$inOptions) {
            $cur['question_text'] .= ' ' . $line;
        }
    }

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

    $start = 0;
    $cell0 = strtolower(trim($rows[0][0] ?? ''));
    if (in_array($cell0, ['soal', 'pertanyaan', 'question', 'no', 'nomor'])) $start = 1;

    $questions = [];
    for ($i = $start; $i < count($rows); $i++) {
        $r = $rows[$i];
        $q = trim($r[0] ?? '');
        if (!$q) continue;
        $letters  = ['A', 'B', 'C', 'D', 'E'];
        $correct  = strtoupper(trim($r[5] ?? 'A'));
        $expl     = trim($r[6] ?? '');
        $opts     = [];
        for ($j = 1; $j <= 5; $j++) {
            $ot = trim($r[$j] ?? '');
            if ($ot) $opts[] = ['option_text' => $ot, 'is_correct' => ($correct === $letters[$j-1]) ? 1 : 0];
        }
        if (!empty($opts)) $questions[] = ['question_text' => $q, 'explanation' => $expl, 'options' => $opts];
    }
    return _fixCorrect($questions);
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

function _parseCsv(string $path): array {
    // Deteksi encoding: coba baca sebagai UTF-8, fallback strip BOM
    $handle = fopen($path, 'r');
    if (!$handle) return [];

    // Strip UTF-8 BOM jika ada
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle, 0, ',')) !== false) {
        // Coba separator koma dulu, nanti fallback ke titik koma
        $rows[] = $row;
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

    // Lewati header jika baris pertama berisi kata seperti "soal", "question", dll.
    $start = 0;
    $cell0 = strtolower(trim($rows[0][0] ?? ''));
    if (in_array($cell0, ['soal', 'pertanyaan', 'question', 'no', 'nomor', ''])) $start = 1;

    $questions = [];
    $letters   = ['A', 'B', 'C', 'D', 'E'];
    for ($i = $start; $i < count($rows); $i++) {
        $r = $rows[$i];
        $q = trim($r[0] ?? '');
        if (!$q) continue;

        $correct = strtoupper(trim($r[5] ?? 'A'));
        $expl    = trim($r[6] ?? '');
        $opts    = [];
        for ($j = 1; $j <= 5; $j++) {
            $ot = trim($r[$j] ?? '');
            if ($ot !== '') {
                $opts[] = ['option_text' => $ot, 'is_correct' => ($correct === $letters[$j - 1]) ? 1 : 0];
            }
        }
        if (!empty($opts)) {
            $questions[] = ['question_text' => $q, 'explanation' => $expl, 'options' => $opts];
        }
    }
    return _fixCorrect($questions);
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
                         WHERE subtheme_id = ? AND deleted_at IS NULL ORDER BY title LIMIT 200'
                    );
                    $t->execute([$sub['id']]);
                    $sub['titles'] = $t->fetchAll();
                }
                unset($sub);
                $th['subthemes'] = $subs;
            }
            unset($th);
            jsonSuccess(['themes' => $themes]);
        }
    } catch (Exception $e) {
        jsonError('Koneksi ke QuizB gagal: ' . $e->getMessage(), 500);
    }
}

// ============================================
// question_import_quizb
// ============================================

function question_import_quizb(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body   = getBody();
    $quizId = (int)($body['quiz_id'] ?? 0);
    $qIds   = array_map('intval', $body['question_ids'] ?? []);
    if (!$quizId) jsonError('Quiz ID diperlukan');
    if (empty($qIds)) jsonError('Pilih minimal satu soal');

    try {
        $pdo = _quizbPdo();
        $maxOrder = (int)(DB::one(
            'SELECT COALESCE(MAX(order_num), 0) AS m FROM questions WHERE quiz_id = ?', [$quizId]
        )['m'] ?? 0);

        $imported = 0;
        foreach ($qIds as $qid) {
            $sq = $pdo->prepare('SELECT text, explanation FROM questions WHERE id = ?');
            $sq->execute([$qid]);
            $qRow = $sq->fetch();
            if (!$qRow) continue;

            $sc = $pdo->prepare('SELECT text AS option_text, is_correct FROM choices WHERE question_id = ? ORDER BY id');
            $sc->execute([$qid]);
            $opts = $sc->fetchAll();
            if (empty($opts)) continue;

            $maxOrder++;
            DB::execute(
                'INSERT INTO questions (quiz_id, question_text, type, points, order_num, explanation) VALUES (?,?,?,?,?,?)',
                [$quizId, $qRow['text'], 'multiple', 10, $maxOrder, $qRow['explanation'] ?? '']
            );
            $newQid = (int)DB::lastId();
            foreach ($opts as $i => $o) {
                DB::execute(
                    'INSERT INTO options (question_id, option_text, is_correct, order_num) VALUES (?,?,?,?)',
                    [$newQid, $o['option_text'], (int)$o['is_correct'], $i + 1]
                );
            }
            $imported++;
        }
        DB::execute(
            'UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM questions WHERE quiz_id = ?) WHERE id = ?',
            [$quizId, $quizId]
        );

        // — Broadcast notifikasi soal baru
        if ($imported > 0) {
            $adminUser = getCurrentUser();
            broadcastNewQuestion($quizId, (int)($adminUser['id'] ?? 0));
        }

        jsonSuccess(['imported' => $imported], "$imported soal berhasil diimpor dari QuizB");
    } catch (Exception $e) {
        jsonError('Gagal import dari QuizB: ' . $e->getMessage(), 500);
    }
}
