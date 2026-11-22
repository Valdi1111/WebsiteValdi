<?php

namespace App\Tests\GamesBundle\Model\Pull;

use App\GamesBundle\Model\Pull\Manual\CreateManualPullRequest;
use App\GamesBundle\Model\Pull\Manual\DeclarePityRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The request DTOs go through the app's real serializer (global camelCase <-> snake_case name
 * converter) and validator, as #[MapRequestPayload] does.
 */
class ManualPullRequestTest extends KernelTestCase
{
    public function testCreateRequestMapsSnakeCaseKeys(): void
    {
        $request = $this->deserialize(CreateManualPullRequest::class, [
            'banner' => 'character',
            'anchor_id' => 12,
            'name' => 'Neuvillette',
            'item_id' => '1070',
            'item_type' => 'Character',
            'pulled_at' => '2024-12-31 23:50:00',
            'pity' => 81,
            'allow_duplicate' => true,
        ]);

        $this->assertSame('character', $request->banner);
        $this->assertSame(12, $request->anchorId);
        $this->assertSame('Neuvillette', $request->name);
        $this->assertSame('1070', $request->itemId);
        $this->assertSame('Character', $request->itemType);
        $this->assertSame('2024-12-31 23:50:00', $request->pulledAt);
        $this->assertSame(81, $request->pity);
        $this->assertTrue($request->allowDuplicate);
        $this->assertCount(0, $this->validate($request));
    }

    public function testCreateRequestDefaultsAndConstraints(): void
    {
        $request = $this->deserialize(CreateManualPullRequest::class, [
            'banner' => 'character',
            'name' => 'Furina',
            'pulled_at' => '2025-01-01T10:00:00Z',
            'pity' => 0,
        ]);

        $this->assertNull($request->anchorId);
        $this->assertNull($request->itemId);
        $this->assertFalse($request->allowDuplicate);

        $paths = array_map(
            static fn ($violation): string => $violation->getPropertyPath(),
            iterator_to_array($this->validate($request)),
        );
        sort($paths);
        $this->assertSame(['pity', 'pulledAt'], $paths);
    }

    public function testDeclareRequest(): void
    {
        $request = $this->deserialize(DeclarePityRequest::class, ['pity' => 74]);
        $this->assertSame(74, $request->pity);
        $this->assertCount(0, $this->validate($request));

        $cleared = $this->deserialize(DeclarePityRequest::class, ['pity' => null]);
        $this->assertNull($cleared->pity);
        $this->assertCount(0, $this->validate($cleared));

        $this->assertCount(1, $this->validate($this->deserialize(DeclarePityRequest::class, ['pity' => 201])));
    }

    /**
     * @template T of object
     * @param class-string<T>      $class
     * @param array<string, mixed> $payload
     * @return T
     */
    private function deserialize(string $class, array $payload): object
    {
        $serializer = static::getContainer()->get(SerializerInterface::class);

        return $serializer->deserialize((string) json_encode($payload), $class, 'json');
    }

    private function validate(object $object): ConstraintViolationListInterface
    {
        return static::getContainer()->get(ValidatorInterface::class)->validate($object);
    }
}
