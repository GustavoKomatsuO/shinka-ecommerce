-- ============================================================
-- SHINKA — MIGRAÇÃO DE INSTALAÇÃO ANTIGA
-- Use este arquivo quando a tabela usuarios já existe na versão
-- original enviada com o projeto. Ele NÃO apaga clientes.
-- Depois disso, execute o restante do database/shinka_db.sql apenas
-- se as tabelas de catálogo ainda não existirem.
-- ============================================================

USE shinka_db;

SET @has_papel = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='papel');
SET @sql = IF(@has_papel=0, "ALTER TABLE usuarios ADD COLUMN papel ENUM('cliente','admin_master','catalogo','logistica','suporte') NOT NULL DEFAULT 'cliente' AFTER senha", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_ativo = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='ativo');
SET @sql = IF(@has_ativo=0, "ALTER TABLE usuarios ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER papel", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_atualizado = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='atualizado_em');
SET @sql = IF(@has_atualizado=0, "ALTER TABLE usuarios ADD COLUMN atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER criado_em", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Contas são criadas localmente pelo cadastro; nenhum usuário padrão é publicado.


-- ============================================================
-- MIGRAÇÃO 2: GALERIA E VARIAÇÕES DE PRODUTOS
-- Execute depois da migração inicial.
-- ============================================================

CREATE TABLE IF NOT EXISTS produto_variantes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  nome VARCHAR(120) NOT NULL,
  sku VARCHAR(120) NULL,
  cor_nome VARCHAR(80) NULL,
  cor_hex CHAR(7) NULL,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_variantes_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  UNIQUE KEY uk_variantes_produto_sku (produto_id, sku),
  KEY idx_variantes_produto (produto_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS produto_variante_imagens (
  variante_id INT UNSIGNED NOT NULL,
  imagem_id INT UNSIGNED NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  principal TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (variante_id, imagem_id),
  KEY idx_variante_imagens_ordem (variante_id, ordem),
  CONSTRAINT fk_variante_imagens_variante FOREIGN KEY (variante_id) REFERENCES produto_variantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_variante_imagens_imagem FOREIGN KEY (imagem_id) REFERENCES imagens(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cria uma variação padrão para produtos que já existiam.
INSERT INTO produto_variantes (produto_id, nome, ordem)
SELECT p.id, 'Padrão', 0
FROM produtos p
LEFT JOIN produto_variantes v ON v.produto_id = p.id
WHERE v.id IS NULL;

-- Liga a imagem principal antiga à nova galeria.
INSERT IGNORE INTO produto_variante_imagens (variante_id, imagem_id, ordem, principal)
SELECT v.id, p.imagem_id, 0, 1
FROM produtos p
INNER JOIN produto_variantes v ON v.produto_id = p.id AND v.nome = 'Padrão'
WHERE p.imagem_id IS NOT NULL;

-- ============================================================
-- MIGRAÇÃO 3: CONTA, CHECKOUT, ENTREGA E PERSONALIZAÇÃO DA LOJA
-- ============================================================

SET @has_tema = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='tema');
SET @sql = IF(@has_tema=0, "ALTER TABLE categorias ADD COLUMN tema VARCHAR(40) NOT NULL DEFAULT 'default' AFTER descricao", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_destaque = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='destaque_texto');
SET @sql = IF(@has_destaque=0, "ALTER TABLE categorias ADD COLUMN destaque_texto VARCHAR(180) NULL AFTER tema", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_hero = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='hero_texto');
SET @sql = IF(@has_hero=0, "ALTER TABLE categorias ADD COLUMN hero_texto VARCHAR(255) NULL AFTER destaque_texto", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_forma = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='forma_pagamento');
SET @sql = IF(@has_forma=0, "ALTER TABLE pedidos ADD COLUMN forma_pagamento VARCHAR(40) NULL AFTER total", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_pagstatus = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='pagamento_status');
SET @sql = IF(@has_pagstatus=0, "ALTER TABLE pedidos ADD COLUMN pagamento_status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente' AFTER forma_pagamento", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_endereco = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='endereco_entrega');
SET @sql = IF(@has_endereco=0, "ALTER TABLE pedidos ADD COLUMN endereco_entrega JSON NULL AFTER pagamento_status", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_cep_dest = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='entregas' AND COLUMN_NAME='cep_destino');
SET @sql = IF(@has_cep_dest=0, "ALTER TABLE entregas ADD COLUMN cep_destino VARCHAR(8) NULL AFTER entregue_em", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_prev = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='entregas' AND COLUMN_NAME='previsao_entrega');
SET @sql = IF(@has_prev=0, "ALTER TABLE entregas ADD COLUMN previsao_entrega DATE NULL AFTER cep_destino", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_status = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='entregas' AND COLUMN_NAME='ultimo_status');
SET @sql = IF(@has_status=0, "ALTER TABLE entregas ADD COLUMN ultimo_status VARCHAR(160) NULL AFTER previsao_entrega", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_evento = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='entregas' AND COLUMN_NAME='ultimo_evento_em');
SET @sql = IF(@has_evento=0, "ALTER TABLE entregas ADD COLUMN ultimo_evento_em DATETIME NULL AFTER ultimo_status", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS enderecos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  apelido VARCHAR(60) NULL,
  destinatario VARCHAR(140) NOT NULL,
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
  KEY idx_enderecos_usuario (usuario_id, principal, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagamentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT UNSIGNED NOT NULL,
  forma VARCHAR(40) NOT NULL,
  valor DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente',
  transacao VARCHAR(120) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pagamento_pedido (pedido_id),
  KEY idx_pagamentos_status (status, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE categorias
SET tema = CASE
  WHEN LOWER(slug) = 'smartphones' THEN 'smartphones'
  WHEN LOWER(slug) = 'notebooks' THEN 'notebooks'
  WHEN LOWER(slug) = 'tablets' THEN 'tablets'
  WHEN LOWER(slug) IN ('tv','tvs') THEN 'tv'
  ELSE 'default'
END
WHERE tema = 'default' OR tema IS NULL;


-- ============================================================
-- MIGRAÇÃO 4: MATRIZ DE SKUS / MÍDIA / HISTÓRICO DE PEDIDOS
-- ============================================================

ALTER TABLE imagens ADD COLUMN IF NOT EXISTS tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem' AFTER mime_original;
ALTER TABLE produto_variantes ADD COLUMN IF NOT EXISTS swatch_imagem_id INT UNSIGNED NULL AFTER cor_hex;
ALTER TABLE produto_variante_imagens ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL AFTER principal;
ALTER TABLE produto_variante_imagens ADD COLUMN IF NOT EXISTS tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem' AFTER alt_text;
ALTER TABLE produto_variante_imagens ADD COLUMN IF NOT EXISTS midia_url VARCHAR(500) NULL AFTER tipo_midia;

SET @has_fk_swatch = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='produto_variantes'
    AND COLUMN_NAME='swatch_imagem_id'
    AND REFERENCED_TABLE_NAME='imagens'
);
SET @sql = IF(@has_fk_swatch=0,
  'ALTER TABLE produto_variantes ADD CONSTRAINT fk_variantes_swatch FOREIGN KEY (swatch_imagem_id) REFERENCES imagens(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS pedido_status_historico (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL,
  detalhe VARCHAR(255) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pedido_status_historico_pedido (pedido_id, criado_em),
  CONSTRAINT fk_pedido_status_historico_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
