<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Value;
use App\Entity\Endpoint;
use App\Entity\File;
use CommonGateway\CoreBundle\Service\CallService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Smalot\PdfParser\Parser;

/**
 * Service responsible for basic woo functionality and re-usable functions for the other WooBundle services.
 *
 * @author  Conduction BV <info@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class WooService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;


    /**
     * FileService constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface        $pluginLogger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->logger        = $pluginLogger;

    }//end __construct()


    /**
     * Checks if existing objects still exist in the source, if not deletes them.
     *
     * @param array       $idsSynced ID's from objects we just synced from the source.
     * @param Source      $source    These objects belong to.
     * @param string      $schemaRef These objects belong to.
     * @param string|null $categorie The categorie these objects came from.
     *
     * @return int Count of deleted objects.
     */
    public function deleteNonExistingObjects(array $idsSynced, Source $source, string $schemaRef, string $categorie=null): int
    {
        // Get all existing sourceIds.
        $source            = $this->entityManager->find('App:Gateway', $source->getId()->toString());
        $existingSourceIds = [];
        $existingObjects   = [];
        foreach ($source->getSynchronizations() as $synchronization) {
            if ($synchronization->getEntity()->getReference() === $schemaRef && $synchronization->getSourceId() !== null
                && ($categorie === null || $synchronization->getObject()->getValue('categorie') === $categorie)
            ) {
                $existingSourceIds[] = $synchronization->getSourceId();
                $existingObjects[]   = $synchronization->getObject();
            }
        }

        // Check if existing sourceIds are in the array of new synced sourceIds.
        $objectIdsToDelete = array_diff($existingSourceIds, $idsSynced);

        // If not it means the object does not exist in the source anymore and should be deleted here.
        $deletedObjectsCount = 0;
        foreach ($objectIdsToDelete as $key => $id) {
            $this->logger->info("Object $id does not exist at the source, deleting.", ['plugin' => 'common-gateway/woo-bundle']);
            try {
                $this->entityManager->remove($existingObjects[$key]);
                $deletedObjectsCount++;
            } catch (Exception $e) {
                $this->logger->error("Something went wrong deleting object ({$existingObjects[$key]->getId()->toString()}) with sourceId: {$existingObjects[$key]->getSynchronizations()[0]->getSourceId()} with error: {$e->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                isset($this->style) === true && $this->style->error("Something went wrong deleting object ({$existingObjects[$key]->getId()->toString()}) with sourceId: {$existingObjects[$key]->getSynchronizations()[0]->getSourceId()} with error: {$e->getMessage()}");
            }
        }

        $this->entityManager->flush();

        return $deletedObjectsCount;

    }//end deleteNonExistingObjects()


    /**
     * Validates if the Configuration array has the required keys (with a value set).
     * Will check a default list of keys ('source','oin','organisatie','portalUrl','schema','mapping','sourceEndpoint'), more keys to check can be given.
     *
     * @param array|null $requiredKeys More keys to check besides the default keys, will default to empty array.
     * @param string     $handlerName  A string used to describe de type of sync we are ending when an error occurs, used when creating a log.
     *
     * @return bool True if all keys are present, else this will return false.
     */
    public function validateHandlerConfig(array $configuration, ?array $requiredKeys=[], string $handlerName='sync OpenWoo'): bool
    {
        $defaultRequired = [
            'source',
            'oin',
            'organisatie',
            'portalUrl',
            'schema',
            'mapping',
        ];

        $requiredKeys = array_merge($defaultRequired, $requiredKeys);

        foreach ($requiredKeys as $key) {
            if (isset($configuration[$key]) === false) {
                $keys = implode(', ', array_slice($requiredKeys, 0, -1)).' or '.end($requiredKeys);

                isset($this->style) === true && $this->style->error("No $keys configured on this action, ending $handlerName");
                $this->logger->error('No source, schema, mapping, oin, organisationId, organisatie, sourceEndpoint or portalUrl configured on this action, ending '.$handlerName, ['plugin' => 'common-gateway/woo-bundle']);

                return false;
            }
        }

        return true;

    }//end validateHandlerConfig()


}//end class
