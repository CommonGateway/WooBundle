<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway;
use App\Entity\Mapping;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
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
        LoggerInterface $pluginLogger,
        ValidationService $validationService
    ) {
        $this->resourceService   = $resourceService;
        $this->callService       = $callService;
        $this->syncService       = $syncService;
        $this->entityManager     = $entityManager;
        $this->mappingService    = $mappingService;
        $this->logger            = $pluginLogger;
        $this->validationService = $validationService;

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
        $source = $this->entityManager->find('App:Gateway', $source->getId()->toString());
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
        ) {
            isset($this->style) === true && $this->style->error('No source, schema, mapping, oidn, bestuursorgaan or portalUrl configured on this action, ending syncXxllncCasesHandler');
            $this->logger->error('No source, schema, mapping, oidn, bestuursorgaan or portalUrl configured on this action, ending syncXxllncCasesHandler');

            return [];
        }

        $source  = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        if ($source instanceof Gateway === false
            || $schema instanceof Entity === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending syncXxllncCasesHandler");

            return [];
        }

        $sourceConfig = $source->getConfiguration();

        isset($this->style) === true && $this->style->info("Fetching cases from {$source->getLocation()}");
        $this->logger->info("Fetching cases from {$source->getLocation()}");

        $response        = $this->callService->call($source, '', 'GET', $sourceConfig);
        $decodedResponse = $this->callService->decodeResponse($source, $response);
        $this->entityManager->flush();

        $responseItems    = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);
        foreach ($decodedResponse['result'] as $result) {
            $result = array_merge($result, ['oidn' => $this->configuration['oidn'], 'bestuursorgaan' => $this->configuration['bestuursorgaan']]);
            $result = $this->mappingService->mapping($mapping, $result);

            $validationErrors = $this->validationService->validateData($result, $schema, 'POST');
            if ($validationErrors !== null) {
                $validationErrors = implode(', ', $validationErrors);
                $this->logger->error("SyncXxllncCases validation errors: $validationErrors");
                continue;
            }

            if (isset($result['Categorie']) === false || isset($result['Publicatiedatum']) === false || new DateTime($result['Publicatiedatum']) > new DateTime()) {
                continue;
            }

            $object = $hydrationService->searchAndReplaceSynchronizations(
                $result,
                $source,
                $schema,
                true,
                true
            );

            $object->hydrate(['Portal_url' => $this->configuration['portalUrl'].'/'.$object->getId()->toString()]);
            $this->entityManager->persist($object);
            $this->entityManager->flush();

            // Get all synced sourceIds.
            if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
            }

            $responseItems[] = $object;
        }//end foreach

        $deletedObjectsCount = $this->deleteNonExistingObjects($idsSynced, $source, $this->configuration['schema']);

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems cases to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage);

        return $this->data;

    }//end syncXxllncCasesHandler()


}//end class
