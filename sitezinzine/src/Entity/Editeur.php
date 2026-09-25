<?php

namespace App\Entity;

use App\Repository\EditeurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EditeurRepository::class)]
class Editeur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column]
    private ?\DateTime $updateAt = null;

    /**
     * @var Collection<int, Emission>
     */
    #[ORM\OneToMany(targetEntity: Emission::class, mappedBy: 'editeur')]
    private Collection $emissions;

    public function __construct()
    {
        $this->emissions = new ArrayCollection();
    }

    public function __toString(): string
{
    return $this->getName() ?? '';
}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getMail(): ?string
    {
        return $this->mail;
    }

    public function setMail(?string $mail): static
    {
        $this->mail = $mail;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getUpdateAt(): ?\DateTime
    {
        return $this->updateAt;
    }

    public function setUpdateAt(\DateTime $updateAt): static
    {
        $this->updateAt = $updateAt;

        return $this;
    }

    /**
     * @return Collection<int, Emission>
     */
    public function getEmissions(): Collection
    {
        return $this->emissions;
    }

    public function addEmission(Emission $emission): static
    {
        if (!$this->emissions->contains($emission)) {
            $this->emissions->add($emission);
            $emission->setEditeur($this);
        }

        return $this;
    }

    public function removeEmission(Emission $emission): static
    {
        if ($this->emissions->removeElement($emission)) {
            // set the owning side to null (unless already changed)
            if ($emission->getEditeur() === $this) {
                $emission->setEditeur(null);
            }
        }

        return $this;
    }
}
