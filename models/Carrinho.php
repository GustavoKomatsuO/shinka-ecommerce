<?php
/**
 * Representa o carrinho de compras no backend: uma coleção de
 * ItemCarrinho, com as operações da Semana 4 (adicionar, remover,
 * alterar quantidade, calcular subtotal e total) e a validação dos
 * produtos contra o banco, usada no checkout (Semana 5).
 *
 * O carrinho "de verdade" do cliente vive no navegador (localStorage,
 * em ShinkaStore) para permitir montar a compra sem login. Esta classe
 * é o espelho server-side: ela recebe os itens enviados pelo front no
 * momento do checkout, revalida preço/estoque no banco e passa a ser a
 * fonte confiável para gravar o pedido.
 */
class Carrinho
{
    /** @var ItemCarrinho[] */
    private array $itens = [];

    public function adicionar(ItemCarrinho $item): void
    {
        $chave = $item->chave();
        if (isset($this->itens[$chave])) {
            $this->itens[$chave]->alterarQuantidade($this->itens[$chave]->quantidade + $item->quantidade);
            return;
        }
        $this->itens[$chave] = $item;
    }

    public function remover(string $chave): void
    {
        unset($this->itens[$chave]);
    }

    public function alterarQuantidade(string $chave, int $quantidade): void
    {
        if (isset($this->itens[$chave])) {
            $this->itens[$chave]->alterarQuantidade($quantidade);
        }
    }

    public function esvaziar(): void
    {
        $this->itens = [];
    }

    public function estaVazio(): bool
    {
        return count($this->itens) === 0;
    }

    /** @return ItemCarrinho[] */
    public function itens(): array
    {
        return array_values($this->itens);
    }

    public function totalItens(): int
    {
        return array_sum(array_map(static fn(ItemCarrinho $i): int => $i->quantidade, $this->itens));
    }

    /** Soma dos subtotais de cada item (sem frete/desconto). */
    public function subtotal(): float
    {
        return round(array_sum(array_map(static fn(ItemCarrinho $i): float => $i->subtotal(), $this->itens)), 2);
    }

    /** Total considerando frete e desconto (usado no checkout). */
    public function total(float $frete = 0.0, float $desconto = 0.0): float
    {
        return max(0.0, round($this->subtotal() + $frete - $desconto, 2));
    }

    /**
     * Monta um Carrinho a partir do array de itens enviado pelo front
     * (id/quantidade/variantId) e revalida cada produto contra o banco:
     * existência, se está ativo e se há estoque suficiente. Usa preço
     * promocional quando existir. Lança RuntimeException com uma
     * mensagem amigável quando algum item não pode ser comprado.
     *
     * @param array<int,array{id:int,quantidade:int,variantId?:int}> $itensPostados
     */
    public function pacoteFrete(PDO $pdo, array $padrao = []): array
    {
        $defaultPeso=max(0.01,(float)($padrao['peso_kg']??1.0));
        $defaultAltura=max(1.0,(float)($padrao['altura_cm']??10.0));
        $defaultLargura=max(1.0,(float)($padrao['largura_cm']??20.0));
        $defaultComprimento=max(1.0,(float)($padrao['comprimento_cm']??30.0));
        $ids=array_values(array_unique(array_map(static fn(ItemCarrinho $item): int=>$item->produtoId,$this->itens())));
        if(!$ids) return ['peso_kg'=>$defaultPeso,'altura_cm'=>$defaultAltura,'largura_cm'=>$defaultLargura,'comprimento_cm'=>$defaultComprimento];
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("SELECT id,peso_kg,altura_cm,largura_cm,comprimento_cm FROM produtos WHERE id IN ({$ph})");
        $stmt->execute($ids); $dados=[];
        foreach($stmt->fetchAll() as $row) $dados[(int)$row['id']] = $row;
        $peso=0.0;$altura=0.0;$largura=0.0;$comprimento=0.0;
        foreach($this->itens() as $item){
            $row=$dados[$item->produtoId]??[];
            $pi=(float)($row['peso_kg']??0); $ai=(float)($row['altura_cm']??0); $li=(float)($row['largura_cm']??0); $ci=(float)($row['comprimento_cm']??0);
            if($pi<=0)$pi=$defaultPeso; if($ai<=0)$ai=$defaultAltura; if($li<=0)$li=$defaultLargura; if($ci<=0)$ci=$defaultComprimento;
            $peso += $pi*$item->quantidade; $altura += $ai*$item->quantidade; $largura=max($largura,$li); $comprimento=max($comprimento,$ci);
        }
        return ['peso_kg'=>round(max($defaultPeso,$peso),3),'altura_cm'=>round(max($defaultAltura,$altura),2),'largura_cm'=>round(max($defaultLargura,$largura),2),'comprimento_cm'=>round(max($defaultComprimento,$comprimento),2)];
    }

