<?php
/**
 * Operações do Admin Console:
 * - dashboard/KPIs;
 * - pedidos e OMS;
 * - estoque por centro de distribuição;
 * - clientes e histórico resumido;
 * - sessão atual e permissões.
 */
require_once __DIR__ . '/../public/config_db/database.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../dao/CategoriaDAO.php';

class AdminController
{
    private PDO $pdo;
    private CategoriaDAO $categoriaDAO;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->categoriaDAO = new CategoriaDAO($this->pdo);
    }

    private function exigir(array $papeis): void
    {
        if (empty($_SESSION['admin_autenticado']) || !in_array($_SESSION['usuario_papel'] ?? '', $papeis, true)) {
            http_response_code(403);
            respostaJson(false, 'Acesso restrito ao painel administrativo.');
        }
    }

    public function me(): never
    {
        $this->exigir(['admin_master', 'catalogo', 'logistica', 'suporte']);
        respostaJson(true, '', [
            'usuario' => [
                'id' => (int) $_SESSION['usuario_id'],
                'nome' => $_SESSION['usuario_nome'],
                'email' => $_SESSION['usuario_email'],
                'papel' => $_SESSION['usuario_papel'],
            ],
        ]);
    }

    public function dashboard(): never
    {
        $this->exigir(['admin_master', 'catalogo', 'logistica', 'suporte']);

        $clientes = (int) $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE papel='cliente' AND ativo=1")->fetchColumn();
        $produtos = (int) $this->pdo->query('SELECT COUNT(*) FROM produtos WHERE ativo=1')->fetchColumn();
        $estoqueBaixo = (int) $this->pdo->query('SELECT COUNT(*) FROM produtos WHERE ativo=1 AND estoque <= estoque_minimo')->fetchColumn();
        $pedidosAbertos = (int) $this->pdo->query("SELECT COUNT(*) FROM pedidos WHERE status IN ('pago','separacao','enviado')")->fetchColumn();
        $faturamento = (float) $this->pdo->query("SELECT COALESCE(SUM(total),0) FROM pedidos WHERE status IN ('pago','separacao','enviado','entregue')")->fetchColumn();

        $pedidos = $this->pdo->query(
            'SELECT p.id, p.status, p.total, p.criado_em, u.nome cliente
             FROM pedidos p INNER JOIN usuarios u ON u.id=p.usuario_id
             ORDER BY p.criado_em DESC LIMIT 8'
        )->fetchAll();

        $baixo = $this->pdo->query(
            'SELECT id, nome, estoque, estoque_minimo FROM produtos
             WHERE ativo=1 AND estoque <= estoque_minimo ORDER BY estoque ASC LIMIT 8'
        )->fetchAll();

        respostaJson(true, '', [
            'indicadores' => compact('clientes', 'produtos', 'estoqueBaixo', 'pedidosAbertos', 'faturamento'),
            'pedidos' => $pedidos,
            'estoque_baixo' => $baixo,
        ]);
    }

    public function pedidos(): never
    {
        $this->exigir(['admin_master', 'logistica', 'suporte']);
        $stmt = $this->pdo->query(
            'SELECT p.id, p.status, p.total, p.subtotal, p.frete, p.desconto, p.criado_em,
                    u.nome cliente, u.email,
                    e.transportadora, e.codigo_rastreio
             FROM pedidos p
             INNER JOIN usuarios u ON u.id=p.usuario_id
             LEFT JOIN entregas e ON e.pedido_id=p.id
             ORDER BY p.criado_em DESC'
        );
        respostaJson(true, '', ['pedidos' => $stmt->fetchAll()]);
    }

    public function atualizarPedido(): never
    {
        $this->exigir(['admin_master', 'logistica']);
        $id = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $permitidos = ['aguardando_pagamento','pago','separacao','enviado','entregue','cancelado','estornado'];
        if ($id < 1 || !in_array($status, $permitidos, true)) {
            respostaJson(false, 'Dados do pedido inválidos.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE pedidos SET status=:status WHERE id=:id');
            $stmt->execute([':status' => $status, ':id' => $id]);

            $detalhesStatus = [
                'aguardando_pagamento' => 'Pedido recebido e aguardando pagamento.',
                'pago' => 'Pagamento aprovado. Pedido confirmado.',
                'separacao' => 'Pedido em separação no centro de distribuição.',
                'enviado' => 'Pedido enviado para transporte.',
                'entregue' => 'Pedido entregue ao cliente.',
                'cancelado' => 'Pedido cancelado.',
                'estornado' => 'Pedido estornado.'
            ];
            $this->pdo->prepare('INSERT INTO pedido_status_historico (pedido_id,status,detalhe) VALUES (:pedido,:status,:detalhe)')->execute([':pedido'=>$id,':status'=>$status,':detalhe'=>$detalhesStatus[$status] ?? null]);

            if ($status === 'enviado') {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO entregas (pedido_id, enviado_em) VALUES (:pedido, NOW())
                     ON DUPLICATE KEY UPDATE enviado_em=COALESCE(enviado_em, NOW())'
                );
                $stmt->execute([':pedido' => $id]);
            }
            if ($status === 'entregue') {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO entregas (pedido_id, entregue_em) VALUES (:pedido, NOW())
                     ON DUPLICATE KEY UPDATE entregue_em=NOW()'
                );
                $stmt->execute([':pedido' => $id]);
            }

            registrarAdminLog($this->pdo, 'pedido.status_alterado', 'pedido', $id, ['status' => $status]);
            $this->pdo->commit();
            respostaJson(true, 'Status do pedido atualizado.');
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            respostaJson(false, 'Não foi possível atualizar o pedido.');
        }
    }

    public function clientes(): never
    {
        $this->exigir(['admin_master', 'suporte']);
        $stmt = $this->pdo->query(
            "SELECT u.id, u.nome, u.email, u.criado_em,
                    COUNT(p.id) pedidos,
                    COALESCE(SUM(CASE WHEN p.status NOT IN ('cancelado','estornado') THEN p.total ELSE 0 END),0) total_compras
             FROM usuarios u
             LEFT JOIN pedidos p ON p.usuario_id=u.id
             WHERE u.papel='cliente'
             GROUP BY u.id
             ORDER BY u.criado_em DESC"
        );
        respostaJson(true, '', ['clientes' => $stmt->fetchAll()]);
    }

    public function historicoCliente(): never
    {
        $this->exigir(['admin_master', 'suporte']);
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);
        if ($clienteId < 1) {
            respostaJson(false, 'Cliente inválido.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, status, total, criado_em FROM pedidos WHERE usuario_id=:cliente ORDER BY criado_em DESC'
        );
        $stmt->execute([':cliente' => $clienteId]);
        respostaJson(true, '', ['pedidos' => $stmt->fetchAll()]);
    }

    /**
     * Limite de segurança para saldos (evita estouro de INT e erros de digitação).
     */
    private const ESTOQUE_LIMITE = 10000000;

    public function estoque(): never
    {
        $this->exigir(['admin_master', 'logistica']);

        $centros = $this->pdo->query(
            'SELECT id, nome, cidade, estado FROM centros_distribuicao WHERE ativo=1 ORDER BY nome'
        )->fetchAll();

        $produtos = $this->pdo->query(
            'SELECT id, nome, estoque, estoque_minimo FROM produtos WHERE ativo=1 ORDER BY nome'
        )->fetchAll();

        // Saldos por CD em uma consulta separada (evita o limite do GROUP_CONCAT
        // e problemas com nomes de CD que contenham ":" ou "|").
        $saldos = $this->pdo->query(
            'SELECT ec.produto_id, cd.id AS centro_id, cd.nome, ec.quantidade
             FROM estoque_centros ec
             INNER JOIN centros_distribuicao cd ON cd.id=ec.centro_id AND cd.ativo=1
             ORDER BY cd.nome'
        )->fetchAll();

        $porProduto = [];
        foreach ($saldos as $saldo) {
            $porProduto[(int) $saldo['produto_id']][] = [
                'id' => (int) $saldo['centro_id'],
                'nome' => $saldo['nome'],
                'quantidade' => (int) $saldo['quantidade'],
            ];
        }

        foreach ($produtos as &$produto) {
            $produto['id'] = (int) $produto['id'];
            $produto['estoque'] = (int) $produto['estoque'];
            $produto['estoque_minimo'] = (int) $produto['estoque_minimo'];
            $produto['centros'] = $porProduto[$produto['id']] ?? [];

            // Diferença entre o total do produto e a soma dos CDs (dados antigos
            // ou estoque sem CD definido). Mostrada no painel para o admin corrigir.
            $somaCentros = 0;
            foreach ($produto['centros'] as $centro) {
                $somaCentros += $centro['quantidade'];
            }
            $produto['nao_alocado'] = $produto['estoque'] - $somaCentros;
        }
        unset($produto);

        respostaJson(true, '', ['centros' => $centros, 'produtos' => $produtos]);
    }

    /**
     * Movimenta o estoque de um produto em um centro de distribuição.
     *
     * operacao:
     *  - adicionar: SOMA a quantidade ao saldo atual do CD;
     *  - remover:   SUBTRAI do saldo do CD (nunca deixa negativo);
     *  - definir:   substitui o saldo do CD por um valor exato (inventário).
     *
     * O total do produto recebe a mesma variação (delta) do CD, então estoque
     * já existente nunca é apagado nem sobrescrito por uma adição.
     */
    public function ajustarEstoque(): never
    {
        $this->exigir(['admin_master', 'logistica']);

        $produtoId = (int) ($_POST['produto_id'] ?? 0);
        $centroId = (int) ($_POST['centro_id'] ?? 0);
        $operacao = (string) ($_POST['operacao'] ?? 'adicionar');
        $quantidadeBruta = trim((string) ($_POST['quantidade'] ?? ''));

        if ($produtoId < 1 || $centroId < 1) {
            respostaJson(false, 'Selecione o produto e o centro de distribuição.');
        }
        if (!in_array($operacao, ['adicionar', 'remover', 'definir'], true)) {
            respostaJson(false, 'Operação de estoque inválida.');
        }
        if (!preg_match('/^\d{1,9}$/', $quantidadeBruta)) {
            respostaJson(false, 'Informe a quantidade como um número inteiro, sem sinais, vírgulas ou letras.');
        }

        $quantidade = (int) $quantidadeBruta;
        if ($operacao !== 'definir' && $quantidade < 1) {
            respostaJson(false, 'Informe uma quantidade maior que zero.');
        }
        if ($quantidade > self::ESTOQUE_LIMITE) {
            respostaJson(false, 'Quantidade acima do limite permitido (' . number_format(self::ESTOQUE_LIMITE, 0, ',', '.') . ' un.).');
        }

        $this->pdo->beginTransaction();
        try {
            // Trava o produto: serializa ajustes simultâneos e vendas do mesmo item.
            $stmt = $this->pdo->prepare('SELECT id, nome, estoque FROM produtos WHERE id=:id AND ativo=1 FOR UPDATE');
            $stmt->execute([':id' => $produtoId]);
            $produto = $stmt->fetch();
            if (!$produto) {
                throw new DomainException('Produto não encontrado ou inativo.');
            }

            $stmt = $this->pdo->prepare('SELECT id, nome FROM centros_distribuicao WHERE id=:id AND ativo=1 LIMIT 1');
            $stmt->execute([':id' => $centroId]);
            $centro = $stmt->fetch();
            if (!$centro) {
                throw new DomainException('Centro de distribuição não encontrado ou inativo.');
            }

            $stmt = $this->pdo->prepare(
                'SELECT quantidade FROM estoque_centros WHERE centro_id=:centro AND produto_id=:produto FOR UPDATE'
            );
            $stmt->execute([':centro' => $centroId, ':produto' => $produtoId]);
            $linha = $stmt->fetch();

            $saldoAntes = $linha ? (int) $linha['quantidade'] : 0;
            $totalAntes = (int) $produto['estoque'];

            if ($operacao === 'adicionar') {
                $saldoDepois = $saldoAntes + $quantidade;
            } elseif ($operacao === 'remover') {
                if ($quantidade > $saldoAntes) {
                    throw new DomainException(
                        "Saldo insuficiente em {$centro['nome']}: há {$saldoAntes} un. e você tentou remover {$quantidade}."
                    );
                }
                $saldoDepois = $saldoAntes - $quantidade;
            } else {
                $saldoDepois = $quantidade;
            }

            $variacao = $saldoDepois - $saldoAntes;
            $totalDepois = $totalAntes + $variacao;

            if ($totalDepois < 0) {
                throw new DomainException(
                    "O estoque total do produto ({$totalAntes} un.) é menor que a quantidade que você quer remover."
                );
            }
            if ($saldoDepois > self::ESTOQUE_LIMITE || $totalDepois > self::ESTOQUE_LIMITE) {
                throw new DomainException(
                    'O saldo resultante passaria do limite permitido (' . number_format(self::ESTOQUE_LIMITE, 0, ',', '.') . ' un.).'
                );
            }

            // O valor final já foi calculado com as linhas travadas, então gravá-lo é seguro.
            $stmt = $this->pdo->prepare(
                'INSERT INTO estoque_centros (centro_id, produto_id, quantidade)
                 VALUES (:centro, :produto, :quantidade)
                 ON DUPLICATE KEY UPDATE quantidade=VALUES(quantidade)'
            );
            $stmt->execute([
                ':centro' => $centroId,
                ':produto' => $produtoId,
                ':quantidade' => $saldoDepois,
            ]);

            $stmt = $this->pdo->prepare('UPDATE produtos SET estoque=:total WHERE id=:id');
            $stmt->execute([':total' => $totalDepois, ':id' => $produtoId]);

            try {
                registrarAdminLog($this->pdo, 'estoque.' . $operacao, 'produto', $produtoId, [
                    'centro_id' => $centroId,
                    'quantidade' => $quantidade,
                    'saldo_centro_antes' => $saldoAntes,
                    'saldo_centro_depois' => $saldoDepois,
                    'total_antes' => $totalAntes,
                    'total_depois' => $totalDepois,
                ]);
            } catch (Throwable $logError) {
                // Falha no log de auditoria não pode desfazer a movimentação.
                error_log('[SHINKA] Falha ao registrar log de estoque: ' . $logError->getMessage());
            }

            $this->pdo->commit();

            $mensagens = [
                'adicionar' => "Adicionadas {$quantidade} un. em {$centro['nome']}.",
                'remover' => "Removidas {$quantidade} un. de {$centro['nome']}.",
                'definir' => "Saldo de {$centro['nome']} definido em {$quantidade} un.",
            ];

            respostaJson(true, $mensagens[$operacao] . " Saldo do CD: {$saldoDepois} un. · Total do produto: {$totalDepois} un.", [
                'resultado' => [
                    'produto_id' => $produtoId,
                    'centro_id' => $centroId,
                    'saldo_centro' => $saldoDepois,
                    'total' => $totalDepois,
                ],
            ]);
        } catch (DomainException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            respostaJson(false, $e->getMessage());
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[SHINKA] Falha ao ajustar estoque: ' . $e->getMessage());
            respostaJson(false, 'Não foi possível atualizar o estoque. Tente novamente.');
        }
    }

    public function resumoLogs(): never
    {
        $this->exigir(['admin_master']);
        // Os 200 eventos mais recentes; busca, filtros e "Ver mais" funcionam sobre eles no painel.
        $stmt = $this->pdo->query(
            'SELECT l.id, l.acao, l.entidade, l.entidade_id, l.detalhes, l.criado_em, u.nome usuario
             FROM admin_logs l LEFT JOIN usuarios u ON u.id=l.usuario_id
             ORDER BY l.criado_em DESC, l.id DESC LIMIT 200'
        );
        respostaJson(true, '', ['logs' => $stmt->fetchAll()]);
    }
    public function categorias(): never
    {
        $this->exigir(['admin_master','catalogo']);
        respostaJson(true,'',['categorias'=>$this->categoriaDAO->listar(false)]);
    }

    public function salvarCategoria(): never
    {
        $this->exigir(['admin_master','catalogo']);
        $id=(int)($_POST['id']??0);
        $slug=strtolower(trim((string)($_POST['slug']??'')));
        $nome=trim((string)($_POST['nome']??''));
        $descricao=trim((string)($_POST['descricao']??''));
        $tema=trim((string)($_POST['tema']??'default'));
        $destaque=trim((string)($_POST['destaque_texto']??''));
        $hero=trim((string)($_POST['hero_texto']??''));
        $ativo=isset($_POST['ativo'])?1:0;
        if($nome===''||$slug===''||!preg_match('/^[a-z0-9-]{2,60}$/',$slug)) respostaJson(false,'Informe nome e slug válidos.');
        try{
            $categoriaId=$this->categoriaDAO->salvar(['id'=>$id,'slug'=>$slug,'nome'=>$nome,'descricao'=>$descricao,'tema'=>$tema,'destaque_texto'=>$destaque,'hero_texto'=>$hero,'ativo'=>$ativo]);
            registrarAdminLog($this->pdo,'categoria.salva','categoria',$categoriaId,['nome'=>$nome,'slug'=>$slug,'tema'=>$tema,'ativo'=>$ativo]);
            respostaJson(true,'Categoria salva com sucesso.',['categoria_id'=>$categoriaId]);
        }catch(Throwable $e){
            if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062){
                respostaJson(false,'Já existe uma categoria com o slug "'.$slug.'". Escolha um slug diferente.');
            }
            responderExcecao($e,'Não foi possível salvar a categoria. Tente novamente.','AdminController salvarCategoria');
        }
    }

    public function excluirCategoria(): never
    {
        $this->exigir(['admin_master']);
        $id=(int)($_POST['id']??0);
        if($id<1) respostaJson(false,'Categoria inválida.');
        try{
            $this->pdo->beginTransaction();
            $this->categoriaDAO->excluir($id);
            try {
                registrarAdminLog($this->pdo,'categoria.excluida','categoria',$id);
            } catch (Throwable $logError) {
                // Uma falha ao registrar o log de auditoria nunca deve
                // impedir a exclusão em si (a categoria já foi apagada
                // com sucesso acima).
            }
            $this->pdo->commit();
            respostaJson(true,'Categoria excluída com sucesso.');
        }catch(Throwable $e){
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            responderExcecao($e,'Não foi possível excluir a categoria. Tente novamente.','AdminController excluirCategoria');
        }
    }

    public function atualizarEntrega(): never
    {
        $this->exigir(['admin_master', 'logistica']);
        $pedidoId = (int) ($_POST['pedido_id'] ?? 0);
        $transportadora = trim((string) ($_POST['transportadora'] ?? 'Correios'));
        $codigo = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($_POST['codigo_rastreio'] ?? '')));
        $previsao = trim((string) ($_POST['previsao_entrega'] ?? ''));
        if ($pedidoId < 1) {
            respostaJson(false, 'Pedido inválido.');
        }
        if ($codigo !== '' && !preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $codigo)) {
            respostaJson(false, 'Código de rastreio inválido.');
        }
        $data = null;
        if ($previsao !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $previsao);
            if (!$d || $d->format('Y-m-d') !== $previsao) {
                respostaJson(false, 'Data de previsão inválida.');
            }
            $data = $previsao;
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO entregas (pedido_id,transportadora,codigo_rastreio,previsao_entrega) VALUES (:pedido,:transportadora,:codigo,:previsao)
                 ON DUPLICATE KEY UPDATE transportadora=VALUES(transportadora),codigo_rastreio=VALUES(codigo_rastreio),previsao_entrega=VALUES(previsao_entrega)'
            );
            $stmt->execute([':pedido'=>$pedidoId,':transportadora'=>$transportadora ?: 'Correios',':codigo'=>$codigo ?: null,':previsao'=>$data]);
            registrarAdminLog($this->pdo, 'entrega.atualizada', 'pedido', $pedidoId, ['codigo'=>$codigo,'previsao'=>$data]);
            respostaJson(true, 'Dados de entrega atualizados.');
        } catch (Throwable $e) {
            respostaJson(false, 'Não foi possível atualizar a entrega.');
        }
    }

}
