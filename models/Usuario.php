<?php
/**
 * Modelo de usuário.
 * As regras de autenticação ficam nos controllers; o modelo apenas encapsula
 * consultas simples ao banco para evitar SQL espalhado pela aplicação.
 */
require_once __DIR__ . '/../public/config_db/database.php';

class Usuario
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function cadastrarCliente(string $nome, string $cpf, string $email, string $senha): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (nome, cpf, email, senha, papel, ativo)
             VALUES (:nome, :cpf, :email, :senha, "cliente", 1)'
        );
        return $stmt->execute([
            ':nome' => $nome,
            ':cpf' => $cpf,
            ':email' => strtolower($email),
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
        ]);
    }

    public function buscarPorEmail(string $email): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM usuarios WHERE email=:email LIMIT 1');
        $stmt->execute([':email' => strtolower($email)]);
        return $stmt->fetch();
    }

    public function buscarPorCpf(string $cpf): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM usuarios WHERE cpf=:cpf LIMIT 1');
        $stmt->execute([':cpf' => preg_replace('/\D/', '', $cpf)]);
        return $stmt->fetch();
    }
}
