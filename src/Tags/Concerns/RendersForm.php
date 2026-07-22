<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tags\Concerns;

use DebugBar\DataCollector\ConfigCollector;
use Illuminate\Support\Collection;
use Statamic\Facades\Blink;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Section;
use Statamic\Fields\Tab;
use Statamic\Hooks\Payload;
use Statamic\Tags\Concerns\GetsFormSession;
use Statamic\Tags\Concerns\GetsRedirects;
use Statamic\Tags\Concerns\RendersForms;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Support\ContextSigner;
use Throwable;

trait RendersForm
{
    use GetsFormSession,
        GetsMeerkatConfig,
        GetsRedirects,
        RendersForms;

    /** @return array<string, mixed>|string */
    public function form(): array|string
    {
        $blueprint = $this->getBlueprint();

        $data = $this->stringKeyedFormArray($this->getFormSession('meerkat'));
        $data['sections'] = $this->getSections($blueprint);

        $sectionsPayload = $this->runHooksWith('form-sections', [
            'sections' => $data['sections'],
            'blueprint' => $blueprint,
        ]);

        $sections = $sectionsPayload instanceof Payload ? $sectionsPayload->sections : null;
        $data['sections'] = is_array($sections) ? $sections : [];
        $data['fields'] = collect($data['sections'])
            ->flatMap(fn (mixed $section): array => is_array($section) && is_array($section['fields'] ?? null)
                ? $section['fields']
                : [])
            ->all();

        $fieldsPayload = $this->runHooksWith('form-fields-prepared', [
            'fields' => $data['fields'],
            'sections' => $data['sections'],
            'errors' => $data['errors'] ?? [],
        ]);

        $hookFields = $fieldsPayload instanceof Payload ? $fieldsPayload->fields : null;
        $data['fields'] = is_array($hookFields) ? $hookFields : $data['fields'];
        $data['honeypot'] = config('meerkat.form.honeypot');

        $this->addToDebugBar($data);

        $knownParams = [
            'redirect', 'error_redirect', 'allow_request_redirect', 'csrf', 'files', 'js',
            'thread', 'from_thread', 'meerkat_jump',
        ];

        $action = route('meerkat.comment-create');
        $methodValue = $this->params->get('method', 'POST');
        $method = is_string($methodValue) ? $methodValue : 'POST';

        $attrs = [];

        $attrsPayload = $this->stringKeyedFormArray($this->runHooks('attrs', ['attrs' => $attrs, 'data' => $data]));
        $attrs = is_array($attrsPayload['attrs'] ?? null) ? $attrsPayload['attrs'] : [];

        $params = [];

        if ($redirect = $this->getRedirectUrl()) {
            $params['redirect'] = $this->parseRedirect($redirect);
        }

        if ($errorRedirect = $this->getErrorRedirectUrl()) {
            $params['error_redirect'] = $this->parseRedirect($errorRedirect);
        }

        $params['meerkat_context'] = $this->getThreadId();

        if ($params['meerkat_context'] !== null && $params['meerkat_context'] !== '') {
            $params['meerkat_context_signature'] = ContextSigner::sign((string) $params['meerkat_context']);
        }

        if (! $this->params->has('data-meerkat-form')) {
            $this->params['data-meerkat-form'] = 'comment-form';
        }

        if (! $this->canParseContents()) {
            return array_merge([
                'attrs' => $this->formAttrs($action, $method, $knownParams, $attrs),
                'params' => $this->formMetaPrefix($this->formParams($method, $params)),
            ], $data);
        }

        $html = $this->formOpen($action, $method, $knownParams, $attrs);
        $afterOpen = $this->stringKeyedFormArray($this->runHooks('after-open', ['html' => $html, 'data' => $data]));
        $hookHtml = $afterOpen['html'] ?? null;
        $html = is_string($hookHtml) ? $hookHtml : $html;

        $metaFields = $this->formMetaFields($params);
        $html .= is_string($metaFields) ? $metaFields : '';

        if ($this->params->has('meerkat_jump')) {
            $jump = $this->params->get('meerkat_jump');
            $jump = is_string($jump) || is_int($jump) ? (string) $jump : '';
            $html .= sprintf(
                '<input type="hidden" name="meerkat_jump" value="%s" />',
                e($jump)
            );
        }

        $html .= $this->parse($data);

        $beforeClose = $this->stringKeyedFormArray($this->runHooks('before-close', ['html' => $html, 'data' => $data]));
        $hookHtml = $beforeClose['html'] ?? null;
        $html = is_string($hookHtml) ? $hookHtml : $html;

        return $html.$this->formClose();
    }

    /** @param array<string, mixed> $data */
    protected function addToDebugBar(array $data): void
    {
        if (! function_exists('debugbar') || ! class_exists(ConfigCollector::class)) {
            return;
        }

        $blink = Blink::store();

        if (! is_object($blink) || ! method_exists($blink, 'get') || ! method_exists($blink, 'put')) {
            return;
        }

        $storedDebug = $blink->get('debug_bar_data', []);
        $debug = array_merge(['meerkat' => $data], is_array($storedDebug) ? $storedDebug : []);

        $blink->put('debug_bar_data', $debug);

        try {
            $debugBar = debugbar();

            if (! is_object($debugBar) || ! method_exists($debugBar, 'getCollector') || ! method_exists($debugBar, 'addCollector')) {
                return;
            }

            $collector = $debugBar->getCollector('Forms');

            if ($collector instanceof ConfigCollector) {
                $collector->setData($debug);
            } elseif ($collector === null) {
                $debugBar->addCollector(new ConfigCollector($debug, 'Forms'));
            }
        } catch (Throwable) {
            // Debug tooling must not interrupt form rendering.
        }
    }

    /** @return list<array{display: mixed, instructions: mixed, fields: array<int, mixed>}> */
    protected function getSections(Blueprint $blueprint): array
    {
        $tab = $blueprint->tabs()->first();

        if (! $tab instanceof Tab) {
            return [];
        }

        $sections = $tab->sections();

        if (! $sections instanceof Collection) {
            return [];
        }

        $result = [];

        foreach ($sections as $section) {
            if (! $section instanceof Section) {
                continue;
            }

            $fieldValues = $section->fields()->all();
            $fields = [];

            foreach ($fieldValues as $field) {
                $fields[] = $field;
            }
            $result[] = [
                'display' => $section->display(),
                'instructions' => $section->instructions(),
                'fields' => $this->getFields($fields),
            ];
        }

        return $result;
    }

    /**
     * @param  iterable<array-key, Field>  $fields
     * @return array<int, mixed>
     */
    protected function getFields(iterable $fields): array
    {
        $excludeFields = config('meerkat.fields.exclude', []);

        if (! is_array($excludeFields)) {
            $excludeFields = [];
        }

        return collect($fields)
            ->filter(fn ($field) => ! in_array($field->handle(), $excludeFields, true))
            ->map(fn ($field) => $this->getRenderableField($field, 'meerkat'))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function stringKeyedFormArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
