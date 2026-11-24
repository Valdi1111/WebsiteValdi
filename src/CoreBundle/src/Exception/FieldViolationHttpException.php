<?php

namespace App\CoreBundle\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * A 422 on one field, for a check made by the action instead of the validator (e.g. a wrong current password), with
 * the same shape as the MapUploadedFile / MapRequestPayload violations: "detail" is readable also in production and
 * the frontend shows the message next to the field ("violations[].propertyPath").
 */
class FieldViolationHttpException extends UnprocessableEntityHttpException
{
    /**
     * @param \Throwable|null $cause the exception of the failed check, kept as the cause of the violation
     */
    public function __construct(string $propertyPath, string $message, ?\Throwable $cause = null)
    {
        $violation = new ConstraintViolation($message, null, [], null, $propertyPath, null, cause: $cause);

        parent::__construct($message, new ValidationFailedException(null, new ConstraintViolationList([$violation])));
    }
}
