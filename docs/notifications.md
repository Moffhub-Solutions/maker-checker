# Notifications

The package dispatches events at every stage of the request lifecycle. Your application listens to these events and handles notifications however you want — email, Slack, SMS, push, database, or anything else.

## Events

| Event | When Dispatched | Typical Use |
|-------|----------------|-------------|
| `RequestInitiated` | New request created | Notify approvers |
| `RequestApproved` | Request fully approved and fulfilled | Notify maker |
| `RequestRejected` | Request rejected | Notify maker |
| `RequestCancelled` | Request cancelled by maker | Notify approvers |
| `RequestFailed` | Fulfillment failed | Alert ops team |

All events carry the `MakerCheckerRequest` instance. `RequestFailed` also carries the `Throwable`.

## Listening to Events

Register listeners in your `EventServiceProvider` or anywhere in your app:

```php
use Moffhub\MakerChecker\Events\RequestInitiated;
use Moffhub\MakerChecker\Events\RequestApproved;
use Moffhub\MakerChecker\Events\RequestRejected;
use Moffhub\MakerChecker\Events\RequestFailed;

// In EventServiceProvider::$listen
protected $listen = [
    RequestInitiated::class => [
        NotifyApproversListener::class,
    ],
    RequestApproved::class => [
        NotifyMakerOfApprovalListener::class,
    ],
    RequestRejected::class => [
        NotifyMakerOfRejectionListener::class,
    ],
];
```

Or register inline:

```php
// In a service provider
Event::listen(RequestInitiated::class, function (RequestInitiated $event) {
    // $event->request is the MakerCheckerRequest
    // Send notifications, post to Slack, etc.
});
```

## Example Listener

```php
use Moffhub\MakerChecker\Events\RequestInitiated;

class NotifyApproversListener
{
    public function handle(RequestInitiated $event): void
    {
        $request = $event->request;

        // Option A: Use the built-in NotificationService helper
        app(NotificationService::class)->notifyPendingApproval($request);

        // Option B: Do it yourself
        $approvers = User::where('role', 'admin')->get();
        Notification::send($approvers, new YourCustomNotification($request));

        // Option C: Post to Slack, send SMS, whatever you need
        Slack::channel('#approvals')->send("New request: {$request->description}");
    }
}
```

## Built-in Helpers (Optional)

The package provides a `NotificationService` and notification classes you can use in your listeners. These are helpers — they don't run automatically.

### NotificationService

```php
use Moffhub\MakerChecker\Facades\MakerChecker;

// Notify all approvers about a pending request
MakerChecker::notifyApprovers($request);

// Sequential mode — notify only the first pending role
MakerChecker::notifyApprovers($request, sequential: true);

// Notify next role after a partial approval
MakerChecker::notifyNextApprovers($request);

// Notify maker of approval/rejection
MakerChecker::notifications()->notifyRequestApproved($request);
MakerChecker::notifications()->notifyRequestRejected($request);
```

### Built-in Notification Classes

Use them directly or extend them:

```php
use Moffhub\MakerChecker\Notifications\PendingApprovalNotification;
use Moffhub\MakerChecker\Notifications\RequestApprovedNotification;
use Moffhub\MakerChecker\Notifications\RequestRejectedNotification;

// Send directly
$approver->notify(new PendingApprovalNotification($request, 'admin'));
$request->maker->notify(new RequestApprovedNotification($request));
$request->maker->notify(new RequestRejectedNotification($request));
```

### Custom Notification Classes

Override the classes used by `NotificationService`:

```php
// config/maker-checker.php
'notifications' => [
    'enabled' => true,
    'pending_notification' => App\Notifications\CustomPendingNotification::class,
    'approved_notification' => App\Notifications\CustomApprovedNotification::class,
    'rejected_notification' => App\Notifications\CustomRejectedNotification::class,
],
```

Your custom notification should accept a `MakerCheckerRequest` in its constructor:

```php
use Illuminate\Notifications\Notification;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class CustomPendingNotification extends Notification
{
    public function __construct(
        public MakerCheckerRequest $request,
        public ?string $role = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'slack'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approval Needed')
            ->line("Please review: {$this->request->description}")
            ->action('Review', url("/approvals/{$this->request->code}"));
    }
}
```

## Finding Approvers

The `ApproverResolver` contract determines which users can approve a request. The default resolver queries users by their `role` attribute.

For complex setups (Spatie permissions, team-based roles, etc.), implement your own:

```php
use Moffhub\MakerChecker\Contracts\ApproverResolver;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class CustomApproverResolver implements ApproverResolver
{
    public function getApproversForRole(MakerCheckerRequest $request, string $role): Collection
    {
        return User::role($role)
            ->where('team_id', $request->team_id)
            ->where('id', '!=', $request->maker_id)
            ->get();
    }

    public function getAllApprovers(MakerCheckerRequest $request): Collection
    {
        // ...
    }

    // ... other contract methods
}

// Register in AppServiceProvider
$this->app->bind(ApproverResolver::class, CustomApproverResolver::class);
```

## Sequential Notifications

For workflows where roles should be notified one at a time:

```php
// In your RequestInitiated listener:
MakerChecker::notifyApprovers($request, sequential: true);
// Only the first required role is notified

// After a partial approval, in your own code:
if ($request->isPartiallyApproved()) {
    MakerChecker::notifyNextApprovers($request);
}
```

## Notification Configuration

These settings control the `NotificationService` helper behavior:

```php
// config/maker-checker.php
'notifications' => [
    'enabled' => true,                    // Master enable/disable for the helper
    'channels' => ['mail', 'database'],   // Notification channels
    'notify_maker' => true,               // Whether notifyRequestApproved/Rejected sends to maker
    'user_model' => App\Models\User::class,
    'role_attribute' => 'role',
],
```
