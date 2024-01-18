<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SyncOpenWooService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler executing SyncOpenWooService->syncOpenWooHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category ActionHandler
 */
class SyncOpenWooHandler implements ActionHandlerInterface
{

    /**
     * @var SyncOpenWooService
     */
    private SyncOpenWooService $service;


    /**
     * SyncOpenWooHandler constructor.
     *
     * @param SyncOpenWooService $service
     */
    public function __construct(SyncOpenWooService $service)
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
            '$id'         => 'https://commongateway.nl/pdd.SyncOpenWooAction.action.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SyncOpenWooHandler',
            'description' => 'Handles the sync for open woo requests.',
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
                    'example'     => 'https://commongateway.woo.nl/source/buren.openwoo.source.json',
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
                    'example'     => 'https://commongateway.nl/mapping/woo.openWooToWoo.mapping.json',
                    'reference'   => 'https://commongateway.nl/mapping/woo.openWooToWoo.mapping.json',
                    'required'    => true,
                ],
                'sourceType'     => [
                    'type'        => 'string',
                    'description' => 'The source type.',
                    'example'     => 'openWoo',
                    'required'    => true,
                ],
                'organisatie'    => [
                    'type'        => 'string',
                    'description' => 'The organisatie.',
                    'example'     => 'Gemeente Buren',
                    'required'    => true,
                ],
                'sourceEndpoint' => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => '/wp-json/owc/openwoo/v1/items',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the SyncOpenWoo service plugin.
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
        return $this->service->syncOpenWooHandler($data, $configuration);

    }//end run()


}//end class
