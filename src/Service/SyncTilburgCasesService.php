<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\File;
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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use Exception;
use Smalot\PdfParser\Parser;

/**
 * Service responsible for synchronizing TIP cases to woo objects.
 *
 * @package  CommonGateway\WOOBundle
 * @license  EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @author   Acato BV <yoeri@acato.nl>
 * @category Service
 */
class SyncTilburgCasesService
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
     * @var FileService $fileService .
     */
    private FileService $fileService;

    /**
     * @var WooService
     */
    private WooService $wooService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var Parser $pdfParser .
     */
    private Parser $pdfParser;

    /**
     * SyncTilburgCasesService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService $mappingService
     * @param LoggerInterface $pluginLogger
     * @param ValidationService $validationService
     * @param ObjectEntityService $gatewayOEService
     * @param FileService $fileService
     * @param CacheService $cacheService
     * @param WooService $wooService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService            $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService         $mappingService,
        LoggerInterface        $pluginLogger,
        ValidationService      $validationService,
        FileService            $fileService,
        CacheService           $cacheService,
        WooService             $wooService,
        ObjectEntityService    $gatewayOEService
    )
    {
        $this->resourceService = $resourceService;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->entityManager = $entityManager;
        $this->mappingService = $mappingService;
        $this->logger = $pluginLogger;
        $this->validationService = $validationService;
        $this->fileService = $fileService;
        $this->cacheService = $cacheService;
        $this->wooService = $wooService;
        $this->gatewayOEService = $gatewayOEService;
        $this->pdfParser = new Parser();

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
     * Handles the synchronization of TIP cases.
     *
     * @param array $data
     * @param array $configuration
     *
     * @return array
     * @throws Exception|ExceptionCacheException|InvalidArgumentException
     *
     */
    public function SyncTilburgCasesHandler(array $data, array $configuration): array
    {
        // Setup data and configuration.
        $this->data = $data;
        $this->configuration = $configuration;

        // Define source, schema and mapping.
        $source = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');

        // For documents.
        $documentSourceIdMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.tilburgDocumentSetSourceId.schema.json", "common-gateway/woo-bundle");
        $documentMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.tilburgDocumentToBijlage.mapping.json", "common-gateway/woo-bundle");
        $fileEndpoint = $this->resourceService->getEndpoint($this->configuration['fileEndpoint'], 'common-gateway/woo-bundle');

        if ($source instanceof Source === false || $schema instanceof Schema === false || $mapping instanceof Mapping === false) {
            return [];
        }

        $results = $this->fetchObjects($source, '2024-05-01', date('Y-m-d'));
        $this->entityManager->flush();

        $responseItems = [];
        $hydrationService = new HydrationService($this->syncService, $this->entityManager);

        foreach ($results as $result) {

            try {
                $mappedResult = $this->mappingService->mapping($mapping, $result);
            } catch (Exception $exception) {
                error_log(var_export(['ERROR mappingService' => $exception->getMessage()], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                continue;
            }

            // Add organisation data.
            $mappedResult = array_merge(
                $mappedResult,
                [
                    'organisatie' => [
                        'oin' => $this->configuration['oin'],
                        'naam' => $this->configuration['organisatie']
                    ]
                ]
            );

            // Run validation against the provided schema.
            try {
                $validationErrors = $this->validationService->validateData($mappedResult, $schema, 'POST');
                if (null !== $validationErrors) {
                    error_log(var_export(['validationErrors' => $validationErrors], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                    continue;
                }
            } catch (Exception $exception) {
                error_log(var_export(['ERROR validationService' => $exception->getMessage()], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                continue;
            }

            // Fetch all documents for the publication and save them for later enrichment.
            try {
                $temporaryDocuments = [];
                $attachments = $this->fetchDetails($source, $mappedResult['id']);

                // Create temporary documents for later enrichment.
                if (!empty($attachments)) {
                    foreach ($attachments as $attachment) {

                        // Map the document.
                        $temporaryDocument = $this->mappingService->mapping($documentSourceIdMapping, $attachment);
                        $temporaryDocuments[] = $temporaryDocument;
                    }
                }

                // Add them to the 'bijlagen' of the publication.
                $mappedResult['bijlagen'] = $temporaryDocuments;

            } catch (Exception $exception) {
                error_log(var_export(['ERROR fetchDetails' => $exception->getMessage()], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                continue;
            }

            // First save the publication object before enriching documents.
            try {
                $hydrationService->searchAndReplaceSynchronizations(
                    $mappedResult,
                    $source,
                    $schema,
                    true,
                    true
                );

            } catch (Exception $exception) {
                error_log(var_export(['ERROR' => $exception->getMessage()], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                continue;
            }

            // Enrich the attached documents.
            if (!empty($attachments)) {

                $mappedDocuments = [];
                foreach ($attachments as $attachment) {

                    // Fetch the actual file from the TIP.
                    $document = $this->fetchDocument($source, $attachment['identificatie']);

                    // Fetch the existing temporary document.
                    $bijlageObject = $this->entityManager->getRepository('App:ObjectEntity')->findByAnyId($document['identificatie']);

                    // Define the value.
                    $value = $bijlageObject->getValueObject("url");

                    // Persist?
                    $this->entityManager->persist($value);

                    // Create the actual file with contents.
                    $url = $this->fileService->createOrUpdateFile(
                        $value,
                        $document['titel'],
                        $document['inhoud'],
                        $document['formaat'],
                        $fileEndpoint
                    );

                    // Apply document mapping and add url.
                    $mappedDocument = $this->mappingService->mapping($documentMapping, $attachment);

                    // Try extracting text from PDF.
                    try {
                        $pdf = $this->pdfParser->parseContent(\Safe\base64_decode($document['inhoud']));
                        $text = $pdf->getText();
                    } catch (\Exception $e) {
                        error_log(var_export(['ERROR pdfParser' => $e->getMessage()], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                        $text = null;
                    }

                    // Add the enriched document to the array of documents.
                    $mappedDocuments[] = array_merge(
                        $mappedDocument,
                        [
                            'extension' => strtolower(pathinfo($document['titel'], PATHINFO_EXTENSION)),
                            'url' => $url,
                            'documentText' => $text,
                        ]
                    );
                }

                // Overwrite 'bijlagen' to update the enriched documents.
                $mappedResult['bijlagen'] = $mappedDocuments;
            }

            try {
                $publication = $hydrationService->searchAndReplaceSynchronizations(
                    $mappedResult,
                    $source,
                    $schema,
                    true,
                    false
                );

            } catch (Exception $exception) {
                error_log(var_export(['ERROR' => $exception->getMessage()], true) . PHP_EOL, 3, '/srv/api/var/log/debug.log');
                continue;
            }

            $responseItems[] = $publication->toArray();
        }

        $this->entityManager->flush();

        $this->data['response'] = new Response(json_encode($responseItems), 200);
        return $this->data;
    }

    /**
     * Fetches objects from TIP with start and end date.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param string $start The start date for the API query.
     * @param string $end Teh end date for the API query.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, string $start, string $end): array
    {
        try {
            $response = $this->callService->call($source, $this->configuration['caseIndex'], 'GET', ['query' => ['startdatum__gte' => $start, 'einddatum__lt' => $end]]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            error_log('Something went wrong fetching ' . $source->getLocation() . $this->configuration['caseIndex'] . ': ' . $e->getMessage() . PHP_EOL, 3, '/srv/api/var/log/debug.log');
            return [];
        }

        if (isset($decodedResponse['count'], $decodedResponse['results']) && $decodedResponse['count'] > 0) {
            return $decodedResponse['results'];
        }

        return [];

    }//end fetchObjects()

    private function fetchDetails(Source $source, string $identification): array
    {
        try {
            $endpoint = str_replace(':identificatie', $identification, $this->configuration['caseDetail']);
            $response = $this->callService->call($source, $endpoint);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            error_log('Something went wrong fetching ' . $source->getLocation() . $endpoint . ': ' . $e->getMessage() . PHP_EOL, 3, '/srv/api/var/log/debug.log');
            return [];
        }

        if (isset($decodedResponse['count'], $decodedResponse['results']) && $decodedResponse['count'] > 0) {
            return $decodedResponse['results'];
        }

        return [];

    }//end fetchObjects()

    private function fetchDocument(Source $source, string $identification): array
    {
        try {
            $endpoint = str_replace(':identificatie', $identification, $this->configuration['caseDocument']);
            $response = $this->callService->call($source, $endpoint);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            error_log('Something went wrong fetching ' . $source->getLocation() . $endpoint . ': ' . $e->getMessage() . PHP_EOL, 3, '/srv/api/var/log/debug.log');
            return [];
        }

        if (!empty($decodedResponse['identificatie'])) {
            return $decodedResponse;
        }

        return false;

    }

}//end class
