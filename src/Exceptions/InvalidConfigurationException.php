<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use RuntimeException;

/**
 * Exception thrown when the maker-checker configuration is invalid.
 *
 * This exception is thrown during service provider boot when required
 * configuration values are missing or invalid.
 */
class InvalidConfigurationException extends RuntimeException
{
    /**
     * Create an exception for an invalid request model.
     */
    public static function invalidRequestModel(string $class): self
    {
        return new self(
            "The configured request_model '{$class}' must be a valid class that extends "
            .'Moffhub\\MakerChecker\\Models\\MakerCheckerRequest.'
        );
    }

    /**
     * Create an exception for an invalid approval count.
     */
    public static function invalidApprovalCount(int $count): self
    {
        return new self(
            "The configured default_approval_count must be >= 1, got {$count}."
        );
    }

    /**
     * Create an exception for an invalid config driver.
     */
    public static function invalidConfigDriver(string $driver): self
    {
        return new self(
            "The configured config_driver '{$driver}' is not valid. Must be 'file' or 'database'."
        );
    }

    /**
     * Create an exception for invalid whitelisted models.
     */
    public static function invalidWhitelistedModels(string $key): self
    {
        return new self(
            "The configured whitelisted_models.{$key} must be an array. "
            .'Check your maker-checker.php config file.'
        );
    }

    /**
     * Create a generic configuration exception.
     */
    public static function create(string $message): self
    {
        return new self($message);
    }
}
