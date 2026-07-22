import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const axiosPackagePath = require.resolve('axios/package.json');
const axiosPackage = JSON.parse(fs.readFileSync(axiosPackagePath, 'utf8'));
const axiosLicense = fs.readFileSync(path.join(path.dirname(axiosPackagePath), 'LICENSE'), 'utf8').trim();
const thirdPartyBanner = `/*!
 * Axios v${axiosPackage.version}
 *
${axiosLicense.split('\n').map((line) => ` * ${line}`.trimEnd()).join('\n')}
 */`;

export default defineConfig({
    build: {
        rollupOptions: {
            output: {
                banner: thirdPartyBanner,
            },
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/js/cp.js',
                'resources/css/cp.css',
            ],
            publicDirectory: 'resources/dist',
        }),
        statamic(),
    ],
});
