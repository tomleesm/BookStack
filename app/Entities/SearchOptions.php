<?php namespace BookStack\Entities;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchOptions
{

    /**
     * @var array
     */
    public $searches = [];

    /**
     * @var array
     */
    public $exacts = [];

    /**
     * @var array
     */
    public $tags = [];

    /**
     * @var array
     */
    public $filters = [];

    /**
     * entities to search
     *
     * @var Illuminate\Support\Collection
     */
    private $entities = null;

    private $whichEntityTypeToSearch = null;

    private $searchableEntities = ['page', 'chapter', 'book', 'bookshelf'];

    /**
     * Create a new instance from a search string.
     */
    public function fromString(string $search): SearchOptions
    {
        $decoded = $this->decode($search);
        foreach ($decoded as $type => $value) {
            $this->$type = $value;
        }

        $this->setEntities();

        return $this;
    }

    /**
     * Create a new instance from a request.
     * Will look for a classic string term and use that
     * Otherwise we'll use the details from an advanced search form.
     */
    public function fromRequest(Request $request, $whichEntityTypeToSearch = []): SearchOptions
    {
        $this->whichEntityTypeToSearch = $whichEntityTypeToSearch;

        // search for nothing
        if (!$request->has('search') && !$request->has('term')) {
            return $this->fromString('');
        }

        // search from SearchController@searchEntitiesAjax
        if(Str::contains(url()->full(), '/ajax/search/entities')) {
            return $this->ajaxSearch($request);
        }

        // search from navigation bar
        if ($request->has('term')) {
            return $this->fromString($request->get('term'));
        }

        // search from advance search
        if($request->has('search') && $request->has('term')) {
            return $this->advanceSearch($request);
        }

    }

    /**
     * Decode a search string into an array of terms.
     */
    protected function decode(string $searchString): array
    {
        $terms = [
            'searches' => [],
            'exacts' => [],
            'tags' => [],
            'filters' => []
        ];

        $patterns = [
            'exacts' => '/"(.*?)"/',
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
                $terms['searches'][] = $searchTerm;
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
     * Encode this instance to a search string.
     */
    public function toString(): string
    {
        $string = implode(' ', $this->searches ?? []);

        foreach ($this->exacts as $term) {
            $string .= ' "' . $term . '"';
        }

        foreach ($this->tags as $term) {
            $string .= " [{$term}]";
        }

        foreach ($this->filters as $filterName => $filterVal) {
            $string .= ' {' . $filterName . ($filterVal ? ':' . $filterVal : '') . '}';
        }

        return $string;
    }

    private function advanceSearch($request)
    {
        $instance = new self();
        $inputs = $request->only(['search', 'types', 'filters', 'exact', 'tags']);
        $instance->searches = explode(' ', $inputs['search'] ?? []);
        $instance->exacts = array_filter($inputs['exact'] ?? []);
        $instance->tags = array_filter($inputs['tags'] ?? []);
        foreach (($inputs['filters'] ?? []) as $filterKey => $filterVal) {
            if (empty($filterVal)) {
                continue;
            }
            $instance->filters[$filterKey] = $filterVal === 'true' ? '' : $filterVal;
        }
        if (isset($inputs['types']) && count($inputs['types']) < 4) {
            $instance->filters['type'] = implode('|', $inputs['types']);
        }

        $this->setEntities();

        return $instance;
    }

    private function ajaxSearch($request)
    {
        $searchTerm =  $request->get('term');
        $entityTypes = $request->filled('types') ? explode(',', $request->get('types')) : ['page', 'chapter', 'book', 'bookshelf'];
        $searchTerm .= ' {type:'. implode('|', $entityTypes) .'}';
        return $this->fromString(($searchTerm));
    }

    public function getEntities()
    {
        return $this->entities;
    }

    private function setEntities()
    {
        $this->entities = collect($this->getEntityTypesToSearch($this->whichEntityTypeToSearch, $this));
    }

    private function getEntityTypesToSearch($whichEntityTypeToSearch, $searchOptions){
        $types = $this->searchableEntities;
        if ($this->existFilterType($searchOptions)) {
            $types = explode('|', $searchOptions->filters['type']);
        } else if($whichEntityTypeToSearch !== 'all') {
            $types = $whichEntityTypeToSearch;
        }

        return $this->filterEntityTypes($types);
    }

    private function existFilterType($searchOptions)
    {
        return   isset($searchOptions->filters['type'])
            && ( ! empty($searchOptions->filters['type']) );
    }

    private function filterEntityTypes($entityTypes)
    {
        $validEntityTypes = [];
        foreach ($entityTypes as $entityType) {
            if (in_array($entityType, $this->searchableEntities)) {
                array_push($validEntityTypes, $entityType);
            }
        }

        return $validEntityTypes;
    }
}
