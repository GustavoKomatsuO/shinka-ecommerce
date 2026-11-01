-- ============================================================
-- SHINKA E-COMMERCE — INSTALAÇÃO COMPLETA
-- MySQL 8+ / MariaDB compatível com XAMPP.
--
-- IMPORTANTE:
-- 1) Faça backup antes de importar em um banco já usado.
-- 2) Para uma instalação nova, este arquivo cria o banco inteiro.
-- 3) O arquivo database/migracao_existente.sql serve para a versão
--    antiga da tabela usuarios, sem apagar os dados existentes.
-- ============================================================

CREATE DATABASE IF NOT EXISTS shinka_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE shinka_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  cpf VARCHAR(11) NOT NULL,
  email VARCHAR(150) NOT NULL,
  senha VARCHAR(255) NOT NULL,
  papel ENUM('cliente','admin_master','catalogo','logistica','suporte') NOT NULL DEFAULT 'cliente',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuarios_email (email),
  UNIQUE KEY uk_usuarios_cpf (cpf),
  KEY idx_usuarios_papel (papel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  nome VARCHAR(80) NOT NULL,
  descricao VARCHAR(255) NULL,
  tema VARCHAR(40) NOT NULL DEFAULT 'default',
  destaque_texto VARCHAR(180) NULL,
  hero_texto VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_categorias_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compatibilidade: garante as colunas da personalização antes dos INSERTs,
-- inclusive quando a tabela já existia em uma versão antiga do projeto.
ALTER TABLE categorias ADD COLUMN IF NOT EXISTS tema VARCHAR(40) NOT NULL DEFAULT 'default';
ALTER TABLE categorias ADD COLUMN IF NOT EXISTS destaque_texto VARCHAR(180) NULL;
ALTER TABLE categorias ADD COLUMN IF NOT EXISTS hero_texto VARCHAR(255) NULL;

-- Biblioteca de mídia: o binário da imagem fica no MySQL.
CREATE TABLE IF NOT EXISTS imagens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome_arquivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    tamanho_bytes INT UNSIGNED NOT NULL,
    alt_text VARCHAR(255) NULL,
    origem_url TEXT NULL,
    sha256 CHAR(64) NOT NULL,
    arquivo MEDIUMBLOB NOT NULL,
    criado_por INT UNSIGNED NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    uso ENUM('produto','banner') NOT NULL DEFAULT 'produto',
    largura INT UNSIGNED NULL,
    altura INT UNSIGNED NULL,
    mime_original VARCHAR(80) NULL,
    tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem',
    processado_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_imagens_sha256 (sha256),
    KEY idx_imagens_ativo (ativo),
    KEY idx_imagens_uso (uso),
    CONSTRAINT fk_imagens_usuario
        FOREIGN KEY (criado_por)
        REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS imagem_derivadas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    imagem_id INT UNSIGNED NOT NULL,
    preset VARCHAR(40) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    largura INT UNSIGNED NOT NULL,
    altura INT UNSIGNED NOT NULL,
    tamanho_bytes INT UNSIGNED NOT NULL,
    arquivo MEDIUMBLOB NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_imagem_preset (imagem_id, preset),
    KEY idx_imagem_derivadas_preset (preset),
    CONSTRAINT fk_imagem_derivada_imagem
        FOREIGN KEY (imagem_id)
        REFERENCES imagens(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS produtos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT UNSIGNED NOT NULL,
  imagem_id INT UNSIGNED NULL,
  nome VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  familia VARCHAR(100) NULL,
  descricao TEXT NULL,
  especificacoes VARCHAR(700) NULL,
  preco DECIMAL(12,2) NOT NULL,
  preco_promocional DECIMAL(12,2) NULL,
  preco_anterior DECIMAL(12,2) NULL,
  badge VARCHAR(80) NULL,
  estoque INT NOT NULL DEFAULT 0,
  estoque_minimo INT NOT NULL DEFAULT 5,
  destaque TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_produtos_slug (slug),
  KEY idx_produtos_categoria (categoria_id),
  KEY idx_produtos_ativo (ativo),
  CONSTRAINT fk_produtos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id),
  CONSTRAINT fk_produtos_imagem FOREIGN KEY (imagem_id) REFERENCES imagens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Variações do produto: permite representar cores/modelos do mesmo SKU comercial.
CREATE TABLE IF NOT EXISTS produto_variantes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  nome VARCHAR(120) NOT NULL,
  sku VARCHAR(120) NULL,
  cor_nome VARCHAR(80) NULL,
  cor_hex CHAR(7) NULL,
  swatch_imagem_id INT UNSIGNED NULL,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_variantes_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  CONSTRAINT fk_variantes_swatch FOREIGN KEY (swatch_imagem_id) REFERENCES imagens(id) ON DELETE SET NULL,
  UNIQUE KEY uk_variantes_produto_sku (produto_id, sku),
  KEY idx_variantes_produto (produto_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Relação N:N entre variações e imagens. Uma variação pode ter várias fotos
-- (frente, costas, lateral etc.) e uma mesma imagem pode ser reutilizada.
CREATE TABLE IF NOT EXISTS produto_variante_imagens (
  variante_id INT UNSIGNED NOT NULL,
  imagem_id INT UNSIGNED NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  principal TINYINT(1) NOT NULL DEFAULT 0,
  alt_text VARCHAR(255) NULL,
  tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem',
  midia_url VARCHAR(500) NULL,
  PRIMARY KEY (variante_id, imagem_id),
  KEY idx_variante_imagens_ordem (variante_id, ordem),
  CONSTRAINT fk_variante_imagens_variante FOREIGN KEY (variante_id) REFERENCES produto_variantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_variante_imagens_imagem FOREIGN KEY (imagem_id) REFERENCES imagens(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS centros_distribuicao (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  cidade VARCHAR(100) NOT NULL,
  estado CHAR(2) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estoque_centros (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_id INT UNSIGNED NOT NULL,
  produto_id INT UNSIGNED NOT NULL,
  quantidade INT NOT NULL DEFAULT 0,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_estoque_centro_produto (centro_id, produto_id),
  KEY idx_estoque_produto (produto_id),
  CONSTRAINT fk_estoque_centro FOREIGN KEY (centro_id) REFERENCES centros_distribuicao(id),
  CONSTRAINT fk_estoque_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pedidos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  status ENUM('aguardando_pagamento','pago','separacao','enviado','entregue','cancelado','estornado') NOT NULL DEFAULT 'aguardando_pagamento',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  frete DECIMAL(12,2) NOT NULL DEFAULT 0,
  desconto DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  forma_pagamento VARCHAR(40) NULL,
  pagamento_status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente',
  endereco_entrega JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  KEY idx_pedidos_status (status),
  KEY idx_pedidos_usuario (usuario_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pedido_status_historico (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL,
  detalhe VARCHAR(255) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pedido_status_historico_pedido (pedido_id, criado_em),
  CONSTRAINT fk_pedido_status_historico_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pedido_itens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id BIGINT UNSIGNED NOT NULL,
  produto_id INT UNSIGNED NOT NULL,
  nome_produto VARCHAR(160) NOT NULL,
  quantidade INT UNSIGNED NOT NULL,
  preco_unitario DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_itens_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_itens_produto FOREIGN KEY (produto_id) REFERENCES produtos(id),
  KEY idx_itens_pedido (pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entregas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id BIGINT UNSIGNED NOT NULL,
  transportadora VARCHAR(120) NULL,
  codigo_rastreio VARCHAR(120) NULL,
  enviado_em DATETIME NULL,
  entregue_em DATETIME NULL,
  cep_destino VARCHAR(8) NULL,
  previsao_entrega DATE NULL,
  ultimo_status VARCHAR(160) NULL,
  ultimo_evento_em DATETIME NULL,
  UNIQUE KEY uk_entrega_pedido (pedido_id),
  CONSTRAINT fk_entregas_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS enderecos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  apelido VARCHAR(60) NULL,
  destinatario VARCHAR(150) NOT NULL,
  cep VARCHAR(8) NOT NULL,
  logradouro VARCHAR(180) NOT NULL,
  numero VARCHAR(30) NOT NULL,
  complemento VARCHAR(120) NULL,
  bairro VARCHAR(120) NOT NULL,
  cidade VARCHAR(120) NOT NULL,
  estado CHAR(2) NOT NULL,
  principal TINYINT(1) NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_enderecos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  KEY idx_enderecos_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagamentos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id BIGINT UNSIGNED NOT NULL,
  forma VARCHAR(40) NOT NULL,
  valor DECIMAL(12,2) NOT NULL,
  status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente',
  transacao VARCHAR(120) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pagamentos_pedido (pedido_id),
  CONSTRAINT fk_pagamentos_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(60) NOT NULL,
  descricao VARCHAR(255) NULL,
  tipo ENUM('percentual','valor') NOT NULL DEFAULT 'percentual',
  valor DECIMAL(12,2) NOT NULL,
  valor_minimo DECIMAL(12,2) NOT NULL DEFAULT 0,
  limite_uso INT UNSIGNED NULL,
  usado INT UNSIGNED NOT NULL DEFAULT 0,
  inicio DATETIME NULL,
  fim DATETIME NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cupons_codigo (codigo),
  KEY idx_cupons_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS banners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  imagem_id INT UNSIGNED NULL,
  titulo VARCHAR(160) NOT NULL,
  subtitulo VARCHAR(255) NULL,
  link VARCHAR(255) NULL,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_banners_imagem FOREIGN KEY (imagem_id) REFERENCES imagens(id) ON DELETE SET NULL,
  KEY idx_banners_ativo_ordem (ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  acao VARCHAR(120) NOT NULL,
  entidade VARCHAR(80) NULL,
  entidade_id BIGINT UNSIGNED NULL,
  detalhes JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_logs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  KEY idx_admin_logs_data (criado_em)
) ENGINE=InnoDB;

INSERT INTO categorias (slug, nome, descricao, tema, destaque_texto, hero_texto) VALUES
  ('smartphones', 'Smartphones', 'Linha Mirai: dobráveis, premium e acessíveis.', 'smartphones', 'Inovação que cabe na sua mão.', 'Encontre a linha Mirai ideal para seu perfil.'),
  ('notebooks', 'Notebooks', 'Desempenho para trabalho, estudo e criação.', 'notebooks', 'Potência para produzir sem limites.', 'Escolha o notebook SHINKA para trabalho, estudo e criação.'),
  ('tablets', 'Tablets', 'Mobilidade, produtividade e entretenimento.', 'tablets', 'Versatilidade para todos os momentos.', 'Encontre um tablet SHINKA para criar, estudar e aproveitar.'),
  ('tv', 'TVs', 'Imagem avançada para entretenimento e negócios.', 'tv', 'Cinema que transforma o ambiente.', 'Descubra TVs SHINKA para uma experiência visual imersiva.')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao), ativo = 1;

INSERT INTO centros_distribuicao (nome, cidade, estado) VALUES
  ('CD Sudeste', 'Belo Horizonte', 'MG'),
  ('CD São Paulo', 'São Paulo', 'SP'),
  ('CD Sul', 'Curitiba', 'PR')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), cidade = VALUES(cidade), estado = VALUES(estado), ativo = 1;

-- Contas são criadas localmente pelo cadastro; nenhum usuário padrão é publicado.

-- Contas são criadas localmente pelo cadastro; nenhum usuário padrão é publicado.

-- Produtos de demonstração. As imagens ficam em branco até o Admin
-- vinculá-las pela Biblioteca de Imagens.
INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Mirai Z Fold', 'mirai-z-fold', 'Mirai Z', 'Smartphone dobrável premium com foco em produtividade.', 'Tela AMOLED flexível 7.6" • 512GB • Câmera tripla 50MP', 8999.00, NULL, 9499.00, 'Dobrável Premium', 12, 5, 1 FROM categorias WHERE slug='smartphones'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Mirai Z Flip', 'mirai-z-flip', 'Mirai Z', 'Formato compacto com tela dobrável para mobilidade.', '256GB • Câmera 50MP com IA • 5G', 5499.00, NULL, NULL, 'Dobrável Compacto', 18, 5, 1 FROM categorias WHERE slug='smartphones'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Mirai Pro Ultra', 'mirai-pro-ultra', 'Mirai Pro', 'Desempenho para usuários que exigem o máximo.', 'Câmera 200MP • Processador Ultra • Stylus', 6299.00, NULL, 6799.00, 'Alta Performance', 9, 5, 1 FROM categorias WHERE slug='smartphones'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Mirai Pro', 'mirai-pro', 'Mirai Pro', 'Equilíbrio entre potência, design e produtividade.', 'Tela 120Hz • 256GB • Liga de alumínio', 4199.00, NULL, NULL, 'Profissional', 23, 5, 0 FROM categorias WHERE slug='smartphones'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Mirai Neo 5G', 'mirai-neo-5g', 'Mirai Neo', 'Autonomia e conectividade para o dia a dia.', '5000mAh • 128GB • 5G', 1899.00, NULL, 2099.00, 'Melhor Custo-Benefício', 35, 8, 1 FROM categorias WHERE slug='smartphones'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Mirai Neo Essential', 'mirai-neo-essential', 'Mirai Neo', 'Modelo acessível para tarefas essenciais.', '128GB • Tela AMOLED • Bateria de longa duração', 999.00, NULL, NULL, 'Acessível', 52, 10, 0 FROM categorias WHERE slug='smartphones'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Book Ultra', 'book-ultra', 'Book', 'Notebook para produtividade e criação profissional.', 'Tela 16" • 1TB SSD • 32GB RAM', 8489.00, NULL, 8999.00, 'Mais potência', 7, 4, 1 FROM categorias WHERE slug='notebooks'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Book Pro', 'book-pro', 'Book', 'Notebook premium para trabalho e estudo.', 'Tela 15" • 512GB SSD • 16GB RAM', 5499.00, NULL, NULL, 'Produtividade', 16, 5, 1 FROM categorias WHERE slug='notebooks'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Book Lite', 'book-lite', 'Book', 'Leve, portátil e acessível.', 'Tela 14" • 256GB SSD • 8GB RAM', 2899.00, NULL, 3099.00, 'Acessível', 21, 6, 0 FROM categorias WHERE slug='notebooks'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Tab S Max', 'tab-s-max', 'Tab', 'Tablet premium para produtividade e entretenimento.', 'Tela 14.6" • 512GB • S Pen', 4299.00, NULL, 4699.00, 'Novo', 14, 5, 1 FROM categorias WHERE slug='tablets'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Tab Active', 'tab-active', 'Tab', 'Robusto para mobilidade e ambientes de trabalho.', 'Tela 10.1" • 128GB • Resistência avançada', 2399.00, NULL, NULL, 'Resistente', 11, 4, 0 FROM categorias WHERE slug='tablets'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Neo QLED 8K 55"', 'neo-qled-8k-55', 'Neo QLED', 'Imagem premium para cinema em casa.', '55" • 8K • Smart TV', 8999.00, NULL, 9999.00, '8K', 6, 3, 1 FROM categorias WHERE slug='tv'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Ultra Display 4K 65"', 'ultra-display-4k-65', 'Ultra Display', 'Tela 4K para entretenimento imersivo.', '65" • 4K • HDR', 5499.00, NULL, NULL, 'Mais vendido', 10, 3, 1 FROM categorias WHERE slug='tv'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO produtos (categoria_id, nome, slug, familia, descricao, especificacoes, preco, preco_promocional, preco_anterior, badge, estoque, estoque_minimo, destaque)
SELECT id, 'SHINKA Smart Frame 55"', 'smart-frame-55', 'Smart Frame', 'Design fino que integra tecnologia e decoração.', '55" • 4K • Modo arte', 4499.00, NULL, 4799.00, 'Design', 8, 3, 0 FROM categorias WHERE slug='tv'
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

-- Distribuição inicial: todo o estoque demonstrativo começa no CD Sudeste.
INSERT INTO estoque_centros (centro_id, produto_id, quantidade)
SELECT (SELECT id FROM centros_distribuicao WHERE nome='CD Sudeste' LIMIT 1), p.id, p.estoque
FROM produtos p
ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade);


UPDATE categorias SET
  tema = CASE slug
    WHEN 'smartphones' THEN 'smartphones'
    WHEN 'notebooks' THEN 'notebooks'
    WHEN 'tablets' THEN 'tablets'
    WHEN 'tv' THEN 'tv'
    ELSE 'default'
  END
WHERE tema IS NULL OR tema='default';

INSERT INTO cupons (codigo, descricao, tipo, valor, valor_minimo, ativo)
VALUES ('SHINKA10', '10% de desconto para demonstração', 'percentual', 10.00, 300.00, 1)
ON DUPLICATE KEY UPDATE descricao=VALUES(descricao), ativo=1;

INSERT INTO banners (imagem_id, titulo, subtitulo, link, ordem, ativo) VALUES
(NULL, 'Tecnologia que acompanha seu ritmo', 'Explore a linha SHINKA.', 'telaPrincipla/index.html', 1, 1),
(NULL, 'Compra simples. Entrega acompanhada.', 'Checkout, pagamento simulado e rastreamento em um só fluxo.', 'telaPrincipla/carrinho.html', 2, 1);

SET FOREIGN_KEY_CHECKS = 1;
