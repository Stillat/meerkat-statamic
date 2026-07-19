<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Mirror;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class CommentParser
{
    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    public static function parse(string $contents): array
    {

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace("\r\n", "\n", $contents);

        if (! str_starts_with($contents, "---\n")) {
            throw new RuntimeException('Mirror file is missing the opening `---` frontmatter delimiter.');
        }

        $rest = substr($contents, 4);
        $closingPos = strpos($rest, "\n---");

        if ($closingPos === false) {
            throw new RuntimeException('Mirror file is missing the closing `---` frontmatter delimiter.');
        }

        $yamlBlock = substr($rest, 0, $closingPos);
        $after = substr($rest, $closingPos + 4);

        if ($after !== '' && $after[0] === "\n") {
            $after = substr($after, 1);
        }

        $frontmatter = Yaml::parse($yamlBlock) ?? [];

        if (! is_array($frontmatter)) {
            throw new RuntimeException('Mirror frontmatter did not decode to an associative array.');
        }

        $normalizedFrontmatter = array_filter($frontmatter, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);

        return [
            'frontmatter' => $normalizedFrontmatter,
            'body' => $after,
        ];
    }
}
