<?php

namespace App\Services\Audit;

use App\Models\Customer;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerAuditContext
{
    private ?array $current = null;

    public function run(array $context, Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = [
            'source' => $context['source'] ?? 'system',
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'actor_customer_id' => $context['actor_customer_id'] ?? null,
            'actor_phone' => $context['actor_phone'] ?? null,
            'conversation_session_id' => $context['conversation_session_id'] ?? null,
            'external_message_id' => $context['external_message_id'] ?? null,
            'request_id' => (string) Str::uuid(),
        ];
        try {
            $this->apply();

            return $callback();
        } finally {
            $this->current = $previous;
            $this->apply();
        }
    }

    public function attachSessionId(?int $sessionId): void
    {
        if ($sessionId === null || $this->current === null) {
            return;
        }

        $this->current['conversation_session_id'] = $sessionId;
        if ($this->current['actor_customer_id'] === null && $this->current['actor_phone'] !== null) {
            $this->current['actor_customer_id'] = Customer::where('phone', $this->current['actor_phone'])->value('id');
        }
        DB::table('customer_audit_logs')
            ->where('request_id', $this->current['request_id'])
            ->whereNull('conversation_session_id')
            ->update([
                'conversation_session_id' => $sessionId,
                'actor_customer_id' => $this->current['actor_customer_id'],
            ]);
        $this->apply();
    }

    public function prepareModelWrite(): void
    {
        if (DB::getDriverName() === 'mysql' && $this->current === null) {
            $this->setMysqlContext(['source' => 'system']);
        }
    }

    public function recordModelEvent(Customer $customer, string $action): void
    {
        if (DB::getDriverName() === 'mysql') {
            return;
        }

        $changes = $action === 'updated'
            ? array_keys(array_diff_key($customer->getChanges(), ['updated_at' => true]))
            : [$action];
        if ($changes === []) {
            return;
        }

        DB::table('customer_audit_logs')->insert([
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone,
            'previous_phone' => $action === 'created' ? null : $customer->getRawOriginal('phone'),
            'action' => $action,
            'source' => $this->current['source'] ?? 'system',
            'actor_user_id' => $this->current['actor_user_id'] ?? null,
            'actor_customer_id' => $this->current['actor_customer_id'] ?? null,
            'actor_phone' => $this->current['actor_phone'] ?? null,
            'conversation_session_id' => $this->current['conversation_session_id'] ?? null,
            'external_message_id' => $this->current['external_message_id'] ?? null,
            'request_id' => $this->current['request_id'] ?? null,
            'database_account' => null,
            'database_connection_id' => null,
            'changed_fields' => implode(',', $changes),
            'identity_before' => $action === 'created' ? null : json_encode($this->identity($customer, true), JSON_THROW_ON_ERROR),
            'identity_after' => $action === 'deleted' ? null : json_encode($this->identity($customer, false), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function identity(Customer $customer, bool $original): array
    {
        $identity = [];
        foreach (['phone', 'name', 'whatsapp_id', 'whatsapp_username', 'whatsapp_profile_name'] as $column) {
            $identity[$column] = $original ? $customer->getRawOriginal($column) : $customer->getAttribute($column);
        }

        return $identity;
    }

    private function apply(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->setMysqlContext($this->current ?? []);
        }
    }

    private function setMysqlContext(array $context): void
    {
        DB::statement('SET @mpmm_audit_source = ?, @mpmm_audit_actor_user_id = ?,
            @mpmm_audit_actor_customer_id = ?, @mpmm_audit_actor_phone = ?,
            @mpmm_audit_session_id = ?, @mpmm_audit_message_id = ?,
            @mpmm_audit_request_id = ?', [
            $context['source'] ?? null,
            $context['actor_user_id'] ?? null,
            $context['actor_customer_id'] ?? null,
            $context['actor_phone'] ?? null,
            $context['conversation_session_id'] ?? null,
            $context['external_message_id'] ?? null,
            $context['request_id'] ?? null,
        ]);
    }
}
