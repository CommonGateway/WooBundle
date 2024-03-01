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
    private function retrieveInhoud(array $enkelvoudigInformatieObject, array $config): array
    {
        // There can be expected here that there always should be a Bijlage ObjectEntity because of the mapping and hydration + flush that gets executed before this function.
        // ^ Note: this is necessary, so we always have a ObjectEntity and Value to attach the File to, so we don't create duplicated Files when syncing every 10 minutes.
        $bijlageObject = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($enkelvoudigInformatieObject['informatieobject']['url']);

        $mimeType = $enkelvoudigInformatieObject['informatieobject']['formaat'];

        if (filter_var($enkelvoudigInformatieObject['informatieobject']['inhoud'], FILTER_VALIDATE_URL) === true) {
            $base64 = $this->fileService->getInhoudInformatieObject($enkelvoudigInformatieObject['informatieobject']['inhoud'], $enkelvoudigInformatieObject['url'], $mimeType, $config['source']);
        } else {
            $base64 = $enkelvoudigInformatieObject['informatieobject']['inhoud'];
        }
            
        // This finds the existing Value or creates a new one.
        $value = $bijlageObject->getValueObject("url");

        $this->entityManager->persist($value);

        $url = $this->fileService->createOrUpdateFile($value, $enkelvoudigInformatieObject['informatieobject']['titel'], $base64, $mimeType, $config['endpoint']);

        return $this->mappingService->mapping($config['mapping'], array_merge($enkelvoudigInformatieObject, ['viewUrl' => $url]));

    }//end retrieveInhoud()


    /**
     * Generates file view urls for woo bijlagen documents.
     *
     * @param array $enkelvoudigInformatieObjecten The result data that contains the information of file fields.
     * @param array $config                        Gateway config objects.
     * @param array $fileURLS                      File urls we also return.
     *
     * @return array $fileURLS The view urls for files.
     */
    private function getBijlagen(array $enkelvoudigInformatieObjecten, array $config, array &$fileURLS): array
    {
        $fileFields = [
            'informatieverzoek',
            'inventarisatielijst',
            'besluit',
        ];

        // Prevent having documents with the same names.
        $fileNames = [];

        foreach ($fileFields as $field) {
            if (isset($enkelvoudigInformatieObjecten[$field][0]) === true) {
                $fileURLS[$field] = $this->retrieveInhoud($enkelvoudigInformatieObjecten[$field][0], $config);
                $fileNames[]      = $enkelvoudigInformatieObjecten[$field][0]['informatieobject']['titel'];
            }//end if
        }//end foreach

        $bijlagen = [];
        if (isset($enkelvoudigInformatieObjecten['bijlagen']) === true) {
            foreach ($enkelvoudigInformatieObjecten['bijlagen'] as $bijlage) {
                if (in_array($bijlage['informatieobject']['titel'], $fileNames) === false) {
                    $bijlagen[]  = $this->retrieveInhoud($bijlage, $config);
                    $fileNames[] = $bijlage['informatieobject']['titel'];
                }
            }//end foreach
        }//end if

        return $bijlagen;

    }//end getBijlagen()


    /**
     * Fetches inhoud from informatieobject and updates the bijlagen properties on the Woo object with it.
     *
     * @param array    $objectArray  Self array of the Woo object.
     * @param array    $result       The result data that contains the information of file fields.
     * @param Endpoint $fileEndpoint The endpoint entity for the file.
     * @param Source   $source       The source entity that provides the source of the result data.
     * @param string   $sourceId     The sourceId of the zaak.
     *
     * @return array                     The hydrated object.
     */
    private function updateBijlagen(array $objectArray, array $enkelvoudigInformatieObjecten, Endpoint $fileEndpoint, Source $source, string $sourceId): array
    {
        $documentMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.zgwEnkelvoudigInformatieToBijlage.mapping.json", "common-gateway/woo-bundle");
        // Yes we can use the same mapping here as used for xxllnc.
        $customFieldsMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncCustomFields.mapping.json", "common-gateway/woo-bundle");

        // $fileURLS get set from $this->getBijlagen (see arguments).
        $fileURLS  = [];
        $bijlagen  = $this->getBijlagen($enkelvoudigInformatieObjecten, ['endpoint' => $fileEndpoint, 'source' => $source, 'mapping' => $documentMapping], $fileURLS);
        $portalURL = $this->configuration['portalUrl'].'/'.$objectArray['_self']['id'];

        return $this->mappingService->mapping($customFieldsMapping, array_merge($objectArray, $fileURLS, ["bijlagen" => $bijlagen, "portalUrl" => $portalURL, "id" => $sourceId]));

    }//end updateBijlagen()


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
     * @param Source $source                    The source entity that provides the source of the result data.
     * @param array  $zaakInformatieObjecten    ZaakInformatieObjecten array.
     * @param array  $informatieObjectTypenUrls Array with the needed informatieobjecttypen urls.
     *
     * @return array The fetched enkelvoudiginformatieobjecten.
     */
    private function getInformatieObjecten(Source $source, array $zaakInformatieObjecten, array $informatieObjectTypenUrls): array
    {
        $informatieObjecten = [
            'besluit'             => [],
            'informatieverzoek'   => [],
            'inventarisatielijst' => [],
            'bijlagen'            => [],
        ];

        foreach ($zaakInformatieObjecten as $key => $zaakInformatieObject) {
            if (isset($zaakInformatieObjecten[$key]['informatieobject']) === true) {
                $enkelvoudigInformatieObject = $this->fileService->getEnkelvoudigInformatieObject($zaakInformatieObjecten[$key]['informatieobject'], $source);
                if ($enkelvoudigInformatieObject !== null && isset($enkelvoudigInformatieObject['informatieobjecttype']) !== false) {
                    $zaakInformatieObjecten[$key]['informatieobject'] = $enkelvoudigInformatieObject;
                    switch ($enkelvoudigInformatieObject['informatieobjecttype']) {
                        case $informatieObjectTypenUrls['informatieverzoek']:
                            $informatieObjecten['informatieverzoek'][] = $zaakInformatieObjecten[$key];
                            break;
                        case $informatieObjectTypenUrls['inventarisatielijst']:
                            $informatieObjecten['inventarisatielijst'][] = $zaakInformatieObjecten[$key];
                            break;
                        case $informatieObjectTypenUrls['besluit']:
                            $informatieObjecten['besluit'][] = $zaakInformatieObjecten[$key];
                            break;
                        case $informatieObjectTypenUrls['bijlagen']:
                            $informatieObjecten['bijlagen'][] = $zaakInformatieObjecten[$key];
                            break;
                        }
                }
            }
        }//end foreach

        return $informatieObjecten;

    }//end getInformatieObjecten()


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

        $configFields = [
            'oin',
            'organisatie',
            'portalUrl',
            'schema',
            'mapping',
            'zrcSource',
            'drcSource',
            'zaakType',
            'zakenEndpoint',
            'fileEndpointReference',
            'bijlageInformatieObjectUrl',
            'informatieverzoekInformatieObjectUrl',
            'inventarisatielijstInformatieObjectUrl',
            'besluitInformatieObjectUrl'
        ];

        $missingConfigField = false;
        $errorMessage = 'Missing one or more config values:';
        foreach ($configFields as $configField) {
            if (isset($this->configuration[$configField]) === false) {
                $missingConfigField = true;
                $errorMessage .= ' '.$configField; 
            }
        }

        if ($missingConfigField === true) {
            isset($this->style) === true && $this->style->error($errorMessage);
            $this->logger->error($errorMessage, ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        $fileEndpoint                           = $this->resourceService->getEndpoint($this->configuration['fileEndpointReference'], 'common-gateway/woo-bundle');
        $zrcSource                              = $this->resourceService->getSource($this->configuration['zrcSource'], 'common-gateway/woo-bundle');
        $drcSource                              = $this->resourceService->getSource($this->configuration['drcSource'], 'common-gateway/woo-bundle');
        $schema                                 = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping                                = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        $zaakEndpoint                           = $this->configuration['zakenEndpoint'];
        $zaakType                               = $this->configuration['zaakType'];
        $bijlageInformatieObjectUrl             = $this->configuration['bijlageInformatieObjectUrl'];
        $informatieverzoekInformatieObjectUrl   = $this->configuration['informatieverzoekInformatieObjectUrl'];
        $inventarisatielijstInformatieObjectUrl = $this->configuration['inventarisatielijstInformatieObjectUrl'];
        $besluitInformatieObjectUrl             = $this->configuration['besluitInformatieObjectUrl'];
        if ($zrcSource instanceof Source === false
            || $drcSource instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['zrcSource']}, {$this->configuration['drcSource']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncXxllncCasesHandler");

            return [];
        }//end if

        $endpoint = "$zaakEndpoint?zaaktype=$zaakType";
        $url      = "{$zrcSource->getLocation()}$endpoint";

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
                $eigenschappenEndpoint = "/zaakeigenschappen?zaak={$result['url']}";
                $zaakEigenschappen     = $this->fetchObjects($zrcSource, $eigenschappenEndpoint);
                $zaakEigenschappen     = $this->getZaakEigenschappen($zaakEigenschappen);

                $informatieObjectTypenUrls = [
                    'besluit'             => $besluitInformatieObjectUrl,
                    'informatieverzoek'   => $informatieverzoekInformatieObjectUrl,
                    'inventarisatielijst' => $inventarisatielijstInformatieObjectUrl,
                    'bijlage'             => $bijlageInformatieObjectUrl,
                ];

                // Get informatieobjecten
                $zaakInformatieEndpoint        = "/zaakinformatieobjecten?zaak={$result['url']}";
                $zaakInformatieObjecten        = $this->fetchObjects($zrcSource, $zaakInformatieEndpoint);
                $enkelvoudigInformatieObjecten = $this->getInformatieObjecten($drcSource, $zaakInformatieObjecten, $informatieObjectTypenUrls);

                $dataToMap = [
                    'eigenschappen'                 => $zaakEigenschappen,
                    'enkelvoudigInformatieObjecten' => $enkelvoudigInformatieObjecten,
                    'organisatie' => [
                        'oin'  => $this->configuration['oin'],
                        'naam' => $this->configuration['organisatie'],
                    ]
                ];

                $result       = array_merge($result, $dataToMap);
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

                $sourceId = $result['uuid'];

                // Some custom logic.
                $hydrateArray = $this->updateBijlagen($object->toArray(), $enkelvoudigInformatieObjecten, $fileEndpoint, $drcSource, $sourceId);

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

        $deletedObjectsCount = $this->deleteNonExistingObjects($idsSynced, $zrcSource, $this->configuration['schema']);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$zrcSource->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end syncZGWToWooHandler()


}//end class
