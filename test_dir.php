<?php
$dir = __DIR__ . '/uploads/snapshots';
echo "DIR: $dir\n";
if (is_dir($dir)) {
    echo "EXISTS\n";
    $files = scandir($dir);
    foreach($files as $f) {
        if ($f != '.' && $f != '..') {
            echo "$f - " . filesize($dir . '/' . $f) . " bytes\n";
        }
    }
} else {
    echo "NOT FOUND\n";
}
