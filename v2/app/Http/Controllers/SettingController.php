<?php

namespace App\Http\Controllers;

use App\Models\ExpenseArticle;
use App\Models\ReconRule;
use App\Models\Setting;
use App\Models\VatRate;
use App\Models\WebauthnCredential;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingController extends Controller
{
    private const KEYS = [
        'tax_rate', 'salary_rate', 'acquiring_card_rate', 'acquiring_sbp_rate',
        'recon_tolerance', 'acquiring_auto', 'stale_days', 'idle_lock_minutes', 'theme_mode',
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
            'vatRates' => VatRate::orderBy('rate')->get(['id', 'rate']),
            'devices' => WebauthnCredential::where('user_id', auth()->id())->get(['id', 'name', 'last_used_at']),
            'lastPatch' => Setting::get('last_patch'),
            'currentLogin' => auth()->user()->email,
            'status' => session('status'),
        ]);
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'tax_rate' => 'nullable|numeric', 'salary_rate' => 'nullable|numeric',
            'acquiring_card_rate' => 'nullable|numeric', 'acquiring_sbp_rate' => 'nullable|numeric',
            'recon_tolerance' => 'nullable|numeric', 'acquiring_auto' => 'boolean', 'stale_days' => 'nullable|integer',
            'idle_lock_minutes' => 'nullable|integer|min:1|max:480',
            'theme_mode' => 'nullable|string|in:system,light,dark,auto_time',
            'company_name' => 'nullable|string|max:255', 'company_inn' => 'nullable|string|max:20',
            'company_ogrnip' => 'nullable|string|max:20', 'company_account' => 'nullable|string|max:40',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }

        return back();
    }

    // Смена логина/пароля (владелец задаёт их сам — значения нигде не хранятся вне БД).
    public function changeCredentials(Request $r)
    {
        $user = $r->user();
        // Пустая строка — «пароль не меняем»; приводим к null, иначе nullable
        // не сработает и min:6/confirmed будут проверять пустое значение.
        if ($r->input('password') === '') {
            $r->merge(['password' => null]);
        }
        $data = $r->validate([
            'email' => ['required', 'string', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6|confirmed',
        ]);
        $user->email = $data['email'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('status', 'Данные для входа обновлены');
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

    // Ставки НДС (справочник для товаров в поставках)
    public function storeVatRate(Request $r)
    {
        VatRate::create($r->validate(['rate' => 'required|numeric|min:0|max:100|unique:vat_rates,rate']));

        return back();
    }

    public function destroyVatRate(VatRate $vatRate)
    {
        $vatRate->delete();

        return back();
    }
}
