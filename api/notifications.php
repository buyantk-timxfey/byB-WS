<?php
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    exit;
}

require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

// Ensure tables exist (notes and reminders may not yet exist on first load)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        text TEXT NOT NULL,
        deadline DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(50) NOT NULL,
        entity_id INT NOT NULL,
        text TEXT NOT NULL,
        remind_at DATETIME NOT NULL,
        is_done TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // non-fatal — tables might already exist
}

$notifications = [];

// ─── 1. Overdue shipments ───────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT s.id, s.eta,
               (SELECT COUNT(*) FROM shipments s2 WHERE s2.id <= s.id) AS display_num
        FROM shipments s
        WHERE s.status = 'В пути' AND s.eta < CURDATE()
        ORDER BY s.eta ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $etaFmt = date('d.m.Y', strtotime($row['eta']));
        $notifications[] = [
            'type'      => 'shipment',
            'icon'      => 'package',
            'severity'  => 'danger',
            'title'     => 'Поставка #' . $row['display_num'] . ' просрочена',
            'sub'       => 'ETA был: ' . $etaFmt,
            'link_page' => 'shipments',
            'link_id'   => (int)$row['id'],
            '_sort_date' => $row['eta'],
        ];
    }
} catch (PDOException $e) { /* table may not exist */ }

// ─── 2. Unpaid invoices ─────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT id, sale_date, total_amount
        FROM sales
        WHERE status = 'Счёт выставлен'
          AND sale_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)
        ORDER BY sale_date ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dateFmt   = date('d.m.Y', strtotime($row['sale_date']));
        $amountFmt = number_format((float)$row['total_amount'], 0, '.', ' ');
        $notifications[] = [
            'type'      => 'sale',
            'icon'      => 'credit-card',
            'severity'  => 'warning',
            'title'     => 'Счёт #' . $row['id'] . ' не оплачен',
            'sub'       => 'Выставлен: ' . $dateFmt . ', Сумма: ' . $amountFmt . ' ₽',
            'link_page' => 'sales',
            'link_id'   => (int)$row['id'],
            '_sort_date' => $row['sale_date'],
        ];
    }
} catch (PDOException $e) { /* table may not exist */ }

// ─── 3. Note deadlines ──────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT id, text, deadline
        FROM notes
        WHERE deadline IS NOT NULL
          AND deadline <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ORDER BY deadline ASC
    ");
    $today = date('Y-m-d');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $severity  = ($row['deadline'] < $today) ? 'danger' : 'warning';
        $deadlineFmt = date('d.m.Y', strtotime($row['deadline']));
        $preview   = mb_substr($row['text'], 0, 60, 'UTF-8');
        $notifications[] = [
            'type'      => 'note',
            'icon'      => 'file-text',
            'severity'  => $severity,
            'title'     => 'Дедлайн заметки',
            'sub'       => $preview . ' — ' . $deadlineFmt,
            'link_page' => null,
            'link_id'   => (int)$row['id'],
            '_sort_date' => $row['deadline'],
        ];
    }
} catch (PDOException $e) { /* table may not exist */ }

// ─── 4. Active reminders ────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT id, entity_type, entity_id, text, remind_at
        FROM reminders
        WHERE is_done = 0
          AND remind_at <= NOW()
        ORDER BY remind_at ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notifications[] = [
            'type'        => 'reminder',
            'icon'        => 'bell',
            'severity'    => 'info',
            'title'       => 'Напоминание',
            'sub'         => $row['text'],
            'link_page'   => $row['entity_type'] . 's',
            'link_id'     => (int)$row['entity_id'],
            'reminder_id' => (int)$row['id'],
            '_sort_date'  => $row['remind_at'],
        ];
    }
} catch (PDOException $e) { /* table may not exist */ }

// ─── Sort: severity order then by date ──────────────────────────────────────
$severityOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
usort($notifications, function ($a, $b) use ($severityOrder) {
    $sa = $severityOrder[$a['severity']] ?? 99;
    $sb = $severityOrder[$b['severity']] ?? 99;
    if ($sa !== $sb) return $sa - $sb;
    return strcmp($a['_sort_date'] ?? '', $b['_sort_date'] ?? '');
});

// Strip internal sort key
foreach ($notifications as &$n) {
    unset($n['_sort_date']);
}
unset($n);

echo json_encode(array_values($notifications));
