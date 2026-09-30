<?php
// Just enough of Elementor for IXES_Elementor: Plugin::$instance->files_manager->clear_cache().
namespace Elementor;

class Plugin {
	public static $instance;
}

class Fake_Files_Manager {
	public $cleared = 0;
	public $throw = null;
	public function clear_cache() {
		if ( $this->throw ) throw $this->throw;
		$this->cleared++;
	}
}
