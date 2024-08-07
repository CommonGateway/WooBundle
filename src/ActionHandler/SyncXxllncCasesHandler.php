<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SyncXxllncService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler executing SyncXxllncService->syncXxllncCasesHandler.
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
     * @var SyncXxllncService
     */
    private SyncXxllncService $service;


    /**
     * SyncXxllncCasesHandler constructor.
     *
     * @param SyncXxllncService $service
     */
    public function __construct(SyncXxllncService $service)
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
            '$id'         => 'https://commongateway.nl/ActionHandler/woo.SyncXxllncCasesHandler.actionHandler.json',
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
                    'type'        => 'boolean',
                    'description' => 'If pdf documents should only be allowed.',
                    'example'     => false,
                    'required'    => true,
                ],
                'autoPublish'               => [
                    'type'        => 'boolean',
                    'description' => 'If publications automatically should be visible and public.',
                    'example'     => true,
                    'required'    => true,
                    'default'     => true,
                ],
                'extractTextFromDocuments'  => [
                    'type'        => 'boolean',
                    'description' => 'If text should be extracted from documents and set in to the Bijlage.documentText.',
                    'example'     => true,
                    'required'    => false,
                    'default'     => false,
                ],
                'throw'                     => [
                    'type'        => 'string',
                    'description' => 'The throw fired by the discover-action to start the case detail actions.',
                    'example'     => 'woo.conduction.case',
                    'required'    => true,
                    'default'     => false,
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
        return $this->service->syncXxllncCaseHandler($data, $configuration);

    }//end run()


}//end class
