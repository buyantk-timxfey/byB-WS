<?php

namespace App\Http\Controllers;

use App\Models\ExpenseArticle;
use App\Models\MailAccount;
use App\Models\ReconRule;
use App\Models\Setting;
use App\Models\WebauthnCredential;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingController extends Controller
{
    private const KEYS = [
        'tax_rate', 'salary_rate', 'acquiring_card_rate', 'acquiring_sbp_rate',
        'recon_tolerance', 'acquiring_auto', 'stale_days',
        'company_name', 'company_inn', 'company_ogrnip', 'company_account',
    ];

    public function index()
    {
        $settings = [];
        foreach (self::KEYS as $k) {
            $settings[$k] = Setting::get($k);
        }

        return Inertia::render('Settings', [
            'settings' => $settings,
            'rules' => ReconRule::with('article:id,name')->orderBy('priority')->get(),
            'articles' => ExpenseArticle::orderBy('name')->get(['id', 'name']),
            'devices' => WebauthnCredential::where('user_id', auth()->id())->get(['id', 'name', 'last_used_at']),
            'mailAccounts' => MailAccount::orderBy('email')->get(['id', 'email', 'imap_host', 'imap_port', 'smtp_host', 'smtp_port', 'login', 'use_ssl']),
            'imapAvailable' => true,   // чтение через собственный IMAP-клиент, расширение PHP не требуется
            'lastPatch' => Setting::get('last_patch'),
        ]);
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'tax_rate' => 'nullable|numeric', 'salary_rate' => 'nullable|numeric',
            'acquiring_card_rate' => 'nullable|numeric', 'acquiring_sbp_rate' => 'nullable|numeric',
            'recon_tolerance' => 'nullable|numeric', 'acquiring_auto' => 'boolean', 'stale_days' => 'nullable|integer',
            'company_name' => 'nullable|string|max:255', 'company_inn' => 'nullable|string|max:20',
            'company_ogrnip' => 'nullable|string|max:20', 'company_account' => 'nullable|string|max:40',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }

        return back();
    }

    // Авто-правила сверки
    public function storeRule(Request $r)
    {
        ReconRule::create($r->validate([
            'match_field' => 'required|in:purpose,inn', 'match_value' => 'required|string|max:255',
            'action_type' => 'required|in:expense_article,sale_income,shipment_payment,acquiring,transfer',
            'article_id' => 'nullable|exists:expense_articles,id', 'priority' => 'nullable|integer',
        ]));

        return back();
    }

    public function destroyRule(ReconRule $rule)
    {
        $rule->delete();

        return back();
    }
}
