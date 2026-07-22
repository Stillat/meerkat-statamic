<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Statamic\Contracts\Query\Builder;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Facades\Blink;
use Statamic\Support\Arr;

/**
 * @template TModel of Model
 *
 * @method $this whereRaw(string $expression, array<int, mixed> $bindings = [], string $boolean = 'and')
 * @method $this forPage(int $page, int $perPage = 15)
 *
 * @mixin EloquentBuilder<TModel>
 */
abstract class BaseQueryBuilder implements Builder
{
    /** @var EloquentBuilder<TModel> */
    protected $builder;

    /** @var array<int, string>|string|null */
    protected $columns;

    /** @var array<string, string> */
    protected $operators = [
        '=' => 'Equals',
        '<>' => 'NotEquals',
        '!=' => 'NotEquals',
        'like' => 'Like',
        'not like' => 'NotLike',
        'regexp' => 'LikeRegex',
        'not regexp' => 'NotLikeRegex',
        '>' => 'GreaterThan',
        '<' => 'LessThan',
        '>=' => 'GreaterThanOrEqualTo',
        '<=' => 'LessThanOrEqualTo',
    ];

    /** @param EloquentBuilder<TModel> $builder */
    public function __construct(EloquentBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $response = $this->builder->$method(...$args);

        return $response instanceof EloquentBuilder ? $this : $response;
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $this->columns = $columns;

        $this->builder->select($columns);

        return $this;
    }

