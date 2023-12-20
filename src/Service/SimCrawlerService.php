<?php

namespace CommonGateway\WOOBundle\Service;

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
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->resourceService = $resourceService;
        $this->mappingService = $mappingService;
    }

    public function SimSiteHandler(array $data, array $configuration): array
    {
        $source = $this->resourceService->getSource($configuration['source']);
        $schema = $this->resourceService->getSchema($configuration['schema']);
        $sitemapMapping = $this->resourceService->getMapping($configuration['sitemapMapping']);
        $pageMapping = $this->resourceService->getMapping($configuration['pageMapping']);

        $sitemapResponse = $this->callService->call($source, 'sitemap.xml');
        $sitemap = $this->callService->decodeBody($source, $sitemapResponse);

        $pages = $this->mappingService->mapping($sitemap, $sitemapMapping);

        foreach ($pages as $page) {
            $metaDataResponse = $this->callService->call($source, $configuration['sourceLocation'], 'GET', ['query' => ['path' => $page]]);
            $metadata = $this->callService->decodeResponse($source, $metaDataResponse);

            $wooArray = $this->mappingService->mapping($metadata, $pageMapping);

            $wooArray['organisatie'] = ['oin' => $configuration['oin'], 'naam' => $configuration['organisatie']];

            $wooObject = new ObjectEntity($schema);
            $wooObject->hydrate($wooArray);

            $this->entityManager->persist($wooObject);
        }

        $this->entityManager->flush();

        return $data;
    }
}