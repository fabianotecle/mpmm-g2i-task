<?php

namespace App\Services\Conversation\Flows\Concerns;

trait ParsesConversationInput
{
    private function normalized(string $message): string
    {
        return mb_strtolower(trim($message));
    }

    private function onlyDigits(string $message): string
    {
        return preg_replace('/\D+/', '', $message) ?: '';
    }

    private function moneyFromMessage(string $message): ?float
    {
        $normalized = $this->moneyCandidateFromMessage($message);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', '', $normalized) ?? '';

        if (! preg_match('/^-?\d+(?:[.,]\d+)*$/', $normalized)) {
            return null;
        }

        $negative = str_starts_with($normalized, '-');
        $number = ltrim($normalized, '-');
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        $decimalPosition = null;

        if ($lastComma !== false && $lastDot !== false) {
            $decimalPosition = max($lastComma, $lastDot);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $positions = $this->separatorPositions($number, $separator);
            $lastSeparatorPosition = end($positions);
            $digitsAfterSeparator = strlen($number) - $lastSeparatorPosition - 1;

            if ($digitsAfterSeparator === 0) {
                return null;
            }

            if (count($positions) === 1) {
                if ($digitsAfterSeparator <= 2) {
                    $decimalPosition = ($negative ? 1 : 0) + $lastSeparatorPosition;
                } elseif ($digitsAfterSeparator !== 3) {
                    return null;
                }
            } elseif (! $this->hasOnlyThousandsGroups($number, $separator)) {
                return null;
            }
        }

        if ($decimalPosition !== null) {
            $integer = preg_replace('/[.,]/', '', substr($normalized, 0, $decimalPosition));
            $cents = preg_replace('/[.,]/', '', substr($normalized, $decimalPosition + 1));
            $normalized = $integer.'.'.$cents;
        } else {
            $normalized = str_replace([',', '.'], '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function moneyCandidateFromMessage(string $message): ?string
    {
        $message = trim($message);

        if (preg_match('/R\$\s*(-?\d[\d\s.,]*\d|-?\d)/iu', $message, $currencyMatch)) {
            return trim($currencyMatch[1]);
        }

        preg_match_all('/-?\d[\d\s.,]*\d|-?\d/u', $message, $matches);
        $candidates = array_values(array_filter(array_map(
            static fn (string $candidate): string => trim($candidate),
            $matches[0] ?? [],
        ), static fn (string $candidate): bool => $candidate !== ''));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function separatorPositions(string $value, string $separator): array
    {
        $positions = [];
        $offset = 0;

        while (($position = strpos($value, $separator, $offset)) !== false) {
            $positions[] = $position;
            $offset = $position + 1;
        }

        return $positions;
    }

    private function hasOnlyThousandsGroups(string $value, string $separator): bool
    {
        $groups = explode($separator, $value);

        if ($groups[0] === '' || strlen($groups[0]) > 3) {
            return false;
        }

        foreach (array_slice($groups, 1) as $group) {
            if (strlen($group) !== 3) {
                return false;
            }
        }

        return true;
    }

    private function isCancel(string $message): bool
    {
        return in_array($this->normalized($message), ['cancelar', '❌cancelar', 'cancela', 'sair', 'voltar'], true);
    }

    private function isConfirm(string $message): bool
    {
        return in_array($this->normalized($message), ['confirmar', '✅confirmar', 'confirma', 'sim', 'aceitar', 'aceito', 'ok'], true);
    }
}
