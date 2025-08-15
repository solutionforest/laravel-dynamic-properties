<?php

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

/**
 * Exception thrown when a property operation fails due to system issues
 */
class PropertyOperationException extends PropertyException
{
    public function __construct(string $operation, string $reason, array $context = [])
    {
        $message = "Property operation '{$operation}' failed: {$reason}";

        parent::__construct($message, 500, null, array_merge([
            'operation' => $operation,
            'reason'    => $reason,
        ], $context));
    }

    public function getUserMessage(): string
    {
        $operation = $this->context['operation'] ?? 'operation';

        return "The property {$operation} could not be completed. Please try again later.";
    }
}
