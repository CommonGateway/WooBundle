<?php

namespace CommonGateway\PDDBundle\Service;

use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Service responsible for synchronizing zgw zaken to woo objects.
 *
 * @author  Conduction BV (info@conduction.nl), Barry Brands (barry@conduction.nl).
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\PDDBundle
 * @category Service
 */
class SyncZGWZakenService
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
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * SyncZGWZakenService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService            $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService         $mappingService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService $mappingService
    ) {
        $this->resourceService = $resourceService;
        $this->callService     = $callService;
        $this->syncService     = $syncService;
        $this->entityManager   = $entityManager;
        $this->mappingService  = $mappingService;

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
     * Handles the synchronization of zgw zaken.
     *
     * @param array $data
     * @param array $configuration
     *
     * @throws CacheException|InvalidArgumentException
     *
     * @return array
     */
    public function syncZGWZakenHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('SyncZGWZakenService triggered');

        $sourceRef = 'https://commongateway.woo.nl/source/zgw.source.json';
        $source    = $this->resourceService->getSource($sourceRef, 'common-gateway/pdd-bundle');
        if ($source === null) {
            isset($this->style) === true && $this->style->error("$sourceRef not found.");
            return [];
        }

        $schemaRef = 'https://commongateway.nl/pdd.openWOO.schema.json';
        $schema    = $this->resourceService->getSchema($schemaRef, 'common-gateway/pdd-bundle');
        if ($schema === null) {
            isset($this->style) === true && $this->style->error("$schemaRef not found.");
            return [];
        }

        $mappingRef = 'https://commongateway.nl/mapping/pdd.zgwZaakToWoo.mapping.json';
        $mapping    = $this->resourceService->getMapping($mappingRef, 'common-gateway/pdd-bundle');
        if ($mapping === null) {
            isset($this->style) === true && $this->style->error("$mappingRef not found.");
            return [];
        }

        $sourceConfig = $source->getConfiguration();

        isset($this->style) === true && $this->style->info("Fetching cases from {$source->getLocation()}");

        $response        = $this->callService->call($source, '/zrc/v1/zaken', 'GET', $sourceConfig);
        $decodedResponse = $this->callService->decodeResponse($source, $response);

        $zaken = [];
        foreach ($decodedResponse['result'] as $result) {
            $result           = $this->mappingService->mapping($mapping, $result);
            $hydrationService = new HydrationService($this->syncService, $this->entityManager);
            $object           = $hydrationService->searchAndReplaceSynchronizations(
                $result,
                $source,
                $schema,
                true,
                true
            );
            $zaken[] = $object;
        }

        $this->data['response'] = new Response(json_encode($responseItems), 200);

        isset($this->style) === true && $this->style->success("Synchronized cases to woo objects.");

        return $this->data;

    }//end syncCasesHandler()


}//end class
