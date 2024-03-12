<?php

namespace CommonGateway\WOOBundle\Command;

use App\Entity\Action;
use CommonGateway\WOOBundle\Service\SyncXxllncCasesService;
use CommonGateway\WOOBundle\Service\SyncOpenWooService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This class handles the command for the synchronization of woo objects.
 *
 * This Command can execute multiple services.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @package  CommonGateway\WOOBundle
 * @category Command
 */
class SyncWooCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'woo:objects:synchronize';

    /**
     * The case service.
     *
     * @var SyncXxllncCasesService
     */
    private SyncXxllncCasesService $syncXxllncCasesService;

    /**
     * The OpenWoo service.
     *
     * @var SyncOpenWooService
     */
    private SyncOpenWooService $syncOpenWooService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;


    /**
     * Class constructor.
     *
     * @param SyncXxllncCasesService $syncXxllncCasesService The case service
     * @param SyncOpenWooService     $syncOpenWooService     The OpenWoo service
     */
    public function __construct(SyncXxllncCasesService $syncXxllncCasesService, SyncOpenWooService $syncOpenWooService, EntityManagerInterface $entityManager)
    {
        $this->syncXxllncCasesService = $syncXxllncCasesService;
        $this->syncOpenWooService     = $syncOpenWooService;
        $this->entityManager          = $entityManager;
        parent::__construct();

    }//end __construct()


    /**
     * Configures this command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command can trigger multiple types of synchronization')
            ->setHelp('This command can trigger multiple types of synchronization')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action reference to find the action and execute for different organizations (municipalities)'
            )
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'Case id to fetch'
            );

    }//end configure()


    /**
     * Executes syncXxllncCasesService->syncXxllncCasesHandler or syncXxllncCasesService->getCase if a id is given.
     *
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->syncXxllncCasesService->setStyle($style);

        if (($actionRef = $input->getArgument('action')) === null
        ) {
            $style->error("No id and/or caseId given to the command");

            return Command::FAILURE;
        }

        $caseId = $input->getArgument('id');

        $action = $this->entityManager->getRepository('App:Action')->findOneBy(['reference' => $actionRef]);
        if ($action instanceof Action === false) {
            $style->error("Action with reference $actionRef not found");

            return Command::FAILURE;
        }

        if (Uuid::isValid($caseId) === true
        ) {
            // if ($this->syncXxllncCasesService->getZaak($action->getConfiguration(), $caseId) === true) {
            // return Command::FAILURE;
            // }
            isset($style) === true && $style->error("Single object synchronization not supported yet.");

            return Command::FAILURE;
        }//end if

        $config = $action->getConfiguration();
        $startTimer = microtime(true);
        if (isset($config['sourceType']) === true && $config['sourceType'] === 'openWoo') {
            $this->syncOpenWooService->setStyle($style);
            if ($this->syncOpenWooService->syncOpenWooHandler([], $config) === null) {
                $stopTimer = microtime(true);
                $totalTime = ($stopTimer - $startTimer);
                $action->setLastRunTime($totalTime);
                $action->setStatus(false);
                $this->entityManager->persist($action);
                $this->entityManager->flush();
                
                return Command::FAILURE;
            }
        } else {
            if ($this->syncXxllncCasesService->syncXxllncCasesHandler([], $config) === null) {
                $stopTimer = microtime(true);
                $totalTime = ($stopTimer - $startTimer);
                $action->setLastRunTime($totalTime);
                $action->setStatus(false);
                $this->entityManager->persist($action);
                $this->entityManager->flush();
                
                return Command::FAILURE;
            }
        }
        
        $stopTimer = microtime(true);
        $totalTime = ($stopTimer - $startTimer);
        $action->setLastRun(new DateTime());
        $action->setLastRunTime($totalTime);
        $action->setStatus(true);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        return Command::SUCCESS;

    }//end execute()


}//end class
