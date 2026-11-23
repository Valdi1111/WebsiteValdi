<?php

namespace App\Tests\CoreBundle\Validator;

use App\CoreBundle\Validator\NewPassword;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The rules of a new password, with the haveibeenpwned.com API replaced by a mock (the test environment disables
 * the check, so the HTTP tests do not reach it).
 */
class NewPasswordTest extends TestCase
{
    private const string STRONG_PASSWORD = 'Correct-Horse-Battery-Staple-42';

    /**
     * @param MockResponse|\Closure $apiResponse the answer of the API, or a MockHttpClient callback
     */
    private static function validator(MockResponse|\Closure $apiResponse): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator(new MockHttpClient($apiResponse)),
            ]))
            ->getValidator();
    }

    /**
     * @return list<string> violation messages
     */
    private static function messages(ValidatorInterface $validator, string $password): array
    {
        $messages = [];
        foreach ($validator->validate($password, new NewPassword()) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    public function testAStrongPasswordNotLeakedIsValid(): void
    {
        $this->assertSame([], self::messages(self::validator(new MockResponse("0000000000000000000000000000000000A:3\r\n")), self::STRONG_PASSWORD));
    }

    public function testALeakedPasswordIsRefused(): void
    {
        $sha1 = strtoupper(sha1(self::STRONG_PASSWORD));
        $validator = self::validator(new MockResponse(substr($sha1, 5).":42\r\n"));

        $this->assertSame(
            ['This password has been leaked in a data breach, it must not be used. Please use another password.'],
            self::messages($validator, self::STRONG_PASSWORD),
        );
    }

    public function testAnUnreachableApiDoesNotBlockTheChange(): void
    {
        $validator = self::validator(new MockResponse('', ['http_code' => 503]));

        $this->assertSame([], self::messages($validator, self::STRONG_PASSWORD));
    }

    public function testOnlyTheFirstBrokenRuleIsReported(): void
    {
        // The other rules stop first: the API is not called
        $validator = self::validator(static fn (): never => throw new \LogicException('The API must not be called.'));

        $this->assertSame(['Enter a new password.'], self::messages($validator, ''));
        $this->assertSame(['This value is too short. It should have 12 characters or more.'], self::messages($validator, 'aaaa'));
        $this->assertSame(['The password strength is too low. Please use a stronger password.'], self::messages($validator, 'aaaaaaaaaaaa'));
    }
}
