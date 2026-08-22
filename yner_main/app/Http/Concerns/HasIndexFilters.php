<?php

namespace App\Http\Concerns;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared `search` / `sort` / `direction` handling for the index tables.
 *
 * Every listing screen in the app offers the same three query parameters, so the parsing,
 * the whitelisting and the LIKE-building live here rather than being re-derived (and
 * re-mis-derived) in each controller. The sort key is always matched against a caller-supplied
 * whitelist: a raw column name from the query string never reaches the ORDER BY.
 */
trait HasIndexFilters
{
    /**
     * Resolve the shared filters, falling back to the given defaults.
     *
     * @param  array<string, string|array<int, string>|Builder|Expression>  $sortable  sort key => column(s) to order by
     * @return array{search: ?string, sort: string, direction: string, explicit: bool}
     */
    protected function indexFilters(
        Request $request,
        array $sortable,
        string $defaultSort,
        string $defaultDirection = 'asc',
    ): array {
        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', '');
        $direction = strtolower((string) $request->query('direction', ''));

        return [
            // Capped rather than validated away: an over-long term is a paste accident, not an
            // error worth bouncing the whole page render for.
            'search' => $search === '' ? null : mb_substr($search, 0, 100),
            'sort' => array_key_exists($sort, $sortable) ? $sort : $defaultSort,
            'direction' => in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection,
            // Whether the user actually picked a column. The review queues put pending rows on
            // top by default, and that pre-ordering has to yield the moment someone sorts by
            // something else — otherwise their chosen column looks like it did nothing.
            'explicit' => array_key_exists($sort, $sortable),
        ];
    }

    /**
     * OR-ed `LIKE %term%` across the given columns. A column written `relation.column` is
     * matched through `whereHas` so related records (department name, owner email) are
     * searchable without joining and de-duplicating.
     *
     * @param  array<int, string>  $columns
     */
    protected function applySearch(Builder $query, ?string $term, array $columns): Builder
    {
        if ($term === null || $columns === []) {
            return $query;
        }

        $like = '%'.$term.'%';

        // Nested so the OR group cannot leak past any scoping the caller already applied
        // (an Employee seeing only their own rows must stay only their own rows).
        return $query->where(function (Builder $q) use ($columns, $like) {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);
                    $q->orWhereHas($relation, fn (Builder $related) => $related->where($field, 'like', $like));

                    continue;
                }

                $q->orWhere($column, 'like', $like);
            }
        });
    }

    /**
     * Order by the whitelisted mapping for the resolved sort key. Multi-column values order by
     * each in turn (last name then first name); a Builder value orders by a correlated
     * subquery, which is how a relation's column is sorted on without a join.
     *
     * @param  array{search: ?string, sort: string, direction: string, explicit: bool}  $filters
     * @param  array<string, string|array<int, string>|Builder|Expression>  $sortable
     */
    protected function applySort(Builder $query, array $filters, array $sortable): Builder
    {
        $target = $sortable[$filters['sort']] ?? null;

        if ($target === null) {
            return $query;
        }

        foreach (is_array($target) ? $target : [$target] as $column) {
            $query->orderBy($column, $filters['direction']);
        }

        return $query;
    }
}
