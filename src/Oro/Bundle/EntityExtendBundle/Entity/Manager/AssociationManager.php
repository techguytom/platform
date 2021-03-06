<?php

namespace Oro\Bundle\EntityExtendBundle\Entity\Manager;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\DBAL\Types\Type;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\EntityBundle\ORM\QueryUtils;
use Oro\Bundle\EntityBundle\ORM\SqlQueryBuilder;
use Oro\Bundle\EntityBundle\Provider\EntityNameResolver;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityExtendBundle\Extend\RelationType;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SoapBundle\Event\GetListBefore;

class AssociationManager
{
    /** @var ConfigManager */
    protected $configManager;

    /** @var EventDispatcherInterface */
    protected $eventDispatcher;

    /** @var DoctrineHelper */
    protected $doctrineHelper;

    /** @var EntityNameResolver */
    protected $entityNameResolver;

    /**
     * @param ConfigManager            $configManager
     * @param EventDispatcherInterface $eventDispatcher
     * @param DoctrineHelper           $doctrineHelper
     * @param EntityNameResolver       $entityNameResolver
     */
    public function __construct(
        ConfigManager $configManager,
        EventDispatcherInterface $eventDispatcher,
        DoctrineHelper $doctrineHelper,
        EntityNameResolver $entityNameResolver
    ) {
        $this->configManager      = $configManager;
        $this->eventDispatcher    = $eventDispatcher;
        $this->doctrineHelper     = $doctrineHelper;
        $this->entityNameResolver = $entityNameResolver;
    }

    /**
     * Returns the list of fields responsible to store associations for the given entity type
     *
     * @param string        $associationOwnerClass The FQCN of the entity that is the owning side of the association
     * @param callable|null $filter                The callback that can be used to filter returned associations.
     *                                             For example you can use it to filter active associations only.
     *                                             Signature:
     *                                             function ($ownerClass, $targetClass, ConfigManager $configManager)
     *                                             The filter should return TRUE if an association between
     *                                             $ownerClass and $targetClass is allowed.
     * @param string        $associationType       The type of the association.
     *                                             For example manyToOne or manyToMany
     *                                             {@see Oro\Bundle\EntityExtendBundle\Extend\RelationType}
     * @param string        $associationKind       The kind of the association.
     *                                             For example 'activity', 'sponsorship' etc
     *                                             Can be NULL for unclassified (default) association
     *
     * @return array [target_entity_class => field_name]
     */
    public function getAssociationTargets(
        $associationOwnerClass,
        $filter,
        $associationType,
        $associationKind = null
    ) {
        $result = [];

        $relations = $this->configManager->getProvider('extend')
            ->getConfig($associationOwnerClass)
            ->get('relation', false, []);
        foreach ($relations as $relation) {
            if ($this->isSupportedRelation($relation, $associationType, $associationKind)) {
                $targetClass = $relation['target_entity'];

                if (null === $filter
                    || call_user_func($filter, $associationOwnerClass, $targetClass, $this->configManager)
                ) {
                    /** @var FieldConfigId $fieldConfigId */
                    $fieldConfigId = $relation['field_id'];

                    $result[$targetClass] = $fieldConfigId->getFieldName();
                }
            }
        }

        return $result;
    }

    /**
     * Returns a function which can be used to filter enabled single owner associations
     *
     * @param string $scope     The name of the entity config scope where the association is declared
     * @param string $attribute The name of the entity config attribute which indicates
     *                          whether the association is enabled or not
     *
     * @return callable
     */
    public function getSingleOwnerFilter($scope, $attribute = 'enabled')
    {
        return function ($ownerClass, $targetClass, ConfigManager $configManager) use ($scope, $attribute) {
            return $configManager->getProvider($scope)
                ->getConfig($targetClass)
                ->is($attribute);
        };
    }

    /**
     * Returns a function which can be used to filter enabled multi owner associations
     *
     * @param string $scope     The name of the entity config scope where the association is declared
     * @param string $attribute The name of the entity config attribute which is used to store
     *                          enabled associations
     *
     * @return callable
     */
    public function getMultiOwnerFilter($scope, $attribute)
    {
        return function ($ownerClass, $targetClass, ConfigManager $configManager) use ($scope, $attribute) {
            $ownerClassNames = $configManager->getProvider($scope)
                ->getConfig($targetClass)
                ->get($attribute, false, []);

            return in_array($ownerClass, $ownerClassNames, true);
        };
    }

