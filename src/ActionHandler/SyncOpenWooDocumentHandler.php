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
class SyncOpenWooDocumentHandler implements ActionHandlerInterface
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
                'endpoint' => [
                    'type'        => 'string',
                    'description' => 'The endpoint reference for documents.',
                    'example'     => 'buren',
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
        return $this->service->syncOpenWooDocumentHandler($data, $configuration);

    }//end run()


}//end class
