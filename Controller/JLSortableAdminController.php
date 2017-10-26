<?php
/*
 * This file is part of the pixSortableBehaviorBundle.
 *
 * (c) Nicolas Ricci <nicolas.ricci@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pix\SortableBehaviorBundle\Controller;

use Doctrine\Common\Util\ClassUtils;
use Pix\SortableBehaviorBundle\Services\PositionHandler;
use Sonata\AdminBundle\Controller\CRUDController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManager;

/**
 * Class SortableAdminController
 *
 * @package Pix\SortableBehaviorBundle
 */
class JLSortableAdminController extends CRUDController
{
    /**
     * Get the method to set this entitie's position
     *
     * @param string $entityClass
     * @throws \LogicException
     * @return string
     */
    private function getPositionSetter($entityClass)
    {
        /** @var PositionHandler $positionHandler */
        $positionHandler = $this->get('pix_sortable_behavior.position');

        $setter = sprintf('set%s', ucfirst($positionHandler->getPositionFieldByEntity($entityClass)));

        if (!method_exists($entityClass, $setter)) {
            throw new \LogicException(
                sprintf(
                    '%s does not implement ->%s() to set the desired position.',
                    $entityClass,
                    $setter
                )
            );
        }

        return $setter;
    }

    /**
     * Get filter parameters and query for this entitie's position
     *
     * @param object $object
     * @return string[]|NULL[][] (keys: string filters, array parameters)
     */
    private function getGroupFilter($object)
    {
        $filters = [];
        $parameters = [];

        /** @var PositionHandler $positionHandler */
        $positionHandler = $this->get('pix_sortable_behavior.position');

        $groups = $positionHandler->getSortableGroupsFieldByEntity($object);
        foreach ($groups as $groupField) {
            $proposedGetter = 'get'.ucfirst($groupField);
            if (is_callable([$object, $proposedGetter])) {
                $filters[] = 'e.'.$groupField.' = :group_'.$groupField;
                $parameters['group_'.$groupField] = call_user_func([$object, $proposedGetter]);
            }
        }

        return [
            'filters' => implode(' AND ', $filters),
            'parameters' => $parameters,
        ];
    }

    /**
     * Get a collection of objects with no sorting (zero or null)
     *
     * @param Object $object
     * @return array
     */
    private function getUnsorted($object)
    {
        $entityClass = get_class($object);

        /** @var EntityManager $em */
        $em = $this->get('doctrine')->getEntityManager();

        /** @var PositionHandler $positionHandler */
        $positionHandler = $this->get('pix_sortable_behavior.position');
        $positionField = $positionHandler->getPositionFieldByEntity($entityClass);
        $filters = $this->getGroupFilter($object);

        if (!empty($filters['filters'])) {
            $groupRestrict = ' AND ('.$filters['filters'].')';
        } else {
            $groupRestrict = '';
        }

        $dql = <<<DQL
            SELECT e
              FROM $entityClass e
              WHERE (e.$positionField < 1 OR e.$positionField IS NULL) $groupRestrict
DQL;
        return $em->createQuery($dql)->setParameters($filters['parameters'])->execute();
    }

    /**
     * Move element
     *
     * @param string $position
     *
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function moveAction($position)
    {
        $response = [];
        $translator = $this->get('translator');

        if (!$this->admin->isGranted('EDIT')) {
            $this->addFlash(
                'sonata_flash_error',
                $translator->trans('flash_error_no_rights_update_position')
            );

            return new RedirectResponse($this->admin->generateUrl(
                'list',
                array('filter' => $this->admin->getFilterParameters())
            ));
        }

        /** @var PositionHandler $positionHandler */
        $positionHandler = $this->get('pix_sortable_behavior.position');
        $object          = $this->admin->getSubject();

        $currentPositionNumber = $positionHandler->getCurrentPosition($object);
        $lastPositionNumber = $positionHandler->getLastPosition($object);
        $newPositionNumber  = $positionHandler->getPosition($object, $position, $lastPositionNumber);

        $entityClass = ClassUtils::getClass($object);
        $positionField = $positionHandler->getPositionFieldByEntity($entityClass);
        $setter = $this->getPositionSetter($entityClass);

        call_user_func([$object, $setter], $newPositionNumber);
        $this->admin->update($object);

        // make a space
        if ($currentPositionNumber > $newPositionNumber) {
            $shift = +1;
            $lowerBound = $newPositionNumber;
            $upperBound = $currentPositionNumber;
        } elseif ($currentPositionNumber < $newPositionNumber) {
            $shift = -1;
            $lowerBound = $currentPositionNumber;
            $upperBound = $newPositionNumber;
        }

        $response['remap'] = [];

