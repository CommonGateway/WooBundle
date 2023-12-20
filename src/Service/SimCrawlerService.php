<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;

class SimCrawlerService
{

    private GatewayResourceService $resourceService;

    private CallService $callService;

    private MappingService $mappingService;

    private EntityManagerInterface $entityManager;


    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GatewayResourceService $resourceService,
        MappingService $mappingService
    ) {
        $this->callService     = $callService;
        $this->entityManager   = $entityManager;
        $this->resourceService = $resourceService;
        $this->mappingService  = $mappingService;

    }//end __construct()


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

            $metaDataResponse = $this->callService->call($source, $configuration['sourceLocation'], 'GET', ['query' => ['path' => $parsedUrl['path']]]);
            $metadata         = $this->callService->decodeResponse($source, $metaDataResponse);

            $metadata['site'] = $source->getLocation();

            $wooArray = $this->mappingService->mapping($pageMapping, $metadata);

            $wooArray['organisatie'] = [
                'oin'  => $configuration['oin'],
                'naam' => $configuration['organisatie'],
            ];

            $wooObject = new ObjectEntity($schema);
            $wooObject->hydrate($wooArray);

            $this->entityManager->persist($wooObject);
        }

        $this->entityManager->flush();

        return $data;

    }//end SimSiteHandler()


}//end class
