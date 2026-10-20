<?php

namespace App\Bureaucracy\Facts;

enum FactInputMethod: string
{
    case Structured = 'structured';
    case Onboarding = 'onboarding';
    case ConfirmedExtraction = 'confirmed_extraction';

    public function source(): string
    {
        return match ($this) {
            self::Structured => 'manual',
            self::Onboarding => 'onboarding',
            self::ConfirmedExtraction => 'ai_extracted_user_confirmed',
        };
    }
}
