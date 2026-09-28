<?php

namespace App\Entity;

use App\Repository\HistoriqueMdpRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HistoriqueMdpRepository::class)]
#[ORM\Table(name: 'historique_mdp')]
class HistoriqueMdp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateRemplacement = null;

    // Hachage copié depuis User avant son remplacement.
    #[ORM\Column(length: 255)]
    private ?string $ancienMdpHache = null;

    public function __construct()
    {
        $this->dateRemplacement = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getDateRemplacement(): ?\DateTimeImmutable
    {
        return $this->dateRemplacement;
    }

    public function setDateRemplacement(\DateTimeImmutable $dateRemplacement): static
    {
        $this->dateRemplacement = $dateRemplacement;

        return $this;
    }

    public function getAncienMdpHache(): ?string
    {
        return $this->ancienMdpHache;
    }

    public function setAncienMdpHache(string $ancienMdpHache): static
    {
        $this->ancienMdpHache = $ancienMdpHache;

        return $this;
    }
}
