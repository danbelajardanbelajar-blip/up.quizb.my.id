from pathlib import Path
p = Path('api/question.php')
lines = p.read_text(encoding='utf-8').splitlines()
patterns = [
    "    $cell0 = strtolower(trim($rows[0][0] ?? ''));",
    "    if (in_array($cell0, ['soal', 'pertanyaan', 'question', 'no', 'nomor'])) $start = 1;"
]
for pattern in patterns:
    for i, l in enumerate(lines):
        if l == pattern:
            lines[i] = ''
            break
p.write_text('\n'.join(lines) + '\n', encoding='utf-8')
