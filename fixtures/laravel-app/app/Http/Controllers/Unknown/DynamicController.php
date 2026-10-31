<?php

namespace App\Http\Controllers\Unknown;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The method that runs is chosen at run time, so static analysis cannot say what it does. */
class DynamicController extends Controller
{
    private const ALLOWED = ['summary', 'latest'];

    public function __invoke(Request $request): JsonResponse
    {
        $method = $request->query('method', 'summary');

        abort_unless(in_array($method, self::ALLOWED, true), 404);

        return response()->json($this->{$method}());
    }

    private function summary(): array
    {
        return ['orders' => Order::count()];
    }

    private function latest(): array
    {
        return Order::latest('id')->limit(10)->pluck('id')->all();
    }
}
