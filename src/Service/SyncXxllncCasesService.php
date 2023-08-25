<?php

namespace CommonGateway\PDDBundle\Service;

use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for synchronizing xxllnc cases to woo objects.
 *
 * @author  Conduction BV (info@conduction.nl), Barry Brands (barry@conduction.nl).
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\PDDBundle
 * @category Service
 */
class SyncXxllncCasesService
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
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * SyncXxllncCasesService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger
    ) {
        $this->resourceService = $resourceService;
        $this->callService     = $callService;
        $this->syncService     = $syncService;
        $this->entityManager   = $entityManager;
        $this->mappingService  = $mappingService;
        $this->logger          = $pluginLogger;
    }//end __construct()


    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     *
     * @todo change to monolog
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()


    /**
     * Handles the synchronization of xxllnc cases.
     *
     * @param array $data
     * @param array $configuration
     *
     * @throws CacheException|InvalidArgumentException
     *
     * @return array
     */
    public function syncXxllncCasesHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('SyncXxllncCasesService triggered');
        $this->logger->info('SyncXxllncCasesService triggered');

        if (isset($this->configuration['source']) === false) {
            isset($this->style) === true && $this->style->error('No source configured on this action, ending syncXxllncCasesHandler');
            $this->logger->error('No source configured on this action, ending syncXxllncCasesHandler');

            return [];
        }

        if (isset($this->configuration['oidn']) === false) {
            isset($this->style) === true && $this->style->error('No oidn configured on this action, ending syncXxllncCasesHandler');
            $this->logger->error('No oidn configured on this action, ending syncXxllncCasesHandler');

            return [];
        }

        $source = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/pdd-bundle');
        if ($source === null) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']} not found, ending syncXxllncCasesHandler");
            $this->logger->error("{$this->configuration['source']} not found, ending syncXxllncCasesHandler");
            return [];
        }

        $schemaRef = 'https://commongateway.nl/pdd.openWOO.schema.json';
        $schema    = $this->resourceService->getSchema($schemaRef, 'common-gateway/pdd-bundle');
        if ($schema === null) {
            isset($this->style) === true && $this->style->error("$schemaRef not found, ending syncXxllncCasesHandler");
            $this->logger->error("$schemaRef not found, ending syncXxllncCasesHandlerr");
            return [];
        }

        $mappingRef = 'https://commongateway.nl/mapping/pdd.xxllncCaseToWoo.schema.json';
        $mapping    = $this->resourceService->getMapping($mappingRef, 'common-gateway/pdd-bundle');
        if ($mapping === null) {
            isset($this->style) === true && $this->style->error("$mappingRef not found, ending syncXxllncCasesHandler");
            $this->logger->error("$mappingRef not found, ending syncXxllncCasesHandlerr");
            return [];
        }

        $sourceConfig = $source->getConfiguration();

        isset($this->style) === true && $this->style->info("Fetching cases from {$source->getLocation()}");
        $this->logger->info("Fetching cases from {$source->getLocation()}");

        $response        = $this->callService->call($source, '', 'GET', $sourceConfig);
        $decodedResponse = $this->callService->decodeResponse($source, $response);

        $responseItems = [];
        foreach ($decodedResponse['result'] as $result) {
            $result           = array_merge($result, ['oidn' => $this->configuration['oidn']]);
            $result           = $this->mappingService->mapping($mapping, $result);
            $hydrationService = new HydrationService($this->syncService, $this->entityManager);
            $object           = $hydrationService->searchAndReplaceSynchronizations(
                $result,
                $source,
                $schema,
                true,
                true
            );

            $responseItems[] = $object;
        }

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        isset($this->style) === true && $this->style->success("Synchronized cases to woo objects for " . $source->getName());
        $this->logger->info("Synchronized cases to woo objects for " . $source->getName());

        return $this->data;

    }//end syncXxllncCasesHandler()


}//end class
