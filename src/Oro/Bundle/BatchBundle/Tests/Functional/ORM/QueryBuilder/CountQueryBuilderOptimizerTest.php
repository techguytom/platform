<?php

namespace Oro\Bundle\BatchBundle\Tests\Functional\ORM\QueryBuilder;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManager;

use Oro\Bundle\BatchBundle\ORM\QueryBuilder\CountQueryBuilderOptimizer;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

class CountQueryBuilderOptimizerTest extends WebTestCase
{
    /**
     * @dataProvider getCountQueryBuilderDataProvider
     * @param QueryBuilder $queryBuilder
     * @param string $expectedDql
     */
    public function testGetCountQueryBuilder(QueryBuilder $queryBuilder, $expectedDql)
    {
        $optimizer = new CountQueryBuilderOptimizer();
        $countQb = $optimizer->getCountQueryBuilder($queryBuilder);

        $this->assertInstanceOf('Doctrine\ORM\QueryBuilder', $countQb);
        // Check for expected DQL
        $this->assertEquals($expectedDql, $countQb->getQuery()->getDQL());
        // Check that Optimized DQL can be converted to SQL
        $this->assertNotEmpty($countQb->getQuery()->getSQL());
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @return array
     */
    public function getCountQueryBuilderDataProvider()
    {
        self::initClient();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        return [
            'simple' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username']),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u'
            ],
            'group_test' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username as uName'])
                    ->groupBy('uName'),
                'expectedDQL' => 'SELECT u.username as _groupByPart0 FROM OroUserBundle:User u GROUP BY _groupByPart0'
            ],
            'function_having_test' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'SUBSTRING(u.username, 1, 3) as uName'])
                    ->groupBy('u.id')
                    ->having("SUBSTRING(u.username, 1, 3) LIKE 'A%'"),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 ' .
                    'FROM OroUserBundle:User u ' .
                    'GROUP BY _groupByPart0 ' .
                    "HAVING SUBSTRING(u.username, 1, 3) LIKE 'A%'"
            ],
            'function_group_test' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'SUBSTRING(u.username, 1, 3) as uName'])
                    ->groupBy('uName'),
                'expectedDQL' => 'SELECT SUBSTRING(u.username, 1, 3) as _groupByPart0 ' .
                    'FROM OroUserBundle:User u ' .
                    'GROUP BY _groupByPart0'
            ],
            'complex_group_by' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'SUBSTRING(u.username, 1, 3) as uName'])
                    ->groupBy('u.id, uName'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0, SUBSTRING(u.username, 1, 3) as _groupByPart1 ' .
                    'FROM OroUserBundle:User u ' .
                    'GROUP BY _groupByPart0, _groupByPart1'
            ],
            'one_table' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username'])
                    ->where('u.id=10')
                    ->andWhere('LOWER(u.username) LIKE :testParameter')
                    ->groupBy('u.id')
                    ->having('u.username = :testParameter'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'WHERE u.id=10 AND LOWER(u.username) LIKE :testParameter '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username = :testParameter'
            ],
            'unused_left_join' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->leftJoin('OroUserBundle:UserApi', 'api')
                    ->select(['u.id', 'u.username', 'api.apiKey']),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u',
            ],
            'unused_left_join_without_conditions' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->leftJoin('u.owner', 'o')
                    ->select('u.id, o.name'),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u',
            ],
            'unused_left_join_with_condition' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->leftJoin('u.owner', 'o', Join::WITH, 'o.id = 123')
                    ->select('u.id, o.name'),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u',
            ],
            'unused_left_join_with_condition_in_several_joins' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->leftJoin('u.owner', 'o', Join::WITH, 'o.id = 123')
                    ->leftJoin('o.businessUnits', 'bu', Join::WITH, 'bu.id = 456')
                    ->select('u.id, o.name'),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u',
            ],
            'used_left_join' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->leftJoin('OroUserBundle:UserApi', 'api')
                    ->select(['u.id', 'u.username', 'api.apiKey as aKey'])
                    ->where('aKey = :test')
                    ->setParameter('test', 'test_api_key'),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'LEFT JOIN OroUserBundle:UserApi api '
                    . 'WHERE api.apiKey = :test',
            ],
            'with_inner_join' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->innerJoin('u.businessUnits', 'bu')
                    ->leftJoin('bu.organization', 'o')
                    ->select(['u.id', 'u.username', 'api.apiKey as aKey']),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'INNER JOIN u.businessUnits bu'
            ],
            'with_inner_join_with_condition' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->innerJoin('OroOrganizationBundle:BusinessUnit', 'bu', Join::WITH, 'u.owner = bu.id')
                    ->leftJoin('OroUserBundle:UserApi', 'api')
                    ->select(['u.id', 'u.username', 'api.apiKey as aKey']),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'INNER JOIN OroOrganizationBundle:BusinessUnit bu WITH u.owner = bu.id'
            ],
            'with_inner_join_depends_on_left_join' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->innerJoin('OroOrganizationBundle:BusinessUnit', 'bu', Join::WITH, 'owner.id = bu.id')
                    ->leftJoin('u.owner', 'owner')
                    ->select(['u.id']),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'INNER JOIN OroOrganizationBundle:BusinessUnit bu WITH owner.id = bu.id '
                    . 'LEFT JOIN u.owner owner'
            ],
            'inner_with_2_left_group' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->innerJoin('u.owner', 'bu')
                    ->leftJoin('u.groups', 'g')
                    ->leftJoin('u.roles', 'r')
                    ->leftJoin('g.roles', 'gr')
                    ->select(['u.id', 'u.username', 'api.apiKey as aKey'])
                    ->groupBy('gr.id')
                    ->having('u.username LIKE :test'),
                'expectedDQL' => 'SELECT gr.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'INNER JOIN u.owner bu '
                    . 'LEFT JOIN u.groups g '
                    . 'LEFT JOIN g.roles gr '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username LIKE :test'
            ],
            'inner_with_2_left_having' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->innerJoin('u.owner', 'bu')
                    ->leftJoin('u.groups', 'g')
                    ->leftJoin('u.roles', 'r')
                    ->leftJoin('g.roles', 'gr')
                    ->select(['u.id', 'u.username', 'api.apiKey as aKey'])
                    ->groupBy('u.id')
                    ->having('gr.label LIKE :test'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'INNER JOIN u.owner bu '
                    . 'LEFT JOIN u.groups g '
                    . 'LEFT JOIN g.roles gr '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING gr.label LIKE :test'
            ],
            'third_join_in_on' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->innerJoin('u.owner', 'bu')
                    ->leftJoin('u.groups', 'g')
                    ->leftJoin('u.roles', 'r')
                    ->leftJoin('g.roles', 'gr', Join::WITH, 'aKey = :test')
                    ->leftJoin('u.apiKeys', 'api')
                    ->select(['u.id', 'u.username', 'api.apiKey as aKey'])
                    ->where('gr.id > 10'),
                'expectedDQL' => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'INNER JOIN u.owner bu '
                    . 'LEFT JOIN u.groups g '
                    . 'LEFT JOIN g.roles gr WITH api.apiKey = :test '
                    . 'LEFT JOIN u.apiKeys api '
                    . 'WHERE gr.id > 10'
            ],
            'having_equal' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username as login', 'api.apiKey as aKey'])
                    ->groupBy('u.id')
                    ->having('login = :test'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username = :test'
            ],
            'having_in' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username as login', 'api.apiKey as aKey'])
                    ->groupBy('u.id')
                    ->having('login IN (?0)'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username IN (?0)'
            ],
            'having_like' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username as login', 'api.apiKey as aKey'])
                    ->groupBy('u.id')
                    ->having('login LIKE :test'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username LIKE :test'
            ],
            'having_is_null' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username as login', 'api.apiKey as aKey'])
                    ->groupBy('u.id')
                    ->having('login IS NULL'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username IS NULL'
            ],
            'having_is_not_null' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                    ->select(['u.id', 'u.username as login', 'api.apiKey as aKey'])
                    ->groupBy('u.id')
                    ->having('login IS NOT NULL'),
                'expectedDQL' => 'SELECT u.id as _groupByPart0 FROM OroUserBundle:User u '
                    . 'GROUP BY _groupByPart0 '
                    . 'HAVING u.username IS NOT NULL'
            ],
            'having_instead_where' => [
                'queryBuilder' => self::createQueryBuilder($em)
                    ->from('OroUserBundle:User', 'u')
                        ->select(['u.id', 'u.username as login', 'api.apiKey as aKey'])
                        ->having('login LIKE :test'),
                'expectedDQL'  => 'SELECT u.id FROM OroUserBundle:User u WHERE u.username LIKE :test'
            ],
            'join_on_table_that_has_with_join_condition' => [
                'queryBuilder' => self::createQueryBuilder($em)
                        ->from('OroUserBundle:User', 'u')
                        ->select(['u.id'])
                        ->leftJoin('OroUserBundle:Email', 'e', Join::WITH, 'e.user = u')
                        ->leftJoin('e.user', 'eu')
                        ->leftJoin('eu.owner', 'euo')
                        ->where('euo.name = :name'),
                'expectedDQL'  => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'LEFT JOIN OroUserBundle:Email e WITH e.user = u '
                    . 'LEFT JOIN e.user eu '
                    . 'LEFT JOIN eu.owner euo WHERE euo.name = :name'
            ],
            'join_on_table_that_has_with_join_and_join_on_alias_condition' => [
                'queryBuilder' => self::createQueryBuilder($em)
                        ->from('OroUserBundle:User', 'u')
                        ->select(['u.id'])
                        ->leftJoin('OroUserBundle:Email', 'e', Join::WITH, 'e.user = u')
                        ->leftJoin('e.user', 'eu')
                        ->leftJoin('OroUserBundle:Status', 's', Join::WITH, 's.user = eu')
                        ->where('s.status = :statusName'),
                'expectedDQL'  => 'SELECT u.id FROM OroUserBundle:User u '
                    . 'LEFT JOIN OroUserBundle:Email e WITH e.user = u '
                    . 'LEFT JOIN OroUserBundle:Status s WITH s.user = e.user '
                    . 'WHERE s.status = :statusName'
            ],
            'join_on_table_that_has_with_join_and_join_on_alias_condition_and_group_by' => [
                'queryBuilder' => self::createQueryBuilder($em)
                        ->from('OroUserBundle:User', 'u')
                        ->select(['u.id'])
                        ->leftJoin('OroUserBundle:Email', 'e', Join::WITH, 'e.user = u')
                        ->leftJoin('e.user', 'eu')
                        ->leftJoin('OroUserBundle:Status', 's', Join::WITH, 's.user = eu')
                        ->groupBy('eu.username')
                        ->where('s.status = :statusName'),
                'expectedDQL'  => 'SELECT eu.username as _groupByPart0 FROM OroUserBundle:User u '
                    . 'LEFT JOIN OroUserBundle:Email e WITH e.user = u '
                    . 'LEFT JOIN e.user eu '
                    . 'LEFT JOIN OroUserBundle:Status s WITH s.user = e.user '
                    . 'WHERE s.status = :statusName '
                    . 'GROUP BY _groupByPart0'
            ]
        ];
    }

    /**
     * @param EntityManager $entityManager
     * @return QueryBuilder
     */
    public static function createQueryBuilder(EntityManager $entityManager)
    {
        return new QueryBuilder($entityManager);
    }
}
