<?php

namespace Database\Seeders;

use App\Models\GeneralContent;
use Illuminate\Database\Seeder;

class GeneralContentSeeder extends Seeder
{
    public function run(): void
    {
        GeneralContent::updateOrCreate(
            [
                'area' => 'registration',
                'key' => 'welcome',
            ],
            [
                'content' => [
                    'header_image_url' => config('services.whatsapp.welcome_image_url'),
                    'body' => "Olá, 🙏🏼🙏🏼 seja bem-vindo ao\n*_MyPhone MyMoney®_*\n*_Meu Celular Meu Dinheiro®_*\n\nExperimente e aproveite todas as vantagens de ter uma carteira no\n*_MyPhone MyMoney®_*\n\nO serviço é 100% automático.\n\nA equipe MPMM está à disposição pelo canal oficial de suporte.\n\nVamos começar?",
                ],
                'actions' => [
                    [
                        'label' => '👍 Vamos lá!',
                        'value' => 'vamos_la',
                        'action' => 'start_registration',
                    ],
                ],
                'is_active' => true,
            ],
        );

        GeneralContent::updateOrCreate(
            [
                'area' => 'registration',
                'key' => 'terms_acceptance',
            ],
            [
                'content' => [
                    'title' => 'Boas-vindas ao *_MpMM®_* Trial',
                    'body' => 'Para começar, leia e aceite os termos de uso.',
                    'terms_version' => '2026-06-20',
                    'terms_url' => 'https://mpmm.local/terms',
                    'privacy_policy_url' => 'https://mpmm.local/privacy',
                ],
                'actions' => [
                    [
                        'label' => 'Aceito',
                        'value' => 'aceitar',
                        'action' => 'accept_terms',
                    ],
                    [
                        'label' => '❌Cancelar',
                        'value' => 'cancelar',
                        'action' => 'cancel',
                    ],
                ],
                'is_active' => true,
            ],
        );
    }
}
