<?php
/**
 * SHINKA — DAO da tabela produtos.
 * Centraliza o CRUD administrativo da entidade principal de produto.
 */

/**
 * Produto que não pode ser excluído porque clientes já o compraram.
 * A mensagem é escrita para ser exibida diretamente ao administrador.
 */
class ProdutoEmUsoException extends DomainException
{
    public static function dePedidos(string $nome, int $total, int $emAndamento): self
    {
        $pedidos = static fn(int $n): string => $n === 1 ? '1 pedido' : "{$n} pedidos";

        if ($emAndamento > 0) {
            $verbo = $emAndamento === 1 ? 'inclui' : 'incluem';
            return new self(
                "Não é possível excluir \"{$nome}\": {$pedidos($emAndamento)} em andamento {$verbo} este produto "
                . '(cliente aguardando pagamento ou entrega). '
                . 'Para tirá-lo da loja sem afetar esses pedidos, edite o produto e desmarque "Ativo no catálogo".'
            );
        }

        $realizados = $total === 1 ? 'já registrado' : 'já registrados';
        return new self(
            "Não é possível excluir \"{$nome}\": ele consta em {$pedidos($total)} {$realizados}"
            . ' e precisa ficar no histórico de vendas. '
            . 'Para retirá-lo da loja, edite o produto e desmarque "Ativo no catálogo".'
        );
    }

    public static function generico(string $nome): self
    {
        return new self(
            "Não é possível excluir \"{$nome}\": ele ainda está vinculado a outros registros do sistema. "
            . 'Para retirá-lo da loja, edite o produto e desmarque "Ativo no catálogo".'
        );
    }
}

class ProdutoDAO
{
    public function __construct(private PDO $pdo) {}

    public function listarAdmin(): array
    {
        return $this->pdo->query(
            'SELECT p.id,p.categoria_id,p.imagem_id,p.nome,p.slug,p.familia,
                    p.descricao,p.especificacoes,p.preco,p.preco_promocional,
                    p.preco_anterior,p.badge,p.estoque,p.estoque_minimo,
                    p.peso_kg,p.altura_cm,p.largura_cm,p.comprimento_cm,
                    p.destaque,p.ativo,
                    c.nome categoria_nome,c.slug categoria_slug
             FROM produtos p
             INNER JOIN categorias c ON c.id=p.categoria_id
             ORDER BY p.criado_em DESC,p.id DESC'
        )->fetchAll();
    }

