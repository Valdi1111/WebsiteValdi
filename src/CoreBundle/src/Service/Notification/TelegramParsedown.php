<?php

namespace App\CoreBundle\Service\Notification;

use Parsedown;

class TelegramParsedown extends Parsedown
{
    public function __construct()
    {
        // Abilita la modalità sicura: esegue l'escape di qualsiasi tag HTML inserito dall'utente
        $this->setSafeMode(true);

        // Aggiunge il supporto alla sintassi Discord per gli spoiler: ||testo||
        $this->InlineTypes['|'][] = 'Spoiler';
        $this->inlineMarkerList .= '|';
    }

    /**
     * Trasforma il Markdown inline e converte i tag web (strong/em)
     * nei tag nativi accettati da Telegram (b/i).
     */
    public function toTelegramHtml(string $text): string
    {
        // line() parsa solo inline senza avvolgere in <p>...</p>
        $html = $this->line($text);

        // Mappa i tag generati da Parsedown in quelli supportati da Telegram
        $search = [
            '<strong>', '</strong>',
            '<em>', '</em>',
            '<del>', '</del>',
        ];

        $replace = [
            '<b>', '</b>',
            '<i>', '</i>',
            '<s>', '</s>',
        ];

        return str_replace($search, $replace, $html);
    }

    /**
     * Parser custom per la sintassi spoiler di Discord ||testo||
     */
    protected function inlineSpoiler(array $excerpt): ?array
    {
        if (preg_match('/^\|\|(.+?)\|\|/s', $excerpt['text'], $matches)) {
            return [
                'extent' => strlen($matches[0]),
                'element' => [
                    'name' => 'tg-spoiler',
                    'handler' => 'line',
                    'text' => $matches[1],
                ],
            ];
        }

        return null;
    }
}