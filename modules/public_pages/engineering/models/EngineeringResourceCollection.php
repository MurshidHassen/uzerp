<?php

/** 
 *	(c) 2017 uzERP LLP (support#uzerp.com). All rights reserved. 
 * 
 *	Released under GPLv3 license; see LICENSE. 
 **/

class EngineeringResourceCollection extends DataObjectCollection {
	
	protected $version = '$Revision: 1.2 $';
	
	public $field;
		
	function __construct($do = 'EngineeringResource', $tablename = 'eng_resources_overview')
	{
		parent::__construct($do, $tablename);
			
		$this->identifierField = 'id';
	}
	
}

// End of EngineeringResourceCollection
