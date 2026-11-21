<?php

namespace App\Console\Commands;

use App\Models\WhatsappNotificationDelivery;
use App\Services\WhatsApp\WhatsappTemplateHistory;
use Illuminate\Console\Command;

class BackfillWhatsappTemplateHistory extends Command
{
    protected $signature = 'mpmm:backfill-whatsapp-template-history {--dry-run}';

    protected $description = 'Recover accepted WhatsApp templates missing from the member conversation history';

    public function handle(WhatsappTemplateHistory $history): int
    {
        $eligible = 0;
        $created = 0;

        WhatsappNotificationDelivery::query()
            ->whereNotNull('delivered_steps')
            ->chunkById(500, function ($deliveries) use ($history, &$eligible, &$created) {
                foreach ($deliveries as $tracking) {
                    $notification = $tracking->notification ?? [];
                    foreach (['welcome_template', 'transfer_template'] as $step) {
                        if (! in_array($step, $tracking->delivered_steps ?? [], true)) {
                            continue;
                        }

                        $eligible++;
                        if ($this->option('dry-run')) {
                            continue;
                        }

                        $name = $step === 'welcome_template'
                            ? config('services.whatsapp.start_template', 'boas_vindas')
                            : ($notification['template_name'] ?? null);
                        if (! $name) {
                            continue;
                        }

                        $message = $history->record(
                            (string) $tracking->recipient_phone,
                            (string) $name,
                            $step === 'transfer_template' ? ($notification['template_components'] ?? []) : [],
                            $tracking,
                            $step,
                            null,
                            $tracking->sent_at ?? $tracking->last_attempted_at ?? $tracking->created_at,
                            recovered: true,
                            timeEstimated: $tracking->sent_at === null,
                        );
                        if ($message?->wasRecentlyCreated) {
                            $created++;
                        }
                    }
                }
            });

        $this->info("Eligible template sends: {$eligible}; recovered: {$created}.");

        return self::SUCCESS;
    }
}
