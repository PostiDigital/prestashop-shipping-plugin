<?php

namespace Posti\PrestaShop\Postishipping\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Posti\PrestaShop\Postishipping\Entity\PostishippingCarrier;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * @extends ServiceEntityRepository<PostishippingCarrier>
 *
 * @method PostishippingCarrier|null find($id, $lockMode = null, $lockVersion = null)
 * @method PostishippingCarrier|null findOneBy(array $criteria, array $orderBy = null)
 * @method PostishippingCarrier[] findAll()
 * @method PostishippingCarrier[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PostishippingCarrierRepository extends ServiceEntityRepository
{
    /**
     * @var ManagerRegistry the Doctrine Registry
     */
    private $registry;

    /**
     * @var Connection the Database connection
     */
    private $connection;

    /**
     * @var string the Database prefix
     */
    private $databasePrefix;

    /**
     * @param ManagerRegistry $registry
     * @param Connection $connection
     * @param string $databasePrefix
     */
    public function __construct($registry, $connection, $databasePrefix)
    {
        parent::__construct($registry, PostishippingCarrier::class);
        $this->connection = $connection;
        $this->databasePrefix = $databasePrefix;
    }

    public function add(PostishippingCarrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PostishippingCarrier $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /* Removes and  flushes entity */
    public function delete(PostishippingCarrier $carrier)
    {
        $this->remove($carrier, true);
    }

    /* Update */
    public function update(PostishippingCarrier $carrier)
    {
        $this->getEntityManager()->persist($carrier);
        $this->getEntityManager()->flush();

        return 1;
    }


    public function updateGeneral(PostishippingCarrier $carrier): void
    {
        $this->getEntityManager()->persist($carrier);
        $this->getEntityManager()->flush();
    }

    /**
     * @return array
     */
    public function getCarriers(int $id_lang, $type = false, $active = false)
    {
        $sql = '
            SELECT pc.`id_postishipping_carrier`, pc.`id_posti_service`, c.`name`, c.id_carrier as id_ps_carrier, pc.active
            FROM `' . $this->databasePrefix . 'postishipping_carrier` pc
            LEFT JOIN `' . $this->databasePrefix . 'carrier` c ON (c.id_reference = pc.id_carrier_reference AND c.deleted = 0)
            WHERE 1
            ORDER BY pc.`id_postishipping_carrier` ASC';

        $statement = $this->connection->executeQuery($sql);

        if (method_exists($statement, 'fetchAllAssociative')) {
            return $statement->fetchAllAssociative();
        }

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function isPostishippingCarrier($carrierId)
    {
        $sql = 'SELECT id_carrier FROM `' . $this->databasePrefix . 'carrier` c
            WHERE EXISTS (
	            SELECT pc.`id_postishipping_carrier` FROM `' . $this->databasePrefix . 'postishipping_carrier` pc 
                WHERE pc.id_carrier_reference = c.id_reference AND c.id_carrier = ' . (int) $carrierId . '
            )
        ';

        $statement = $this->connection->executeQuery($sql);

        if (method_exists($statement, 'fetchFirstColumn')) {
            return (bool) ($statement->fetchFirstColumn() ?? false);
        }

        return (bool) ($statement->fetchColumn() ?? false);
    }
}
