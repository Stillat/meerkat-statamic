<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Exporters;

use Illuminate\Support\Arr;
use League\Csv\Writer;
use SplTempFileObject;
use Statamic\Fields\Field;

class CsvExporter extends Exporter
{
    private const COLUMNS = [
        'id',
        'thread_id',
        'parent_id',
        'depth',
        'comment_text',
        'author_id',
        'author_name',
        'author_email',
        'is_published',
        'is_spam',
        'is_ham',
        'moderation_status',
        'moderation_reason',
        'moderation_notes',
        'moderated_at',
        'moderated_by',
        'site',
        'collection',
        'created_at',
        'updated_at',
    ];

    public function export(): string
    {
        $writer = Writer::createFromFileObject(new SplTempFileObject);
        $delimiter = Arr::get($this->config, 'delimiter', config('statamic.forms.csv_delimiter', ','));
        $writer->setDelimiter(is_string($delimiter) && $delimiter !== '' ? $delimiter : ',');

        $useDisplay = Arr::get($this->config, 'headers', config('statamic.forms.csv_headers', 'handle')) === 'display';

        $extraDataKeys = $this->commentDataColumns();

        $columns = array_merge(self::COLUMNS, $extraDataKeys);

        $writer->insertOne(array_map(
            fn ($column) => $useDisplay ? $this->displayLabel($column, in_array($column, $extraDataKeys, true)) : $column,
            $columns,
        ));

        foreach ($this->comments as $comment) {
            $writer->insertOne($this->row($comment->toExportArray(), $columns, $extraDataKeys));
        }

        return (string) $writer;
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv';
    }

    /** @return list<string> */
    private function commentDataColumns(): array
    {
        $handles = [];

        foreach ($this->getBlueprint()->fields()->all()->keys() as $handle) {
            if (is_string($handle) && ! in_array($handle, self::COLUMNS, true)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * @param  array<string, mixed>  $export
     * @param  list<string>  $columns
     * @param  list<string>  $extraDataKeys
     * @return list<string>
     */
    private function row(array $export, array $columns, array $extraDataKeys): array
    {
        $data = $export['comment_data'] ?? [];
        $data = is_array($data) ? $data : [];

        $row = [];

        foreach ($columns as $column) {
            if (in_array($column, $extraDataKeys, true)) {
                $row[] = $this->stringify($data[$column] ?? null);
            } else {
                $row[] = $this->stringify($export[$column] ?? null);
            }
        }

        return $row;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return $this->neutralizeFormula(
                implode(', ', array_map($this->stringify(...), $value))
            );
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return $this->neutralizeFormula((string) $value);
        }

        $encoded = json_encode($value);

        return $this->neutralizeFormula(is_string($encoded) ? $encoded : '');
    }

    private function neutralizeFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function displayLabel(string $handle, bool $isCustomField): string
    {
        if ($isCustomField) {
            $field = $this->getBlueprint()->field($handle);

            if (! $field instanceof Field) {
                return $handle;
            }

            $display = $field->display();

            return is_string($display) && $display !== '' ? $display : $handle;
        }

        return match ($handle) {
            'created_at' => __('meerkat::fields.date'),
            'updated_at' => __('meerkat::fields.updated'),
            default => __(str_replace('_', ' ', ucfirst($handle))),
        };
    }
}