    /**
     * Returns a query builder that could be used for fetching the list of entities
     * associated with $associationOwnerClass entities found by $filters and $joins
     *
     * The resulting query would be something like this:
     * <code>
     * SELECT entity.entityId AS id, entity.entityClass AS entity, entity.entityTitle AS title FROM (
     *      SELECT [DISTINCT]
     *          e.id AS id,
     *          target.id AS entityId,
     *          {first_target_entity_class} AS entityClass,
     *          {first_target_title} AS entityTitle
     *      FROM {associationOwnerClass} AS e
     *          INNER JOIN e.{first_target_field_name} AS target
     *          {joins}
     *      WHERE {filters}
     *      UNION ALL
     *      SELECT [DISTINCT]
     *          e.id AS id,
     *          target.id AS entityId,
     *          {second_target_entity_class} AS entityClass,
     *          {second_target_title} AS entityTitle
     *      FROM {associationOwnerClass} AS e
     *          INNER JOIN e.{second_target_field_name} AS target
     *          {joins}
     *      WHERE {filters}
     *      UNION ALL
     *      ... select statements for other targets
     * ) entity
     * ORDER BY {orderBy}
     * LIMIT {limit} OFFSET {(page - 1) * limit}
     * </code>
     *
     * @param string      $associationOwnerClass The FQCN of the entity that is the owning side of the association
     * @param mixed|null  $filters               Criteria is used to filter entities which are association owners
     *                                           e.g. ['age' => 20, ...] or \Doctrine\Common\Collections\Criteria
     * @param array|null  $joins                 Additional associations required to filter owning side entities
     * @param array       $associationTargets    The list of fields responsible to store associations
     *                                           Array format: [target_entity_class => field_name]
     * @param int         $limit                 The maximum number of items per page
     * @param int         $page                  The page number
     * @param string|null $orderBy               The ordering expression for the result
     *
     * @return SqlQueryBuilder
     */
    public function getMultiAssociationsQueryBuilder(
        $associationOwnerClass,
        $filters,
        $joins,
        $associationTargets,
        $limit = null,
        $page = null,
        $orderBy = null
    ) {
        $em       = $this->doctrineHelper->getEntityManager($associationOwnerClass);
        $criteria = $this->doctrineHelper->normalizeCriteria($filters);

        $selectStmt = null;
        $subQueries = [];
        foreach ($associationTargets as $entityClass => $fieldName) {
            // dispatch oro_api.request.get_list.before event
            $event = new GetListBefore($this->cloneCriteria($criteria), $entityClass);
            $this->eventDispatcher->dispatch(GetListBefore::NAME, $event);
            $subCriteria = $event->getCriteria();

            $nameExpr = $this->entityNameResolver->getNameDQL($entityClass, 'target');
            $subQb    = $em->getRepository($associationOwnerClass)->createQueryBuilder('e')
                ->select(
                    sprintf(
                        'e.id AS id, target.%s AS entityId, \'%s\' AS entityClass, '
                        . ($nameExpr ?: '\'\'') . ' AS entityTitle',
                        $this->doctrineHelper->getSingleEntityIdentifierFieldName($entityClass),
                        str_replace('\'', '\'\'', $entityClass)
                    )
                )
                ->innerJoin('e.' . $fieldName, 'target');
            $this->doctrineHelper->applyJoins($subQb, $joins);

            $subQb->addCriteria($subCriteria);

            $subQuery = $subQb->getQuery();

            $subQueries[] = QueryUtils::getExecutableSql($subQuery);

            if (empty($selectStmt)) {
                $mapping    = QueryUtils::parseQuery($subQuery)->getResultSetMapping();
                $selectStmt = sprintf(
                    'entity.%s AS id, entity.%s AS entity, entity.%s AS title',
                    QueryUtils::getColumnNameByAlias($mapping, 'entityId'),
                    QueryUtils::getColumnNameByAlias($mapping, 'entityClass'),
                    QueryUtils::getColumnNameByAlias($mapping, 'entityTitle')
                );
            }
        }

        $rsm = new ResultSetMapping();
        $rsm
            ->addScalarResult('id', 'id', Type::INTEGER)
            ->addScalarResult('entity', 'entity')
            ->addScalarResult('title', 'title');
        $qb = new SqlQueryBuilder($em, $rsm);
        $qb
            ->select($selectStmt)
            ->from('(' . implode(' UNION ALL ', $subQueries) . ')', 'entity');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
            if (null !== $page) {
                $qb->setFirstResult($this->doctrineHelper->getPageOffset($page, $limit));
            }
        }
        if ($orderBy) {
            $qb->orderBy($orderBy);
        }

