<?php namespace BookStack\Entities;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CollectionService
{
    public static function paginate(Collection $collection, int $perPage = 10)
    {
        $currentPage = Paginator::resolveCurrentPage('page');

        return new LengthAwarePaginator($collection->forPage($currentPage, $perPage),
                                        $collection->count(),
                                        $perPage,
                                        $currentPage,
                                        [
                                            'pageName' => 'page'
                                        ]
        );
    }

    public static function simplePaginate(Collection $collection, int $perPage = 10)
    {
        $currentPage = Paginator::resolveCurrentPage('page');

        return new Paginator($collection->forPage($currentPage, $perPage),
                                        $perPage,
                                        $currentPage,
                                        [
                                            'pageName' => 'page'
                                        ]
        );
    }
}
