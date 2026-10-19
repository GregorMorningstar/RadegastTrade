<?php

namespace App\Support;

/**
 * Text written by a person (a name, a message, a reason) before it goes into an e-mail we send in the platform's name.
 * The mail templates render Markdown, so without this a message could carry a link or formatting and make the mail look
 * like an instruction from the platform (phishing). The result is plain text on one line, without links.
 */
final class MailText
{
    public static function plain(?string $text, int $limit = 300): string
    {
        $text = strip_tags((string) $text);

        // addresses and the characters Markdown (and HTML) is made of
        $text = preg_replace('~(?:https?://|ftp://|www\.)\S+~i', '[link usunięty]', $text) ?? '';
        $text = preg_replace('/[\[\]()<>`*_#|~\\\\]/u', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }
}