        return $qb;
    }

    /**
     * Returns a query builder that could be used for fetching the list of owner side entities
     * the specified $associationTargetClass associated with.
     * The $filters and $joins could be used to filter entities
     *
     * The resulting query would be something like this:
     * <code>
     * SELECT entity.entityId AS id, entity.entityClass AS entity, entity.entityTitle AS title FROM (
     *      SELECT [DISTINCT]
     *          target.id AS id,
     *          e.id AS entityId,
     *          {first_owner_entity_class} AS entityClass,
     *          {first_owner_title} AS entityTitle
     *      FROM {first_owner_entity_class} AS e
     *          INNER JOIN e.{target_field_name_for_first_owner} AS target
     *          {joins}
     *      WHERE {filters}
     *      UNION ALL
     *      SELECT [DISTINCT]
     *          target.id AS id,
     *          e.id AS entityId,
     *          {second_owner_entity_class} AS entityClass,
     *          {second_owner_title} AS entityTitle
     *      FROM {second_owner_entity_class} AS e
     *          INNER JOIN e.{target_field_name_for_second_owner} AS target
     *          {joins}
     *      WHERE {filters}
     *      UNION ALL
     *      ... select statements for other owners
     * ) entity
     * ORDER BY {orderBy}
     * LIMIT {limit} OFFSET {(page - 1) * limit}
     * </code>
     *
     * @param string      $associationTargetClass The FQCN of the entity that is the target side of the association
     * @param mixed|null  $filters                Criteria is used to filter entities which are association owners
     *                                            e.g. ['age' => 20, ...] or \Doctrine\Common\Collections\Criteria
     * @param array|null  $joins                  Additional associations required to filter owning side entities
     * @param array       $associationOwners      The list of fields responsible to store associations between
     *                                            the given target and association owners
     *                                            Array format: [owner_entity_class => field_name]
     * @param int         $limit                  The maximum number of items per page
     * @param int         $page                   The page number
     * @param string|null $orderBy                The ordering expression for the result
     *
     * @return SqlQueryBuilder
     */
    public function getMultiAssociationOwnersQueryBuilder(
        $associationTargetClass,
        $filters,
        $joins,
        $associationOwners,
        $limit = null,
        $page = null,
        $orderBy = null
    ) {
        $em       = $this->doctrineHelper->getEntityManager($associationTargetClass);
        $criteria = $this->doctrineHelper->normalizeCriteria($filters);

        $selectStmt        = null;
        $subQueries        = [];
        $targetIdFieldName = $this->doctrineHelper->getSingleEntityIdentifierFieldName($associationTargetClass);
        foreach ($associationOwners as $ownerClass => $fieldName) {
            // dispatch oro_api.request.get_list.before event
            $event = new GetListBefore($this->cloneCriteria($criteria), $associationTargetClass);
            $this->eventDispatcher->dispatch(GetListBefore::NAME, $event);
            $subCriteria = $event->getCriteria();

            $nameExpr = $this->entityNameResolver->getNameDQL($ownerClass, 'e');
            $subQb    = $em->getRepository($ownerClass)->createQueryBuilder('e')
                ->select(
                    sprintf(
                        'target.%s AS id, e.id AS entityId, \'%s\' AS entityClass, '
                        . ($nameExpr ?: '\'\'') . ' AS entityTitle',
                        $targetIdFieldName,
                        str_replace('\'', '\'\'', $ownerClass)
                    )
                )
                ->innerJoin('e.' . $fieldName, 'target');
            $this->doctrineHelper->applyJoins($subQb, $joins);

            $subQb->addCriteria($subCriteria);

            $subQuery = $subQb->getQuery();

            $subQueries[] = QueryUtils::getExecutableSql($subQuery);

            if (empty($selectStmt)) {
                $mapping    = QueryUtils::parseQuery($subQuery)->getResultSetMapping();
                $selectStmt = sprintf(
                    'entity.%s AS id, entity.%s AS entity, entity.%s AS title',
                    QueryUtils::getColumnNameByAlias($mapping, 'entityId'),
                    QueryUtils::getColumnNameByAlias($mapping, 'entityClass'),
                    QueryUtils::getColumnNameByAlias($mapping, 'entityTitle')
                );
            }
        }

        $rsm = new ResultSetMapping();
        $rsm
            ->addScalarResult('id', 'id', Type::INTEGER)
            ->addScalarResult('entity', 'entity')
            ->addScalarResult('title', 'title');
        $qb = new SqlQueryBuilder($em, $rsm);
        $qb
            ->select($selectStmt)
            ->from('(' . implode(' UNION ALL ', $subQueries) . ')', 'entity');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
            if (null !== $page) {
                $qb->setFirstResult($this->doctrineHelper->getPageOffset($page, $limit));
            }
        }
        if ($orderBy) {
            $qb->orderBy($orderBy);
        }

        return $qb;
    }

    /**
     * @param array  $relation
     * @param string $associationType
     * @param string $associationKind
     *
     * @return bool
     */
    protected function isSupportedRelation($relation, $associationType, $associationKind)
    {
        /** @var FieldConfigId|null $fieldConfigId */
        $fieldConfigId = $relation['field_id'];

        return
            $fieldConfigId instanceof FieldConfigId
            && $relation['owner']
            && (
                $fieldConfigId->getFieldType() === $associationType
                || (
                    $associationType === RelationType::MULTIPLE_MANY_TO_ONE
                    && $fieldConfigId->getFieldType() === RelationType::MANY_TO_ONE
                )
            )
            && $fieldConfigId->getFieldName() === ExtendHelper::buildAssociationName(
                $relation['target_entity'],
                $associationKind
            );
    }

    /**
     * Makes a clone of the given Criteria
     *
     * @param Criteria $criteria
     *
     * @return Criteria
     */
    protected function cloneCriteria(Criteria $criteria)
    {
        return new Criteria(
            $criteria->getWhereExpression(),
            $criteria->getOrderings(),
            $criteria->getFirstResult(),
            $criteria->getMaxResults()
        );
    }
}
