<?php
/**
 * Representa um item dentro do carrinho de compras: um produto
 * (opcionalmente uma variação dele) e a quantidade escolhida.
 */
class ItemCarrinho
{
    public int $produtoId;
    public string $nome;
    public float $precoUnitario;
    public int $quantidade;
    public int $variantId;

    public function __construct(
        int $produtoId,
        string $nome,
        float $precoUnitario,
        int $quantidade,
        int $variantId = 0
    ) {
        $this->produtoId = $produtoId;
        $this->nome = $nome;
        $this->precoUnitario = $precoUnitario;
        $this->quantidade = max(1, min(99, $quantidade));
        $this->variantId = $variantId;
    }

    /** Chave única do item dentro do carrinho (produto + variação). */
    public function chave(): string
    {
        return $this->produtoId . ':' . $this->variantId;
    }

    /** Subtotal do item: preço unitário x quantidade. */
    public function subtotal(): float
    {
        return round($this->precoUnitario * $this->quantidade, 2);
    }

    public function alterarQuantidade(int $quantidade): void
    {
        $this->quantidade = max(1, min(99, $quantidade));
    }

    public function toArray(): array
    {
        return [
            'produto_id' => $this->produtoId,
            'nome' => $this->nome,
            'preco_unitario' => $this->precoUnitario,
            'quantidade' => $this->quantidade,
            'variant_id' => $this->variantId,
            'subtotal' => $this->subtotal(),
        ];
    }
}
