<?php
/**
 * Service Interface - For reusable service classes
 * 
 * @package OSINT_PRO\Core
 * @since 12.0.0
 */

namespace OSINT_PRO\Core\Interfaces;

interface Service {
    /**
     * Initialize the service
     * 
     * @return void
     */
    public function init(): void;
    
    /**
     * Check if service is available
     * 
     * @return bool
     */
    public function is_available(): bool;
}
