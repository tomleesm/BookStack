<?php namespace BookStack\Http\Controllers;

use Activity;
use BookStack\Entities\Book;
use BookStack\Entities\Managers\EntityContext;
use BookStack\Entities\Repos\BookshelfRepo;
use BookStack\Exceptions\ImageUploadException;
use BookStack\Exceptions\NotFoundException;
use BookStack\Uploads\ImageRepo;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Views;

class BookshelfController extends Controller
{

    protected $bookshelfRepo;
    protected $entityContextManager;
    protected $imageRepo;

    /**
     * BookController constructor.
     */
    public function __construct(BookshelfRepo $bookshelfRepo, EntityContext $entityContextManager, ImageRepo $imageRepo)
    {
        $this->bookshelfRepo = $bookshelfRepo;
        $this->entityContextManager = $entityContextManager;
        $this->imageRepo = $imageRepo;
        parent::__construct();
    }

    /**
     * Display a listing of the book.
     */
    public function index()
    {
        // 抓取目前使用者設定的書架排列順序，預設是名稱
        // setting()->getForCurrentUser('bookshelves_sort', 'name');
        // 抓取目前使用者設定的書架排列方式，預設是由小到大
        // setting()->getForCurrentUser('bookshelves_sort_order', 'asc');

        // 刪除之前瀏覽的的書架資料表id (儲存在 session)
        $this->entityContextManager->clearShelfContext();
        // 設定 <title>
        $this->setPageTitle(trans('entities.shelves'));
        return view('shelves.index', [
            // 所有書架資料，一頁18個
            'shelves' => $this->bookshelfRepo->getAllPaginated(18,
                             setting()->getForCurrentUser('bookshelves_sort', 'name'),
                             setting()->getForCurrentUser('bookshelves_sort_order', 'asc')),
            // 左邊的 Recently Viewed
            'recents' => $this->isSignedIn() ? $this->bookshelfRepo->getRecentlyViewed(4) : false,
            // 左邊的 Popular Shelves
            'popular' => $this->bookshelfRepo->getPopular(4),
            // 左邊的 New Shelves
            'new' => $this->bookshelfRepo->getRecentlyCreated(4),
            // 抓取目前使用者設定的書架顯示方式，清單或網格，預設是網格
            'view' => setting()->getForCurrentUser('bookshelves_view_type', config('app.views.bookshelves', 'grid')),
            'sort' => setting()->getForCurrentUser('bookshelves_sort', 'name'),
            'order' => setting()->getForCurrentUser('bookshelves_sort_order', 'asc'),
            // 排序界面顯示的文字
            'sortOptions' => [
                'name' => trans('common.sort_name'),
                'created_at' => trans('common.sort_created_at'),
                'updated_at' => trans('common.sort_updated_at')
            ],
        ]);
    }

    /**
     * Show the form for creating a new bookshelf.
     */
    public function create()
    {
        $this->checkPermission('bookshelf-create-all');
        $books = Book::hasPermission('update')->get();
        $this->setPageTitle(trans('entities.shelves_create'));
        return view('shelves.create', ['books' => $books]);
    }

