# Como testar

## Verificações automáticas

Com PHP 8.2 e Node.js instalados, na raiz:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
find public -type f -name '*.js' -print0 | xargs -0 -n1 node --check
```

A mesma verificação está no GitHub Actions. Ela verifica sintaxe, sem executar checkout, banco ou integrações.

## Roteiro manual com banco de demonstração

1. Abra a loja e confira categorias, pesquisa e página de produto.
2. Crie uma conta e promova-a com `tools/promover_admin.php`, conforme o README. Entre como administrador; cadastre um produto, envie uma imagem e vincule uma variante.
3. Confira o produto na loja e sua imagem após recarregar a página.
4. Entre como cliente; adicione itens ao carrinho e confira quantidades e total.
5. Consulte um CEP válido e outro inválido. Confirme o tratamento de falha de rede.
6. Finalize um pedido usando apenas dados fictícios e pagamento simulado.
7. Confira o pedido na conta do cliente e no painel administrativo, inclusive estoque e status.
8. Saia da conta e confira a exigência de autenticação nas áreas protegidas.
9. Sem token dos Correios, confirme que o frete informa estimativa local.

## Verificação nesta publicação

- Sintaxe dos 16 arquivos JavaScript verificada localmente com `node --check`.
- Estrutura, referências da documentação e conteúdo do pacote revisados.
- PHP e MariaDB não estavam disponíveis no ambiente de preparação; os fluxos de ponta a ponta e as integrações externas não foram executados localmente.
- O resultado da verificação PHP deve ser consultado na execução do GitHub Actions; não é uma comprovação dos fluxos com banco.
