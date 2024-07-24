<?php

namespace CommonGateway\WOOBundle\Command;

use App\Entity\Action;
use App\Entity\User;
use CommonGateway\WOOBundle\Service\SyncXxllncService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Ramsey\Uuid\Uuid;

/**
 * This class handles the command for the synchronization of woo objects.
 *
 * This Command can execute multiple services.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @package  CommonGateway\WOOBundle
 * @category Command
 */
class SyncXxllncCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'woo:xxllnc:synchronize';

    /**
     * The case service.
     *
     * @var SyncXxllncService
     */
    private SyncXxllncService $syncXxllncService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SessionInterface
     */
    private SessionInterface $session;


    /**
     * Class constructor.
     *
     * @param SyncXxllncService      $syncXxllncService The case service
     * @param EntityManagerInterface $entityManager     The entity manager.
     * @param SessionInterface       $session           The session interface
     */
    public function __construct(
        SyncXxllncService $syncXxllncService,
        EntityManagerInterface $entityManager,
        SessionInterface $session
    ) {
        $this->syncXxllncService = $syncXxllncService;
        $this->entityManager     = $entityManager;
        $this->session           = $session;
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
            );

    }//end configure()


    /**
     * Executes SyncXxllncService->syncXxllncCasesHandler or SyncXxllncService->getCase if a id is given.
     *
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        // $this->syncXxllncService->setStyle($style);
        if (($actionRef = $input->getArgument('action')) === null
        ) {
            $style->error("No action reference given to the command");

            return Command::FAILURE;
        }

        $action = $this->entityManager->getRepository('App:Action')->findOneBy(['reference' => $actionRef]);
        if ($action instanceof Action === false) {
            $style->error("Action with reference $actionRef not found");

            return Command::FAILURE;
        }

        $this->session->remove('currentActionUserId');
        if ($action->getUserId() !== null && Uuid::isValid($action->getUserId()) === true) {
            $user = $this->entityManager->getRepository('App:User')->find($action->getUserId());
            if ($user instanceof User === true) {
                $this->session->set('currentActionUserId', $action->getUserId());
            }
        }

        $config     = $action->getConfiguration();
        $startTimer = microtime(true);

        $actionResult = $this->syncXxllncService->syncXxllncCaseHandler([], $config);

        if ($actionResult !== null) {
            $action->setLastRun(new DateTime());
        }

        $stopTimer = microtime(true);
        $totalTime = ($stopTimer - $startTimer);
        $action->setLastRunTime($totalTime);
        $action->setStatus(true);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        if ($actionResult === null) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
