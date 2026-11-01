# SHINKA — Relatório de uso do Admin Console

## 1. Visão geral

O SHINKA Admin Console é o ambiente separado para operação do e-commerce. O navegador apresenta módulos, mas as permissões reais continuam sendo verificadas no servidor. O código atual já trabalha com sessão administrativa, token CSRF, carregamento de módulos por papel e APIs de catálogo, pedidos, estoque, clientes e mídia.

### Perfis

- **Admin Master:** acesso completo.
- **Gestão de Catálogo:** produtos, imagens e campanhas.
- **Gestão de Logística:** pedidos e estoque.
- **Suporte ao Cliente:** pedidos e clientes.

## 2. Como entrar

Abra:

`http://localhost/PROJETO_ECOMMERCE_SHINKA/public/admin/login.html`

Acessos: siga a criação local de contas descrita no README principal.

## 3. Visão geral

A página inicial mostra KPIs, pedidos recentes e estoque crítico.

Use os atalhos para ir diretamente para Pedidos ou Estoque.

## 4. Catálogo

### Criar produto

1. Abra **Catálogo**.
2. Clique em **+ Novo produto**.
3. Preencha categoria, nome, slug, família, descrição, especificações e preços.
4. Escolha a imagem principal.
5. Abra **Variações e imagens**.
6. Adicione uma variação para cada cor/modelo.
7. Em cada variação, informe:
   - nome da variação;
   - SKU;
   - nome da cor;
   - cor visual;
   - várias imagens da biblioteca;
   - qual imagem será a principal.
8. Salve o produto.

### Exemplo — celular com várias cores

Produto: `SHINKA Mirai Pro`

- Variação 1: Azul Titânio — 4 imagens.
- Variação 2: Preto — 3 imagens.
- Variação 3: Branco — 4 imagens.

Na loja, o cliente pode tocar na cor e a galeria troca automaticamente para as fotos daquela variação.

## 5. Banco de imagens

O módulo **Banco de imagens** aceita:

- upload de arquivo;
- importação por URL;
- texto alternativo;
- visualização da biblioteca;
- download;
- remoção quando a imagem não está vinculada.

As imagens são guardadas como BLOB no MySQL. O produto não precisa carregar uma URL externa diretamente.

### Recomendações

Use nomes claros, por exemplo:

`mirai-pro-azul-frente.webp`

`mirai-pro-azul-costas.webp`

`mirai-pro-preto-lateral.webp`

Isso facilita localizar a foto na biblioteca.

## 6. Pedidos / OMS

Use o módulo de pedidos para acompanhar:

`aguardando_pagamento → pago → separacao → enviado → entregue`

Também existem os estados `cancelado` e `estornado`.

Usuários de logística/admin podem atualizar o status.

## 7. Estoque / Centros de Distribuição

O estoque é organizado por centro de distribuição.

Exemplo:

- CD Sudeste: 10
- CD São Paulo: 7
- CD Sul: 5
- Total: 22

O painel mostra também o estoque mínimo e sinaliza produtos que precisam de reposição.

## 8. Clientes / Suporte

O suporte consulta cliente, e-mail, número de pedidos, total comprado e histórico resumido.

O histórico abre em uma janela própria para evitar perder o contexto da tela.

## 9. Campanhas

O módulo permite cadastrar banners e cupons/regras de desconto.

O objetivo é retirar do HTML da loja as informações que mudam com frequência.

## 10. Auditoria

O Admin Master possui o módulo de auditoria, que registra operações administrativas como criação/alteração/exclusão de produtos, imagens e outras operações do console.

## 11. Como funciona a galeria de produtos

A estrutura nova separa três conceitos:

1. **Produto:** identidade comercial do item.
2. **Variação:** cor/modelo/versão do mesmo produto.
3. **Imagem:** arquivo da biblioteca.

Uma variação pode ter várias imagens e a mesma imagem pode ser reutilizada.

Isso resolve o caso em que um mesmo celular possui Azul, Preto e Branco e cada cor precisa de sua própria sequência de fotos.

## 12. Banco de dados novo

Foram adicionadas as tabelas:

`produto_variantes`

`produto_variante_imagens`

A tabela `produto_variante_imagens` faz a relação entre a variação e todas as imagens dela, inclusive qual é a principal e a ordem de exibição.

## 13. Migração

Para instalação nova, use:

`database/shinka_db.sql`

Para banco já existente, execute a migração correspondente em:

`database/migracao_existente.sql`

Ela cria a estrutura de variações e transforma produtos antigos em uma variação `Padrão`, vinculando a imagem principal antiga quando existir.

## 14. Acesso administrativo

Cadastre uma conta local e promova-a pelo terminal com `php tools/promover_admin.php seu-email`, conforme o README. Não há senha padrão. O login utiliza `password_verify()` para comparar a senha informada com o hash armazenado.


## 15. Fluxo recomendado de operação

1. Suba imagens para o Banco de imagens.
2. Crie o produto.
3. Crie suas variações de cor/modelo.
4. Selecione várias imagens para cada variação.
5. Escolha a imagem principal.
6. Ative o produto.
7. Configure estoque.
8. Crie banners/cupons quando necessário.
9. Acompanhe pedidos no OMS.
10. Use Auditoria para conferência das alterações.

## 16. Instalação local

1. Coloque o projeto dentro do `htdocs` do XAMPP.
2. Inicie Apache e MySQL.
3. Importe `database/shinka_db.sql` no phpMyAdmin.
4. Acesse `/public/`.
5. Acesse `/public/admin/login.html` para o console.

## 17. Importante sobre imagens

Mesmo com a biblioteca no banco, o uso de imagens de terceiros deve respeitar licença e direitos de uso. Para produção, prefira imagens próprias ou licenciadas.

## 18. Loja / Categorias — personalização do cliente

O módulo **Loja / Categorias** permite que o catálogo administrável altere o conteúdo que o cliente vê sem editar HTML manualmente.

O ADM pode editar nome, slug, descrição, tema, texto de destaque, texto principal e ativação.

Os temas das categorias originais são:

- Smartphones: azul tecnológico;
- Notebooks: dourado/performance;
- Tablets: verde/teal/versatilidade;
- TVs: violeta/cinema;
- Padrão: identidade SHINKA neutra.

Os botões das categorias também foram normalizados para alinhamento central vertical e horizontal.

## 19. Carrinho, checkout e cliente

A loja recebeu um fluxo completo de compra:

`produto → carrinho → checkout → pedido → histórico → rastreio`

O carrinho usa `localStorage` apenas para manter a seleção temporária do navegador. No checkout, produto, preço e estoque são novamente conferidos no servidor antes da criação do pedido.

## 20. Entrega com Correios

A área de pedidos aceita código de rastreio e previsão de entrega.

O cliente consulta os eventos pela camada `services/CorreiosService.php` e não recebe o token da API. Sem credencial configurada, o sistema mantém uma estimativa de referência e oferece o rastreamento oficial.

A integração é preparada para:

- API Rastro;
- API Prazo;
- PAC;
- SEDEX;
- ambiente de produção/homologação.

## 21. Limite desta entrega

O checkout registra a forma de pagamento escolhida e o respectivo status no banco, mas não faz a cobrança financeira real. Para isso, conecte um gateway de pagamento.
