-- SHINKA — migração estrutural das imagens para a pasta htdocs/img
-- Esta etapa adiciona as colunas de caminho e deixa os BLOBs legados opcionais.
-- A cópia física dos BLOBs antigos para o disco é feita pelo script PHP
-- database/migrar_imagens_para_pasta.php ou automaticamente no primeiro acesso.

ALTER TABLE imagens
  ADD COLUMN IF NOT EXISTS caminho_arquivo VARCHAR(500) NULL AFTER sha256;

ALTER TABLE imagens
  MODIFY COLUMN arquivo MEDIUMBLOB NULL;

ALTER TABLE imagem_derivadas
  ADD COLUMN IF NOT EXISTS caminho_arquivo VARCHAR(500) NULL AFTER tamanho_bytes;

ALTER TABLE imagem_derivadas
  MODIFY COLUMN arquivo MEDIUMBLOB NULL;
