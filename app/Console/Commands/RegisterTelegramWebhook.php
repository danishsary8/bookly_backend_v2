<?php

namespace App\Console\Commands;

use App\Exceptions\TelegramBotProblem;
use App\Services\Telegram\TelegramBot;
use Illuminate\Console\Command;

/** Tells Telegram where to send the Bookly bot's messages. The container runs it at every start. */
class RegisterTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook';

    protected $description = 'Point the Bookly Telegram bot at this API (setWebhook)';

    public function handle(TelegramBot $bot): int
    {
        if (! TelegramBot::enabled()) {
            $this->info('TELEGRAM_BOT_TOKEN is empty: the Telegram bot is off.');

            return self::SUCCESS;
        }
        try {
            $bot->registerWebhook();
            $this->info('Telegram bot @'.$bot->username().' now sends its messages to '.$bot->webhookUrl());
        } catch (TelegramBotProblem $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
