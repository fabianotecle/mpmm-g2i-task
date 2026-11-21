<?php

namespace App\Services\Installments;

use App\Models\Customer;
use App\Models\InstallmentPayment;
use App\Models\InstallmentProposal;
use App\Models\WhatsappNotificationDelivery;
use App\Services\Conversation\ConversationResponse;
use App\Services\Phone\PhoneNumberFormatter;
use App\Services\RotativeWallet\RotativeCreditService;
use App\Services\WhatsApp\WhatsappConversationSender;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class InstallmentService
{
    public const MAX_INSTALLMENTS = 12;

    public function __construct(
        private readonly RotativeCreditService $wallet,
        private readonly PhoneNumberFormatter $phoneFormatter,
    ) {}

    public function availableAmount(?Customer $customer): float
    {
        return $this->wallet->availableToTransfer($customer);
    }

    public function intervalDays(): int
    {
        if (config('mpmm.installments.schedule_type') === 'quarter_hour') {
            return 7;
        }

        return max(1, min(255, (int) config('mpmm.installments.interval_days', 7)));
    }

    public function scheduleType(): string
    {
        return match (config('mpmm.installments.schedule_type')) {
            'monthly' => 'monthly',
            'minute' => 'minute',
            default => 'daily',
        };
    }

    public function scheduleTimezone(Customer $customer): string
    {
        $timezone = $customer->timezone ?: 'America/Sao_Paulo';
        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception) {
            return 'America/Sao_Paulo';
        }
    }

    public function propose(Customer $sender, Customer $recipient, float $amount, int $count, bool $fromTransfer = false,
        ?string $scheduleType = null, ?int $intervalDays = null): InstallmentProposal
    {
        $scheduleType ??= $this->scheduleType();
        $intervalDays ??= $this->intervalDays();
        if (! in_array($scheduleType, ['daily', 'monthly', 'minute'], true)
            || $intervalDays < 1 || $intervalDays > 255) {
            throw new InvalidArgumentException('Prazo de parcelamento inválido.');
        }
        $cents = (int) round($amount * 100);
        if ($sender->id === $recipient->id || abs($amount - $cents / 100) > 0.000001
            || $cents < ($fromTransfer ? 2 : 200)
            || $count < 2 || $count > self::MAX_INSTALLMENTS || $cents < $count) {
            throw new InvalidArgumentException('Proposta de parcelamento inválida ou fora do teste.');
        }

        if ($cents > (int) round($this->availableAmount($sender->fresh()) * 100)) {
            throw new InvalidArgumentException('Saldo disponível insuficiente para o valor total da proposta.');
        }

        return InstallmentProposal::create([
            'sender_customer_id' => $sender->id,
            'recipient_customer_id' => $recipient->id,
            'amount' => $cents / 100,
            'installments' => $count,
            'interval_days' => $intervalDays,
            'schedule_type' => $scheduleType,
            'schedule_timezone' => $this->scheduleTimezone($sender),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function createAndConfirm(Customer $sender, Customer $recipient, float $amount, int $count,
        bool $fromTransfer = false, ?string $scheduleType = null, ?int $intervalDays = null): InstallmentProposal
    {
        return DB::transaction(function () use ($sender, $recipient, $amount, $count, $fromTransfer, $scheduleType, $intervalDays) {
            $customers = Customer::whereIn('id', [$sender->id, $recipient->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedSender = $customers->get($sender->id);
            $lockedRecipient = $customers->get($recipient->id);
            if (! $lockedSender || ! $lockedRecipient) {
                throw new InvalidArgumentException('Membro não encontrado.');
            }

            $proposal = $this->propose($lockedSender, $lockedRecipient, $amount, $count,
                $fromTransfer, $scheduleType, $intervalDays);

            return $this->decide($proposal, $lockedRecipient, true);
        });
    }

    public function decide(InstallmentProposal $proposal, Customer $recipient, bool $accept): InstallmentProposal
    {
        return DB::transaction(function () use ($proposal, $recipient, $accept) {
            $proposal = InstallmentProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->recipient_customer_id !== $recipient->id
                || $proposal->status !== 'pending') {
                throw new InvalidArgumentException('Esta proposta não está disponível para aceite.');
            }

            if ($this->deadline($proposal)->lte(now())) {
                $this->expireLocked($proposal);

                return $proposal->fresh();
            }

            $acceptedAt = now();
            $entries = $accept ? $this->schedule((float) $proposal->amount, $proposal->installments,
                $acceptedAt, $proposal->interval_days, $proposal->schedule_type,
                $proposal->schedule_timezone) : [];
            $customers = null;
            if ($accept) {
                $customers = Customer::whereIn('id', [$proposal->sender_customer_id, $proposal->recipient_customer_id])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $sender = $customers->get($proposal->sender_customer_id);
                if (! $sender || $this->availableAmount($sender) + 0.000001 < $entries[0]['amount']) {
                    throw new InvalidArgumentException('Saldo disponível insuficiente para a primeira parcela. A proposta continua pendente.');
                }
            }
            $proposal->forceFill([
                'status' => $accept ? 'accepted' : 'rejected',
                'decided_at' => $acceptedAt,
                'accepted_at' => $accept ? $acceptedAt : null,
            ])->save();

            if ($accept) {
                foreach ($entries as $entry) {
                    $payment = InstallmentPayment::create([
                        'installment_proposal_id' => $proposal->id,
                        'number' => $entry['number'],
                        'amount' => $entry['amount'],
                        'due_at' => $entry['due_at'],
                        'status' => 'pending',
                    ]);
                    if ($entry['number'] === 1) {
                        $this->settle($proposal, $payment, $sender, $customers->get($proposal->recipient_customer_id));
                    }
                }
            }

            return $proposal->fresh();
        });
    }

    public function expirePending(int $limit = 100): int
    {
        $ids = InstallmentProposal::query()->where('status', 'pending')
            ->where(function ($query) {
                $query->where('expires_at', '<=', now())
                    ->orWhere(fn ($legacy) => $legacy->whereNull('expires_at')
                        ->where('created_at', '<=', now()->subMinutes(15)));
            })
            ->orderBy('id')->limit($limit)->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$expired): void {
                $proposal = InstallmentProposal::whereKey($id)->lockForUpdate()->first();
                if ($proposal && $proposal->status === 'pending' && $this->deadline($proposal)->lte(now())) {
                    $this->expireLocked($proposal);
                    $expired++;
                }
            });
        }

        return $expired;
    }

    public function pendingNotificationsAfterInbound(Customer $recipient): array
    {
        $legacy = InstallmentProposal::query()
            ->where('recipient_customer_id', $recipient->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('id')->limit(5)->get()
            ->filter(function (InstallmentProposal $proposal) use ($recipient): bool {
                return ! \App\Models\ConversationMessage::query()
                    ->where('direction', 'inbound')
                    ->where('external_channel', 'whatsapp')
                    ->whereHas('conversationSession', fn ($query) => $query
                        ->where('channel', 'whatsapp')->where('external_user_id', $recipient->phone))
                    ->where('external_timestamp', '>', $proposal->created_at->copy()->subHours(24))
                    ->where('external_timestamp', '<', $proposal->created_at)
                    ->exists();
            })
            ->map(function (InstallmentProposal $proposal) use ($recipient): ?array {
                $notification = WhatsappNotificationDelivery::query()
                    ->where('notification_key', 'installment-proposal:'.$proposal->id)
                    ->where('recipient_phone', $recipient->phone)
                    ->first()?->notification;
                if (! is_array($notification) || empty($notification['messages'])) {
                    return null;
                }

                $notification['notification_key'] = 'installment-proposal-inbound:'.$proposal->id;

                return $notification;
            })->filter()->values()->all();

        $plans = WhatsappNotificationDelivery::query()
            ->where('recipient_phone', $recipient->phone)
            ->where('notification_key', 'like', 'installment-plan:%')
            ->whereIn('status', ['pending', 'failed'])
            ->orderBy('id')->limit(10)->get()
            ->pluck('notification')->filter(fn ($notification) => is_array($notification))->all();

        return [...$legacy, ...$plans];
    }

    private function deadline(InstallmentProposal $proposal): Carbon
    {
        return $proposal->expires_at ?? $proposal->created_at->copy()->addMinutes(15);
    }

    private function expireLocked(InstallmentProposal $proposal): void
    {
        $proposal->forceFill([
            'status' => 'expired',
            'expires_at' => $this->deadline($proposal),
            'decided_at' => now(),
        ])->save();

        WhatsappNotificationDelivery::query()
            ->whereIn('notification_key', [
                'installment-proposal:'.$proposal->id,
                'installment-proposal-inbound:'.$proposal->id,
            ])
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'expired']);

        $proposal->loadMissing(['sender', 'recipient']);
        foreach ([$proposal->sender, $proposal->recipient] as $customer) {
            $notification = [
                'channel' => 'whatsapp',
                'to' => $customer->phone,
                'notification_key' => 'installment-expired:'.$proposal->id,
                'messages' => [ConversationResponse::text($this->decisionSummary($proposal, $customer))],
            ];
            WhatsappNotificationDelivery::firstOrCreate(
                ['notification_key' => $notification['notification_key'], 'recipient_phone' => $customer->phone],
                ['notification' => $notification, 'status' => 'pending', 'next_attempt_at' => now()],
            );
        }
    }

    public function decisionSummary(InstallmentProposal $proposal, ?Customer $viewer = null): string
    {
        $proposal->loadMissing(['sender', 'recipient']);
        if ($proposal->status === 'expired') {
            $counterparty = $viewer?->id === $proposal->recipient->id
                ? $proposal->sender : $proposal->recipient;
            $direction = $viewer?->id === $proposal->recipient->id ? 'DE' : 'PARA';

            return "🗓 Crédito parcelado *Cancelado*\n\n"
                ."Proposta #{$proposal->id}\n"
                .'Valor total: *R$* '.number_format((float) $proposal->amount, 2, ',', '.')."\n"
                ."em {$proposal->installments} parcelas\n\n"
                ."{$direction}:\n"
                .$this->phoneFormatter->international($counterparty->phone)."\n"
                .($counterparty->whatsappDisplayName() ?: 'Membro')."\n\n"
                .'*Cancelada* pelo MpMM® porque não teve resposta dentro do limite de 15 minutos.';
        }

        $recipientName = $proposal->recipient->whatsappDisplayName() ?: 'Membro';
        $recipientPhone = $this->phoneFormatter->international($proposal->recipient->phone);
        $details = "A proposta #{$proposal->id} para {$recipientPhone}, {$recipientName}, "
            .'no valor total de R$ '.number_format((float) $proposal->amount, 2, ',', '.')
            .", em {$proposal->installments} parcelas, ";

        return match ($proposal->status) {
            'accepted' => "🗓 Crédito parcelado *Aceito*\n{$details}foi *ACEITA*.",
            'rejected' => "🗓 Crédito parcelado *Cancelado*\n{$details}foi *rejeitada*.",
            default => '',
        };
    }

    public function schedule(float $total, int $count, Carbon $acceptedAt, int $intervalDays,
        string $scheduleType, string $scheduleTimezone): array
    {
        $cents = (int) round($total * 100);
        if ($count < 2 || $cents < $count) {
            throw new InvalidArgumentException('Cada parcela deve valer pelo menos R$ 0,01.');
        }
        $regular = intdiv($cents + $count - 1, $count);
        $last = $cents - ($count - 1) * $regular;
        $base = intdiv($cents, $count);
        $remainder = $cents % $count;
        $entries = [];

        for ($number = 1; $number <= $count; $number++) {
            $entries[] = [
                'number' => $number,
                'amount' => ($last > 0
                    ? ($number === $count ? $last : $regular)
                    : $base + ($number <= $remainder ? 1 : 0)) / 100,
                'due_at' => $scheduleType === 'monthly'
                    ? $acceptedAt->copy()->timezone($scheduleTimezone)->addMonthsNoOverflow($number - 1)->timezone('UTC')
                    : (in_array($scheduleType, ['minute', 'quarter_hour'], true)
                        ? $acceptedAt->copy()->addMinutes(($number - 1) * ($scheduleType === 'quarter_hour' ? 15 : 1))
                        : $acceptedAt->copy()->timezone($scheduleTimezone)
                            ->addDays(($number - 1) * $intervalDays)->timezone('UTC')),
            ];
        }

        return $entries;
    }

    public function scheduleText(float $total, int $count, Carbon $acceptedAt,
        string $timezone, int $intervalDays, string $scheduleType, string $scheduleTimezone): string
    {
        $rows = collect($this->schedule($total, $count, $acceptedAt, $intervalDays,
            $scheduleType, $scheduleTimezone))
            ->map(fn (array $entry) => $entry['number'].'  '
                .$entry['due_at']->copy()->timezone($timezone)
                    ->format(in_array($scheduleType, ['minute', 'quarter_hour'], true) ? 'd/m/Y H:i' : 'd/m/Y')
                .'  R$ '.number_format($entry['amount'], 2, ',', '.'))
            ->implode("\n");

        return "Parcelas:\nNº  Data  Valor\n{$rows}";
    }

    public function processDue(int $limit = 100): array
    {
        $result = ['paid' => 0, 'failed' => 0];
        $ids = InstallmentPayment::query()->where('status', 'pending')
            ->where('due_at', '<=', now())->orderBy('due_at')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $outcome = DB::transaction(function () use ($id) {
                $proposalId = InstallmentPayment::whereKey($id)->value('installment_proposal_id');
                if (! $proposalId) return null;

                $proposal = InstallmentProposal::whereKey($proposalId)->lockForUpdate()->first();
                $payment = InstallmentPayment::whereKey($id)->lockForUpdate()->first();
                if (! $proposal || ! $payment || $proposal->status !== 'accepted'
                    || $payment->status !== 'pending' || $payment->due_at->isFuture()) {
                    return null;
                }

                $customers = Customer::whereIn('id', [$proposal->sender_customer_id, $proposal->recipient_customer_id])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $sender = $customers->get($proposal->sender_customer_id);
                $recipient = $customers->get($proposal->recipient_customer_id);
                $amount = (float) $payment->amount;
                $available = max(0, (float) $sender->balance);

                if ($amount > $available + 0.000001) {
                    $payment->forceFill(['status' => 'failed', 'processed_at' => now()])->save();
                    InstallmentPayment::where('installment_proposal_id', $proposal->id)
                        ->where('status', 'pending')->update(['status' => 'cancelled']);
                    $proposal->forceFill(['status' => 'failed'])->save();

                    return 'failed';
                }

                $this->settle($proposal, $payment, $sender, $recipient);

                if (! InstallmentPayment::where('installment_proposal_id', $proposal->id)
                    ->where('status', 'pending')->exists()) {
                    $proposal->forceFill(['status' => 'completed'])->save();
                }

                return 'paid';
            });

            if ($outcome !== null) $result[$outcome]++;
            if ($outcome === 'paid') {
                $notifications = WhatsappNotificationDelivery::whereIn('notification_key', [
                    "installment-payment:{$id}:sender",
                    "installment-payment:{$id}:recipient",
                ])->get()->pluck('notification')->all();
                if ($notifications !== []) {
                    try {
                        app(WhatsappConversationSender::class)->sendNotifications(['notifications' => $notifications]);
                    } catch (Throwable $exception) {
                        // The committed transfer and its outbox entries remain available for retry.
                        Log::error('Installment notification delivery failed.', [
                            'payment_id' => $id,
                            'exception' => class_basename($exception),
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $result;
    }

    private function settle(InstallmentProposal $proposal, InstallmentPayment $payment,
        Customer $sender, Customer $recipient): void
    {
        $metadata = ['installment_proposal_id' => $proposal->id,
            'installment_payment_id' => $payment->id, 'installment_number' => $payment->number];
        $movements = $this->wallet->transfer($sender, $recipient, (float) $payment->amount,
            ['metadata' => $metadata + ['recipient' => $recipient->phone]],
            ['metadata' => $metadata + ['sender_customer_id' => $sender->id]]);
        $received = $movements['received'];
        $received->forceFill(['metadata' => [
            ...($received->metadata ?? []),
            'source_movement_id' => $movements['sent']->id,
        ]])->save();
        $payment->forceFill([
            'status' => 'paid', 'processed_at' => now(),
            'sent_movement_id' => $movements['sent']->id,
            'received_movement_id' => $received->id,
        ])->save();
        if ($payment->number > 1) {
            $this->queuePaymentNotifications($proposal, $payment, $sender, $recipient);
        }
    }

    private function queuePaymentNotifications(InstallmentProposal $proposal, InstallmentPayment $payment,
        Customer $sender, Customer $recipient): void
    {
        foreach ([
            'sender' => [
                'customer' => $sender,
                'message' => $this->sentPaymentMessage($proposal, $payment),
            ],
            'recipient' => [
                'customer' => $recipient,
                'message' => $this->receivedPaymentMessage($proposal, $payment),
            ],
        ] as $side => $notice) {
            $notification = [
                'channel' => 'whatsapp',
                'to' => $notice['customer']->phone,
                'notification_key' => "installment-payment:{$payment->id}:{$side}",
                'messages' => [$notice['message']],
            ];
            WhatsappNotificationDelivery::firstOrCreate(
                ['notification_key' => $notification['notification_key'],
                    'recipient_phone' => $notification['to']],
                ['notification' => $notification, 'status' => 'pending',
                    'next_attempt_at' => now()],
            );
        }
    }

    public function sentPaymentMessage(InstallmentProposal $proposal, InstallmentPayment $payment): array
    {
        $proposal->loadMissing(['sender', 'recipient']);
        $next = $proposal->payments()->where('number', '>', $payment->number)->orderBy('number')->first();
        $dateFormat = in_array($proposal->schedule_type, ['minute', 'quarter_hour'], true) ? 'd/m/Y H:i' : 'd/m/Y';
        $nextLine = $next
            ? '*Próxima parcela em '.$next->due_at->copy()
                ->timezone($proposal->sender->timezone ?: 'America/Sao_Paulo')->format($dateFormat).'*'
            : '*Parcelamento concluído.*';
        return ConversationResponse::options(
            "↗️ *Transferência Enviada*\n\n"
            ."DE:\n".$this->phoneFormatter->international($proposal->sender->phone)."\n"
            .($proposal->sender->whatsappDisplayName() ?: 'Membro')."\n\n"
            ."PARA:\n".$this->phoneFormatter->international($proposal->recipient->phone)."\n"
            .($proposal->recipient->whatsappDisplayName() ?: 'Membro')."\n\n"
            ."Parcela {$payment->number} de {$proposal->installments}\n\n{$nextLine}\n\n"
            ."_Cumprir os compromissos assumidos fortalece a confiança e estreita relações._\n\n"
            ."*MyPhone MyMoney®*\n💬 0800 000 0000",
            $this->paymentActionOptions(),
        );
    }

    public function receivedPaymentMessage(InstallmentProposal $proposal, InstallmentPayment $payment): array
    {
        $proposal->loadMissing('sender');
        $amount = number_format((float) $payment->amount, 2, ',', '.');
        $senderPhone = $this->phoneFormatter->international($proposal->sender->phone);
        $senderName = $proposal->sender->whatsappDisplayName() ?: 'Membro';
        $completion = $payment->number === $proposal->installments ? "\nParcelamento concluído.\n" : "\n";

        return ConversationResponse::options(
            "↘️ *Transferência recebida!*\n\n"
            ."R$ {$amount}\n\n"
            ."DE:\n{$senderPhone}\n{$senderName}\n\n"
            ."Proposta #{$proposal->id}\n"
            ."Parcela {$payment->number} de {$proposal->installments}\n"
            .$completion
            ."Saiba Mais em:\n*MyPhone MyMoney®*\n💬 0800 000 0000",
            [
                ['label' => '🏡 Voltar ao menu', 'value' => 'continuar_mpmm'],
                ['label' => '↗️ Transferir', 'value' => 'transferir'],
            ],
        );
    }

    private function paymentActionOptions(): array
    {
        return [
            ['label' => '🏡 Voltar ao Menu', 'value' => 'continuar_mpmm'],
            ['label' => '↗️ Transferir', 'value' => 'transferir'],
            ['label' => '🔳 Gerar QR Code', 'value' => 'gerar_qr_code'],
        ];
    }
}
