<?php

namespace Posti\PrestaShop\Postishipping\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Posti\PrestaShop\Postishipping\Entity\PostishippingCartMeta;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * @extends ServiceEntityRepository<PostishippingCartMeta>
 *
 * @method PostishippingCartMeta|null find($id, $lockMode = null, $lockVersion = null)
 * @method PostishippingCartMeta|null findOneBy(array $criteria, array $orderBy = null)
 * @method PostishippingCartMeta[] findAll()
 * @method PostishippingCartMeta[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PostishippingCartMetaRepository extends ServiceEntityRepository
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
        parent::__construct($registry, PostishippingCartMeta::class);
        $this->connection = $connection;
        $this->databasePrefix = $databasePrefix;
    }

    public function add(PostishippingCartMeta $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PostishippingCartMeta $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /* Removes and  flushes entity */
    public function delete(PostishippingCartMeta $meta)
    {
        $this->remove($meta, true);
    }

    // /* Update */
    public function update(PostishippingCartMeta $meta)
    {
        $this->getEntityManager()->persist($meta);
        $this->getEntityManager()->flush();

        return 1;
    }


    public function updateGeneral(PostishippingCartMeta $meta): void
    {
        $this->getEntityManager()->persist($meta);
        $this->getEntityManager()->flush();
    }

    /**
     * Returns tracking codes that still exist in cart meta registrations.
     *
     * @param array<array{orderId: int, trackingCode: string}> $labels
     * @return array<string> tracking codes that still exist
     */
    public function filterExistingTrackingCodes(array $labels): array
    {
        if (empty($labels)) {
            return [];
        }

        // Group tracking codes by orderId
        $byOrder = [];
        foreach ($labels as $label) {
            $byOrder[(int) $label['orderId']][] = $label['trackingCode'];
        }

        $orderIds = array_keys($byOrder);

        // Get cart IDs for these orders
        $ordersTable = $this->databasePrefix . 'orders';
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = 'SELECT id_order, id_cart FROM ' . $ordersTable . ' WHERE id_order IN (' . $placeholders . ')';
        $stmt = $this->connection->executeQuery($sql, array_values($orderIds));
        $rows = method_exists($stmt, 'fetchAllAssociative')
            ? $stmt->fetchAllAssociative()
            : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $orderToCart = [];
        foreach ($rows as $row) {
            $orderToCart[(int) $row['id_order']] = (int) $row['id_cart'];
        }

        // Fetch cart metas
        $cartIds = array_unique(array_values($orderToCart));
        if (empty($cartIds)) {
            return [];
        }

        $metaTable = $this->databasePrefix . 'postishipping_cart_meta';
        $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
        $sql = 'SELECT id_cart, meta_data FROM ' . $metaTable . ' WHERE id_cart IN (' . $placeholders . ')';
        $stmt = $this->connection->executeQuery($sql, array_values($cartIds));
        $metaRows = method_exists($stmt, 'fetchAllAssociative')
            ? $stmt->fetchAllAssociative()
            : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Build set of existing tracking codes per cart
        $existingByCart = [];
        foreach ($metaRows as $row) {
            $meta = json_decode($row['meta_data'], true);
            $registered = $meta['registered'] ?? [];
            // Handle old single-registration format
            if (isset($registered['trackingCode'])) {
                $registered = [$registered];
            }
            foreach ($registered as $reg) {
                $existingByCart[(int) $row['id_cart']][$reg['trackingCode'] ?? ''] = true;
            }
        }

        // Check which tracking codes from labels still exist
        $existing = [];
        foreach ($byOrder as $orderId => $trackingCodes) {
            $cartId = $orderToCart[$orderId] ?? null;
            if ($cartId === null) {
                continue;
            }
            foreach ($trackingCodes as $tc) {
                if (isset($existingByCart[$cartId][$tc])) {
                    $existing[] = $tc;
                }
            }
        }

        return $existing;
    }
}
