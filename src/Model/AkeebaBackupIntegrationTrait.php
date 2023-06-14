<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Model;

defined('AKEEBA') || die;

use Akeeba\BackupJsonApi\Connector;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientGuzzle;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Akeeba\BackupJsonApi\Options as JsonApiOptions;
use Akeeba\Panopticon\Container;
use Akeeba\Panopticon\Library\Cache\CallbackController;
use Akeeba\Panopticon\Library\Task\Status;
use Akeeba\Panopticon\Model\Exception\AkeebaBackupCannotConnectException;
use Akeeba\Panopticon\Model\Exception\AkeebaBackupIsNotPro;
use Akeeba\Panopticon\Model\Exception\AkeebaBackupNoInfoException;
use Awf\Date\Date;
use Composer\CaBundle\CaBundle;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use stdClass;
use Throwable;

trait AkeebaBackupIntegrationTrait
{
	private ?CallbackController $callbackControllerForAkeebaBackup = null;

	/**
	 * Test the connection to the remote site's Akeeba Backup installation.
	 *
	 * First, we use the API to get information about whether Akeeba Backup is installed and has the JSON API available.
	 *
	 * If so, we test the endpoints returned by the API to see to which one and how we can connect.
	 *
	 * @param   bool  $withEndpoints  Should I also test the endpoints?
	 *
	 * @return  bool  True if the site's configuration must be saved.
	 * @throws GuzzleException If the API request fails.
	 * @since   1.0.0
	 */
	public function testAkeebaBackupConnection(bool $withEndpoints = true): bool
	{
		/** @var \Akeeba\Panopticon\Container $container */
		$container = $this->container;
		$client    = $container->httpFactory->makeClient(cache: false, singleton: false);

		[$url, $options] = $this->getRequestOptions($this, '/index.php/v1/panopticon/akeebabackup/info');

		$options[RequestOptions::HTTP_ERRORS] = false;

		$response = $client->get($url, $options);

		$refreshResponse = (object) [
			'statusCode'   => $response->getStatusCode(),
			'reasonPhrase' => $response->getReasonPhrase(),
			'body'         => $response->getBody()->getContents() ?? '',
		];

		try
		{
			$results = @json_decode($refreshResponse->body ?? '{}');
		}
		catch (Throwable)
		{
			$results = null;
		}

		$config      = $this->getConfig();
		$info        = $results?->data?->attributes ?? null;
		$currentInfo = $config->get('akeebabackup.info') ?: new stdClass();
		$dirtyFlag   = false;

		$hasUpdatedInfo = array_reduce(
			['installed', 'version', 'api', 'secret', 'endpoints'],
			function (bool $carry, $key) use ($info, $currentInfo) {
				if ($carry)
				{
					return true;
				}

				$current = $currentInfo?->{$key} ?? null;
				$new     = $info?->{$key} ?? null;

				if (is_array($current))
				{
					$current = (object) $current;
				}

				if (is_array($new))
				{
					$new = (object) $new;
				}

				return $current != $new;
			},
			false
		);

		if ($hasUpdatedInfo || empty($info))
		{
			$config->set('akeebabackup.info', $info);
			$config->set('akeebabackup.lastRefreshResponse', $refreshResponse);

			$dirtyFlag = true;
		}

		// If `installed` is not true we cannot proceed with auto-detection.
		if ($info?->installed !== true)
		{
			$config->set('akeebabackup.endpoint', null);

			$dirtyFlag = true;
		}
		elseif ($withEndpoints)
		{
			// Auto-detect best endpoint.
			$endpoints                = array_merge(
				$info?->endpoints?->v2 ?? [],
				$info?->endpoints?->v1 ?? []
			);
			$newEndpointConfiguration = null;

			foreach ($endpoints as $someEndpoint)
			{
				$options = new JsonApiOptions(
					[
						'capath' => defined('AKEEBA_CACERT_PEM') ? AKEEBA_CACERT_PEM
							: CaBundle::getBundledCaBundlePath(),
						'ua'     => 'panopticon/' . AKEEBA_PANOPTICON_VERSION,
						'host'   => $someEndpoint,
						'secret' => $info?->secret,
					]
				);

				$httpClient = new HttpClientGuzzle($options);
				$apiClient  = new Connector($httpClient);

				try
				{
					$apiClient->autodetect();
				}
				catch (Throwable)
				{
					continue;
				}

				$newEndpointConfiguration = (object) $httpClient->getOptions()->toArray();

				if (isset($newEndpointConfiguration->capath))
				{
					unset($newEndpointConfiguration->capath);
				}

				if (isset($newEndpointConfiguration->logger))
				{
					unset($newEndpointConfiguration->logger);
				}

				break;
			}

			$oldEndpointConfiguration = $config->get('akeebabackup.endpoint');

			if ($oldEndpointConfiguration != $newEndpointConfiguration)
			{
				$config->set('akeebabackup.endpoint', $newEndpointConfiguration);

				$dirtyFlag = true;
			}
		}

		// Commit any detected changes to the site object
		if ($dirtyFlag)
		{
			$this->setFieldValue('config', $config->toString());
		}

		return $dirtyFlag;
	}

