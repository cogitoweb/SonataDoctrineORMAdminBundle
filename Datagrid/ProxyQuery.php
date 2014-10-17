<?php
/*
 * This file is part of the symfony package.
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 * (c) Jonathan H. Wage <jonwage@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Datagrid;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;

/**
 * This class try to unify the query usage with Doctrine
 */
class ProxyQuery implements ProxyQueryInterface
{
    protected $queryBuilder;

    protected $sortBy;

    protected $sortOrder;

    protected $parameterUniqueId;

    protected $entityJoinAliases;

    /**
     * @param mixed $queryBuilder
     */
    public function __construct($queryBuilder)
    {
        $this->queryBuilder      = $queryBuilder;
        $this->uniqueParameterId = 0;
        $this->entityJoinAliases = array();
    }

    /**
     * {@inheritdoc}
     */
    public function execute(array $params = array(), $hydrationMode = null)
    {
        // always clone the original queryBuilder
        $queryBuilder = clone $this->queryBuilder;

        // todo : check how doctrine behave, potential SQL injection here ...
        if ($this->getSortBy()) {
            $sortBy = $this->getSortBy();
            if (strpos($sortBy, '.') === false) { // add the current alias
                $sortBy = $queryBuilder->getRootAlias() . '.' . $sortBy;
            }
            $queryBuilder->addOrderBy($sortBy, $this->getSortOrder());
        }

        return $this->getFixedQueryBuilder($queryBuilder)->getQuery()->execute($params, $hydrationMode);
    }

    /**
     * This method alters the query to return a clean set of object with a working
     * set of Object
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function getFixedQueryBuilder(QueryBuilder $queryBuilder)
    {
        $queryBuilderId = clone $queryBuilder;

        // step 1 : retrieve the targeted class
        $from  = $queryBuilderId->getDQLPart('from');
        $class = $from[0]->getFrom();

        // step 2 : retrieve the column id
        $idName = current($queryBuilderId->getEntityManager()->getMetadataFactory()->getMetadataFor($class)->getIdentifierFieldNames());

        // step 3 : retrieve the different subjects id
        $select = sprintf('%s.%s', $queryBuilderId->getRootAlias(), $idName);
        
        // il reset della select non deve resettare eventuali hidden aggiunti per sort
        // la condizione è stringa HIDDEN e colonna sortCondition
        $old_select = implode(',', $queryBuilderId->getDQLPart('select'));
        $persist_select = "";
        if(strpos($old_select, ' HIDDEN ') !== false) {
            
            $hidden_pos = strpos($old_select, ' HIDDEN ');
            
            $first_part = substr($old_select, 0, $hidden_pos);
            $first_part = substr($first_part, strrpos($first_part, 'CASE'), $hidden_pos);
            
            $last_part = substr($old_select, $hidden_pos);
            $last_part = substr($last_part, 0, strpos($last_part, 'sortCondition') + 14);
            
            $persist_select = ', '.$first_part.$last_part;
        }

        $queryBuilderId->resetDQLPart('select');
        $queryBuilderId->add('select', 'DISTINCT ' . $select.$persist_select);

        // for SELECT DISTINCT, ORDER BY expressions must appear in select list
        /* Consider
            SELECT DISTINCT x FROM tab ORDER BY y;
        For any particular x-value in the table there might be many different y
        values.  Which one will you use to sort that x-value in the output?
        */
        // todo : check how doctrine behave, potential SQL injection here ...
        if ($this->getSortBy()) {
            $sortBy = $this->getSortBy();
            if (strpos($sortBy, '.') === false) { // add the current alias
                $sortBy = $queryBuilderId->getRootAlias() . '.' . $sortBy;
            }
            $sortBy .= ' AS __order_by';
            $queryBuilderId->addSelect($sortBy);
        }
        
        // 2z ->
        // aggiunta patch per gestire ordinamenti custom
        // fuori dall'admin
        // (aggiunge in automatico in select le condizioni di order by)
        $order_by_parts = $queryBuilderId->getDQLPart('orderBy');
        if($order_by_parts && count($order_by_parts))
        {
            foreach($order_by_parts as $or)
            {
                foreach($or->getParts() as $p) {
                    
                    $string_order = $p;
                
                    // se si tratta di un ordine per id lo salto
                    // ed anche se si tratta di una sortCondition
                    if(strpos($string_order, '.id ') !== false || strpos($string_order, 'sortCondition') !== false) {
                        
                        continue;
                    }
                    
                    $string_order = str_replace(' ASC', '', $string_order);
                    $string_order = str_replace(' DESC', '', $string_order);

                    $queryBuilderId->addSelect($string_order);
                }
            } 
        }
        // fine patch

        $results    = $queryBuilderId->getQuery()->execute(array(), Query::HYDRATE_ARRAY);
        $idx        = array();
        $connection = $queryBuilder->getEntityManager()->getConnection();
        foreach ($results as $id) {
            $idx[] = $connection->quote($id[$idName]);
        }

        // step 4 : alter the query to match the targeted ids
        if (count($idx) > 0) {
            $queryBuilder->andWhere(sprintf('%s IN (%s)', $select, implode(',', $idx)));
            $queryBuilder->setMaxResults(null);
            $queryBuilder->setFirstResult(null);
        }

        return $queryBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function __call($name, $args)
    {
        return call_user_func_array(array($this->queryBuilder, $name), $args);
    }

    /**
     * {@inheritdoc}
     */
    public function __get($name)
    {
        return $this->queryBuilder->$name;
    }

    /**
     * {@inheritdoc}
     */
    public function setSortBy($parentAssociationMappings, $fieldMapping)
    {
        $alias        = $this->entityJoin($parentAssociationMappings);
        $this->sortBy = $alias . '.' . $fieldMapping['fieldName'];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getSortBy()
    {
        return $this->sortBy;
    }

    /**
     * {@inheritdoc}
     */
    public function setSortOrder($sortOrder)
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getSortOrder()
    {
        return $this->sortOrder;
    }

    /**
     * {@inheritdoc}
     */
    public function getSingleScalarResult()
    {
        $query = $this->queryBuilder->getQuery();

        return $query->getSingleScalarResult();
    }

    /**
     * {@inheritdoc}
     */
    public function __clone()
    {
        $this->queryBuilder = clone $this->queryBuilder;
    }

    /**
     * @return mixed
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function setFirstResult($firstResult)
    {
        $this->queryBuilder->setFirstResult($firstResult);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getFirstResult()
    {
        return $this->queryBuilder->getFirstResult();
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxResults($maxResults)
    {
        $this->queryBuilder->setMaxResults($maxResults);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxResults()
    {
        return $this->queryBuilder->getMaxResults();
    }

    /**
     * {@inheritdoc}
     */
    public function getUniqueParameterId()
    {
        return $this->uniqueParameterId++;
    }

    /**
     * {@inheritdoc}
     */
    public function entityJoin(array $associationMappings)
    {
        $alias = $this->queryBuilder->getRootAlias();

        $newAlias = 's';

        foreach ($associationMappings as $associationMapping) {
            $newAlias .= '_' . $associationMapping['fieldName'];
            if (!in_array($newAlias, $this->entityJoinAliases)) {
                $this->entityJoinAliases[] = $newAlias;
                $this->queryBuilder->leftJoin(sprintf('%s.%s', $alias, $associationMapping['fieldName']), $newAlias);
            }

            $alias = $newAlias;
        }

        return $alias;
    }
}
