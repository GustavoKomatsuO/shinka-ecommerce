# SHINKA — Armazenamento de imagens em `htdocs\img`

O armazenamento de imagens foi alterado para não depender de BLOB nem de URL externa.

## Estrutura

Quando o projeto estiver em `C:\xampp\htdocs\projeto_ecommerce`, os arquivos são gravados automaticamente em:

- `C:\xampp\htdocs\img\produtos` — fotos de produtos/SKUs.
- `C:\xampp\htdocs\img\banners` — versões dos banners do carrossel.

O banco grava o caminho relativo em `imagens.caminho_arquivo` e `imagem_derivadas.caminho_arquivo`. O relacionamento continua sendo feito por `produtos.imagem_id`, `produto_variante_imagens.imagem_id` e `banners.imagem_id`.

## Fluxo novo

1. No Admin, entre em **Banco de imagens**.
2. Escolha um arquivo já baixado no computador.
3. Clique em **Salvar em img**.
4. O PHP valida/processa a imagem.
5. O arquivo é salvo em `htdocs\img`.
6. O MySQL registra somente os metadados e o caminho.
7. Ao vincular essa imagem a um produto, o produto guarda apenas o `imagem_id`.
8. A rota `api/imagem` resolve o caminho salvo no banco e entrega o arquivo correto.

## URL externa removida

A opção **Importar por URL** e a rota `api/admin/imagem/url` foram removidas. Isso evita dependência de CDN, redirecionamentos e bloqueios de hotlink da origem.

## Banco legado

Instalações antigas podem ainda possuir os campos BLOB `arquivo`. Eles foram mantidos apenas para compatibilidade temporária. O novo código não grava novos BLOBs.

Para migrar tudo de uma vez, execute no diretório do projeto:

```text
php database/migrar_imagens_para_pasta.php
```

O script copia os BLOBs existentes para `htdocs\img` e atualiza `caminho_arquivo`. As imagens antigas também conseguem ser migradas automaticamente no primeiro acesso pela rota de imagem.

## Permissões

O usuário do Apache/PHP precisa ter permissão de escrita em `C:\xampp\htdocs\img`.

## Download

O botão **Baixar** do Admin agora faz o download do arquivo que já está salvo em `img`. O navegador continua controlando a pasta de downloads do usuário; ele não pode ser forçado pelo PHP a salvar no `htdocs\img`. O que o SHINKA salva automaticamente em `htdocs\img` é o arquivo enviado pelo formulário do Admin.
