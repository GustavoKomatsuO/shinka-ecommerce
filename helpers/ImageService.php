<?php
/**
 * SHINKA — Serviço de processamento de imagens.
 * Processa imagens de produtos e banners e salva os arquivos no sistema de arquivos.
 *
 * Recursos:
 * - Validação de MIME e tamanho.
 * - Redimensionamento proporcional.
 * - Upscale moderado de até 150%.
 * - Geração de versões derivadas.
 * - Conversão para WebP quando disponível.
 * - Nitidez leve.
 * - Geração de banner desktop e mobile.
 * - Prevenção de duplicidade por SHA-256.
 */

require_once __DIR__ . '/../public/config_db/database.php';
require_once __DIR__ . '/security.php';

class ImageService {
    private const MAX_INPUT_BYTES = 10 * 1024 * 1024;
    private const PRODUCT_MASTER = 1600;
    private const BANNER_WIDTH = 1920;
    private const BANNER_HEIGHT = 720;
    private const BANNER_MOBILE_WIDTH = 1080;
    private const BANNER_MOBILE_HEIGHT = 1350;

    private static bool $storageSchemaChecked = false;

    public function __construct(private PDO $pdo) {
        $this->garantirEstruturaDeArquivos();
    }

    /**
     * As imagens agora ficam no sistema de arquivos, em:
     *   C:\xampp\htdocs\img\produtos
     *   C:\xampp\htdocs\img\banners
     *
     * O banco guarda apenas o caminho relativo.
     */
    public function diretorioImg(): string {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'img';
    }

    public function caminhoAbsolutoSeguro(string $caminhoRelativo): string|false {
        $caminhoRelativo = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($caminhoRelativo));
        if ($caminhoRelativo === '') return false;

        $base = realpath($this->diretorioImg());
        if ($base === false) return false;

