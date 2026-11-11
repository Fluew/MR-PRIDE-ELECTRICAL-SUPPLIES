import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import { defineConfig, type Plugin } from 'vite';

// Writes a .gz next to each built script/stylesheet. public/.htaccess serves it to browsers that
// accept gzip, so assets arrive compressed even where Apache's mod_deflate is not enabled.
function precompressAssets(): Plugin {
    let assetsDir = '';

    return {
        name: 'precompress-assets',
        apply: 'build',
        configResolved(config) {
            assetsDir = config.build.ssr ? '' : path.resolve(config.root, config.build.outDir, config.build.assetsDir);
        },
        closeBundle() {
            if (!assetsDir) return;
            for (const file of readdirSync(assetsDir)) {
                if (!/\.(js|css|svg)$/.test(file)) continue;
                const full = path.join(assetsDir, file);
                const source = readFileSync(full);
                if (source.length >= 1024) writeFileSync(`${full}.gz`, gzipSync(source, { level: 9 }));
            }
        },
    };
}

function getLocalIp(): string {
    const interfaces = os.networkInterfaces();
    for (const name of Object.keys(interfaces)) {
        for (const iface of interfaces[name] || []) {
            if (
                iface.family === 'IPv4' &&
                !iface.internal &&
                !iface.address.startsWith('169.254') &&
                !iface.address.startsWith('192.168.137')
            ) {
                return iface.address;
            }
        }
    }
    return 'localhost';
}

const localIp = getLocalIp();

export default defineConfig({
    server: {
        host: '0.0.0.0',
        cors: true,
        origin: `http://${localIp}:5173`,
        hmr: {
            host: localIp,
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        precompressAssets(),
    ],
});
