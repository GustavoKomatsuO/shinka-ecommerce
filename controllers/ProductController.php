<?php
/**
 * SHINKA — Controller de produtos.
 *
 * Responsável por:
 * - listar produtos no catálogo público;
 * - retornar categorias;
 * - retornar variações;
 * - retornar cores;
 * - retornar múltiplas imagens por cor;
 * - criar produtos;
 * - editar produtos;
 * - excluir produtos.
 */

require_once __DIR__ . '/../public/config_db/database.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../dao/ProdutoDAO.php';

class ProductController {
    private PDO $pdo;
    private ProdutoDAO $produtoDAO;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->produtoDAO = new ProdutoDAO($this->pdo);
    }

    /**
     * Verifica se o usuário possui permissão administrativa.
     */
    private function exigirAdmin(): void {
        $autenticado =
            !empty(
                $_SESSION['admin_autenticado']
            );

        $papel =
            $_SESSION['usuario_papel']
            ?? '';

        if (
            !$autenticado ||
            !in_array(
                $papel,
                [
                    'admin_master',
                    'catalogo'
                ],
                true
            )
        ) {
            http_response_code(403);

            respostaJson(
                false,
                'Sem permissão para acessar o catálogo.'
            );
        }
    }

    /**
     * Gera um slug seguro.
     */
    private function gerarSlug(
        string $nome
    ): string {
        $nome =
            mb_strtolower(
                trim($nome)
            );

        $map = [
            'á' => 'a',
            'à' => 'a',
            'ã' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'õ' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c'
        ];

        $nome =
            strtr(
                $nome,
                $map
            );

        $nome =
            preg_replace(
                '/[^a-z0-9]+/',
                '-',
                $nome
            );

        $nome =
            trim(
                $nome,
                '-'
            );

        return substr(
            $nome ?: 'produto',
            0,
            180
        );
    }

    /**
     * Decodifica o JSON das variações.
     */
    private function lerVariantes(
        string $json
    ): array {
        if (
            trim($json) === ''
        ) {
            return [];
        }

        try {
            $dados =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (
            Throwable $e
        ) {
            respostaJson(
                false,
                'As variações possuem formato inválido.'
            );
        }

        if (
            !is_array($dados)
        ) {
            respostaJson(
                false,
                'As variações possuem formato inválido.'
            );
        }

        $variantes = [];

        foreach (
            $dados as $ordem => $item
        ) {
            if (
                !is_array($item)
            ) {
                continue;
            }

            $nome =
                trim(
                    (string)(
                        $item['nome']
                        ?? ''
                    )
                );

            $sku =
                trim(
                    (string)(
                        $item['sku']
                        ?? ''
                    )
                );

            $corNome =
                trim(
                    (string)(
                        $item['cor_nome']
                        ?? ''
                    )
                );

            $corHex =
                trim(
                    (string)(
                        $item['cor_hex']
                        ?? ''
                    )
                );

            if (
                $corHex !== '' &&
                !preg_match(
                    '/^#[0-9A-Fa-f]{6}$/',
                    $corHex
                )
            ) {
                $corHex = '';
            }

            $swatchImagemId = max(0, (int)($item['swatch_imagem_id'] ?? 0));
            $imagens = [];

            foreach (
                (
                    $item['imagens']
                    ?? []
                ) as $imagem
            ) {
                if (
                    is_array($imagem)
                ) {
                    $imagemId =
                        (int)(
                            $imagem['id']
                            ??
                            $imagem['imagem_id']
                            ??
                            0
                        );

                    $principal =
                        (int)(
                            $imagem['principal']
                            ??
                            0
                        );
                    $altText = trim((string)($imagem['alt_text'] ?? ''));
                    $tipoMidia = trim((string)($imagem['tipo_midia'] ?? 'imagem'));
                    if (!in_array($tipoMidia, ['imagem','modelo_3d','video'], true)) {
                        $tipoMidia = 'imagem';
                    }
                    $midiaUrl = trim((string)($imagem['midia_url'] ?? ''));
                } else {
                    $imagemId =
                        (int)$imagem;

                    $principal = 0;
                    $altText = '';
                    $tipoMidia = 'imagem';
                    $midiaUrl = '';
                }

                if (
                    $imagemId > 0
                ) {
                    $imagens[] = [
                        'id' =>
                            $imagemId,
                        'principal' =>
                            $principal,
                        'alt_text' =>
                            $altText !== '' ? mb_substr($altText, 0, 255) : null,
                        'tipo_midia' =>
                            $tipoMidia,
                        'midia_url' =>
                            $midiaUrl !== '' ? mb_substr($midiaUrl, 0, 500) : null
                    ];
                }
            }

            if (
                $nome === '' &&
                $corNome === '' &&
                !$imagens
            ) {
                continue;
            }

            $variantes[] = [
                'nome' =>
                    $nome !== ''
                        ? $nome
                        : $corNome,

                'sku' =>
                    $sku !== ''
                        ? $sku
                        : null,

                'cor_nome' =>
                    $corNome !== ''
                        ? $corNome
                        : null,

                'cor_hex' =>
                    $corHex !== ''
                        ? $corHex
                        : null,

                'swatch_imagem_id' =>
                    $swatchImagemId > 0 ? $swatchImagemId : null,

                'ordem' =>
                    (int)$ordem,

                'imagens' =>
                    $imagens
            ];
        }

        return $variantes;
    }

    /**
     * Busca imagens vinculadas à variação.
     */
    private function buscarImagensVariacao(
        int $varianteId
    ): array {
        $stmt =
            $this->pdo->prepare(
                "
                SELECT
                    pvi.imagem_id AS id,
                    pvi.ordem,
                    pvi.principal,
                    i.nome_arquivo,
                    i.alt_text AS biblioteca_alt_text,
                    pvi.alt_text,
                    pvi.tipo_midia,
                    pvi.midia_url
                FROM produto_variante_imagens pvi
                INNER JOIN imagens i
                    ON i.id = pvi.imagem_id
                WHERE pvi.variante_id = :variante_id
                  AND i.ativo = 1
                ORDER BY
                    pvi.principal DESC,
                    pvi.ordem ASC,
                    pvi.imagem_id ASC
                "
            );

        $stmt->execute([
            ':variante_id' =>
                $varianteId
        ]);

        $imagens =
            $stmt->fetchAll();

        foreach (
            $imagens as &$imagem
        ) {
            $imagem['id'] =
                (int)$imagem['id'];

            $imagem['ordem'] =
                (int)$imagem['ordem'];

            $imagem['principal'] =
                (int)$imagem['principal'];
            $imagem['tipo_midia'] = $imagem['tipo_midia'] ?: 'imagem';
            $imagem['alt_text'] = $imagem['alt_text'] ?: ($imagem['biblioteca_alt_text'] ?? $imagem['nome_arquivo']);
        }

        unset($imagem);

        return $imagens;
    }

    /**
     * Busca todas as variações do produto.
     */
    private function buscarVariantes(
        int $produtoId
    ): array {
        $stmt =
            $this->pdo->prepare(
                "
                SELECT
                    id,
                    nome,
                    sku,
                    cor_nome,
                    cor_hex,
                    swatch_imagem_id,
                    ordem,
                    ativo
                FROM produto_variantes
                WHERE produto_id = :produto_id
                ORDER BY
                    ordem ASC,
                    id ASC
                "
            );

        $stmt->execute([
            ':produto_id' =>
                $produtoId
        ]);

        $variantes =
            $stmt->fetchAll();

        foreach (
            $variantes as &$variante
        ) {
            $variante['id'] =
                (int)$variante['id'];

            $variante['ordem'] =
                (int)$variante['ordem'];

            $variante['ativo'] =
                (int)$variante['ativo'];

            $variante['imagens'] =
                $this->buscarImagensVariacao(
                    (int)$variante['id']
                );
        }

        unset($variante);

        return $variantes;
    }

    /**
     * ==========================================================
     * CATÁLOGO PÚBLICO
     * ==========================================================
     */
    public function listarPublico(): never {
        $categoria =
            trim(
                (string)(
                    $_GET['categoria']
                    ?? ''
                )
            );

        $sql = "
            SELECT
                p.id,
                p.nome,
                p.slug,
                p.familia,
                p.descricao,
                p.especificacoes,
                p.preco,
                p.preco_promocional,
                p.preco_anterior,
                p.badge,
                p.estoque,
                p.estoque_minimo,
                p.destaque,
                p.ativo,
                p.imagem_id,
                c.nome AS categoria_nome,
                c.slug AS categoria_slug
            FROM produtos p
            INNER JOIN categorias c
                ON c.id = p.categoria_id
            WHERE p.ativo = 1
              AND c.ativo = 1
        ";

        $params = [];

        if (
            $categoria !== ''
        ) {
            $sql .= "
                AND c.slug = :categoria
            ";

            $params[':categoria'] =
                $categoria;
        }

        $sql .= "
            ORDER BY
                p.destaque DESC,
                p.id DESC
        ";

        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute(
            $params
        );

        $produtos =
            $stmt->fetchAll();

        foreach (
            $produtos as &$produto
        ) {
            $produto['id'] =
                (int)$produto['id'];

            $produto['imagem_id'] =
                $produto['imagem_id'] !== null
                    ? (int)$produto['imagem_id']
                    : null;

            $produto['preco'] =
                (float)$produto['preco'];

            $produto['preco_promocional'] =
                $produto['preco_promocional'] !== null
                    ? (float)$produto['preco_promocional']
                    : 0;

            $produto['preco_anterior'] =
                $produto['preco_anterior'] !== null
                    ? (float)$produto['preco_anterior']
                    : 0;

            $produto['estoque'] =
                (int)$produto['estoque'];

            $produto['estoque_minimo'] =
                (int)$produto['estoque_minimo'];

            $produto['destaque'] =
                (int)$produto['destaque'];

            $produto['ativo'] =
                (int)$produto['ativo'];

            $produto['variantes'] =
                $this->buscarVariantes(
                    (int)$produto['id']
                );

            /**
             * Produto antigo sem variações:
             * cria uma variação virtual.
             */
            if (
                !$produto['variantes'] &&
                !empty(
                    $produto['imagem_id']
                )
            ) {
                $produto['variantes'] = [
                    [
                        'id' => 0,
                        'nome' => 'Padrão',
                        'sku' =>
                            $produto['slug'],
                        'cor_nome' =>
                            'Padrão',
                        'cor_hex' =>
                            '#D5D9E0',
                        'ordem' => 0,
                        'ativo' => 1,
                        'imagens' => [
                            [
                                'id' =>
                                    $produto['imagem_id'],
                                'ordem' => 0,
                                'principal' => 1,
                                'nome_arquivo' =>
                                    '',
                                'alt_text' =>
                                    $produto['nome']
                            ]
                        ]
                    ]
                ];
            }
        }

        unset($produto);

        respostaJson(
            true,
            '',
            [
                'categoria' =>
                    $categoria,

                'produtos' =>
                    $produtos
            ]
        );
    }

    /**
     * ==========================================================
     * PAINEL ADMINISTRATIVO
     * ==========================================================
     *
     * Retorna produtos + categorias + variações.
     */
    public function painel(): never
    {
        $this->exigirAdmin();
        $categorias = $this->pdo->query('SELECT id,nome,slug,ativo FROM categorias WHERE ativo=1 ORDER BY nome')->fetchAll();
        $produtos = $this->produtoDAO->listarAdmin();
        foreach($produtos as &$produto){
            $produto['id']=(int)$produto['id'];
            $produto['categoria_id']=(int)$produto['categoria_id'];
            $produto['imagem_id']=$produto['imagem_id']!==null?(int)$produto['imagem_id']:null;
            $produto['preco']=(float)$produto['preco'];
            $produto['preco_promocional']=$produto['preco_promocional']!==null?(float)$produto['preco_promocional']:null;
            $produto['preco_anterior']=$produto['preco_anterior']!==null?(float)$produto['preco_anterior']:null;
            $produto['estoque']=(int)$produto['estoque'];
            $produto['estoque_minimo']=(int)$produto['estoque_minimo'];
            $produto['peso_kg']=$produto['peso_kg']!==null?(float)$produto['peso_kg']:null;
            $produto['altura_cm']=$produto['altura_cm']!==null?(float)$produto['altura_cm']:null;
            $produto['largura_cm']=$produto['largura_cm']!==null?(float)$produto['largura_cm']:null;
            $produto['comprimento_cm']=$produto['comprimento_cm']!==null?(float)$produto['comprimento_cm']:null;
            $produto['destaque']=(int)$produto['destaque'];
            $produto['ativo']=(int)$produto['ativo'];
            $produto['variantes']=$this->buscarVariantes($produto['id']);
        }
        unset($produto);
        respostaJson(true,'',['produtos'=>$produtos,'categorias'=>$categorias]);
    }


    /**
     * ==========================================================
     * SALVAR
     * ==========================================================
     */
    public function salvar(): never
    {
        $this->exigirAdmin();
        $id=(int)($_POST['id']??0);
        $categoriaId=(int)($_POST['categoria_id']??0);
        $nome=trim((string)($_POST['nome']??''));
        $slugRecebido=trim((string)($_POST['slug']??''));
        $familia=trim((string)($_POST['familia']??''));
        $descricao=trim((string)($_POST['descricao']??''));
        $especificacoes=trim((string)($_POST['especificacoes']??''));
        $preco=(float)str_replace(',','.',(string)($_POST['preco']??0));
        $precoPromocional=trim((string)($_POST['preco_promocional']??''))===''?null:(float)str_replace(',','.',(string)$_POST['preco_promocional']);
        $precoAnterior=trim((string)($_POST['preco_anterior']??''))===''?null:(float)str_replace(',','.',(string)$_POST['preco_anterior']);
        $badge=trim((string)($_POST['badge']??''));
        $estoqueInformado=array_key_exists('estoque',$_POST);
        $estoqueMinInformado=array_key_exists('estoque_minimo',$_POST);
        $estoque=$estoqueInformado?max(0,(int)$_POST['estoque']):null;
        $estoqueMinimo=$estoqueMinInformado?max(0,(int)$_POST['estoque_minimo']):null;
        $destaque=isset($_POST['destaque'])?1:0;
        $ativo=isset($_POST['ativo'])?1:0;
        $imagemId=trim((string)($_POST['imagem_id']??''))===''?null:(int)$_POST['imagem_id'];

        $cfg=require __DIR__.'/../config/correios.php';
        $pesoInformado=array_key_exists('peso_kg',$_POST)&&trim((string)$_POST['peso_kg'])!=='';
        $alturaInformada=array_key_exists('altura_cm',$_POST)&&trim((string)$_POST['altura_cm'])!=='';
        $larguraInformada=array_key_exists('largura_cm',$_POST)&&trim((string)$_POST['largura_cm'])!=='';
        $comprimentoInformado=array_key_exists('comprimento_cm',$_POST)&&trim((string)$_POST['comprimento_cm'])!=='';
        $pesoKg=$pesoInformado?(float)str_replace(',','.',(string)$_POST['peso_kg']):null;
        $alturaCm=$alturaInformada?(float)str_replace(',','.',(string)$_POST['altura_cm']):null;
        $larguraCm=$larguraInformada?(float)str_replace(',','.',(string)$_POST['largura_cm']):null;
        $comprimentoCm=$comprimentoInformado?(float)str_replace(',','.',(string)$_POST['comprimento_cm']):null;

        if($id>0){
            $atual=$this->produtoDAO->buscar($id);
            if(!$atual) respostaJson(false,'Produto não encontrado.');
            if(!$estoqueInformado) $estoque=(int)$atual['estoque'];
            if(!$estoqueMinInformado) $estoqueMinimo=(int)$atual['estoque_minimo'];
            if(!$pesoInformado) $pesoKg=$atual['peso_kg']!==null?(float)$atual['peso_kg']:(float)$cfg['peso_kg_padrao'];
            if(!$alturaInformada) $alturaCm=$atual['altura_cm']!==null?(float)$atual['altura_cm']:(float)$cfg['altura_cm_padrao'];
            if(!$larguraInformada) $larguraCm=$atual['largura_cm']!==null?(float)$atual['largura_cm']:(float)$cfg['largura_cm_padrao'];
            if(!$comprimentoInformado) $comprimentoCm=$atual['comprimento_cm']!==null?(float)$atual['comprimento_cm']:(float)$cfg['comprimento_cm_padrao'];
        }
        $estoque=$estoque??0; $estoqueMinimo=$estoqueMinimo??5;
        $pesoKg=$pesoKg>0?$pesoKg:(float)$cfg['peso_kg_padrao'];
        $alturaCm=$alturaCm>0?$alturaCm:(float)$cfg['altura_cm_padrao'];
        $larguraCm=$larguraCm>0?$larguraCm:(float)$cfg['largura_cm_padrao'];
        $comprimentoCm=$comprimentoCm>0?$comprimentoCm:(float)$cfg['comprimento_cm_padrao'];

        if($nome==='') respostaJson(false,'Informe o nome do produto.');
        if($categoriaId<1) respostaJson(false,'Selecione uma categoria.');
        if($preco<=0) respostaJson(false,'Informe um preço válido.');
        if($precoPromocional!==null && $precoPromocional>=$preco) respostaJson(false,'O preço promocional deve ser menor que o preço normal.');
        if($pesoKg<=0||$alturaCm<=0||$larguraCm<=0||$comprimentoCm<=0) respostaJson(false,'Peso e dimensões devem ser maiores que zero.');

        $stmt=$this->pdo->prepare('SELECT id FROM categorias WHERE id=:id AND ativo=1 LIMIT 1');
        $stmt->execute([':id'=>$categoriaId]);
        if(!$stmt->fetch()) respostaJson(false,'Categoria não encontrada.');

        $slugBase=$this->gerarSlug($slugRecebido!==''?$slugRecebido:$nome);
        $slug=$slugBase; $contador=2;
        while($this->produtoDAO->existeSlug($slug,$id)){
            $slug=$slugBase.'-'.($contador++);
            if($contador>9999) respostaJson(false,'Não foi possível gerar um slug único.');
        }

        $variantes=$this->lerVariantes((string)($_POST['variantes']??''));

        // SKU precisa ser único dentro do produto (uk_variantes_produto_sku).
        $skusVistos=[];
        foreach($variantes as $variante){
            if($variante['sku']===null) continue;
            $chaveSku=mb_strtolower($variante['sku']);
            if(isset($skusVistos[$chaveSku])) respostaJson(false,'O SKU "'.$variante['sku'].'" está repetido em mais de uma variação. Cada variação precisa ter um SKU diferente.');
            $skusVistos[$chaveSku]=true;
        }

        // Imagens referenciadas precisam existir na biblioteca (senão o MySQL recusa a chave estrangeira).
        $stmtImg=$this->pdo->prepare('SELECT id FROM imagens WHERE id=:id AND ativo=1 LIMIT 1');
        if($imagemId!==null){
            $stmtImg->execute([':id'=>$imagemId]);
            if(!$stmtImg->fetch()) respostaJson(false,'A imagem principal escolhida não existe mais na biblioteca. Selecione outra imagem.');
        }
        foreach($variantes as $variante){
            if($variante['swatch_imagem_id']===null) continue;
            $stmtImg->execute([':id'=>$variante['swatch_imagem_id']]);
            if(!$stmtImg->fetch()) respostaJson(false,'A imagem da amostra de cor da variação "'.$variante['nome'].'" não existe mais na biblioteca. Escolha outra imagem.');
        }

        try{
            $this->pdo->beginTransaction();
            $dadosProduto=[
                'categoria_id'=>$categoriaId,'imagem_id'=>$imagemId,'nome'=>$nome,'slug'=>$slug,'familia'=>$familia,
                'descricao'=>$descricao,'especificacoes'=>$especificacoes,'preco'=>$preco,'preco_promocional'=>$precoPromocional,
                'preco_anterior'=>$precoAnterior,'badge'=>$badge,'estoque'=>$estoque,'estoque_minimo'=>$estoqueMinimo,
                'peso_kg'=>$pesoKg,'altura_cm'=>$alturaCm,'largura_cm'=>$larguraCm,'comprimento_cm'=>$comprimentoCm,
                'destaque'=>$destaque,'ativo'=>$ativo
            ];

            $foiEdicao=$id>0;
            if($foiEdicao){
                $this->produtoDAO->atualizar($id,$dadosProduto);
                $stmt=$this->pdo->prepare('DELETE FROM produto_variante_imagens WHERE variante_id IN (SELECT id FROM produto_variantes WHERE produto_id=:produto_id)');
                $stmt->execute([':produto_id'=>$id]);
                $stmt=$this->pdo->prepare('DELETE FROM produto_variantes WHERE produto_id=:produto_id');
                $stmt->execute([':produto_id'=>$id]);
            }else{
                $id=$this->produtoDAO->inserir($dadosProduto);
            }

            foreach($variantes as $variante){
                $stmt=$this->pdo->prepare('INSERT INTO produto_variantes (produto_id,nome,sku,cor_nome,cor_hex,swatch_imagem_id,ordem,ativo) VALUES (:produto_id,:nome,:sku,:cor_nome,:cor_hex,:swatch_imagem_id,:ordem,1)');
                $stmt->execute([
                    ':produto_id'=>$id,':nome'=>$variante['nome'],':sku'=>$variante['sku'],':cor_nome'=>$variante['cor_nome'],
                    ':cor_hex'=>$variante['cor_hex'],':swatch_imagem_id'=>$variante['swatch_imagem_id'],':ordem'=>$variante['ordem']
                ]);
                $varianteId=(int)$this->pdo->lastInsertId();
                foreach($variante['imagens'] as $ordemImagem=>$imagem){
                    $imagemIdVariante=(int)$imagem['id'];
                    $stmtImagem=$this->pdo->prepare('SELECT id FROM imagens WHERE id=:id AND ativo=1 LIMIT 1');
                    $stmtImagem->execute([':id'=>$imagemIdVariante]);
                    if(!$stmtImagem->fetch()) continue;
                    $stmtLink=$this->pdo->prepare('INSERT INTO produto_variante_imagens (variante_id,imagem_id,ordem,principal,alt_text,tipo_midia,midia_url) VALUES (:variante_id,:imagem_id,:ordem,:principal,:alt_text,:tipo_midia,:midia_url)');
                    $tipo=in_array(($imagem['tipo_midia']??'imagem'),['imagem','modelo_3d','video'],true)?$imagem['tipo_midia']:'imagem';
                    $stmtLink->execute([
                        ':variante_id'=>$varianteId,':imagem_id'=>$imagemIdVariante,':ordem'=>(int)$ordemImagem,
                        ':principal'=>(int)($imagem['principal']??0),':alt_text'=>!empty($imagem['alt_text'])?mb_substr((string)$imagem['alt_text'],0,255):null,
                        ':tipo_midia'=>$tipo,':midia_url'=>!empty($imagem['midia_url'])?mb_substr((string)$imagem['midia_url'],0,500):null
                    ]);
                }
            }

            if(function_exists('registrarAdminLog')) registrarAdminLog($this->pdo,$foiEdicao?'produto.salvo':'produto.criado','produto',$id,['nome'=>$nome,'variantes'=>count($variantes)]);
            $this->pdo->commit();
            respostaJson(true,$foiEdicao?'Produto atualizado com sucesso.':'Produto criado com sucesso.',['id'=>$id]);
        }catch(Throwable $e){
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            // Corrida entre dois administradores: o slug/SKU pode ter sido ocupado
            // entre a verificação acima e o INSERT. Responde com a causa real.
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062){
                $detalhe=$e->getMessage();
                if(str_contains($detalhe,'uk_produtos_slug')) respostaJson(false,'Já existe outro produto com este endereço (slug). Altere o nome ou o slug e tente novamente.');
                if(str_contains($detalhe,'sku')) respostaJson(false,'Já existe uma variação com este SKU neste produto. Use um SKU diferente para cada variação.');
            }
            responderExcecao($e,'Não foi possível salvar o produto. Tente novamente.','ProductController salvar');
        }
    }


    /**
     * ==========================================================
     * EXCLUIR
     * ==========================================================
     */
    public function excluir(): never
    {
        $this->exigirAdmin();
        $id=(int)($_POST['id']??0);
        if($id<1) respostaJson(false,'Produto inválido.');
        try{
            $this->pdo->beginTransaction();
            // O DAO trava o produto e rejeita a exclusão se algum cliente já o
            // comprou (ProdutoEmUsoException). Só depois dessa verificação ele
            // apaga variações/imagens e o produto, então uma exclusão recusada
            // não altera nada.
            $this->produtoDAO->excluir($id);
            $this->pdo->commit();
            try{
                if(function_exists('registrarAdminLog')) registrarAdminLog($this->pdo,'produto.excluido','produto',$id);
            }catch(Throwable $logError){
                // Falha ao registrar a auditoria não desfaz uma exclusão já concluída.
                error_log('SHINKA ProductController excluir (log): '.$logError->getMessage());
            }
            respostaJson(true,'Produto excluído com sucesso.');
        }catch(Throwable $e){
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            responderExcecao($e,'Não foi possível excluir o produto. Tente novamente.','ProductController excluir');
        }
    }

}