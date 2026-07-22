import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifest = JSON.parse(fs.readFileSync(path.join(packageRoot, 'package.json'), 'utf8'));
const lock = JSON.parse(fs.readFileSync(path.join(packageRoot, 'package-lock.json'), 'utf8'));
const notices = fs.readFileSync(path.join(packageRoot, 'THIRD_PARTY_NOTICES.md'), 'utf8');

const allowedLicenses = new Set([
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'BlueOak-1.0.0',
    'ISC',
    'MIT',
    'MIT-0',
]);
const linkedStatamicPackages = new Set([
    'node_modules/@statamic/cms',
    'vendor/statamic/cms/resources/dist-package',
]);
const errors = [];
const counts = new Map();

if (!manifest.dependencies?.axios) {
    errors.push('Axios is imported by production code but is not a direct dependency.');
}

for (const [packagePath, dependency] of Object.entries(lock.packages ?? {})) {
    if (packagePath === '') {
        continue;
    }

    if (!dependency.license) {
        if (!linkedStatamicPackages.has(packagePath)) {
            errors.push(`${packagePath} does not declare a license.`);
        }

        continue;
    }

    const scope = dependency.dev ? 'development' : 'production';
    const key = `${scope}:${dependency.license}`;
    counts.set(key, (counts.get(key) ?? 0) + 1);

    if (!allowedLicenses.has(dependency.license)) {
        errors.push(`${packagePath} uses unapproved license ${dependency.license}.`);
    }
}

const axiosEntry = lock.packages?.['node_modules/axios'];

if (!axiosEntry?.version) {
    errors.push('Axios is missing from package-lock.json.');
} else {
    if (axiosEntry.license !== 'MIT') {
        errors.push(`Expected Axios to use MIT, found ${axiosEntry.license ?? 'no declared license'}.`);
    }

    if (!notices.includes('Copyright (c) 2014-present Matt Zabriskie & Collaborators')) {
        errors.push('THIRD_PARTY_NOTICES.md is missing the Axios copyright notice.');
    }
}

const cpAssetDirectory = path.join(packageRoot, 'resources', 'dist', 'build', 'assets');
const cpAssets = fs.existsSync(cpAssetDirectory)
    ? fs.readdirSync(cpAssetDirectory).filter((file) => /^cp-.*\.js$/.test(file))
    : [];

if (cpAssets.length === 0) {
    errors.push('No compiled Control Panel JavaScript asset was found. Run npm run build.');
} else {
    for (const asset of cpAssets) {
        const contents = fs.readFileSync(path.join(cpAssetDirectory, asset), 'utf8');

        if (!contents.includes(`Axios v${axiosEntry.version}`) || !contents.includes('Permission is hereby granted')) {
            errors.push(`${asset} does not contain the complete Axios license banner. Run npm run build.`);
        }
    }
}

for (const [key, count] of [...counts.entries()].sort()) {
    console.log(`${key}: ${count}`);
}

if (errors.length > 0) {
    console.error('\nLicense audit failed:');
    errors.forEach((error) => console.error(`- ${error}`));
    process.exit(1);
}

console.log(`\nLicense audit passed for ${Object.keys(lock.packages ?? {}).length - 1} npm package entries.`);

