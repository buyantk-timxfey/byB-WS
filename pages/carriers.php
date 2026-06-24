<?php
$carriers = $pdo->query("
    SELECT * FROM carriers ORDER BY name
")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title">Транспортные компании</h1>
</div>
<script>
window._gsPageActions = '<button class="btn btn-primary" onclick="openAddCarrier()">+ Добавить</button>';
</script>

<?php if (empty($carriers)): ?>
    <div class="empty-state">
        <p>Транспортных компаний пока нет</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openAddCarrier()">+ Добавить первую</button>
    </div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Название</th>
                <th>Сайт</th>
                <th>Комментарий</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="carriers-tbody" class="m-cards m-cards-carrier">
            <?php foreach ($carriers as $cr): ?>
            <tr onclick="openCarrier(<?= $cr['id'] ?>)">
                <td><?= htmlspecialchars($cr['name']) ?></td>
                <td>
                    <?php if ($cr['website']): ?>
                    <a href="<?= htmlspecialchars($cr['website']) ?>"
                       target="_blank"
                       class="track-link"
                       onclick="event.stopPropagation()">
                        <?= htmlspecialchars($cr['website']) ?>
                    </a>
                    <?php else: ?>
                    <span style="color:var(--text-dim)">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="color:var(--text-muted);font-size:12px">
                        <?= $cr['comment'] ? mb_substr(htmlspecialchars($cr['comment']), 0, 50) . '...' : '—' ?>
                    </span>
                </td>
                <td>
                    <div class="actions">
                        <button class="btn-action" onclick="event.stopPropagation(); openEditCarrier(<?= $cr['id'] ?>, <?= htmlspecialchars(json_encode($cr['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cr['website'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cr['comment'] ?? '', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Изменить</button>
                        <button class="btn-action danger" onclick="event.stopPropagation(); deleteCarrier(<?= $cr['id'] ?>)">Удалить</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>