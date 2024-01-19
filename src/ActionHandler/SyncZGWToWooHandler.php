<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SyncZGWToWooService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler executing SyncZGWToWooService->syncZGWToWooHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category ActionHandler
 */
class SyncZGWToWooHandler implements ActionHandlerInterface
{

    /**
     * @var SyncZGWToWooService
     */
    private SyncZGWToWooService $service;


    /**
     * SyncZGWToWooHandler constructor.
     *
     * @param SyncZGWToWooService $service
     */
    public function __construct(SyncZGWToWooService $service)
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
            '$id'         => 'https://commongateway.nl/woo.SyncZGWToWooAction.action.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SyncZGWToWooHandler',
            'description' => 'Handles the sync for zgw zaken.',
            'required'    => [
                'oin',
                'portalUrl',
                'zrcSource',
                'drcSource',
                'schema',
                'mapping',
                'fileEndpointReference',
                'zakenEndpoint',
                'zaakType',
                'organisatie',
            ],
            'properties'  => [
                'oin'                       => [
                    'type'        => 'string',
                    'description' => 'The oin of the organisation.',
                    'example'     => 'buren',
                    'required'    => true,
                ],
                'portalUrl'                 => [
                    'type'        => 'string',
                    'description' => 'The portal url of the publication.',
                    'example'     => 'https://conductionnl.github.io/woo-website-buren',
                    'required'    => true,
                ],
                'zrcSource'                    => [
                    'type'        => 'string',
                    'description' => 'The source where the zaken come from.',
                    'example'     => 'https://commongateway.woo.nl/source/example.zrc.source.json',
                    'required'    => true,
                ],
                'drcSource'                    => [
                    'type'        => 'string',
                    'description' => 'The source where the documenten come from.',
                    'example'     => 'https://commongateway.woo.nl/source/example.drc.source.json',
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
                    'description' => 'The mapping for zaak to publication.',
                    'example'     => 'https://commongateway.nl/mapping/woo.zaakToWoo.mapping.json',
                    'reference'   => 'https://commongateway.nl/mapping/woo.zaakToWoo.mapping.json',
                    'required'    => true,
                ],
                'fileEndpointReference'     => [
                    'type'        => 'string',
                    'description' => 'The file endpoint reference.',
                    'example'     => 'https://commongateway.nl/woo.ViewFile.endpoint.json',
                    'reference'   => 'https://commongateway.nl/woo.ViewFile.endpoint.json',
                    'required'    => true,
                ],
                'zaakType' => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => 'http://localhost/api/catalogi/v1/zaaktypen/id',
                    'required'    => true,
                ],
                'zakenEndpoint'     => [
                    'type'        => 'string',
                    'description' => 'The zaken endpoint.',
                    'example'     => '/zrc/v1/zaken',
                    'required'    => true,
                ],
                'organisatie'               => [
                    'type'        => 'string',
                    'description' => 'The organisatie.',
                    'example'     => 'Example',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the SyncZGWToWoo service plugin.
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
        return $this->service->syncZGWToWooHandler($data, $configuration);

    }//end run()


}//end class