        $absolute = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $caminhoRelativo);
        if ($absolute === false) return false;

        $basePrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($absolute, $basePrefix, strlen($basePrefix)) !== 0 && $absolute !== $base) {
            return false;
        }

        return $absolute;
    }

    public function urlPublica(?string $caminhoRelativo): string {
        if (!$caminhoRelativo) return '';
        return '/' . ltrim(str_replace('\\', '/', $caminhoRelativo), '/');
    }

    private function garantirEstruturaDeArquivos(): void {
        if (self::$storageSchemaChecked) return;

        $checks = [
            ['imagens', 'caminho_arquivo', "ALTER TABLE imagens ADD COLUMN caminho_arquivo VARCHAR(500) NULL AFTER arquivo"],
            ['imagem_derivadas', 'caminho_arquivo', "ALTER TABLE imagem_derivadas ADD COLUMN caminho_arquivo VARCHAR(500) NULL AFTER arquivo"],
        ];

        foreach ($checks as [$table, $column, $alter]) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->exec($alter);
            }
        }

        // Banco legado: os BLOBs antigos podem continuar existindo durante a migração.
        foreach ([['imagens', 'arquivo'], ['imagem_derivadas', 'arquivo']] as [$table, $column]) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column AND IS_NULLABLE = 'YES'"
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->exec("ALTER TABLE {$table} MODIFY COLUMN arquivo MEDIUMBLOB NULL");
            }
        }

        foreach (['produtos', 'banners'] as $subdir) {
            $dir = $this->diretorioImg() . DIRECTORY_SEPARATOR . $subdir;
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
        }

        self::$storageSchemaChecked = true;
    }

    private function exigirGd(): void {
        if (!extension_loaded('gd')) {
            respostaJson(false, 'A extensão GD do PHP não está habilitada. Ative a extensão GD no php.ini do XAMPP e reinicie o Apache.');
        }
    }

    public function validarArquivo(string $tmpPath): array {
        if (!is_file($tmpPath)) {
            respostaJson(false, 'Arquivo de imagem não encontrado.');
        }

        $size = filesize($tmpPath);
        if ($size === false || $size <= 0 || $size > self::MAX_INPUT_BYTES) {
            respostaJson(false, 'A imagem deve ter no máximo 10 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        $permitidos = ['image/jpeg','image/png','image/webp','image/gif','image/avif'];

        if (!in_array($mime, $permitidos, true)) {
            respostaJson(false, 'Formato não permitido. Use JPG, PNG, WEBP, GIF ou AVIF.');
        }

        $info = @getimagesize($tmpPath);
        if (!$info || empty($info[0]) || empty($info[1])) {
            respostaJson(false, 'O arquivo não contém uma imagem válida.');
        }

        return [
            'mime' => $mime,
            'width' => (int)$info[0],
            'height' => (int)$info[1],
            'bytes' => (int)$size
        ];
    }

    public function processarParaBanco(string $dados, string $uso = 'produto'): array {
        $this->exigirGd();

        if (strlen($dados) > self::MAX_INPUT_BYTES) {
            respostaJson(false, 'A imagem deve ter no máximo 10 MB.');
        }

        $src = @imagecreatefromstring($dados);
        if (!$src) {
            $mime = $this->mimeFromBytes($dados);
            $extra = '';
            if ($mime === 'image/avif' && !function_exists('imagecreatefromavif')) {
                $extra = ' O PHP/GD desta instalação não possui suporte a AVIF. Salve a imagem como JPG, PNG ou WEBP, ou habilite o suporte AVIF do GD.';
            }
            respostaJson(false, 'Não foi possível decodificar esta imagem no PHP/GD.' . $extra);
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        $hasAlpha = $this->possuiTransparencia($src, $uso);

        if ($uso === 'banner') {
            $desktop = $this->criarCover($src, self::BANNER_WIDTH, self::BANNER_HEIGHT, false);
            $mobile = $this->criarCover($src, self::BANNER_MOBILE_WIDTH, self::BANNER_MOBILE_HEIGHT, false);

            $desktopEncoded = $this->encodeWebpOrJpeg($desktop, 88, false);
            $mobileEncoded = $this->encodeWebpOrJpeg($mobile, 86, false);

            $master = [
                'binary' => $desktopEncoded['binary'],
                'mime' => $desktopEncoded['mime'],
                'width' => self::BANNER_WIDTH,
                'height' => self::BANNER_HEIGHT
            ];

            $derivadas = [
                'banner_desktop' => [
                    'binary' => $desktopEncoded['binary'],
                    'mime' => $desktopEncoded['mime'],
                    'width' => self::BANNER_WIDTH,
                    'height' => self::BANNER_HEIGHT
                ],
                'banner_mobile' => [
                    'binary' => $mobileEncoded['binary'],
                    'mime' => $mobileEncoded['mime'],
                    'width' => self::BANNER_MOBILE_WIDTH,
                    'height' => self::BANNER_MOBILE_HEIGHT
                ]
            ];
        } else {
            $masterCanvas = $this->criarFit($src, self::PRODUCT_MASTER, self::PRODUCT_MASTER, $hasAlpha);

            if (!$hasAlpha) {
                $this->aplicarNitidez($masterCanvas);
            }

            $masterEncoded = $this->encodeWebpOrPng($masterCanvas, 90, $hasAlpha);

            $master = [
                'binary' => $masterEncoded['binary'],
                'mime' => $masterEncoded['mime'],
                'width' => imagesx($masterCanvas),
                'height' => imagesy($masterCanvas)
            ];

            $thumb = $this->criarFit($src, 480, 480, $hasAlpha);
            $medium = $this->criarFit($src, 960, 960, $hasAlpha);
            $large = $this->criarFit($src, 1400, 1400, $hasAlpha);
            $superZoom = $this->criarFit($src, 2200, 2200, $hasAlpha);

            if (!$hasAlpha) {
                $this->aplicarNitidez($thumb);
                $this->aplicarNitidez($medium);
                $this->aplicarNitidez($large);
                $this->aplicarNitidez($superZoom);
            }

            $thumbEncoded = $this->encodeWebpOrPng($thumb, 88, $hasAlpha);
            $mediumEncoded = $this->encodeWebpOrPng($medium, 89, $hasAlpha);
            $largeEncoded = $this->encodeWebpOrPng($large, 91, $hasAlpha);
            $superZoomEncoded = $this->encodeWebpOrPng($superZoom, 92, $hasAlpha);

            $derivadas = [
                'thumb' => [
                    'binary' => $thumbEncoded['binary'],
                    'mime' => $thumbEncoded['mime'],
                    'width' => imagesx($thumb),
                    'height' => imagesy($thumb)
                ],
                'medium' => [
                    'binary' => $mediumEncoded['binary'],
                    'mime' => $mediumEncoded['mime'],
                    'width' => imagesx($medium),
                    'height' => imagesy($medium)
                ],
                'large' => [
                    'binary' => $largeEncoded['binary'],
                    'mime' => $largeEncoded['mime'],
                    'width' => imagesx($large),
                    'height' => imagesy($large)
                ],
                'superzoom' => [
                    'binary' => $superZoomEncoded['binary'],
                    'mime' => $superZoomEncoded['mime'],
                    'width' => imagesx($superZoom),
                    'height' => imagesy($superZoom)
                ]
            ];
        }

        $originalMime = $this->mimeFromBytes($dados);

        imagedestroy($src);

        if (isset($masterCanvas) && $masterCanvas instanceof GdImage) {
            imagedestroy($masterCanvas);
        }

        foreach (['desktop','mobile','thumb','medium','large','superZoom'] as $tmp) {
            if (isset($$tmp) && $$tmp instanceof GdImage) {
                imagedestroy($$tmp);
            }
        }

        return [
            'master' => $master,
            'derivadas' => $derivadas,
            'orig_width' => $srcWidth,
            'orig_height' => $srcHeight,
            'orig_mime' => $originalMime,
            'uso' => $uso
        ];
    }

    private function mimeFromBytes(string $dados): string {
        $tmp = tempnam(sys_get_temp_dir(), 'shinka_mime_');
        file_put_contents($tmp, $dados);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        @unlink($tmp);
        return $mime;
    }

    private function possuiTransparencia($img, string $uso): bool {
        return $uso === 'produto' && function_exists('imagealphablending') && function_exists('imagesavealpha');
    }

    private function criarCanvas(int $width, int $height, bool $alpha): GdImage {
        $canvas = imagecreatetruecolor($width, $height);

        if ($alpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
        } else {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
        }

        return $canvas;
    }

    private function criarFit($src, int $maxWidth, int $maxHeight, bool $alpha): GdImage {
        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);

        $scale = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
        $scale = min(max($scale, 1.0), 1.5);

        $dstWidth = max(1, (int)round($srcWidth * $scale));
        $dstHeight = max(1, (int)round($srcHeight * $scale));

        $canvas = $this->criarCanvas($dstWidth, $dstHeight, $alpha);

        imagecopyresampled(
            $canvas,
            $src,
            0, 0, 0, 0,
            $dstWidth,
            $dstHeight,
            $srcWidth,
            $srcHeight
        );

        return $canvas;
    }

    private function criarCover($src, int $dstWidth, int $dstHeight, bool $alpha): GdImage {
        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);

        $scale = max($dstWidth / $srcWidth, $dstHeight / $srcHeight);

        $scaledWidth = (int)ceil($srcWidth * $scale);
        $scaledHeight = (int)ceil($srcHeight * $scale);

        $scaled = $this->criarCanvas($scaledWidth, $scaledHeight, $alpha);

        imagecopyresampled(
            $scaled,
            $src,
            0, 0, 0, 0,
            $scaledWidth,
            $scaledHeight,
            $srcWidth,
            $srcHeight
        );

        $canvas = $this->criarCanvas($dstWidth, $dstHeight, $alpha);

        $x = (int)round(($scaledWidth - $dstWidth) / 2);
        $y = (int)round(($scaledHeight - $dstHeight) / 2);

        imagecopy(
            $canvas,
            $scaled,
            0, 0,
            $x, $y,
            $dstWidth,
            $dstHeight
        );

        imagedestroy($scaled);
        $this->aplicarNitidez($canvas);

        return $canvas;
    }

    private function aplicarNitidez($img): void {
        if (!function_exists('imageconvolution')) {
            return;
        }

        $matrix = [
            [-1, -1, -1],
            [-1, 12, -1],
            [-1, -1, -1]
        ];

        @imageconvolution($img, $matrix, 4, 0);
    }

    private function encodeWebpOrPng($img, int $quality, bool $alpha): array {
        ob_start();

        if (function_exists('imagewebp')) {
            if ($alpha) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            imagewebp($img, null, $quality);
            $binary = ob_get_clean();

            return [
                'binary' => $binary,
                'mime' => 'image/webp'
            ];
        }

        ob_clean();
        imagepng($img, null, 6);
        $binary = ob_get_clean();

        return [
            'binary' => $binary,
            'mime' => 'image/png'
        ];
    }

    private function encodeWebpOrJpeg($img, int $quality, bool $alpha): array {
        ob_start();

        if (function_exists('imagewebp')) {
            imagewebp($img, null, $quality);
            $binary = ob_get_clean();

            return [
                'binary' => $binary,
                'mime' => 'image/webp'
            ];
        }

        ob_clean();
        imagejpeg($img, null, $quality);
        $binary = ob_get_clean();

        return [
            'binary' => $binary,
            'mime' => 'image/jpeg'
        ];
    }

    public function salvarProcessada(array $processed, string $nomeOriginal, string $alt, int $usuarioId = 0): int {
        $master = $processed['master'];
        $hash = hash('sha256', $master['binary']);
        $folder = $processed['uso'] === 'banner' ? 'banners' : 'produtos';
        $masterExt = $this->extensaoMime($master['mime']);

        /*
         * O nome físico passa a ser baseado no texto alternativo informado
         * pelo Admin, mantendo um pequeno sufixo do SHA-256 para evitar
         * colisões. Assim, em vez de nomes pouco identificáveis, a pasta
         * fica com nomes como:
         *
         *   shinka-mirai-z-verde-titanio-frente-a81c2f4e.webp
         */
        $nomeBase = $this->nomeSeguroArquivo($alt);
        if ($nomeBase === '') {
            $nomeBase = $this->nomeSeguroArquivo($nomeOriginal);
        }

        $nomeBase .= '-' . substr($hash, 0, 8);

        $masterRelative = 'img/' . $folder . '/' . $nomeBase . '.' . $masterExt;
        $this->gravarArquivoRelativo($masterRelative, $master['binary']);

        $stmt = $this->pdo->prepare('SELECT id, caminho_arquivo FROM imagens WHERE sha256=:sha256 LIMIT 1');
        $stmt->execute([':sha256' => $hash]);
        $existing = $stmt->fetch();

        if ($existing) {
            if (empty($existing['caminho_arquivo']) || !is_file((string)$this->caminhoAbsolutoSeguro((string)$existing['caminho_arquivo']))) {
                $this->pdo->prepare('UPDATE imagens SET caminho_arquivo=:caminho WHERE id=:id')
                    ->execute([':caminho' => $masterRelative, ':id' => (int)$existing['id']]);
            }
            $this->garantirDerivadas((int)$existing['id'], $processed['derivadas'], $hash, $folder);
            return (int)$existing['id'];
        }

        $nomeFinal = substr($nomeBase . '.' . $masterExt, 0, 255);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO imagens (
                    nome_arquivo, mime_type, tamanho_bytes, alt_text, sha256, caminho_arquivo, arquivo,
                    criado_por, ativo, uso, largura, altura, mime_original, processado_em
                ) VALUES (
                    :nome, :mime, :tamanho, :alt, :sha256, :caminho, NULL,
                    :usuario, 1, :uso, :largura, :altura, :mime_original, NOW()
                )'
            );

            $stmt->bindValue(':nome', $nomeFinal);
            $stmt->bindValue(':mime', $master['mime']);
            $stmt->bindValue(':tamanho', strlen($master['binary']), PDO::PARAM_INT);
            $stmt->bindValue(':alt', $alt);
            $stmt->bindValue(':sha256', $hash);
            $stmt->bindValue(':caminho', $masterRelative);

            if ($usuarioId > 0) $stmt->bindValue(':usuario', $usuarioId, PDO::PARAM_INT);
            else $stmt->bindValue(':usuario', null, PDO::PARAM_NULL);

            $stmt->bindValue(':uso', $processed['uso']);
            $stmt->bindValue(':largura', $master['width'], PDO::PARAM_INT);
            $stmt->bindValue(':altura', $master['height'], PDO::PARAM_INT);
            $stmt->bindValue(':mime_original', $processed['orig_mime']);
            $stmt->execute();

            $id = (int)$this->pdo->lastInsertId();
            $this->garantirDerivadas($id, $processed['derivadas'], $hash, $folder);
            return $id;
        } catch (Throwable $e) {
            @unlink((string)$this->caminhoAbsolutoSeguro($masterRelative));
            throw $e;
        }
    }

    private function garantirDerivadas(int $imagemId, array $derivadas, string $hash = '', string $folder = 'produtos'): void {
        if ($hash === '') {
            $stmt = $this->pdo->prepare('SELECT sha256, uso FROM imagens WHERE id=:id LIMIT 1');
            $stmt->execute([':id' => $imagemId]);
            $row = $stmt->fetch();
            if (!$row) return;
            $hash = (string)$row['sha256'];
            $folder = ($row['uso'] ?? 'produto') === 'banner' ? 'banners' : 'produtos';
        }

        foreach ($derivadas as $preset => $data) {
            $ext = $this->extensaoMime($data['mime']);

            /*
             * As versões thumb/medium/large/superzoom não ficam mais
             * misturadas com a imagem principal. Elas são cache do sistema
             * e vão para uma subpasta própria.
             */
            $relative = 'img/' . $folder . '/derivadas/' . $hash . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $preset) . '.' . $ext;
            $this->gravarArquivoRelativo($relative, $data['binary']);

            $stmt = $this->pdo->prepare(
                'INSERT INTO imagem_derivadas (
                    imagem_id, preset, mime_type, largura, altura, tamanho_bytes, caminho_arquivo, arquivo
                ) VALUES (
                    :imagem_id, :preset, :mime, :largura, :altura, :tamanho, :caminho, NULL
                )
                ON DUPLICATE KEY UPDATE
                    mime_type=VALUES(mime_type),
                    largura=VALUES(largura),
                    altura=VALUES(altura),
                    tamanho_bytes=VALUES(tamanho_bytes),
                    caminho_arquivo=VALUES(caminho_arquivo)'
            );

            $stmt->execute([
                ':imagem_id' => $imagemId,
                ':preset' => $preset,
                ':mime' => $data['mime'],
                ':largura' => $data['width'],
                ':altura' => $data['height'],
                ':tamanho' => strlen($data['binary']),
                ':caminho' => $relative
            ]);
        }
    }

    private function gravarArquivoRelativo(string $relative, string $binary): void {
        $relativeFs = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);
        $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativeFs;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de imagens em: ' . $dir);
        }

        if (@file_put_contents($absolute, $binary, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar a imagem na pasta img. Verifique as permissões de escrita.');
        }
    }

    private function extensaoMime(string $mime): string {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            default => 'webp',
        };
    }

    public function ensureBannerDerivatives(int $imagemId): void {
        $stmt = $this->pdo->prepare(
            'SELECT id, caminho_arquivo, arquivo, sha256 FROM imagens WHERE id=:id AND ativo=1 LIMIT 1'
        );
        $stmt->execute([':id' => $imagemId]);
        $row = $stmt->fetch();

        if (!$row) {
            respostaJson(false, 'Imagem selecionada não encontrada.');
        }

        $absolute = !empty($row['caminho_arquivo'])
            ? $this->caminhoAbsolutoSeguro((string)$row['caminho_arquivo'])
            : false;
        $dados = ($absolute && is_file($absolute))
            ? @file_get_contents($absolute)
            : (($row['arquivo'] ?? null) !== null ? $row['arquivo'] : false);

        if ($dados === false || $dados === '') {
            respostaJson(false, 'A imagem não está disponível na pasta img. Reenvie a imagem pelo Admin.');
        }

        $processed = $this->processarParaBanco($dados, 'banner');
        $this->garantirDerivadas($imagemId, $processed['derivadas'], (string)$row['sha256'], 'banners');
    }

    /**
     * Gera um nome seguro para o arquivo físico a partir do texto alternativo.
     */
    private function nomeSeguroArquivo(string $texto): string {
        $texto = trim($texto);

        if ($texto === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
            if ($transliterado !== false) {
                $texto = $transliterado;
            }
        }

        $texto = preg_replace('/[^A-Za-z0-9]+/', '-', $texto) ?? '';
        $texto = trim($texto, '-');
        $texto = strtolower($texto);

        return substr($texto, 0, 180);
    }

    private function nomeProcessado(string $nomeOriginal, string $uso): string {
        $base = $this->nomeSeguroArquivo($nomeOriginal);
        if ($base === '') {
            $base = 'imagem-shinka';
        }

        $suffix = $uso === 'banner' ? '-banner' : '-produto';

        return substr($base . $suffix . '.webp', 0, 255);
    }
}