	/**
	 * Is the Akeeba Backup package or component installed on this site?
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	public function hasAkeebaBackup(): bool
	{
		$config     = $this->getConfig();
		$extensions = (array) $config->get('extensions.list');
		$extensions = array_filter(
			$extensions,
			fn(object $ext) => in_array(
				$ext->element, ['pkg_akeebabackup', 'pkg_akeeba', 'com_akeebabackup', 'com_akeeba']
			)
		);

		return count($extensions) > 0;
	}

	/**
	 * Get a list of backup records.
	 *
	 * Each returned object has the following keys:
	 * - id
	 * - description
	 * - comment
	 * - backupstart
	 * - backupend
	 * - status
	 * - origin
	 * - type
	 * - profile_id
	 * - archivename
	 * - absolute_path
	 * - multipart
	 * - tag
	 * - backupid
	 * - filesexist
	 * - remote_filename
	 * - total_size
	 * - frozen
	 * - instep
	 * - meta
	 * - hasRemoteFiles
	 *
	 * @param   bool  $cache  Should I use a cache to speed things up?
	 * @param   int   $from   Skip this many records
	 * @param   int   $limit  Maximum number of records to display
	 *
	 * @return  object[]
	 * @throws  CacheException
	 * @throws  InvalidArgumentException
	 * @since   1.0.0
	 */
	public function akeebaBackupGetBackups(bool $cache = true, int $from = 0, int $limit = 200): array
	{
		$this->ensureAkeebaBackupConnectionOptions();

		return $this->getAkeebaBackupCacheController()->get(
			fn(Connector $connector, $from, $limit): array => $connector->getBackups($from, $limit),
			[
				$this->getAkeebaBackupAPIConnector(),
				$from,
				$limit,
			],
			sprintf('backupList-%d-%d-%d', $this->id, $from, $limit),
			$cache ? null : 0
		);
	}

	/**
	 * Retrieve a list of backup profiles
	 *
	 * @param   bool  $cache  Should I use a cache to speed things up?
	 *
	 * @return  array
	 * @throws  CacheException
	 * @throws  InvalidArgumentException
	 * @since   1.0.0
	 */
	public function akeebaBackupGetProfiles(bool $cache = true): array
	{
		$this->ensureAkeebaBackupConnectionOptions();

		return $this->getAkeebaBackupCacheController()->get(
			fn(Connector $connector): array => $connector->getProfiles(),
			[
				$this->getAkeebaBackupAPIConnector(),
			],
			sprintf(sprintf('profilesList-%d', $this->id)),
			$cache ? null : 0
		);
	}

	/**
	 * Starts taking a new backup.
	 *
	 * @param   int          $profile      The profile ID to use
	 * @param   string|null  $description  Backup description
	 * @param   string|null  $comment      Backup comment
	 *
	 * @return  object
	 * @throws  Throwable
	 * @since   1.0.0
	 */
	public function akeebaBackupStartBackup(int $profile = 1, ?string $description = null, ?string $comment = null
	): object
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$httpClient = $this->getAkeebaBackupAPIClient();

