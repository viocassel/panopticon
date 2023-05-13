<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Panopticon\CliCommand;

defined('AKEEBA') || die;

use Akeeba\Panopticon\CliCommand\Trait\ForkedLoggerAwareTrait;
use Akeeba\Panopticon\Factory;
use Akeeba\Panopticon\Library\Task\CallbackInterface;
use Akeeba\Panopticon\Library\Task\Status;
use Akeeba\Panopticon\Task\LogRotate as LogRotateTask;
use Awf\Registry\Registry;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
	name: "site:info:refresh",
	description: "Update the cached site information"
)]
class SiteInfoRefresh extends AbstractCommand
{
	use ForkedLoggerAwareTrait;

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		/** @var LogRotateTask|CallbackInterface $callback */
		$container = Factory::getContainer();
		$callback  = $container->taskRegistry->get('refreshsiteinfo');

		if ($callback instanceof LoggerAwareInterface)
		{
			$callback->setLogger(
				$this->getForkedLogger(
					$output,
					[
						$container->loggerFactory->get('get_site_info'),
					]
				)
			);
		}

		$dummy    = new \stdClass();
		$registry = new Registry();

		$registry->set('limitStart', 0);
		$registry->set('limit', $input->getOption('batchSize'));
		$registry->set('force', $input->getOption('force'));
		$registry->set('filter.ids', $input->getOption('id'));

		do
		{
			$return = $callback($dummy, $registry);
		} while ($return === Status::WILL_RESUME->value);

		return Command::SUCCESS;
	}

	protected function configure(): void
	{
		$this
			->addOption('force', 'f', InputOption::VALUE_NEGATABLE, 'Force update, regardless of last update time', false)
			->addOption('batchSize', null, InputOption::VALUE_OPTIONAL, 'Number of sites to retrieve concurrently', 10)
			->addOption('id', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Site IDs to update. Omit for all.', []);
	}

}