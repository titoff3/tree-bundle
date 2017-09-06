<?php

namespace Umanit\Bundle\TreeBundle\Repository;

use Umanit\Bundle\TreeBundle\Entity\Menu;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Umanit\Bundle\TreeBundle\Entity\Link;

/**
 * MenuRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 * @todo AGU : Translate comments.
 */
class MenuRepository extends \Doctrine\ORM\EntityRepository
{
    /**
     * Récupération du menu à plat
     *
     * @return array
     */
    public function getMenu()
    {
        // Build select part
        $menuSelect       = $this->buildSelectPart('menu');
        $secondMenuSelect = $this->buildSelectPart('c');

        $sql = <<<SQL
with recursive menu_tree as (
    select $menuSelect
    , link_id
    , 1 as level
    , array[priority]::integer[] as path_priority
   from menu
   where parent_id is null
   union all
   select $secondMenuSelect
    , c.link_id
    , p.level + 1
    , p.path_priority||c.priority
   from menu c
     join menu_tree p on c.parent_id = p.id
)
SELECT %SELECT%, mt.level
FROM menu_tree mt
LEFT OUTER JOIN treebundle_link l ON mt.link_id = l.id
order by path_priority
SQL;

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata($this->getClassMetadata()->name, 'mt');
        $rsm->addJoinedEntityFromClassMetadata(Link::class, 'l', 'mt', 'link', array('id' => 'address_id'));


        $sql = strtr($sql, ['%SELECT%' => $rsm->generateSelectClause()]);

        $query = $this->_em->createNativeQuery($sql, $rsm);

        return $query->getResult(Query::HYDRATE_ARRAY);
    }

    /**
     * Récupération du menu à plat indenté (pour les Select en BO)
     *
     * @return Menu[]
     */
    public function getIndentMenu()
    {
        $cols = $this->getClassMetadata()->columnNames;
        unset($cols['title']);
        // Build select part
        $menuSelect       = $this->buildSelectPart('menu', $cols);
        $secondMenuSelect = $this->buildSelectPart('c', $cols);

        $sql = <<<SQL
with recursive menu_tree as (
    select $menuSelect
    , link_id
    , menu.title::text
    , 1 as level
    , array[priority]::integer[] as path_priority
   from menu
   where parent_id is null
   union all
   select $secondMenuSelect
    , c.link_id
    , rpad('', p.level * 4, '\xC2\xA0')::text||c.title::text
    , p.level + 1
    , p.path_priority||c.priority
   from menu c
     join menu_tree p on c.parent_id = p.id
)
SELECT %SELECT%, mt.level
FROM menu_tree mt
LEFT OUTER JOIN treebundle_link l ON mt.link_id = l.id
order by path_priority
SQL;

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata($this->getClassMetadata()->name, 'mt');
        $rsm->addJoinedEntityFromClassMetadata(Link::class, 'l', 'mt', 'link', array('id' => 'address_id'));

        $sql = strtr($sql, ['%SELECT%' => $rsm->generateSelectClause()]);

        $query = $this->_em->createNativeQuery($sql, $rsm);

        return $query->getResult();
    }

    /**
     * Récupération d'une partie du menu (pour la mise à jour
     *
     * @param int $parentId Identitfiant du parent
     *
     * @return array
     */
    public function subIdMenu($parentId)
    {
        $selectSQL = ' = :parent_id';
        if ($parentId == null) {
            $selectSQL = ' IS NULL';
        }
        $sql = <<<SQL
SELECT id 
FROM menu
WHERE parent_id $selectSQL
ORDER BY priority
SQL;

        $query = $this->_em->getConnection()->prepare($sql);

        if ($parentId != null) {
            $query->bindValue('parent_id', $parentId);
        }
        $query->execute();

        return $query->fetchAll();
    }

    /**
     * Déplace un menu dans un nouvel emplacement
     *
     * @param int   $parentId Identifant du noeud parent
     * @param int   $currentNodeId Identifant du noeud à déplacer
     * @param int[] $newMenu Ordenancement du menu
     *
     * @return int Nombre d'object modifié
     */
    public function moveMenu($parentId, $currentNodeId, $newMenu)
    {
        $paramSQL = [];

        if ($parentId == null) {
            $parentIdOperatorSQL = ' IS';
            $parentIdSQL = ' NULL';
            $paramSQL[] = $currentNodeId;
        } else {
            $parentIdOperatorSQL = ' = ';
            $parentIdSQL = ' ?::int ';
            $paramSQL[] = $parentId;
            $paramSQL[] = $currentNodeId;
            $paramSQL[] = $parentId;
        }

        $case = '';
        $lastIndex = 0;
        foreach ($newMenu as $index => $menuId) {
            $case .= ' WHEN ?::int THEN ?::int';
            $paramSQL[] = $menuId;
            $paramSQL[] = $index;
            $lastIndex = $index;
        }
        $case .= ' ELSE ?::int ';
        $paramSQL[] = ++$lastIndex;


        $sql = <<<SQL
WITH source AS (
    SELECT * FROM menu
    WHERE parent_id $parentIdOperatorSQL $parentIdSQL
    UNION SELECT * FROM menu where id = ?::int
),
orderable AS (
SELECT id, title, $parentIdSQL::int as parent_id,
CASE id
%CASE%
END
as priority FROM source
ORDER BY priority)
update menu set parent_id=orderable.parent_id, priority=orderable.priority
FROM orderable
WHERE menu.id = orderable.id
SQL;

        $sql = strtr($sql, ['%CASE%' => $case]);

        $count = $this->_em->getConnection()->executeUpdate($sql, $paramSQL);

        return $count;
    }

    /**
     * Récupération du menu à plat pour le front
     *
     * @param String $locale langue voulu
     *
     * @return array
     */
    public function getFrontMenu($locale)
    {
        // Build select part
        $menuSelect       = $this->buildSelectPart('menu');
        $secondMenuSelect = $this->buildSelectPart('c');

        $sql = <<<SQL
with recursive menu_tree as (
    select $menuSelect
    , link_id
    , 1 as level
    , array[priority]::integer[] as path_priority
   from menu
   where parent_id is null and "locale" = :locale
   union all
   select $secondMenuSelect
    , c.link_id
    , p.level + 1
    , p.path_priority||c.priority
   from menu c
     join menu_tree p on c.parent_id = p.id
   where c."locale" = :locale
)
SELECT %SELECT%, mt.level
FROM menu_tree mt
LEFT OUTER JOIN treebundle_link l ON mt.link_id = l.id
order by path_priority
SQL;

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addEntityResult($this->getClassMetadata()->name, 'mt');

        foreach ($this->getClassMetadata()->fieldNames as $col => $field) {
            $rsm->addFieldResult('mt', $col, $field);
        }

        $rsm->addJoinedEntityFromClassMetadata(Link::class, 'l', 'mt', 'link', array('id' => 'address_id'));

        $sql = strtr($sql, ['%SELECT%' => $rsm->generateSelectClause()]);

        $query = $this->_em->createNativeQuery($sql, $rsm);

        $query->setParameter('locale', $locale);

        return $query->getResult();
    }

    /**
     * Builds select part from class metadata
     *
     * @param null  $alias
     * @param array $columnNames
     *
     * @return string
     */
    protected function buildSelectPart($alias = null, array $columnNames = [])
    {
        if (empty($columnNames)) {
            $columnNames = $this->getClassMetadata()->columnNames;
        }
        return implode(', ', array_map(function ($colname) use ($alias) {
            if ($alias) {
                return sprintf('%s.%s', $alias, $colname);
            }
        }, $columnNames));
    }
}
