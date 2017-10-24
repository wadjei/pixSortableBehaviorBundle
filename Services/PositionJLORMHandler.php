<?php

/*
 * This file is part of the pixSortableBehaviorBundle.
 *
 * (c) Nicolas Ricci <nicolas.ricci@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pix\SortableBehaviorBundle\Services;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Class PositionORMHandler
 *
 * @package Pix\SortableBehaviorBundle
 */
class PositionJLORMHandler extends PositionHandler
{
    /**
     * @var array
     */
    private static $cacheLastPosition = [];

    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->em = $entityManager;
    }

    /**
     * @param object $entity
     * @return int
     */
    public function getLastPosition($entity)
    {
        $entityClass = ClassUtils::getClass($entity);
        $parentEntityClass = true;
        while ($parentEntityClass)
        {
            $parentEntityClass = ClassUtils::getParentClass($entityClass);
            if ($parentEntityClass)
                $entityClass = $parentEntityClass;
        }
        
        $groups      = $this->getSortableGroupsFieldByEntity($entityClass);

        $cacheKey = $this->getCacheKeyForLastPosition($entity, $groups);
        if (!isset(self::$cacheLastPosition[$cacheKey])) {
            $qb = $this->em->createQueryBuilder()
                ->select(sprintf('MAX(t.%s) as last_position', $this->getPositionFieldByEntity($entityClass)))
                ->from($entityClass, 't')
            ;

            if ($groups) {
                $i = 1;
                foreach ($groups as $groupName) {
                    $getter = 'get' . $groupName;

                    if ($entity->$getter()) {
                        $qb
                            ->andWhere(sprintf('t.%s = :group_%s', $groupName, $i))
                            ->setParameter(sprintf('group_%s', $i), $entity->$getter())
                        ;
                        $i++;
                    }
                }
            }

            self::$cacheLastPosition[$cacheKey] = (int)$qb->getQuery()->getSingleScalarResult();
        }

        return self::$cacheLastPosition[$cacheKey];
    }

    /**
     * @param object $entity
     * @param array  $groups
     * @return string
     */
    private function getCacheKeyForLastPosition($entity, $groups)
    {
        $cacheKey = ClassUtils::getClass($entity);
        foreach ($groups as $groupName) {
            $getter = 'get' . ucfirst($groupName);

            if (is_callable([$entity, $getter]) && ($current = call_user_func([$entity, $getter]))) {
                $cacheKey .= '_' . $current . '_' . $entity->getId();
            }
        }
        return $cacheKey;
    }
}
