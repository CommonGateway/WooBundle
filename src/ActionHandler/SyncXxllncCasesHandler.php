<?php

namespace CommonGateway\PDDBundle\ActionHandler;

use CommonGateway\PDDBundle\Service\SyncXxllncCasesService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler executing SyncXxllncCasesService->syncXxllncCasesHandler.
 *
 * @author  Conduction BV (info@conduction.nl), Barry Brands (barry@conduction.nl)
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\PDDBundle
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
     *  This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
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
            'required'    => [],
            'properties'  => [],
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
