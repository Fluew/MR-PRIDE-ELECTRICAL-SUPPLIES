<?php

$dir = 'public/images/brands/safety';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$safetyLogos = [
    '3m.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 80" width="160" height="80">
        <rect width="160" height="80" fill="transparent"/>
        <text x="50%" y="62%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="900" font-size="52" fill="#EE0000" letter-spacing="-2">3M</text>
    </svg>',
    'honeywell.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80" width="240" height="80">
        <rect width="240" height="80" fill="transparent"/>
        <text x="50%" y="58%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="800" font-size="34" fill="#EE3124" letter-spacing="-1">Honeywell</text>
    </svg>',
    'ansell.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80" width="200" height="80">
        <rect width="200" height="80" fill="transparent"/>
        <text x="50%" y="58%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="800" font-size="36" fill="#D32F2F">Ansell</text>
    </svg>',
    'msa.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 80" width="220" height="80">
        <rect width="220" height="80" fill="transparent"/>
        <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="900" font-size="38" fill="#00843D">MSA</text>
        <text x="50%" y="78%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="700" font-size="13" fill="#1b5e20" letter-spacing="3">The Safety Company</text>
    </svg>',
    'deltaplus.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80" width="240" height="80">
        <rect width="240" height="80" fill="transparent"/>
        <text x="50%" y="58%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="900" font-size="30" fill="#002F6C" letter-spacing="1">DELTA PLUS</text>
    </svg>',
    'catu.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80" width="200" height="80">
        <rect width="200" height="80" fill="transparent"/>
        <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="900" font-size="42" fill="#E53935" letter-spacing="2">CATU</text>
        <text x="50%" y="80%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="700" font-size="10" fill="#333" letter-spacing="2">SAFETY</text>
    </svg>',
    'kidde.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80" width="200" height="80">
        <rect width="200" height="80" fill="transparent"/>
        <text x="50%" y="58%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="800" font-size="38" fill="#C62828">Kidde</text>
    </svg>',
];

foreach ($safetyLogos as $file => $svg) {
    file_put_contents("{$dir}/{$file}", $svg);
    echo "Created {$dir}/{$file}\n";
}
