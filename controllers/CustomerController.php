<?php
/**
 * SHINKA - área do cliente: sessão, endereços, checkout, histórico e rastreio.
 */
require_once __DIR__ . '/../public/config_db/database.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../services/CorreiosService.php';
require_once __DIR__ . '/../services/cep_api.php';
require_once __DIR__ . '/../models/ItemCarrinho.php';
require_once __DIR__ . '/../models/Carrinho.php';

class CustomerController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function clienteId(): int
    {
        $id = (int) ($_SESSION['usuario_id'] ?? 0);
        if ($id < 1 || ($_SESSION['usuario_papel'] ?? '') !== 'cliente') {
            http_response_code(401);
            respostaJson(false, 'Faça login para acessar sua conta.');
        }
        return $id;
    }

    private function normalizarCep(string $cep): string
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            respostaJson(false, 'CEP inválido.');
        }
        return $cep;
    }

    public function me(): never
    {
        $id = $this->clienteId();
        $stmt = $this->pdo->prepare('SELECT id,nome,cpf,email FROM usuarios WHERE id=:id AND papel="cliente" AND ativo=1 LIMIT 1');
        $stmt->execute([':id' => $id]);
        $usuario = $stmt->fetch();
        if (!$usuario) {
            respostaJson(false, 'Cliente não encontrado.');
        }

        $enderecos = $this->listarEnderecosInterno($id);
        $pedidos = $this->listarPedidosInterno($id, 8);
        respostaJson(true, '', ['usuario' => $usuario, 'enderecos' => $enderecos, 'pedidos' => $pedidos]);
    }

    public function enderecos(): never
    {
        $id = $this->clienteId();
        respostaJson(true, '', ['enderecos' => $this->listarEnderecosInterno($id)]);
    }

    public function salvarEndereco(): never
    {
        $id = $this->clienteId();
        $destinatario = trim((string) ($_POST['destinatario'] ?? ''));
        $cep = $this->normalizarCep((string) ($_POST['cep'] ?? ''));
        $logradouro = trim((string) ($_POST['logradouro'] ?? ''));
        $numero = trim((string) ($_POST['numero'] ?? ''));
        $complemento = trim((string) ($_POST['complemento'] ?? ''));
        $bairro = trim((string) ($_POST['bairro'] ?? ''));
        $cidade = trim((string) ($_POST['cidade'] ?? ''));
        $estado = strtoupper(trim((string) ($_POST['estado'] ?? '')));
        $apelido = trim((string) ($_POST['apelido'] ?? ''));
        $principal = !empty($_POST['principal']) ? 1 : 0;

        if ($destinatario === '' || $logradouro === '' || $numero === '' || $bairro === '' || $cidade === '' || !preg_match('/^[A-Z]{2}$/', $estado)) {
            respostaJson(false, 'Preencha todos os dados do endereço.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($principal) {
                $this->pdo->prepare('UPDATE enderecos SET principal=0 WHERE usuario_id=:usuario')->execute([':usuario' => $id]);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO enderecos (usuario_id,apelido,destinatario,cep,logradouro,numero,complemento,bairro,cidade,estado,principal)
                 VALUES (:usuario,:apelido,:destinatario,:cep,:logradouro,:numero,:complemento,:bairro,:cidade,:estado,:principal)'
            );
            $stmt->execute([
                ':usuario' => $id,
                ':apelido' => $apelido ?: null,
                ':destinatario' => $destinatario,
                ':cep' => $cep,
                ':logradouro' => $logradouro,
                ':numero' => $numero,
                ':complemento' => $complemento ?: null,
                ':bairro' => $bairro,
                ':cidade' => $cidade,
                ':estado' => $estado,
                ':principal' => $principal,
            ]);
            if (!$principal && (int) $this->pdo->lastInsertId() > 0) {
                $count = (int) $this->pdo->query("SELECT COUNT(*) FROM enderecos WHERE usuario_id={$id}")->fetchColumn();
                if ($count === 1) {
                    $this->pdo->prepare('UPDATE enderecos SET principal=1 WHERE id=:id')->execute([':id' => (int) $this->pdo->lastInsertId()]);
                }
            }
            $this->pdo->commit();
            respostaJson(true, 'Endereço salvo com sucesso.', ['enderecos' => $this->listarEnderecosInterno($id)]);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            respostaJson(false, 'Não foi possível salvar o endereço.');
        }
    }

    /**
     * Consulta um CEP na API ViaCEP e devolve o endereço já pronto para
     * preencher o formulário (logradouro, bairro, cidade, estado).
     * Não exige login: usado tanto no cadastro de endereço quanto no checkout.
     */
    public function consultarCep(): never
    {
        $cep = (string) ($_GET['cep'] ?? '');

        $resultado = ConsultaCep::buscarPorCep($cep);

        if (!$resultado['sucesso']) {
            respostaJson(false, (string) ($resultado['mensagem'] ?? 'Não foi possível consultar o CEP.'));
        }

        unset($resultado['sucesso']);
        respostaJson(true, '', ['endereco' => $resultado]);
    }

    /**
     * Permite ao cliente alterar seus próprios dados de cadastro
     * (nome, e-mail e, opcionalmente, senha).
     */
    public function atualizarPerfil(): never
    {
        $id = $this->clienteId();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respostaJson(false, 'Método inválido.');
        }

        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
        $senhaNova = (string) ($_POST['senha_nova'] ?? '');
        $senhaConfirmar = (string) ($_POST['senha_confirmar'] ?? '');

        if ($nome === '' || mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            respostaJson(false, 'Informe um nome válido.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            respostaJson(false, 'Informe um e-mail válido.');
        }

        $stmt = $this->pdo->prepare('SELECT id,senha FROM usuarios WHERE id=:id AND papel="cliente" LIMIT 1');
        $stmt->execute([':id' => $id]);
        $usuario = $stmt->fetch();
        if (!$usuario) {
            respostaJson(false, 'Cliente não encontrado.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM usuarios WHERE email=:email AND id<>:id LIMIT 1');
        $stmt->execute([':email' => $email, ':id' => $id]);
        if ($stmt->fetch()) {
            respostaJson(false, 'Este e-mail já está em uso por outra conta.');
        }

        $campos = ['nome' => $nome, 'email' => $email];
        $trocaSenha = $senhaNova !== '' || $senhaConfirmar !== '';

        if ($trocaSenha) {
            if (!password_verify($senhaAtual, $usuario['senha'])) {
                respostaJson(false, 'Senha atual incorreta.');
            }
            if (strlen($senhaNova) < 6) {
                respostaJson(false, 'A nova senha deve ter pelo menos 6 caracteres.');
            }
            if ($senhaNova !== $senhaConfirmar) {
                respostaJson(false, 'A confirmação da nova senha não confere.');
            }
            $campos['senha'] = password_hash($senhaNova, PASSWORD_DEFAULT);
        }

        try {
            $sets = implode(',', array_map(static fn(string $c): string => "{$c}=:{$c}", array_keys($campos)));
            $params = [];
            foreach ($campos as $chave => $valor) {
                $params[":{$chave}"] = $valor;
            }
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE usuarios SET {$sets} WHERE id=:id")->execute($params);

            $_SESSION['usuario_nome'] = $nome;
            $_SESSION['usuario_email'] = $email;

            respostaJson(true, 'Dados atualizados com sucesso.', ['usuario' => ['nome' => $nome, 'email' => $email]]);
        } catch (PDOException $e) {
            respostaJson(false, $e->getCode() === '23000' ? 'Este e-mail já está em uso.' : 'Não foi possível atualizar seus dados.');
        }
    }

    public function prazo(): never
    {
        $cep = $this->normalizarCep((string) ($_GET['cep'] ?? ''));
        $codigo = trim((string) ($_GET['servico'] ?? ''));
        $correios = new CorreiosService();
        try {
            $rawItems = json_decode((string) ($_GET['itens'] ?? '[]'), true);
            $items = is_array($rawItems) ? $rawItems : [];
            $carrinho = $items ? Carrinho::montarDoPost($this->pdo, $items) : null;
            $pacote = $carrinho ? $carrinho->pacoteFrete($this->pdo, $correios->pacotePadrao()) : $correios->pacotePadrao();
            $dados = $correios->calcularFreteTodas($cep, $pacote);
            if ($codigo !== '') $dados = array_values(array_filter($dados, static fn(array $item): bool => $item['codigo'] === $codigo));
            respostaJson(true, '', ['servicos'=>$dados,'api_configurada'=>$correios->configurada(),'pacote'=>$pacote]);
        } catch (Throwable $e) { responderExcecao($e, 'Não foi possível calcular a entrega agora. Tente novamente.', 'CustomerController prazo'); }
    }

    public function checkout(): never
    {
        $id = $this->clienteId();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respostaJson(false, 'Método inválido.');
        }

        $itemsJson = (string) ($_POST['itens'] ?? '');
        $items = json_decode($itemsJson, true);
        if (!is_array($items) || !$items) {
            respostaJson(false, 'Seu carrinho está vazio.');
        }

        $forma = trim((string) ($_POST['forma_pagamento'] ?? ''));
        $formas = ['pix','cartao','boleto'];
        if (!in_array($forma, $formas, true)) {
            respostaJson(false, 'Selecione uma forma de pagamento.');
        }
        $simularPagamento = (string) ($_POST['simular_pagamento'] ?? '') === '1';
        if ($forma === 'cartao' && $simularPagamento) {
            $numero = preg_replace('/\D/', '', (string) ($_POST['cartao_numero'] ?? ''));
            $nomeCartao = trim((string) ($_POST['cartao_nome'] ?? ''));
            $validade = trim((string) ($_POST['cartao_validade'] ?? ''));
            $cvv = preg_replace('/\D/', '', (string) ($_POST['cartao_cvv'] ?? ''));
            if (strlen($numero) < 13 || strlen($numero) > 19 || $nomeCartao === '' || !preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $validade) || strlen($cvv) < 3) {
                respostaJson(false, 'Confira os dados do cartão para continuar.');
            }
        }

        $endereco = [
            'destinatario' => trim((string) ($_POST['destinatario'] ?? '')),
            'cep' => $this->normalizarCep((string) ($_POST['cep'] ?? '')),
            'logradouro' => trim((string) ($_POST['logradouro'] ?? '')),
            'numero' => trim((string) ($_POST['numero'] ?? '')),
            'complemento' => trim((string) ($_POST['complemento'] ?? '')),
            'bairro' => trim((string) ($_POST['bairro'] ?? '')),
            'cidade' => trim((string) ($_POST['cidade'] ?? '')),
            'estado' => strtoupper(trim((string) ($_POST['estado'] ?? ''))),
        ];
        if ($endereco['destinatario'] === '' || $endereco['logradouro'] === '' || $endereco['numero'] === '' || $endereco['bairro'] === '' || $endereco['cidade'] === '' || !preg_match('/^[A-Z]{2}$/', $endereco['estado'])) {
            respostaJson(false, 'Informe um endereço de entrega completo.');
        }

        $servico = trim((string) ($_POST['servico_correiros'] ?? ''));
        $correios = new CorreiosService();
        $servicos = $correios->servicos();
        if ($servico === '') $servico = $servicos[0]['codigo'];
        $frete = 0.0;
        $prazo = null;

        $this->pdo->beginTransaction();
        try {
            // Classes de carrinho (Semana 4): revalida os itens do POST
            // contra o banco (preço/estoque) e calcula o subtotal.
            $carrinho = Carrinho::montarDoPost($this->pdo, $items);
            $subtotal = $carrinho->subtotal();

            $pacote = $carrinho->pacoteFrete($this->pdo, $correios->pacotePadrao());
            $cotacoes = $correios->calcularFreteTodas($endereco['cep'], $pacote);
            $cotacaoSelecionada = null;
            foreach ($cotacoes as $cotacao) {
                if ($cotacao['codigo'] === $servico) { $cotacaoSelecionada = $cotacao; break; }
            }
            if (!$cotacaoSelecionada) throw new RuntimeException('A modalidade de entrega selecionada não está disponível.');
            $frete=(float)$cotacaoSelecionada['valor'];
            $prazo=[
                'configurada'=>$cotacaoSelecionada['configurada_prazo']??false,
                'codigo'=>$cotacaoSelecionada['codigo'],
                'servico'=>$cotacaoSelecionada['servico'],
                'dias'=>$cotacaoSelecionada['dias']??null,
                'data_prevista'=>$cotacaoSelecionada['data_prevista']??null,
                'mensagem'=>$cotacaoSelecionada['mensagem_prazo']??null,
                'frete_configurado'=>$cotacaoSelecionada['configurada']??false,
                'frete_mensagem'=>$cotacaoSelecionada['mensagem']??null,
                'pacote'=>$pacote,
            ];

            $desconto = 0.0;
            $cupomCodigo = strtoupper(trim((string) ($_POST['cupom'] ?? '')));
            if ($cupomCodigo !== '') {
                $cupom = $this->buscarCupom($cupomCodigo, $subtotal);
                if (!$cupom) {
                    throw new RuntimeException('Cupom inválido ou indisponível.');
                }
                $desconto = $cupom['tipo'] === 'percentual'
                    ? min($subtotal, $subtotal * ((float) $cupom['valor'] / 100))
                    : min($subtotal, (float) $cupom['valor']);
            }

            $total = max(0, $subtotal + $frete - $desconto);
            $statusInicial = $simularPagamento ? 'pago' : 'aguardando_pagamento';
            $pagamentoStatusInicial = $simularPagamento ? 'aprovado' : 'pendente';
            $stmtOrder = $this->pdo->prepare(
                'INSERT INTO pedidos (usuario_id,status,subtotal,frete,desconto,total,forma_pagamento,pagamento_status,endereco_entrega)
                 VALUES (:usuario,:status,:subtotal,:frete,:desconto,:total,:forma,:pagamento_status,:endereco)'
            );
            $stmtOrder->execute([
                ':usuario' => $id,
                ':status' => $statusInicial,
                ':subtotal' => $subtotal,
                ':frete' => $frete,
                ':desconto' => $desconto,
                ':total' => $total,
                ':forma' => $forma,
                ':pagamento_status' => $pagamentoStatusInicial,
                ':endereco' => json_encode($endereco, JSON_UNESCAPED_UNICODE),
            ]);
            $pedidoId = (int) $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare(
                'INSERT INTO pedido_itens (pedido_id,produto_id,nome_produto,quantidade,preco_unitario,subtotal)
                 VALUES (:pedido,:produto,:nome,:qtd,:preco,:subtotal)'
            );
            $stmtEstoque = $this->pdo->prepare('UPDATE produtos SET estoque=estoque-:qtd WHERE id=:id AND estoque>=:minimo');
            foreach ($carrinho->itens() as $item) {
                $stmtItem->execute([
                    ':pedido' => $pedidoId,
                    ':produto' => $item->produtoId,
                    ':nome' => $item->nome,
                    ':qtd' => $item->quantidade,
                    ':preco' => $item->precoUnitario,
                    ':subtotal' => $item->subtotal(),
                ]);
                $stmtEstoque->execute([':qtd' => $item->quantidade, ':id' => $item->produtoId, ':minimo' => $item->quantidade]);
                if ($stmtEstoque->rowCount() < 1) {
                    throw new RuntimeException("Estoque insuficiente para {$item->nome}: o estoque mudou durante a compra. Volte ao carrinho e tente novamente.");
                }
                // Mantém o saldo por centro de distribuição coerente com o total do produto.
                $this->baixarEstoqueCentros((int) $item->produtoId, (int) $item->quantidade);
            }

            $pagamentoStatus = $simularPagamento ? 'aprovado' : 'pendente';
            $transacao = $simularPagamento ? strtoupper('SIM-' . $forma . '-' . bin2hex(random_bytes(5))) : null;
            $stmtPagamento = $this->pdo->prepare(
                'INSERT INTO pagamentos (pedido_id,forma,valor,status,transacao) VALUES (:pedido,:forma,:valor,:status,:transacao)'
            );
            $stmtPagamento->execute([
                ':pedido' => $pedidoId,
                ':forma' => $forma,
                ':valor' => $total,
                ':status' => $pagamentoStatus,
                ':transacao' => $transacao,
            ]);

            $entregaStatus = $simularPagamento
                ? 'Pagamento aprovado em simulação - pedido recebido'
                : 'Pedido recebido - aguardando pagamento';
            $stmtEntrega = $this->pdo->prepare(
                'INSERT INTO entregas (pedido_id,transportadora,cep_destino,previsao_entrega,ultimo_status)
                 VALUES (:pedido,:transportadora,:cep,:previsao,:status)'
            );
            $stmtEntrega->execute([
                ':pedido' => $pedidoId,
                ':transportadora' => 'Correios - ' . $prazo['servico'],
                ':cep' => $endereco['cep'],
                ':previsao' => $prazo['data_prevista'] ?? null,
                ':status' => $entregaStatus,
            ]);

            $this->pdo->prepare('INSERT INTO pedido_status_historico (pedido_id,status,detalhe) VALUES (:pedido,:status,:detalhe)')->execute([':pedido'=>$pedidoId,':status'=>$statusInicial,':detalhe'=>$entregaStatus]);

            if ($cupomCodigo !== '') {
                $this->pdo->prepare('UPDATE cupons SET usado=usado+1 WHERE UPPER(codigo)=:codigo')->execute([':codigo' => $cupomCodigo]);
            }
            $this->pdo->commit();
            respostaJson(true, 'Pedido criado com sucesso.', [
                'pedido' => [
                    'id' => $pedidoId,
                    'total' => $total,
                    'frete' => $frete,
                    'forma_pagamento' => $forma,
                    'pagamento_simulado' => $simularPagamento,
                    'transacao' => $transacao,
                    'servico_correiros' => $servico,
                    'prazo' => $prazo,
                ],
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Regras de negócio (estoque, cupom, produto removido...) mostram a
            // própria mensagem; erros de banco viram um aviso genérico.
            responderExcecao($e, 'Não foi possível finalizar a compra. Tente novamente em instantes.', 'CustomerController checkout');
        }
    }

    /**
     * Baixa a quantidade vendida dos centros de distribuição (maior saldo primeiro),
     * para que o estoque por CD continue batendo com o total do produto.
     * Deve ser chamado dentro da transação do checkout.
     */
    private function baixarEstoqueCentros(int $produtoId, int $quantidade): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, quantidade FROM estoque_centros
             WHERE produto_id=:produto AND quantidade>0
             ORDER BY quantidade DESC, id ASC
             FOR UPDATE'
        );
        $stmt->execute([':produto' => $produtoId]);

        $baixa = $this->pdo->prepare('UPDATE estoque_centros SET quantidade=quantidade-:qtd WHERE id=:id');
        $restante = $quantidade;
        foreach ($stmt->fetchAll() as $linha) {
            if ($restante <= 0) {
                break;
            }
            $retirar = min($restante, (int) $linha['quantidade']);
            $baixa->execute([':qtd' => $retirar, ':id' => (int) $linha['id']]);
            $restante -= $retirar;
        }
    }

    public function pedidos(): never
    {
        $id = $this->clienteId();
        respostaJson(true, '', ['pedidos' => $this->listarPedidosInterno($id)]);
    }

    public function pedido(): never
    {
        $id = $this->clienteId();
        $pedidoId = (int) ($_GET['id'] ?? 0);
        if ($pedidoId < 1) {
            respostaJson(false, 'Pedido inválido.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id,p.status,p.subtotal,p.frete,p.desconto,p.total,p.forma_pagamento,p.pagamento_status,p.endereco_entrega,p.criado_em,
                    pg.transacao, e.transportadora,e.codigo_rastreio,e.cep_destino,e.previsao_entrega,e.ultimo_status,e.ultimo_evento_em,e.enviado_em,e.entregue_em
             FROM pedidos p LEFT JOIN entregas e ON e.pedido_id=p.id
             LEFT JOIN pagamentos pg ON pg.pedido_id=p.id
             WHERE p.id=:pedido AND p.usuario_id=:usuario LIMIT 1'
        );
        $stmt->execute([':pedido' => $pedidoId, ':usuario' => $id]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            respostaJson(false, 'Pedido não encontrado.');
        }
        $pedido['endereco_entrega'] = $pedido['endereco_entrega'] ? json_decode((string) $pedido['endereco_entrega'], true) : null;
        $stmt = $this->pdo->prepare('SELECT produto_id,nome_produto,quantidade,preco_unitario,subtotal FROM pedido_itens WHERE pedido_id=:pedido ORDER BY id');
        $stmt->execute([':pedido' => $pedidoId]);
        $pedido['itens'] = $stmt->fetchAll();
        foreach ($pedido['itens'] as &$item) {
            $item['quantidade'] = (int) $item['quantidade'];
            $item['preco_unitario'] = (float) $item['preco_unitario'];
            $item['subtotal'] = (float) $item['subtotal'];
        }
        unset($item);
        $stmt = $this->pdo->prepare('SELECT status,detalhe,criado_em FROM pedido_status_historico WHERE pedido_id=:pedido ORDER BY criado_em ASC,id ASC');
        $stmt->execute([':pedido'=>$pedidoId]);
        $pedido['historico_status'] = $stmt->fetchAll();
        respostaJson(true, '', ['pedido' => $pedido]);
    }

    public function rastrearPedido(): never
    {
        $id = $this->clienteId();
        $pedidoId = (int) ($_GET['pedido_id'] ?? 0);
        $stmt = $this->pdo->prepare(
            'SELECT p.id,p.status,e.codigo_rastreio,e.transportadora,e.previsao_entrega,e.ultimo_status,e.ultimo_evento_em
             FROM pedidos p LEFT JOIN entregas e ON e.pedido_id=p.id WHERE p.id=:pedido AND p.usuario_id=:usuario LIMIT 1'
        );
        $stmt->execute([':pedido' => $pedidoId, ':usuario' => $id]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            respostaJson(false, 'Pedido não encontrado.');
        }

        $resultado = null;
        if (!empty($pedido['codigo_rastreio'])) {
            try {
                $resultado = (new CorreiosService())->rastrear((string) $pedido['codigo_rastreio']);
                if (!empty($resultado['status'])) {
                    $this->pdo->prepare('UPDATE entregas SET ultimo_status=:status,ultimo_evento_em=NOW() WHERE pedido_id=:pedido')->execute([
                        ':status' => mb_substr($resultado['status'], 0, 160),
                        ':pedido' => $pedidoId,
                    ]);
                }
            } catch (Throwable $e) {
                $resultado = [
                    'configurada' => (new CorreiosService())->configurada(),
                    'erro' => $e->getMessage(),
                    'url_oficial' => (new CorreiosService())->oficial((string) $pedido['codigo_rastreio']),
                ];
            }
        }

        $hist = $this->pdo->prepare('SELECT status,detalhe,criado_em FROM pedido_status_historico WHERE pedido_id=:pedido ORDER BY criado_em ASC,id ASC');
        $hist->execute([':pedido'=>$pedidoId]);
        $pedido['historico_status'] = $hist->fetchAll();
        respostaJson(true, '', ['pedido' => $pedido, 'rastreio' => $resultado]);
    }

    private function listarEnderecosInterno(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id,apelido,destinatario,cep,logradouro,numero,complemento,bairro,cidade,estado,principal FROM enderecos WHERE usuario_id=:usuario ORDER BY principal DESC,id DESC');
        $stmt->execute([':usuario' => $id]);
        return $stmt->fetchAll();
    }

    private function listarPedidosInterno(int $id, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT p.id,p.status,p.subtotal,p.frete,p.desconto,p.total,p.forma_pagamento,p.criado_em,
                    e.transportadora,e.codigo_rastreio,e.previsao_entrega,e.ultimo_status
             FROM pedidos p LEFT JOIN entregas e ON e.pedido_id=p.id
             WHERE p.usuario_id=:usuario ORDER BY p.criado_em DESC LIMIT ' . $limit
        );
        $stmt->execute([':usuario' => $id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            foreach (['subtotal','frete','desconto','total'] as $key) {
                $row[$key] = (float) $row[$key];
            }
        }
        unset($row);
        return $rows;
    }

    private function buscarCupom(string $codigo, float $subtotal): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,codigo,tipo,valor,valor_minimo,limite_uso,usado,inicio,fim FROM cupons
             WHERE UPPER(codigo)=UPPER(:codigo) AND ativo=1
             AND (inicio IS NULL OR inicio<=NOW()) AND (fim IS NULL OR fim>=NOW()) LIMIT 1'
        );
        $stmt->execute([':codigo' => $codigo]);
        $cupom = $stmt->fetch();
        if (!$cupom || $subtotal < (float) $cupom['valor_minimo']) {
            return null;
        }
        if ($cupom['limite_uso'] !== null && (int) $cupom['usado'] >= (int) $cupom['limite_uso']) {
            return null;
        }
        return $cupom;
    }
}
