<?php
$counterparties = $pdo->query("
    SELECT * FROM counterparties ORDER BY name
")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title">Контрагенты</h1>
</div>
<script>
window._gsPageActions = '<button class="btn btn-primary" onclick="openAddCounterparty()">+ Добавить</button>';
</script>

<?php if (empty($counterparties)): ?>
    <div class="empty-state">
        <p>Контрагентов пока нет</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openAddCounterparty()">+ Добавить первого</button>
    </div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Тип</th>
                <th>Название</th>
                <th>Контрагент</th>
                <th>Реквизиты</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="counterparties-tbody" class="m-cards m-cards-cp">
            <?php foreach ($counterparties as $cp): ?>
            <tr onclick="openCounterparty(<?= $cp['id'] ?>)">
                <td>
                    <span style="
                        display:inline-block;
                        padding:2px 8px;
                        border-radius:4px;
                        font-size:11px;
                        font-weight:600;
                        background:rgba(201,169,110,0.1);
                        color:var(--accent)
                    "><?= $cp['company_type'] ?></span>
                </td>
                <td><?= htmlspecialchars($cp['name']) ?></td>
                <td>
                    <?php
                    $typeMap = [
                        'Поставщик' => 'var(--info)',
                        'Покупатель' => 'var(--success)',
                        'Оба' => 'var(--accent)'
                    ];
                    $color = $typeMap[$cp['type']] ?? 'var(--text-muted)';
                    ?>
                    <span style="color:<?= $color ?>;font-size:13px"><?= $cp['type'] ?></span>
                </td>
                <td>
                    <span style="color:var(--text-muted);font-size:12px">
                        <?= $cp['requisites'] ? mb_substr(htmlspecialchars($cp['requisites']), 0, 40) . '...' : '—' ?>
                    </span>
                </td>
                <td>
                    <div class="actions">
                        <button class="btn-action" onclick="event.stopPropagation(); openEditCounterparty(<?= $cp['id'] ?>, <?= htmlspecialchars(json_encode($cp['company_type'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cp['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cp['type'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cp['requisites'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cp['comment'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Изменить</button>
                        <button class="btn-action danger" onclick="event.stopPropagation(); deleteCounterparty(<?= $cp['id'] ?>)">Удалить</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>