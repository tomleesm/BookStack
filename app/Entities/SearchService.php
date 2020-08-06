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

    /**
     * SearchService constructor.
     * @param SearchTerm $searchTerm
     * @param EntityProvider $entityProvider
     * @param Connection $db
     * @param PermissionService $permissionService
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
     * @param Connection $connection
     */
    public function setConnection(Connection $connection)
    {
        $this->db = $connection;
    }

    /**
     * Search all entities in the system.
     * @param string $searchString
     * @param string $entityType
     * @param int $page
     * @param int $count - Count of each entity to search, Total returned could can be larger and not guaranteed.
     * @return array[int, Collection];
     */
    public function searchEntities($searchString, $entityType = 'all', $page = 1, $count = 20)
    {
        // 解析輸入的字串，回傳搜尋條件陣列
        // [ search: 一般關鍵字, exact: 精準搜尋, tags: 標籤, filters: 過濾條件 ]
        $terms = $this->parseSearchString($searchString);
        // entityProvider->all(): 回傳 4 個主要 Entity 的物件關聯陣列
        // array_keys(): 回傳 key 值，所以 $entityTypes = ['bookshelf', 'book', 'chapter', 'page']
        $entityTypes = array_keys($this->entityProvider->all());
        // 要搜尋的 entity 預設是全部
        $entityTypesToSearch = $entityTypes;

        // 使用 ack 搜尋過，只有 SearchController@search 有呼叫 SearchService@searchEntities，所以$entityType 只可能是 all
        if ($entityType !== 'all') {
            $entityTypesToSearch = $entityType;
            // 如果輸入的字串有指定 entity type，就設爲它
            // 例如 {type:page|chapter}，表示指定搜尋範圍 page 和 chapter
        } else if (isset($terms['filters']['type'])) {
            $entityTypesToSearch = explode('|', $terms['filters']['type']);
        }

        $results = collect();
        $total = 0;
        $hasMore = false;

        foreach ($entityTypesToSearch as $entityType) {
            // 如果filters type 亂打，不是主要的 entity，則跳過
            if (!in_array($entityType, $entityTypes)) {
                continue;
            }
            // 設定必須有檢視的權限
            $this->permissionService->setCurrentAction('view');
            $searchQuery = $this->searchEntityTable($terms, $entityType);
            // 如果是第2頁 $page = 2，一頁顯示20筆，則越過前面 (2 - 1) * 20 = 20 筆資料，只顯示之後的 20 筆資料
            $searchResult = $searchQuery->skip(($page - 1) * $count)->take($count)->get();
            // 是否還有下一頁
            if ($this->hasMore($searchQuery, $page, $count)) {
                $hasMore = true;
            }
            // 加總 Book 等類別的的資料總筆數
            $total += $searchQuery->count();
            // 這個 Book 等類別的搜尋結果合併到 $results
            $results = $results->merge($searchResult);
        }

        // $total 和 $results->count() 是一樣的，實際上只用到 $total
        return [
            'total' => $total,
            'count' => $results->count(),
            'has_more' => $hasMore,
            // sortBy() 和 sortByDESC() 會把 collection 的元素順序不依照 key 值排列
            // values() 依照目前的元素順序重設 collection key 值，確保從 0 開始
            // 但是不會修改 collection 本身，所以每次都要用 values() 重設
            // https://laravel.com/docs/6.x/collections#method-sortby
            'results' => $results->sortByDesc('score')->values()
        ];
    }

    // 如果總共有 105 筆資料，每頁20筆，則在第 5 筆時，105 > 5 * 20，之後還有資料
    private function hasMore($searchQuery, $page, $count)
    {
        return $searchQuery->count() > $page * $count;
    }

    /**
     * Search a book for entities
     * @param integer $bookId
     * @param string $searchString
     * @return Collection
     */
    public function searchBook($bookId, $searchString)
    {
        $terms = $this->parseSearchString($searchString);
        // 預設搜尋 page 和 chapter
        $entityTypes = ['page', 'chapter'];
        // 如果有指定搜尋範圍: $terms['filters']['type'] = page|chapter|book
        // 則轉成陣列 $entityTypesToSearch = ['page', 'chapter', 'book']
        $entityTypesToSearch = isset($terms['filters']['type']) ? explode('|', $terms['filters']['type']) : $entityTypes;

        $results = collect();
        foreach ($entityTypesToSearch as $entityType) {
            // 只搜尋預設指定範圍，例如 book 就不會搜尋
            if (!in_array($entityType, $entityTypes)) {
                continue;
            }
            // 產生搜尋結果，設定範圍是這本書，只抓前20筆資料
            $search = $this->buildEntitySearchQuery($terms, $entityType)->where('book_id', '=', $bookId)->take(20)->get();
            $results = $results->merge($search);
        }
        // 搜尋結果依照 score 遞減排列，只抓前20筆資料
        return $results->sortByDesc('score')->take(20);
    }

    /**
     * Search a book for entities
     * @param integer $chapterId
     * @param string $searchString
     * @return Collection
     */
    public function searchChapter($chapterId, $searchString)
    {
        $terms = $this->parseSearchString($searchString);
        $pages = $this->buildEntitySearchQuery($terms, 'page')->where('chapter_id', '=', $chapterId)->take(20)->get();
        return $pages->sortByDesc('score');
    }

    /**
     * Search across a particular entity type.
     * @param array $terms
     * @param string $entityType
     * @param int $page
     * @param int $count
     * @return \Illuminate\Database\Eloquent\Collection|int|static[]
     */
    public function searchEntityTable($terms, $entityType = 'page')
    {
        return $this->buildEntitySearchQuery($terms, $entityType);
    }

    /**
     * Create a search query for an entity
     * @param array $terms
     * @param string $entityType
     * @return EloquentBuilder
     */
    protected function buildEntitySearchQuery($terms, $entityType = 'page')
    {
        // 傳入字串 page，回傳 Page 物件
        $entity = $this->entityProvider->get($entityType);
        // newQuery(): Get a new query builder for the model's table.
        // 所以執行完 newQuery() ，就可以接著 ->where() 查詢這個 table 的資料，包含關聯的 tables
        // 但是一旦執行 get() 或 all() 回傳資料，這個 Builder 就不能用了
        $entitySelect = $entity->newQuery();

        // Handle normal search terms
        // 一般的關鍵字放在 $terms['search'][]
        if (count($terms['search']) > 0) {
            // $subQuery: 搜尋包含關鍵字開頭的文字，然後列出每個 entity 的加總分數
            /** 例如輸入 a b {type:book}
              SELECT books.*, s.score from `books` INNER JOIN (
              SELECT `entity_id`, `entity_type`, SUM(score) AS score
              FROM `search_terms`
              WHERE `entity_type` = 'BookStack\\Book'
              AND (`term` LIKE 'a%' OR `term` LIKE 'b%')
              GROUP BY `entity_type`, `entity_id`) AS s
              ON `id` = `entity_id`
              ORDER BY `score` DESC
             */
            $subQuery = $this->db->table('search_terms')->select('entity_id', 'entity_type', \DB::raw('SUM(score) as score'));
            $subQuery->where('entity_type', '=', $entity->getMorphClass());
            $subQuery->where(function (Builder $query) use ($terms) {
                foreach ($terms['search'] as $inputTerm) {
                    $query->orWhere('term', 'like', $inputTerm .'%');
                }
            })->groupBy('entity_type', 'entity_id');
            // 爲了查看 entity 的 name 和 description，所以 JOIN $subQuery
            $entitySelect->join(\DB::raw('(' . $subQuery->toSql() . ') as s'), function (JoinClause $join) {
                $join->on('id', '=', 'entity_id');
            })->selectRaw($entity->getTable().'.*, s.score')->orderBy('score', 'desc');
            // mergeBindings
            $entitySelect->mergeBindings($subQuery);
        }

        // Handle exact term matching
        // 如果搜尋 "a b" "c d" 則 SQL 查詢
        // 加上 WHERE ((`name` LIKE '%a b%' OR `text` LIKE '%a b%')
        //      AND    (`name` LIKE '%c d%' OR `text` LIKE '%c d%'))
        // 所以產生的 SQL 是錯的。輸入的字串意味著 "a b" 或 "c d"，但是 SQL 卻是 AND
        // 建議 foreach 改成 for 迴圈，$query->when($i) ，當 $i = 0，使用 $query->where()，$i > 0，使用 $query->orWhere()
        if (count($terms['exact']) > 0) {
            $entitySelect->where(function (EloquentBuilder $query) use ($terms, $entity) {
                foreach ($terms['exact'] as $inputTerm) {
                    $query->where(function (EloquentBuilder $query) use ($inputTerm, $entity) {
                        $query->where('name', 'like', '%'.$inputTerm .'%')
                            ->orWhere($entity->textField, 'like', '%'.$inputTerm .'%');
                    });
                }
            });
        }

        // Handle tag searches
        foreach ($terms['tags'] as $inputTerm) {
            $this->applyTagSearch($entitySelect, $inputTerm);
        }

        // Handle filters
        foreach ($terms['filters'] as $filterTerm => $filterValue) {
            $functionName = Str::camel('filter_' . $filterTerm);
            if (method_exists($this, $functionName)) {
                $this->$functionName($entitySelect, $entity, $filterValue);
            }
        }

        return $this->permissionService->enforceEntityRestrictions($entityType, $entitySelect);
    }


    /**
     * Parse a search string into components.
     * @param $searchString
     * @return array
     */
    protected function parseSearchString($searchString)
    {
        $terms = [
            'search' => [],
            'exact' => [],
            'tags' => [],
            'filters' => []
        ];

        $patterns = [
            'exact' => '/"(.*?)"/',
            'tags' => '/\[(.*?)\]/',
            'filters' => '/\{(.*?)\}/'
        ];

        // Parse special terms
        foreach ($patterns as $termType => $pattern) {
            $matches = [];
            preg_match_all($pattern, $searchString, $matches);
            if (count($matches) > 0) {
                $terms[$termType] = $matches[1];
                $searchString = preg_replace($pattern, '', $searchString);
            }
        }

        // Parse standard terms
        foreach (explode(' ', trim($searchString)) as $searchTerm) {
            if ($searchTerm !== '') {
                $terms['search'][] = $searchTerm;
            }
        }

        // Split filter values out
        $splitFilters = [];
        foreach ($terms['filters'] as $filter) {
            $explodedFilter = explode(':', $filter, 2);
            $splitFilters[$explodedFilter[0]] = (count($explodedFilter) > 1) ? $explodedFilter[1] : '';
        }
        $terms['filters'] = $splitFilters;

        return $terms;
    }

    /**
     * Get the available query operators as a regex escaped list.
     * @return mixed
     */
    protected function getRegexEscapedOperators()
    {
        $escapedOperators = [];
        foreach ($this->queryOperators as $operator) {
            $escapedOperators[] = preg_quote($operator);
        }
        return join('|', $escapedOperators);
    }

    /**
     * Apply a tag search term onto a entity query.
     * @param EloquentBuilder $query
     * @param string $tagTerm
     * @return mixed
     */
    protected function applyTagSearch(EloquentBuilder $query, $tagTerm)
    {
        // getRegexExcapedOperators(): <= >= = < > like != 把左側這些 operator 加上 \ ，才能在正規表示法中正常使用
        // tags 是所見即所得編輯器的功能，tag 是 key => value 格式，例如 age => 3
        // 搜尋恇字串輸入 [age>1] 在 parseSearchString() 中已經把 [] 拿掉，所以 pre_match() 是抓 age>1
        preg_match("/^(.*?)((".$this->getRegexEscapedOperators().")(.*?))?$/", $tagTerm, $tagSplit);
        $query->whereHas('tags', function (EloquentBuilder $query) use ($tagSplit) {
            $tagName = $tagSplit[1]; // age
            $tagOperator = count($tagSplit) > 2 ? $tagSplit[3] : ''; // operator >
            $tagValue = count($tagSplit) > 3 ? $tagSplit[4] : ''; // 數字 1
            $validOperator = in_array($tagOperator, $this->queryOperators); // 檢查輸入的 operator 是否合法
            if (!empty($tagOperator) && !empty($tagValue) && $validOperator) {
                if (!empty($tagName)) {
                    // WHERE `name` = 'age'
                    $query->where('name', '=', $tagName);
                }
                // value 是數字，且 operator 是 <= >= = < = !=
                if (is_numeric($tagValue) && $tagOperator !== 'like') {
                    // We have to do a raw sql query for this since otherwise PDO will quote the value and MySQL will
                    // search the value as a string which prevents being able to do number-based operations
                    // on the tag values. We ensure it has a numeric value and then cast it just to be sure.
                    $tagValue = (float) trim($query->getConnection()->getPdo()->quote($tagValue), "'");
                    // value > 1
                    $query->whereRaw("value ${tagOperator} ${tagValue}");
                } else {
                    // value 不是數字或 operator 是 LIKE
                    // 類似 value like 'abc'
                    $query->where('value', $tagOperator, $tagValue);
                }
            } else {
                // 沒有 operator 或沒有 value 或 operator 不是右側這些: <= >= = < > like !=
                // 最單純的 `name` = 'age'
                $query->where('name', '=', $tagName);
            }
        });
        return $query;
    }

    /**
     * Index the given entity.
     * @param Entity $entity
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