    public function existeSlug(string $slug, int $ignorarId = 0): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM produtos WHERE slug=:slug AND id<>:id LIMIT 1'
        );
        $stmt->execute([':slug'=>$slug, ':id'=>$ignorarId]);
        return (bool)$stmt->fetch();
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM produtos WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function inserir(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO produtos (
                categoria_id,imagem_id,nome,slug,familia,descricao,especificacoes,
                preco,preco_promocional,preco_anterior,badge,estoque,estoque_minimo,
                peso_kg,altura_cm,largura_cm,comprimento_cm,destaque,ativo
             ) VALUES (
                :categoria_id,:imagem_id,:nome,:slug,:familia,:descricao,:especificacoes,
                :preco,:preco_promocional,:preco_anterior,:badge,:estoque,:estoque_minimo,
                :peso_kg,:altura_cm,:largura_cm,:comprimento_cm,:destaque,:ativo
             )'
        );
        $stmt->execute($this->params($dados));
        return (int)$this->pdo->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $params = $this->params($dados);
        $params[':id'] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE produtos SET
                categoria_id=:categoria_id,
                imagem_id=:imagem_id,
                nome=:nome,
                slug=:slug,
                familia=:familia,
                descricao=:descricao,
                especificacoes=:especificacoes,
                preco=:preco,
                preco_promocional=:preco_promocional,
                preco_anterior=:preco_anterior,
                badge=:badge,
                estoque=:estoque,
                estoque_minimo=:estoque_minimo,
                peso_kg=:peso_kg,
                altura_cm=:altura_cm,
                largura_cm=:largura_cm,
                comprimento_cm=:comprimento_cm,
                destaque=:destaque,
                ativo=:ativo
             WHERE id=:id'
        );
        $stmt->execute($params);
    }

    /**
     * Quantos pedidos de clientes contêm este produto.
     *
     * @return array{total:int,em_andamento:int}
     *   total        = todos os pedidos que já tiveram o produto (histórico de vendas);
     *   em_andamento = pedidos ainda não concluídos (aguardando pagamento,
     *                  pago, em separação ou enviado).
     */
    public function resumoPedidos(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT pi.pedido_id) AS total,
                    COUNT(DISTINCT CASE WHEN p.status IN ('aguardando_pagamento','pago','separacao','enviado')
                                        THEN pi.pedido_id END) AS em_andamento
             FROM pedido_itens pi
             INNER JOIN pedidos p ON p.id = pi.pedido_id
             WHERE pi.produto_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch() ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'em_andamento' => (int)($row['em_andamento'] ?? 0),
        ];
    }

    /**
     * Exclui o produto somente se nenhum cliente tiver comprado.
     *
     * Um produto que consta em pedidos precisa continuar existindo para o
     * histórico de vendas, nota e rastreio dos clientes. Nesse caso a
     * exclusão é rejeitada com uma mensagem explicando o motivo e a
     * alternativa (desativar o produto).
     *
     * Deve ser chamado dentro de uma transação: a linha do produto é
     * travada (FOR UPDATE), o que impede que um checkout concorrente
     * venda o produto entre a verificação e o DELETE.
     *
     * @throws ProdutoEmUsoException se houver pedidos com o produto
     */
    public function excluir(int $id): void
    {
        if ($id < 1) throw new InvalidArgumentException('Produto inválido.');

        $stmt = $this->pdo->prepare('SELECT id, nome FROM produtos WHERE id=:id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $produto = $stmt->fetch();
        if (!$produto) throw new RuntimeException('Produto não encontrado. Ele pode já ter sido excluído; atualize a lista.');

        $pedidos = $this->resumoPedidos($id);
        if ($pedidos['total'] > 0) {
            throw ProdutoEmUsoException::dePedidos((string)$produto['nome'], $pedidos['total'], $pedidos['em_andamento']);
        }

        try {
            $stmt = $this->pdo->prepare('DELETE FROM produto_variante_imagens WHERE variante_id IN (SELECT id FROM produto_variantes WHERE produto_id=:id)');
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare('DELETE FROM produto_variantes WHERE produto_id=:id');
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare('DELETE FROM produtos WHERE id=:id');
            $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            // Rede de segurança: se outra tabela ainda referenciar o produto
            // (FK), devolve a mesma mensagem amigável em vez do erro do MySQL.
            if ((int)($e->errorInfo[1] ?? 0) === 1451) {
                throw ProdutoEmUsoException::generico((string)$produto['nome']);
            }
            throw $e;
        }
    }

    private function params(array $d): array
    {
        return [
            ':categoria_id'=>(int)$d['categoria_id'],
            ':imagem_id'=>$d['imagem_id'] !== null ? (int)$d['imagem_id'] : null,
            ':nome'=>$d['nome'],
            ':slug'=>$d['slug'],
            ':familia'=>$d['familia'] ?: null,
            ':descricao'=>$d['descricao'] ?: null,
            ':especificacoes'=>$d['especificacoes'] ?: null,
            ':preco'=>(float)$d['preco'],
            ':preco_promocional'=>$d['preco_promocional'],
            ':preco_anterior'=>$d['preco_anterior'],
            ':badge'=>$d['badge'] ?: null,
            ':estoque'=>(int)$d['estoque'],
            ':estoque_minimo'=>(int)$d['estoque_minimo'],
            ':peso_kg'=>(float)$d['peso_kg'],
            ':altura_cm'=>(float)$d['altura_cm'],
            ':largura_cm'=>(float)$d['largura_cm'],
            ':comprimento_cm'=>(float)$d['comprimento_cm'],
            ':destaque'=>(int)$d['destaque'],
            ':ativo'=>(int)$d['ativo'],
        ];
    }
}
