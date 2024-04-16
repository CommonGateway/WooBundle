<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\WOOBundle\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Exception;

/**
 * Service responsible for synchronizing NotuBiz objects to woo objects.
 *
 * @author  Conduction BV <info@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SyncNotubizService
{

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SymfonyStyle|null
     */
    private ?SymfonyStyle $style = null;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;

    /**
     * @var ValidationService $validationService.
     */
    private ValidationService $validationService;

    /**
     * @var CacheService $cacheService.
     */
    private CacheService $cacheService;

    /**
     * @var FileService $fileService.
     */
    private FileService $fileService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;
    
    
    /**
     * SyncNotubizService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     * @param ValidationService      $validationService
     * @param FileService            $fileService
     * @param CacheService           $cacheService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        ValidationService $validationService,
        FileService $fileService,
        CacheService $cacheService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;
        $this->fileService       = $fileService;
        $this->cacheService      = $cacheService;

    }//end __construct()


    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()
    
    
    /**
     * Fetches objects from NotuBiz.
     *
     * @param Source   $source    The source entity that provides the source of the result data.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source)
    {
        try {
            $response        = $this->callService->call($source, $this->configuration['sourceEndpoint'], 'GET');
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);
            
            return [];
        }
        
        // todo: Pagination? get results from correct key in decodedResponse
        $results = $decodedResponse;
        
        return $results;
        
    }//end fetchObjects()
    
    
    /**
     * Handles the synchronization of openwoo objects.
     *
     * @param array $data
     * @param array $configuration
     *
     * @throws CacheException|InvalidArgumentException
     *
     * @return array
     */
    public function syncNotubizHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;
        
        isset($this->style) === true && $this->style->success('SyncNotubizService triggered');
        $this->logger->info('SyncNotubizService triggered', ['plugin' => 'common-gateway/woo-bundle']);
        
        // TODO: check if need all of these config options, remove the ones we don't use and update error message & SyncNotubizHandler->getConfiguration():
        if (isset($this->configuration['source']) === false
            || isset($this->configuration['oin']) === false
            || isset($this->configuration['organisatie']) === false
            || isset($this->configuration['portalUrl']) === false
            || isset($this->configuration['schema']) === false
            || isset($this->configuration['mapping']) === false
            || isset($this->configuration['sourceEndpoint']) === false
        ) {
            isset($this->style) === true && $this->style->error('No source, schema, mapping, oin, organisatie, sourceEndpoint or portalUrl configured on this action, ending syncNotubizHandler');
            $this->logger->error('No source, schema, mapping, oin, organisatie, sourceEndpoint or portalUrl configured on this action, ending syncNotubizHandler', ['plugin' => 'common-gateway/woo-bundle']);
            
            return [];
        }//end if
        
        $source           = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema           = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping          = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncNotubizHandler");
            $this->logger->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncNotubizHandler", ['plugin' => 'common-gateway/woo-bundle']);
            
            return [];
        }//end if
        
        isset($this->style) === true && $this->style->info("Fetching objects from {$source->getLocation()}");
        $this->logger->info("Fetching objects from {$source->getLocation()}", ['plugin' => 'common-gateway/woo-bundle']);
        
        $results = $this->fetchObjects($source);
        if (empty($results) === true) {
            $this->logger->info('No results found, ending syncNotubizHandler', ['plugin' => 'common-gateway/woo-bundle']);
            return $this->data;
        }
        
        // todo...
        
        return $this->data;
        
    }//end syncOpenWooHandler()
    
    
    public function syncNotubizDocumentHandler(array $data, array $config): array
    {
        $source   = $this->resourceService->getSource($data['source'], 'common-gateway/woo-bundle');
        $document = $data['document'];
        $endpoint = $this->resourceService->getEndpoint($config['endpoint'], 'common-gateway/woo-bundle');
        
        // todo... do we even need this document handler? if we just sync one main object and also the documents connected to it with the syncNotubizHandler?
        
        return $data;
        
    }//end syncOpenWooDocumentHandler()


}//end class
