<?php
/**
 * SHINKA — Organiza versões derivadas das imagens.
 *
 * Execute uma vez pelo terminal, a partir da raiz do projeto:
 *   php tools/organizar_imagens_derivadas.php
 *
 * Ele move thumb/medium/large/superzoom para:
 *   img/produtos/derivadas/
 *   img/banners/derivadas/
 * e atualiza caminho_arquivo no MySQL.
 */

require_once __DIR__ . '/../public/config_db/database.php';

$pdo = Database::getConnection();
$root = dirname(__DIR__);

function moverArquivo(string $root, string $origemRel, string $destinoRel): bool
{
    $origem = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $origemRel);
    $destino = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destinoRel);

    if (!is_file($origem)) {
        return false;
    }

    $dir = dirname($destino);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar ' . $dir);
    }

    if (is_file($destino)) {
        @unlink($origem);
        return true;
    }

    if (!@rename($origem, $destino)) {
        if (!@copy($origem, $destino)) {
            throw new RuntimeException('Não foi possível mover ' . $origemRel);
        }
        @unlink($origem);
    }

    return true;
}

$stmt = $pdo->query(
    "SELECT id, caminho_arquivo
       FROM imagem_derivadas
      WHERE caminho_arquivo IS NOT NULL
        AND caminho_arquivo <> ''"
);

$movidas = 0;
$ignoradas = 0;

foreach ($stmt->fetchAll() as $row) {
    $origem = (string)$row['caminho_arquivo'];
    if (!preg_match('~^img/(produtos|banners)/([^/]+)$~i', $origem, $m)) {
        $ignoradas++;
        continue;
    }

    $destino = 'img/' . strtolower($m[1]) . '/derivadas/' . $m[2];

    if (moverArquivo($root, $origem, $destino)) {
        $up = $pdo->prepare(
            'UPDATE imagem_derivadas SET caminho_arquivo=:caminho WHERE id=:id'
        );
        $up->execute([
            ':caminho' => $destino,
            ':id' => (int)$row['id']
        ]);
        $movidas++;
    }
}

echo "Organização concluída. Arquivos movidos/normalizados: {$movidas}. Ignorados: {$ignoradas}.\n";
