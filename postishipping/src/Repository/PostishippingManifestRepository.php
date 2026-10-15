<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Posti\PrestaShop\Postishipping\Entity\PostishippingManifest;

/**
 * @extends ServiceEntityRepository<PostishippingManifest>
 *
 * @method PostishippingManifest|null find($id, $lockMode = null, $lockVersion = null)
 * @method PostishippingManifest|null findOneBy(array $criteria, array $orderBy = null)
 * @method PostishippingManifest[] findAll()
 * @method PostishippingManifest[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PostishippingManifestRepository extends ServiceEntityRepository
{
    /**
     * @var Connection the Database connection
     */
    private $connection;

    /**
     * @var string the Database prefix
     */
    private $databasePrefix;

    public function __construct($registry, $connection, $databasePrefix)
    {
        parent::__construct($registry, PostishippingManifest::class);
        $this->connection = $connection;
        $this->databasePrefix = $databasePrefix;
    }

    public function save(PostishippingManifest $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function delete(PostishippingManifest $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * Find the current open manifest, or create a new one
     */
    public function getOrCreateOpenManifest(): PostishippingManifest
    {
        $manifest = $this->findOneBy(['status' => PostishippingManifest::STATUS_OPEN]);

        if (!$manifest) {
            $manifest = new PostishippingManifest();
            $this->save($manifest);
        }

        return $manifest;
    }

    /**
     * Get all manifests ordered by date descending
     */
    public function getAllManifests(): array
    {
        return $this->findBy([], ['dateAdd' => 'DESC']);
    }

    /**
     * Count all manifests
     */
    public function countAllManifests(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get paginated manifests ordered by date descending
     */
    public function getPaginatedManifests(int $limit, int $offset): array
    {
        return $this->findBy([], ['dateAdd' => 'DESC'], $limit, $offset);
    }

    /**
     * Get manifests filtered by given criteria
     */
    public function getFilteredManifests(array $filters): array
    {
        $qb = $this->createQueryBuilder('m');

        if (!empty($filters['id'])) {
            $qb->andWhere('m.id = :id')
                ->setParameter('id', (int) $filters['id']);
        }

        if (!empty($filters['title_from'])) {
            $qb->andWhere('m.dateAdd >= :titleFrom')
                ->setParameter('titleFrom', $filters['title_from'] . ' 00:00:00');
        }

        if (!empty($filters['title_to'])) {
            $qb->andWhere('m.dateAdd <= :titleTo')
                ->setParameter('titleTo', $filters['title_to'] . ' 23:59:59');
        }

        if (!empty($filters['order_or_tracking'])) {
            $searchTerm = addcslashes($filters['order_or_tracking'], '%_');
            $qb->andWhere('m.labels LIKE :orderOrTracking')
                ->setParameter('orderOrTracking', '%' . $searchTerm . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('m.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['pickup_from'])) {
            $qb->andWhere('m.pickupTime >= :pickupFrom')
                ->setParameter('pickupFrom', $filters['pickup_from']);
        }

        if (!empty($filters['pickup_to'])) {
            $qb->andWhere('m.pickupTime <= :pickupTo')
                ->setParameter('pickupTo', $filters['pickup_to'] . ' 23:59:59');
        }

        $qb->orderBy('m.dateAdd', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Count manifests filtered by given criteria
     */
    public function countFilteredManifests(array $filters): int
    {
        $qb = $this->createQueryBuilder('m');
        $qb->select('COUNT(m.id)');

        if (!empty($filters['id'])) {
            $qb->andWhere('m.id = :id')
                ->setParameter('id', (int) $filters['id']);
        }

        if (!empty($filters['title_from'])) {
            $qb->andWhere('m.dateAdd >= :titleFrom')
                ->setParameter('titleFrom', $filters['title_from'] . ' 00:00:00');
        }

        if (!empty($filters['title_to'])) {
            $qb->andWhere('m.dateAdd <= :titleTo')
                ->setParameter('titleTo', $filters['title_to'] . ' 23:59:59');
        }

        if (!empty($filters['order_or_tracking'])) {
            $searchTerm = addcslashes($filters['order_or_tracking'], '%_');
            $qb->andWhere('m.labels LIKE :orderOrTracking')
                ->setParameter('orderOrTracking', '%' . $searchTerm . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('m.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['pickup_from'])) {
            $qb->andWhere('m.pickupTime >= :pickupFrom')
                ->setParameter('pickupFrom', $filters['pickup_from']);
        }

        if (!empty($filters['pickup_to'])) {
            $qb->andWhere('m.pickupTime <= :pickupTo')
                ->setParameter('pickupTo', $filters['pickup_to'] . ' 23:59:59');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get paginated manifests filtered by given criteria
     */
    public function getFilteredManifestsPaginated(array $filters, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('m');

        if (!empty($filters['id'])) {
            $qb->andWhere('m.id = :id')
                ->setParameter('id', (int) $filters['id']);
        }

        if (!empty($filters['title_from'])) {
            $qb->andWhere('m.dateAdd >= :titleFrom')
                ->setParameter('titleFrom', $filters['title_from'] . ' 00:00:00');
        }

        if (!empty($filters['title_to'])) {
            $qb->andWhere('m.dateAdd <= :titleTo')
                ->setParameter('titleTo', $filters['title_to'] . ' 23:59:59');
        }

        if (!empty($filters['order_or_tracking'])) {
            $searchTerm = addcslashes($filters['order_or_tracking'], '%_');
            $qb->andWhere('m.labels LIKE :orderOrTracking')
                ->setParameter('orderOrTracking', '%' . $searchTerm . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('m.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['pickup_from'])) {
            $qb->andWhere('m.pickupTime >= :pickupFrom')
                ->setParameter('pickupFrom', $filters['pickup_from']);
        }

        if (!empty($filters['pickup_to'])) {
            $qb->andWhere('m.pickupTime <= :pickupTo')
                ->setParameter('pickupTo', $filters['pickup_to'] . ' 23:59:59');
        }

        $qb->orderBy('m.dateAdd', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get all tracking codes that are in any manifest
     *
     * @param string[] $trackingCodes tracking codes to check
     * @return string[] tracking codes that exist in at least one manifest
     */
    public function filterPickupTrackingCodes(array $trackingCodes): array
    {
        if (empty($trackingCodes)) {
            return [];
        }

        $table = $this->databasePrefix . 'postishipping_manifest';
        $conditions = [];
        $params = [];

        foreach ($trackingCodes as $i => $code) {
            $paramName = 'tc' . $i;
            $conditions[] = 'labels LIKE :' . $paramName;
            $params[$paramName] = '%' . addcslashes($code, '%_') . '%';
        }

        $sql = 'SELECT labels FROM ' . $table
            . ' WHERE status IN (:status_open, :status_closed) AND (' . implode(' OR ', $conditions) . ')';
        $params['status_open'] = PostishippingManifest::STATUS_OPEN;
        $params['status_closed'] = PostishippingManifest::STATUS_CLOSED;
        $stmt = $this->connection->executeQuery($sql, $params);
        $rows = method_exists($stmt, 'fetchAllAssociative')
            ? $stmt->fetchAllAssociative()
            : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $found = [];
        foreach ($rows as $row) {
            $labels = json_decode($row['labels'], true);
            if (!is_array($labels)) {
                continue;
            }
            foreach ($labels as $label) {
                if (in_array($label['trackingCode'], $trackingCodes, true)) {
                    $found[$label['trackingCode']] = true;
                }
            }
        }

        return array_keys($found);
    }
}
