import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
    build: {
        lib: {
            entry: 'resources/js/replies.js',
            name: 'MeerkatReplies',
            fileName: () => 'replies.js',
            formats: ['iife'],
        },
        outDir: 'resources/dist/',
        emptyOutDir: false,
    },
});