    /**
     * @param  string  $expression
     * @param  array<int, mixed>  $bindings
     * @return $this
     */
    public function selectRaw($expression, array $bindings = [])
    {
        $this->builder->selectRaw($expression, $bindings);

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return EloquentCollection<int, TModel>
     */
    public function get($columns = ['*'])
    {
        $columns = $this->columns ?? $columns;

        $items = $this->builder->get($this->selectableColumns($columns));

        if (($first = $items->first()) && method_exists($first, 'selectedQueryColumns')) {
            $items->each(function (Model $item) use ($columns): void {
                if (method_exists($item, 'selectedQueryColumns')) {
                    $item->selectedQueryColumns($columns);
                }
            });
        }

        return $this->modelCollection($items->all());
    }

    /** @return TModel|null */
    public function first()
    {
        return $this->get()->first();
    }

    /**
     * @param  int|null  $perPage
     * @param  array<int, string>|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $paginator = $this->builder->paginate($perPage, $this->selectableColumns($columns), $pageName, $page);

        return app()->makeWith(LengthAwarePaginator::class, [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'perPage' => $paginator->perPage(),
            'currentPage' => $paginator->currentPage(),
            'options' => $paginator->getOptions(),
        ]);
    }

    /** @return int */
    public function getCountForPagination()
    {
        return $this->builder->toBase()->getCountForPagination();
    }

    /**
     * @param  array<int, Model>  $models
     * @return EloquentCollection<int, TModel>
     */
    abstract protected function modelCollection(array $models): EloquentCollection;

    /**
     * @param  string|Expression|Closure|array<int|string, mixed>  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        if ($column instanceof Closure && is_null($operator)) {
            return $this->whereNested($column, $boolean);
        }

        if (is_string($operator) && strtolower($operator) === 'like') {
            $grammar = $this->builder->getQuery()->getGrammar();
            $this->builder->whereRaw(
                'LOWER('.$grammar->wrap($this->column($column)).') LIKE ?',
                $this->lowercaseValue($value),
                $boolean
            );

            return $this;
        }

        $this->builder->where($this->column($column), $operator, $value, $boolean);

        return $this;
    }

    /**
     * @param  string|Expression|Closure|array<int|string, mixed>  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereColumn($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $this->builder->whereColumn(
            $this->column($column),
            $this->nullableString($operator, 'operator'),
            $this->stringColumn($value),
            $boolean
        );

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>|Closure  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and')
    {
        if (is_array($values) && count($values) === 0) {

            $this->builder->whereRaw('1 = 0', [], $boolean);

            return $this;
        }

        $this->builder->whereIn($this->column($column), $values, $boolean);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>|Closure  $values
     * @return $this
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>|Closure  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        $this->builder->whereNotIn($this->column($column), $values, $boolean);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>|Closure  $values
     * @return $this
     */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereJsonContains($column, $values, $boolean = 'and')
    {
        $this->builder->whereJsonContains($this->stringColumn($column), $values, $boolean);

        return $this;
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @return $this
     */
    public function orWhereJsonContains($column, $values)
    {
        return $this->whereJsonContains($column, $values, 'or');
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereJsonDoesntContain($column, $values, $boolean = 'and')
    {
        $this->builder->whereJsonDoesntContain($this->stringColumn($column), $values, $boolean);

        return $this;
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @return $this
     */
    public function orWhereJsonDoesntContain($column, $values)
    {
        return $this->whereJsonDoesntContain($column, $values, 'or');
    }

    /**
     * @param  string  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereJsonLength($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $this->builder->whereJsonLength($this->stringColumn($column), $operator, $value, $boolean);

        return $this;
    }

    /**
     * @param  string  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhereJsonLength($column, $operator, $value = null)
    {
        return $this->whereJsonLength($column, $operator, $value, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $this->builder->whereNull($this->column($column), $boolean, $not);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @return $this
     */
    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * @param  string|Expression  $column
     * @return $this
     */
    public function orWhereNotNull($column)
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereBetween($column, $values, $boolean = 'and', $not = false)
    {
        $this->builder->whereBetween($this->column($column), $values, $boolean, $not);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>  $values
     * @return $this
     */
    public function orWhereBetween($column, $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotBetween($column, $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * @param  string|Expression  $column
     * @param  iterable<mixed>  $values
     * @return $this
     */
    public function orWhereNotBetween($column, $values)
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    /**
     * @param  mixed  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $value = $this->dateValue($value);

        $this->builder->whereDate(
            $this->column($column),
            $this->nullableString($operator, 'operator'),
            $value,
            $boolean
        );

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhereDate($column, $operator, $value = null)
    {
        return $this->whereDate($column, $operator, $value, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereMonth($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $this->builder->whereMonth(
            $this->column($column),
            $this->nullableString($operator, 'operator'),
            $this->datePartValue($value),
            $boolean
        );

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhereMonth($column, $operator, $value = null)
    {
        return $this->whereMonth($column, $operator, $value, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereDay($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $this->builder->whereDay(
            $this->column($column),
            $this->nullableString($operator, 'operator'),
            $this->datePartValue($value),
            $boolean
        );

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhereDay($column, $operator, $value = null)
    {
        return $this->whereDay($column, $operator, $value, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereYear($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $this->builder->whereYear(
            $this->column($column),
            $this->nullableString($operator, 'operator'),
            $this->datePartValue($value),
            $boolean
        );

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhereYear($column, $operator, $value = null)
    {
        return $this->whereYear($column, $operator, $value, 'or');
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function whereTime($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $this->builder->whereTime(
            $this->column($column),
            $this->nullableString($operator, 'operator'),
            $this->dateValue($value),
            $boolean
        );

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function orWhereTime($column, $operator, $value = null)
    {
        return $this->whereTime($column, $operator, $value, 'or');
    }

    /**
     * @param  Closure(static): mixed  $callback
     * @param  string  $boolean
     * @return $this
     */
    public function whereNested(Closure $callback, $boolean = 'and')
    {
        $query = app(static::class);

        if (! $query instanceof static) {
            throw new \LogicException('The container returned an invalid nested query builder.');
        }

        $callback($query);

        $this->builder->getQuery()->addNestedWhereQuery($query->builder->getQuery(), $boolean);

        return $this;
    }

    /**
     * @param  array<int|string, mixed>  $column
     * @param  string  $boolean
     * @param  string  $method
     * @return $this
     */
    protected function addArrayOfWheres($column, $boolean, $method = 'where')
    {
        $this->whereNested(function ($query) use ($column, $method, $boolean) {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $query->{$method}(...array_values($value));
                } else {
                    $query->$method($this->column($key), '=', $value, $boolean);
                }
            }
        }, $boolean);

        return $this;
    }

    /**
     * @param  mixed  $value
     * @param  callable($this, mixed): mixed  $callback
     * @param  (callable($this, mixed): mixed)|null  $default
     * @return mixed
     */
    public function when($value, $callback, $default = null)
    {
        if ($value) {
            return $callback($this, $value) ?: $this;
        }

        if ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    /**
     * @param  callable($this, mixed): mixed  $callback
     * @return mixed
     */
    public function tap($callback)
    {
        return $this->when(true, $callback);
    }

    /**
     * @param  mixed  $value
     * @param  callable($this, mixed): mixed  $callback
     * @param  (callable($this, mixed): mixed)|null  $default
     * @return mixed
     */
    public function unless($value, $callback, $default = null)
    {
        if (! $value) {
            return $callback($this, $value) ?: $this;
        }

        if ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->builder->orderBy($this->column($column), $direction);

        return $this;
    }

    /**
     * @param  string|Expression  $column
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'desc');
    }

    protected function column(mixed $column): string|Expression
    {
        if (is_string($column) || $column instanceof Expression) {
            return $column;
        }

        throw new InvalidArgumentException('Query columns must be strings or database expressions.');
    }

    protected function stringColumn(mixed $column): string
    {
        $resolved = $this->column($column);

        if (is_string($resolved)) {
            return $resolved;
        }

        throw new InvalidArgumentException('This query requires a string column name.');
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return array<int, string>
     */
    protected function selectableColumns($columns = ['*'])
    {
        $columns = Arr::wrap($columns);
        $selected = ['*'];

        if (! in_array('*', $columns, true)) {
            $model = $this->builder->getModel();
            $table = $model->getTable();

            $schemaValue = Blink::once("eloquent-schema-{$table}", fn () => $model->getConnection()->getSchemaBuilder()->getColumnListing($table));
            $schema = is_array($schemaValue)
                ? array_values(array_filter($schemaValue, is_string(...)))
                : [];

            $selected = array_intersect($schema, $columns);
        }

        return $selected === [] ? ['*'] : $selected;
    }

    protected function lowercaseValue(mixed $value): string
    {
        if (is_string($value)) {
            return strtolower($value);
        }

        if (is_int($value) || is_float($value)) {
            return strtolower((string) $value);
        }

        throw new InvalidArgumentException('Like query values must be strings or numbers.');
    }

    protected function nullableString(mixed $value, string $description): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException("Query {$description} must be a string or null.");
    }

    private function dateValue(mixed $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return Carbon::parse($value);
        }

        throw new InvalidArgumentException('Query date values must be dates, strings, or numbers.');
    }

    private function datePartValue(mixed $value): DateTimeInterface|int|string|null
    {
        if ($value === null || $value instanceof DateTimeInterface || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Query date parts must be dates, strings, integers, or null.');
    }

    /**
     * @param  mixed  $value
     * @param  mixed  $operator
     * @param  bool  $useDefault
     * @return array{mixed, mixed}
     */
    public function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];
        }

        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    protected function invalidOperatorAndValue($operator, $value): bool
    {
        return is_null($value) && in_array($operator, array_keys($this->operators)) &&
            ! in_array($operator, ['=', '<>', '!=']);
    }

    /**
     * @param  int  $count
     * @param  callable(EloquentCollection<int, TModel>, int): mixed  $callback
     */
    public function chunk($count, callable $callback): bool
    {
        $page = 1;

        do {
            $results = $this->forPage($page, $count)->get();

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * @param  int  $chunkSize
     * @return LazyCollection<int, TModel>
     */
    public function lazy($chunkSize = 1000)
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        return LazyCollection::make(function () use ($chunkSize) {
            $page = 1;

            while (true) {
                $results = $this->forPage($page++, $chunkSize)->get();

                foreach ($results as $result) {
                    yield $result;
                }

                if ($results->count() < $chunkSize) {
                    return;
                }
            }
        });
    }
}
