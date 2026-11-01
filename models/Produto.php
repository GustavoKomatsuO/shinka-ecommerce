<?php
/**
 * Modelo de produto.
 * O estoque é controlado pelo módulo de logística; por isso este modelo não
 * altera saldo de centro de distribuição diretamente.
 */
require_once __DIR__ . '/../public/config_db/database.php';

class Produto
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function buscarAtivosPorCategoria(string $categoria): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.slug categoria, c.nome categoria_nome
             FROM produtos p
             INNER JOIN categorias c ON c.id=p.categoria_id
             WHERE p.ativo=1 AND c.slug=:categoria
             ORDER BY p.destaque DESC, p.criado_em DESC'
        );
        $stmt->execute([':categoria' => $categoria]);
        return $stmt->fetchAll();
    }
}
