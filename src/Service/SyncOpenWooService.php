<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
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
 * Service responsible for synchronizing OpenWoo objects to woo objects.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SyncOpenWooService
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
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * SyncOpenWooService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     * @param FileService            $fileService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        ValidationService $validationService,
        CacheService $cacheService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;
        $this->cacheService      = $cacheService;

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
     * Checks if existing objects still exist in the source, if not deletes them.
     *
     * @param array  $idsSynced ID's from objects we just synced from the source.
     * @param Source $source    These objects belong to.
     * @param string $schemaRef These objects belong to.
     * @param string $categorie The categorie these objects came from.
     *
     * @return int Count of deleted objects.
     */
    private function deleteNonExistingObjects(array $idsSynced, Source $source, string $schemaRef, string $categorie): int
    {
        // Get all existing sourceIds.
        $source            = $this->entityManager->find('App:Gateway', $source->getId()->toString());
        $existingSourceIds = [];
        $existingObjects   = [];
        foreach ($source->getSynchronizations() as $synchronization) {
            if ($synchronization->getEntity()->getReference() === $schemaRef && $synchronization->getSourceId() !== null && $synchronization->getObject()->getValue('categorie') === $categorie) {
                $existingSourceIds[] = $synchronization->getSourceId();
                $existingObjects[]   = $synchronization->getObject();
            }
        }

        // Check if existing sourceIds are in the array of new synced sourceIds.
        $objectIdsToDelete = array_diff($existingSourceIds, $idsSynced);

        // If not it means the object does not exist in the source anymore and should be deleted here.
        $deletedObjectsCount = 0;
        foreach ($objectIdsToDelete as $key => $id) {
            $this->logger->info("Object $id does not exist at the source, deleting.", ['plugin' => 'common-gateway/woo-bundle']);
            $this->entityManager->remove($existingObjects[$key]);
            $deletedObjectsCount++;
        }

        $this->entityManager->flush();

        return $deletedObjectsCount;

    }//end deleteNonExistingObjects()


    /**
     * Fetches objects from openWoo with pagination.
     *
     * @param Source   $source    The source entity that provides the source of the result data.
     * @param int|null $page      The page we are fetching, increments each iteration.
     * @param array    $results   The results from xxllnc api we merge each iteration.
     * @param string   $categorie The type of object we are fetching.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, ?int $page=1, array $results=[], string $categorie)
    {
        $response        = $this->callService->call($source, $this->configuration['sourceEndpoint'], 'GET', ['query' => ['page' => $page]]);
        $decodedResponse = $this->callService->decodeResponse($source, $response);

        switch ($categorie) {
        case 'Woo verzoek':
            $results = array_merge($results, $decodedResponse['WOOverzoeken']);
            break;
        case 'Convenant':
            $results = array_merge($results, $decodedResponse['Convenantenverzoeken']);
            break;
        }

        // Pagination xxllnc.
        if (isset($decodedResponse['pagination']) === true && $decodedResponse['pagination']['pages']['current'] < $decodedResponse['pagination']['pages']['total']) {
            $page++;
            $results = $this->fetchObjects($source, $page, $results, $categorie);
        }

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
    public function syncOpenWooHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('SyncOpenWooService triggered');
        $this->logger->info('SyncOpenWooService triggered', ['plugin' => 'common-gateway/woo-bundle']);

        if (isset($this->configuration['source']) === false
            || isset($this->configuration['oin']) === false
            || isset($this->configuration['organisatie']) === false
            || isset($this->configuration['portalUrl']) === false
            || isset($this->configuration['schema']) === false
            || isset($this->configuration['mapping']) === false
            || isset($this->configuration['sourceEndpoint']) === false
        ) {
            isset($this->style) === true && $this->style->error('No source, schema, mapping, oin, organisatie, sourceEndpoint or portalUrl configured on this action, ending syncOpenWooHandler');
            $this->logger->error('No source, schema, mapping, oin, organisatie, sourceEndpoint or portalUrl configured on this action, ending syncOpenWooHandler', ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }//end if

        $source           = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema           = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping          = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.categorie.mapping.json', 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncOpenWooHandler");

            return [];
        }//end if

        $categorie = '';
        switch ($mapping->getReference()) {
        case 'https://commongateway.nl/mapping/woo.openWooToWoo.mapping.json':
            $categorie = 'Woo verzoek';
            break;
        case 'https://commongateway.nl/mapping/woo.openConvenantToWoo.mapping.json':
            $categorie = 'Convenant';
            break;
        }

        isset($this->style) === true && $this->style->info("Fetching objects from {$source->getLocation()}");
        $this->logger->info("Fetching objects from {$source->getLocation()}", ['plugin' => 'common-gateway/woo-bundle']);

        $results = $this->fetchObjects($source, 1, [], $categorie);
        if (empty($results) === true) {
            $this->logger->info('No results found, ending SyncOpenWooService', ['plugin' => 'common-gateway/woo-bundle']);
            return $this->data;
        }

        $this->entityManager->flush();

        $customFields = [
            'organisatie' => [
                'oin'  => $this->configuration['oin'],
                'naam' => $this->configuration['organisatie'],
            ],
            'categorie'   => $categorie,
        ];

        $idsSynced        = [];
        $responseItems    = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);
        foreach ($results as $result) {
            try {
                $result       = array_merge($result, $customFields);
                $mappedResult = $this->mappingService->mapping($mapping, $result);
                // Map categories to prevent multiple variants of the same categorie.
                $mappedResult = $this->mappingService->mapping($categorieMapping, $mappedResult);

                $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
                if ($validationErrors !== null) {
                    $validationErrors = implode(', ', $validationErrors);
                    $this->logger->warning("SyncOpenWoo validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
                    isset($this->style) === true && $this->style->warning("SyncOpenWoo validation errors: $validationErrors");
                    continue;
                }

                $object = $hydrationService->searchAndReplaceSynchronizations(
                    $mappedResult,
                    $source,
                    $schema,
                    true,
                    true
                );

                // Get all synced sourceIds.
                if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                    $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
                }

                $this->entityManager->persist($object);
                $this->cacheService->cacheObject($object);
                $responseItems[] = $object;
            } catch (Exception $exception) {
                $this->logger->error("Something wen't wrong synchronizing sourceId: {$result['UUID']} with error: {$exception->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                continue;
            }//end try
        }//end foreach

        $this->entityManager->flush();

        $deletedObjectsCount = $this->deleteNonExistingObjects($idsSynced, $source, $this->configuration['schema'], $categorie);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end syncOpenWooHandler()


}//end class
