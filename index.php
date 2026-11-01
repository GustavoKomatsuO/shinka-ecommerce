<?php
// Encaminha o acesso pela raiz para a entrada pública da aplicação.
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: public/index.php' . ($query !== '' ? '?' . $query : ''));
exit;
