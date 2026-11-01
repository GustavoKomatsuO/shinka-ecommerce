<?php
/**
 * SHINKA — conexão central com MySQL.
 *
 * Em desenvolvimento local/XAMPP, a aplicação verifica e cria as tabelas
 * essenciais caso ainda não existam. Em produção, recomenda-se usar
 * migrações versionadas em vez de DDL a cada conexão.
 */

class Database {
    private static ?PDO $conn = null;
    private static string $host = 'localhost';
    private static string $dbName = 'shinka_db';
    private static string $username = 'root';
    private static string $password = '';

    public static function getConnection(): PDO {
        if (self::$conn instanceof PDO) {
            return self::$conn;
        }

        $localFile = __DIR__ . '/../../config/database.local.php';
        $local = is_file($localFile) ? require $localFile : [];
        self::$host = (string) (getenv('DB_HOST') !== false ? getenv('DB_HOST') : ($local['host'] ?? 'localhost'));
        self::$dbName = (string) (getenv('DB_NAME') !== false ? getenv('DB_NAME') : ($local['database'] ?? 'shinka_db'));
        self::$username = (string) (getenv('DB_USER') !== false ? getenv('DB_USER') : ($local['username'] ?? 'root'));
        self::$password = (string) (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($local['password'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_]+$/', self::$dbName)) {
            throw new InvalidArgumentException('DB_NAME deve conter apenas letras, números e underscore.');
        }

        try {
            // Conecta primeiro ao servidor MySQL, sem selecionar o banco,
            // para permitir a criação automática do `shinka_db` em uma
            // instalação nova do projeto.
            $server = new PDO(
                'mysql:host=' . self::$host . ';charset=utf8mb4',
                self::$username,
                self::$password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            self::$conn = new PDO(
                'mysql:host=' . self::$host . ';dbname=' . self::$dbName . ';charset=utf8mb4',
                self::$username,
                self::$password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('SHINKA conexão com o banco falhou: ' . $e->getMessage());
            $mensagem = 'Erro ao conectar/criar o banco de dados. Verifique se o MySQL do XAMPP está ativo e se o usuário possui permissão para criar o banco.';
            // Rotas da API respondem em JSON (o painel e a loja leem `message`);
            // antes o texto puro aparecia como "resposta inválida do servidor".
            if (str_starts_with(trim((string) ($_GET['route'] ?? ''), '/'), 'api/')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE);
                exit;
            }
            die($mensagem);
        }

        self::ensureSchema(self::$conn);
        return self::$conn;
    }

    private static function ensureSchema(PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS categorias (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(60) NOT NULL,
                nome VARCHAR(80) NOT NULL,
                descricao VARCHAR(255) NULL,
                tema VARCHAR(40) NOT NULL DEFAULT 'default',
                destaque_texto VARCHAR(180) NULL,
                hero_texto VARCHAR(255) NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_categorias_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS produtos (
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
                peso_kg DECIMAL(8,3) NULL,
                altura_cm DECIMAL(8,2) NULL,
                largura_cm DECIMAL(8,2) NULL,
                comprimento_cm DECIMAL(8,2) NULL,
                destaque TINYINT(1) NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_produtos_slug (slug),
                KEY idx_produtos_categoria (categoria_id),
                KEY idx_produtos_ativo (ativo),
                CONSTRAINT fk_produtos_categoria
                    FOREIGN KEY (categoria_id)
                    REFERENCES categorias(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec("ALTER TABLE produtos ADD COLUMN IF NOT EXISTS peso_kg DECIMAL(8,3) NULL AFTER estoque_minimo");
        $pdo->exec("ALTER TABLE produtos ADD COLUMN IF NOT EXISTS altura_cm DECIMAL(8,2) NULL AFTER peso_kg");
        $pdo->exec("ALTER TABLE produtos ADD COLUMN IF NOT EXISTS largura_cm DECIMAL(8,2) NULL AFTER altura_cm");
        $pdo->exec("ALTER TABLE produtos ADD COLUMN IF NOT EXISTS comprimento_cm DECIMAL(8,2) NULL AFTER largura_cm");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS imagens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nome_arquivo VARCHAR(255) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                tamanho_bytes INT UNSIGNED NOT NULL,
                alt_text VARCHAR(255) NULL,
                sha256 CHAR(64) NOT NULL,
                caminho_arquivo VARCHAR(500) NULL,
                arquivo MEDIUMBLOB NULL,
                criado_por INT UNSIGNED NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                uso ENUM('produto','banner') NOT NULL DEFAULT 'produto',
                largura INT UNSIGNED NULL,
                altura INT UNSIGNED NULL,
                mime_original VARCHAR(80) NULL,
                processado_em DATETIME NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_imagens_sha256 (sha256),
                KEY idx_imagens_ativo (ativo),
                KEY idx_imagens_uso (uso)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec("ALTER TABLE imagens ADD COLUMN IF NOT EXISTS tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem' AFTER mime_original");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS imagem_derivadas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                imagem_id INT UNSIGNED NOT NULL,
                preset VARCHAR(40) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                largura INT UNSIGNED NOT NULL,
                altura INT UNSIGNED NOT NULL,
                tamanho_bytes INT UNSIGNED NOT NULL,
                caminho_arquivo VARCHAR(500) NULL,
                arquivo MEDIUMBLOB NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_imagem_preset (imagem_id, preset),
                CONSTRAINT fk_imagem_derivada_imagem
                    FOREIGN KEY (imagem_id)
                    REFERENCES imagens(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS produto_variantes (
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
                UNIQUE KEY uk_variante_sku (produto_id, sku),
                KEY idx_variante_produto (produto_id),
                CONSTRAINT fk_variante_produto
                    FOREIGN KEY (produto_id)
                    REFERENCES produtos(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_variante_swatch
                    FOREIGN KEY (swatch_imagem_id)
                    REFERENCES imagens(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec("ALTER TABLE produto_variantes ADD COLUMN IF NOT EXISTS swatch_imagem_id INT UNSIGNED NULL AFTER cor_hex");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS produto_variante_imagens (
                variante_id INT UNSIGNED NOT NULL,
                imagem_id INT UNSIGNED NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                principal TINYINT(1) NOT NULL DEFAULT 0,
                alt_text VARCHAR(255) NULL,
                tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem',
                midia_url VARCHAR(500) NULL,
                PRIMARY KEY (variante_id, imagem_id),
                CONSTRAINT fk_pvi_variante
                    FOREIGN KEY (variante_id)
                    REFERENCES produto_variantes(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_pvi_imagem
                    FOREIGN KEY (imagem_id)
                    REFERENCES imagens(id)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec("ALTER TABLE produto_variante_imagens ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL AFTER principal");
        $pdo->exec("ALTER TABLE produto_variante_imagens ADD COLUMN IF NOT EXISTS tipo_midia ENUM('imagem','modelo_3d','video') NOT NULL DEFAULT 'imagem' AFTER alt_text");
        $pdo->exec("ALTER TABLE produto_variante_imagens ADD COLUMN IF NOT EXISTS midia_url VARCHAR(500) NULL AFTER tipo_midia");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS centros_distribuicao (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(120) NOT NULL,
                cidade VARCHAR(100) NOT NULL,
                estado CHAR(2) NOT NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS estoque_centros (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                centro_id INT UNSIGNED NOT NULL,
                produto_id INT UNSIGNED NOT NULL,
                quantidade INT NOT NULL DEFAULT 0,
                atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_estoque_centro_produto (centro_id, produto_id),
                CONSTRAINT fk_estoque_centro
                    FOREIGN KEY (centro_id)
                    REFERENCES centros_distribuicao(id),
                CONSTRAINT fk_estoque_produto
                    FOREIGN KEY (produto_id)
                    REFERENCES produtos(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS pedidos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT UNSIGNED NOT NULL,
                status ENUM(
                    'aguardando_pagamento',
                    'pago',
                    'separacao',
                    'enviado',
                    'entregue',
                    'cancelado',
                    'estornado'
                ) NOT NULL DEFAULT 'aguardando_pagamento',
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                frete DECIMAL(12,2) NOT NULL DEFAULT 0,
                desconto DECIMAL(12,2) NOT NULL DEFAULT 0,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                forma_pagamento VARCHAR(40) NULL,
                pagamento_status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente',
                endereco_entrega JSON NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_pedidos_usuario (usuario_id),
                KEY idx_pedidos_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS pedido_itens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pedido_id BIGINT UNSIGNED NOT NULL,
                produto_id INT UNSIGNED NOT NULL,
                nome_produto VARCHAR(160) NOT NULL,
                quantidade INT UNSIGNED NOT NULL,
                preco_unitario DECIMAL(12,2) NOT NULL,
                subtotal DECIMAL(12,2) NOT NULL,
                KEY idx_itens_pedido (pedido_id),
                CONSTRAINT fk_itens_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
                CONSTRAINT fk_itens_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS entregas (
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
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_entrega_pedido (pedido_id),
                CONSTRAINT fk_entrega_pedido
                    FOREIGN KEY (pedido_id)
                    REFERENCES pedidos(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS pedido_status_historico (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pedido_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(40) NOT NULL,
                detalhe VARCHAR(255) NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pedido_status_historico_pedido (pedido_id, criado_em),
                CONSTRAINT fk_pedido_status_historico_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS enderecos (
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
                KEY idx_enderecos_usuario (usuario_id, principal, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS pagamentos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pedido_id BIGINT UNSIGNED NOT NULL,
                forma VARCHAR(40) NOT NULL,
                valor DECIMAL(12,2) NOT NULL DEFAULT 0,
                status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente',
                transacao VARCHAR(120) NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_pagamentos_pedido (pedido_id),
                KEY idx_pagamentos_status (status, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT UNSIGNED NULL,
                acao VARCHAR(120) NOT NULL,
                entidade VARCHAR(80) NULL,
                entidade_id BIGINT NULL,
                detalhes JSON NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_admin_logs_data (criado_em),
                CONSTRAINT fk_admin_logs_usuario
                    FOREIGN KEY (usuario_id)
                    REFERENCES usuarios(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::garantirColuna($pdo, 'categorias', 'tema', "ALTER TABLE categorias ADD COLUMN tema VARCHAR(40) NOT NULL DEFAULT 'default' AFTER descricao");
        self::garantirColuna($pdo, 'categorias', 'destaque_texto', "ALTER TABLE categorias ADD COLUMN destaque_texto VARCHAR(180) NULL AFTER tema");
        self::garantirColuna($pdo, 'categorias', 'hero_texto', "ALTER TABLE categorias ADD COLUMN hero_texto VARCHAR(255) NULL AFTER destaque_texto");
        self::garantirColuna($pdo, 'pedidos', 'forma_pagamento', "ALTER TABLE pedidos ADD COLUMN forma_pagamento VARCHAR(40) NULL AFTER total");
        self::garantirColuna($pdo, 'pedidos', 'pagamento_status', "ALTER TABLE pedidos ADD COLUMN pagamento_status ENUM('pendente','aprovado','recusado','estornado') NOT NULL DEFAULT 'pendente' AFTER forma_pagamento");
        self::garantirColuna($pdo, 'pedidos', 'endereco_entrega', "ALTER TABLE pedidos ADD COLUMN endereco_entrega JSON NULL AFTER pagamento_status");
        self::garantirColuna($pdo, 'entregas', 'cep_destino', "ALTER TABLE entregas ADD COLUMN cep_destino VARCHAR(8) NULL AFTER entregue_em");
        self::garantirColuna($pdo, 'entregas', 'previsao_entrega', "ALTER TABLE entregas ADD COLUMN previsao_entrega DATE NULL AFTER cep_destino");
        self::garantirColuna($pdo, 'entregas', 'ultimo_status', "ALTER TABLE entregas ADD COLUMN ultimo_status VARCHAR(160) NULL AFTER previsao_entrega");
        self::garantirColuna($pdo, 'entregas', 'ultimo_evento_em', "ALTER TABLE entregas ADD COLUMN ultimo_evento_em DATETIME NULL AFTER ultimo_status");

        self::garantirCategorias($pdo);
    }

    private static function garantirColuna(PDO $pdo, string $tabela, string $coluna, string $sql): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:tabela AND column_name=:coluna');
        $stmt->execute([':tabela'=>$tabela, ':coluna'=>$coluna]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    private static function garantirCategorias(PDO $pdo): void {
        $categorias = [
            ['smartphones', 'Smartphones'],
            ['notebooks', 'Notebooks'],
            ['tablets', 'Tablets'],
            ['tv', 'TVs']
        ];

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO categorias (slug, nome)
             VALUES (:slug, :nome)"
        );

        foreach ($categorias as [$slug, $nome]) {
            $stmt->execute([
                ':slug' => $slug,
                ':nome' => $nome
            ]);
        }
    }

}
