<?php
require __DIR__ . '/../config.php';
$pdo->exec("DELETE FROM bank_statement_matches");
$pdo->exec("DELETE FROM bank_statement_lines");
$pdo->exec("DELETE FROM bank_statement_batches");
// Все pending операции подтверждаем (выписка удалена, нет смысла держать в ожидании)
$pdo->exec("UPDATE bank_operations SET status='confirmed' WHERE status='pending'");
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'msg' => 'Выписки очищены']);
