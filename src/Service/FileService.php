<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Value;
use App\Entity\Endpoint;
use App\Entity\File;
use CommonGateway\CoreBundle\Service\CallService;
use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Smalot\PdfParser\Parser;

/**
 * Service responsible for woo files.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class FileService
{

    /**
     * @var CallService
     */
    private CallService $callService;

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
     * @var ParameterBagInterface $parameterBag.
     */
    private ParameterBagInterface $parameterBag;

    /**
     * @var Parser $pdfParser.
     */
    private Parser $pdfParser;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * FileService constructor.
     *
     * @param CallService            $callService
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface        $pluginLogger
     * @param ParameterBagInterface  $parameterBag
     */
    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        ParameterBagInterface $parameterBag
    ) {
        $this->callService   = $callService;
        $this->entityManager = $entityManager;
        $this->logger        = $pluginLogger;
        $this->parameterBag  = $parameterBag;
        $this->pdfParser     = new Parser();

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
     * Gets the inhoud of the document from a different endpoint that has the metadata.
     *
     * @param string $caseId      Case id.
     * @param string $documentId  Document id.
     * @param Source $zaaksysteem Xxllnc zaaksysteem api v1.
     *
     * @return string|null $this->callService->decodeResponse() Decoded requested document as PHP array.
     */
    public function getInhoudDocument(string $caseId, string $documentId, string $mimeType, Source $zaaksysteem): ?string
    {
        try {
            isset($this->style) === true && $this->style->info("Fetching inhoud document: $documentId for case $caseId");
            $this->logger->info("Fetching inhoud document: $documentId for case $caseId", ['plugin' => 'common-gateway/woo-bundle']);
            $response = $this->callService->call($zaaksysteem, "/v1/case/$caseId/document/$documentId/download", 'GET', [], false);
            return $this->callService->decodeResponse($zaaksysteem, $response, $mimeType);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error("Failed to fetch inhoud of document: $documentId, message:  {$e->getMessage()}");
            $this->logger->error("Failed to fetch inhoud of document: $documentId, message:  {$e->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
            return null;
        }

    }//end getInhoudDocument()


    /**
     * Creates or updates a file associated with a given Value instance.
     *
     * This method handles the logic for creating or updating a file based on
     * provided data. If an existing file is associated with the Value,
     * it updates the file's properties; otherwise, it creates a new file.
     *
     * @param Value    $value            The object entity associated with the file.
     * @param string   $title            File title.
     * @param string   $base64           Encoded base64.
     * @param string   $mimeType         Mimetype of file.
     * @param Endpoint $downloadEndpoint Endpoint to use for downloading the file.
     *
     * @return void
     */
    public function createOrUpdateFile(Value $value, string $title, string $base64, string $mimeType, Endpoint $downloadEndpoint): string
    {
        if ($value->getFiles()->count() > 0) {
            $file = $value->getFiles()->first();
        } else {
            $file = new File();
        }

        $file->setName($title);
        $file->setBase64($base64);
        $file->setMimeType(($mimeType ?? 'application/pdf'));
        $extension = match ($file->getMimeType()) {
            'pdf', 'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => '',
        };

        $file->setExtension($extension);
        $file->setSize(mb_strlen(\Safe\base64_decode($base64)));
        $file->setValue($value);

        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return $this->generateDownloadEndpoint($file->getId()->toString(), $downloadEndpoint);

    }//end createOrUpdateFile()


    /**
     * Generates a download endpoint from the id of an 'WOO Object' and the endpoint for downloads.
     *
     * @param string   $id               The id of the WOO object.
     * @param Endpoint $downloadEndpoint The endpoint for downloads.
     *
     * @return string The endpoint to download the document from.
     */
    public function generateDownloadEndpoint(string $id, Endpoint $downloadEndpoint): string
    {
        // Unset the last / from the app_url.
        $baseUrl = rtrim($this->parameterBag->get('app_url'), '/');

        $pathArray = $downloadEndpoint->getPath();
        foreach ($pathArray as $key => $value) {
            if ($value == 'id' || $value == '[id]' || $value == '{id}') {
                $pathArray[$key] = $id;
            }
        }

        return $baseUrl.'/api/'.implode('/', $pathArray);

    }//end generateDownloadEndpoint()


    /**
     * Extracts text from a document (File).
     *
     * @param Value $value The value associated with the file.
     *
     * @return string|null
     */
    public function getTextFromDocument(Value $value): ?string
    {
        if ($value->getFiles()->count() > 0) {
            $file = $value->getFiles()->first();
        } else {
            return null;
        }

        switch ($file->getMimeType()) {
        case 'pdf':
        case 'application/pdf':
            try {
                $pdf  = $this->pdfParser->parseContent(\Safe\base64_decode($file->getBase64()));
                $text = $pdf->getText();
            } catch (\Exception $e) {
                $this->logger->error('Something went wrong extracting text from '.$file->getName().' '.$e->getMessage());
                $this->style && $this->style->error('Something went wrong extracting text from '.$file->getName().' '.$e->getMessage());

                $text = null;
            }
            break;
        default:
            $text = null;
        }

        return $text;

    }//end getTextFromDocument()


    /**
     * Returns the data from an document as a response.
     *
     * @param array $data          The data passed by the action.
     * @param array $configuration The configuration of the action.
     *
     * @return array
     */
    public function viewFileHandler(array $data, array $configuration): array
    {
        $this->data = $data;

        $parameters = $this->data;
        $path       = $this->data['path'];

        $file = $this->entityManager->getRepository('App:File')->find($path['id']);

        // Make sure only files for the configured schema are retrievable.
        if ($file instanceof File === false
            || $file->getValue() === null
            || $file->getValue()->getObjectEntity() === null
            || $file->getValue()->getObjectEntity()->getEntity() === null
            || $file->getValue()->getObjectEntity()->getEntity()->getReference() === null
            || isset($configuration['schemaThisFileBelongsTo']) === false
            || in_array($file->getValue()->getObjectEntity()->getEntity()->getReference(), $configuration['schemaThisFileBelongsTo']) === false
        ) {
            return ['response' => new Response('{"message" => "File not found or file doesn\'t belong to the configured schema."}', 400, ['content-type' => 'application/json'])];
        }

        if ($file->getMimeType() === "text/plain") {
            $responseBody = $file->getBase64();
        } else {
            $responseBody = \Safe\base64_decode($file->getBase64());
        }

        return ['response' => new Response($responseBody, 200, ['content-type' => $file->getMimeType()])];

    }//end viewFileHandler()


}//end class
