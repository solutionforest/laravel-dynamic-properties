<?php

namespace DynamicProperties\Exceptions;

use Exception;

/**
 * Base exception class for all property-related errors
 */
class PropertyException extends Exception
{
    protected array $context = [];

    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get additional context information about the error
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context information
     */
    public function setContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Get a user-friendly error message suitable for API responses
     */
    public function getUserMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * Get error data formatted for API responses
     */
    public function toArray(): array
    {
        return [
            'error' => class_basename(static::class),
            'message' => $this->getUserMessage(),
            'context' => $this->getContext(),
        ];
    }
}
