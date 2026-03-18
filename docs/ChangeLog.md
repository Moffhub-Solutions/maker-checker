# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
