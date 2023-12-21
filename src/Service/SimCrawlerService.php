<?php
/**
 * Service to crawl SIMsite websites and store pages as WOO publications.
 *
 * This service provides a crawler to store pages on SIMsite websites as WOO publications.
 *
 * @Author Ruben van der Linde <ruben@conduction.nl>, Robert Zondervan <robert@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>, Barry Brands <barry@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
namespace CommonGateway\WOOBundle\Service;

use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;

class SimCrawlerService
{

    /**
     * The gateway resource service.
     *
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * The call service.
     *
     * @var CallService
     */
    private CallService $callService;

    /**
     * The mapping service.
     *
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * The entity manager.
     *
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * The synchronization service.
     *
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;


    /**
     * Service constructor.
     *
     * @param CallService            $callService            The call service.
     * @param EntityManagerInterface $entityManager          The entity manager.
     * @param GatewayResourceService $resourceService        The resource service.
     * @param MappingService         $mappingService         The mapping service.
     * @param SynchronizationService $synchronizationService The synchronization service.
     */
    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GatewayResourceService $resourceService,
        MappingService $mappingService,
        SynchronizationService $synchronizationService
    ) {
        $this->callService            = $callService;
        $this->entityManager          = $entityManager;
        $this->resourceService        = $resourceService;
        $this->mappingService         = $mappingService;
        $this->synchronizationService = $synchronizationService;

    }//end __construct()


    /**
     * Retrieve a sitemap.xml from a source, parse it into a list of paths, fetch metadata and map it into publications.
     *
     * @param array $data          The data retrieved by the action.
     * @param array $configuration The configuration of the action.
     *
     * @return array The resulting data array.
     */
    public function SimSiteHandler(array $data, array $configuration): array
    {
        $source         = $this->resourceService->getSource($configuration['source'], 'common-gateway/woo-bundle');
        $schema         = $this->resourceService->getSchema($configuration['schema'], 'common-gateway/woo-bundle');
        $sitemapMapping = $this->resourceService->getMapping($configuration['sitemapMapping'], 'common-gateway/woo-bundle');
        $pageMapping    = $this->resourceService->getMapping($configuration['pageMapping'], 'common-gateway/woo-bundle');

        $sitemapResponse = $this->callService->call($source, '/sitemap.xml');
        $sitemap         = $this->callService->decodeResponse($source, $sitemapResponse, 'application/xml');

        $pages = $this->mappingService->mapping($sitemapMapping, $sitemap)['pages'];

        foreach ($pages as $page) {
            $parsedUrl = parse_url($page);

            $metaDataResponse = $this->callService->call($source, $configuration['sourceEndpoint'], 'GET', ['query' => ['path' => $parsedUrl['path']]]);
            $metadata         = $this->callService->decodeResponse($source, $metaDataResponse);
            $metadata['site'] = $source->getLocation();

            $wooArray = $this->mappingService->mapping($pageMapping, $metadata);

            $wooArray['organisatie'] = [
                'oin'  => $configuration['oin'],
                'naam' => $configuration['organisatie'],
            ];

            $synchronization = $this->synchronizationService->findSyncBySource($source, $schema, $page);

            if ($synchronization->getObject() === null) {
                $synchronization->setObject(new ObjectEntity($schema));
            }

            $synchronization->getObject()->hydrate($wooArray);
            $this->entityManager->persist($synchronization->getObject());

            $synchronization->setLastSynced(new \DateTime('now'));
            $this->entityManager->persist($synchronization);

            $this->entityManager->flush();
        }//end foreach

        return $data;

    }//end SimSiteHandler()


}//end class
