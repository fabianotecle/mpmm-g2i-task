<?php

namespace App\Services\Conversation;

use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use Illuminate\Support\Facades\DB;

class ConversationMessageOrderRepair
{
    public function repair(): int
    {
        $repaired = 0;

        ConversationSession::query()->where('channel', 'whatsapp')->select('id')->chunkById(500, function ($sessions) use (&$repaired) {
            foreach ($sessions as $session) {
                $first = ConversationMessage::query()
                    ->where('conversation_session_id', $session->id)
                    ->orderBy('id')
                    ->limit(2)
                    ->get();

                if ($first->count() !== 2) {
                    continue;
                }

                [$outbound, $inbound] = $first->all();
                if ($outbound->direction !== 'outbound'
                    || $inbound->direction !== 'inbound'
                    || $outbound->display_order !== null
                    || $inbound->display_order !== null
                    || $inbound->external_channel !== 'whatsapp'
                    || ! $inbound->external_timestamp
                    || ! $outbound->created_at
                    || ! $inbound->created_at
                    || abs($inbound->created_at->diffInSeconds($outbound->created_at)) > 60
                    || $inbound->external_timestamp->gt($outbound->created_at)) {
                    continue;
                }

                DB::transaction(function () use ($outbound, $inbound) {
                    $outbound->forceFill(['display_order' => $inbound->id])->save();
                    $inbound->forceFill(['display_order' => $outbound->id])->save();
                });
                $repaired++;
            }
        });

        return $repaired;
    }
}
