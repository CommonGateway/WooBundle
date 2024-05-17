<?php

namespace CommonGateway\WOOBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for basic woo functionality and re-usable functions for the other WooBundle services.
 *
 * @author  Conduction BV <info@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class WooNotificationService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;

    /**
     * @var SyncNotubizService
     */
    private SyncNotubizService $syncNotubizService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;


    /**
     * FileService constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface        $pluginLogger
     * @param SyncNotubizService     $syncNotubizService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        SyncNotubizService $syncNotubizService
    ) {
        $this->entityManager      = $entityManager;
        $this->logger             = $pluginLogger;
        $this->syncNotubizService = $syncNotubizService;

    }//end __construct()


    /**
     * Handles incoming WOO notification api-call and is responsible for generating a response.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration from the call
     *
     * @return array A handler must ALWAYS return an array
     * @throws Exception
     */
    public function wooNotificationHandler(array $data, array $configuration): array
    {
        if ($data['method'] !== 'POST') {
            return $data;
        }

        $this->data          = $data;
        $this->configuration = $configuration;

        $this->logger->debug('WooNotificationService -> notificationHandler()');

        // Make sure the action has the field sourceType
        if (isset($configuration['sourceType']) === false) {
            $response = json_encode(['Message' => "Could not find the field 'sourceType' in the action configuration"]);
            return ['response' => new Response($response, 500, ['Content-type' => 'application/json'])];
        }

        $result = ["Message" => "Something went wrong"];
        try {
            switch ($configuration['sourceType']) {
            case 'notubiz':
                $result = $this->syncNotubizService->handleNotification($data, $configuration);
                break;
            case 'openWoo':
                // todo: please use syncNotubizService as an example and update syncOpenWooService to match the same structure.
            case 'xxllncCases':
                // todo please use syncNotubizService as an example and update syncXxllncCasesService to match the same structure.
            default:
                $response = json_encode(['Message' => "The 'sourceType' {$configuration['sourceType']} is not supported"]);
                return ['response' => new Response($response, 500, ['Content-type' => 'application/json'])];
            }
        } catch (\Exception $exception) {
            $response = json_encode(['Message' => "Notification received, but sync failed and returned an Exception: {$exception->getMessage()}"]);
            return ['response' => new Response($response, 500, ['Content-type' => 'application/json'])];
        }//end try

        $response         = [
            'Message' => 'Notification received, object synchronized',
            'Object'  => $result,
        ];
        $data['response'] = new Response(json_encode($response), 200, ['Content-type' => 'application/json']);

        return $data;

    }//end wooNotificationHandler()


}//end class
