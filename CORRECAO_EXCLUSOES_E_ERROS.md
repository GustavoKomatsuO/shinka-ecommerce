# Correção — exclusão de produtos e tratamento de erros

## O problema

Excluir um produto que já foi comprado por algum cliente falhava no MySQL
(`pedido_itens.produto_id` aponta para `produtos`), e o painel mostrava o
texto cru do banco (`SQLSTATE[23000]: Integrity constraint violation...`).
O mesmo padrão existia em outras partes do sistema.

> O carrinho do cliente fica no navegador (`localStorage`), então o servidor
> não consegue saber quem está com um produto no carrinho. O bloqueio vale para
> quem **já fez pedido** com o produto. Para quem só tem o item no carrinho,
> o carrinho agora avisa que o produto saiu da loja (veja "Loja").

## Regra nova: excluir produto

`ProdutoDAO::excluir()` (chamado por `ProductController::excluir()`):

1. trava a linha do produto (`FOR UPDATE`), o que impede um checkout simultâneo
   de vender o produto entre a verificação e a exclusão;
2. conta os pedidos que contêm o produto (`resumoPedidos()`);
3. se houver algum, **rejeita** (HTTP 409) com mensagem explicando o motivo e
   a alternativa — desativar o produto ("Ativo no catálogo") — e **não apaga
   nada** (nem as variações);
4. só então apaga variações, imagens vinculadas e o produto.

Mensagens:

- com pedidos em andamento (aguardando pagamento, pago, separação, enviado):
  `Não é possível excluir "X": 2 pedidos em andamento incluem este produto (...)`
- só com histórico (entregue/cancelado/estornado):
  `Não é possível excluir "X": ele consta em 3 pedidos já registrados e precisa ficar no histórico de vendas. (...)`

## Outros pontos corrigidos

| Onde | Antes | Agora |
|---|---|---|
| `helpers/security.php` | — | `mensagemErroBanco()` e `responderExcecao()`: erro de banco vira mensagem amigável (nunca SQL); regra de negócio mostra a própria mensagem; o detalhe técnico vai só para o `error_log` |
| Salvar produto | SKU repetido / imagem inexistente → texto cru do MySQL | valida antes (SKU repetido no mesmo produto, imagem principal e amostra de cor existentes) e traduz erros de slug/SKU duplicado |
| Excluir/salvar categoria | slug duplicado → texto cru; editar categoria inexistente → "salva com sucesso" | mensagem de slug duplicado, "não encontrada", e contagem de produtos vinculados na recusa |
| Excluir imagem | não validava o id; não olhava a "amostra de cor" das variações | valida id/existência e diz onde a imagem está em uso (principal, galeria, amostra, banner) |
| Excluir banner | dizia "Banner excluído" mesmo se o id não existia; sem tratamento de erro | valida id, informa "não encontrado" |
| Salvar banner | imagem/banner inexistente → erro fatal em HTML | mensagens claras |
| Checkout / cálculo de frete | repassava `getMessage()` de qualquer erro (inclusive SQL) | só mostra mensagens de regra de negócio; erro de banco vira aviso genérico |
| Carrinho (`Carrinho::montarDoPost`) | "Um produto do carrinho não está mais disponível." | diferencia produto removido de produto desativado e cita o nome |
| `index.php` / `public/index.php` | erro não tratado → página HTML ("resposta inválida") | `set_exception_handler` nas rotas `api/*` devolve JSON |
| `Database` | MySQL fora do ar → texto puro | JSON nas rotas `api/*` |
| `ensureSchema` | tabela `pedido_itens` não era criada | criada com as chaves estrangeiras |

## Loja (cliente)

- `cliente.js` / `carrinho.js`: item cujo produto foi excluído ou desativado
  mostra "Este produto não está mais disponível na loja. Remova este item…"
  (antes aparecia "Esgotado").
- `checkout.js`: envia o nome do item para a mensagem do servidor citar o
  produto, e mostra o link "Voltar ao carrinho" também nesse caso.
