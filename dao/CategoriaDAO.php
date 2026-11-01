<?php
/**
 * SHINKA — DAO de categorias.
 */
class CategoriaDAO
{
    public function __construct(private PDO $pdo) {}

    public function listar(bool $somenteAtivas = false): array
    {
        $where = $somenteAtivas ? 'WHERE c.ativo=1' : '';
        return $this->pdo->query(
            "SELECT c.id,c.slug,c.nome,c.descricao,c.tema,c.destaque_texto,c.hero_texto,c.ativo,
                    (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id=c.id) AS total_produtos
             FROM categorias c {$where} ORDER BY c.nome"
        )->fetchAll();
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categorias WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>$id]);
        $row=$stmt->fetch();
        return $row ?: null;
    }

    public function salvar(array $dados): int
    {
        $id=(int)($dados['id']??0);
        $slug=strtolower(trim((string)$dados['slug']));
        $nome=trim((string)$dados['nome']);
        $descricao=trim((string)($dados['descricao']??''));
        $tema=trim((string)($dados['tema']??'default'));
        $temas=['smartphones','notebooks','tablets','tv','default'];
        if(!in_array($tema,$temas,true)) $tema='default';
        $destaque=trim((string)($dados['destaque_texto']??''));
        $hero=trim((string)($dados['hero_texto']??''));
        $ativo=(int)($dados['ativo']??1);

        if($id>0){
            if(!$this->buscar($id)) throw new RuntimeException('Categoria não encontrada. Ela pode ter sido excluída; atualize a lista.');
            $stmt=$this->pdo->prepare('UPDATE categorias SET slug=:slug,nome=:nome,descricao=:descricao,tema=:tema,destaque_texto=:destaque,hero_texto=:hero,ativo=:ativo WHERE id=:id');
            $stmt->execute([':slug'=>$slug,':nome'=>$nome,':descricao'=>$descricao?:null,':tema'=>$tema,':destaque'=>$destaque?:null,':hero'=>$hero?:null,':ativo'=>$ativo,':id'=>$id]);
            return $id;
        }
        $stmt=$this->pdo->prepare('INSERT INTO categorias (slug,nome,descricao,tema,destaque_texto,hero_texto,ativo) VALUES (:slug,:nome,:descricao,:tema,:destaque,:hero,:ativo)');
        $stmt->execute([':slug'=>$slug,':nome'=>$nome,':descricao'=>$descricao?:null,':tema'=>$tema,':destaque'=>$destaque?:null,':hero'=>$hero?:null,':ativo'=>$ativo]);
        return (int)$this->pdo->lastInsertId();
    }

    public function excluir(int $id): void
    {
        if($id<1) throw new InvalidArgumentException('Categoria inválida.');
        $categoria=$this->buscar($id);
        if(!$categoria) throw new RuntimeException('Categoria não encontrada. Ela pode já ter sido excluída; atualize a lista.');
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM produtos WHERE categoria_id=:id');
        $stmt->execute([':id'=>$id]);
        $total=(int)$stmt->fetchColumn();
        if($total>0){
            $produtos=$total===1?'1 produto vinculado':"{$total} produtos vinculados";
            throw new DomainException(
                "Não é possível excluir a categoria \"{$categoria['nome']}\": há {$produtos} a ela. "
                .'Mova esses produtos para outra categoria (ou exclua-os) antes de excluir a categoria. '
                .'Se só quiser escondê-la da loja, desmarque "Ativa para o cliente".'
            );
        }
        $stmt=$this->pdo->prepare('DELETE FROM categorias WHERE id=:id');
        $stmt->execute([':id'=>$id]);
    }
}
