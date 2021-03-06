<?php namespace BookStack\Http\Controllers;

use BookStack\Actions\ViewService;
use BookStack\Entities\Book;
use BookStack\Entities\Bookshelf;
use BookStack\Entities\Entity;
use BookStack\Entities\Managers\EntityContext;
use BookStack\Entities\SearchService;
use BookStack\Entities\SearchOptions;
use Illuminate\Http\Request;
use BookStack\Facades\Collection;

class SearchController extends Controller
{
    protected $viewService;
    protected $searchService;
    protected $entityContextManager;

    /**
     * SearchController constructor.
     */
    public function __construct(
        ViewService $viewService,
        SearchService $searchService,
        EntityContext $entityContextManager
    ) {
        $this->viewService = $viewService;
        $this->searchService = $searchService;
        $this->entityContextManager = $entityContextManager;
        parent::__construct();
    }

    /**
     * Searches all entities.
     */
    public function search(Request $request)
    {
        $searchOpts = (new SearchOptions)->fromRequest($request, 'all');
        $fullSearchString = $searchOpts->toString();
        $this->setPageTitle(trans('entities.search_for_term', ['term' => $fullSearchString]));

        $results = $this->searchService->searchEntities();
        $results = Collection::paginate($results, 20);

        return view('search.all', [
            'entities'   => $results,
            'searchTerm' => $fullSearchString,
            'nextPageLink' => $this->getNextPageLink($fullSearchString),
            'options' => $searchOpts,
        ]);
    }


    /**
     * Searches all entities within a book.
     */
    public function searchBook(Request $request, int $bookId)
    {
        $results = $this->searchService->searchBook($bookId);
        $results = Collection::paginate($results, 20);
        return view('partials.entity-list', ['entities' => $results]);
    }

    /**
     * Searches all entities within a chapter.
     */
    public function searchChapter(Request $request, int $chapterId)
    {
        $results = $this->searchService->searchChapter($chapterId);
        $results = Collection::paginate($results, 20);
        return view('partials.entity-list', ['entities' => $results]);
    }

    /**
     * Search for a list of entities and return a partial HTML response of matching entities.
     * Returns the most popular entities if no search is provided.
     */
    public function searchEntitiesAjax(Request $request)
    {
        $entityTypes = $request->filled('types') ? explode(',', $request->get('types')) : ['page', 'chapter', 'book'];
        $searchTerm =  $request->get('term');
        $permission = $request->get('permission', 'view');

        // Search for entities otherwise show most popular
        if (empty($searchTerm)) {
            $entities = $this->viewService->getPopular(20, 0, $entityTypes, $permission);
        } else {
            $entities = $this->searchService->searchEntities($permission);
        }

        $entities = Collection::paginate($entities, 20);

        return view('search.entity-ajax-list', ['entities' => $entities]);
    }

    /**
     * Search siblings items in the system.
     */
    public function searchSiblings(Request $request)
    {
        $type = $request->get('entity_type', null);
        $id = $request->get('entity_id', null);

        $entity = Entity::getEntityInstance($type)->newQuery()->visible()->find($id);
        if (!$entity) {
            return $this->jsonError(trans('errors.entity_not_found'), 404);
        }

        $entities = [];

        // Page in chapter
        if ($entity->isA('page') && $entity->chapter) {
            $entities = $entity->chapter->getVisiblePages();
        }

        // Page in book or chapter
        if (($entity->isA('page') && !$entity->chapter) || $entity->isA('chapter')) {
            $entities = $entity->book->getDirectChildren();
        }

        // Book
        // Gets just the books in a shelf if shelf is in context
        if ($entity->isA('book')) {
            $contextShelf = $this->entityContextManager->getContextualShelfForBook($entity);
            if ($contextShelf) {
                $entities = $contextShelf->visibleBooks()->get();
            } else {
                $entities = Book::visible()->get();
            }
        }

        // Shelve
        if ($entity->isA('bookshelf')) {
            $entities = Bookshelf::visible()->get();
        }

        return view('partials.entity-list-basic', ['entities' => $entities, 'style' => 'compact']);
    }

    private function getPageNumber()
    {
        $page = request()->get('page');
        if(empty($page) || intval($page) === 0) {
            return 1;
        }

        return intval($page);
    }

    private function getNextPageNumber()
    {
        return $this->getPageNumber() + 1;
    }

    private function getNextPageLink($fullSearchString)
    {
        return url('/search?term=' . urlencode($fullSearchString) . '&page=' . $this->getNextPageNumber() );
    }
}
