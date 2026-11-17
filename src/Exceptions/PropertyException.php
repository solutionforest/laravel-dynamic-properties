<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

use Exception;

/**
 * Base exception class for all property-related errors.
 */
class PropertyException extends Exception
{
    /**
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function setContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Get a user-friendly error message suitable for API responses.
     */
    public function getUserMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * @return array{error: string, message: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'error'   => class_basename(static::class),
            'message' => $this->getUserMessage(),
            'context' => $this->getContext(),
        ];
    }
}
