<?php

namespace siaeb\edd\gateways\mellat\includes;

class Initializer {

	private $_gateway;

	public function __construct() {
		$this->_gateway = new MellatGateway();
	}

}
