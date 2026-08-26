<?php
/**
 * Service registry.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every registered service, keyed by machine key.
 */
class Service_Registry {

	/**
	 * Registered services.
	 *
	 * @var Abstract_Service[]
	 */
	private $services = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Discover and register services.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$built_in = array(
			new Services\Service_Rfi(),
			new Services\Service_Submittals(),
			new Services\Service_Payapp(),
			new Services\Service_Costexposure(),
		);

		/**
		 * Register AEC Forge Tools services. New paid tools (including market-
		 * researched additions shipped via updates) hook in here.
		 *
		 * @param Abstract_Service[] $services Services to register.
		 */
		$services = apply_filters( 'aec_tools_register_services', $built_in );

		foreach ( $services as $service ) {
			if ( $service instanceof Abstract_Service ) {
				$this->services[ $service->key() ] = $service;
			}
		}
	}

	/**
	 * All services.
	 *
	 * @return Abstract_Service[]
	 */
	public function all() {
		$this->boot();
		return $this->services;
	}

	/**
	 * Get one service by key, or null.
	 *
	 * @param string $key Machine key.
	 * @return Abstract_Service|null
	 */
	public function get( $key ) {
		$this->boot();
		return isset( $this->services[ $key ] ) ? $this->services[ $key ] : null;
	}
}
