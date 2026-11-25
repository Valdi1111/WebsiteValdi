<?php

namespace App\GamesBundle\Model\Status;

/**
 * Data of one section for one profile, or the error that kept it from being read. The error is for logs and
 * diagnostics only: provider messages can hold URLs or response bodies, so views show a generic line instead.
 */
final readonly class SectionResult
{
    private function __construct(
        public ProfileSectionInterface $section,
        public ?object $data,
        public ?\Throwable $error,
    ) {
    }

    public static function success(ProfileSectionInterface $section, object $data): self
    {
        return new self($section, $data, null);
    }

    public static function failure(ProfileSectionInterface $section, \Throwable $error): self
    {
        return new self($section, null, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }
}