		$data = $httpClient->doQuery(
			'startBackup', [
				'profile'     => (int) $profile,
				'description' => $description ?: 'Remote backup',
				'comment'     => $comment,
			]
		);

		$info = $this->akeebaBackupHandleAPIResponse($data);

		$info->data = $data;

		return $info;
	}

	/**
	 * Continues taking a backup.
	 *
	 * @param   string|null  $backupId  The backup ID to continue stepping through.
	 *
	 * @return  object
	 * @throws  Throwable
	 * @since   1.0.0
	 */
	public function akeebaBackupStepBackup(?string $backupId): object
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$httpClient = $this->getAkeebaBackupAPIClient();
		$parameters = [];

		if (!empty($backupId))
		{
			$parameters['backupid'] = $backupId;
		}

		$data = $httpClient->doQuery('stepBackup', $parameters);
		$info = $this->akeebaBackupHandleAPIResponse($data);

		$info->data = $data;

		return $info;
	}

	/**
	 * Delete a backup record.
	 *
	 * @param   int  $id  The backup record to delete
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function akeebaBackupDelete(int $id): void
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$connector = $this->getAkeebaBackupAPIConnector();

		$connector->delete($id);
	}

	/**
	 * Delete a backup record's files from the web server.
	 *
	 * @param   int  $id  The backup record whose files will be deleted
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function akeebaBackupDeleteFiles(int $id): void
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$connector = $this->getAkeebaBackupAPIConnector();

		$connector->deleteFiles($id);
	}

	/**
	 * Enqueue a new backup
	 *
	 * @param   int          $profile
	 * @param   string|null  $description
	 * @param   string|null  $comment
	 *
	 * @return  void
	 */
	public function akeebaBackupEnqueue(int $profile = 1, ?string $description = null, ?string $comment = null): void
	{
		// Try to find an akeebabackup task object which is run once, not running / initial schedule, and matches the specifics
		$tasks = $this->getSiteSpecificTasks('akeebabackup')
			->filter(
				function (Task $task) {
					$params = $task->getParams();

					// Mast not be running, or waiting to run
					if (in_array(
						$task->last_exit_code, [
							Status::INITIAL_SCHEDULE->value,
							Status::WILL_RESUME->value,
							Status::RUNNING->value,
						]
					))
					{
						return false;
					}

					// Must be a run-once task
					if (empty($params->get('run_once')))
					{
						return false;
					}

					// Must be a generated task, not a user-defined backup schedule
					if (empty($params->get('run_once')))
					{
						return false;
					}

					if (empty($params->get('enqueued_backup')))
					{
						return false;
					}

					// Its next execution date must be empty or in the past
					if (empty($task->last_execution))
					{
						return true;
					}

					$date = new Date($task->last_execution, 'UTC');
					$now  = new Date();

					return ($date < $now);
				}
			);

		if ($tasks->count())
		{
			$task = $tasks->first();
		}
		else
		{
			$task = Task::getTmpInstance('', 'Task', $this->container);
		}

		try
		{
			$tz = $this->container->appConfig->get('timezone', 'UTC');

			// Do not remove. This tests the validity of the configured timezone.
			new DateTimeZone($tz);
		}
		catch (Exception)
		{
			$tz = 'UTC';
		}

		$runDateTime = new Date('now', $tz);
		$runDateTime->add(new \DateInterval('PT1M'));
		$runDateTime->setTime($runDateTime->hour, $runDateTime->minute, 0);

		$task->save(
			[
				'site_id'         => $this->getId(),
				'type'            => 'akeebabackup',
				'params'          => json_encode(
					[
						'run_once'        => 'disable',
						'enqueued_backup' => 1,
						'profile_id'      => $profile,
						'description'     => $description,
						'comment'         => $comment ?? '',
					]
				),
				'cron_expression' => $runDateTime->minute . ' ' . $runDateTime->hour . ' ' . $runDateTime->day . ' ' .
					$runDateTime->month . ' ' . $runDateTime->dayofweek,
				'enabled'         => 1,
				'last_exit_code'  => Status::INITIAL_SCHEDULE,
				'last_execution'  => null,
				'last_run_end'    => null,
				'next_execution'  => null,
				'locked'          => null,
				'priority'        => 1,
			]
		);
	}

	/**
	 * Ensures that we have valid Akeeba Backup Endpoint options
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function ensureAkeebaBackupConnectionOptions(): void
	{
		$config          = $this->getConfig();
		$info            = $config->get('akeebabackup.info');
		$endpointOptions = $config->get('akeebabackup.endpoint');

		if (empty($info) || (!empty($info?->api) && empty($endpointOptions)))
		{
			$this->getDbo()->lockTable('#__sites');
			$this->find($this->getId());

			try
			{
				$mustSave = $this->testAkeebaBackupConnection();

				if ($mustSave)
				{
					$this->save();

					$config          = $this->getConfig();
					$info            = $config->get('akeebabackup.info');
					$endpointOptions = $config->get('akeebabackup.endpoint');
				}
			}
			catch (GuzzleException $e)
			{
				throw new AkeebaBackupNoInfoException(previous: $e);
			}
			finally
			{
				$this->getDbo()->unlockTables();
			}
		}

		if (empty($info))
		{
			throw new AkeebaBackupNoInfoException();
		}

		if (empty($info?->api))
		{
			throw new AkeebaBackupIsNotPro();
		}

		if (empty($endpointOptions))
		{
			throw new AkeebaBackupCannotConnectException();
		}
	}

	/**
	 * Get the cache controller for requests to Akeeba Backup
	 *
	 * @return  CallbackController
	 * @since   1.0.0
	 */
	private function getAkeebaBackupCacheController(): CallbackController
	{
		if (empty($this->callbackControllerForAkeebaBackup))
		{
			/** @var Container $container */
			$container = $this->container;
			$pool      = $container->cacheFactory->pool('akeebabackup');

			$this->callbackControllerForAkeebaBackup = new CallbackController($container, $pool);
		}

		return $this->callbackControllerForAkeebaBackup;
	}

	/**
	 * Get the Akeeba Backup JSON API Connector object
	 *
	 * @return  Connector
	 * @since   1.0.0
	 */
	private function getAkeebaBackupAPIConnector(): Connector
	{
		return new Connector($this->getAkeebaBackupAPIClient());
	}

	/**
	 * Get the Akeeba Backup JSON API HTTP client
	 *
	 * @return  HttpClientInterface
	 */
	private function getAkeebaBackupAPIClient(): HttpClientInterface
	{
		$config            = $this->getConfig();
		$connectionOptions = (array) $config->get('akeebabackup.endpoint', null);

		if (empty($connectionOptions))
		{
			// This should never happen; we've already run ensureAkeebaBackupConnectionOptions to prevent this problem.
			throw new AkeebaBackupCannotConnectException();
		}

		$connectionOptions['capath'] = defined('AKEEBA_CACERT_PEM') ? AKEEBA_CACERT_PEM : null;

		$options = new JsonApiOptions($connectionOptions);

		return new HttpClientGuzzle($options);
	}

	private function akeebaBackupHandleAPIResponse(object $data): object
	{
		$backupID       = null;
		$backupRecordID = 0;
		$archive        = '';

		if ($data->body?->status != 200)
		{
			throw new RemoteError('Error ' . $data->body->status . ": " . $data->body->data);
		}

		if (isset($data->body->data->BackupID))
		{
			$backupRecordID = $data->body->data->BackupID;
		}

		if (isset($data->body->data->backupid))
		{
			$backupID = $data->body->data->backupid;
		}

		if (isset($data->body->data->Archive))
		{
			$archive = $data->body->data->Archive;
		}

		return (object) [
			'backupID'       => $backupID,
			'backupRecordID' => $backupRecordID,
			'archive'        => $archive,
		];
	}
}