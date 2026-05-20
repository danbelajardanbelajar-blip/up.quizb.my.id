from pathlib import Path

p = Path('api/question.php')
lines = p.read_text(encoding='utf-8').splitlines()

# Update parseXlsx header detection
parse_xlsx = next(i for i,l in enumerate(lines) if l.strip().startswith('function _parseXlsx'))
idx = next(i for i in range(parse_xlsx, len(lines)) if lines[i] == '    $start = 0;')
lines[idx:idx+1] = [
    '    $headerMap = _detectTableHeaderColumns($rows[0]);',
    '    $start     = $headerMap[\'hasHeader\'] ? 1 : 0;'
]

# Update parseCsv header detection
parse_csv = next(i for i,l in enumerate(lines) if l.strip().startswith('function _parseCsv'))
idx = next(i for i in range(parse_csv, len(lines)) if lines[i] == '    $start = 0;')
lines[idx:idx+1] = [
    '    $headerMap = _detectTableHeaderColumns($rows[0]);',
    '    $start     = $headerMap[\'hasHeader\'] ? 1 : 0;'
]
for pattern in [
    '    $cell0 = strtolower(trim($rows[0][0] ?? ""));',
    "    if (in_array($cell0, ['soal', 'pertanyaan', 'question', 'no', 'nomor', ''])) $start = 1;"
]:
    for i,l in enumerate(lines):
        if l == pattern:
            lines[i] = ''
            break

p.write_text('\n'.join(lines) + '\n', encoding='utf-8')
