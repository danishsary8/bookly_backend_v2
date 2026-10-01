<?php

namespace App\Support;

use Illuminate\Support\Str;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Adds just enough context to an error report to find the request in the logs (request ID) and to
 * know who hit it (user id + type) — no names, emails, IP addresses, cookies or request bodies.
 */
final class SentryBeforeSend
{
    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($id = $request?->attributes->get('request_id')) {
            $event->setTag('request_id', $id);
        }

        $user = $request?->user();
        $event->setUser($user ? UserDataBag::createFromUserIdentifier((string) $user->getKey()) : null);
        if ($user) {
            $event->setTag('user_type', Str::snake(class_basename($user)));
        }

        $data = $event->getRequest();
        unset($data['cookies'], $data['data'], $data['env']);
        if (isset($data['headers'])) {
            $data['headers'] = array_diff_key($data['headers'], array_flip(['authorization', 'cookie', 'x-xsrf-token']));
        }
        $event->setRequest($data);

        return $event;
    }
}
