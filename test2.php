<?php
$f = 'C:\\Users\\zenhk\\OneDrive\\Documents\\2024\\Genap\\SOAL\\UAS\\Ushul Fiqh - Kelas X - UAS.docx';
$z=new ZipArchive(); $z->open($f); $x=$z->getFromName('word/document.xml'); 
$d=simplexml_load_string($x); $d->registerXPathNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main'); 
$i = 0;
foreach($d->xpath('//w:p') as $p){ 
    $t=''; foreach($p->xpath('.//w:t') as $tx) $t.=(string)$tx; 
    $t=trim($t); 
    if($t!=='') {
        echo "[$i] TEXT: " . mb_substr($t, 0, 80) . PHP_EOL;
        echo "XML: " . $p->asXML() . PHP_EOL . PHP_EOL;
        $i++;
    }
    if ($i > 10) break;
}
