<?php

namespace App\Http\Controllers\Ng;

use App\Ng\UseCases\ListOrderSummaries;
use Illuminate\Http\JsonResponse;

class DeepLayeredController
{
    public function __construct(private readonly ListOrderSummaries $useCase) {}

    /** Layer 1 of 5: Controller -> UseCase -> DomainService -> Repository interface -> Eloquent implementation. */
    public function __invoke(): JsonResponse
    {
        return response()->json(['orders' => $this->useCase->handle()]);
    }
}
