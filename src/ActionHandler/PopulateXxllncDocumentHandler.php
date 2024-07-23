<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SyncXxllncService;
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
class PopulateXxllncDocumentHandler implements ActionHandlerInterface
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
            'required'    => [],
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
        return $this->service->populateXxllncDocumentHandler($data, $configuration);

    }//end run()


}//end class
