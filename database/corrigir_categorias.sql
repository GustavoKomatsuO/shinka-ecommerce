-- SHINKA — correção segura da tabela categorias
-- Resolve o erro "Unknown column 'tema' in 'field list'" em bases antigas.
USE shinka_db;
SET NAMES utf8mb4;

SET @has_tema = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='tema');
SET @sql = IF(@has_tema=0, "ALTER TABLE categorias ADD COLUMN tema VARCHAR(40) NOT NULL DEFAULT 'default' AFTER descricao", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_destaque = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='destaque_texto');
SET @sql = IF(@has_destaque=0, "ALTER TABLE categorias ADD COLUMN destaque_texto VARCHAR(180) NULL AFTER tema", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_hero = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='hero_texto');
SET @sql = IF(@has_hero=0, "ALTER TABLE categorias ADD COLUMN hero_texto VARCHAR(255) NULL AFTER destaque_texto", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_ordem = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categorias' AND COLUMN_NAME='ordem');
SET @sql = IF(@has_ordem=0, "ALTER TABLE categorias ADD COLUMN ordem INT NOT NULL DEFAULT 0 AFTER hero_texto", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO categorias (slug, nome, descricao, tema, destaque_texto, hero_texto) VALUES
  ('smartphones', 'Smartphones', 'Linha Mirai: dobráveis, premium e acessíveis.', 'smartphones', 'Inovação que cabe na sua mão.', 'Encontre a linha Mirai ideal para seu perfil.'),
  ('notebooks', 'Notebooks', 'Desempenho para trabalho, estudo e criação.', 'notebooks', 'Potência para produzir sem limites.', 'Escolha o notebook SHINKA para trabalho, estudo e criação.'),
  ('tablets', 'Tablets', 'Mobilidade, produtividade e entretenimento.', 'tablets', 'Versatilidade para todos os momentos.', 'Encontre um tablet SHINKA para criar, estudar e aproveitar.'),
  ('tv', 'TVs', 'Imagem avançada para entretenimento e negócios.', 'tv', 'Cinema que transforma o ambiente.', 'Descubra TVs SHINKA para uma experiência visual imersiva.')
ON DUPLICATE KEY UPDATE
  nome = VALUES(nome),
  descricao = VALUES(descricao),
  tema = VALUES(tema),
  destaque_texto = VALUES(destaque_texto),
  hero_texto = VALUES(hero_texto),
  ativo = 1;
