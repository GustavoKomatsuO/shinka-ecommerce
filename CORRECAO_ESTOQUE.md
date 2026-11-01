# Correção do módulo de Estoque / CDs

## O que mudou
- **Remover estoque**: o painel agora tem três operações — *Adicionar*, *Remover* e *Definir saldo* (contagem física).
- **Bug da sobrescrita**: antes, o saldo digitado *substituía* o saldo do CD (`quantidade=VALUES(quantidade)`) e o total do produto era recalculado só pela soma dos CDs, apagando estoque que não estava distribuído. Agora *Adicionar* soma ao saldo existente e o total do produto recebe a mesma variação.
- Validações no servidor: inteiro positivo, sem sinais/vírgulas, não remove mais que o saldo do CD, nunca fica negativo, limite de 10.000.000 un.
- Linhas travadas (`FOR UPDATE`) em transação: dois ajustes simultâneos não se atropelam.
- Checkout: baixa o estoque apenas se houver saldo (`estoque>=qtd`) e também desconta dos CDs, mantendo total e distribuição coerentes.
- Painel: seleção de produto/CD mantida após salvar, prévia "saldo atual → novo saldo", confirmação ao reduzir, botão bloqueado durante o envio (evita somar duas vezes), botão "Movimentar" na tabela.
- Correção colateral: `loadStock()` sobrescrevia a lista de produtos do catálogo; agora usa variáveis próprias.

## Arquivos alterados
`controllers/AdminController.php`, `controllers/CustomerController.php`,
`public/admin/index.html`, `public/admin/adminScript.js`, `public/admin/adminStyle.css`

Não há mudança de banco de dados nem de rotas.

---

## Auditoria (painel admin)
- Barra de pesquisa (usuário, ação, entidade, ID, data e detalhes) + filtros por **Entidade** e **Ação** + "Limpar filtros".
- Mostra **5 eventos por vez**; "Ver mais" libera mais 5 a cada clique e "Ver menos" volta para 5.
- Nova coluna **Detalhes** (ex.: `CD #1: 10 → 8 un. · total 12 → 10 un.`).
- A API (`api/admin/logs`) agora devolve os **200 eventos mais recentes** (antes 30), com os detalhes; a busca e os filtros atuam sobre esses 200.

## Cliente pedindo mais do que existe em estoque
- Servidor (`models/Carrinho.php`): soma o que o carrinho pede do mesmo produto (várias cores dividem o estoque) e responde com mensagem clara: "restam N unidades e seu carrinho pede M" / "esgotado".
- Checkout continua protegido por `UPDATE ... WHERE estoque >= qtd` dentro da transação (rollback se o estoque mudou durante a compra).
- Carrinho: confere o estoque atual ao abrir, reduz quantidades acima do disponível (com aviso), desabilita o "+" no máximo, mostra "Esgotado" e bloqueia "Finalizar compra" enquanto houver item sem estoque.
- Página do produto envia o estoque ao carrinho; `add()` e `setQty()` respeitam o limite.
- Checkout: se o servidor recusar por estoque, mostra a mensagem com link "Voltar ao carrinho".

Arquivos: `controllers/AdminController.php`, `controllers/CustomerController.php`, `models/Carrinho.php`, `public/admin/*`, `public/telaPrincipla/{cliente,carrinho,produto,checkout}.js`, `public/telaPrincipla/cliente.css`.
