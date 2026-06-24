<?php
/**
 * Общий рендерер строки операции банка.
 * Используется и при первой отрисовке (pages/bank.php), и при догрузке
 * через бесконечный скролл (api/bank.php?operations_html). Единый источник
 * разметки — чтобы строки не разъезжались между серверным рендером и догрузкой.
 *
 * $op ожидает поля: id, account_id, type, description, has_statement,
 *                   sale_id, shipment_id, operation_date, account_name, amount.
 */
if (!function_exists('renderBankOpRow')) {
    function renderBankOpRow(array $op): string {
        $ico = 'style="width:11px;height:11px"';
        $typeMap = [
            'Продажа'       => ['badge-done',    '<i data-lucide="trending-up" '.$ico.'></i> Продажа'],
            'Закупка'       => ['badge-alert',   '<i data-lucide="package" '.$ico.'></i> Закупка'],
            'Прочий приход' => ['badge-transit', '<i data-lucide="arrow-down-circle" '.$ico.'></i> Приход'],
            'Расход'        => ['badge-waiting', '<i data-lucide="minus-circle" '.$ico.'></i> Расход'],
            'Выплата ЗП'    => ['badge-alert',   '<i data-lucide="users" '.$ico.'></i> Выплата ЗП'],
            'Перевод'       => ['badge-transit', '<i data-lucide="arrow-left-right" '.$ico.'></i> Перевод'],
        ];
        [$badgeCls, $badgeLabel] = $typeMap[$op['type']] ?? ['badge-waiting', htmlspecialchars($op['type'] ?: 'Операция')];
        if ($op['type'] === 'Расход' && !$op['sale_id'] && !$op['shipment_id']) {
            $badgeLabel = '<i data-lucide="briefcase" '.$ico.'></i> Расход предпр.';
        }
        if (str_contains($op['description'] ?? '', 'кассе')) {
            $badgeLabel = '<i data-lucide="shopping-bag" '.$ico.'></i> Касса';
            $badgeCls = 'badge-done';
        }
        // Для перевода направление берём по знаку суммы (списание <0, зачисление >0)
        $isIncome = $op['type'] === 'Перевод'
            ? ((float)$op['amount'] >= 0)
            : in_array($op['type'], ['Продажа', 'Прочий приход']);
        $displayAmount = abs((float)$op['amount']);

        ob_start(); ?>
        <tr class="has-actions"
            data-account="<?= $op['account_id'] ?>"
            data-type="<?= htmlspecialchars($op['type']) ?>"
            data-direction="<?= $isIncome ? 'income' : 'expense' ?>"
            onclick="openBankOperation(<?= $op['id'] ?>)" style="cursor:pointer">
            <td><?= date('d.m.Y', strtotime($op['operation_date'])) ?></td>
            <td><span class="badge <?= $badgeCls ?>"><?= $badgeLabel ?></span></td>
            <td><?= htmlspecialchars($op['description'] ?? '—') ?></td>
            <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($op['account_name']) ?></td>
            <td style="text-align:right;font-weight:500;color:<?= $isIncome ? 'var(--success)' : 'var(--danger)' ?>">
                <?= $isIncome ? '+' : '−' ?><?= number_format($displayAmount, 0, '.', ' ') ?> ₽
            </td>
            <td class="actions-cell">
                <?php if (!empty($op['has_statement'])): ?>
                <span title="Подтверждено по выписке" style="color:var(--success);font-size:14px;line-height:1">✓</span>
                <?php else: ?>
                <span title="Ожидает подтверждения по выписке" style="color:var(--warning);font-size:13px;line-height:1"><i data-lucide="clock" style="width:13px;height:13px"></i></span>
                <?php endif; ?>
                <?php if (!$op['sale_id'] && !$op['shipment_id']): ?>
                <div class="row-actions">
                    <button class="btn-icon btn-icon-danger" title="Удалить"
                        onclick="event.stopPropagation(); deleteBankOperation(<?= $op['id'] ?>)">
                        <i data-lucide="trash-2"></i>
                    </button>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
}
