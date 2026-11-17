<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

/**
 * Exception thrown when a property operation fails due to system issues.
 */
class PropertyOperationException extends PropertyException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $operation, string $reason, array $context = [])
    {
        parent::__construct("Property operation '{$operation}' failed: {$reason}", 500, null, array_merge([
            'operation' => $operation,
            'reason'    => $reason,
        ], $context));
    }

    public function getUserMessage(): string
    {
        $operation = $this->context['operation'] ?? 'operation';
        $name = is_string($operation) ? $operation : 'operation';

        return "The property {$name} could not be completed. Please try again later.";
    }
}
