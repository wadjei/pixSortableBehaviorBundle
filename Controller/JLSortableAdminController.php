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

/**
 * Class SortableAdminController
 *
 * @package Pix\SortableBehaviorBundle
 */
class SortableAdminController extends CRUDController
{
    private function getPositionSetter($object, $entityClass)
    {
        /** @var PositionHandler $positionHandler */
        $positionHandler = $this->get('pix_sortable_behavior.position');

        $setter = sprintf('set%s', ucfirst($positionHandler->getPositionFieldByEntity($entityClass)));

        if (!method_exists($object, $setter)) {
            throw new \LogicException(
                sprintf(
                    '%s does not implement ->%s() to set the desired position.',
                    $object,
                    $setter
                )
            );
        }

        return $setter;
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

        $lastPositionNumber = $positionHandler->getLastPosition($object);
        $newPositionNumber  = $positionHandler->getPosition($object, $position, $lastPositionNumber);

        $entityClass = ClassUtils::getClass($object);
        $setter = $this->getPositionSetter($object, $entityClass);

        call_user_func([$object, $setter], $newPositionNumber);
        $this->admin->update($object);

        if ($this->isXmlHttpRequest()) {
            return $this->renderJson(array(
                'result' => 'ok',
                'objectId' => $this->admin->getNormalizedIdentifier($object)
            ));
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

        $entityClass = ClassUtils::getClass($object);
        $positionField = $positionHandler->getPositionFieldByEntity($entityClass);
        $setter = $this->getPositionSetter($object, $entityClass);

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

            // amend sorting of all items with sort set to 0
            $unassigned = $repository
                ->createQueryBuilder($entityClass)
                ->select('e')
                ->from($entityClass, 'e')
                ->where('e.'.$positionField.' < 1')
                ->getQuery()
                ->execute()
                ;

            foreach ($unassigned as $object) {
                $response['redraw_recommended'] = true;
                $response['log'][] = "setting unassigned ".$entityClass.":{$object->getId()} sorting to {$sorting}";
                $response['remap'][$object->getId()] = $sorting;
                call_user_func([$object, $setter], $sorting++);
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
