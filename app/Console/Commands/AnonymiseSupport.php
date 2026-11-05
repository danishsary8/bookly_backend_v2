<?php

namespace App\Console\Commands;

use App\Models\SupportConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnonymiseSupport extends Command
{
    protected $signature = 'support:anonymise';

    protected $description = 'Anonymise closed support conversations after one year';

    public function handle(): int
    {
        SupportConversation::where('status', 'closed')->where('closed_at', '<', now()->subYear())->whereNull('anonymised_at')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $locked = SupportConversation::lockForUpdate()->find($row->id);
                    if (! $locked || $locked->status !== 'closed' || ! $locked->closed_at?->lt(now()->subYear()) || $locked->anonymised_at) {
                        return;
                    }
                    $locked->messages()->update(['body' => __('support.removed'), 'staff_id' => null]);
                    $locked->rating()->update(['comment' => null]);
                    DB::table('support_telegram_messages')->where('conversation_id', $locked->id)->delete();
                    DB::table('support_telegram_updates')->where('conversation_id', $locked->id)->delete();
                    $locked->fill(['customer_id' => null, 'guest_name' => null, 'guest_email' => null, 'order_id' => null, 'assigned_staff_id' => null, 'anonymised_at' => now()]);
                    $locked->version++;
                    $locked->save();
                });
            }
        });

        return self::SUCCESS;
    }
}
