<?php

namespace Plugin\Elepay\Controller\Admin;

use Eccube\Application;
use Plugin\Elepay\Entity\ElepayConfig;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Eccube\Controller\AbstractController;

class ConfigController extends AbstractController
{
    /**
     * @param Application $app
     * @param Request $request
     * @return Response
     */
    public function index(Application $app, Request $request)
    {
        /** @var ElepayConfig $config */
        $config = $app['eccube.elepay.repository.config']->get();
        /** @var Form $form */
        $form = $app['form.factory']
            ->createBuilder('elepay_config', $config)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $config = $form->getData();

                if ($form->isValid()) {
                    $app['orm.em']->persist($config);
                    $app['orm.em']->flush($config);

                    $app->addSuccess('elepay.admin.save.success', 'admin');
                }
            }
        }

        return $app->render('Elepay/Resource/template/admin/config.twig', array(
            'form' => $form->createView(),
        ));
    }
}
