<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
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
use App\Entity\Gateway as Source;
use Exception;

/**
 * Service responsible for synchronizing TIP cases to woo objects.
 *
 * @package  CommonGateway\WOOBundle
 * @license  EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @author   Acato BV <yoeri@acato.nl>
 * @category Service
 */
class SyncTilburgCasesService {

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
	 * SyncTilburgCasesService constructor.
	 *
	 * @param GatewayResourceService $resourceService
	 * @param CallService            $callService
	 * @param SynchronizationService $syncService
	 * @param EntityManagerInterface $entityManager
	 * @param MappingService         $mappingService
	 * @param LoggerInterface        $pluginLogger
	 * @param ValidationService      $validationService
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
		WooService $wooService
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
	public function setStyle( SymfonyStyle $style ): self {
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
	 * @throws CacheException|InvalidArgumentException
	 *
	 */
	public function SyncTilburgCasesHandler( array $data, array $configuration ): array {
		$this->data          = $data;
		$this->configuration = $configuration;

		isset( $this->style ) === true && $this->style->success( 'SyncTilburgCasesService triggered' );
		$this->logger->info( 'SyncTilburgCasesService triggered', [ 'plugin' => 'common-gateway/woo-bundle' ] );

		$source = $this->resourceService->getSource( $this->configuration['source'], 'common-gateway/woo-bundle' );
		$this->logger->info( 'SyncTilburg source', [ 'source' => $source ] );

		return $this->data;

	}//end SyncTilburgCasesHandler()


}//end class
