# SHINKA — Guia de entendimento do sistema

## 1. Visão rápida

O SHINKA é dividido em duas experiências: **Loja do cliente** e **Console Administrativo**. O cliente pesquisa, escolhe, adiciona ao carrinho, informa entrega, simula pagamento, recebe a confirmação e acompanha o pedido. O ADM controla catálogo, categorias, imagens, pedidos, estoque, clientes, campanhas e auditoria.

## 2. Como o cliente usa

1. Crie a conta e entre.
2. Pesquise ou abra uma categoria.
3. Use os filtros e abra o produto.
4. Escolha a variação e adicione ao carrinho.
5. No checkout, informe o endereço.
6. Escolha PAC ou SEDEX e consulte o prazo.
7. Selecione PIX, cartão ou boleto. Nesta entrega, o **pagamento é simulado**: nenhum valor real é cobrado e os dados do cartão não são armazenados.
8. Finalize a compra. O pedido é criado com pagamento aprovado em modo demonstração.
9. Consulte o pedido e, quando houver código, acompanhe o rastreamento.

## 3. Como o ADM usa

### Dashboard
Indicadores de clientes, produtos, pedidos e faturamento.

### Catálogo
Use a barra de pesquisa e filtros para encontrar produtos. Clique em **Editar** para abrir o editor por etapas: identificação, preços, mídia, variações e publicação. **Salvar produto** grava; **Cancelar** e **X** fecham sem salvar.

### Loja / Categorias
Cada categoria pode ter nome, descrição, tema, texto de destaque e texto principal. O cliente recebe uma identidade visual específica por categoria sem sair da linguagem SHINKA.

### Banco de imagens
As imagens são salvas no sistema de arquivos em `C:\xampp\htdocs\img\produtos` e `C:\xampp\htdocs\img\banners`. O MySQL guarda o caminho relativo em `imagens.caminho_arquivo`; o `imagem_id` continua sendo usado para vincular a imagem ao produto, SKU/variação e banner.

### Pedidos / OMS
Atualize o status e, quando enviado, informe o código de rastreamento.

### Estoque / CDs
Acompanhe o estoque total e sua distribuição pelos centros.

### Clientes / Suporte
Consulte clientes e histórico de pedidos.

### Campanhas
Cadastre banners e cupons.

### Auditoria
Consulte registros de ações administrativas.

## 4. Fluxo técnico

```text
Browser → public/index.php → Controller → Model/Database
                     ↘ Service (Correios)

Cliente: HTML/CSS/JS → CustomerController
ADM: HTML/CSS/JS → AdminController / AdminAuthController
Imagens: ImageController → ImageService → MySQL (BLOB)
```

## 5. Organização de código

- `controllers/`: regras de entrada e negócio por domínio.
- `models/`: entidades/regras de acesso específicas.
- `services/`: integrações externas, como Correios.
- `helpers/`: segurança, imagens e utilidades.
- `public/`: arquivos que o navegador acessa.
- `database/`: instalação e migração.
- `tools/`: utilitários de manutenção, como geração de hash.

A regra é manter cada arquivo pequeno e focado: interface não acessa banco diretamente; controller não deve virar biblioteca de componentes; integrações externas ficam em `services/`.

## 6. Credenciais de demonstração

Acessos: siga a criação local de contas descrita no README principal.
Crie sua conta e promova o administrador pelo terminal conforme o README.

Crie sua conta e promova o administrador pelo terminal conforme o README.


## 7. Matriz de SKU e mídia — opção A

O editor de produto separa deliberadamente o **Produto Pai** das **Variações/SKUs Filhos**.

```text
PRODUTO PAI
├── Informações gerais
├── Imagem global
│   └── Fotos que servem para qualquer cor/variante
└── MATRIZ DE SKUs
    ├── Verde / SKU GALAXY-VD-256
    │   ├── Foto 1 — Principal / Thumbnail
    │   ├── Foto 2 — lateral
    │   ├── Foto 3 — traseira
    │   └── Foto 4 — detalhe técnico
    ├── Preto / SKU GALAXY-PT-256
    └── Creme / SKU GALAXY-CR-512
```

Na tela **Mídia e variantes**, cada cor possui uma área de arrastar/soltar com upload múltiplo. As imagens entram apenas na variante selecionada. A ordem pode ser alterada por arraste; a primeira é tratada como principal. Cada vínculo possui ALT, tipo de mídia e URL opcional para mídia externa. O swatch pode ser escolhido da biblioteca e também existe a cor em HEX.

## 8. Banco de dados das imagens

A relação importante é:

```text
produtos (Pai)
   1 ───────── N
produto_variantes (Filhos/SKUs)
   N ───────── N
produto_variante_imagens
   N ───────── 1
imagens
```

`produtos.imagem_id` guarda a mídia global do produto. `produto_variantes` guarda atributos como nome, SKU, cor, HEX e o `swatch_imagem_id`. `produto_variante_imagens` é a tabela de associação que permite várias fotos por cor, com `ordem`, `principal`, `alt_text`, `tipo_midia` e `midia_url`.

O arquivo original fica em `imagens.arquivo` (MEDIUMBLOB). O processamento cria derivações em `imagem_derivadas`: `thumb` 480 px, `medium` 960 px, `large` 1400 px e `superzoom` 2200 px, priorizando formatos modernos quando o servidor possui suporte. Isso evita fazer o administrador preparar manualmente cada tamanho.

## 9. Pedido, status e rastreio

Quando o checkout cria o pedido, a SHINKA registra também o primeiro evento em `pedido_status_historico`. Cada alteração posterior feita pelo painel administrativo grava uma nova entrada. O cliente usa esse histórico para enxergar um trajeto amigável em `pedido.html` e `rastrear.html`.

O rastreio externo dos Correios é complementar: quando existem eventos, eles aparecem como **Atualizações de transporte**. O cliente não precisa basear a experiência somente em um código cru.

## 10. Catálogo e filtros

No ADM, a pesquisa fica em uma área própria e os filtros em uma área separada. A busca considera nome, slug, família, categoria, descrição, especificações, badge e dados das variantes/SKUs. O filtro também permite categoria, ativo/inativo e estoque baixo.

Na home, os filtros da vitrine deixam de limitar o cliente aos poucos cards fixos: ao escolher uma categoria, o sistema consulta o catálogo e monta o grid com todos os produtos daquela categoria, mantendo o mesmo estilo visual da SHINKA.

## 11. Cadastro e checkout do cliente

O painel de cadastro não utiliza mais vídeo; o espaço superior é concluído até a base com benefícios da conta. O checkout permanece de uma página e apresenta visual de PIX e cartão. Nesta versão, os pagamentos continuam **simulados**, portanto o QR PIX exibido é apenas uma composição visual de demonstração e não pode receber dinheiro real.

## 12. Mirai Z

A área do vídeo do Mirai Z possui uma máscara visual persistente sobre a região reservada para o aparelho. Como a máscara é independente do estado `play`/`ended` do vídeo, ela não é removida quando o vídeo termina.

## 13. WhatsApp e integrações reais

A interface de rastreio está preparada para uma experiência orientada por status, mas o projeto entregue ainda não possui um provedor de envio automático de WhatsApp configurado. Não é correto chamar isso de automação ativa sem uma API/WhatsApp Business configurada. A documentação deve ser atualizada quando essa credencial e integração forem adicionadas.
