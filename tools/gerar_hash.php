<?php


if (PHP_SAPI !== 'cli') {
    exit("Execute este arquivo pelo terminal.\n");
}

$senha = $argv[1] ?? '';
if ($senha === '') {
    exit("Uso: php tools/gerar_hash.php \"MinhaSenhaForte\"\n");
}

echo password_hash($senha, PASSWORD_DEFAULT) . PHP_EOL;
