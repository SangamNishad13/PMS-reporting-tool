<?php

/**
 * UnauthorizedAccessException - Exception for unauthorized access attempts
 * 
 * Thrown when a user attempts to access resources they don't have permission for
 */
class UnauthorizedAccessException extends Exception {
    
    public function __construct($message = "Unauthorized access", $code = 403, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}