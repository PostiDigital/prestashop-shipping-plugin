<?php

declare(strict_types=1);

namespace Posti\PrestaShop\Postishipping\Entity;

use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use ReturnTypeWillChange;

/**
 * @ORM\Table()
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks()
 */
class PostishippingManifest implements JsonSerializable
{
    const STATUS_OPEN = 'open';
    const STATUS_CLOSED = 'closed';
    const STATUS_CANCELED = 'canceled';

    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(name="id_postishipping_manifest", type="integer")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=32)
     */
    private $status = self::STATUS_OPEN;

    /**
     * @var string
     *
     * @ORM\Column(name="labels", type="text")
     */
    private $labels = '[]';

    /**
     * @var string|null
     *
     * @ORM\Column(name="pickup_time", type="string", length=32, nullable=true)
     */
    private $pickupTime;

    /**
     * @var string|null
     *
     * @ORM\Column(name="canceled_by", type="string", length=255, nullable=true)
     */
    private $canceledBy;

    /**
     * @var \DateTimeInterface
     *
     * @ORM\Column(name="date_add", type="datetime")
     */
    private $dateAdd;

    /**
     * @var \DateTimeInterface
     *
     * @ORM\Column(name="date_upd", type="datetime")
     */
    private $dateUpd;

    public function __construct()
    {
        $this->dateAdd = new \DateTime();
        $this->dateUpd = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->dateUpd = new \DateTime();
        return $this;
    }

    public function getLabels(): array
    {
        $decoded = json_decode($this->labels, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setLabels(array $labels): self
    {
        $this->labels = json_encode($labels);
        $this->dateUpd = new \DateTime();
        return $this;
    }

    public function addLabel(int $orderId, string $trackingCode): self
    {
        $labels = $this->getLabels();

        // Check if this tracking code already exists
        foreach ($labels as $label) {
            if ($label['trackingCode'] === $trackingCode) {
                return $this;
            }
        }

        $labels[] = [
            'orderId' => $orderId,
            'trackingCode' => $trackingCode,
            'addedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];

        $this->labels = json_encode($labels);
        $this->dateUpd = new \DateTime();

        return $this;
    }

    public function getPickupTime(): ?string
    {
        return $this->pickupTime;
    }

    public function setPickupTime(?string $pickupTime): self
    {
        $this->pickupTime = $pickupTime;
        $this->dateUpd = new \DateTime();
        return $this;
    }

    public function getCanceledBy(): ?string
    {
        return $this->canceledBy;
    }

    public function setCanceledBy(?string $canceledBy): self
    {
        $this->canceledBy = $canceledBy;
        $this->dateUpd = new \DateTime();
        return $this;
    }

    public function getDateAdd(): \DateTimeInterface
    {
        return $this->dateAdd;
    }

    public function getDateUpd(): \DateTimeInterface
    {
        return $this->dateUpd;
    }

    /**
     * Returns labels grouped by order ID
     */
    public function getLabelsGroupedByOrder(): array
    {
        $grouped = [];
        foreach ($this->getLabels() as $label) {
            $orderId = $label['orderId'];
            if (!isset($grouped[$orderId])) {
                $grouped[$orderId] = [];
            }
            $grouped[$orderId][] = $label['trackingCode'];
        }
        return $grouped;
    }

    /**
     * Returns all unique tracking codes
     */
    public function getTrackingCodes(): array
    {
        return array_column($this->getLabels(), 'trackingCode');
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'labels' => $this->getLabels(),
            'pickupTime' => $this->pickupTime,
            'canceledBy' => $this->canceledBy,
            'dateAdd' => $this->dateAdd->format('Y-m-d H:i:s'),
            'dateUpd' => $this->dateUpd->format('Y-m-d H:i:s'),
        ];
    }
}
