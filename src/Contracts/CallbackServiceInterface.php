<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Closure;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Contract for managing configurable lifecycle callbacks.
 *
 * Callbacks can be registered via config file (class names implementing RequestCallback)
 * or programmatically via the register methods on this interface.
 */
interface CallbackServiceInterface
{
    /**
     * Register a callback to run before approval.
     */
    public function beforeApproval(Closure|string $callback): self;

    /**
     * Register a callback to run after approval.
     */
    public function afterApproval(Closure|string $callback): self;

    /**
     * Register a callback to run before rejection.
     */
    public function beforeRejection(Closure|string $callback): self;

    /**
     * Register a callback to run after rejection.
     */
    public function afterRejection(Closure|string $callback): self;

    /**
     * Register a callback to run when a request is initiated.
     */
    public function onInitiated(Closure|string $callback): self;

    /**
     * Register a callback to run on failure.
     */
    public function onFailure(Closure|string $callback): self;

    /**
     * Execute all callbacks for a given hook.
     */
    public function execute(string $hook, MakerCheckerRequest $request): void;

    /**
     * Execute before approval callbacks.
     */
    public function executeBeforeApproval(MakerCheckerRequest $request): void;

    /**
     * Execute after approval callbacks.
     */
    public function executeAfterApproval(MakerCheckerRequest $request): void;

    /**
     * Execute before rejection callbacks.
     */
    public function executeBeforeRejection(MakerCheckerRequest $request): void;

    /**
     * Execute after rejection callbacks.
     */
    public function executeAfterRejection(MakerCheckerRequest $request): void;

    /**
     * Execute on initiated callbacks.
     */
    public function executeOnInitiated(MakerCheckerRequest $request): void;

    /**
     * Execute on failure callbacks.
     */
    public function executeOnFailure(MakerCheckerRequest $request): void;
}
