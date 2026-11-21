<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappMessageStatus;
use Illuminate\Support\Carbon;

class WhatsappStatusRecorder
{
    public function record(array $payload): int
    {
        $count = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $id = trim((string) ($status['id'] ?? ''));
                    $state = (string) ($status['status'] ?? '');
                    if ($id === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }

                    $event = WhatsappMessageStatus::firstOrNew(['provider_message_id' => $id]);
                    $timestamp = isset($status['timestamp'])
                        ? Carbon::createFromTimestamp((int) $status['timestamp'])
                        : now();
                    $field = $state.'_at';
                    if (! $event->{$field} || $timestamp->lt($event->{$field})) {
                        $event->{$field} = $timestamp;
                    }

                    $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
                    if ($state === 'failed' && ! $event->delivered_at && ! $event->read_at) {
                        $event->status = 'failed';
                    } elseif ($state !== 'failed'
                        && ($event->status !== 'failed' || in_array($state, ['delivered', 'read'], true))
                        && ($rank[$state] ?? 0) > ($rank[$event->status] ?? 0)) {
                        $event->status = $state;
                    }
                    $event->save();
                    $count++;
                }
            }
        }

        return $count;
    }
}
