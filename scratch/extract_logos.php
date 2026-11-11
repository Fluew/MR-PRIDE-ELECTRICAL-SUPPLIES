<?php

$categories = [
    'hardware' => [
        'source' => 'public/PRODUCT_A.png',
        'brands' => [
            ['name' => 'DeWalt', 'file' => 'dewalt.png', 'rect' => [30, 3, 134, 61]],
            ['name' => 'Makita', 'file' => 'makita.png', 'rect' => [175, 3, 136, 69]],
            ['name' => 'Powerhouse', 'file' => 'powerhouse.png', 'rect' => [320, 0, 139, 72]],
            ['name' => 'Stanley', 'file' => 'stanley.png', 'rect' => [30, 65, 134, 68]],
            ['name' => 'STIHL', 'file' => 'stihl.png', 'rect' => [189, 76, 126, 61]],
            ['name' => 'Yale', 'file' => 'yale.png', 'rect' => [340, 73, 108, 77]],
            ['name' => 'Zekoki', 'file' => 'zekoki.png', 'rect' => [26, 153, 268, 45]],
            ['name' => 'Jr. Kawasaki', 'file' => 'jr-kawasaki.png', 'rect' => [40, 204, 239, 32]],
            ['name' => 'Lotus', 'file' => 'lotus.png', 'rect' => [298, 150, 162, 162]],
            ['name' => 'Yamato', 'file' => 'yamato.png', 'rect' => [62, 249, 200, 45]],
            ['name' => 'Butterfly', 'file' => 'butterfly.png', 'rect' => [10, 284, 241, 54]],
            ['name' => 'Tacoma', 'file' => 'tacoma.png', 'rect' => [18, 350, 185, 53]],
            ['name' => 'Hoyoma', 'file' => 'hoyoma.png', 'rect' => [267, 321, 228, 85]],
            ['name' => 'DCA', 'file' => 'dca.png', 'rect' => [42, 419, 237, 73]],
            ['name' => 'Ingco', 'file' => 'ingco.png', 'rect' => [305, 412, 164, 73]],
        ],
    ],
    'electrical' => [
        'source' => 'public/PRODUCT_B.png',
        'brands' => [
            ['name' => 'Himel', 'file' => 'himel.png', 'rect' => [25, 11, 193, 90]],
            ['name' => 'ROYU', 'file' => 'royu.png', 'rect' => [226, 7, 185, 105]],
            ['name' => 'Panasonic', 'file' => 'panasonic.png', 'rect' => [439, 12, 174, 57]],
            ['name' => 'Phelps Dodge', 'file' => 'phelps-dodge.png', 'rect' => [25, 105, 191, 99]],
            ['name' => 'General Electric', 'file' => 'general-electric.png', 'rect' => [234, 126, 177, 87]],
            ['name' => 'Koten', 'file' => 'koten.png', 'rect' => [439, 89, 173, 98]],
            ['name' => 'Schneider Electric', 'file' => 'schneider-electric.png', 'rect' => [15, 214, 197, 91]],
            ['name' => 'Philflex', 'file' => 'philflex.png', 'rect' => [235, 213, 176, 101]],
            ['name' => 'Duraflex', 'file' => 'duraflex.png', 'rect' => [440, 191, 185, 121]],
            ['name' => 'Hypertech', 'file' => 'hypertech.png', 'rect' => [13, 325, 324, 61]],
            ['name' => 'Pureflex', 'file' => 'pureflex.png', 'rect' => [347, 316, 116, 81]],
            ['name' => 'Paciflex', 'file' => 'paciflex.png', 'rect' => [469, 313, 157, 84]],
        ],
    ],
    'pipes' => [
        'source' => 'public/PRODUCT_C.png',
        'brands' => [
            ['name' => 'Crown Pipes', 'file' => 'crown-pipes.png', 'rect' => [7, 3, 196, 67]],
            ['name' => 'Emerald', 'file' => 'emerald.png', 'rect' => [244, 2, 172, 70]],
            ['name' => 'Neltex', 'file' => 'neltex.png', 'rect' => [454, 16, 182, 45]],
            ['name' => 'Nichi', 'file' => 'nichi.png', 'rect' => [15, 95, 194, 36]],
            ['name' => 'McGill', 'file' => 'mcgill.png', 'rect' => [268, 92, 160, 37]],
            ['name' => 'Dupro', 'file' => 'dupro.png', 'rect' => [495, 94, 159, 37]],
            ['name' => 'Nikon', 'file' => 'nikon.png', 'rect' => [0, 135, 220, 48]],
            ['name' => 'Panasonic', 'file' => 'panasonic.png', 'rect' => [249, 140, 195, 35]],
            ['name' => 'Atlanta Smartube', 'file' => 'smartube.png', 'rect' => [491, 131, 173, 46]],
        ],
    ],
];

foreach ($categories as $catKey => $catData) {
    $dir = "public/images/brands/{$catKey}";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $srcImg = imagecreatefrompng($catData['source']);
    if (!$srcImg) {
        echo "Failed to load {$catData['source']}\n";
        continue;
    }

    foreach ($catData['brands'] as $b) {
        [$x, $y, $w, $h] = $b['rect'];
        $dest = imagecreatetruecolor($w, $h);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
        imagefilledrectangle($dest, 0, 0, $w, $h, $transparent);

        imagecopy($dest, $srcImg, 0, 0, $x, $y, $w, $h);
        $outPath = "{$dir}/{$b['file']}";
        imagepng($dest, $outPath);
        imagedestroy($dest);
        echo "Created {$outPath}\n";
    }

    imagedestroy($srcImg);
}

echo "Done extracting brand logos.\n";