    /**
     * Store a newly created bookshelf in storage.
     * @throws ValidationException
     * @throws ImageUploadException
     */
    public function store(Request $request)
    {
        $this->checkPermission('bookshelf-create-all');
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'description' => 'string|max:1000',
            'image' => 'nullable|' . $this->getImageValidationRules(),
        ]);

        $bookIds = explode(',', $request->get('books', ''));
        $shelf = $this->bookshelfRepo->create($request->all(), $bookIds);
        $this->bookshelfRepo->updateCoverImage($shelf, $request->file('image', null));

        Activity::add($shelf, 'bookshelf_create');
        return redirect($shelf->getUrl());
    }

    /**
     * Display the bookshelf of the given slug.
     * @throws NotFoundException
     */
    public function show(string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('book-view', $shelf);

        // 計算該名會員對於書架的點閱數，一般是增加 1 次
        // 以產生左側 Popular Shelves 的顯示順序
        Views::add($shelf);
        // 把目前顯示的書架 id 存到 session (auto increment id)
        $this->entityContextManager->setShelfContext($shelf->id);
        // 抓取這個使用者設定的書架顯示方式，list or grid ?
        $view = setting()->getForCurrentUser('bookshelf_view_type', config('app.views.books'));

        // 設定 <title>
        $this->setPageTitle($shelf->getShortName());
        return view('shelves.show', [
            'shelf' => $shelf,
            'view' => $view,
            'activity' => Activity::entityActivity($shelf, 20, 1)
        ]);
    }

    /**
     * Show the form for editing the specified bookshelf.
     */
    public function edit(string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('bookshelf-update', $shelf);

        // 書架上所有書籍的 id: [3, 2, 4]
        $shelfBookIds = $shelf->books()->get(['id'])->pluck('id');
        // 這個書架沒有的書籍。編輯書架的功能中，有把書籍加到書架中，或者移出書架
        $books = Book::hasPermission('update')->whereNotIn('id', $shelfBookIds)->get();

        $this->setPageTitle(trans('entities.shelves_edit_named', ['name' => $shelf->getShortName()]));
        return view('shelves.edit', [
            'shelf' => $shelf,
            'books' => $books,
        ]);
    }

    /**
     * Update the specified bookshelf in storage.
     * @throws ValidationException
     * @throws ImageUploadException
     * @throws NotFoundException
     */
    public function update(Request $request, string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('bookshelf-update', $shelf);
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'description' => 'string|max:1000',
            'image' => 'nullable|' . $this->getImageValidationRules(),
        ]);

        // 用隱藏欄位記錄要改成書架中有哪些書籍
        // <input type="hidden" id="books-input" name="books" value="6,1">
        $bookIds = explode(',', $request->get('books', ''));
        $shelf = $this->bookshelfRepo->update($shelf, $request->all(), $bookIds);
        $resetCover = $request->has('image_reset');
        // 修改書架封面圖片
        $this->bookshelfRepo->updateCoverImage($shelf, $request->file('image', null), $resetCover);
        // 記錄使用者有更新書架的活動，顯示在首頁的 Recent Activity
        Activity::add($shelf, 'bookshelf_update');

        return redirect($shelf->getUrl());
    }

    /**
     * Shows the page to confirm deletion
     */
    public function showDelete(string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('bookshelf-delete', $shelf);

        $this->setPageTitle(trans('entities.shelves_delete_named', ['name' => $shelf->getShortName()]));
        return view('shelves.delete', ['shelf' => $shelf]);
    }

    /**
     * Remove the specified bookshelf from storage.
     * @throws Exception
     */
    public function destroy(string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('bookshelf-delete', $shelf);

        // Adds a activity history with a message, without binding to a entity.
        Activity::addMessage('bookshelf_delete', $shelf->name);
        $this->bookshelfRepo->destroy($shelf);

        return redirect('/shelves');
    }

    /**
     * Show the permissions view.
     * 顯示設定權限的頁面
     */
    public function showPermissions(string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('restrictions-manage', $shelf);

        return view('shelves.permissions', [
            'shelf' => $shelf,
        ]);
    }

    /**
     * Set the permissions for this bookshelf.
     */
    public function permissions(Request $request, string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('restrictions-manage', $shelf);

        $restricted = $request->get('restricted') === 'true';
        // $request->filled('restrictions') 檢查 input 欄位 restrictions 是否有值並且不是空值(空字串)
        // restrictions 欄位用在勾選 Enable Custom Permissions 後的 checkbox
        $permissions = $request->filled('restrictions') ? collect($request->get('restrictions')) : null;
        $this->bookshelfRepo->updatePermissions($shelf, $restricted, $permissions);

        // 新增成功訊息到 session，跳轉後顯示
        $this->showSuccessNotification(trans('entities.shelves_permissions_updated'));
        return redirect($shelf->getUrl());
    }

    /**
     * Copy the permissions of a bookshelf to the child books.
     */
    public function copyPermissions(string $slug)
    {
        $shelf = $this->bookshelfRepo->getBySlug($slug);
        $this->checkOwnablePermission('restrictions-manage', $shelf);

        $updateCount = $this->bookshelfRepo->copyDownPermissions($shelf);
        $this->showSuccessNotification(trans('entities.shelves_copy_permission_success', ['count' => $updateCount]));
        return redirect($shelf->getUrl());
    }
}
