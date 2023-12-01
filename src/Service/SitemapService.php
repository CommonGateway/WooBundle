<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Value;
use App\Entity\Endpoint;
use App\Entity\File;
use App\Service\ApplicationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\DownloadService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * Service responsible for woo sitemaps, sitemapindex and robot.txt.
 *
 * @author  Conduction BV (info@conduction.nl), Sarai Misidjan (sarai@conduction.nl).
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SitemapService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;

    /**
     * @var GatewayResourceService $resourceService.
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CacheService $cacheService.
     */
    private CacheService $cacheService;

    /**
     * @var MappingService $mappingService.
     */
    private MappingService $mappingService;

    /**
     * @var DownloadService $downloadService.
     */
    private DownloadService $downloadService;

    /**
     * @var ApplicationService $applicationService.
     */
    private ApplicationService $applicationService;

    /**
     * @var array $data
     */
    private array $data;

    /**
     * @var array $configuration
     */
    private array $configuration;


    /**
     * SitemapService constructor.
     *
     * @param EntityManagerInterface $entityManager      The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger       The Logger Interface
     * @param GatewayResourceService $resourceService    The Gateway Resource Service
     * @param CacheService           $cacheService       The Cache Service
     * @param MappingService         $mappingService     The Mapping Service
     * @param DownloadService        $downloadService    The Download Service
     * @param ApplicationService     $applicationService The Application Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        DownloadService $downloadService,
        ApplicationService $applicationService
    ) {
        $this->entityManager      = $entityManager;
        $this->logger             = $pluginLogger;
        $this->resourceService    = $resourceService;
        $this->cacheService       = $cacheService;
        $this->mappingService     = $mappingService;
        $this->downloadService    = $downloadService;
        $this->applicationService = $applicationService;

    }//end __construct()


    /**
     * Generates a sitemap for the given organization
     *
     * @param string $queryKey The key of the query from the request.
     * @param array  $query    The query array from the request.
     *
     * @return array
     */
    public function getSitemap(string $queryKey, array $query): array
    {
        // TODO: Generate the sitemaps with the type.
        // Get the publication schema and the sitemap mapping.
        $mapping          = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemap.mapping.json', 'common-gateway/woo-bundle');
        $publicatieSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.publicatie.schema.json', 'common-gateway/woo-bundle');
        if ($publicatieSchema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            $this->logger->error('The publication schema or the sitemap mapping cannot be found.');
            return $this->data;
        }

        // Get all the publication objects with the given query.
        $objects = $this->cacheService->searchObjects(
            null,
            ['organisatie.'.$queryKey => $query[$queryKey]],
            [$publicatieSchema->getId()->toString()]
        )['results'];

        $sitemap = [];
        foreach ($objects as $object) {
            // TODO: Verschillede sitemaps voor de categorieen
            $publicatie['object'] = $this->entityManager->getRepository('App:ObjectEntity')->find($object['_id']);
            $sitemap['url'][]     = $this->mappingService->mapping($mapping, $publicatie);
        }

        // Return the sitemap response.
        $this->data['response'] = $this->createResponse($sitemap, 200, 'urlset');
        return $this->data;

    }//end getSitemap()


    /**
     * Generates a sitemapindex for the given organization
     *
     * @param string $queryKey The key of the query from the request.
     * @param array  $query    The query array from the request.
     *
     * @return array
     */
    public function getSitemapindex(string $queryKey, array $query): array
    {
        $mapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.mapping.json', 'common-gateway/woo-bundle');
        if ($mapping instanceof Mapping === false) {
            return $this->data;
        }

        // Get the domain of the request.
        $domain = $this->applicationService->getApplication()->getDomains()[0];

        // TODO: Get the type of the sitemapindex.
        // The location of the sitemap file is the endpoint of the sitemap.
        $location['location']      = 'https://'.$domain.'/api/sitemaps?'.$queryKey.'='.$query[$queryKey];
        $sitemapindex['sitemap'][] = $this->mappingService->mapping($mapping, $location);

        // Return the sitemapindex response.
        $this->data['response'] = $this->createResponse($sitemapindex, 200, 'sitemapindex');
        return $this->data;

    }//end getSitemapindex()


    /**
     * Generates a robot.txt for the given organization
     *
     * @param string $queryKey The key of the query from the request.
     * @param array  $query    The query array from the request.
     *
     * @return array
     */
    public function getRobot(string $queryKey, array $query): array
    {
        $mapping       = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.robot.txt.mapping.json', 'common-gateway/woo-bundle');
        $sitemapSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.sitemap.schema.json', 'common-gateway/woo-bundle');
        if ($sitemapSchema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            $this->logger->error('The publication schema or the sitemap mapping cannot be found.');
            return $this->data;
        }

        // Get the domain of the request.
        $domain = $this->applicationService->getApplication()->getDomains()[0];

        // The location of the robot.txt file is the endpoint of the sitemapindex.
        // TODO: Get the type of the sitemapindex.
        $robotArray['location'] = 'https://'.$domain.'/api/sitemapindex-diwoo-infocat?'.$queryKey.'='.$query[$queryKey];
        // Set the id of the schema to the array so that the downloadService can work with that.
        $robotArray['_self']['schema']['id'] = $sitemapSchema->getId()->toString();
        $robot                               = $this->downloadService->render($robotArray);

        $this->data['response'] = new Response($robot, 200, ['Content-Type' => 'text/plain']);
        $this->data['response']->headers->set('Content-Disposition', 'attachment; filename="Robot.txt"');

        return $this->data;

    }//end getRobot()


    /**
     * Generates a sitemap, sitemapindex or robot.txt for the given organization
     *
     * @param array $data          The data passed by the action.
     * @param array $configuration The configuration of the action.
     *
     * @return array
     */
    public function sitemapHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the type from the action so that we know what to generate.
        if (key_exists('type', $this->configuration) === false) {
            $this->logger->error('The type in the configuration of the action is not given.');
            return $this->data;
        }

        // Get the query from the call. This has to be any identification for a organization.
        $query = $this->data['query'];
        if (count($query) !== 1) {
            $this->logger->error('There are more or null query items given.');
        }

        // Get the key of the given query.
        $queryKey = key($query);
        switch ($this->configuration['type']) {
        case 'sitemap':
            return $this->getSitemap($queryKey, $query);
                break;
        case 'sitemapindex':
            return $this->getSitemapindex($queryKey, $query);
                break;
        case 'robot.txt':
            return $this->getRobot($queryKey, $query);
                break;
        default:
            // Throw an error.
        }

        return $this->data;

    }//end sitemapHandler()


    /**
     * Creates a response based on content.
     *
     * @param array  $content  The content to incorporate in the response
     * @param int    $status   The status code of the response
     * @param string $rootName The rootName of the xml.
     *
     * @return Response
     */
    public function createResponse(array $content, int $status, string $rootName): Response
    {
        $this->logger->debug('Creating XML response');
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => $rootName]);
        $xml        = ['@xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9'];
        $content    = array_merge($xml, $content);

        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status);

    }//end createResponse()


}//end class
