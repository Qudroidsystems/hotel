<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Base for the staff mobile app's API (/api/v1). Every answer has the same
 * shape: {"success": true, "message": ..., "data": ..., "meta": {...}}.
 * Errors come back as {"message": ..., "errors": {...}, "code": ...}.
 */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => $meta ?: null,
        ], fn ($v) => $v !== null), $status);
    }

    protected function fail(string $message, int $status = 422, ?string $code = null, array $errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'code'    => $code,
            'errors'  => $errors ?: null,
        ], fn ($v) => $v !== null), $status);
    }
}
