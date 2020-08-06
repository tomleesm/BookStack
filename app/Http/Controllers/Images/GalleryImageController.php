<?php

namespace BookStack\Http\Controllers\Images;

use BookStack\Exceptions\ImageUploadException;
use BookStack\Uploads\ImageRepo;
use Illuminate\Http\Request;
use BookStack\Http\Controllers\Controller;

class GalleryImageController extends Controller
{
    protected $imageRepo;

    /**
     * GalleryImageController constructor.
     * @param ImageRepo $imageRepo
     */
    public function __construct(ImageRepo $imageRepo)
    {
        $this->imageRepo = $imageRepo;
        parent::__construct();
    }

    /**
     * Get a list of gallery images, in a list.
     * Can be paged and filtered by entity.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request)
    {
        // GET /images/gallery?page=1&uploaded_to=293
        // 第幾頁
        $page = $request->get('page', 1);
        // 關鍵字，用來搜尋圖片的名稱
        $keywordForSearch = $request->get('search', null);
        // 圖片上傳到哪一頁 Page 的id
        $uploadedToFilter = $request->get('uploaded_to', null);
        // chapter or page
        $parentTypeFilter = $request->get('filter_type', null);

        $imgData = $this->imageRepo->getEntityFiltered('gallery', $parentTypeFilter, $page, 24, $uploadedToFilter, $keywordForSearch);
        return response()->json($imgData);
    }

    /**
     * Store a new gallery image in the system.
     * @param Request $request
     * @return Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function create(Request $request)
    {
        $this->checkPermission('image-create-all');
        $this->validate($request, [
            'file' => $this->getImageValidationRules()
        ]);

        try {
            $imageUpload = $request->file('file');
            $uploadedTo = $request->get('uploaded_to', 0);
            $image = $this->imageRepo->saveNew($imageUpload, 'gallery', $uploadedTo);
        } catch (ImageUploadException $e) {
            return response($e->getMessage(), 500);
        }

        return response()->json($image);
    }
}
