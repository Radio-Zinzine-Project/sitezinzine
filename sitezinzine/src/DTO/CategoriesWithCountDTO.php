<?php

namespace App\DTO;


use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[Vich\Uploadable]

class CategoriesWithCountDTO
{
   public function __construct(
      public readonly int $id,
      public readonly string $titre,
      public readonly ?string $thumbnail,
      public readonly string $descriptif,
      public readonly int $count
   ) {
      
   }
}
