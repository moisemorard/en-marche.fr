<?php

namespace AppBundle\Repository\Timeline;

use AppBundle\Entity\Timeline\Theme;
use Doctrine\ORM\EntityRepository;

class ThemeRepository extends EntityRepository
{
    public function findOneByTitle(string $title): ?Theme
    {
        return $this->createQueryBuilder('theme')
            ->join('theme.translations', 'translations')
            ->where('translations.title = :title')
            ->setParameter('title', $title)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
