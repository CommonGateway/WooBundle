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
            isset($this->style) === true && $this->style->error('Something went wrong fetching '.$source->getLocation().$configuration['sourceEndpoint'].': '.$e->getMessage());
            $this->logger->error('Something went wrong fetching '.$source->getLocation().$configuration['sourceEndpoint'].': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

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
    }

    public function populateXxllncDocumentHandler(array $data, array $configuration): array
    {
        $source = $this->resourceService->getSource($configuration['source'], "common-gateway/woo-bundle");
        $endpoint = $this->resourceService->getEndpoint($configuration['fileEndpoint'], "common-gateway/woo-bundle");

        $document = $this->entityManager->getRepository(ObjectEntity::class)->findByAnyId($data['sourceId']);
        $base64   = $this->fileService->getInhoudDocument(
            caseId: $data['caseSourceId'],
            documentId: $data['sourceId'],
            mimeType: $data['metadata']['mimetype'],
            zaaksysteem: $source
        );

        $value    = $document->getValueObject('url');
        $url      = $this->fileService->createOrUpdateFile(
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
    }



    public function createAttachmentMessages(array $case, ObjectEntity $publication): void
    {
        foreach($case['attribute.woo_publicatie'] as $document)
        {
            $this->sendMessage('woo.xxllnc.document.populate', ['sourceId' => $document['uuid'], 'caseSourceId' => $case['id'], 'metadata' => $document, 'publication' => $publication->getId()]);
        }

        if (isset($case['attribute.woo_informatieverzoek']) === true) {
            $this->sendMessage('woo.xxllnc.document.populate', ['sourceId' => $case['attribute.woo_informatieverzoek']['uuid'], 'caseSourceId' => $case['id'], 'metadata' => $case['attribute.woo_informatieversoek'], 'publication' => $publication->getId()]);
        }

        if (isset($case['attribute.woo_inventarisatielijst']) === true) {
            $this->sendMessage('woo.xxllnc.document.populate', ['sourceId' => $case['attribute.woo_inventarisatielijst']['uuid'], 'caseSourceId' => $case['id'], 'metadata' => $case['attribute.woo_inventarisatielijst'], 'publication' => $publication->getId()]);
        }

        if (isset($case['attribute.woo_besluit']) === true) {
            $this->sendMessage('woo.xxllnc.document.populate', ['sourceId' => $case['attribute.woo_besluit']['uuid'], 'caseSourceId' => $case['id'], 'metadata' => $case['attribute.woo_besluit'], 'publication' => $publication->getId()]);
        }

    }//end createAttachmentObjects()


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
        $case = array_merge($case, ['autoPublish' => $configuration['autoPublish'] ?? true, 'organisatie' => ['oin' => $configuration['oin'], 'naam' => $configuration['organisatie']]]);

        $mappedCase = $this->mappingService->mapping($mapping, $case);

        $validationErrors = $this->validationService->validateData($mappedCase, $schema, 'POST');
        if ($validationErrors !== null) {
            $validationErrors = implode(', ', $validationErrors);
            $this->logger->warning("SyncXxllncCases validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
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
        $this->cacheService->cacheObject($object);

        return $data;

    }//end syncXxllncCase()


    public function discoverXxllncCases(array $data, array $configuration): array
    {
        $this->setConfiguration($configuration);

        $source = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');

        $this->fetchObjects(source: $source);

        return $data;

    }//end discoverXxllncCases()


    public function syncXxllncCaseHandler(array $data, array $configuration): array
    {
        if (isset($data['case']) === true || isset($data['caseId']) === true) {
            return $this->syncXxllncCase(
                data: $data,
                configuration: $configuration
            );
        }

        return $this->discoverXxllncCases(
            data: $data,
            configuration: $configuration
        );

    }//end syncXxllncCaseHandler()


}//end class