<?php
/** Promove uma conta cadastrada localmente, sem publicar senhas padrão. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Disponível apenas pelo terminal.');
}
$email = trim((string) ($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php tools/promover_admin.php email-da-conta-cadastrada\n");
    exit(1);
}
require_once __DIR__ . '/../public/config_db/database.php';
$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email AND ativo = 1 LIMIT 1');
$stmt->execute([':email' => $email]);
$id = $stmt->fetchColumn();
if (!$id) {
    fwrite(STDERR, "Cadastre primeiro uma conta ativa na loja.\n");
    exit(1);
}
$update = $pdo->prepare("UPDATE usuarios SET papel = 'admin_master' WHERE id = :id");
$update->execute([':id' => $id]);
echo "Conta promovida. Encerre a sessão e entre pelo login administrativo.\n";
