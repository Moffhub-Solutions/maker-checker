## MakerChecker

MakerChecker is a package that provides a simple way to implement the Maker-Checker pattern in your Laravel application.

## Installation

You can install the package via composer:

```bash
composer require moffhub/maker-checker
```
This package depends on the `sourcetoad\enhanced-resources` which you can install via composer:

```bash
composer require sourcetoad\enhanced-resources
```

You can publish the config file with:

```bash 
php artisan vendor:publish --provider="Moffhub\MakerChecker\MakerCheckerServiceProvider" --tag="config"
```

This is the contents of the published config file:

## Usage

First, you need to add the `ChecksRequests` trait to the model you want to check against for roles and permissions typically the `User` Model.

```php
use Moffhub\MakerChecker\Traits\ChecksRequests;

class Post extends Model
{
    use ChecksRequests;
}
```

Then, you can use the `makerChecker` method to create a new record.

```php
 if (auth()->user()->requiresApproval('create', Post::class)) {
            $approvalRequest = MakerChecker::request()
                ->toExecute(
                    $approvableActionClass,
                    (array) $data,
                )
                ->madeBy(auth()->user())
                ->description('Create Posts')
                ->save();

            return MakerCheckerResource::make($approvalRequest)
                ->format(MakerCheckerResource::SIMPLE)
                ->response()
                ->setStatusCode(Response::HTTP_MULTI_STATUS);
        }
```
## Approvable actions

This library supports create, delete, update and execute actions. You can create your own actions by extending the `ApprovableAction` class.

This is configured by either calling

for execute
Additionally you can pass an array of roles that can approve the request and the number of approvals required for the request to be approved by each role
```php
MakerChecker::request()->toExecute(
                    $approvableActionClass,
                    (array) $data,
                    [
                        'admin'=> 2,
                        'client' => 3,
                    ],
                )
``` 
or for create
```php
MakerChecker::request()->toCreate(
                    $createActionClass,
                    (array) $data,
                )
```
or for update
```php
MakerChecker::request()->toUpdate(
                    $updateActionClass,
                    (array) $data,
                )
```
or for delete
```php
MakerChecker::request()->toDelete(
                    $deleteActionClass,
                    (array) $data,
                )
```

