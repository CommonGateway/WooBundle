<?php

namespace CommonGateway\WOOBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\WOOBundle\Service\SyncXxllncCasesService;

/**
 * Service responsible for woo notifications.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class NotificationService
{

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
     * @var GatewayResourceService $resourceService.
     */
    private GatewayResourceService $resourceService;

    /**
     * @var SyncXxllncCasesService $syncXxllncCasesService.
     */
    private SyncXxllncCasesService $syncXxllncCasesService;

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
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface        $pluginLogger
     * @param GatewayResourceService $resourceService
     * @param SyncXxllncCasesService $syncXxllncCasesService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        SyncXxllncCasesService $syncXxllncCasesService
    ) {
        $this->entityManager          = $entityManager;
        $this->logger                 = $pluginLogger;
        $this->resourceService        = $resourceService;
        $this->syncXxllncCasesService = $syncXxllncCasesService;
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
     * Handles the zaaksysteem notification.
     *
     * @param array $data          The data passed by the action.
     * @param array $configuration The configuration of the action.
     *
     * @return array
     */
    public function zaaksysteemNotificationHandler(array $data, array $configuration): array
    {
        if ($data['method'] !== 'POST') {
            return $data;
        }

        $this->data = $data;
        $this->configuration = $configuration;

        $this->logger->debug("WooBundle -> NotificationService -> zaaksysteemNotificationHandler()", ['plugin' => 'common-gateway/woo-bundle']);

        if (isset($data['body']['case_uuid']) === false || isset($data['body']['event_name']) === false) {
            $this->logger->error("Notification invalid, case_uuid or event_name is not given", ['plugin' => 'common-gateway/woo-bundle']);

            return $data;
        }

        if (isset($data['headers']['host'][0]) === false) {
            $this->logger->error("Notification invalid, host could not determine", ['plugin' => 'common-gateway/woo-bundle']);

            return $data;
        }

        $notification = $data['body'];
        $host = $data['headers']['host'][0];

        $host = 'http://' . $host;

        $source = $this->resourceService->findSourceForUrl($host, 'common-gateway/woo-bundle', $endpoint);
        if ($source === null) {
            $this->logger->error("Notification invalid, source could not be determined with received host: $host", ['plugin' => 'common-gateway/woo-bundle']);

            return $data;
        }

        $conn = $this->entityManager->getConnection();

        $sql = "
        SELECT * FROM action
        WHERE configuration LIKE '%oin%'
        AND configuration LIKE '%portalUrl%'
        AND configuration LIKE '%source%'
        AND configuration LIKE '%schema%'
        AND configuration LIKE '%mapping%'
        AND configuration LIKE '%organisatie%'
        AND configuration LIKE '%zaaksysteemSearchEndpoint%'
        AND configuration LIKE '%fileEndpointReference%'
        AND configuration LIKE '%{$source->getReference()}%';
        ";
        
        $params = ['sourceValue' => $source->getReference()];
        
        // Using executeQuery() for executing the query.
        $stmt = $conn->executeQuery($sql, $params);

        $actions = $stmt->fetchAllAssociative();
        if (empty($actions)) {
            $this->logger->error("Notification invalid, action could not be determined with found source: {$source->getReference()}", ['plugin' => 'common-gateway/woo-bundle']);

            return $data;
        }

        $action = $stmt->fetchAllAssociative()[0];

        $this->syncXxllncCasesService->syncCaseToPublicatie($action, $source);



        return ;

    }//end zaaksysteemNotificationHandler()


}//end class
