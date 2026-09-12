<?php

namespace App\CoreBundle\Model\Notification;

class UniversalEmbed
{
    private ?string $title = null;
    private ?string $description = null;
    private ?int $color = null;
    private ?string $thumbnailUrl = null;
    private ?string $footerText = null;
    private ?string $footerIconUrl = null;
    private ?\DateTimeInterface $timestamp = null;

    /** @var EmbedField[] */
    private array $fields = [];

    // --- Getters & Setters ---

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getColor(): ?int
    {
        return $this->color;
    }

    public function setColor(?int $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function setThumbnailUrl(?string $thumbnailUrl): self
    {
        $this->thumbnailUrl = $thumbnailUrl;
        return $this;
    }

    public function getFooterText(): ?string
    {
        return $this->footerText;
    }

    public function setFooterText(?string $footerText): self
    {
        $this->footerText = $footerText;
        return $this;
    }

    public function getFooterIconUrl(): ?string
    {
        return $this->footerIconUrl;
    }

    public function setFooterIconUrl(?string $footerIconUrl): self
    {
        $this->footerIconUrl = $footerIconUrl;
        return $this;
    }

    public function getTimestamp(): ?\DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(?\DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * @return EmbedField[]
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function addField(string $name, string $value, ?string $icon = null, bool $inline = false): self
    {
        $this->fields[] = new EmbedField()
            ->setName($name)
            ->setValue($value)
            ->setIcon($icon)
            ->setInline($inline);
        return $this;
    }

    public function addEmbedField(EmbedField $field): self
    {
        $this->fields[] = $field;
        return $this;
    }
}