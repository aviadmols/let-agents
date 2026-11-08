<?php

namespace App\Modules\Admin\Contracts;

/**
 * The link that opens a reply to a waiting shopper, the same for every module that keeps requests (ShopperRequests): a
 * WhatsApp chat to the phone, or an email, either one with a greeting and the shopper's question quoted, in the panel's
 * language. The phone is in international digits (972501234567), as the modules keep it.
 */
final class ReplyLink
{
    public static function make(string $channel, string $contact, ?string $name, string $question): ?string
    {
        $contact = trim($contact);
        $name = trim((string) $name);
        $text = __($name === '' ? 'admin::shoppers.reply.text_plain' : 'admin::shoppers.reply.text', ['name' => $name, 'question' => trim($question)]);

        if ($channel === 'email') {
            return filter_var($contact, FILTER_VALIDATE_EMAIL) === false ? null
                : 'mailto:'.$contact.'?subject='.rawurlencode(__('admin::shoppers.reply.subject')).'&body='.rawurlencode($text);
        }

        $digits = preg_replace('/\D/', '', $contact) ?? '';

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1 ? 'https://wa.me/'.$digits.'?text='.rawurlencode($text) : null;
    }
}
