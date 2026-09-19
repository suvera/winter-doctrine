<?php

declare(strict_types=1);

namespace WinterDoctrineTest\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Widget {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = '';
}
