<?php namespace BookStack\Entities;

use BookStack\Auth\Permissions\PermissionService;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SearchService
{
    /**
     * @var SearchTerm
     */
    protected $searchTerm;

    /**
     * @var EntityProvider
     */
    protected $entityProvider;

    /**
     * @var Connection
     */
    protected $db;

    /**
     * @var PermissionService
     */
    protected $permissionService;


    /**
     * Acceptable operators to be used in a query
     * @var array
     */
    protected $queryOperators = ['<=', '>=', '=', '<', '>', 'like', '!='];

    private $searchOptions = null;

    /**
     * SearchService constructor.
     */
    public function __construct(SearchTerm $searchTerm, EntityProvider $entityProvider, Connection $db, PermissionService $permissionService)
    {
        $this->searchTerm = $searchTerm;
        $this->entityProvider = $entityProvider;
        $this->db = $db;
        $this->permissionService = $permissionService;
    }

    /**
     * Set the database connection
     */
    public function setConnection(Connection $connection)
    {
        $this->db = $connection;
    }

    /**
     * Search all entities in the system.
     * The provided count is for each entity to search,
     * Total returned could can be larger and not guaranteed.
     */
    public function searchEntities(string $whichEntityTypeToSearch = 'all', string $action = 'view')
    {
        $searchOptions = (new SearchOptions)->fromRequest(request(), $whichEntityTypeToSearch);

        $results = collect();
        foreach ($searchOptions->getEntities() as $entityType) {
            $search = $this->buildEntitySearchQuery($searchOptions, $entityType, $action)->get();
            $results = $results->merge($search);
        }

        return $results->sortByDesc('score')->values();
    }


    /**
     * Search a book for entities
     */
    public function searchBook(int $bookId): Collection
    {
        $searchOptions = (new SearchOptions)->fromRequest(request(), ['page', 'chapter']);

        $results = collect();
        foreach ($searchOptions->getEntities() as $entityType) {
            $search = $this->buildEntitySearchQuery($searchOptions, $entityType)
                           ->where('book_id', '=', $bookId)
                           ->get();
            $results = $results->merge($search);
        }

        return $results->sortByDesc('score')->values();
    }

    /**
     * Search a book for entities
     */
    public function searchChapter(int $chapterId): Collection
    {
        $searchOptions = (new SearchOptions)->fromRequest(request(), ['page']);

        $results = collect();
        foreach ($searchOptions->getEntities() as $entityType) {
            $search = $this->buildEntitySearchQuery($searchOptions, $entityType)
                      ->where('chapter_id', '=', $chapterId)
                      ->get();
            $results = $results->merge($search);
        }

        return $results->sortByDesc('score')->values();
    }

    /**
     * Create a search query for an entity
     */
    protected function buildEntitySearchQuery(SearchOptions $searchOptions,
                                              string $entityType = 'page',
                                              string $action = 'view'): EloquentBuilder
    {
        $entity = $this->entityProvider->get($entityType);
        $entitySelect = $entity->newQuery();

        // Handle normal search terms
        if (count($searchOptions->searches) > 0) {
            $subQuery = \DB::table('search_terms')->select('entity_id', 'entity_type', \DB::raw('SUM(score) as score'));
            $subQuery->where('entity_type', '=', $entity->getMorphClass());
            $subQuery->where(function ($query) use ($searchOptions) {
                foreach ($searchOptions->searches as $inputTerm) {
                    $query->orWhere('term', 'like', $inputTerm .'%');
                }
            })->groupBy('entity_type', 'entity_id');
            $entitySelect->join(\DB::raw('(' . $subQuery->toSql() . ') as s'), function ($join) {
                $join->on('id', '=', 'entity_id');
            })->selectRaw($entity->getTable().'.*, s.score')->orderBy('score', 'desc');
            $entitySelect->mergeBindings($subQuery);
        }

        // Handle exact term matching
        if (count($searchOptions->exacts) > 0) {
            $entitySelect->where(function ($query) use ($searchOptions, $entity) {
                foreach ($searchOptions->exacts as $inputTerm) {
                    $query->where(function ($query) use ($inputTerm, $entity) {
                        $query->where('name', 'like', '%'.$inputTerm .'%')
                            ->orWhere($entity->textField, 'like', '%'.$inputTerm .'%');
                    });
                }
            });
        }

        // Handle tag searches
        foreach ($searchOptions->tags as $inputTerm) {
            $this->applyTagSearch($entitySelect, $inputTerm);
        }

        // Handle filters
        foreach ($searchOptions->filters as $filterTerm => $filterValue) {
            $functionName = Str::camel('filter_' . $filterTerm);
            if (method_exists($this, $functionName)) {
                $this->$functionName($entitySelect, $entity, $filterValue);
            }
        }

        return $this->permissionService->enforceEntityRestrictions($entityType, $entitySelect, $action);
    }

    /**
     * Get the available query operators as a regex escaped list.
     */
    protected function getRegexEscapedOperators(): string
    {
        $escapedOperators = [];
        foreach ($this->queryOperators as $operator) {
            $escapedOperators[] = preg_quote($operator);
        }
        return join('|', $escapedOperators);
    }

    /**
     * Apply a tag search term onto a entity query.
     */
    protected function applyTagSearch(EloquentBuilder $query, string $tagTerm): EloquentBuilder
    {
        preg_match("/^(.*?)((".$this->getRegexEscapedOperators().")(.*?))?$/", $tagTerm, $tagSplit);

        $query->whereHas('tags', function ($query) use ($tagSplit) {
            $tagName = $tagSplit[1];
            $tagOperator = count($tagSplit) > 2 ? $tagSplit[3] : '';
            $tagValue = count($tagSplit) > 3 ? $tagSplit[4] : '';
            $validOperator = in_array($tagOperator, $this->queryOperators);

            if ( ( empty($tagValue) || ( ! $validOperator ) ) && ( ! empty($tagName) ) ) {
                $query->where('name', '=', $tagName);
            } else {
                $query->where('value', $tagOperator, $tagValue);
            }
        });
        return $query;
    }

    /**
     * Index the given entity.
     */
    public function indexEntity(Entity $entity)
    {
        $this->deleteEntityTerms($entity);
        $nameTerms = $this->generateTermArrayFromText($entity->name, 5 * $entity->searchFactor);
        $bodyTerms = $this->generateTermArrayFromText($entity->getText(), 1 * $entity->searchFactor);
        $terms = array_merge($nameTerms, $bodyTerms);
        foreach ($terms as $index => $term) {
            $terms[$index]['entity_type'] = $entity->getMorphClass();
            $terms[$index]['entity_id'] = $entity->id;
        }
        $this->searchTerm->newQuery()->insert($terms);
    }

    /**
     * Index multiple Entities at once
     * @param \BookStack\Entities\Entity[] $entities
     */
    protected function indexEntities($entities)
    {
        $terms = [];
        foreach ($entities as $entity) {
            $nameTerms = $this->generateTermArrayFromText($entity->name, 5 * $entity->searchFactor);
            $bodyTerms = $this->generateTermArrayFromText($entity->getText(), 1 * $entity->searchFactor);
            foreach (array_merge($nameTerms, $bodyTerms) as $term) {
                $term['entity_id'] = $entity->id;
                $term['entity_type'] = $entity->getMorphClass();
                $terms[] = $term;
            }
        }

        $chunkedTerms = array_chunk($terms, 500);
        foreach ($chunkedTerms as $termChunk) {
            $this->searchTerm->newQuery()->insert($termChunk);
        }
    }

    /**
     * Delete and re-index the terms for all entities in the system.
     */
    public function indexAllEntities()
    {
        $this->searchTerm->truncate();

        foreach ($this->entityProvider->all() as $entityModel) {
            $selectFields = ['id', 'name', $entityModel->textField];
            $entityModel->newQuery()->select($selectFields)->chunk(1000, function ($entities) {
                $this->indexEntities($entities);
            });
        }
    }

    /**
     * Delete related Entity search terms.
     * @param Entity $entity
     */
    public function deleteEntityTerms(Entity $entity)
    {
        $entity->searchTerms()->delete();
    }

    /**
     * Create a scored term array from the given text.
     * @param $text
     * @param float|int $scoreAdjustment
     * @return array
     */
    protected function generateTermArrayFromText($text, $scoreAdjustment = 1)
    {
        $tokenMap = []; // {TextToken => OccurrenceCount}
        $splitChars = " \n\t.,!?:;()[]{}<>`'\"";
        $token = strtok($text, $splitChars);

        while ($token !== false) {
            if (!isset($tokenMap[$token])) {
                $tokenMap[$token] = 0;
            }
            $tokenMap[$token]++;
            $token = strtok($splitChars);
        }

        $terms = [];
        foreach ($tokenMap as $token => $count) {
            $terms[] = [
                'term' => $token,
                'score' => $count * $scoreAdjustment
            ];
        }
        return $terms;
    }




    /**
     * Custom entity search filters
     */

    protected function filterUpdatedAfter(EloquentBuilder $query, Entity $model, $input)
    {
        try {
            $date = date_create($input);
        } catch (\Exception $e) {
            return;
        }
        $query->where('updated_at', '>=', $date);
    }

    protected function filterUpdatedBefore(EloquentBuilder $query, Entity $model, $input)
    {
        try {
            $date = date_create($input);
        } catch (\Exception $e) {
            return;
        }
        $query->where('updated_at', '<', $date);
    }

    protected function filterCreatedAfter(EloquentBuilder $query, Entity $model, $input)
    {
        try {
            $date = date_create($input);
        } catch (\Exception $e) {
            return;
        }
        $query->where('created_at', '>=', $date);
    }

    protected function filterCreatedBefore(EloquentBuilder $query, Entity $model, $input)
    {
        try {
            $date = date_create($input);
        } catch (\Exception $e) {
            return;
        }
        $query->where('created_at', '<', $date);
    }

    protected function filterCreatedBy(EloquentBuilder $query, Entity $model, $input)
    {
        if (!is_numeric($input) && $input !== 'me') {
            return;
        }
        if ($input === 'me') {
            $input = user()->id;
        }
        $query->where('created_by', '=', $input);
    }

    protected function filterUpdatedBy(EloquentBuilder $query, Entity $model, $input)
    {
        if (!is_numeric($input) && $input !== 'me') {
            return;
        }
        if ($input === 'me') {
            $input = user()->id;
        }
        $query->where('updated_by', '=', $input);
    }

    protected function filterInName(EloquentBuilder $query, Entity $model, $input)
    {
        $query->where('name', 'like', '%' .$input. '%');
    }

    protected function filterInTitle(EloquentBuilder $query, Entity $model, $input)
    {
        $this->filterInName($query, $model, $input);
    }

    protected function filterInBody(EloquentBuilder $query, Entity $model, $input)
    {
        $query->where($model->textField, 'like', '%' .$input. '%');
    }

    protected function filterIsRestricted(EloquentBuilder $query, Entity $model, $input)
    {
        $query->where('restricted', '=', true);
    }

    protected function filterViewedByMe(EloquentBuilder $query, Entity $model, $input)
    {
        $query->whereHas('views', function ($query) {
            $query->where('user_id', '=', user()->id);
        });
    }

    protected function filterNotViewedByMe(EloquentBuilder $query, Entity $model, $input)
    {
        $query->whereDoesntHave('views', function ($query) {
            $query->where('user_id', '=', user()->id);
        });
    }

    protected function filterSortBy(EloquentBuilder $query, Entity $model, $input)
    {
        $functionName = Str::camel('sort_by_' . $input);
        if (method_exists($this, $functionName)) {
            $this->$functionName($query, $model);
        }
    }


    /**
     * Sorting filter options
     */

    protected function sortByLastCommented(EloquentBuilder $query, Entity $model)
    {
        $commentsTable = $this->db->getTablePrefix() . 'comments';
        $morphClass = str_replace('\\', '\\\\', $model->getMorphClass());
        $commentQuery = $this->db->raw('(SELECT c1.entity_id, c1.entity_type, c1.created_at as last_commented FROM '.$commentsTable.' c1 LEFT JOIN '.$commentsTable.' c2 ON (c1.entity_id = c2.entity_id AND c1.entity_type = c2.entity_type AND c1.created_at < c2.created_at) WHERE c1.entity_type = \''. $morphClass .'\' AND c2.created_at IS NULL) as comments');

        $query->join($commentQuery, $model->getTable() . '.id', '=', 'comments.entity_id')->orderBy('last_commented', 'desc');
    }
}
