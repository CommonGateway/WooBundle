<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
use App\Entity\ObjectEntity;
use App\Entity\Value;
use App\Event\ActionEvent;
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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Exception;

class SyncXxllncService
{

    private LoggerInterface $logger;

    private HydrationService $hydrationService;

    private array $configuration = [];


    /**
     * SyncXxllncCasesService constructor.
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
     * @param WooService             $wooService
     */
    public function __construct(
        private readonly GatewayResourceService $resourceService,
        private readonly CallService $callService,
        private readonly SynchronizationService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MappingService $mappingService,
        private readonly ValidationService $validationService,
        private readonly FileService $fileService,
        private readonly CacheService $cacheService,
        private readonly WooService $wooService,
        private readonly EventDispatcherInterface $eventDispatcher,
        LoggerInterface $pluginLogger,
    ) {
        $this->logger           = $pluginLogger;
        $this->hydrationService = new HydrationService($this->syncService, $this->entityManager);

    }//end __construct()


    public function getConfiguration(): array
    {
        return $this->configuration;

    }//end getConfiguration()


    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;

    }//end setConfiguration()


    /**
     * Fetches objects from xxllnc with pagination.
     *
     * @param Source   $source  The source entity that provides the source of the result data.
     * @param int|null $page    The page we are fetching, increments each iteration.
     * @param array    $results The results from xxllnc api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, ?int $page=1, array $results=[]): array
    {
        $configuration = $this->getConfiguration();

        try {
            $response        = $this->callService->call($source, $configuration['zaaksysteemSearchEndpoint'], 'GET', ['query' => ['zapi_page' => $page]]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something went wrong fetching '.$source->getLocation().$configuration['zaaksysteemSearchEndpoint'].': '.$e->getMessage());
            $this->logger->error(
                message: 'Something went wrong fetching '.$source->getLocation().$configuration['zaaksysteemSearchEndpoint'].': '.$e->getMessage(),
                context: ['plugin' => 'common-gateway/woo-bundle']
            );

            return [];
        }

        $results = array_merge($results, $decodedResponse['result']);

        // Pagination xxllnc.
        if (isset($decodedResponse['next']) === true) {
            $page++;
            $results = $this->fetchObjects(
                source: $source,
                page: $page,
                results: $results
            );
        }

        return $results;

    }//end fetchObjects()


    private function fetchCase(string $caseId, array $configuration): array
    {
        $case = [];

        return $case;

    }//end fetchCase()


    public function extractText(Value $value, array $configuration): ?string
    {
        $documentText = null;
        if (isset($configuration['extractTextFromDocuments']) === true && $configuration['extractTextFromDocuments'] === true) {
            // Give the code 5 sec max to extract text.
            $starttime = time();
            // Start timing
            do {
                $documentText = $this->fileService->getTextFromDocument(value: $value);
            } while (isset($documentText) === false && (time() - $starttime) < 5);
        }

        return $documentText;

    }//end extractText()


    public function populateXxllncDocumentHandler(array $data, array $configuration): array
    {
        $source   = $this->resourceService->getSource($data['config']['source'], "common-gateway/woo-bundle");
        $endpoint = $this->resourceService->getEndpoint($data['config']['fileEndpointReference'], "common-gateway/woo-bundle");

        $document = $this->entityManager->getRepository(ObjectEntity::class)->findByAnyId($data['sourceId']);
        $base64   = $this->fileService->getInhoudDocument(
            caseId: $data['caseSourceId'],
            documentId: $data['sourceId'],
            mimeType: $data['metadata']['mimetype'],
            zaaksysteem: $source
        );

        $value = $document->getValueObject('url');
        $url   = $this->fileService->createOrUpdateFile(
            value: $value,
            title: $data['metadata']['filename'],
            base64: $base64,
            mimeType: $data['metadata']['mimetype'],
            downloadEndpoint: $endpoint
        );

        $documentText = $this->extractText(
            value: $value,
            configuration: $configuration
        );

        $document->hydrate(['url' => $url, 'documentText' => $documentText]);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $publication = $this->entityManager->getRepository(ObjectEntity::class)->find($data['publication']);
        $this->cacheService->cacheObject($publication);
        return $data;

    }//end populateXxllncDocumentHandler()


    private function sendMessage(string $throw, array $data): void
    {
        $event = new ActionEvent(type: 'commongateway.action.event', data: $data, subType: $throw);

        $this->eventDispatcher->dispatch(event: $event, eventName: 'commongateway.action.event');

    }//end sendMessage()


    public function createAttachmentMessages(array $case, ObjectEntity $publication): void
    {

        foreach ($case['values']['attribute.woo_publicatie'] as $document) {
            $this->sendMessage(
                throw: 'woo.xxllnc.document.populate',
                data: [
                    'sourceId'     => $document['uuid'],
                    'caseSourceId' => $case['id'],
                    'metadata'     => $document,
                    'publication'  => $publication->getId(),
                    'config'       => $this->getConfiguration(),
                ]
            );
        }

        if (isset($case['values']['attribute.woo_informatieverzoek']) === true && $case['values']['attribute.woo_informatieverzoek'] !== []) {
            $this->sendMessage(
                throw:'woo.xxllnc.document.populate',
                data: [
                    'sourceId'     => $case['values']['attribute.woo_informatieverzoek'][0]['uuid'],
                    'caseSourceId' => $case['id'],
                    'metadata'     => $case['values']['attribute.woo_informatieverzoek'][0],
                    'publication'  => $publication->getId(),
                    'config'       => $this->getConfiguration(),
                ]
            );
        }

        if (isset($case['values']['attribute.woo_inventarisatielijst']) === true && $case['values']['attribute.woo_inventarisatielijst'] !== []) {
            $this->sendMessage(
                throw: 'woo.xxllnc.document.populate',
                data: [
                    'sourceId'     => $case['values']['attribute.woo_inventarisatielijst'][0]['uuid'],
                    'caseSourceId' => $case['id'],
                    'metadata'     => $case['values']['attribute.woo_inventarisatielijst'][0],
                    'publication'  => $publication->getId(),
                    'config'       => $this->getConfiguration(),
                ]
            );
        }

        if (isset($case['values']['attribute.woo_besluit']) === true && $case['values']['attribute.woo_besluit'] !== []) {
            $this->sendMessage(
                throw: 'woo.xxllnc.document.populate',
                data: [
                    'sourceId'     => $case['values']['attribute.woo_besluit'][0]['uuid'],
                    'caseSourceId' => $case['id'],
                    'metadata'     => $case['values']['attribute.woo_besluit'][0],
                    'publication'  => $publication->getId(),
                    'config'       => $this->getConfiguration(),
                ]
            );
        }

    }//end createAttachmentMessages()


    private function searchAndDeleteObject(array $case): void
    {
        if (isset($case['id']) === true) {
            $this->logger->warning("Searching for a object with sourceId: {$case['id']} to delete it because it became invalid", ['plugin' => 'common-gateway/woo-bundle']);
            $publicationObject = $this->entityManager->getRepository(ObjectEntity::class)->findByAnyId($case['id']);

            if ($publicationObject instanceof ObjectEntity === true) {
                $this->entityManager->remove($publicationObject);
                $this->entityManager->flush();
            }
        }

    }//end searchAndDeleteObject()


    public function syncXxllncCase(array $data, array $configuration): array
    {
        if (isset($data['case']) === false) {
            $data['case'] = $this->fetchCase(
                caseId: $data['caseId'],
                configuration: $configuration
            );
        }

        $case = $data['case'];

        $schema  = $this->resourceService->getSchema($configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($configuration['mapping'], 'common-gateway/woo-bundle');
        $source  = $this->resourceService->getSource($configuration['source'], 'common-gateway/woo-bundle');

        // TODO: Check if we can put this into the mapping.
        $case = array_merge(
            $case,
            [
                'autoPublish' => $configuration['autoPublish'] ?? true,
                'organisatie' => [
                    'oin'  => $configuration['oin'],
                    'naam' => $configuration['organisatie'],
                ],
                'settings'    => [
                    'allowPDFOnly' => $configuration['allowPDFOnly'],
                ],
            ]
        );

        $mappedCase = $this->mappingService->mapping($mapping, $case);

        $validationErrors = $this->validationService->validateData($mappedCase, $schema, 'POST');
        if ($validationErrors !== null) {
            $validationErrors = implode(', ', $validationErrors);
            $this->logger->warning("SyncXxllncCases validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);

            $this->searchAndDeleteObject(case: $case);

            isset($this->style) === true && $this->style->warning("SyncXxllncCases validation errors: $validationErrors");
            return $data;
        }

        $object = $this->hydrationService->searchAndReplaceSynchronizations(
            $mappedCase,
            $source,
            $schema,
            true,
            true
        );

        $object = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($case['id']);

        $object->hydrate(['portalUrl' => "{$configuration['portalUrl']}/{$object->getId()->toString()}"]);

        $this->entityManager->persist($object);
        $this->entityManager->flush();
        $this->cacheService->cacheObject($object);

        $this->createAttachmentMessages(case: $case, publication: $object);

        return $data;

    }//end syncXxllncCase()


    public function discoverXxllncCases(array $data, array $configuration): array
    {
        $this->setConfiguration($configuration);

        $source = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');

        $objects = $this->fetchObjects(source: $source);

        $allSourceIds = [];
        foreach ($objects as $object) {
            if (isset($object['id']) === true) {
                $allSourceIds[] = $object['id'];
            }

            $this->sendMessage(throw: $configuration['throw'], data: ['case' => $object]);
        }

        $this->sendMessage(throw: $configuration['throw'], data: ['deleteUnsyncedObjects' => true, 'allSourceIds' => $allSourceIds]);

        return $data;

    }//end discoverXxllncCases()


    public function syncXxllncCaseHandler(array $data, array $configuration): array
    {
        $this->setConfiguration($configuration);

        if (isset($data['case']) === true || isset($data['caseId']) === true) {
            return $this->syncXxllncCase(
                data: $data,
                configuration: $configuration
            );
        }

        if (isset($data['deleteUnsyncedObjects']) === true && $data['deleteUnsyncedObjects'] === true) {
            $source = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
            return ['deletedObjects' => $this->wooService->deleteUnsyncedObjects($data['allSourceIds'], $source, $this->configuration['schema'])];
        }

        return $this->discoverXxllncCases(
            data: $data,
            configuration: $configuration
        );

    }//end syncXxllncCaseHandler()


}//end class
