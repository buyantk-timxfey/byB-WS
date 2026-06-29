<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Базовое наполнение «нулевой» БД: один аккаунт, настройки-ставки,
     * системные статьи затрат, авто-правила сверки, пустая машина.
     */
    public function run(): void
    {
        // Единственный аккаунт (полный доступ). Пароль/PIN сменить после установки.
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@bybuka.ru')],
            [
                'name' => 'byBuka',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'changeme')),
                'pin_hash' => Hash::make(env('ADMIN_PIN', '0000')),
            ]
        );

        // Ставки и параметры (меняются в Настройках, не в коде)
        $settings = [
            'tax_rate' => '16',          // налог, %
            'salary_rate' => '20',       // зарплата от чистой, %
            'acquiring_card_rate' => '1.22',
            'acquiring_sbp_rate' => '0.7',
            'recon_tolerance' => '1.5',  // допуск сопоставления, %
            'acquiring_auto' => '1',     // авто-относить комиссию эквайринга
            'stale_days' => '60',        // порог залежалости склада
            'company_name' => '',
            'company_inn' => '',
            'company_ogrnip' => '',
            'company_account' => '',
        ];
        foreach ($settings as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }

        // Статьи затрат: системные нельзя удалить
        $articles = [
            ['name' => 'Эквайринг', 'is_system' => true],
            ['name' => 'Зарплата', 'is_system' => true],
            ['name' => 'Аренда', 'is_system' => false],
            ['name' => 'Связь и интернет', 'is_system' => false],
            ['name' => 'Транспорт / Топливо', 'is_system' => false],
            ['name' => 'Прочие расходы', 'is_system' => false],
        ];
        foreach ($articles as $a) {
            DB::table('expense_articles')->updateOrInsert(['name' => $a['name']], $a + ['updated_at' => now(), 'created_at' => now()]);
        }

        // Авто-правила сверки под формат выписки (СБП «Возмещение» + «Комиссия к возм.»)
        $acquiringArticleId = DB::table('expense_articles')->where('name', 'Эквайринг')->value('id');
        $rules = [
            ['match_field' => 'purpose', 'match_value' => 'Комиссия к возм', 'action_type' => 'acquiring', 'article_id' => $acquiringArticleId, 'priority' => 10],
            ['match_field' => 'purpose', 'match_value' => 'Возмещение', 'action_type' => 'sale_income', 'article_id' => null, 'priority' => 20],
            ['match_field' => 'purpose', 'match_value' => 'СБП', 'action_type' => 'sale_income', 'article_id' => null, 'priority' => 30],
        ];
        foreach ($rules as $r) {
            DB::table('recon_rules')->updateOrInsert(
                ['match_field' => $r['match_field'], 'match_value' => $r['match_value']],
                $r + ['updated_at' => now(), 'created_at' => now()]
            );
        }

        // Пустая машина (одна строка настроек транспорта)
        DB::table('vehicle_settings')->updateOrInsert(['id' => 1], [
            'tank_liters' => 60, 'consumption' => 8, 'odometer' => 0,
            'fuel_left' => 0, 'card_balance' => 0,
            'updated_at' => now(), 'created_at' => now(),
        ]);

        // Счётчики номеров документов
        foreach (['shipment', 'sale', 'writeoff', 'adjustment'] as $scope) {
            DB::table('doc_sequences')->updateOrInsert(['scope' => $scope], ['last_number' => 0, 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}
