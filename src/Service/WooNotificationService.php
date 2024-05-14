<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Value;
use App\Entity\Endpoint;
use App\Entity\File;
use CommonGateway\CoreBundle\Service\CallService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Smalot\PdfParser\Parser;

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
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->logger        = $pluginLogger;

    }//end __construct()


    /**
     * Handles incoming WOO notification api-call and is responsible for generating a response.
     *
     * TODO: this is a copy past from CoreBundle needs to change
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration from the call
     *
     * @return array A handler must ALWAYS return an array
     * @throws Exception
     */
    public function notificationHandler(array $data, array $configuration): array
    {
        // todo: check url of notification to get the source that matches it
        // todo: somehow decide what sourceType this is ??? ... and continue to one of the other services for actual syncing
        
        if ($data['method'] !== 'POST') {
            return $data;
        }
        
        $this->data          = $data;
        $this->configuration = $configuration;
        
        $this->logger->debug('NotificationService -> notificationHandler()');
        
        $dot = new Dot($this->data);
        $url = $dot->get($this->configuration['urlLocation']);
        
        // Get the correct Entity.
        $entity = $this->resourceService->getSchema($this->configuration['entity'], 'commongateway/corebundle');
        if ($entity === null) {
            $response = json_encode(['Message' => "Could not find an Entity with this reference: {$this->configuration['entity']}"]);
            return ['response' => new Response($response, 500, ['Content-type' => 'application/json'])];
        }
        
        try {
            $this->syncService->aquireObject($url, $entity);
        } catch (\Exception $exception) {
            $response = json_encode(['Message' => "Notification call before sync returned an Exception: {$exception->getMessage()}"]);
            return ['response' => new Response($response, 500, ['Content-type' => 'application/json'])];
        }//end try
        
        $this->entityManager->flush();
        
        $response         = ['Message' => 'Notification received, object synchronized'];
        $data['response'] = new Response(json_encode($response), 200, ['Content-type' => 'application/json']);
        
        return $data;
        
    }//end notificationHandler()


}//end class
