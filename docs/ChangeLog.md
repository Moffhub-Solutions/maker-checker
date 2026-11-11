# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [v0.1.2] - 2026-11-11

### Added
- Relationship-level approval: new `RequestType::RELATION` covering `attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle`, `updateExistingPivot` (BelongsToMany/MorphToMany) and `associate`/`dissociate` (BelongsTo)
- Explicit relationship API: `RequiresApproval::requestRelation()` returning a fluent `PendingRelationship`, plus `RequestBuilder::toRelation()`/`toAttach()`/`toDetach()`/`toSync()`/`toSyncWithoutDetaching()`/`toToggle()`/`toUpdateExistingPivot()`/`toAssociate()`/`toDissociate()`
- Transparent relationship interception via the `InterceptsRelationships` trait and `$approvableRelations` config (Approvable* relation proxies)
- Rollback support for relationship requests (prior relationship state snapshotted at request time)
- `relation` key in `global_approvals` / model `approvals` config for relationship approval requirements

### Fixed
- Fulfillment and rollback of create/update/delete/relationship requests no longer re-trigger `RequiresApproval` interception (previously an approved change could be silently re-queued instead of applied)
- `RequestApproved::fromRequest()` no longer calls `array_sum()` on non-numeric approval structures (PHP 8.4+ `array_sum` on strings), fixing OR-mode/user-based approvals
- Removed dead `is_numeric()` guards on the array-cast `required_approvals` and resolved all PHPStan findings

### Security
- Raised the minimum `illuminate/support` to `^12.69 || ^13.30` so installs resolve a Laravel release that includes the fix for the `TokenGuard` authentication bypass through non-string API credentials (GHSA advisory for `laravel/framework`)
- Updated the dev lockfile from `laravel/framework` v13.2.0 to v13.42.0

## [v0.1.1] - 2026-03-29

### Added
- Checker identity validation via `ApproverResolver` before approval processing
- Rate limiting on all API endpoints (configurable, default 60/min)
- Database indexes on `maker_id`, `checker_id`, and composite indexes for query performance
- `AuditService` with database and log drivers for recording all approval actions
- Pessimistic locking (`lockForUpdate`) for race condition prevention during concurrent approvals
- Bulk approval endpoint (`POST /requests/bulk-approve`)
- Approval notes/comments system for reviewers
- Approval delegation with dedicated API endpoints
- Approval reminders and escalation artisan command
- Audit trail export with CSV and JSON format support
- Interfaces: `CallbackServiceInterface`, `ConfigResolverInterface`, `ConditionEvaluatorInterface`
- Config validation on boot with `InvalidConfigurationException` for early failure detection
- Performance documentation covering indexing strategy, locking behavior, and query optimization
- Model factories for consumer testing

### Fixed
- ReDoS vulnerability in `ConditionEvaluator` regex handling
- Generic exceptions replaced with typed `FulfillmentException` and `RequestCannotBeChecked`
- `composer.json` type changed from `"project"` to `"library"` for proper package consumption
- `Carbon::diffInMinutes()` negative value bug in expiration check
- `NotificationServiceTest` stale request state after `lockForUpdate`

### Changed
- PHPStan level tightened with unnecessary ignores removed
- Updated to Laravel 13 support (`illuminate/support` `^12.0 || ^13.0`)

## [v0.1.0] - 2026-01-29

### Added
- Conditional evaluations for approval workflows
- Notifications and callback handling
- `RequiresApproval` trait for models
- Tests for approval workflows

### Changed
- Removed legacy approach in favor of new conditional evaluation system
- Cleaned up provider and publish configuration
- General code cleanup and gap fixes

## [v0.0.13] - 2025-09-09

### Changed
- Updated packages and dependencies
- General cleanup and package updates

## [v0.0.12] - 2025-03-10

_No changes to this package (tag only)._

## [v0.0.11] - 2025-03-10

### Changed
- Updated packages and dependencies

## [v0.0.10] - 2025-03-10

### Added
- Support for Laravel 12

### Fixed
- Lint fixes

## [v0.0.9] - 2024-11-23

### Added
- Advanta integration setup

### Changed
- Made ID field a string type

### Fixed
- Lint and test name fixes

## [v0.0.8] - 2024-08-29

### Added
- Partially approved status support

## [v0.0.7] - 2024-08-29

### Added
- Partially approved status support

## [v0.0.6] - 2024-08-29

### Added
- Partially approved status support

## [v0.0.5] - 2024-08-29

### Added
- Partially approved status support

## [v0.0.4] - 2024-08-29

### Added
- Partially approved status support

## [v0.0.3] - 2024-08-29

### Added
- Partially approved status support
- CI/CD pipeline

### Fixed
- PHP version constraint fix

## [v0.0.2] - 2024-08-29

### Added
- Roles support for approval requests

## [v0.0.1] - 2024-08-28

### Added
- Initial release: maker-checker library scaffold

### Fixed
- Initial bug fixes
