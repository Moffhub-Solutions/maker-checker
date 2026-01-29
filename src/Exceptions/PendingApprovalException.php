<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use RuntimeException;

/**
 * Exception thrown when a model operation is intercepted and requires approval.
 *
 * This exception is thrown by the RequiresApproval trait when a create, update,
 * or delete operation needs to go through the maker-checker approval workflow.
 *
 * The exception contains the created MakerCheckerRequest so the caller can
 * access the pending request details, return appropriate responses, etc.
 *
 * @example
 * ```php
 * try {
 *     $post = Post::create(['title' => 'My Post']);
 * } catch (PendingApprovalException $e) {
 *     return response()->json([
 *         'message' => 'Your request has been submitted for approval.',
 *         'request_id' => $e->getRequest()->id,
 *         'request_code' => $e->getRequest()->code,
 *     ], 202);
 * }
 * ```
 */
class PendingApprovalException extends RuntimeException
{
    public function __construct(
        protected MakerCheckerRequest $request,
        string $message = 'Operation requires approval.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the maker-checker request that was created.
     */
    public function getRequest(): MakerCheckerRequest
    {
        return $this->request;
    }

    /**
     * Get the request code for easy reference.
     */
    public function getRequestCode(): string
    {
        return $this->request->code;
    }

    /**
     * Get the request ID.
     */
    public function getRequestId(): int|string
    {
        return $this->request->getKey();
    }

    /**
     * Create exception for a create operation.
     */
    public static function forCreate(MakerCheckerRequest $request): self
    {
        return new self($request, 'Create operation requires approval.');
    }

    /**
     * Create exception for an update operation.
     */
    public static function forUpdate(MakerCheckerRequest $request): self
    {
        return new self($request, 'Update operation requires approval.');
    }

    /**
     * Create exception for a delete operation.
     */
    public static function forDelete(MakerCheckerRequest $request): self
    {
        return new self($request, 'Delete operation requires approval.');
    }
}
