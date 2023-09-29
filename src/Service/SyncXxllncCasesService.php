<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
use CommonGateway\WOOBundle\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use DateTime;

/**
 * Service responsible for synchronizing xxllnc cases to woo objects.
 *
 * @author  Conduction BV (info@conduction.nl), Barry Brands (barry@conduction.nl).
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
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
     * @var ValidationService $validationService.
     */
    private ValidationService $validationService;

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
     * SyncXxllncCasesService constructor.
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
        FileService $fileService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;
        $this->fileService       = $fileService;

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
            $this->logger->info("Object $id does not exist at the source, deleting.");
            $this->entityManager->remove($existingObjects[$key]);
            $deletedObjectsCount++;
        }

        $this->entityManager->flush();

        return $deletedObjectsCount;

    }//end deleteNonExistingObjects()


    /**
     * Generates file view urls for woo informatieverzoek, inventarisatielijst and besluit documents.
     *
     * @param ObjectEntity $object       The main object entity that will be hydrated.
     * @param array        $result       The result data that contains the information of file fields.
     * @param Endpoint     $fileEndpoint The endpoint entity for the file.
     * @param Source       $source       The source entity that provides the source of the result data.
     *
     * @return array       $fileURLS     The view urls for files.
     */
    private function getFileUrls(ObjectEntity $object, array $result, Endpoint $fileEndpoint, Source $source): array
    {
        $fileFields = [
            'informatieverzoek',
            'inventarisatielijst',
            'besluit',
        ];
        $fileURLS   = [];
        foreach ($fileFields as $field) {
            if (isset($result['values']["attribute.woo_$field"][0]) === true) {
                $mimeType = $result['values']["attribute.woo_$field"][0]['mimetype'];
                $base64   = $this->fileService->getInhoudDocument($result['id'], $result['values']["attribute.woo_$field"][0]['uuid'], $mimeType, $source);
                // Finds the existing ValueObject for the URL property or creates a new one.
                // ^ Note: This is important because a File is attached to a Value.
                $value            = $object->getValueObject("URL_$field");
                $fileName         = $result['values']["attribute.woo_$field"][0]['filename'];
                $fileURLS[$field] = $this->fileService->createOrUpdateFile($value, $fileName, $base64, $mimeType, $fileEndpoint);
            }//end if
        }//end foreach

        return $fileURLS;

    }//end getFileUrls()


    /**
     * Generates file view urls for woo bijlagen documents.
     *
     * @param array    $result          The result data that contains the information of file fields.
     * @param Endpoint $fileEndpoint    The endpoint entity for the file.
     * @param Source   $source          The source entity that provides the source of the result data.
     * @param Mapping  $documentMapping The source entity that provides the source of the result data.
     *
     * @return array       $fileURLS     The view urls for files.
     */
    private function getBijlagen(array $result, Endpoint $fileEndpoint, Source $source, Mapping $documentMapping): array
    {
        $bijlagen = [];
        if (isset($result['values']["attribute.woo_publicatie"]) === true) {
            foreach ($result['values']["attribute.woo_publicatie"] as $field) {
                // There can be expected here that there always should be a Bijlage ObjectEntity because of the mapping and hydration + flush that gets executed before this function.
                // ^ Note: this is necessary so we always have a ObjectEntity and Value to attach the File to so we don't create duplicated Files when syncing every 10 minutes.
                $bijlageObject = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($field['uuid']);
                $mimeType      = $field['mimetype'];
                $base64        = $this->fileService->getInhoudDocument($result['id'], $field['uuid'], $mimeType, $source);

                // This finds the existing Value or creates a new one.
                $value = $bijlageObject->getValueObject("URL_Bijlage");
                $this->entityManager->persist($value);

                $url        = $this->fileService->createOrUpdateFile($value, $field['filename'], $base64, $mimeType, $fileEndpoint);
                $bijlage    = $this->mappingService->mapping($documentMapping, array_merge($field, ['url' => $url]));
                $bijlagen[] = $bijlage;
            }//end foreach
        }//end if

        return $bijlagen;

    }//end getBijlagen()


    /**
     * Handles custom logic for processing and hydrating file fields from the given result.
     *
     * @param ObjectEntity $object       The main object entity that will be hydrated.
     * @param array        $result       The result data that contains the information of file fields.
     * @param Endpoint     $fileEndpoint The endpoint entity for the file.
     * @param Source       $source       The source entity that provides the source of the result data.
     *
     * @return ObjectEntity              The hydrated object entity.
     */
    private function handleCustomLogic(ObjectEntity $object, array $result, Endpoint $fileEndpoint, Source $source)
    {
        $documentMapping     = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncDocumentToBijlage.schema.json", "common-gateway/woo-bundle");
        $customFieldsMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.xxllncCustomFields.schema.json", "common-gateway/woo-bundle");

        $fileURLS  = $this->getFileUrls($object, $result, $fileEndpoint, $source);
        $bijlagen  = $this->getBijlagen($result, $fileEndpoint, $source, $documentMapping);
        $portalURL = $this->configuration['portalUrl'].'/'.$object->getId()->toString();

        $hydrateArray = $this->mappingService->mapping($customFieldsMapping, array_merge($fileURLS, ["bijlagen" => $bijlagen, "portalUrl" => $portalURL]));
        $object->hydrate($hydrateArray);

        return $object;

    }//end handleCustomLogic()


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

        if (isset($this->configuration['source']) === false
            || isset($this->configuration['oidn']) === false
            || isset($this->configuration['bestuursorgaan']) === false
            || isset($this->configuration['portalUrl']) === false
            || isset($this->configuration['schema']) === false
            || isset($this->configuration['mapping']) === false
            || isset($this->configuration['fileEndpointReference']) === false
            || isset($this->configuration['zaaksysteemSearchEndpoint']) === false
        ) {
            isset($this->style) === true && $this->style->error('No source, schema, mapping, oidn, bestuursorgaan, fileEndpointReference, zaaksysteemSearchEndpoint or portalUrl configured on this action, ending syncXxllncCasesHandler');
            $this->logger->error('No source, schema, mapping, oidn, bestuursorgaan, fileEndpointReference, zaaksysteemSearchEndpoint or portalUrl configured on this action, ending syncXxllncCasesHandler');

            return [];
        }//end if

        $fileEndpoint = $this->resourceService->getEndpoint($this->configuration['fileEndpointReference'], 'common-gateway/woo-bundle');
        $source       = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema       = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping      = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncXxllncCasesHandler");

            return [];
        }//end if

        isset($this->style) === true && $this->style->info("Fetching cases from {$source->getLocation()}");
        $this->logger->info("Fetching cases from {$source->getLocation()}");

        $response        = $this->callService->call($source, $this->configuration['zaaksysteemSearchEndpoint'], 'GET', []);
        $decodedResponse = $this->callService->decodeResponse($source, $response);
        $this->entityManager->flush();

        $idsSynced        = [];
        $responseItems    = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);
        foreach ($decodedResponse['result'] as $result) {
            $result       = array_merge($result, ['oidn' => $this->configuration['oidn'], 'bestuursorgaan' => $this->configuration['bestuursorgaan']]);
            $mappedResult = $this->mappingService->mapping($mapping, $result);

            $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
            if ($validationErrors !== null) {
                $validationErrors = implode(', ', $validationErrors);
                $this->logger->warning("SyncXxllncCases validation errors: $validationErrors");
                isset($this->style) === true && $this->style->warning("SyncXxllncCases validation errors: $validationErrors");
                continue;
            }

            if (isset($mappedResult['Categorie']) === false
                || empty($mappedResult['Categorie']) === true
                || isset($mappedResult['Publicatiedatum']) === false
                || empty($mappedResult['Publicatiedatum']) === true
                || new DateTime($mappedResult['Publicatiedatum']) > new DateTime()
            ) {
                $this->logger->warning("Categorie or Publicatiedatum is not set or invalid, skipping this case..");
                isset($this->style) === true && $this->style->warning("Categorie or Publicatiedatum is not set or invalid, skipping this case..");
                continue;
            }

            $object = $hydrationService->searchAndReplaceSynchronizations(
                $mappedResult,
                $source,
                $schema,
                true,
                true
            );

            // Prevents empty Bijlagen.
            $object->setValue('Bijlagen', null);

            // Some custom logic.
            $object = $this->handleCustomLogic($object, $result, $fileEndpoint, $source);

            // Get all synced sourceIds.
            if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
            }

            $this->entityManager->persist($object);
            $responseItems[] = $object;
        }//end foreach

        $this->entityManager->flush();

        $deletedObjectsCount = $this->deleteNonExistingObjects($idsSynced, $source, $this->configuration['schema']);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage);

        return $this->data;

    }//end syncXxllncCasesHandler()


}//end class
