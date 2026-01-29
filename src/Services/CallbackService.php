<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Services;

use Closure;
use Illuminate\Foundation\Application;
use Moffhub\MakerChecker\Contracts\RequestCallback;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Service for managing configurable callbacks.
 *
 * Callbacks can be registered:
 * 1. Via config file (class names implementing RequestCallback)
 * 2. Programmatically via register methods
 */
class CallbackService
{
    /**
     * @var array<string, array<Closure>>
     */
    protected array $callbacks = [
        'before_approval' => [],
        'after_approval' => [],
        'before_rejection' => [],
        'after_rejection' => [],
        'on_initiated' => [],
        'on_failure' => [],
    ];

    protected bool $configCallbacksLoaded = false;

    public function __construct(
        protected Application $app
    ) {}

    /**
     * Register a callback to run before approval.
     */
    public function beforeApproval(Closure|string $callback): self
    {
        $this->callbacks['before_approval'][] = $this->resolveCallback($callback);

        return $this;
    }

    /**
     * Register a callback to run after approval.
     */
    public function afterApproval(Closure|string $callback): self
    {
        $this->callbacks['after_approval'][] = $this->resolveCallback($callback);

        return $this;
    }

    /**
     * Register a callback to run before rejection.
     */
    public function beforeRejection(Closure|string $callback): self
    {
        $this->callbacks['before_rejection'][] = $this->resolveCallback($callback);

        return $this;
    }

    /**
     * Register a callback to run after rejection.
     */
    public function afterRejection(Closure|string $callback): self
    {
        $this->callbacks['after_rejection'][] = $this->resolveCallback($callback);

        return $this;
    }

    /**
     * Register a callback to run when a request is initiated.
     */
    public function onInitiated(Closure|string $callback): self
    {
        $this->callbacks['on_initiated'][] = $this->resolveCallback($callback);

        return $this;
    }

    /**
     * Register a callback to run on failure.
     */
    public function onFailure(Closure|string $callback): self
    {
        $this->callbacks['on_failure'][] = $this->resolveCallback($callback);

        return $this;
    }

    /**
     * Execute all callbacks for a given hook.
     */
    public function execute(string $hook, MakerCheckerRequest $request): void
    {
        $this->loadConfigCallbacks();

        $callbacks = $this->callbacks[$hook] ?? [];

        foreach ($callbacks as $callback) {
            $callback($request);
        }
    }

    /**
     * Execute before approval callbacks.
     */
    public function executeBeforeApproval(MakerCheckerRequest $request): void
    {
        $this->execute('before_approval', $request);
    }

    /**
     * Execute after approval callbacks.
     */
    public function executeAfterApproval(MakerCheckerRequest $request): void
    {
        $this->execute('after_approval', $request);
    }

    /**
     * Execute before rejection callbacks.
     */
    public function executeBeforeRejection(MakerCheckerRequest $request): void
    {
        $this->execute('before_rejection', $request);
    }

    /**
     * Execute after rejection callbacks.
     */
    public function executeAfterRejection(MakerCheckerRequest $request): void
    {
        $this->execute('after_rejection', $request);
    }

    /**
     * Execute on initiated callbacks.
     */
    public function executeOnInitiated(MakerCheckerRequest $request): void
    {
        $this->execute('on_initiated', $request);
    }

    /**
     * Execute on failure callbacks.
     */
    public function executeOnFailure(MakerCheckerRequest $request): void
    {
        $this->execute('on_failure', $request);
    }

    /**
     * Load callbacks from config file (only once).
     */
    protected function loadConfigCallbacks(): void
    {
        if ($this->configCallbacksLoaded) {
            return;
        }

        $this->configCallbacksLoaded = true;

        $configCallbacks = config('maker-checker.callbacks', []);

        foreach ($configCallbacks as $hook => $callbacks) {
            if (!isset($this->callbacks[$hook])) {
                continue;
            }

            $callbacks = is_array($callbacks) ? $callbacks : [$callbacks];

            foreach ($callbacks as $callback) {
                if (is_string($callback) && class_exists($callback)) {
                    $this->callbacks[$hook][] = $this->resolveCallback($callback);
                }
            }
        }
    }

    /**
     * Resolve a callback to a Closure.
     */
    protected function resolveCallback(Closure|string $callback): Closure
    {
        if ($callback instanceof Closure) {
            return $callback;
        }

        // It's a class name, resolve it
        return function (MakerCheckerRequest $request) use ($callback) {
            $instance = $this->app->make($callback);

            if ($instance instanceof RequestCallback) {
                $instance->handle($request);
            } elseif (is_callable($instance)) {
                $instance($request);
            } elseif (method_exists($instance, 'handle')) {
                $instance->handle($request);
            }
        };
    }
}
