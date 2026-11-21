<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\InstallmentPayment;
use App\Models\InstallmentProposal;
use App\Models\WhatsappNotificationDelivery;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Installments\InstallmentService;
use App\Services\Phone\PhoneNumberNormalizer;
use App\Services\Phone\PhoneNumberFormatter;
use InvalidArgumentException;

class InstallmentFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly InstallmentService $installments,
        private readonly PhoneNumberNormalizer $phones,
        private readonly PhoneNumberFormatter $phoneFormatter,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->loadMissing('customer');
        if (! $session->customer) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        $latest = InstallmentProposal::where('sender_customer_id', $session->customer_id)
            ->latest('id')->first();
        if ($latest?->status === 'accepted' && $latest->payments()->where('number', 1)->where('status', 'paid')->exists()
            && $latest->payments()->where('number', '>', 1)->where('status', 'pending')->exists()) {
            $session->forceFill([
                'current_step' => 'waiting_status_ack',
                'context' => ['installment_proposal_id' => $latest->id],
            ])->save();

            return ConversationResponse::make([
                ConversationResponse::options("🗓️ *Crédito Parcelado*\n\n"
                    ."O parcelamento #{$latest->id} foi confirmado no *MyPhone MyMoney®*.\n\n"
                    ."A primeira parcela foi transferida e as restantes estão agendadas.\n\n"
                    ."Lembre-se:\n"
                    ."*Cumprir os compromissos assumidos fortalece a confiança e estreita relações.*", [
                        ['label' => '🏡 Voltar ao Menu', 'value' => 'continuar_mpmm'],
                    ]),
            ], ['type' => 'option', 'field' => 'installment_status_ack']);
        }

        return $this->askAmount($session);
    }

    public function enterFromTransfer(ConversationSession $session, float $amount, ?Customer $recipient): array
    {
        $session->loadMissing('customer');
        $cents = (int) round($amount * 100);
        $availableCents = (int) round($this->installments->availableAmount($session->customer?->fresh()) * 100);

        if (! $session->customer) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        if ($cents < 2 || $cents > $availableCents || abs($amount - $cents / 100) > 0.000001
            || ! $recipient || $recipient->id === $session->customer_id) {
            return ConversationResponse::make([
                ConversationResponse::text('Para parcelar esta transferência, escolha um valor de pelo menos R$ 0,02 e dentro do saldo disponível.'),
            ], ['type' => 'option', 'field' => 'transfer_confirmation']);
        }

        $session->forceFill([
            'current_flow' => 'installment',
            'current_step' => 'waiting_installment_count',
            'context' => [
                'installment_total_cents' => $cents,
                'recipient_customer_id' => $recipient->id,
                'from_transfer' => true,
            ],
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text($this->countPrompt($cents)),
        ], ['type' => 'number', 'field' => 'installment_count', 'min' => 2,
            'max' => min(InstallmentService::MAX_INSTALLMENTS, $cents)]);
    }

    public function decideFromShortcut(ConversationSession $session, int $proposalId, bool $accept): array
    {
        $session->forceFill([
            'current_flow' => 'installment',
            'current_step' => 'waiting_decision',
            'context' => ['installment_proposal_id' => $proposalId],
        ])->save();

        return $this->decide($session, $accept);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);
        if ($choice === 'voltar_menu' || str_contains($choice, 'voltar ao menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }
        if ($choice === 'mudar' && in_array($session->current_step, ['waiting_installment_count', 'waiting_preview'], true)) {
            return $this->changeProposal($session);
        }

        if (preg_match('/^parcelado_(aceitar|recusar)_(\d+)$/', $choice, $matches)) {
            return $this->decideFromShortcut($session, (int) $matches[2], $matches[1] === 'aceitar');
        }
        if (in_array($choice, ['nova_proposta', 'nova proposta', 'novo parcelado', 'propor'], true)) {
            return $this->askAmount($session);
        }

        return match ($session->current_step) {
            'empty' => $this->enter($session),
            'waiting_installment_amount' => $this->captureAmount($session, $message),
            'waiting_installment_count' => $this->captureCount($session, $message),
            'waiting_recipient' => $this->captureRecipient($session, $message),
            'waiting_schedule' => $this->captureSchedule($session, $choice),
            'waiting_preview' => $this->confirmPreview($session, $choice),
            'waiting_sent_ack' => ConversationResponse::make([], null, null, 'main_menu'),
            'waiting_status_ack' => ConversationResponse::make([], null, null, 'main_menu'),
            'waiting_decision' => in_array($choice, ['aceitar', 'recusar'], true)
                ? $this->decide($session, $choice === 'aceitar') : $this->enter($session),
            default => $this->enter($session),
        };
    }

    private function askAmount(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_installment_amount', 'context' => []])->save();

        $available = $this->installments->availableAmount($session->customer?->fresh());
        if ($available < 2) {
            return ConversationResponse::make([
                ConversationResponse::text('Seu saldo disponível é de R$ '
                    .number_format($available, 2, ',', '.')
                    .'. É necessário pelo menos R$ 2,00 para propor um parcelado.'),
            ]);
        }

        return ConversationResponse::make([
            ConversationResponse::text($this->amountPrompt($available)),
        ], ['type' => 'money', 'field' => 'installment_total', 'min' => 2, 'max' => $available]);
    }

    private function changeProposal(ConversationSession $session): array
    {
        $context = $session->context ?? [];
        if (! ($context['from_transfer'] ?? false)) {
            return $this->askAmount($session);
        }

        return $this->enterFromTransfer(
            $session,
            ((int) ($context['installment_total_cents'] ?? 0)) / 100,
            Customer::find($context['recipient_customer_id'] ?? null),
        );
    }

    private function captureAmount(ConversationSession $session, string $message): array
    {
        $amount = $this->moneyFromMessage($message);
        $cents = $amount === null ? 0 : (int) round($amount * 100);
        $available = $this->installments->availableAmount($session->customer?->fresh());
        if ($cents < 200 || $cents > (int) round($available * 100)
            || abs(($amount ?? 0) - $cents / 100) > 0.000001) {
            return ConversationResponse::make([
                ConversationResponse::text($this->amountPrompt($available)),
            ], ['type' => 'money', 'field' => 'installment_total', 'min' => 2, 'max' => $available]);
        }

        $session->forceFill([
            'current_step' => 'waiting_installment_count',
            'context' => ['installment_total_cents' => $cents],
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text($this->countPrompt($cents)),
        ], ['type' => 'number', 'field' => 'installment_count', 'min' => 2, 'max' => InstallmentService::MAX_INSTALLMENTS]);
    }

    private function countPrompt(int $cents): string
    {
        return "🔢 Em *QUANTAS* parcelas?\n\nDigite o nº de 2 a ".min(InstallmentService::MAX_INSTALLMENTS, $cents).'.';
    }

    private function amountPrompt(float $available): string
    {
        return "💳 Crédito Parcelado\n\nQual o *valor total* da proposta?\n\n"
            .'*De momento seu limite disponível no MyPhone MyMoney®* é de R$ '
            .number_format($available, 2, ',', '.').'.';
    }

    private function captureCount(ConversationSession $session, string $message): array
    {
        $count = trim($message);
        $cents = (int) (($session->context ?? [])['installment_total_cents'] ?? 0);
        $maximum = min(InstallmentService::MAX_INSTALLMENTS, $cents);
        if (! ctype_digit($count) || (int) $count < 2 || (int) $count > $maximum) {
            return ConversationResponse::make([
                ConversationResponse::text('Digite o nº de 2 a '.$maximum.'; cada parcela deve valer pelo menos R$ 0,01. Você pode escolher outra quantidade ou tocar em Mudar para recomeçar.'),
                ConversationResponse::options('Escolha uma opção:', [
                    ['label' => '🔄 Mudar', 'value' => 'mudar'],
                ]),
            ], ['type' => 'number', 'field' => 'installment_count', 'min' => 2, 'max' => $maximum]);
        }

        $context = $session->context ?? [];
        $context['installment_count'] = (int) $count;
        $recipient = Customer::find($context['recipient_customer_id'] ?? null);
        if ($recipient && $recipient->id !== $session->customer_id) {
            $session->forceFill(['current_step' => 'waiting_preview', 'context' => $context])->save();

            return $this->preview($session, $context, $recipient);
        }

        $session->forceFill(['current_step' => 'waiting_recipient', 'context' => $context])->save();

        return ConversationResponse::make([
            ConversationResponse::text("👤 *QUEM* irá receber as parcelas?\n\nVocê pode parcelar para qualquer número de WhatsApp.\n\n*Digite o número ou compartilhe o contato*."),
        ], ['type' => 'phone', 'field' => 'installment_recipient']);
    }

    private function captureRecipient(ConversationSession $session, string $message): array
    {
        $phone = $this->phones->normalize($message, $session->external_user_id);
        if (! $phone) {
            return ConversationResponse::make([
                ConversationResponse::text('Não consegui identificar o telefone deste contato. Envie um contato da agenda com WhatsApp ou informe o número com DDI e DDD.'),
            ], ['type' => 'phone', 'field' => 'installment_recipient']);
        }
        $recipient = Customer::firstOrCreate(
            ['phone' => $phone],
            ['status' => 'active', 'acquisition_origin' => 'network',
                'metadata' => ['created_by_installment_proposal' => true]],
        );
        if ($recipient->id === $session->customer_id) {
            return ConversationResponse::make([
                ConversationResponse::text('Você não pode enviar uma proposta para você mesmo. 😉 Informe outro WhatsApp.'),
            ], ['type' => 'phone', 'field' => 'installment_recipient']);
        }

        $context = $session->context ?? [];
        $context['recipient_customer_id'] = $recipient->id;
        $session->forceFill(['current_step' => 'waiting_preview', 'context' => $context])->save();

        return $this->preview($session, $context, $recipient);
    }

    private function askSchedule(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_schedule'])->save();

        return ConversationResponse::make([
            ConversationResponse::options('Escolha o intervalo entre as parcelas:', [
                ['label' => '7 dias', 'value' => 'seven_days'],
                ['label' => '2 semanas (14 dias)', 'value' => 'two_weeks'],
                ['label' => '1 mês', 'value' => 'monthly'],
            ], 'Escolher prazo'),
        ], ['type' => 'option', 'field' => 'installment_schedule']);
    }

    private function captureSchedule(ConversationSession $session, string $choice): array
    {
        if (! in_array($choice, ['seven_days', 'two_weeks', 'monthly'], true)) {
            return $this->askSchedule($session);
        }

        $context = $session->context ?? [];
        $recipient = Customer::find($context['recipient_customer_id'] ?? null);
        if (! $recipient || $recipient->id === $session->customer_id) {
            return $this->changeProposal($session);
        }
        $context['installment_schedule'] = $choice;
        $session->forceFill(['current_step' => 'waiting_preview', 'context' => $context])->save();

        return $this->preview($session, $context, $recipient);
    }

    private function scheduleChoice(array $context): array
    {
        return match ($context['installment_schedule'] ?? null) {
            'seven_days' => ['daily', 7],
            'two_weeks' => ['daily', 14],
            'monthly' => ['monthly', 1],
            default => [$this->installments->scheduleType(), $this->installments->intervalDays()],
        };
    }

    private function preview(ConversationSession $session, array $context, Customer $recipient): array
    {
        $count = (int) $context['installment_count'];
        $total = (int) $context['installment_total_cents'] / 100;
        $sender = Customer::findOrFail($session->customer_id);
        [$scheduleType, $intervalDays] = $this->scheduleChoice($context);
        $schedule = $this->installments->scheduleText($total, $count, now(),
            $this->timezone($recipient), $intervalDays,
            $scheduleType, $this->installments->scheduleTimezone($sender));
        $periodicity = $scheduleType === 'monthly'
            ? 'As demais a cada mês, conforme as datas acima.'
            : ($scheduleType === 'minute'
                ? 'As demais a cada minuto, nos horários indicados acima.'
                : ($scheduleType === 'quarter_hour'
                    ? 'As demais a cada 15 minutos, nos horários indicados acima.'
                    : 'As demais a cada '.$intervalDays.' dias, nas datas indicadas acima.'));
        $senderName = $sender->whatsappDisplayName() ?: 'Membro';
        $recipientName = $recipient->whatsappDisplayName() ?: 'Membro';
        $senderPhone = $this->phoneFormatter->international($sender->phone);
        $recipientPhone = $this->phoneFormatter->international($recipient->phone);

        return ConversationResponse::make([
            ConversationResponse::options("💳 *Crédito - Pedido de parcelamento*\n\n"
                ."PARA Beneficiário:\n{$recipientPhone}\nhttps://wa.me/{$recipient->phone}\n{$recipientName}\n\n"
                ."DE:\n{$senderPhone}\nhttps://wa.me/{$sender->phone}\n{$senderName}\n\n"
                .'Valor Total: R$ '.number_format($total, 2, ',', '.')."\n"
                ."Nº de parcelas: {$count}\n\n{$schedule}\n"
                ."Datas previstas; serão confirmadas no envio.\n\n"
                ."A 1ª parcela é transferida imediatamente após a confirmação.\n"
                ."{$periodicity}\n"
                ."Eu {$senderName}, com o {$senderPhone}, responsabilizo-me por fazer as cargas necessárias no cartão para que MpMM® realize as transferências por mim a {$recipientName}, com o {$recipientPhone}.\n\n"
                ."*_Esta transferência e este plano de parcelamento não poderão ser desfeitos._*\n\n"
                .'*Confirma o envio do plano de parcelamento?*', [
                    ['label' => '🤔 Alterar Proposta', 'value' => 'alterar_pedido'],
                    ['label' => '🗓️ Escolher Prazo', 'value' => 'escolher_prazo'],
                    ['label' => '✅ Confirmar e Enviar', 'value' => 'confirmar'],
                ]),
        ], ['type' => 'option', 'field' => 'installment_preview']);
    }

    private function confirmPreview(ConversationSession $session, string $choice): array
    {
        if (in_array($choice, ['cancelar', '❌ cancelar'], true)) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }
        if (in_array($choice, ['alterar_pedido', 'mudar'], true)) return $this->changeProposal($session);
        if ($choice === 'escolher_prazo') return $this->askSchedule($session);
        if (in_array($session->context['installment_schedule'] ?? null, ['quarter_hour', 'fifteen_days'], true)) {
            return $this->askSchedule($session);
        }
        if (! in_array($choice, ['confirmar', 'ok'], true)) {
            $recipient = Customer::find(($session->context ?? [])['recipient_customer_id'] ?? null);
            return $recipient ? $this->preview($session, $session->context, $recipient) : $this->askAmount($session);
        }
        $context = $session->context ?? [];
        $sender = Customer::find($session->customer_id);
        $recipient = Customer::find($context['recipient_customer_id'] ?? null);
        $count = (int) ($context['installment_count'] ?? 0);
        $total = (int) ($context['installment_total_cents'] ?? 0) / 100;
        try {
            if (! $sender || ! $recipient) throw new InvalidArgumentException();
            [$scheduleType, $intervalDays] = $this->scheduleChoice($context);
            $proposal = $this->installments->createAndConfirm($sender, $recipient, $total, $count,
                (bool) ($context['from_transfer'] ?? false), $scheduleType, $intervalDays);
        } catch (InvalidArgumentException $exception) {
            return ConversationResponse::make([
                ConversationResponse::text($exception->getMessage()),
            ], null, null, 'installment');
        }

        $session->forceFill([
            'current_step' => 'waiting_sent_ack',
            'context' => ['installment_proposal_id' => $proposal->id],
        ])->save();

        $firstPayment = $proposal->payments()->where('number', 1)->firstOrFail();
        $transferNotice = $this->recipientTransferNotification($proposal, $firstPayment);
        $planNotice = [
            'channel' => 'whatsapp',
            'to' => $recipient->phone,
            'notification_key' => 'installment-plan:'.$proposal->id,
            'requires_inbound_window' => true,
            'messages' => [ConversationResponse::text($this->proposalText($proposal, $recipient))],
        ];
        foreach ([$transferNotice, $planNotice] as $notification) {
            WhatsappNotificationDelivery::firstOrCreate(
                ['notification_key' => $notification['notification_key'], 'recipient_phone' => $notification['to']],
                ['notification' => $notification, 'status' => 'pending', 'next_attempt_at' => now()],
            );
        }

        return ConversationResponse::make([
            ConversationResponse::options("🗓️ *Crédito Parcelado*\n\n"
                ."O plano nº {$proposal->id} foi confirmado. A primeira parcela foi transferida e as demais estão agendadas.\n\n"
                .$this->installments->scheduleText((float) $proposal->amount, $proposal->installments,
                    $proposal->accepted_at, $this->timezone($sender), $proposal->interval_days,
                    $proposal->schedule_type, $proposal->schedule_timezone)."\n\n"
                ."Lembre-se:\n"
                ."_Cumprir os compromissos assumidos fortalece a confiança e estreita relações._\n\n"
                ."Saiba mais em:\n*MyPhone MyMoney®*\n💬 0800 000 0000", [
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'continuar_mpmm'],
                ]),
            $this->installments->sentPaymentMessage($proposal, $firstPayment),
        ], ['type' => 'option', 'field' => 'installment_sent_ack'], null, null,
            [$transferNotice, $planNotice]);
    }

    private function recipientTransferNotification(InstallmentProposal $proposal, InstallmentPayment $payment): array
    {
        $amount = number_format((float) $payment->amount, 2, ',', '.');

        return [
            'channel' => 'whatsapp',
            'to' => $proposal->recipient->phone,
            'notification_key' => 'installment-first-received:'.$proposal->id,
            'template_name' => config('services.whatsapp.transfer_notification_template', 'aviso_transferencia_v1'),
            'template_language' => config('services.whatsapp.transfer_notification_template_language', 'pt_BR'),
            'template_components' => [[
                'type' => 'header',
                'parameters' => [['type' => 'image', 'image' => ['link' => config('services.whatsapp.welcome_image_url')]]],
            ], [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $amount],
                    ['type' => 'text', 'text' => $this->phoneFormatter->international($proposal->sender->phone)],
                ],
            ]],
            'messages' => [$this->installments->receivedPaymentMessage($proposal, $payment)],
        ];
    }

    private function proposalText(InstallmentProposal $proposal, Customer $recipient): string
    {
        $rows = $proposal->payments()->orderBy('number')->get()
            ->map(fn ($payment) => $payment->number.'ª  R$ '.number_format((float) $payment->amount, 2, ',', '.')
                .' — '.$payment->due_at->copy()->timezone($this->timezone($recipient))
                    ->format(in_array($proposal->schedule_type, ['minute', 'quarter_hour'], true) ? 'd/m/Y H:i' : 'd/m/Y'))
            ->implode("\n");

        return '💳 *Plano de parcelamento #'.$proposal->id." confirmado*\n\n"
            .'DE: '.$this->phoneFormatter->international($proposal->sender->phone)."\n"
            .($proposal->sender->whatsappDisplayName() ?: 'Membro')."\n\n"
            ."PARA:\n".$this->phoneFormatter->international($proposal->recipient->phone)."\n"
            .($proposal->recipient->whatsappDisplayName() ?: 'Membro')."\n\n"
            .'Valor total: *R$* '.number_format((float) $proposal->amount, 2, ',', '.')."\n"
            .'N° de parcelas: '.$proposal->installments."\n\n"
            ."Nº  Valor  Data\n{$rows}"
            ."\n\nA 1ª parcela foi transferida; as demais estão agendadas.";
    }

    private function decide(ConversationSession $session, bool $accept): array
    {
        $proposal = InstallmentProposal::find(($session->context ?? [])['installment_proposal_id'] ?? null);
        try {
            if (! $proposal || ! $session->customer) throw new InvalidArgumentException();
            $proposal = $this->installments->decide($proposal, $session->customer, $accept);
        } catch (InvalidArgumentException $exception) {
            return ConversationResponse::make([
                ConversationResponse::text($exception->getMessage()),
            ], null, null, 'installment');
        }

        if ($proposal->status === 'expired') {
            return ConversationResponse::make([
                ConversationResponse::text($this->installments->decisionSummary($proposal, $session->customer)),
            ], null, null, 'main_menu');
        }

        $text = $accept
            ? "✅ Proposta aceita com sucesso!\n".$this->installments->decisionSummary($proposal)."\n\n"
                .$this->installments->scheduleText((float) $proposal->amount, $proposal->installments,
                    $proposal->accepted_at, $this->timezone($session->customer), $proposal->interval_days,
                    $proposal->schedule_type, $proposal->schedule_timezone)
            : '❌ Proposta recusada com sucesso!'."\n".$this->installments->decisionSummary($proposal);
        $senderText = $accept
            ? $this->approvedSenderText($proposal)
            : "❌ Sua proposta de parcelamento #{$proposal->id} foi recusada.\n".$this->installments->decisionSummary($proposal);

        $firstPayment = $accept ? $proposal->payments()->where('number', 1)->firstOrFail() : null;
        $receivedMessage = $firstPayment ? $this->installments->receivedPaymentMessage($proposal, $firstPayment) : null;
        $sentMessage = $firstPayment ? $this->installments->sentPaymentMessage($proposal, $firstPayment) : null;

        $notifications = [[
            'channel' => 'whatsapp',
            'to' => $proposal->sender->phone,
            'notification_key' => 'installment-decision:'.$proposal->id,
            'messages' => [
                ConversationResponse::text($senderText),
                ...($sentMessage ? [$sentMessage] : []),
            ],
        ]];
        if ($receivedMessage) {
            $notifications[] = [
                'channel' => 'whatsapp',
                'to' => $proposal->recipient->phone,
                'notification_key' => 'installment-first-received:'.$proposal->id,
                'messages' => [$receivedMessage],
            ];
        }
        foreach ($notifications as $notification) {
            WhatsappNotificationDelivery::firstOrCreate(
                ['notification_key' => $notification['notification_key'],
                    'recipient_phone' => $notification['to']],
                ['notification' => $notification, 'status' => 'pending',
                    'next_attempt_at' => now()],
            );
        }

        return ConversationResponse::make([
            ConversationResponse::text($text),
        ], null, null, 'main_menu', $notifications);
    }

    private function approvedSenderText(InstallmentProposal $proposal): string
    {
        $payments = $proposal->payments()->orderBy('number')->get();
        $regularAmount = number_format((float) $payments->firstOrFail()->amount, 2, ',', '.');
        $dateFormat = in_array($proposal->schedule_type, ['minute', 'quarter_hour'], true) ? 'd/m/Y H:i' : 'd/m/Y';
        $rows = $payments->map(fn ($payment) => $payment->number.'  '
            .$payment->due_at->copy()->timezone($this->timezone($proposal->sender))->format($dateFormat)
            .'  R$ '.number_format((float) $payment->amount, 2, ',', '.'))
            ->implode("\n");

        return "✅ 💳 Seu pedido de parcelamento foi *aprovada com sucesso*!\n\n"
            ."_Cumprir os compromissos assumidos fortalece a confiança e estreita relações._\n\n"
            .'Valor total: R$ '.number_format((float) $proposal->amount, 2, ',', '.')."\n"
            ."Nº de Parcelas: {$proposal->installments}\n"
            ."Valor da Parcela: R$ {$regularAmount}\n\n"
            ."Parcelas:\nNº  Data  Valor\n{$rows}";
    }

    private function timezone(Customer $customer): string
    {
        return $customer->timezone ?: 'America/Sao_Paulo';
    }
}
