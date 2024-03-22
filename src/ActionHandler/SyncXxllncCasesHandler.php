<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SyncXxllncCasesService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler executing SyncXxllncCasesService->syncXxllncCasesHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category ActionHandler
 */
class SyncXxllncCasesHandler implements ActionHandlerInterface
{

    /**
     * @var SyncXxllncCasesService
     */
    private SyncXxllncCasesService $service;


    /**
     * SyncXxllncCasesHandler constructor.
     *
     * @param SyncXxllncCasesService $service
     */
    public function __construct(SyncXxllncCasesService $service)
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
            '$id'         => 'https://commongateway.nl/pdd.SyncCasesAction.action.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SyncXxllncCasesHandler',
            'description' => 'Handles the sync for xxllnc cases.',
            'required'    => [
                'oin',
                'portalUrl',
                'source',
                'schema',
                'mapping',
                'organisatie',
                'zaaksysteemSearchEndpoint',
                'fileEndpointReference',
            ],
            'properties'  => [
                'oin'                       => [
                    'type'        => 'string',
                    'description' => 'The oin of the publication.',
                    'example'     => 'buren',
                    'required'    => true,
                ],
                'portalUrl'                 => [
                    'type'        => 'string',
                    'description' => 'The portal url of the publication.',
                    'example'     => 'https://conductionnl.github.io/woo-website-buren',
                    'required'    => true,
                ],
                'source'                    => [
                    'type'        => 'string',
                    'description' => 'The source where the publication belongs to.',
                    'example'     => 'https://commongateway.woo.nl/source/buren.openwoo.source.json',
                    'required'    => true,
                ],
                'schema'                    => [
                    'type'        => 'string',
                    'description' => 'The publication schema.',
                    'example'     => 'https://commongateway.nl/woo.publicatie.schema.json',
                    'reference'   => 'https://commongateway.nl/woo.publicatie.schema.json',
                    'required'    => true,
                ],
                'mapping'                   => [
                    'type'        => 'string',
                    'description' => 'The mapping for xxllnc case to publication.',
                    'example'     => 'https://commongateway.nl/mapping/woo.xxllncCaseToWoo.mapping.json',
                    'reference'   => 'https://commongateway.nl/mapping/woo.xxllncCaseToWoo.mapping.json',
                    'required'    => true,
                ],
                'organisatie'               => [
                    'type'        => 'string',
                    'description' => 'The organisatie.',
                    'example'     => 'Gemeente Buren',
                    'required'    => true,
                ],
                'zaaksysteemSearchEndpoint' => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => '/public_search/517/search',
                    'required'    => true,
                ],
                'fileEndpointReference'     => [
                    'type'        => 'string',
                    'description' => 'The file endpoint reference.',
                    'example'     => 'https://commongateway.nl/woo.ViewFile.endpoint.json',
                    'reference'   => 'https://commongateway.nl/woo.ViewFile.endpoint.json',
                    'required'    => true,
                ],
                'allowPDFOnly'              => [
                    'type'        => 'bool',
                    'description' => 'If pdf documents should only be allowed.',
                    'example'     => false,
                    'required'    => true,
                ],
                'autoPublish'   => [
                    'type'        => 'bool',
                    'description' => 'If publications automatically should be visible and public.',
                    'example'     => true,
                    'required'    => true,
                    'default'     => true
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the SyncXxllncCases service plugin.
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
        return $this->service->syncXxllncCasesHandler($data, $configuration);

    }//end run()


}//end class
