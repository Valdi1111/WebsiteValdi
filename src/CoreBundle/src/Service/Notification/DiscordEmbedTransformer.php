<?php

namespace App\CoreBundle\Service\Notification;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use Symfony\Component\Notifier\Bridge\Discord\Embeds\DiscordEmbed;
use Symfony\Component\Notifier\Bridge\Discord\Embeds\DiscordFieldEmbedObject;
use Symfony\Component\Notifier\Bridge\Discord\Embeds\DiscordFooterEmbedObject;
use Symfony\Component\Notifier\Bridge\Discord\Embeds\DiscordMediaEmbedObject;

class DiscordEmbedTransformer
{
    public function transform(UniversalEmbed $embed): DiscordEmbed
    {
        $discordEmbed = new DiscordEmbed();

        if ($embed->getTitle() !== null) {
            $discordEmbed->title($embed->getTitle());
        }

        if ($embed->getDescription() !== null) {
            $discordEmbed->description($embed->getDescription());
        }

        if ($embed->getColor() !== null) {
            $discordEmbed->color($embed->getColor());
        }

        if ($embed->getThumbnailUrl() !== null) {
            $discordEmbed->thumbnail(new DiscordMediaEmbedObject()->url($embed->getThumbnailUrl()));
        }

        if ($embed->getFooterText() !== null) {
            $footer = new DiscordFooterEmbedObject()->text($embed->getFooterText());
            if ($embed->getFooterIconUrl() !== null) {
                $footer->iconUrl($embed->getFooterIconUrl());
            }
            $discordEmbed->footer($footer);
        }

        if ($embed->getTimestamp() !== null) {
            $discordEmbed->timestamp($embed->getTimestamp());
        }

        foreach ($embed->getFields() as $field) {
            $value = $field->getValue();

            // Su Discord: Icona PRIMA del value
            if ($field->getIcon() !== null && $field->getIcon() !== '') {
                $value = sprintf("%s\u{00A0}\u{00A0}%s", $field->getIcon(), $value);
            }

            $discordEmbed->addField(
                new DiscordFieldEmbedObject()
                    ->name($field->getName())
                    ->value($value)
                    ->inline($field->isInline())
            );
        }

        return $discordEmbed;
    }
}