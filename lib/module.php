<?php

declare(strict_types=1);

namespace lithiumhosting\whmcs;

use Module\Support\Webapps;
use Module\Support\Webapps\MetaManager;

class Whmcs_Module extends Webapps
{

	const APP_NAME = 'WHMCS';
	const VERSION_CHECK_URL = 'https://api1.whmcs.com/download/latest';
	const WHMCS_USERNAME = 'admin';

	public $whmcs_username;
	public $whmcs_password;

	protected $aclList = [
		'min' => [
			'attachments',
			'downloads',
			'templates_c',
			'.htaccess',
		],
		'max' => [
			'attachments',
			'downloads',
			'templates_c',
		],
	];

	/**
	 * Installed app is type "whmcs"
	 *
	 * @param  string  $mixed
	 * @param  string  $path
	 *
	 * @return bool
	 */
	public function valid(string $mixed, string $path = ''): bool
	{
		// $mixed is passed as [hostname, path] combination
		// convert to filesystem path
		if ($mixed[0] !== '/') {
			$mixed = $this->getDocumentRoot($mixed, $path);
		}

		return file_exists($this->domain_fs_path($mixed) . '/vendor/whmcs/whmcs-foundation/lib/License.php');
	}

	/**
	 * Install application
	 *
	 * @param  string  $hostname
	 * @param  string  $path
	 * @param  array  $opts
	 *
	 * @return bool
	 */
	public function install(string $hostname, string $path = '', array $opts = []): bool
	{
		if ( ! $this->mysql_enabled()) {
			return error(
				'%(what)s must be enabled to install %(app)s',
				['what' => 'MySQL', 'app' => static::APP_NAME]
			);
		}

		$docroot = $this->getDocumentRoot($hostname, $path);

		if ( ! $docroot) {
			return error("failed to detect document root for `%s'", $hostname);
		}

		if ( ! $this->parseInstallOptions($opts, $hostname, $path)) {
			return false;
		}

		if ( ! $this->crontab_permitted()) {
			return error('Task scheduling not enabled for account - admin must enable crontab,permit');
		}

		if ( ! $this->crontab_enabled() && ! $this->crontab_toggle_status(1)) {
			return error('Failed to enable task scheduling');
		}

		if (empty($opts['license_key'])) {
			return error('A WHMCS License Key is required.');
		}

		$this->whmcs_username = $opts['user'] = self::WHMCS_USERNAME;
		$this->whmcs_password = $opts['password'] = \Opcenter\Auth\Password::generate(10);

		$version = $opts['version'];
		$license = $opts['license_key'];
		$release = $this->_getReleaseData()[$version];

		if ( ! ($url = array_get($release, 'url'))) {
			return error("Failed to fetch install URL");
		}

		if ( ! $this->download($url, $docroot, true)) {
			return false;
		}

		$db = \Module\Support\Webapps\DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
		$db->connectionLimit = max($db->connectionLimit, 15);

		if ( ! $db->create()) {
			return false;
		}

//		$this->set_configuration($hostname, $path, $this->buildWhmcsConfig($opts, $db));
		$install = $ret = $this->run_install($docroot, $opts, $db);
		if ($install['success']) {
			$this->file_delete("${docroot}/install", true);
		}

		$this->crontab_add_job('*/5', '*', '*', '*', '*', 'php -q ' . $docroot . '/crons/cron.php', $this->getDocrootUser($docroot));

		$this->initializeMeta($docroot, $opts);

		// create database, modify config, etc

		// Apply Fortification, useful with PHP applications which run under a different UID
		// see Fortification.md
		$this->fortify($hostname, $path, 'max');

		// Send notification email
		$this->notifyInstalled($hostname, $path, $opts);

		return true;
	}

	/**
	 * Remove application
	 *
	 * @param  string  $hostname
	 * @param  string  $path
	 * @param  string  $delete
	 *
	 * @return bool
	 */
	public function uninstall(string $hostname, string $path = '', $delete = 'all'): bool
	{
		// parent does a good job of removing all traces, you can do any last minute touch-ups, such as
		// removing Redis services or changing DNS after uninstallation
		$this->crontab_delete_job('*/5', '*', '*', '*', '*', 'php -q ' . $this->getDocumentRoot($hostname, $path) . '/crons/cron.php');

		return parent::uninstall($hostname, $path, $delete);
	}

	/**
	 * Get available versions
	 *
	 * Used to determine whether an app is eligible for updates
	 *
	 * @return array|string[]
	 */
	public function get_versions(): array
	{
		return array_keys($this->_getReleaseData());
	}

	/**
	 * Retrieve WHMCS release data from API
	 *
	 * @return array
	 */
	private function _getReleaseData(): array
	{
		$cache = \Cache_Super_Global::spawn();
		if (false !== ($versions = $cache->get('whmcs.versions'))) {
			return $versions;
		}

		$contents = file_get_contents(self::VERSION_CHECK_URL);
		if ( ! ($versions = json_decode($contents, true))) {
			return [];
		}

		$cache->set('whmcs.versions', $versions = [$versions['version'] => $versions]);

		return $versions;
	}

	public function get_version(string $hostname, string $path = ''): ?string
	{
		$path = $this->getDocumentRoot($hostname, $path) . '/foo';

		// install file missing?
		if ( ! $this->file_exists($this->domain_fs_path($path))) {
			return null;
		}

		return strtok($this->domain_fs_path($path), ' ');
	}

	/**
	 * Get Web App handler name
	 *
	 * @return string
	 * @throws \ReflectionException
	 */
	public function getModule(): string
	{
		// fallback Web App handler name when "type" isn't specified in install
		return parent::getModule();
	}

	private function run_install($docroot, $opts, $db)
	{
		$json = json_encode([
			'admin'         => [
				'username' => $this->whmcs_username,
				'password' => $this->whmcs_password,
			],
			'configuration' => [
				'license'            => $opts['license_key'],
				'db_host'            => $db->hostname,
				'db_username'        => $db->username,
				'db_password'        => $db->password,
				'db_name'            => $db->database,
				'cc_encryption_hash' => \Opcenter\Auth\Password::generate(64),
				'mysql_charset'      => 'utf8',
			],
		]);

		return $this->pman_run('echo $(echo $CONF) | /usr/bin/php -f %(path)s/install/bin/installer.php – -i -n -c', ['path' => $docroot], ['CONF' => $json]);
	}
}
