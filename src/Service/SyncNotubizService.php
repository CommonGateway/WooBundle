<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\File;
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
     * @var LoggerInterface $logger .
     */
    private LoggerInterface $logger;

    /**
     * @var ValidationService $validationService .
     */
    private ValidationService $validationService;

    /**
     * @var CacheService $cacheService .
     */
    private CacheService $cacheService;

    /**
     * @var ObjectEntityService
     */
    private ObjectEntityService $gatewayOEService;

    /**
     * @var WooService
     */
    private WooService $wooService;

    /**
     * @var HydrationService
     */
    private HydrationService $hydrationService;

    /**
     * @var FileService
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
     * @param CacheService           $cacheService
     * @param ObjectEntityService    $gatewayOEService
     * @param WooService             $wooService
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
        CacheService $cacheService,
        ObjectEntityService $gatewayOEService,
        WooService $wooService,
        FileService $fileService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;
        $this->cacheService      = $cacheService;
        $this->gatewayOEService  = $gatewayOEService;
        $this->wooService        = $wooService;
        $this->fileService       = $fileService;
        $this->hydrationService  = new HydrationService($this->syncService, $this->entityManager);

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
     * Fetches Event objects from NotuBiz.
     *
     * @param Source   $source  The source entity that provides the source of the result data.
     * @param int|null $page    The page we are fetching, increments each iteration.
     * @param array    $results The results from NotuBiz api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, ?int $page=1, array $results=[]): array
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
     * Fetches a single Event object from NotuBiz.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param string $id     The id of and Event from the NotuBiz API to get.
     *
     * @return array The fetched object.
     */
    private function fetchObject(Source $source, string $id): array
    {
        $query = ['format' => 'json'];

        $endpoint = $this->configuration['sourceEndpoint'].'/'.$id;

        try {
            $response        = $this->callService->call($source, $endpoint, 'GET', ['query' => $query]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);

            $result = $decodedResponse['event'][0];
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return ["Message" => 'Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage()];
        }

        if ((string) $result['organisation'] !== (string) $this->configuration['organisationId']) {
            $this->logger->info('Fetched Notubiz Event does not match the organisationId of the Action', ['plugin' => 'common-gateway/woo-bundle']);
            return ["Message" => 'Fetched Notubiz Event does not match the organisationId of the Action'];
        }

        if (isset($this->configuration['gremiaIds']) === true) {
            // todo check if gremium id is allowed, we need to fetch meeting object of the Event in orde to check this
            // todo do want to do this here, because we will do the same api-call again later, prevent doing this twice somehow?
            // $meetingObject = $this->fetchMeeting($source, $id);
            // if (in_array($meetingObject['gremium']['id'], $this->configuration['gremiaIds']) === false) {
            // $this->logger->info('Fetched Notubiz Event (Meeting) does not match one of the valid gremium id\'s configured in the Action', ['plugin' => 'common-gateway/woo-bundle']);
            // return [];
            // }
        }

        return $result;

    }//end fetchObject()


    /**
     * Deletes a single Synchronization and its object(s) from the gateway.
     *
     * @param array  $config An array containing the Source, Mapping and Schema we need in order to sync/delete.
     * @param string $id     The id of and Event from the NotuBiz API (sourceId) to find a Synchronization with in the gateway.
     *
     * @return array An array with a success message or error message.
     */
    private function deleteObject(array $config, string $id, string $categorie=null): array
    {
        // Make sure this object does no longer exist in the Notubiz source.
        $result = $this->fetchObject($config['source'], $id);
        if (count($result) !== 1 || isset($result['Message']) === false) {
            return ["Message" => "Object still exists in the NotuBiz API, object in the gateway did not get deleted"];
        }

        $synchronization = $this->syncService->findSyncBySource($config['source'], $config['schema'], $id);

        if ($categorie !== null && $synchronization->getObject()->getValue('categorie') !== $categorie) {
            return ["Message" => "Object does not match the categorie: $categorie"];
        }

        $this->entityManager->remove($synchronization->getObject());
        $this->entityManager->flush();

        return ["Message" => "Object deleted successfully"];

    }//end deleteObject()


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
            'autoPublish' => $this->configuration['autoPublish'] ?? true,
        ];

    }//end getCustomFields()


    /**
     * Fetches meeting object for an Event from NotuBiz.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param string $id     The id of and Event from the NotuBiz API to get the Metting object for.
     *
     * @return array|null The fetched meeting object.
     */
    private function fetchMeeting(Source $source, string $id): ?array
    {
        $endpoint = "/events/meetings/$id";

        try {
            $response        = $this->callService->call($source, $endpoint, 'GET', ['query' => ['format' => 'json']]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        return $decodedResponse['meeting'];

    }//end fetchMeeting()


    /**
     * Syncs a single result from the Notubiz source.
     *
     * @param array $meetingObject The meetingObject of the Event we are syncing.
     * @param array $config        An array containing the Source, Mapping and Schema we need in order to sync.
     * @param array $result        The result array to map and sync
     *
     * @return string|ObjectEntity|array|null
     */
    private function syncResult(array $meetingObject, array $config, array $result): ObjectEntity|array|string|null
    {
        if (isset($meetingObject['documents']) === true) {
            $result['bijlagen'] = $meetingObject['documents'];
            foreach ($meetingObject['agenda_items'] as $agenda_item) {
                $result['bijlagen'] = array_merge($result['bijlagen'], $agenda_item['documents']);
            }
        }

        $mappedResult = $this->mappingService->mapping($config['mapping'], $result);

        $validationErrors = $this->validationService->validateData($mappedResult, $config['schema'], 'POST');
        if ($validationErrors !== null) {
            $validationErrors = implode(', ', $validationErrors);
            $this->logger->warning("SyncNotubiz validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
            isset($this->style) === true && $this->style->warning("SyncNotubiz validation errors: $validationErrors");

            return 'continue';
        }

        return $this->hydrationService->searchAndReplaceSynchronizations(
            $mappedResult,
            $config['source'],
            $config['schema'],
            false,
            false
        );

    }//end syncResult()


    /**
     * Adds a default agenda.pdf file to the given OpenWoo Publicatie ObjectEntity if it doesn't have one yet in its 'bijlagen'.
     *
     * @param ObjectEntity $object The ObjectEntity to add this new document to.
     *
     * @return void
     */
    private function handleAgendaDocument(ObjectEntity $object): void
    {
        $bijlagenValue = $object->getValueObject('bijlagen');

        $documents = $bijlagenValue->getObjects()->filter(
            function (ObjectEntity $document) {
                return $document->getValue('titel') === 'agenda.pdf';
            }
        );

        if (count($documents) > 0) {
            return;
        }

        $file            = new File();
        $agendaPdfBase64 = 'JVBERi0xLjcNCiW1tbW1DQoxIDAgb2JqDQo8PC9UeXBlL0NhdGFsb2cvUGFnZXMgMiAwIFIvTGFuZyhubCkgL1N0cnVjdFRyZWVSb290IDE1IDAgUi9NYXJrSW5mbzw8L01hcmtlZCB0cnVlPj4vTWV0YWRhdGEgMjcgMCBSL1ZpZXdlclByZWZlcmVuY2VzIDI4IDAgUj4+DQplbmRvYmoNCjIgMCBvYmoNCjw8L1R5cGUvUGFnZXMvQ291bnQgMS9LaWRzWyAzIDAgUl0gPj4NCmVuZG9iag0KMyAwIG9iag0KPDwvVHlwZS9QYWdlL1BhcmVudCAyIDAgUi9SZXNvdXJjZXM8PC9Gb250PDwvRjEgNSAwIFIvRjIgMTIgMCBSPj4vRXh0R1N0YXRlPDwvR1MxMCAxMCAwIFIvR1MxMSAxMSAwIFI+Pi9Qcm9jU2V0Wy9QREYvVGV4dC9JbWFnZUIvSW1hZ2VDL0ltYWdlSV0gPj4vTWVkaWFCb3hbIDAgMCA1OTUuMzIgODQxLjkyXSAvQ29udGVudHMgNCAwIFIvR3JvdXA8PC9UeXBlL0dyb3VwL1MvVHJhbnNwYXJlbmN5L0NTL0RldmljZVJHQj4+L1RhYnMvUy9TdHJ1Y3RQYXJlbnRzIDA+Pg0KZW5kb2JqDQo0IDAgb2JqDQo8PC9GaWx0ZXIvRmxhdGVEZWNvZGUvTGVuZ3RoIDIwMz4+DQpzdHJlYW0NCniclY49i8JAFEX7gfkPt9SFTN4b8zGBMEUmURQVlgS2EAsLTaNBd/8/+KJb7IKNr7kcuLx7ELfXw4CyjDdhWYPi9WHoMRnO0XY99R5VHXDTigyN51zOIKRFamYWLmFTWHwftfr6wKBV1WkVzxnMhhJ0J63GNoGRk3E2QZ5JortIbdEyof+R1+ifyL+40GpXyhj7iIuSmILPhOeJT4Vm1kdWsGl8LhFqv0e30qqR7U+t3nS1L1yZMpO6P64Pw6fXBNN/c2g2AXfMXkCjDQplbmRzdHJlYW0NCmVuZG9iag0KNSAwIG9iag0KPDwvVHlwZS9Gb250L1N1YnR5cGUvVHlwZTAvQmFzZUZvbnQvQkNERUVFK0FwdG9zL0VuY29kaW5nL0lkZW50aXR5LUgvRGVzY2VuZGFudEZvbnRzIDYgMCBSL1RvVW5pY29kZSAyMyAwIFI+Pg0KZW5kb2JqDQo2IDAgb2JqDQpbIDcgMCBSXSANCmVuZG9iag0KNyAwIG9iag0KPDwvQmFzZUZvbnQvQkNERUVFK0FwdG9zL1N1YnR5cGUvQ0lERm9udFR5cGUyL1R5cGUvRm9udC9DSURUb0dJRE1hcC9JZGVudGl0eS9EVyAxMDAwL0NJRFN5c3RlbUluZm8gOCAwIFIvRm9udERlc2NyaXB0b3IgOSAwIFIvVyAyNSAwIFI+Pg0KZW5kb2JqDQo4IDAgb2JqDQo8PC9PcmRlcmluZyhJZGVudGl0eSkgL1JlZ2lzdHJ5KEFkb2JlKSAvU3VwcGxlbWVudCAwPj4NCmVuZG9iag0KOSAwIG9iag0KPDwvVHlwZS9Gb250RGVzY3JpcHRvci9Gb250TmFtZS9CQ0RFRUUrQXB0b3MvRmxhZ3MgMzIvSXRhbGljQW5nbGUgMC9Bc2NlbnQgOTM5L0Rlc2NlbnQgLTI4Mi9DYXBIZWlnaHQgOTM5L0F2Z1dpZHRoIDU2MS9NYXhXaWR0aCAxNjgyL0ZvbnRXZWlnaHQgNDAwL1hIZWlnaHQgMjUwL1N0ZW1WIDU2L0ZvbnRCQm94WyAtNTAwIC0yODIgMTE4MiA5MzldIC9Gb250RmlsZTIgMjQgMCBSPj4NCmVuZG9iag0KMTAgMCBvYmoNCjw8L1R5cGUvRXh0R1N0YXRlL0JNL05vcm1hbC9jYSAxPj4NCmVuZG9iag0KMTEgMCBvYmoNCjw8L1R5cGUvRXh0R1N0YXRlL0JNL05vcm1hbC9DQSAxPj4NCmVuZG9iag0KMTIgMCBvYmoNCjw8L1R5cGUvRm9udC9TdWJ0eXBlL1RydWVUeXBlL05hbWUvRjIvQmFzZUZvbnQvQkNERkVFK0FwdG9zL0VuY29kaW5nL1dpbkFuc2lFbmNvZGluZy9Gb250RGVzY3JpcHRvciAxMyAwIFIvRmlyc3RDaGFyIDMyL0xhc3RDaGFyIDMyL1dpZHRocyAyNiAwIFI+Pg0KZW5kb2JqDQoxMyAwIG9iag0KPDwvVHlwZS9Gb250RGVzY3JpcHRvci9Gb250TmFtZS9CQ0RGRUUrQXB0b3MvRmxhZ3MgMzIvSXRhbGljQW5nbGUgMC9Bc2NlbnQgOTM5L0Rlc2NlbnQgLTI4Mi9DYXBIZWlnaHQgOTM5L0F2Z1dpZHRo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NTMxXSAgMjM4WyA1NjFdICAyNDRbIDUyN10gIDI2OFsgNDg0XSAgMzA2WyA1NTFdICA5ODVbIDIwM10gXSANCmVuZG9iag0KMjYgMCBvYmoNClsgMjAzXSANCmVuZG9iag0KMjcgMCBvYmoNCjw8L1R5cGUvTWV0YWRhdGEvU3VidHlwZS9YTUwvTGVuZ3RoIDMwOTI+Pg0Kc3RyZWFtDQo8P3hwYWNrZXQgYmVnaW49Iu+7vyIgaWQ9Ilc1TTBNcENlaGlIenJlU3pOVGN6a2M5ZCI/Pjx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IjMuMS03MDEiPgo8cmRmOlJERiB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiPgo8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIiAgeG1sbnM6cGRmPSJodHRwOi8vbnMuYWRvYmUuY29tL3BkZi8xLjMvIj4KPHBkZjpQcm9kdWNlcj5NaWNyb3NvZnTCriBXb3JkIHZvb3IgTWljcm9zb2Z0IDM2NTwvcGRmOlByb2R1Y2VyPjwvcmRmOkRlc2NyaXB0aW9uPgo8cmRmOkRlc2NyaXB0aW9uIHJkZjphYm91dD0iIiAgeG1sbnM6ZGM9Imh0dHA6Ly9wdXJsLm9yZy9kYy9lbGVtZW50cy8xLjEvIj4KPGRjOmNyZWF0b3I+PHJkZjpTZXE+PHJkZjpsaT5XaWxjbyBMb3V3ZXJzZTwvcmRmOmxpPjwvcmRmOlNlcT48L2RjOmNyZWF0b3I+PC9yZGY6RGVzY3JpcHRpb24+CjxyZGY6RGVzY3JpcHRpb24gcmRmOmFib3V0PSIiICB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iPgo8eG1wOkNyZWF0b3JUb29sPk1pY3Jvc29mdMKuIFdvcmQgdm9vciBNaWNyb3NvZnQgMzY1PC94bXA6Q3JlYXRvclRvb2w+PHhtcDpDcmVhdGVEYXRlPjIwMjQtMDUtMjhUMTU6MjM6MDArMDI6MDA8L3htcDpDcmVhdGVEYXRlPjx4bXA6TW9kaWZ5RGF0ZT4yMDI0LTA1LTI4VDE1OjIzOjAwKzAyOjAwPC94bXA6TW9kaWZ5RGF0ZT48L3JkZjpEZXNjcmlwdGlvbj4KPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgIHhtbG5zOnhtcE1NPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvbW0vIj4KPHhtcE1NOkRvY3VtZW50SUQ+dXVpZDo5OEJCNUM3MC1CNDc1LTQ5MjItQUQ3OC1DRkUzQkJBNkU1MTY8L3htcE1NOkRvY3VtZW50SUQ+PHhtcE1NOkluc3RhbmNlSUQ+dXVpZDo5OEJCNUM3MC1CNDc1LTQ5MjItQUQ3OC1DRkUzQkJBNkU1MTY8L3htcE1NOkluc3RhbmNlSUQ+PC9yZGY6RGVzY3JpcHRpb24+CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIAo8L3JkZjpSREY+PC94OnhtcG1ldGE+PD94cGFja2V0IGVuZD0idyI/Pg0KZW5kc3RyZWFtDQplbmRvYmoNCjI4IDAgb2JqDQo8PC9EaXNwbGF5RG9jVGl0bGUgdHJ1ZT4+DQplbmRvYmoNCjI5IDAgb2JqDQo8PC9UeXBlL1hSZWYvU2l6ZSAyOS9XWyAxIDQgMl0gL1Jvb3QgMSAwIFIvSW5mbyAxNCAwIFIvSURbPDcwNUNCQjk4NzVCNDIyNDlBRDc4Q0ZFM0JCQTZFNTE2Pjw3MDVDQkI5ODc1QjQyMjQ5QUQ3OENGRTNCQkE2RTUxNj5dIC9GaWx0ZXIvRmxhdGVEZWNvZGUvTGVuZ3RoIDEwNz4+DQpzdHJlYW0NCnicLcy9EUBAEAXgd38YP4FEEQq4BjSFRAMyMxqQyBQkVoMZyTn7bLDfzO6+BWKFoGKvgY+NnIK6BVOSmeyC7QiHbiAHuYSEu3QEdPzZQBNDLHFEkf8yiblslXjuhWIS2or0ZBH8A7yEUg7VDQplbmRzdHJlYW0NCmVuZG9iag0KeHJlZg0KMCAzMA0KMDAwMDAwMDAxNSA2NTUzNSBmDQowMDAwMDAwMDE3IDAwMDAwIG4NCjAwMDAwMDAxNjMgMDAwMDAgbg0KMDAwMDAwMDIxOSAwMDAwMCBuDQowMDAwMDAwNTAzIDAwMDAwIG4NCjAwMDAwMDA3ODAgMDAwMDAgbg0KMDAwMDAwMDkwOCAwMDAwMCBuDQowMDAwMDAwOTM2IDAwMDAwIG4NCjAwMDAwMDEwOTEgMDAwMDAgbg0KMDAwMDAwMTE2NCAwMDAwMCBuDQowMDAwMDAxNDAxIDAwMDAwIG4NCjAwMDAwMDE0NTUgMDAwMDAgbg0KMDAwMDAwMTUwOSAwMDAwMCBuDQowMDAwMDAxNjc2IDAwMDAwIG4NCjAwMDAwMDE5MTQgMDAwMDAgbg0KMDAwMDAwMDAxNiA2NTUzNSBmDQowMDAwMDAwMDE3IDY1NTM1IGYNCjAwMDAwMDAwMTggNjU1MzUgZg0KMDAwMDAwMDAxOSA2NTUzNSBmDQowMDAwMDAwMDIwIDY1NTM1IGYNCjAwMDAwMDAwMjEgNjU1MzUgZg0KMDAwMDAwMDAyMiA2NTUzNSBmDQowMDAwMDAwMDAwIDY1NTM1IGYNCjAwMDAwMDI2MTQgMDAwMDAgbg0KMDAwMDAwMjk0MyAwMDAwMCBuDQowMDAwMDEwNzY1IDAwMDAwIG4NCjAwMDAwMTA4NzIgMDAwMDAgbg0KMDAwMDAxMDg5OSAwMDAwMCBuDQowMDAwMDE0MDc0IDAwMDAwIG4NCjAwMDAwMTQxMTkgMDAwMDAgbg0KdHJhaWxlcg0KPDwvU2l6ZSAzMC9Sb290IDEgMCBSL0luZm8gMTQgMCBSL0lEWzw3MDVDQkI5ODc1QjQyMjQ5QUQ3OENGRTNCQkE2RTUxNj48NzA1Q0JCOTg3NUI0MjI0OUFENzhDRkUzQkJBNkU1MTY+XSA+Pg0Kc3RhcnR4cmVmDQoxNDQyNw0KJSVFT0YNCnhyZWYNCjAgMA0KdHJhaWxlcg0KPDwvU2l6ZSAzMC9Sb290IDEgMCBSL0luZm8gMTQgMCBSL0lEWzw3MDVDQkI5ODc1QjQyMjQ5QUQ3OENGRTNCQkE2RTUxNj48NzA1Q0JCOTg3NUI0MjI0OUFENzhDRkUzQkJBNkU1MTY+XSAvUHJldiAxNDQyNy9YUmVmU3RtIDE0MTE5Pj4NCnN0YXJ0eHJlZg0KMTUxODQNCiUlRU9G';
        $file->setBase64($agendaPdfBase64);
        $file->setMimeType('application/pdf');
        $file->setSize($this->gatewayOEService->getBase64Size($file->getBase64()));
        $file->setName('agenda.pdf');
        $file->setExtension('pdf');
        $file->setValue($bijlagenValue);

        $this->entityManager->persist($file);

        $endpoint = $this->resourceService->getEndpoint("https://commongateway.nl/woo.ViewFile.endpoint.json", 'common-gateway/woo-bundle');
        $url      = $this->fileService->generateDownloadEndpoint($file->getId()->toString(), $endpoint);

        $agendaDocument = new ObjectEntity($bijlagenValue->getAttribute()->getObject());
        $agendaDocument->hydrate(
            [
                'titel'        => 'agenda.pdf',
                'url'          => $url,
                'documentText' => 'Agenda',
                'extension'    => 'pdf',
            ]
        );

        $this->entityManager->persist($agendaDocument);

        $this->cacheService->cacheObject($agendaDocument);

        $bijlagenValue->addObject($agendaDocument);

    }//end handleAgendaDocument()


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
     * Handles syncing the Event object results we got from the Notubiz source to the gateway.
     *
     * @param array $results The array of results form the source.
     * @param array $config  An array containing the Source, Mapping and Schema we need in order to sync.
     *
     * @return array
     */
    private function handleResults(array $results, array $config): array
    {
        $categorie = "Vergaderstukken decentrale overheden";
        // todo: or maybe: "Agenda's en besluitenlijsten bestuurscolleges"
        $customFields = $this->getCustomFields($categorie);

        $documents = $idsSynced = $responseItems = [];
        foreach ($results as $result) {
            try {
                $result        = array_merge($result, $customFields);
                $meetingObject = $this->fetchMeeting($config['source'], $result['id']);

                $object = $this->syncResult($meetingObject, $config, $result);
                if ($object === 'continue') {
                    continue;
                }

                $this->handleAgendaDocument($object);

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

        $this->handleDocuments($documents, $config['source']);

        // Make sure to cache all objects with updated documents (specific their urls)
        foreach ($responseItems as $responseItem) {
            $this->cacheService->cacheObject($responseItem);
        }

        $deletedObjectsCount = $this->wooService->deleteNonExistingObjects($idsSynced, $config['source'], $this->configuration['schema'], $categorie);

        return $this->returnResponse($responseItems, $config['source'], $deletedObjectsCount);

    }//end handleResults()


    /**
     * Handles syncing a single Event object result we got from the Notubiz source to the gateway.
     *
     * @param array $result The result form the source.
     * @param array $config An array containing the Source, Mapping and Schema we need in order to sync.
     *
     * @return array
     */
    private function handleResult(array $result, array $config): array
    {
        $categorie = "Vergaderstukken decentrale overheden";
        // todo: or maybe: "Agenda's en besluitenlijsten bestuurscolleges"
        $customFields = $this->getCustomFields($categorie);

        $result        = array_merge($result, $customFields);
        $meetingObject = $this->fetchMeeting($config['source'], $this->data['body']['resourceId']);
        if (empty($meetingObject) === true) {
            return ["Message" => "Something went wrong fetching the Meeting object for Event {$this->data['body']['resourceId']}, check error logs for more info"];
        }

        // Make sure we add id to the result so the Synchronization uses the correct SourceId
        $result['id'] = $this->data['body']['resourceId'];
        // Use creation_date of meeting because Event doesn't have this field when getting one single Event object.
        $result['creation_date'] = $meetingObject['creation_date'];

        $object = $this->syncResult($meetingObject, $config, $result);
        if ($object === 'continue') {
            return ["Message" => "Validation errors, check warning logs for more info"];
        }

        $this->handleAgendaDocument($object);

        $this->entityManager->persist($object);
        $this->entityManager->flush();

        $this->handleDocuments($object->toArray()['bijlagen'], $config['source']);

        $this->cacheService->cacheObject($object);

        $this->logger->info("Synchronized Event {$this->data['body']['resourceUrl']} to woo object", ['plugin' => 'common-gateway/woo-bundle']);

        return $object->toArray();

    }//end handleResult()


    /**
     * Validates if the Configuration array has the required information to sync from Notubiz to OpenWoo.
     *
     * @return array|null The source, schema and mapping objects if they exist. Null if configuration array does not contain all required fields.
     */
    private function validateConfiguration(): ?array
    {
        if ($this->wooService->validateHandlerConfig(
            $this->configuration,
            [
                'sourceEndpoint',
                'organisationId',
            ],
            'sync Notubiz'
        ) === false
        ) {
            return null;
        }

        $source  = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending sync NotuBiz");
            $this->logger->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending sync NotuBiz", ['plugin' => 'common-gateway/woo-bundle']);

            return null;
        }//end if

        return [
            "source"  => $source,
            "schema"  => $schema,
            'mapping' => $mapping,
        ];

    }//end validateConfiguration()


    /**
     * Handles the synchronization of Notubiz API Event objects to OpenWoo publicatie objects.
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

        // Check if configuration array contains the required data and check if source, schema and mapping exist.
        $config = $this->validateConfiguration();
        if ($config === null) {
            return [];
        }

        isset($this->style) === true && $this->style->info("Fetching objects from {$config['source']->getLocation()}");
        $this->logger->info("Fetching objects from {$config['source']->getLocation()}", ['plugin' => 'common-gateway/woo-bundle']);

        $results = $this->fetchObjects($config['source']);
        if (empty($results) === true) {
            $this->logger->info('No results found, ending syncNotubizHandler', ['plugin' => 'common-gateway/woo-bundle']);
            return $this->data;
        }

        return $this->handleResults($results, $config);

    }//end syncNotubizHandler()


    /**
     * Handles the synchronization of one single Notubiz API Event object to an OpenWoo publicatie object when a notification got triggered.
     *
     * @param array $data
     * @param array $configuration
     *
     * @return array
     */
    public function handleNotification(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Check if configuration array contains the required data and check if source, schema and mapping exist.
        $config = $this->validateConfiguration();
        if ($config === null) {
            return [];
        }

        if ($this->data['body']['actie'] === 'delete') {
            return $this->deleteObject($config, $this->data['body']['resourceId']);
        }

        $this->logger->info("Fetching object {$this->data['body']['resourceUrl']}", ['plugin' => 'common-gateway/woo-bundle']);

        $result = $this->fetchObject($config['source'], $this->data['body']['resourceId']);
        if (count($result) === 1 && isset($result['Message']) === true) {
            $this->logger->info('No result found, stop handling notification for Notubiz sync', ['plugin' => 'common-gateway/woo-bundle']);
            return $result;
        }

        return $this->handleResult($result, $config);

    }//end handleNotification()


}//end class
