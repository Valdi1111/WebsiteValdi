<?php

namespace App\CoreBundle\ObjectMapper;

use Symfony\Component\ObjectMapper\ConditionCallableInterface;

/**
 * TODO: Remove this class when upgrading to Symfony 8.1+ and use the built-in
 *       Symfony\Component\ObjectMapper\Condition\IsNotNull attribute instead.
 */
class NotNullCondition implements ConditionCallableInterface
{
    public function __invoke(mixed $value, object $source, ?object $target): bool
    {
        return $value !== null;
    }
}