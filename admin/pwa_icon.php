<?php
require dirname(__DIR__) . '/bootstrap.php';

$source = imagecreatefrompng(__DIR__ . '/assets/img/favicon-192.png');
$icon = imagecreatetruecolor(512, 512);
imagealphablending($icon, false);
imagesavealpha($icon, true);
$transparent = imagecolorallocatealpha($icon, 0, 0, 0, 127);
imagefill($icon, 0, 0, $transparent);
imagecopyresampled($icon, $source, 0, 0, 0, 0, 512, 512, imagesx($source), imagesy($source));

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
imagepng($icon);
imagedestroy($icon);
imagedestroy($source);
