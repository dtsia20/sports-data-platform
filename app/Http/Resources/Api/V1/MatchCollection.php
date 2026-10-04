<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class MatchCollection extends ResourceCollection
{
    public $collects = MatchResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    public function paginationInformation(
        $request,
        $paginated,
        $default
    ): array {
        return [
            'meta' => [
                'currentPage' => $paginated['current_page'],
                'lastPage' => $paginated['last_page'],
                'perPage' => $paginated['per_page'],
                'total' => $paginated['total'],
            ],
            'links' => [
                'first' => $paginated['first_page_url'],
                'last' => $paginated['last_page_url'],
                'prev' => $paginated['prev_page_url'],
                'next' => $paginated['next_page_url'],
            ],
        ];
    }
}
