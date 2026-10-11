<?php

declare(strict_types=1);

namespace WinterDoctrineTest\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
class Invoice {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(name: 'tenant_id')]
    public int $tenantId = 0;

    #[ORM\Column]
    public string $customer = '';
}
