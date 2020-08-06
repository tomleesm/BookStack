<?php namespace BookStack\Http\Controllers;

use Activity;
use BookStack\Entities\Book;
use BookStack\Entities\Managers\PageContent;
use BookStack\Entities\Page;
use BookStack\Entities\Repos\BookRepo;
use BookStack\Entities\Repos\BookshelfRepo;
use Illuminate\Http\Response;
use Views;

class HomeController extends Controller
{

    /**
     * Display the homepage.
     * @return Response
     */
    public function index()
    {
        $activity = Activity::latest(10);
        $draftPages = [];

        // 如果使用者已登入
        // 因爲如果設爲開放，使用者就就不見得有登入
        if ($this->isSignedIn()) {
            // 抓取該使用者的最近6個草稿
            $draftPages = Page::visible()->where('draft', '=', true)
                ->where('created_by', '=', user()->id)
                ->orderBy('updated_at', 'desc')->take(6)->get();
        }

        $recentFactor = count($draftPages) > 0 ? 0.5 : 1;
        // 如果有登入，抓取最近檢視的 entity，否則抓取最近新增的 12 本書
        $recents = $this->isSignedIn() ?
              Views::getUserRecentlyViewed(12*$recentFactor, 0) // 有草稿，最多 6 個，否則最多 12 個
            : Book::visible()->orderBy('created_at', 'desc')->take(12 * $recentFactor)->get();
        // 最近更新的12個頁面
        $recentlyUpdatedPages = Page::visible()->where('draft', false)
            ->orderBy('updated_at', 'desc')->take(12)->get();

        $homepageOptions = ['default', 'books', 'bookshelves', 'page'];
        // 抓取設定的首頁類型，沒有的話，值爲預設
        $homepageOption = setting('app-homepage-type', 'default');
        // 只能是 $homepageOptions 的四個其中之一，否則設爲預設
        if (!in_array($homepageOption, $homepageOptions)) {
            $homepageOption = 'default';
        }

        $commonData = [
            'activity' => $activity,
            'recents' => $recents,
            'recentlyUpdatedPages' => $recentlyUpdatedPages,
            'draftPages' => $draftPages,
        ];

        // Add required list ordering & sorting for books & shelves views.
        if ($homepageOption === 'bookshelves' || $homepageOption === 'books') {
            $key = $homepageOption;
            // 抓取目前登入的會員設定的 bookshelves 或 books 的顯示方式: list 或 grid、排列順序，預設是依照名稱由小到大排列
            $view = setting()->getForCurrentUser($key . '_view_type', config('app.views.' . $key));
            $sort = setting()->getForCurrentUser($key . '_sort', 'name');
            $order = setting()->getForCurrentUser($key . '_sort_order', 'asc');

            // 頁面上排列方式選單顯示的文字
            $sortOptions = [
                'name' => trans('common.sort_name'), // Name
                'created_at' => trans('common.sort_created_at'), // Created Date
                'updated_at' => trans('common.sort_updated_at'), // Updated Date
            ];

            $commonData = array_merge($commonData, [
                'view' => $view,
                'sort' => $sort,
                'order' => $order,
                'sortOptions' => $sortOptions,
            ]);
        }

        if ($homepageOption === 'bookshelves') {
            // 抓取所有可顯示的書架資料，並依照先前設定的順序排列
            $shelves = app(BookshelfRepo::class)->getAllPaginated(18, $commonData['sort'], $commonData['order']);
            $data = array_merge($commonData, ['shelves' => $shelves]);
            // 書架專用 view
            return view('common.home-shelves', $data);
        }

        if ($homepageOption === 'books') {
            // 抓取所有可顯示的書籍資料，並依照先前設定的順序排列
            $bookRepo = app(BookRepo::class);
            $books = $bookRepo->getAllPaginated(18, $commonData['sort'], $commonData['order']);
            $data = array_merge($commonData, ['books' => $books]);
            // 書籍專用 view
            return view('common.home-book', $data);
        }

        // 如果設定顯示頁面
        if ($homepageOption === 'page') {
            // 則需要指定顯示哪個頁面，沒有的話預設值爲 0:
            $homepageSetting = setting('app-homepage', '0:');
            // 抓取指定頁面的資料庫 id，沒有話爲數字 0
            $id = intval(explode(':', $homepageSetting)[0]);
            // 抓取指定頁面的資料，必須不是草稿
            $customHomepage = Page::query()->where('draft', '=', false)->findOrFail($id);
            // 抓取指定頁面的內容
            $pageContent = new PageContent($customHomepage);
            // 抓取頁面內容的 HTML，但是移除標籤，只剩內容
            $customHomepage->html = $pageContent->render(true);
            // 單一頁面專用 view
            return view('common.home-custom', array_merge($commonData, ['customHomepage' => $customHomepage]));
        }

        // 預設的 view
        return view('common.home', $commonData);
    }

    /**
     * Get custom head HTML, Used in ajax calls to show in editor.
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function customHeadContent()
    {
        return view('partials.custom-head-content');
    }

    /**
     * Show the view for /robots.txt
     * @return $this
     */
    public function getRobots()
    {
        // 讀取資料庫中是否需要登入才能瀏覽的設定，預設是不用
        $sitePublic = setting('app-public', false);
        // 讀取 app/Config/app.php 中的 allow_robots 設定
        $allowRobots = config('app.allow_robots');
        if ($allowRobots === null) {
            $allowRobots = $sitePublic;
        }
        /**
         * 顯示純文字檔如下
         *
         * User-agent: *
         * Disallow: / <-- if $allowRobots
         *
         **/
        return response()
            ->view('common.robots', ['allowRobots' => $allowRobots])
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Show the route for 404 responses.
     */
    public function getNotFound()
    {
        return response()->view('errors.404', [], 404);
    }
}
