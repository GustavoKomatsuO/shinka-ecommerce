<?php
/**
 * SHINKA — Marketing e banners.
 * Permite selecionar imagem existente ou enviar uma nova imagem.
 * Novas imagens de banner são processadas automaticamente.
 */

require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/ImageService.php';

class MarketingController {
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
            respostaJson(false, 'Sem permissão para gerenciar campanhas.');
        }
    }

    /**
     * Retorna somente os banners ativos para o carrossel público da home.
     * Esta rota não exige login, pois é consumida pela loja do cliente.
     */
    public function bannersPublicos(): never {
        $stmt = $this->pdo->query(
            'SELECT
                b.id,
                b.titulo,
                b.subtitulo,
                b.link,
                b.ordem,
                b.ativo,
                b.imagem_id,
                i.nome_arquivo,
                i.alt_text
             FROM banners b
             LEFT JOIN imagens i ON i.id=b.imagem_id AND i.ativo=1
             WHERE b.ativo=1
             ORDER BY b.ordem ASC, b.id DESC'
        );

        respostaJson(true, '', [
            'banners' => $stmt->fetchAll()
        ]);
    }

    public function banners(): never {
        $this->exigir();

        $stmt = $this->pdo->query(
            'SELECT
                b.id,
                b.titulo,
                b.subtitulo,
                b.link,
                b.ordem,
                b.ativo,
                b.imagem_id,
                i.nome_arquivo,
                i.alt_text,
                i.largura,
                i.altura,
                i.uso
             FROM banners b
             LEFT JOIN imagens i ON i.id=b.imagem_id
             ORDER BY b.ordem, b.id DESC'
        );

        respostaJson(true, '', [
            'banners' => $stmt->fetchAll()
        ]);
    }

    public function salvarBanner(): never {
        $this->exigir();

        $id = (int)($_POST['id'] ?? 0);
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $subtitulo = trim((string)($_POST['subtitulo'] ?? ''));
        $link = trim((string)($_POST['link'] ?? ''));
        $ordem = (int)($_POST['ordem'] ?? 0);

        if ($link !== '' && preg_match('/^(?:javascript|data|vbscript):/i', $link)) {
            respostaJson(false, 'O link do banner não pode usar esquemas de script.');
        }
        $ativo = isset($_POST['ativo']) ? (int)!!$_POST['ativo'] : 1;

        $imagemId = trim((string)($_POST['imagem_id'] ?? '')) === ''
            ? null
            : (int)$_POST['imagem_id'];

        if ($titulo === '') {
            respostaJson(false, 'O título do banner é obrigatório.');
        }

        /**
         * Se houver um novo arquivo, ele ganha prioridade
         * sobre a imagem já selecionada.
         */
        if (
            isset($_FILES['imagem_banner']) &&
            $_FILES['imagem_banner']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            if (
                $_FILES['imagem_banner']['error'] !== UPLOAD_ERR_OK
            ) {
                respostaJson(false, 'Não foi possível receber a imagem do banner.');
            }

            $tmp = $_FILES['imagem_banner']['tmp_name'];

            $this->images->validarArquivo($tmp);

            $dados = file_get_contents($tmp);

            if ($dados === false) {
                respostaJson(false, 'Não foi possível ler a imagem do banner.');
            }

            $nome = basename((string)$_FILES['imagem_banner']['name']);

            $alt = trim(
                (string)($_POST['alt_text_banner'] ?? '')
            ) ?: $titulo;

            $processed = $this->images->processarParaBanco(
                $dados,
                'banner'
            );

            $imagemId = $this->images->salvarProcessada(
                $processed,
                $nome,
                $alt,
                null,
                (int)$_SESSION['usuario_id']
            );

            registrarAdminLog(
                $this->pdo,
                'banner.imagem_processada',
                'imagem',
                $imagemId,
                [
                    'largura' => $processed['master']['width'],
                    'altura' => $processed['master']['height'],
                    'uso' => 'banner'
                ]
            );
        }

        /**
         * Se foi escolhida uma imagem existente,
         * garantimos as versões desktop e mobile.
         */
        if ($imagemId !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM imagens WHERE id=:id AND ativo=1 LIMIT 1');
            $stmt->execute([':id' => $imagemId]);
            if (!$stmt->fetch()) {
                respostaJson(false, 'A imagem escolhida para o banner não existe mais na biblioteca. Selecione outra imagem.');
            }
            $this->images->ensureBannerDerivatives($imagemId);
        }

        if ($id > 0) {
            $stmt = $this->pdo->prepare('SELECT id FROM banners WHERE id=:id LIMIT 1');
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetch()) {
                respostaJson(false, 'Banner não encontrado. Ele pode ter sido excluído; atualize a lista.');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE banners
                 SET
                    imagem_id=:imagem_id,
                    titulo=:titulo,
                    subtitulo=:subtitulo,
                    link=:link,
                    ordem=:ordem,
                    ativo=:ativo
                 WHERE id=:id'
            );

            $stmt->execute([
                ':imagem_id' => $imagemId,
                ':titulo' => $titulo,
                ':subtitulo' => $subtitulo,
                ':link' => $link,
                ':ordem' => $ordem,
                ':ativo' => $ativo,
                ':id' => $id
            ]);

            registrarAdminLog(
                $this->pdo,
                'banner.atualizado',
                'banner',
                $id,
                [
                    'titulo' => $titulo,
                    'imagem_id' => $imagemId
                ]
            );

            respostaJson(true, 'Banner atualizado com imagem otimizada.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO banners (
                imagem_id,
                titulo,
                subtitulo,
                link,
                ordem,
                ativo
            ) VALUES (
                :imagem_id,
                :titulo,
                :subtitulo,
                :link,
                :ordem,
                :ativo
            )'
        );

        $stmt->execute([
            ':imagem_id' => $imagemId,
            ':titulo' => $titulo,
            ':subtitulo' => $subtitulo,
            ':link' => $link,
            ':ordem' => $ordem,
            ':ativo' => $ativo
        ]);

        $newId = (int)$this->pdo->lastInsertId();

        registrarAdminLog(
            $this->pdo,
            'banner.criado',
            'banner',
            $newId,
            [
                'titulo' => $titulo,
                'imagem_id' => $imagemId
            ]
        );

        respostaJson(true, 'Banner criado com imagem otimizada.', [
            'id' => $newId,
            'imagem_id' => $imagemId
        ]);
    }

    public function excluirBanner(): never {
        $this->exigir();

        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            respostaJson(false, 'Banner inválido.');
        }

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM banners WHERE id=:id'
            );

            $stmt->execute([':id' => $id]);

            // Antes dizia "Banner excluído" mesmo quando o id nem existia.
            if ($stmt->rowCount() < 1) {
                respostaJson(false, 'Banner não encontrado. Ele pode já ter sido excluído; atualize a lista.');
            }
        } catch (Throwable $e) {
            responderExcecao($e, 'Não foi possível excluir o banner. Tente novamente.', 'MarketingController excluirBanner');
        }

        try {
            registrarAdminLog($this->pdo, 'banner.excluido', 'banner', $id);
        } catch (Throwable $logError) {
            error_log('SHINKA MarketingController excluirBanner (log): ' . $logError->getMessage());
        }

        respostaJson(true, 'Banner excluído.');
    }

    public function cupons(): never {
        $this->exigir();

        $stmt = $this->pdo->query(
            'SELECT
                id,
                codigo,
                descricao,
                tipo,
                valor,
                valor_minimo,
                limite_uso,
                usado,
                inicio,
                fim,
                ativo
             FROM cupons
             ORDER BY criado_em DESC'
        );

        respostaJson(true, '', [
            'cupons' => $stmt->fetchAll()
        ]);
    }

    public function salvarCupom(): never {
        $this->exigir();

        $id = (int)($_POST['id'] ?? 0);
        $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $tipo = (string)($_POST['tipo'] ?? 'percentual');

        $valor = (float)str_replace(
            ',',
            '.',
            (string)($_POST['valor'] ?? 0)
        );

        $valorMinimo = (float)str_replace(
            ',',
            '.',
            (string)($_POST['valor_minimo'] ?? 0)
        );

        $limite = trim((string)($_POST['limite_uso'] ?? '')) === ''
            ? null
            : max(1, (int)$_POST['limite_uso']);

        $inicio = trim((string)($_POST['inicio'] ?? '')) ?: null;
        $fim = trim((string)($_POST['fim'] ?? '')) ?: null;
        $ativo = isset($_POST['ativo']) ? (int)!!$_POST['ativo'] : 1;

        if (
            $codigo === '' ||
            !in_array($tipo, ['percentual','valor'], true) ||
            $valor <= 0
        ) {
            respostaJson(false, 'Código, tipo e valor são obrigatórios.');
        }

        if ($tipo === 'percentual' && $valor > 100) {
            respostaJson(false, 'Um desconto percentual não pode ser maior que 100%.');
        }

        try {
            $params = [
                ':codigo' => $codigo,
                ':descricao' => $descricao,
                ':tipo' => $tipo,
                ':valor' => $valor,
                ':valor_minimo' => $valorMinimo,
                ':limite_uso' => $limite,
                ':inicio' => $inicio,
                ':fim' => $fim,
                ':ativo' => $ativo
            ];

            if ($id > 0) {
                $params[':id'] = $id;

                $stmt = $this->pdo->prepare(
                    'UPDATE cupons
                     SET
                        codigo=:codigo,
                        descricao=:descricao,
                        tipo=:tipo,
                        valor=:valor,
                        valor_minimo=:valor_minimo,
                        limite_uso=:limite_uso,
                        inicio=:inicio,
                        fim=:fim,
                        ativo=:ativo
                     WHERE id=:id'
                );

                $stmt->execute($params);

                registrarAdminLog(
                    $this->pdo,
                    'cupom.atualizado',
                    'cupom',
                    $id,
                    ['codigo' => $codigo]
                );

                respostaJson(true, 'Regra de desconto atualizada.');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO cupons (
                    codigo,
                    descricao,
                    tipo,
                    valor,
                    valor_minimo,
                    limite_uso,
                    inicio,
                    fim,
                    ativo
                ) VALUES (
                    :codigo,
                    :descricao,
                    :tipo,
                    :valor,
                    :valor_minimo,
                    :limite_uso,
                    :inicio,
                    :fim,
                    :ativo
                )'
            );

            $stmt->execute($params);

            $newId = (int)$this->pdo->lastInsertId();

            registrarAdminLog(
                $this->pdo,
                'cupom.criado',
                'cupom',
                $newId,
                ['codigo' => $codigo]
            );

            respostaJson(true, 'Regra de desconto criada.');
        } catch (PDOException $e) {
            respostaJson(
                false,
                $e->getCode() === '23000'
                    ? 'Código de cupom já cadastrado.'
                    : 'Não foi possível salvar a regra.'
            );
        }
    }
}