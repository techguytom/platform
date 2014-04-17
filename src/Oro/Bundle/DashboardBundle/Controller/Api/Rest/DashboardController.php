<?php

namespace Oro\Bundle\DashboardBundle\Controller\Api\Rest;

use Doctrine\Common\Persistence\ObjectManager;

use FOS\Rest\Util\Codes;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Routing\ClassResourceInterface;

use Nelmio\ApiDocBundle\Annotation\ApiDoc;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

use Symfony\Component\HttpFoundation\Response;

use Oro\Bundle\DashboardBundle\Entity\Dashboard;
use Oro\Bundle\SecurityBundle\Annotation\Acl;

/**
 * @Rest\RouteResource("dashboard")
 * @Rest\NamePrefix("oro_api_")
 */
class DashboardController extends FOSRestController implements ClassResourceInterface
{
    /**
     * @param integer $id
     *
     * @ApiDoc(
     *      description="Delete dashboard",
     *      resource=true
     * )
     * @Acl(
     *      id="oro_dashboard_delete",
     *      type="entity",
     *      permission="DELETE",
     *      class="OroDashboardBundle:Dashboard"
     * )
     * @return Response
     */
    public function deleteAction($id)
    {
        $dashboard = $this->getDashboard($id);

        if (!$dashboard) {
            return $this->handleView($this->view(array(), Codes::HTTP_NOT_FOUND));
        }

        $this->getEntityManager()->remove($dashboard);
        $this->getEntityManager()->flush();

        return $this->handleView($this->view(array(), Codes::HTTP_NO_CONTENT));
    }

    /**
     * @param integer $id
     * @return Dashboard
     */
    protected function getDashboard($id)
    {
        $entity = $this
            ->getEntityManager()
            ->getRepository('OroDashboardBundle:Dashboard')
            ->find($id);

        return $entity;
    }

    /**
     * @return ObjectManager
     */
    protected function getEntityManager()
    {
        return $this->getDoctrine()->getManager();
    }
}
