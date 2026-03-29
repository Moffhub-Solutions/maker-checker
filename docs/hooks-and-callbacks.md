# Hooks and Callbacks

Execute custom logic at various points in the approval lifecycle.

## Per-Request Hooks

Attach hooks when building a request. These are serialized and stored with the request, so they execute even if the approval happens in a different process or later.

```php
MakerChecker::request()
    ->toCreate(Post::class, $data)
    ->beforeApproval(function ($request) {
        Log::info('About to approve', ['id' => $request->id]);
    })
    ->afterApproval(function ($request) {
        Notification::send($request->maker, new RequestApproved($request));
    })
    ->beforeRejection(function ($request) {
        // Cleanup logic
    })
    ->afterRejection(function ($request) {
        Notification::send($request->maker, new RequestRejected($request));
    })
    ->onFailure(function ($request) {
        Log::error('Request failed', ['id' => $request->id]);
    })
    ->madeBy(auth()->user())
    ->save();
```

### Available Per-Request Hooks

| Hook | When Executed |
|------|---------------|
| `beforeApproval` | Before the approved request is fulfilled |
| `afterApproval` | After the request is fulfilled successfully |
| `beforeRejection` | Before the request is marked as rejected |
| `afterRejection` | After the request is marked as rejected |
| `onFailure` | When request execution fails |

### Executable Hooks

When using `toExecute()`, the executable class can define hooks directly:

```php
class TransferFunds extends ExecutableRequest
{
    public function execute(MakerCheckerRequest $request): void { /* ... */ }

    public function beforeApproval(MakerCheckerRequest $request): void
    {
        // Validate accounts still exist before executing
    }

    public function afterApproval(MakerCheckerRequest $request): void
    {
        // Send confirmation email
    }

    public function onFailure(MakerCheckerRequest $request): void
    {
        // Alert ops team
    }
}
```

## Global Callbacks

### Config-Based

Define callbacks in `config/maker-checker.php` that run for all requests:

```php
'callbacks' => [
    'on_initiated' => [
        App\MakerChecker\Callbacks\LogNewRequest::class,
        App\MakerChecker\Callbacks\SendSlackNotification::class,
    ],
    'after_approval' => [
        App\MakerChecker\Callbacks\UpdateAuditLog::class,
    ],
    'after_rejection' => [
        App\MakerChecker\Callbacks\NotifyManager::class,
    ],
    'on_failure' => [
        App\MakerChecker\Callbacks\AlertOps::class,
    ],
],
```

Callback classes should implement `RequestCallback` or have a `handle` method:

```php
use Moffhub\MakerChecker\Contracts\RequestCallback;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class LogNewRequest implements RequestCallback
{
    public function handle(MakerCheckerRequest $request): void
    {
        Log::info('New approval request', [
            'code' => $request->code,
            'type' => $request->type->value,
            'maker' => $request->maker_id,
        ]);
    }
}
```

### Programmatic (Event-Based)

Register callbacks at runtime in a service provider:

```php
MakerChecker::callbacks()
    ->onInitiated(function (MakerCheckerRequest $request) {
        Log::info('Request initiated', ['code' => $request->code]);
    })
    ->afterApproval(function (MakerCheckerRequest $request) {
        Notification::send($request->maker, new RequestCompleted($request));
    })
    ->afterRejection(function (MakerCheckerRequest $request) {
        event(new RequestRejectedEvent($request));
    })
    ->onFailure(function (MakerCheckerRequest $request) {
        Alert::critical("Request {$request->code} failed");
    });
```

### Event Listeners

You can also listen to the dispatched events directly:

```php
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Events\RequestCancelled;
use Moffhub\MakerChecker\Events\RequestFailed;

// In EventServiceProvider or via facade methods
MakerChecker::afterInitiating(fn($event) => /* ... */);
MakerChecker::afterApproving(fn($event) => /* ... */);
MakerChecker::afterRejecting(fn($event) => /* ... */);
MakerChecker::afterCancelling(fn($event) => /* ... */);
MakerChecker::onFailure(fn($event) => /* ... */);
```

### All Available Hook Points

| Hook | Per-Request | Global Config | Global Event |
|------|:-----------:|:-------------:|:------------:|
| Request created | - | `on_initiated` | `RequestInitiated` |
| Before approval | `beforeApproval()` | - | - |
| After approval | `afterApproval()` | `after_approval` | `RequestApproved` |
| Before rejection | `beforeRejection()` | - | - |
| After rejection | `afterRejection()` | `after_rejection` | `RequestRejected` |
| Request cancelled | - | - | `RequestCancelled` |
| Execution failed | `onFailure()` | `on_failure` | `RequestFailed` |