    public static function montarDoPost(PDO $pdo, array $itensPostados): self
    {
        $carrinho = new self();
        $stmt = $pdo->prepare(
            'SELECT id, nome, preco, preco_promocional, estoque, ativo
             FROM produtos WHERE id=:id LIMIT 1 FOR UPDATE'
        );

        $solicitadoPorProduto = [];
        foreach ($itensPostados as $entrada) {
            $produtoId = (int) ($entrada['id'] ?? 0);
            $quantidade = max(1, min(99, (int) ($entrada['quantidade'] ?? $entrada['qty'] ?? 0)));
            if ($produtoId < 1) {
                continue;
            }

            $stmt->execute([':id' => $produtoId]);
            $produto = $stmt->fetch();
            if (!$produto) {
                // Produto excluído do catálogo depois de entrar no carrinho do cliente.
                $nomeInformado = trim((string) ($entrada['nome'] ?? ''));
                $quem = $nomeInformado !== '' ? "\"{$nomeInformado}\"" : 'Um produto do seu carrinho';
                throw new RuntimeException("{$quem} foi removido da loja e não está mais disponível. Volte ao carrinho e remova o item para continuar.");
            }
            if ((int) $produto['ativo'] !== 1) {
                throw new RuntimeException("{$produto['nome']} não está mais disponível na loja. Volte ao carrinho e remova o item para continuar.");
            }
            // Soma o que o carrinho pede do mesmo produto (ex.: várias cores/variantes
            // compartilham o mesmo estoque) antes de comparar com o disponível.
            $solicitadoPorProduto[$produtoId] = ($solicitadoPorProduto[$produtoId] ?? 0) + $quantidade;
            $disponivel = max(0, (int) $produto['estoque']);
            if ($disponivel < 1) {
                throw new RuntimeException("{$produto['nome']} está esgotado no momento. Remova o item do carrinho para continuar.");
            }
            if ($disponivel < $solicitadoPorProduto[$produtoId]) {
                $restam = $disponivel === 1 ? 'resta 1 unidade' : "restam {$disponivel} unidades";
                throw new RuntimeException(
                    "Estoque insuficiente para {$produto['nome']}: {$restam} e seu carrinho pede {$solicitadoPorProduto[$produtoId]}. Volte ao carrinho e ajuste a quantidade."
                );
            }

            $preco = (float) (
                ($produto['preco_promocional'] !== null && (float) $produto['preco_promocional'] > 0)
                    ? $produto['preco_promocional']
                    : $produto['preco']
            );

            $carrinho->adicionar(new ItemCarrinho(
                (int) $produto['id'],
                (string) $produto['nome'],
                $preco,
                $quantidade,
                (int) ($entrada['variantId'] ?? 0)
            ));
        }

        if ($carrinho->estaVazio()) {
            throw new RuntimeException('Seu carrinho está vazio.');
        }

        return $carrinho;
    }

    public function toArray(): array
    {
        return [
            'itens' => array_map(static fn(ItemCarrinho $i): array => $i->toArray(), $this->itens()),
            'total_itens' => $this->totalItens(),
            'subtotal' => $this->subtotal(),
        ];
    }
}
