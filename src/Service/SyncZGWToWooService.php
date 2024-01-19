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
 * Service responsible for synchronizing zaken to woo objects.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SyncZGWToWooService
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
     * SyncZGWToWooService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     * @param ValidationService      $validationService
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
     *
     * @return int Count of deleted objects.
     */
    private function deleteNonExistingObjects(array $idsSynced, Source $source, string $schemaRef): int
    {
        // Get all existing sourceIds.
        $source            = $this->entityManager->find('App:Gateway', $source->getId()->toString());
        $existingSourceIds = [];
        $existingObjects   = [];
        foreach ($source->getSynchronizations() as $synchronization) {
            if ($synchronization->getEntity()->getReference() === $schemaRef && $synchronization->getSourceId() !== null) {
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
     * Retrieves files at the zaaksystem and maps them to bijlagen.
     *
     * @param array $result       The result data that contains the information of file fields.
     * @param array $documentMeta Metadata about a document, including the id.
     * @param array $config       Gateway config objects.
     *
     * @return array The view urls for files.
     */
    private function retrieveFile(array $result, array $documentMeta, array $config): array
    {
        // There can be expected here that there always should be a Bijlage ObjectEntity because of the mapping and hydration + flush that gets executed before this function.
        // ^ Note: this is necessary, so we always have a ObjectEntity and Value to attach the File to, so we don't create duplicated Files when syncing every 10 minutes.
        $bijlageObject = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($documentMeta['uuid']);

        $mimeType = $documentMeta['mimetype'];

        $base64 = $this->fileService->getInhoudDocument($result['id'], $documentMeta['uuid'], $mimeType, $config['source']);

        // This finds the existing Value or creates a new one.
        $value = $bijlageObject->getValueObject("url");

        $this->entityManager->persist($value);

        $url = $this->fileService->createOrUpdateFile($value, $documentMeta['filename'], $base64, $mimeType, $config['endpoint']);

        return $this->mappingService->mapping($config['mapping'], array_merge($documentMeta, ['url' => $url]));

    }//end retrieveFile()


    /**
     * Generates file view urls for woo bijlagen documents.
     *
     * @param array $result   The result data that contains the information of file fields.
     * @param array $config   Gateway config objects.
     * @param array $fileURLS File urls we also return.
     *
     * @return array $fileURLS The view urls for files.
     */
    private function getBijlagen(array $result, array $config, array &$fileURLS): array
    {
        $fileFields = [
            'informatieverzoek',
            'inventarisatielijst',
            'besluit',
        ];

        // Prevent having documents with the same names.
        $fileNames = [];

        foreach ($fileFields as $field) {
            if (isset($result['values']["attribute.woo_$field"][0]) === true) {
                $documentMeta     = $result['values']["attribute.woo_$field"][0];
                $fileURLS[$field] = $this->retrieveFile($result, $documentMeta, $config);
                $fileNames[]      = $result['values']["attribute.woo_$field"][0]['filename'];
            }//end if
        }//end foreach

        $bijlagen = [];
        if (isset($result['values']["attribute.woo_publicatie"]) === true) {
            foreach ($result['values']["attribute.woo_publicatie"] as $documentMeta) {
                if (in_array($documentMeta['filename'], $fileNames) === false) {
                    $bijlagen[]  = $this->retrieveFile($result, $documentMeta, $config);
                    $fileNames[] = $documentMeta['filename'];
                }
            }//end foreach
        }//end if

        return $bijlagen;

    }//end getBijlagen()


    /**
     * Handles custom logic for processing and hydrating file fields from the given result.
     *
     * @param array    $objectArray  Self array of object.
     * @param array    $result       The result data that contains the information of file fields.
     * @param Endpoint $fileEndpoint The endpoint entity for the file.
     * @param Source   $source       The source entity that provides the source of the result data.
     *
     * @return array                     The hydrated object.
     */
    private function handleCustomLogic(array $objectArray, array $result, Endpoint $fileEndpoint, Source $source): array
    {
        $documentMapping     = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncDocumentToBijlage.mapping.json", "common-gateway/woo-bundle");
        // $customFieldsMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncCustomFields.mapping.json", "common-gateway/woo-bundle");

        // $fileURLS get set from $this->getBijlagen (see arguments).
        $fileURLS  = [];
        $bijlagen  = $this->getBijlagen($result, ['endpoint' => $fileEndpoint, 'source' => $source, 'mapping' => $documentMapping], $fileURLS);
        $portalURL = $this->configuration['portalUrl'].'/'.$objectArray['_self']['id'];

        return $this->mappingService->mapping($customFieldsMapping, array_merge($objectArray, $fileURLS, ["bijlagen" => $bijlagen, "portalUrl" => $portalURL, "id" => $result['id']]));

    }//end handleCustomLogic()


    /**
     * Fetches objects from zrc.
     *
     * @param Source   $source   The source entity that provides the source of the result data.
     * @param string   $endpoint The endpoint we request on the source.
     * @param int|null $page     The page we are fetching, increments each iteration.
     * @param array    $results  The results from zaken api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, string $endpoint, ?int $page=1, array $results=[]): array
    {
        $response        = $this->callService->call($source, $endpoint, 'GET', ['query' => ['page' => $page]]);
        $decodedResponse = $this->callService->decodeResponse($source, $response);

        $results = array_merge($results, $decodedResponse['results']);

        // Pagination xxllnc.
        if (isset($decodedResponse['next']) === true) {
            $page++;
            $results = $this->fetchObjects($source, $endpoint, $page, $results);
        }

        return $results;

    }//end fetchObjects()

    /**
     * Fetches informatieobjecten from drc.
     *
     * @param Source   $source                 The source entity that provides the source of the result data.
     * @param string   $zaakInformatieObjecten ZaakInformatieObjecten array.
     *
     * @return array The fetched enkelvoudiginformatieobjecten.
     */
    private function getInformatieObjecten(Source $source, array $zaakInformatieObjecten): array
    {
        
    }

    /**
     * This function sets the eigenschappen to a usable array.
     *
     * @param array $zaakEigenschappen The zaak eigenschappen.
     *
     * @return array zaakEigenschappen
     */
    public function getZaakEigenschappen(array $zaakEigenschappen): array
    {
        $arrayZaakEigenschappen = [];
        foreach ($zaakEigenschappen as $zaakEigenschap) {
            $arrayZaakEigenschappen[$zaakEigenschap['naam']] = $zaakEigenschap['waarde'];
        }

        return $arrayZaakEigenschappen;
    }//end getZaakEigenschappen()

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
    public function syncZGWToWooHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('SyncZGWToWooService triggered');
        $this->logger->info('SyncZGWToWooService triggered', ['plugin' => 'common-gateway/woo-bundle']);

        if (isset($this->configuration['source']) === false
            || isset($this->configuration['oin']) === false
            || isset($this->configuration['organisatie']) === false
            || isset($this->configuration['portalUrl']) === false
            || isset($this->configuration['schema']) === false
            || isset($this->configuration['mapping']) === false
            || isset($this->configuration['zrcSource']) === false
            || isset($this->configuration['drcSource']) === false
            || isset($this->configuration['zaakType']) === false
            || isset($this->configuration['zaakEndpoint']) === false
            || isset($this->configuration['zaakInformatieEndpoint']) === false
            || isset($this->configuration['fileEndpointReference']) === false
        ) {
            isset($this->style) === true && $this->style->error('No zrcSource, drcSource schema, mapping, oin, organisatie, zaakType, zakenEndpoint, zaakInformatieEndpoint, fileEndpointReference or portalUrl configured on this action, ending syncZGWToWooHandler');
            $this->logger->error('No zrcSource, drcSource schema, mapping, oin, organisatie, zaakType, zakenEndpoint, zaakInformatieEndpoint, fileEndpointReference or portalUrl configured on this action, ending syncZGWToWooHandler', ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }//end if

        $fileEndpoint           = $this->resourceService->getEndpoint($this->configuration['fileEndpointReference'], 'common-gateway/woo-bundle');
        $zrcSource              = $this->resourceService->getSource($this->configuration['zrcSource'], 'common-gateway/woo-bundle');
        $drcSource              = $this->resourceService->getSource($this->configuration['drcSource'], 'common-gateway/woo-bundle');
        $schema                 = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping                = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        $zaakEndpoint           = $this->configuration['zaakEndpoint'];
        $zaakInformatieEndpoint = $this->configuration['zaakInformatieEndpoint'];
        $zaakType               = $this->configuration['zaakType'];
        if ($zrcSource instanceof Source === false
            || $drcSource instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['zrcSource']}, {$this->configuration['drcSource']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncXxllncCasesHandler");

            return [];
        }//end if

        $endpoint = "$zaakEndpoint?zaaktype=$zaakType";
        $url = "{$zrcSource->getLocation()}$endpoint";

        isset($this->style) === true && $this->style->info("Fetching zaken from $url");
        $this->logger->info("Fetching zaken from $url", ['plugin' => 'common-gateway/woo-bundle']);

        $results = $this->fetchObjects($zrcSource, $endpoint);
        $this->entityManager->flush();

        $idsSynced        = [];
        $responseItems    = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);
        foreach ($results as $result) {
            try {
                if (isset($result['eigenschappen']) === false || isset($result['uuid']) === false) {
                    $this->logger->warning("A zaak has no eigenschappen or uuid, continueing to next zaak", ['plugin' => 'common-gateway/woo-bundle']);
                    isset($this->style) === true && $this->style->warning("A zaak has no eigenschappen or uuid, continueing to next zaak");

                    continue;
                }

                // Get eigenschappen.
                $eigenschappenEndpoint = "/$zaakEndpoint/{$result['uuid']}/zaakeigenschappen";
                $zaakEigenschappen = $this->fetchObjects($zrcSource, $eigenschappenEndpoint); 
                $zaakEigenschappen = $this->getZaakEigenschappen($zaakEigenschappen);

                // Get informatieobjecten
                $zaakInformatieEndpoint = "/$zaakInformatieEndpoint?zaak={$result['url']}";
                $zaakInformatieObjecten = $this->fetchObjects($zrcSource, $zaakInformatieEndpoint); 
                $enkelvoudigInformatieObjecen = $this->getInformatieObjecten($zaakInformatieObjecten, $drcSource);

                $result       = array_merge($result, $zaakEigenschappen, ['organisatie' => ['oin' => $this->configuration['oin'], 'naam' => $this->configuration['organisatie']]]);
                $mappedResult = $this->mappingService->mapping($mapping, $result);

                $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
                if ($validationErrors !== null) {
                    $validationErrors = implode(', ', $validationErrors);
                    $this->logger->warning("SyncZGWToWoo validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
                    isset($this->style) === true && $this->style->warning("SyncZGWToWoo validation errors: $validationErrors");
                    continue;
                }

                $object = $hydrationService->searchAndReplaceSynchronizations(
                    $mappedResult,
                    $zrcSource,
                    $schema,
                    true,
                    true
                );

                // Some custom logic.
                $hydrateArray = $this->handleCustomLogic($object->toArray(), $result, $fileEndpoint, $drcSource);

                // Second time to update Bijlagen.
                $object = $hydrationService->searchAndReplaceSynchronizations(
                    $hydrateArray,
                    $zrcSource,
                    $schema,
                    true,
                    false
                );

                $object = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($result['uuid']);

                // Get all synced sourceIds.
                if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                    $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
                }

                $this->entityManager->persist($object);
                $this->cacheService->cacheObject($object);
                $responseItems[] = $object;
            } catch (Exception $exception) {
                isset($this->style) === true && $this->style->error("Something wen't wrong synchronizing sourceId: {$result['id']} with error: {$exception->getMessage()}");
                $this->logger->error("Something wen't wrong synchronizing sourceId: {$result['id']} with error: {$exception->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                continue;
            }//end try
        }//end foreach

        $this->entityManager->flush();

        $deletedObjectsCount = $this->deleteNonExistingObjects($idsSynced, $source, $this->configuration['schema']);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end syncXxllncCasesHandler()


}//end class
