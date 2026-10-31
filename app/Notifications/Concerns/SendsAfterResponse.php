<?php

namespace App\Notifications\Concerns;

/**
 * For "for your information" emails (order placed, status changes, security notices): the customer gets the
 * API answer first and the email is sent right after it, in the same PHP process (no worker needed). A mail
 * outage then can't slow down or fail the action; the error is reported to Sentry.
 *
 * Emails someone is waiting for (one-time codes, invitations) don't use this: they are sent during the
 * request, so a failure still answers "try again". Set QUEUE_AFTER_RESPONSE_CONNECTION=sync to send these
 * during the request too, or to a real queue (e.g. database) once a worker runs.
 */
trait SendsAfterResponse
{
    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['mail' => config('queue.after_response_connection')];
    }
}
