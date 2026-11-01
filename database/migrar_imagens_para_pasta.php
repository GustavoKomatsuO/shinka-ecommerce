<?php
/**
 * Migra imagens antigas armazenadas em BLOB para C:\xampp\htdocs\img.
 * Execute uma vez pelo CLI:
 *   php database/migrar_imagens_para_pasta.php
 */

require_once __DIR__ . '/../public/config_db/database.php';

$pdo = Database::getConnection();
$imgRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img';

foreach (['produtos', 'banners'] as $folder) {
    $dir = $imgRoot . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Não foi possível criar {$dir}\n");
        exit(1);
    }
}

$mimeExt = static function (string $mime): string {
    return match (strtolower($mime)) {
        'image/png' => 'png',
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        default => 'webp',
    };
};

$rows = $pdo->query(
    'SELECT id, nome_arquivo, mime_type, uso, caminho_arquivo, arquivo FROM imagens ORDER BY id'
)->fetchAll();

$total = 0;
foreach ($rows as $row) {
    if (!empty($row['caminho_arquivo']) && is_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['caminho_arquivo']))) {
        continue;
    }

    if ($row['arquivo'] === null || $row['arquivo'] === '') {
        continue;
    }

    $folder = ($row['uso'] ?? 'produto') === 'banner' ? 'banners' : 'produtos';
    $ext = $mimeExt((string)$row['mime_type']);
    $base = pathinfo((string)$row['nome_arquivo'], PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?: 'imagem';
    $relative = 'img/' . $folder . '/' . (int)$row['id'] . '-' . trim($base, '-_') . '.' . $ext;
    $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (file_put_contents($absolute, $row['arquivo'], LOCK_EX) === false) {
        fwrite(STDERR, "Falha ao gravar #{$row['id']}\n");
        continue;
    }

    $stmt = $pdo->prepare('UPDATE imagens SET caminho_arquivo=:caminho, arquivo=NULL WHERE id=:id');
    $stmt->execute([':caminho' => $relative, ':id' => $row['id']]);
    $total++;
    echo "Imagem #{$row['id']} -> {$relative}\n";
}

$rows = $pdo->query(
    'SELECT d.id, d.imagem_id, d.preset, d.mime_type, d.caminho_arquivo, d.arquivo, i.uso, i.nome_arquivo\n     FROM imagem_derivadas d\n     INNER JOIN imagens i ON i.id=d.imagem_id\n     ORDER BY d.id'
)->fetchAll();

foreach ($rows as $row) {
    if (!empty($row['caminho_arquivo']) && is_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['caminho_arquivo']))) {
        continue;
    }
    if ($row['arquivo'] === null || $row['arquivo'] === '') continue;

    $folder = ($row['uso'] ?? 'produto') === 'banner' || str_starts_with((string)$row['preset'], 'banner_') ? 'banners' : 'produtos';
    $ext = $mimeExt((string)$row['mime_type']);
    $relative = 'img/' . $folder . '/' . (int)$row['imagem_id'] . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$row['preset']) . '.' . $ext;
    $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (file_put_contents($absolute, $row['arquivo'], LOCK_EX) === false) {
        fwrite(STDERR, "Falha na derivada #{$row['id']}\n");
        continue;
    }

    $stmt = $pdo->prepare('UPDATE imagem_derivadas SET caminho_arquivo=:caminho, arquivo=NULL WHERE id=:id');
    $stmt->execute([':caminho' => $relative, ':id' => $row['id']]);
    echo "Derivada #{$row['id']} -> {$relative}\n";
}

echo "Migração concluída. Imagens principais migradas: {$total}\n";
