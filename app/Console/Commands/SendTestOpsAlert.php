<?php

namespace App\Console\Commands;

use App\Services\Telegram\OpsAlerts;
use Illuminate\Console\Command;

class SendTestOpsAlert extends Command
{
    protected $signature = 'ops:test-alert
                            {--chat= : Specific chat ID to test (owner, staff, or custom chat ID)}
                            {--message= : Custom message content to test}';

    protected $description = 'Send a test notification to the owner chat and staff Telegram group to verify bot configuration';

    public function handle(OpsAlerts $opsAlerts): int
    {
        $token = (string) config('services.ops_alerts.token');
        if ($token === '') {
            $this->error('OPS_ALERT_BOT_TOKEN is not configured.');
            $this->line('Add OPS_ALERT_BOT_TOKEN, OPS_ALERT_CHAT_ID, and optional OPS_STAFF_CHAT_ID in your .env or host environment.');

            return self::FAILURE;
        }

        $specificChat = $this->option('chat');
        $targets = [];

        if (filled($specificChat)) {
            $targets['Custom'] = (string) $specificChat;
        } else {
            $ownerChat = (string) config('services.ops_alerts.chat_id');
            $staffChat = (string) config('services.ops_alerts.staff_chat_id');

            if ($ownerChat !== '') {
                $targets['Owner (OPS_ALERT_CHAT_ID)'] = $ownerChat;
            }
            if ($staffChat !== '') {
                $targets['Staff Group (OPS_STAFF_CHAT_ID)'] = $staffChat;
            }
        }

        if ($targets === []) {
            $this->warn('No target chat IDs configured. Set OPS_ALERT_CHAT_ID or OPS_STAFF_CHAT_ID, or pass --chat=<id>.');

            return self::FAILURE;
        }

        $defaultMessage = implode("\n", [
            '🧪 Test alert from Bookly Operations',
            'Time: '.now()->toDateTimeString().' ('.config('app.timezone').')',
            'Status: Telegram ops alerts are active and reachable.',
            'Environment: '.config('app.env'),
        ]);

        $message = (string) ($this->option('message') ?: $defaultMessage);
        $allSuccessful = true;

        $this->info("Sending test alert via Bookly Alerts bot...\n");

        foreach ($targets as $label => $chatId) {
            $this->line("• Testing {$label} [ID: {$chatId}]...");
            $result = $opsAlerts->sendDirect($chatId, $message);

            if ($result['ok']) {
                $this->info("  ✓ Delivered successfully (HTTP {$result['status']})");
            } else {
                $allSuccessful = false;
                $reason = $result['description'] ?: 'Unknown error';
                $this->error("  ✗ Delivery failed: {$reason} (HTTP {$result['status']})");

                if (str_contains($reason, 'chat not found')) {
                    $this->comment('    Tip: Verify the chat ID. For groups, ensure the bot was added as a member and a message was sent in the group.');
                } elseif (str_contains($reason, 'bot was blocked')) {
                    $this->comment('    Tip: For private chats, the recipient must open the bot and tap Start first.');
                }
            }
        }

        return $allSuccessful ? self::SUCCESS : self::FAILURE;
    }
}
