<?php

namespace App\GamesBundle\Model\Pull\Manual;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST .../pulls/manual (operation B). Keys are snake_case in JSON (global name converter).
 */
final readonly class CreateManualPullRequest
{
    /**
     * @param string   $banner         Banner group key
     * @param string   $pulledAt       Wall-clock date without offset (ManualPull::DATE_FORMAT), parsed by the provider
     * @param int|null $anchorId       S whose window receives the new S (null = the open window after the last S)
     * @param bool     $allowDuplicate Confirms another copy when a matching recorded S exists
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $banner,
        #[Assert\NotBlank, Assert\Length(max: 128)]
        public string $name,
        #[Assert\NotBlank, Assert\DateTime(format: ManualPull::DATE_FORMAT)]
        public string $pulledAt,
        #[Assert\Range(min: 1, max: 200)]
        public int $pity,
        #[Assert\Positive]
        public ?int $anchorId = null,
        #[Assert\Length(max: 64)]
        public ?string $itemId = null,
        #[Assert\Length(max: 32)]
        public ?string $itemType = null,
        public bool $allowDuplicate = false,
    ) {
    }
}
