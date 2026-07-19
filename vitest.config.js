import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: false,
        setupFiles: ['resources/js/tests/setup.js'],
        include: ['resources/js/**/*.test.js'],
    },
    resolve: {
        alias: {
            '@statamic/cms/ui': path.resolve(__dirname, 'resources/js/tests/__mocks__/cms-ui.js'),
            '@statamic/cms/save-pipeline': path.resolve(__dirname, 'resources/js/tests/__mocks__/save-pipeline.js'),
            '@statamic/cms': path.resolve(__dirname, 'resources/js/tests/__mocks__/cms.js'),
        },
    },
});
