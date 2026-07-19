<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$installedPath = $packageRoot.'/vendor/composer/installed.json';

if (! is_file($installedPath)) {
    fwrite(STDERR, "Composer dependencies are not installed. Run composer install first.\n");
    exit(1);
}

$installed = json_decode((string) file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR);
$packages = $installed['packages'] ?? $installed;
$permissiveLicenses = [
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'ISC',
    'MIT',
];
$expectedExceptions = [
    'james-heinrich/getid3' => 'A separately installed transitive dependency of Statamic; MPL-2.0 is the selected audit option.',
    'statamic/cms' => 'The separately licensed platform required by Meerkat.',
];
$errors = [];
$counts = [];
$reviewedExceptions = [];

foreach ($packages as $package) {
    $name = $package['name'] ?? '(unknown package)';
    $licenses = $package['license'] ?? [];

    if ($licenses === []) {
        $errors[] = "$name does not declare a license.";

        continue;
    }

    $licenseChoice = implode(' OR ', $licenses);
    $counts[$licenseChoice] = ($counts[$licenseChoice] ?? 0) + 1;

    if (array_intersect($licenses, $permissiveLicenses) !== []) {
        continue;
    }

    if (isset($expectedExceptions[$name])) {
        $reviewedExceptions[$name] = $expectedExceptions[$name].' Declared choices: '.$licenseChoice;

        continue;
    }

    $errors[] = sprintf('%s uses an unapproved license choice: %s.', $name, implode(' OR ', $licenses));
}

ksort($counts);

foreach ($counts as $license => $count) {
    echo "$license: $count\n";
}

if ($reviewedExceptions !== []) {
    echo "\nReviewed exceptions:\n";

    foreach ($reviewedExceptions as $name => $reason) {
        echo "- $name: $reason\n";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\nLicense audit failed:\n");

    foreach ($errors as $error) {
        fwrite(STDERR, "- $error\n");
    }

    exit(1);
}

echo sprintf("\nLicense audit passed for %d installed Composer packages.\n", count($packages));
