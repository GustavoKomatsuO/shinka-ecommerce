<?php
/**
 * Login corporativo da área administrativa SHINKA.
 *
 * O portal administrativo tem autenticação e sessão próprias. O papel do
 * usuário define o redirecionamento inicial e quais módulos estarão disponíveis.
 */
require_once __DIR__ . '/../public/config_db/database.php';

class AdminAuthController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function login(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respostaJson(false, 'Método inválido.');
        }

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
            respostaJson(false, 'Informe e-mail e senha corporativos.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, nome, email, senha, papel, ativo
             FROM usuarios
             WHERE email=:email AND papel<>"cliente"
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch();

        if (!$usuario || (int) $usuario['ativo'] !== 1 || !password_verify($senha, $usuario['senha'])) {
            respostaJson(false, 'Credenciais administrativas inválidas.');
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_email'] = $usuario['email'];
        $_SESSION['usuario_papel'] = $usuario['papel'];
        $_SESSION['admin_autenticado'] = true;
        $_SESSION['ultimo_acesso'] = time();

        $redirect = match ($usuario['papel']) {
            'logistica' => 'index.html#estoque',
            'catalogo' => 'index.html#catalogo',
            'suporte' => 'index.html#clientes',
            default => 'index.html#dashboard',
        };

        respostaJson(true, 'Acesso administrativo autorizado.', [
            'nome' => $usuario['nome'],
            'papel' => $usuario['papel'],
            'redirect' => $redirect,
        ]);
    }
}
