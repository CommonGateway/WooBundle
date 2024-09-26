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
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use Exception;
use Smalot\PdfParser\Parser;

/**
 * Service responsible for synchronizing TIP cases to woo objects.
 *
 * @package CommonGateway\WOOBundle
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
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
     * @var HydrationService $hydrationService .
     */
    private HydrationService $hydrationService;


    /**
     * SyncTilburgCasesService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     * @param LoggerInterface        $pluginLogger
     * @param ValidationService      $validationService
     * @param ObjectEntityService    $gatewayOEService
     * @param FileService            $fileService
     * @param CacheService           $cacheService
     * @param WooService             $wooService
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
        CacheService $cacheService,
        WooService $wooService,
        ObjectEntityService $gatewayOEService
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
        $this->wooService        = $wooService;
        $this->gatewayOEService  = $gatewayOEService;
        $this->pdfParser         = new Parser();

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
     * @throws Exception|InvalidArgumentException
     */
    public function SyncTilburgCasesHandler(array $data, array $configuration): array
    {
        // Setup data and configuration.
        $this->data          = $data;
        $this->configuration = $configuration;

        // Define source, schema and mapping.
        $source  = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');

        // For documents.
        $documentSourceIdMapping = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.tilburgDocumentSetSourceId.schema.json", "common-gateway/woo-bundle");
        $documentMapping         = $this->resourceService->getMapping("https://commongateway.nl/mapping/woo.tilburgDocumentToBijlage.mapping.json", "common-gateway/woo-bundle");
        $fileEndpoint            = $this->resourceService->getEndpoint($this->configuration['fileEndpoint'], 'common-gateway/woo-bundle');

        if ($source instanceof Source === false || $schema instanceof Schema === false || $mapping instanceof Mapping === false) {
            return [];
        }

        // @todo: Implement a way to fetch all objects from the TIP.
        // For now, this fixed date range is used to fetch 28 objects to avoid rate limiting.
        $results = $this->fetchObjects($source, '2024-06-06', '2024-08-01');
        $this->entityManager->flush();

        $responseItems          = [];
        $this->hydrationService = new HydrationService($this->syncService, $this->entityManager);

        foreach ($results as $result) {
            // Map the result.
            $mappedResult = $this->map($mapping, $result);

            // Add organisation data.
            $mappedResult = array_merge(
                $mappedResult,
                [
                    'organisatie' => [
                        'oin'  => $this->configuration['oin'],
                        'naam' => $this->configuration['organisatie'],
                    ],
                ]
            );

            // Run validation against the provided schema.
            if (! $this->isValidData($mappedResult, $schema)) {
                continue;
            }

            // Fetch all documents for the publication and save them for later enrichment.
            $attachments = $this->fetchDetails($source, $mappedResult['id']);
            if (! empty($attachments)) {
                $mappedResult['bijlagen'] = $this->processTemporaryAttachments($attachments, $documentSourceIdMapping);
            }

            // First save the publication object before enriching documents.
            $publication = $this->savePublication($mappedResult, $source, $schema, true, true);
            if ($publication === false) {
                continue;
            }

            // Enrich the attached documents.
            if (! empty($attachments)) {
                $mappedDocuments = [];
                foreach ($attachments as $attachment) {
                    // Add the enriched document to the array of documents.
                    $mappedDocument = $this->processAttachment($attachment, $source, $documentMapping, $fileEndpoint);
                    if ($mappedDocument) {
                        $mappedDocuments[] = $mappedDocument;
                    }
                }

                // Overwrite 'bijlagen' to update the enriched documents.
                $mappedResult['bijlagen'] = $mappedDocuments;
            }

            // Update the publication object with the enriched documents.
            $publication = $this->savePublication($mappedResult, $source, $schema, true, false);
            if ($publication !== false) {
                $responseItems[] = $publication->toArray();
            }
        }//end foreach

        $this->entityManager->flush();

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        return $this->data;

    }//end SyncTilburgCasesHandler()


    /**
     * Processes the attachment.
     *
     * @param array    $attachment The attachment that needs to be processed.
     * @param Source   $source     The source entity that provides the source of the result data.
     * @param Mapping  $mapping    The mapping entity that provides the mapping of the result data.
     * @param Endpoint $endpoint   The endpoint entity that provides the endpoint of the result data.
     *
     * @return array|bool The processed attachment.
     */
    private function processAttachment(array $attachment, Source $source, Mapping $mapping, Endpoint $endpoint): array|bool
    {

        // Fetch the actual file from the TIP.
        $document = $this->fetchDocument($source, $attachment['identificatie']);
        if ($document === false) {
            return false;
        }
        
        // Fetch the existing temporary document.
        $documentObject = $this->entityManager->getRepository('App:ObjectEntity')
            ->findByAnyId($document['identificatie'], $source->getId()->toString());

        // Define the value.
        $value = $documentObject->getValueObject("url");

        // Persist?
        $this->entityManager->persist($value);

        // Create the actual file with contents.
        try {
            $url = $this->fileService->createOrUpdateFile(
                $value,
                $document['titel'],
                $document['inhoud'],
                $document['formaat'],
                $endpoint
            );
        } catch (Exception $e) {
            $this->logger->error(var_export([ 'ERROR fileService' => $e->getMessage() ], true), [ 'plugin' => 'common-gateway/woo-bundle' ]);

            return false;
        }

        // Apply document mapping and add url.
        $mappedDocument = $this->map($mapping, $attachment);

        // Get the file extension.
        $extension = strtolower(pathinfo($document['titel'], PATHINFO_EXTENSION));

        // Set default empty document text.
        $text = null;

        // Try extracting text from PDF.
        if ($extension === 'pdf') {
            try {
                $pdf  = $this->pdfParser->parseContent(\Safe\base64_decode($document['inhoud']));
                $text = $pdf->getText();
            } catch (\Exception $e) {
                $this->logger->error(var_export([ 'ERROR pdfParser' => $e->getMessage() ], true), [ 'plugin' => 'common-gateway/woo-bundle' ]);
            }
        }

        return array_merge(
            $mappedDocument,
            [
                'extension'    => $extension,
                'url'          => $url,
                'documentText' => $text,
            ]
        );

    }//end processAttachment()


    /**
     * Saves the publication.
     *
     * @param array  $data          The data that needs to be saved.
     * @param Source $source        The source entity that provides the source of the result data.
     * @param Schema $schema        The schema entity that provides the schema of the result data.
     * @param bool   $flush         Whether to flush the entity manager.
     * @param bool   $unsafeHydrate Whether to hydrate the entity in an unsafe way.
     *
     * @return ObjectEntity|bool The saved publication.
     */
    private function savePublication(array $data, Source $source, Schema $schema, $flush=true, $unsafeHydrate=false): ObjectEntity|bool
    {
        try {
            $publication = $this->hydrationService->searchAndReplaceSynchronizations(
                $data,
                $source,
                $schema,
                $flush,
                $unsafeHydrate
            );

            return $publication;
        } catch (Exception $exception) {
            $this->logger->error(var_export([ 'ERROR' => $exception->getMessage() ], true), [ 'plugin' => 'common-gateway/woo-bundle' ]);
        }

        return false;

    }//end savePublication()


    /**
     * Maps the result to the provided mapping.
     *
     * @param Mapping $mapping The mapping entity that provides the mapping of the result data.
     * @param array   $result  The result data that needs to be mapped.
     *
     * @return array The mapped result.
     */
    private function map(Mapping $mapping, array $result): array
    {
        try {
            $mappedResult = $this->mappingService->mapping($mapping, $result);
        } catch (Exception $exception) {
            $this->logger->error(var_export([ 'ERROR mappingService' => $exception->getMessage() ], true), [ 'plugin' => 'common-gateway/woo-bundle' ]);
            $mappedResult = [];
        }

        return $mappedResult;

    }//end map()


    /**
     * Validates the result against the provided schema.
     *
     * @param array  $result The result data that needs to be validated.
     * @param Schema $schema The schema entity that provides the schema of the result data.
     * @param string $method The method that is used for the validation.
     *
     * @return bool The validation result.
     */
    private function isValidData(array $result, Schema $schema, string $method='POST'): bool
    {
        try {
            $validationErrors = $this->validationService->validateData($result, $schema, $method);
            if (null !== $validationErrors) {
                $this->logger->error(var_export([ 'validationErrors' => $validationErrors ], true), [ 'plugin' => 'common-gateway/woo-bundle' ]);

                return false;
            }
        } catch (Exception $exception) {
            $this->logger->error(var_export([ 'ERROR validationService' => $exception->getMessage() ], true), [ 'plugin' => 'common-gateway/woo-bundle' ]);

            return false;
        }

        return true;

    }//end isValidData()


    /**
     * Fetches objects from TIP with start and end date.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param string $start  The start date for the API query.
     * @param string $end    Teh end date for the API query.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, string $start, string $end): array
    {
        try {
            $response        = $this->callService->call(
                $source,
                $this->configuration['caseIndex'],
                'GET',
                [
                    'query' => [
                        'startdatum__gte' => $start,
                        'einddatum__lt'   => $end,
                    ],
                ]
            );
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            $this->logger->error('Something went wrong fetching '.$source->getLocation().$this->configuration['caseIndex'].': '.$e->getMessage(), [ 'plugin' => 'common-gateway/woo-bundle' ]);

            return [];
        }

        if (isset($decodedResponse['count'], $decodedResponse['results']) && $decodedResponse['count'] > 0) {
            return $decodedResponse['results'];
        }

        return [];

    }//end fetchObjects()


    /**
     * Fetches details from TIP with identification.
     *
     * @param Source $source         The source entity that provides the source of the result data.
     * @param string $identification The identification of the object.
     *
     * @return array The fetched details.
     */
    private function fetchDetails(Source $source, string $identification): array
    {
        try {
            $endpoint        = str_replace(':identificatie', $identification, $this->configuration['caseDetail']);
            $response        = $this->callService->call($source, $endpoint);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            $this->logger->error('Something went wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage(), [ 'plugin' => 'common-gateway/woo-bundle' ]);

            return [];
        }

        if (isset($decodedResponse['count'], $decodedResponse['results']) && $decodedResponse['count'] > 0) {
            return $decodedResponse['results'];
        }

        return [];

    }//end fetchDetails()


    /**
     * Processes temporary attachments.
     *
     * @param array   $attachments The attachments that need to be processed.
     * @param Mapping $mapping     The mapping entity that provides the mapping of the result data.
     *
     * @return array The processed temporary attachments.
     */
    private function processTemporaryAttachments(array $attachments, Mapping $mapping): array
    {
        $temporaryDocuments = [];
        if (! empty($attachments)) {
            foreach ($attachments as $attachment) {
                // Map the document.
                $temporaryDocument    = $this->map($mapping, $attachment);
                $temporaryDocuments[] = $temporaryDocument;
            }
        }

        return $temporaryDocuments;

    }//end processTemporaryAttachments()


    /**
     * Fetches a document from TIP with identification.
     *
     * @param Source $source         The source entity that provides the source of the result data.
     * @param string $identification The identification of the object.
     *
     * @return array|bool The fetched document.
     */
    private function fetchDocument(Source $source, string $identification): array|bool
    {
        try {
            $endpoint        = str_replace(':identificatie', $identification, $this->configuration['caseDocument']);
            $response        = $this->callService->call($source, $endpoint);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            $this->logger->error('Something went wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage(), [ 'plugin' => 'common-gateway/woo-bundle' ]);

            return false;
        }

        if (! empty($decodedResponse['identificatie'])) {
            return $decodedResponse;
        }

        return false;

    }//end fetchDocument()


}//end class
