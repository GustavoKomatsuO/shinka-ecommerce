<?php
/**
 * SHINKA — Controller do banco de imagens.
 */

require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/ImageService.php';

class ImageController {
    private PDO $pdo;
    private ImageService $images;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->images = new ImageService($this->pdo);
    }

    private function exigir(): void {
        if (
            empty($_SESSION['admin_autenticado']) ||
            !in_array($_SESSION['usuario_papel'] ?? '', ['admin_master','catalogo'], true)
        ) {
            http_response_code(403);
            respostaJson(false, 'Sem permissão para gerenciar imagens.');
        }
    }

    private function obterUso(): string {
        $uso = strtolower(trim((string)($_POST['uso'] ?? 'produto')));

        return in_array($uso, ['produto','banner'], true)
            ? $uso
            : 'produto';
    }

    public function servir(): never {
        $id = (int)($_GET['id'] ?? 0);
        $preset = trim((string)($_GET['preset'] ?? ''));
        $download = (int)($_GET['download'] ?? 0) === 1;

        if ($id <= 0) {
            http_response_code(404);
            exit;
        }

        $imagem = null;
        if ($preset !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT i.nome_arquivo, i.mime_type AS imagem_mime, d.mime_type, d.caminho_arquivo, d.arquivo
                 FROM imagem_derivadas d
                 INNER JOIN imagens i ON i.id=d.imagem_id
                 WHERE d.imagem_id=:id AND d.preset=:preset AND i.ativo=1
                 LIMIT 1'
            );
            $stmt->execute([':id' => $id, ':preset' => $preset]);
            $imagem = $stmt->fetch();
        }

        if (!$imagem) {
            $stmt = $this->pdo->prepare(
                'SELECT nome_arquivo, mime_type, caminho_arquivo, arquivo
                 FROM imagens
                 WHERE id=:id AND ativo=1
                 LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $imagem = $stmt->fetch();
        }

        if (!$imagem) {
            http_response_code(404);
            exit;
        }

        $path = !empty($imagem['caminho_arquivo'])
            ? $this->images->caminhoAbsolutoSeguro((string)$imagem['caminho_arquivo'])
            : false;

        // Compatibilidade: imagens antigas ainda gravadas como BLOB são migradas ao primeiro acesso.
        if (!$path || !is_file($path)) {
            $legacy = $imagem['arquivo'] ?? null;
            if ($legacy !== null && $legacy !== '') {
                $relative = $this->criarCaminhoLegado($id, (string)$imagem['nome_arquivo'], (string)($imagem['mime_type'] ?? 'image/webp'), $preset);
                $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $dir = dirname($absolute);
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                if (@file_put_contents($absolute, $legacy, LOCK_EX) !== false) {
                    if ($preset !== '') {
                        $this->pdo->prepare('UPDATE imagem_derivadas SET caminho_arquivo=:caminho, arquivo=NULL WHERE imagem_id=:id AND preset=:preset')
                            ->execute([':caminho' => $relative, ':id' => $id, ':preset' => $preset]);
                    } else {
                        $this->pdo->prepare('UPDATE imagens SET caminho_arquivo=:caminho, arquivo=NULL WHERE id=:id')
                            ->execute([':caminho' => $relative, ':id' => $id]);
                    }
                    $path = $absolute;
                }
            }
        }

        if (!$path || !is_file($path)) {
            http_response_code(404);
            exit;
        }

        $mime = (string)($imagem['mime_type'] ?: 'application/octet-stream');
        $size = @filesize($path);
        header('Content-Type: ' . $mime);
        if ($size !== false) header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=31536000, immutable');

        if ($download) {
            header('Content-Disposition: attachment; filename="' . basename((string)$imagem['nome_arquivo']) . '"');
        }

        readfile($path);
        exit;
    }

    private function criarCaminhoLegado(int $id, string $nome, string $mime, string $preset = ''): string {
        $folder = $preset !== '' && str_starts_with($preset, 'banner_') ? 'banners' : 'produtos';
        $base = pathinfo($nome, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?: 'imagem';
        $ext = match (strtolower($mime)) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            default => 'webp'
        };
        $suffix = $preset !== '' ? '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $preset) : '';
        return 'img/' . $folder . '/' . $id . '-' . trim($base, '-_') . $suffix . '.' . $ext;
    }

    public function listar(): never {
        $this->exigir();

        $stmt = $this->pdo->query(
            'SELECT
                id,
                nome_arquivo,
                mime_type,
                tamanho_bytes,
                alt_text,
                caminho_arquivo,
                uso,
                largura,
                altura,
                mime_original,
                tipo_midia,
                processado_em,
                criado_em
             FROM imagens
             WHERE ativo=1
             ORDER BY criado_em DESC'
        );

        respostaJson(true, '', [
            'imagens' => $stmt->fetchAll()
        ]);
    }

    public function upload(): never {
        $this->exigir();

        if (!isset($_FILES['imagem'])) {
            respostaJson(false, 'Nenhum arquivo foi recebido pelo servidor.');
        }

        $uploadError = (int)($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $mensagens = [
                UPLOAD_ERR_INI_SIZE => 'A imagem excede o limite upload_max_filesize do PHP.',
                UPLOAD_ERR_FORM_SIZE => 'A imagem excede o tamanho máximo permitido pelo formulário.',
                UPLOAD_ERR_PARTIAL => 'O upload da imagem foi interrompido. Tente novamente.',
                UPLOAD_ERR_NO_FILE => 'Selecione uma imagem.',
                UPLOAD_ERR_NO_TMP_DIR => 'O PHP não encontrou a pasta temporária para o upload.',
                UPLOAD_ERR_CANT_WRITE => 'O PHP não conseguiu gravar o arquivo enviado no disco.',
                UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload da imagem.',
            ];

            respostaJson(false, $mensagens[$uploadError] ?? 'Não foi possível receber a imagem enviada.');
        }

        $tmp = (string)$_FILES['imagem']['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            respostaJson(false, 'O arquivo recebido não passou pela validação de upload do PHP.');
        }
        $info = $this->images->validarArquivo($tmp);
        $dados = file_get_contents($tmp);

        if ($dados === false) {
            respostaJson(false, 'Não foi possível ler a imagem enviada.');
        }

        $nome = basename((string)$_FILES['imagem']['name']);

        $alt = trim((string)($_POST['alt_text'] ?? '')) ?: pathinfo($nome, PATHINFO_FILENAME);
        $uso = $this->obterUso();
        $tipoMidia = trim((string)($_POST['tipo_midia'] ?? 'imagem'));
        if (!in_array($tipoMidia, ['imagem','modelo_3d','video'], true)) $tipoMidia = 'imagem';

        $processed = $this->images->processarParaBanco($dados, $uso);

        $id = $this->images->salvarProcessada(
            $processed,
            $nome,
            $alt,
            (int)$_SESSION['usuario_id']
        );
        $this->pdo->prepare('UPDATE imagens SET tipo_midia=:tipo WHERE id=:id')->execute([':tipo'=>$tipoMidia,':id'=>$id]);

        registrarAdminLog(
            $this->pdo,
            'imagem.criada',
            'imagem',
            $id,
            [
                'nome_original' => $nome,
                'uso' => $uso,
                'largura_original' => $info['width'],
                'altura_original' => $info['height']
            ]
        );

        respostaJson(true, 'Imagem processada e salva na pasta img.', [
            'id' => $id,
            'uso' => $uso,
            'largura' => $processed['master']['width'],
            'altura' => $processed['master']['height']
        ]);
    }

    public function excluir(): never {
        $this->exigir();

        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            respostaJson(false, 'Imagem inválida.');
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM imagens WHERE id=:id AND ativo=1 LIMIT 1');
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                respostaJson(false, 'Imagem não encontrada. Ela pode já ter sido removida; atualize a biblioteca.');
            }

            $contar = function (string $sql) use ($id): int {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                return (int)$stmt->fetchColumn();
            };

            $usos = [];
            $emProduto = $contar('SELECT COUNT(*) FROM produtos WHERE imagem_id=:id');
            $emGaleria = $contar('SELECT COUNT(DISTINCT v.produto_id) FROM produto_variante_imagens pvi INNER JOIN produto_variantes v ON v.id=pvi.variante_id WHERE pvi.imagem_id=:id');
            $emSwatch = $contar('SELECT COUNT(*) FROM produto_variantes WHERE swatch_imagem_id=:id');
            $emBanner = $contar('SELECT COUNT(*) FROM banners WHERE imagem_id=:id');

            if ($emProduto > 0) $usos[] = $emProduto === 1 ? 'imagem principal de 1 produto' : "imagem principal de {$emProduto} produtos";
            if ($emGaleria > 0) $usos[] = $emGaleria === 1 ? 'galeria de 1 produto' : "galeria de {$emGaleria} produtos";
            if ($emSwatch > 0) $usos[] = $emSwatch === 1 ? 'amostra de cor de 1 variação' : "amostra de cor de {$emSwatch} variações";
            if ($emBanner > 0) $usos[] = $emBanner === 1 ? 'imagem de 1 banner' : "imagem de {$emBanner} banners";

            if ($usos) {
                http_response_code(409);
                respostaJson(
                    false,
                    'Não é possível remover esta imagem porque ela está em uso: ' . implode(', ', $usos)
                    . '. Troque a imagem nesses locais e tente de novo.'
                );
            }

            $stmt = $this->pdo->prepare('UPDATE imagens SET ativo=0 WHERE id=:id');
            $stmt->execute([':id' => $id]);
        } catch (Throwable $e) {
            responderExcecao($e, 'Não foi possível remover a imagem. Tente novamente.', 'ImageController excluir');
        }

        try {
            registrarAdminLog($this->pdo, 'imagem.removida', 'imagem', $id);
        } catch (Throwable $logError) {
            error_log('SHINKA ImageController excluir (log): ' . $logError->getMessage());
        }

        respostaJson(true, 'Imagem removida da biblioteca.');
    }
}