        if (!empty($shift)) {
            /** @var EntityManager $em */
            $em = $this->get('doctrine')->getEntityManager();

            /** @var EntityRepository $repository */
            $repository = $em->getRepository($entityClass);

            $filters = $this->getGroupFilter($object);

            if (!empty($filters['filters'])) {
                $groupRestrict = ' AND ('.$filters['filters'].')';
            } else {
                $groupRestrict = '';
            }

            $updateDql = <<<DQL
                UPDATE $entityClass e
                  SET e.$positionField = (CASE
                    WHEN e.id = :objectId THEN :newPosition
                    WHEN e.$positionField BETWEEN :lowerBound AND :upperBound THEN e.$positionField + :shift
                    ELSE e.$positionField END
                   )
                  WHERE e.$positionField BETWEEN :lowerBound AND :upperBound $groupRestrict
DQL;
            $reportDql = <<<DQL
                SELECT e.id, e.$positionField
                  FROM $entityClass e
                  WHERE e.$positionField BETWEEN :lowerBound AND :upperBound $groupRestrict
DQL;

            $updateParameters = array_merge(
                $filters['parameters'],
                [
                    'objectId' => $object->getId(),
                    'lowerBound' => $lowerBound,
                    'upperBound' => $upperBound,
                    'newPosition' => $newPositionNumber,
                    'shift' => $shift,
                ]
            );

            $reportParameters = array_merge(
                $filters['parameters'],
                [
                    'lowerBound' => $lowerBound,
                    'upperBound' => $upperBound,
                ]
            );

            $em->createQuery($updateDql)->setParameters($updateParameters)->execute();

            $updates = $em
                ->createQuery($reportDql)
                ->setParameters($reportParameters)
                ->execute()
                ;

            foreach ($updates as $update) {
                $response['remap'][$update['id']] = $update[$positionField];
            }

            $sorting = $positionHandler->getLastPosition($object) + 1;
            foreach ($this->getUnsorted($object) as $item) {
                $response['redraw_recommended'] = true;
                $response['log'][] = "setting unassigned ".$entityClass.":{$item->getId()} sorting to {$sorting}";
                $response['remap'][$item->getId()] = $sorting;
                call_user_func([$item, $setter], $sorting++);
            }

            $em->flush();
            $response['log'][] = "flushing entities";
            $response['message'] = 'The page order has been updated successfully.';

            if ($logger = $this->get('logger')) {
                /** @var \Psr\Log\LoggerInterface $logger */
                $logger->debug(
                    'Move complete for '.$entityClass.':'.$object->getId(),
                    [
                        'response' => $response,
                    ]
                );
            }
        }

        if ($this->isXmlHttpRequest()) {
            return $this->renderJson(array_merge(['result' => 'ok'], $response));
        }

        $this->addFlash(
            'sonata_flash_success',
            $translator->trans('flash_success_position_updated')
        );

        return new RedirectResponse($this->admin->generateUrl(
            'list',
            array('filter' => $this->admin->getFilterParameters())
        ));
    }

    /**
     * Controller action for the drag sorting interface
     *
     * @param Request $request
     * @return Response
     */
    public function resortAction(Request $request)
    {
        $status = 200;
        $response = [
            'redraw_recommended' => false,
            'remap' => [],
            'log' => [],
        ];
        $parameters = json_decode($request->getContent(), true);
        $positionHandler = $this->get('pix_sortable_behavior.position');

        $entityClass = $this->admin->getClass();
        $positionField = $positionHandler->getPositionFieldByEntity($entityClass);
        $setter = $this->getPositionSetter($entityClass);

        /** @var EntityManager $em */
        $em = $this->get('doctrine')->getEntityManager();

        /** @var EntityRepository $repository */
        $repository = $em->getRepository($entityClass);

        if (empty($parameters['order'])) {
            $response['message'] = 'An empty reorder request was sent.';
            $status = 400;
        }
        else {
            /* @todo allow for non-id primary keys */
            $sorting = empty($parameters['first_sorting']) ? 1 : $parameters['first_sorting'];
            foreach ($parameters['order'] as $objectId) {
                $object = $repository->find($objectId);
                $response['log'][] = "updating ".$entityClass.":{$object->getId()} from {$object->getSorting()} to {$sorting}";
                $response['remap'][$object->getId()] = $sorting;
                call_user_func([$object, $setter], $sorting++);
            }

            // amend sorting of all items with sort set to 0 (assume items are grouped correctly so $order is appropiate)
            foreach ($this->getUnsorted($object) as $item) {
                $response['redraw_recommended'] = true;
                $response['log'][] = "setting unassigned ".$entityClass.":{$item->getId()} sorting to {$sorting}";
                $response['remap'][$item->getId()] = $sorting;
                call_user_func([$item, $setter], $sorting++);
            }
        }

        $em->flush();
        $response['log'][] = "flushing entities";
        $response['message'] = 'The page order has been updated successfully.';

        if ($logger = $this->get('logger')) {
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger->debug(
                'Update ordering complete for '.$entityClass,
                [
                    'status' => $status,
                    'poarameters' => $parameters,
                    'response' => $response,
                ]
            );
            /* @todo desensitise response */
        }

        return $this->renderJson($response, $status);
    }
}
