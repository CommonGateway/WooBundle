<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
use App\Entity\ObjectEntity;
use App\Service\ObjectEntityService;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
use CommonGateway\CoreBundle\Service\CacheService;
use DateTime;
use DateInterval;
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
     * @var ObjectEntityService
     */
    private ObjectEntityService $gatewayOEService;

    /**
     * @var SyncOpenWooService
     */
    private SyncOpenWooService $syncOpenWooService;

    /**
     * @var HydrationService
     */
    private HydrationService $hydrationService;

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
     * @param CacheService           $cacheService
     * @param ObjectEntityService    $gatewayOEService
     * @param SyncOpenWooService     $syncOpenWooService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        ValidationService $validationService,
        CacheService $cacheService,
        ObjectEntityService $gatewayOEService,
        SyncOpenWooService $syncOpenWooService
    ) {
        $this->resourceService    = $resourceService;
        $this->callService        = $callService;
        $this->syncService        = $syncService;
        $this->entityManager      = $entityManager;
        $this->mappingService     = $mappingService;
        $this->logger             = $pluginLogger;
        $this->validationService  = $validationService;
        $this->cacheService       = $cacheService;
        $this->gatewayOEService   = $gatewayOEService;
        $this->syncOpenWooService = $syncOpenWooService;
        $this->hydrationService   = new HydrationService($this->syncService, $this->entityManager);

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
     * @param Source   $source  The source entity that provides the source of the result data.
     * @param int|null $page    The page we are fetching, increments each iteration.
     * @param array    $results The results from NotuBiz api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, ?int $page=1, array $results=[])
    {
        $dateTo   = new DateTime();
        $dateFrom = new DateTime();
        $dateFrom->add(DateInterval::createFromDateString('-10 years'));

        $query = [
            'format'          => 'json',
            'page'            => $page,
            'organisation_id' => $this->configuration['organisationId'],
            'version'         => ($this->configuration['notubizVersion'] ?? '1.21.1'),
            'date_to'         => $dateTo->format('Y-m-d H:i:s'),
            'date_from'       => $dateFrom->format('Y-m-d H:i:s'),
        ];

        if (isset($this->configuration['gremiaIds']) === true) {
            $query['gremia_ids'] = $this->configuration['gremiaIds'];
        }

        try {
            $response        = $this->callService->call($source, $this->configuration['sourceEndpoint'], 'GET', ['query' => $query]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        $results = array_merge($results, $decodedResponse['events']);

        // Pagination NotuBiz.
        if (isset($decodedResponse['pagination']['has_more_pages']) === true && $decodedResponse['pagination']['has_more_pages'] === true) {
            $page++;
            $results = $this->fetchObjects($source, $page, $results);
        }

        return $results;

    }//end fetchObjects()


    /**
     * Gets the custom fields for creating a publication object.
     *
     * @param string $categorie The categorie for this publication object.
     *
     * @return array The custom fields.
     */
    private function getCustomFields(string $categorie): array
    {
        return [
            'organisatie' => [
                'oin'  => $this->configuration['oin'],
                'naam' => $this->configuration['organisatie'],
            ],
            'categorie'   => $categorie,
            // todo: or maybe: "Agenda's en besluitenlijsten bestuurscolleges"
            'autoPublish' => $this->configuration['autoPublish'] ?? true,
        ];

    }//end getCustomFields()


    /**
     * Fetches meeting object for an Event from NotuBiz.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param array  $result An Event result body from the NotuBiz API.
     *
     * @return array|null The fetched meeting object.
     */
    private function fetchMeeting(Source $source, array $result): ?array
    {
        if (isset($result['event_type_data']['self']) === false || str_contains($result['event_type_data']['self'], 'meetings') === false) {
            return null;
        }

        $sourceLocation = str_replace('https://', '', $source->getLocation());
        $endpoint       = str_replace($sourceLocation, '', $result['event_type_data']['self']);

        try {
            $response        = $this->callService->call($source, $endpoint, 'GET', ['query' => ['format' => 'json']]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching '.$source->getLocation().$this->configuration['sourceEndpoint'].': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        return $decodedResponse['meeting'];

    }//end fetchMeeting()


    /**
     * Syncs a single result fromt the Notubiz source.
     *
     * @param array   $meetingObject
     * @param Source  $source        The source used.
     * @param Mapping $mapping       The mapping we need to map the result.
     * @param Schema  $schema        The schema we are going to map the result to.
     *
     * @return string|ObjectEntity|array|null
     */
    private function syncResult(array $meetingObject, Source $source, Mapping $mapping, Schema $schema): ObjectEntity|array|string|null
    {
        if (isset($meetingObject['documents']) === true) {
            $result['bijlagen'] = $meetingObject['documents'];
            foreach ($meetingObject['agenda_items'] as $agenda_item) {
                $result['bijlagen'] = array_merge($result['bijlagen'], $agenda_item['documents']);
            }
        }

        $mappedResult = $this->mappingService->mapping($mapping, $result);

        $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
        if ($validationErrors !== null) {
            $validationErrors = implode(', ', $validationErrors);
            $this->logger->warning("SyncNotubiz validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
            isset($this->style) === true && $this->style->warning("SyncNotubiz validation errors: $validationErrors");

            return 'continue';
        }

        return $this->hydrationService->searchAndReplaceSynchronizations(
            $mappedResult,
            $source,
            $schema,
            true,
            true
        );

    }//end syncResult()


    /**
     * Dispatches an event for creating all files / bijlagen.
     *
     * @param array  $documents All the documents to create files for.
     * @param Source $source    The sources used to get the documents.
     *
     * @return void
     */
    private function handleDocuments(array $documents, Source $source)
    {
        foreach ($documents as $document) {
            $documentData['document'] = $document;
            $documentData['source']   = $source->getReference();
            $this->gatewayOEService->dispatchEvent('commongateway.action.event', $documentData, 'woo.openwoo.document.created');
        }

    }//end handleDocuments()


    /**
     * Builds the response in the data array and returns it.
     *
     * @return array The data array.
     */
    private function returnResponse(array $responseItems, Source $source, int $deletedObjectsCount): array
    {
        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems events to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end returnResponse()


    /**
     * Handles syncing the results we got from the Notubiz source to the gateway.
     *
     * @param array   $results The array of results form the source.
     * @param Source  $source  The source used.
     * @param Mapping $mapping The mapping we need to map the results.
     * @param Schema  $schema  The schema we are going to map the results to.
     *
     * @return array
     */
    private function handleResults(array $results, Source $source, Mapping $mapping, Schema $schema): array
    {
        $categorie    = "Vergaderstukken decentrale overheden";
        $customFields = $this->getCustomFields($categorie);

        $documents = $idsSynced = $responseItems = [];
        foreach ($results as $result) {
            try {
                $result        = array_merge($result, $customFields);
                $meetingObject = $this->fetchMeeting($source, $result);

                $object = $this->syncResult($meetingObject, $source, $mapping, $schema);
                if ($object === 'continue') {
                    continue;
                }

                // Get all synced sourceIds.
                if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                    $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
                }

                $this->entityManager->persist($object);
                $this->cacheService->cacheObject($object);
                $responseItems[] = $object;

                $renderedObject = $object->toArray();
                $documents      = array_merge($documents, $renderedObject['bijlagen']);
            } catch (Exception $exception) {
                $this->logger->error("Something went wrong synchronizing sourceId: {$result['id']} with error: {$exception->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                continue;
            }//end try
        }//end foreach

        $this->entityManager->flush();

        $this->handleDocuments($documents, $source);

        $deletedObjectsCount = $this->syncOpenWooService->deleteNonExistingObjects($idsSynced, $source, $this->configuration['schema'], $categorie);

        return $this->returnResponse($responseItems, $source, $deletedObjectsCount);

    }//end handleResults()


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

        isset($this->style) === true && $this->style->success('syncNotubizHandler triggered');
        $this->logger->info('syncNotubizHandler triggered', ['plugin' => 'common-gateway/woo-bundle']);

        if ($this->syncOpenWooService->validateHandlerConfig(
            $this->configuration,
            [
                'sourceEndpoint',
                'organisationId',
            ],
            'syncNotubizHandler'
        ) === false
        ) {
            return [];
        }

        $source  = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
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

        return $this->handleResults($results, $source, $schema, $mapping);

    }//end syncNotubizHandler()


}//end class
