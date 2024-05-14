<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SyncNotubizService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler executing SyncNotubizService->syncNotubizHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category ActionHandler
 */
class WooNotificationHandler implements ActionHandlerInterface
{

    /**
     * @var SyncNotubizService
     */
    private SyncNotubizService $service;


    /**
     * SyncNotubizHandler constructor.
     *
     * @param SyncNotubizService $service
     */
    public function __construct(SyncNotubizService $service)
    {
        $this->service = $service;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://commongateway.nl/pdd.SyncNotubizAction.action.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SyncNotubizHandler',
            'description' => 'Handles the sync for notubiz requests.',
            'required'    => [
                'oin',
                'portalUrl',
                'source',
                'schema',
                'mapping',
                'sourceType',
                'organisatie',
                'sourceEndpoint',
            ],
            'properties'  => [
                'oin'            => [
                    'type'        => 'string',
                    'description' => 'The oin of the publication.',
                    'example'     => 'buren',
                    'required'    => true,
                ],
                'portalUrl'      => [
                    'type'        => 'string',
                    'description' => 'The portal url of the publication.',
                    'example'     => 'https://conductionnl.github.io/woo-website-buren',
                    'required'    => true,
                ],
                'source'         => [
                    'type'        => 'string',
                    'description' => 'The source where the publication belongs to.',
                    'example'     => 'https://commongateway.woo.nl/source/buren.notubiz.source.json',
                    'required'    => true,
                ],
                'schema'         => [
                    'type'        => 'string',
                    'description' => 'The publication schema.',
                    'example'     => 'https://commongateway.nl/woo.publicatie.schema.json',
                    'reference'   => 'https://commongateway.nl/woo.publicatie.schema.json',
                    'required'    => true,
                ],
                'mapping'        => [
                    'type'        => 'string',
                    'description' => 'The mapping for open woo to publication.',
                    'example'     => 'https://commongateway.nl/mapping/woo.notubizEventToWoo.mapping.json',
                    'reference'   => 'https://commongateway.nl/mapping/woo.notubizEventToWoo.mapping.json',
                    'required'    => true,
                ],
                'sourceType'     => [
                    'type'        => 'string',
                    'description' => 'The source type.',
                    'example'     => 'notubiz',
                    'required'    => true,
                ],
                'organisatie'    => [
                    'type'        => 'string',
                    'description' => 'The organisatie.',
                    'example'     => 'Gemeente Buren',
                    'required'    => true,
                ],
                'organisationId' => [
                    'type'        => 'string',
                    'description' => 'The organization id of the organization in Notubiz.',
                    'example'     => '429',
                    'required'    => true,
                ],
                'notubizVersion' => [
                    'type'        => 'string',
                    'description' => 'The api version of Notubiz, used for getting events.',
                    'example'     => '1.21.1',
                    'required'    => false,
                ],
                'sourceEndpoint' => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => '/events',
                    'required'    => true,
                ],
                'allowPDFOnly'   => [
                    'type'        => 'bool',
                    'description' => 'If pdf documents should only be allowed.',
                    'example'     => false,
                    'required'    => true,
                ],
                'autoPublish'    => [
                    'type'        => 'bool',
                    'description' => 'If publications automatically should be visible and public.',
                    'example'     => true,
                    'required'    => true,
                    'default'     => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the SyncNotubiz service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @throws TransportExceptionInterface|LoaderError|RuntimeError|SyntaxError
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->syncNotubizHandler($data, $configuration);

    }//end run()


}//end class
