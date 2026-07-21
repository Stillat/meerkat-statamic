<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Exporters;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Database\Models\Comment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

abstract class Exporter
{
    use GetsMeerkatConfig;

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var iterable<int, Comment> */
    protected iterable $comments = [];

    abstract public function export(): string;

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /** @param iterable<int, Comment> $comments */
    public function setComments(iterable $comments): static
    {
        $this->comments = $comments;

        return $this;
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function extension(): string
    {
        return 'txt';
    }

    public function response(): Response
    {
        return response($this->export())->header('Content-Type', $this->contentType());
    }

    public function download(): BinaryFileResponse
    {
        $content = $this->export();

        $path = storage_path('statamic/tmp/meerkat/Comments-'.time().'.'.$this->extension());
        File::ensureDirectoryExists(dirname($path));

        File::put($path, $content);

        return response()
            ->download($path, headers: ['Content-Type' => $this->contentType()])
            ->deleteFileAfterSend();
    }
}
