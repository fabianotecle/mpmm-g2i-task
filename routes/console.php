<?php

use App\Services\Installments\InstallmentService;
use App\Services\RotativeWallet\RotativeCreditService;
use App\Services\WhatsApp\WhatsappConversationSender;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('whatsapp:retry-notifications {--limit=100}', function (WhatsappConversationSender $sender) {
    $count = $sender->retryPending((int) $this->option('limit'));
    $this->info("Processed {$count} pending WhatsApp notification(s).");
})->purpose('Retry failed transfer notifications');

Schedule::command('whatsapp:retry-notifications')->everyMinute()->withoutOverlapping();

Artisan::command('whatsapp:retry-ordered-menus', function (WhatsappConversationSender $sender) {
    $this->info('Checked '.$sender->retryOrderedMenus().' pending ordered menu(s).');
})->purpose('Send secondary menus after the primary menu is delivered');

Schedule::command('whatsapp:retry-ordered-menus')->everyMinute()->withoutOverlapping();

Artisan::command('rotative-credit:release-eligible', function (RotativeCreditService $credits) {
    $count = $credits->releaseEligibleLots();
    $this->info("Released {$count} eligible rotative lot(s).");
})->purpose('Convert paid and time-eligible rotative credit lots into debit balance');

Schedule::command('rotative-credit:release-eligible')->everyMinute()->withoutOverlapping();

Artisan::command('installments:process-due', function (InstallmentService $installments) {
    $result = $installments->processDue();
    $this->info("Paid {$result['paid']} installment(s); failed {$result['failed']}.");
})->purpose('Process accepted installment payments when due');

Schedule::command('installments:process-due')->everyMinute()->withoutOverlapping();

Artisan::command('installments:expire-pending', function (InstallmentService $installments) {
    $this->info('Expired '.$installments->expirePending().' installment proposal(s).');
})->purpose('Cancel unanswered installment proposals after 15 minutes');

Schedule::command('installments:expire-pending')->everyMinute()->withoutOverlapping();

