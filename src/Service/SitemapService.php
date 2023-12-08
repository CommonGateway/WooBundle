<?php

namespace CommonGateway\WOOBundle\Service;

use Adbar\Dot;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * Service responsible for woo sitemaps, sitemapindex and robot.txt.
 *
 * @author  Conduction BV <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
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
     * @var RequestStack $requestStack
     */
    private RequestStack $requestStack;

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
     * @param RequestStack           $requestStack       The Request Stack
     * @param LoggerInterface        $pluginLogger       The Logger Interface
     * @param GatewayResourceService $resourceService    The Gateway Resource Service
     * @param CacheService           $cacheService       The Cache Service
     * @param MappingService         $mappingService     The Mapping Service
     * @param DownloadService        $downloadService    The Download Service
     * @param ApplicationService     $applicationService The Application Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        DownloadService $downloadService,
        ApplicationService $applicationService
    ) {
        $this->entityManager      = $entityManager;
        $this->requestStack       = $requestStack;
        $this->logger             = $pluginLogger;
        $this->resourceService    = $resourceService;
        $this->cacheService       = $cacheService;
        $this->mappingService     = $mappingService;
        $this->downloadService    = $downloadService;
        $this->applicationService = $applicationService;

    }//end __construct()


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
            $this->logger->error('The type in the configuration of the action is not set.', ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'The type in the configuration of the action is not set.'], 409, 'error');
            return $this->data;
        }

        // Get the query from the call. This has to be any identification for an organization.
        $query = array_merge($this->data['path'], $this->data['query']);
        if (isset($query['oin']) === false) {
            $this->logger->error('The oin query parameter is missing.', ['plugin' => 'common-gateway/woo-bundle']);
            // Return the error message response.
            $this->data['response'] = $this->createResponse(['Message' => 'The oin query parameter is missing.'], 400, 'error');
            return $this->data;
        }

        switch ($this->configuration['type']) {
            case 'sitemap':
                return $this->getSitemap($query);
            case 'sitemapindex':
                return $this->getSitemapindex($query);
            case 'robot.txt':
                return $this->getRobot($query);
            default:
                $this->logger->error('Invalid action configuration type.', ['plugin' => 'common-gateway/woo-bundle']);
        }

        $this->data['response'] = $this->createResponse(['Message' => 'Invalid action configuration type.'], 409, 'error');
        return $this->data;

    }//end sitemapHandler()


    /**
     * Generates a sitemap for the given organization
     *
     * @param array $query The query array from the request.
     *
     * @return array Handler data with added 'response'.
     */
    private function getSitemap(array $query): array
    {
        // Get the publication schema and the sitemap mapping.
        $mapping          = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemap.mapping.json', 'common-gateway/woo-bundle');
        $publicatieSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.publicatie.schema.json', 'common-gateway/woo-bundle');
        if ($publicatieSchema instanceof Schema === false || $mapping instanceof Mapping === false) {
            $this->logger->error('The publication schema or the sitemap mapping cannot be found.', ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'The publication schema or the sitemap mapping cannot be found.'], 409, 'error');
            return $this->data;
        }

        $filter = array_merge(
            $query,
            [
                'organisatie.oin' => $query['oin'],
                '_limit'          => 50000,
            ]
        );


        unset($filter['oin'], $filter['sitemaps'], $filter['sitemap']);

        // Get all the publication objects with the given query.
        $objects = $this->cacheService->searchObjects(null, $filter, [$publicatieSchema->getId()->toString()])['results'];

        $sitemap = [];
        foreach ($objects as $object) {
            $publicatie['object'] = $this->entityManager->getRepository('App:ObjectEntity')->find($object['_id']);

            $mappedObject        = $this->mappingService->mapping($mapping, $publicatie);
            $mappedObject['loc'] = $this->nonAsciiUrlEncode($mappedObject['loc']);

            $sitemap['url'][] = $mappedObject;
        }

        // Return the sitemap response.
        $this->data['response'] = $this->createResponse($sitemap, 200, 'urlset');
        return $this->data;

    }//end getSitemap()


    /**
     * Generates a sitemapindex for the given organization
     *
     * @param array $path The query array from the request.
     *
     * @return array Handler data with added 'response'.
     */
    private function getSitemapindex(array $path): array
    {
        $mapping          = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.mapping.json', 'common-gateway/woo-bundle');
        $publicatieSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.publicatie.schema.json', 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.informatiecategorie.mapping.json', 'common-gateway/woo-bundle');

        if ($publicatieSchema instanceof Schema === false || $mapping instanceof Mapping === false || $categorieMapping instanceof Mapping === false) {
            $this->logger->error('The publication schema, the sitemap index mapping or categorie mapping cannot be found.');
            $this->data['response'] = $this->createResponse(['Message' => 'The publication schema, the sitemap index mapping or categorie mapping cannot be found.'], 409, 'error');
            return $this->data;
        }

        $filter = $path;
        if ($path['oin'] === '00000000000000000000') {
            $filter = array_merge($filter, ['organisatie.oin' => $path['oin']]);
        }
        unset($filter['oin']);

        $categorieStr = '';
        if (isset($path['sitemapindex']) === true) {
            $categorie    = $this->mappingService->mapping($categorieMapping, [$path['sitemapindex'] => '']);
            $categorieDot = new Dot($categorie);

            if ($categorieDot->has($path['sitemapindex']) === false) {
                $this->logger->error('Invalid informatiecategorie query parameter.');
                $this->data['response'] = $this->createResponse(['Message' => 'Invalid informatiecategorie query parameter.'], 400, 'error');
                return $this->data;
            }

            $filter['categorie'] = $categorieDot->get($path['sitemapindex']);
            $categorieStr        = 'categorie='.$categorieDot->get($path['sitemapindex']);
            unset($filter['sitemapindex']);
        }

        // Count all the publication objects with the given query.
        $count = $this->cacheService->countObjects(null, $filter, [$publicatieSchema->getId()->toString()]);
        $pages = ((int) (($count - 1) / 50000) + 1);

        // Get the domain of the request.
        $domain = $this->requestStack->getMainRequest()->getSchemeAndHttpHost();

        $sitemapindex = [];
        for ($i = 1; $i <= $pages; $i++) {
            // The location of the sitemap file is the endpoint of the sitemap.
            $location['location']      = $this->nonAsciiUrlEncode(
                $domain.'/api/sitemaps/'.$path['oin'].'/sitemap?'.$categorieStr.'&_page='.$i
            );
            $sitemapindex['sitemap'][] = $this->mappingService->mapping($mapping, $location);
        }

        // Return the sitemapindex response.
        $this->data['response'] = $this->createResponse($sitemapindex, 200, 'sitemapindex');
        return $this->data;

    }//end getSitemapindex()


    /**
     * Generates a robot.txt for the given organization
     *
     * @param array $query The query array from the request.
     *
     * @return array Handler data with added 'response'.
     */
    private function getRobot(array $query): array
    {
        $sitemapSchema    = $this->resourceService->getSchema('https://commongateway.nl/woo.sitemap.schema.json', 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.informatiecategorie.mapping.json', 'common-gateway/woo-bundle');
        if ($sitemapSchema instanceof Schema === false || $categorieMapping instanceof Mapping === false) {
            $this->logger->error('The sitemap schema or categorie mapping cannot be found.', ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'The sitemap schema or categorie mapping cannot be found.'], 409, 'error');
            return $this->data;
        }

        $categories = array_keys($categorieMapping->getMapping());

        // Get the domain of the request.
        $domain = $this->requestStack->getMainRequest()->getSchemeAndHttpHost();

        // todo: Don't think we need this here
        // $robotArray['locations'][] = $this->nonAsciiUrlEncode(
        // $domain.'/api/sitemapindex-diwoo-infocat?oin='.$query['oin'],
        // false
        // );
        foreach ($categories as $category) {
            // The location of the robot.txt file is the endpoint of the sitemapindex.
            $robotArray['locations'][] = $this->nonAsciiUrlEncode(
                $domain.'/api/sitemaps/'.$query['oin'].'/'.$category,
                false
            );
        }

        // Set the id of the schema to the array so that the downloadService can work with that.
        $robotArray['_self']['schema']['id'] = $sitemapSchema->getId()->toString();
        $robot                               = $this->downloadService->render($robotArray);

        $this->data['response'] = new Response($robot, 200, ['Content-Type' => 'text/plain']);
        $this->data['response']->headers->set('Content-Disposition', 'attachment; filename="Robot.txt"');

        return $this->data;

    }//end getRobot()


    /**
     * URL encodes all characters in a string that are non ASCII characters.
     * And does a htmlspecialchars() on $str after that unless $htmlspecialchars is set to false.
     *
     * @param string $str              The input string.
     * @param bool   $htmlspecialchars True by default, if set to false htmlspecialchars() will not be used on $str.
     *
     * @return string The updated string.
     */
    private function nonAsciiUrlEncode(string $str, bool $htmlspecialchars=true): string
    {
        $str = preg_replace_callback(
            '/[^\x20-\x7e]/',
            function ($matches) {
                return urlencode($matches[0]);
            },
            $str
        );

        if ($htmlspecialchars === false) {
            return $str;
        }

        return htmlspecialchars($str);

    }//end nonAsciiUrlEncode()


    /**
     * Creates a response based on content.
     *
     * @param array  $content  The content to incorporate in the response
     * @param int    $status   The status code of the response
     * @param string $rootName The rootName of the xml.
     *
     * @return Response
     */
    private function createResponse(array $content, int $status, string $rootName): Response
    {
        $this->logger->debug('Creating XML response', ['plugin' => 'common-gateway/woo-bundle']);
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => $rootName]);
        $xml        = ['@xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9'];
        $content    = array_merge($xml, $content);

        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        // Remove CDATA
        $contentString = str_replace(["<![CDATA[", "]]>"], "", $contentString);

        return new Response($contentString, $status);

    }//end createResponse()


}//end class
