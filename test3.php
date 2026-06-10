<?php
$f = 'C:\\Users\\zenhk\\OneDrive\\Documents\\2024\\Genap\\SOAL\\UAS\\Ushul Fiqh - Kelas X - UAS.docx';
$z=new ZipArchive(); $z->open($f); $x=$z->getFromName('word/document.xml'); 
$d=simplexml_load_string($x); $d->registerXPathNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main'); 
$highlightCount = 0;
foreach($d->xpath('//w:p') as $p){ 
    $t=''; foreach($p->xpath('.//w:t') as $tx) $t.=(string)$tx; 
    $t=trim($t); 
    if($t!=='') {
        $hasHighlight = !empty($p->xpath('.//w:highlight[@w:val="yellow"]') ?: $p->xpath('.//w:highlight[@w:val="green"]'));
        if ($hasHighlight) {
            $highlightCount++;
            echo "KEY: $t" . PHP_EOL;
        }
    }
}
echo "Total highlighted items: $highlightCount\n";
