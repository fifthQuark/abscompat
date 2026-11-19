<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Command;

use OCA\AbsCompat\Service\LibraryService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Scan extends Command {
	public function __construct(
		private LibraryService $library,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('abscompat:scan')
			->setDescription('Scan audiobook library folders')
			->addArgument('user', InputArgument::IS_ARRAY, 'User ids (default: all users)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$users = $input->getArgument('user');
		if ($users === []) {
			$this->userManager->callForSeenUsers(function ($u) use (&$users): void {
				$users[] = $u->getUID();
			});
		}
		foreach ($users as $uid) {
			if (!$this->userManager->userExists($uid)) {
				$output->writeln("<error>Unknown user $uid</error>");
				continue;
			}
			$s = $this->library->scanUser($uid);
			$output->writeln(sprintf('%s: %d books (%d removed)', $uid, $s['items'], $s['removed']));
		}
		return 0;
	}
}
